<?php

require_once __DIR__ . '/QueuePositionModule.php';
require_once __DIR__ . '/constants.php';
require_once __DIR__ . '/circulation.php';
require_once __DIR__ . '/receipts.php';

class BorrowerReservationModule
{
  /**
   * Place a book reservation for a borrower.
   *
   * @param PDO $pdo   Active database connection (caller manages transaction)
   * @param int $userId
   * @param int $bookId
   * @param string $actorRole Role of the actor placing the reservation
   * @return array{success: bool, reason_code?: string, queue_position?: int, receipt?: array}
   */
  public static function place(PDO $pdo, int $userId, int $bookId, string $actorRole = 'borrower'): array
  {
    require_once __DIR__ . '/receipts.php';

    expire_stale_reservations($pdo);

    $bookStmt = $pdo->prepare(
      'SELECT id, total_copies, available_copies
         FROM Books
        WHERE id = ?
        FOR UPDATE'
    );
    $bookStmt->execute([$bookId]);
    $book = $bookStmt->fetch();

    if (!$book) {
      return ['success' => false, 'reason_code' => 'book_not_found'];
    }

    if ((int) $book['total_copies'] < 1) {
      return ['success' => false, 'reason_code' => 'book_not_borrowable'];
    }

    $unpaidFines = get_unpaid_fines_total($pdo, $userId);
    if ($unpaidFines > 0.0) {
      return ['success' => false, 'reason_code' => 'delinquent'];
    }

    if (get_overdue_loan_count($pdo, $userId) > 0) {
      return ['success' => false, 'reason_code' => 'overdue_loans'];
    }

    $limitStmt = $pdo->prepare(
      'SELECT COUNT(*) FROM Circulation WHERE user_id = ? AND status IN (\'active\', \'overdue\')'
    );
    $limitStmt->execute([$userId]);
    $currentLoans = (int) $limitStmt->fetchColumn();

    $resLimitStmt = $pdo->prepare(
      'SELECT COUNT(*) FROM Reservations WHERE user_id = ? AND status IN (?, ?)'
    );
    $resLimitStmt->execute([$userId, RESERVATION_STATUS_PENDING, RESERVATION_STATUS_APPROVED]);
    $currentRes = (int) $resLimitStmt->fetchColumn();

    $maxLimit = (int) get_setting($pdo, 'max_borrow_limit', '3');
    if ($currentLoans + $currentRes >= $maxLimit) {
      return ['success' => false, 'reason_code' => 'max_limit'];
    }

    $dupResStmt = $pdo->prepare(
      'SELECT id FROM Reservations
        WHERE user_id = ? AND book_id = ? AND status IN (?, ?)
        FOR UPDATE'
    );
    $dupResStmt->execute([$userId, $bookId, RESERVATION_STATUS_PENDING, RESERVATION_STATUS_APPROVED]);
    if ($dupResStmt->fetch()) {
      return ['success' => false, 'reason_code' => 'duplicate_res'];
    }

    $dupLoanStmt = $pdo->prepare(
      'SELECT id FROM Circulation
        WHERE user_id = ? AND book_id = ? AND status IN (\'active\', \'overdue\')
        FOR UPDATE'
    );
    $dupLoanStmt->execute([$userId, $bookId]);
    if ($dupLoanStmt->fetch()) {
      return ['success' => false, 'reason_code' => 'duplicate_loan'];
    }

    $expiryDays = get_reservation_expiry($pdo);

    $insStmt = $pdo->prepare(
      'INSERT INTO Reservations (user_id, book_id, expires_at, status)
         VALUES (?, ?, DATE_ADD(NOW(), INTERVAL ? DAY), ?)'
    );
    $insStmt->execute([$userId, $bookId, $expiryDays, RESERVATION_STATUS_PENDING]);
    $newResId = (int) $pdo->lastInsertId();

    $resMetaStmt = $pdo->prepare(
      'SELECT r.reserved_at, r.expires_at, b.title, u.full_name
         FROM Reservations r
         JOIN Books b ON r.book_id = b.id
         JOIN Users u ON r.user_id = u.id
        WHERE r.id = ?
        LIMIT 1'
    );
    $resMetaStmt->execute([$newResId]);
    $resMeta = $resMetaStmt->fetch();

    $reservedAt = strtotime($resMeta['reserved_at'] ?? date('Y-m-d H:i:s'));
    $queuePosition = QueuePositionModule::compute($pdo, $bookId, $reservedAt, $newResId);

    log_circulation($pdo, [
      'actor_id'      => $userId,
      'actor_role'    => $actorRole,
      'action_type'   => 'reservation_place',
      'target_entity' => 'Reservations',
      'target_id'     => $newResId,
      'outcome'       => 'success',
    ]);

    $receipt = issue_receipt_ticket($pdo, [
      'type'            => 'reservation_place',
      'actor_user_id'   => $userId,
      'actor_role'      => $actorRole,
      'patron_user_id'  => $userId,
      'reference_table' => 'Reservations',
      'reference_id'    => $newResId,
      'format'          => 'thermal',
      'channel'         => 'borrower_portal',
      'payload'         => [
        'reservation_id' => $newResId,
        'book_id'        => $bookId,
        'book_title'     => (string) ($resMeta['title'] ?? ''),
        'patron_name'    => (string) ($resMeta['full_name'] ?? ''),
        'expires_at'     => (string) ($resMeta['expires_at'] ?? ''),
        'queue_position' => $queuePosition,
      ],
    ]);

    return [
      'success'       => true,
      'queue_position' => $queuePosition,
      'receipt'        => $receipt,
    ];
  }

  /**
   * Place reservations for multiple books.
   *
   * @param PDO $pdo Active database connection inside caller transaction
   * @param int $userId
   * @param array<int, int> $bookIds
   * @param string $actorRole
   * @return array{successes: array<int, array{title: string, queue_position: int, receipt?: array}>, failures: array<int, array{title: string, reason_code: string}>, first_receipt_no: string}
   */
  public static function placeBulk(PDO $pdo, int $userId, array $bookIds, string $actorRole = 'borrower'): array
  {
    $cleanBookIds = [];
    foreach ($bookIds as $bookId) {
      $bookId = (int) $bookId;
      if ($bookId > 0 && !in_array($bookId, $cleanBookIds, true)) {
        $cleanBookIds[] = $bookId;
      }
    }

    if (empty($cleanBookIds)) {
      return ['successes' => [], 'failures' => [], 'first_receipt_no' => ''];
    }

    $placeholders = implode(', ', array_fill(0, count($cleanBookIds), '?'));
    $titleStmt = $pdo->prepare("SELECT id, title FROM Books WHERE id IN ($placeholders)");
    $titleStmt->execute($cleanBookIds);

    $bookTitles = [];
    foreach ($titleStmt->fetchAll() as $titleRow) {
      $bookTitles[(int) $titleRow['id']] = (string) $titleRow['title'];
    }

    $successes = [];
    $failures = [];
    $firstReceiptNo = '';

    foreach ($cleanBookIds as $bookId) {
      $title = $bookTitles[$bookId] ?? ('Book #' . $bookId);
      $result = self::place($pdo, $userId, $bookId, $actorRole);

      if (!empty($result['success'])) {
        $successes[] = [
          'title' => $title,
          'queue_position' => (int) ($result['queue_position'] ?? 0),
          'receipt' => $result['receipt'] ?? [],
        ];
        if ($firstReceiptNo === '' && !empty($result['receipt']['receipt_no'])) {
          $firstReceiptNo = (string) $result['receipt']['receipt_no'];
        }
        continue;
      }

      $failures[] = [
        'title' => $title,
        'reason_code' => (string) ($result['reason_code'] ?? ''),
      ];
    }

    return [
      'successes' => $successes,
      'failures' => $failures,
      'first_receipt_no' => $firstReceiptNo,
    ];
  }

  /**
   * Cancel a book reservation.
   *
   * @param PDO $pdo
   * @param int $userId
   * @param int $reservationId
   * @param string $actorRole Role of the actor cancelling
   * @return array{success: bool, reason_code?: string}
   */
  public static function cancel(PDO $pdo, int $userId, int $reservationId, string $actorRole = 'borrower'): array
  {
    expire_stale_reservations($pdo);

    $resStmt = $pdo->prepare(
      'SELECT id, user_id, status
         FROM Reservations
        WHERE id = ?
        FOR UPDATE'
    );
    $resStmt->execute([$reservationId]);
    $reservation = $resStmt->fetch();

    if (!$reservation || (int) $reservation['user_id'] !== $userId) {
      return ['success' => false, 'reason_code' => 'not_found'];
    }

    $currentStatus = (string) $reservation['status'];
    if (!in_array($currentStatus, reservation_open_statuses(), true)) {
      return ['success' => false, 'reason_code' => 'not_cancellable'];
    }

    $changed = transition_reservation_status(
      $pdo,
      $reservationId,
      $currentStatus,
      RESERVATION_STATUS_CANCELLED,
      [
        'actor_id'   => $userId,
        'actor_role' => $actorRole,
        'action_type' => 'reservation_cancel',
      ]
    );

    if (!$changed) {
      return ['success' => false, 'reason_code' => 'not_cancellable'];
    }

    return ['success' => true];
  }
}
