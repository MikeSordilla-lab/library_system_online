<?php

require_once __DIR__ . '/constants.php';
require_once __DIR__ . '/circulation.php';
require_once __DIR__ . '/receipts.php';

/**
 * LibrarianCheckoutModule — Transaction boundary for librarian checkout flows.
 *
 * The HTTP page should only adapt request/session data into this module and map
 * result reason codes back to user-facing flash messages.
 */
class LibrarianCheckoutModule
{
  /**
   * Process either a direct checkout or an approved-reservation pickup.
   *
   * @param array{action?: string, user_id?: int, book_id?: int, reservation_id?: int} $request
   * @return array<string, mixed>
   */
  public static function process(PDO $pdo, array $request, int $actorId, string $actorRole): array
  {
    $action = (string) ($request['action'] ?? 'checkout');

    if ($action === 'pickup') {
      return self::pickup($pdo, (int) ($request['reservation_id'] ?? 0), $actorId, $actorRole);
    }

    if ($action === 'checkout') {
      return self::checkout(
        $pdo,
        (int) ($request['user_id'] ?? 0),
        (int) ($request['book_id'] ?? 0),
        $actorId,
        $actorRole
      );
    }

    return [
      'success' => false,
      'action' => $action,
      'reason_code' => 'invalid_action',
    ];
  }

  /**
   * Checkout a book directly for a borrower.
   *
   * @return array<string, mixed>
   */
  public static function checkout(PDO $pdo, int $userId, int $bookId, int $actorId, string $actorRole): array
  {
    if ($userId < 1 || $bookId < 1) {
      return [
        'success' => false,
        'action' => 'checkout',
        'reason_code' => 'missing_checkout_selection',
      ];
    }

    return self::runTransaction($pdo, [
      'action' => 'checkout',
      'user_id' => $userId,
      'book_id' => $bookId,
      'reservation_id' => 0,
    ], $actorId, $actorRole);
  }

  /**
   * Fulfill an approved reservation pickup and check the book out.
   *
   * @return array<string, mixed>
   */
  public static function pickup(PDO $pdo, int $reservationId, int $actorId, string $actorRole): array
  {
    if ($reservationId < 1) {
      return [
        'success' => false,
        'action' => 'pickup',
        'reason_code' => 'missing_pickup_reservation',
      ];
    }

    return self::runTransaction($pdo, [
      'action' => 'pickup',
      'user_id' => 0,
      'book_id' => 0,
      'reservation_id' => $reservationId,
    ], $actorId, $actorRole);
  }

  /**
   * Execute checkout behavior. Starts/finishes a transaction when needed, while
   * allowing tests to wrap calls in their own transaction.
   *
   * @param array{action: string, user_id: int, book_id: int, reservation_id: int} $request
   * @return array<string, mixed>
   */
  private static function runTransaction(PDO $pdo, array $request, int $actorId, string $actorRole): array
  {
    $ownsTransaction = !$pdo->inTransaction();
    if ($ownsTransaction) {
      $pdo->beginTransaction();
    }

    try {
      return self::execute($pdo, $request, $actorId, $actorRole, $ownsTransaction);
    } catch (Throwable $e) {
      if ($ownsTransaction && $pdo->inTransaction()) {
        $pdo->rollBack();
      }
      error_log('[LibrarianCheckoutModule] Transaction failed: ' . $e->getMessage());
      return [
        'success' => false,
        'action' => (string) ($request['action'] ?? 'checkout'),
        'reason_code' => 'unexpected_error',
      ];
    }
  }

  /**
   * @param array{action: string, user_id: int, book_id: int, reservation_id: int} $request
   * @return array<string, mixed>
   */
  private static function execute(PDO $pdo, array $request, int $actorId, string $actorRole, bool $ownsTransaction): array
  {
    expire_stale_reservations($pdo);

    $action = (string) $request['action'];
    $userId = (int) $request['user_id'];
    $bookId = (int) $request['book_id'];
    $reservationToFulfill = null;

    if ($action === 'pickup') {
      $pickup = self::lockPickupReservation($pdo, (int) $request['reservation_id']);
      if (!$pickup) {
        self::finishTransaction($pdo, $ownsTransaction, 'rollback');
        return [
          'success' => false,
          'action' => 'pickup',
          'reason_code' => 'approved_reservation_not_found',
        ];
      }

      if ((string) $pickup['status'] !== RESERVATION_STATUS_APPROVED) {
        self::finishTransaction($pdo, $ownsTransaction, 'commit');
        return [
          'success' => false,
          'action' => 'pickup',
          'reason_code' => 'pickup_not_approved',
          'reservation_id' => (int) $pickup['id'],
        ];
      }

      $userId = (int) $pickup['user_id'];
      $bookId = (int) $pickup['book_id'];
      $reservationToFulfill = [
        'id' => (int) $pickup['id'],
        'status' => (string) $pickup['status'],
      ];
    }

    $availability = BookAvailabilityModule::getAvailability($pdo, $bookId, true, false);
    $book = $availability['book'];
    if (!$availability['exists'] || (int) ($availability['available_copies'] ?? 0) < 1) {
      log_circulation($pdo, [
        'actor_id' => $actorId,
        'actor_role' => $actorRole,
        'action_type' => 'checkout',
        'target_entity' => 'Books',
        'target_id' => $bookId,
        'outcome' => LOG_OUTCOME_FAILURE,
      ]);
      self::finishTransaction($pdo, $ownsTransaction, 'commit');
      return [
        'success' => false,
        'action' => $action,
        'reason_code' => 'no_available_copies',
      ];
    }

    if (!self::verifiedBorrowerExists($pdo, $userId)) {
      log_circulation($pdo, [
        'actor_id' => $actorId,
        'actor_role' => $actorRole,
        'action_type' => 'checkout',
        'target_entity' => 'Users',
        'target_id' => $userId,
        'outcome' => LOG_OUTCOME_FAILURE,
      ]);
      self::finishTransaction($pdo, $ownsTransaction, 'commit');
      return [
        'success' => false,
        'action' => $action,
        'reason_code' => 'borrower_not_found',
      ];
    }

    $unpaidFines = get_unpaid_fines_total($pdo, $userId);
    if ($unpaidFines > 0.0) {
      self::finishTransaction($pdo, $ownsTransaction, 'rollback');
      return [
        'success' => false,
        'action' => $action,
        'reason_code' => 'delinquent',
        'unpaid_fines' => $unpaidFines,
      ];
    }

    if (get_overdue_loan_count($pdo, $userId) > 0) {
      self::finishTransaction($pdo, $ownsTransaction, 'rollback');
      return [
        'success' => false,
        'action' => $action,
        'reason_code' => 'overdue_loans',
      ];
    }

    $maxLimit = (int) get_setting($pdo, 'max_borrow_limit', '3');
    if (self::activeLoanAndReservationCount($pdo, $userId, $bookId) >= $maxLimit) {
      self::finishTransaction($pdo, $ownsTransaction, 'rollback');
      return [
        'success' => false,
        'action' => $action,
        'reason_code' => 'max_limit',
        'max_limit' => $maxLimit,
      ];
    }

    $existingLoanId = self::activeLoanIdForBook($pdo, $userId, $bookId);
    if ($existingLoanId !== null) {
      log_circulation($pdo, [
        'actor_id' => $actorId,
        'actor_role' => $actorRole,
        'action_type' => 'checkout',
        'target_entity' => 'Circulation',
        'target_id' => $existingLoanId,
        'outcome' => LOG_OUTCOME_FAILURE,
      ]);
      self::finishTransaction($pdo, $ownsTransaction, 'commit');
      return [
        'success' => false,
        'action' => $action,
        'reason_code' => 'duplicate_loan',
        'loan_id' => $existingLoanId,
      ];
    }

    if ($reservationToFulfill === null) {
      $reservationToFulfill = checkout_reservation_to_fulfill($pdo, $userId, $bookId);
    }

    $reservationPolicy = BookAvailabilityModule::checkoutPolicy($pdo, $userId, $bookId, $availability, true);
    if (!$reservationPolicy['allowed']) {
      log_circulation($pdo, [
        'actor_id' => $actorId,
        'actor_role' => $actorRole,
        'action_type' => 'checkout',
        'target_entity' => 'Reservations',
        'target_id' => (int) ($reservationPolicy['reservation_id'] ?? 0),
        'outcome' => LOG_OUTCOME_FAILURE,
      ]);
      self::finishTransaction($pdo, $ownsTransaction, 'commit');
      return [
        'success' => false,
        'action' => $action,
        'reason_code' => (string) ($reservationPolicy['reason_code'] ?? 'reserved_for_another_borrower'),
        'reservation_id' => (int) ($reservationPolicy['reservation_id'] ?? 0),
      ];
    }

    $loanDays = get_loan_period($pdo);
    $loanId = self::createLoan($pdo, $userId, $bookId, $loanDays);
    $loanMeta = self::loanMeta($pdo, $loanId);
    self::decrementAvailableCopy($pdo, $bookId);

    if ($reservationToFulfill) {
      transition_reservation_status(
        $pdo,
        (int) $reservationToFulfill['id'],
        (string) $reservationToFulfill['status'],
        RESERVATION_STATUS_FULFILLED,
        [
          'actor_id' => $actorId,
          'actor_role' => $actorRole,
          'action_type' => 'reservation_fulfill',
        ]
      );
    }

    log_circulation($pdo, [
      'actor_id' => $actorId,
      'actor_role' => $actorRole,
      'action_type' => 'checkout',
      'target_entity' => 'Circulation',
      'target_id' => $loanId,
      'outcome' => LOG_OUTCOME_SUCCESS,
    ]);

    $receipt = issue_receipt_ticket($pdo, [
      'type' => 'checkout',
      'actor_user_id' => $actorId,
      'actor_role' => $actorRole,
      'patron_user_id' => $userId,
      'reference_table' => 'Circulation',
      'reference_id' => $loanId,
      'format' => 'thermal',
      'channel' => 'librarian_console',
      'payload' => [
        'loan_id' => $loanId,
        'book_id' => $bookId,
        'book_title' => (string) ($loanMeta['title'] ?? ($book['title'] ?? '')),
        'patron_name' => (string) ($loanMeta['full_name'] ?? ''),
        'due_date' => (string) ($loanMeta['due_date'] ?? ''),
        'loan_days' => $loanDays,
      ],
    ]);

    self::finishTransaction($pdo, $ownsTransaction, 'commit');

    return [
      'success' => true,
      'action' => $action,
      'loan_id' => $loanId,
      'user_id' => $userId,
      'book_id' => $bookId,
      'reservation_id' => $reservationToFulfill ? (int) $reservationToFulfill['id'] : null,
      'receipt' => $receipt,
    ];
  }

  private static function lockPickupReservation(PDO $pdo, int $reservationId): ?array
  {
    $stmt = $pdo->prepare(
      'SELECT id, user_id, book_id, status
         FROM Reservations
        WHERE id = ?
        FOR UPDATE'
    );
    $stmt->execute([$reservationId]);
    $row = $stmt->fetch();
    return $row === false ? null : $row;
  }

  private static function verifiedBorrowerExists(PDO $pdo, int $userId): bool
  {
    $stmt = $pdo->prepare(
      'SELECT id FROM Users WHERE id = ? AND role = ? AND is_verified = 1 AND is_suspended = 0 LIMIT 1'
    );
    $stmt->execute([$userId, ROLE_BORROWER]);
    return $stmt->fetch() !== false;
  }

  private static function activeLoanAndReservationCount(PDO $pdo, int $userId, int $excludeReservationBookId): int
  {
    $loanStmt = $pdo->prepare(
      'SELECT COUNT(*) FROM Circulation WHERE user_id = ? AND status IN (\'active\', \'overdue\')'
    );
    $loanStmt->execute([$userId]);
    $currentLoans = (int) $loanStmt->fetchColumn();

    $reservationStmt = $pdo->prepare(
      'SELECT COUNT(*) FROM Reservations WHERE user_id = ? AND status IN (?, ?) AND book_id != ?'
    );
    $reservationStmt->execute([$userId, RESERVATION_STATUS_PENDING, RESERVATION_STATUS_APPROVED, $excludeReservationBookId]);

    return $currentLoans + (int) $reservationStmt->fetchColumn();
  }

  private static function activeLoanIdForBook(PDO $pdo, int $userId, int $bookId): ?int
  {
    $stmt = $pdo->prepare(
      'SELECT id FROM Circulation
        WHERE user_id = ? AND book_id = ? AND status IN (\'active\', \'overdue\')
        LIMIT 1'
    );
    $stmt->execute([$userId, $bookId]);
    $row = $stmt->fetch();
    return $row === false ? null : (int) $row['id'];
  }

  private static function createLoan(PDO $pdo, int $userId, int $bookId, int $loanDays): int
  {
    $stmt = $pdo->prepare(
      'INSERT INTO Circulation (user_id, book_id, due_date, status)
       VALUES (?, ?, NOW() + INTERVAL ? DAY, \'active\')'
    );
    $stmt->execute([$userId, $bookId, $loanDays]);
    return (int) $pdo->lastInsertId();
  }

  private static function loanMeta(PDO $pdo, int $loanId): array
  {
    $stmt = $pdo->prepare(
      'SELECT c.due_date, b.title, u.full_name
         FROM Circulation c
         JOIN Books b ON c.book_id = b.id
         JOIN Users u ON c.user_id = u.id
        WHERE c.id = ?
        LIMIT 1'
    );
    $stmt->execute([$loanId]);
    $row = $stmt->fetch();
    return $row === false ? [] : $row;
  }

  private static function decrementAvailableCopy(PDO $pdo, int $bookId): void
  {
    $stmt = $pdo->prepare(
      'UPDATE Books
          SET available_copies = available_copies - 1
        WHERE id = ?
          AND available_copies > 0'
    );
    $stmt->execute([$bookId]);

    if ($stmt->rowCount() !== 1) {
      throw new RuntimeException('Unable to decrement available book copy.');
    }
  }

  private static function finishTransaction(PDO $pdo, bool $ownsTransaction, string $mode): void
  {
    if (!$ownsTransaction || !$pdo->inTransaction()) {
      return;
    }

    if ($mode === 'rollback') {
      $pdo->rollBack();
      return;
    }

    $pdo->commit();
  }
}
