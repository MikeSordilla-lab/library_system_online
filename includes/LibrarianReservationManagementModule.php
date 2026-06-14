<?php

require_once __DIR__ . '/constants.php';
require_once __DIR__ . '/circulation.php';
require_once __DIR__ . '/mailer.php';

/**
 * LibrarianReservationManagementModule — Approval/rejection and list models for reservation management.
 */
class LibrarianReservationManagementModule
{
  /**
   * @return array<string, mixed>
   */
  public static function process(PDO $pdo, int $reservationId, string $action, int $actorId, string $actorRole, array $input = []): array
  {
    if ($reservationId < 1 || !in_array($action, ['approve', 'reject'], true)) {
      return ['success' => false, 'reason_code' => 'invalid_request'];
    }

    if ($action === 'approve') {
      return self::approve($pdo, $reservationId, $actorId, $actorRole);
    }

    return self::reject($pdo, $reservationId, $actorId, $actorRole, (string) ($input['rejection_reason'] ?? ''));
  }

  /**
   * @return array<string, mixed>
   */
  public static function approve(PDO $pdo, int $reservationId, int $actorId, string $actorRole): array
  {
    $pdo->beginTransaction();

    try {
      expire_stale_reservations($pdo);
      $reservation = self::lockReservation($pdo, $reservationId);

      if (!$reservation) {
        $pdo->rollBack();
        return ['success' => false, 'reason_code' => 'not_found'];
      }

      if ((string) ($reservation['status'] ?? '') !== RESERVATION_STATUS_PENDING) {
        $pdo->commit();
        return ['success' => false, 'reason_code' => 'already_processed'];
      }

      $changed = transition_reservation_status(
        $pdo,
        $reservationId,
        RESERVATION_STATUS_PENDING,
        RESERVATION_STATUS_APPROVED,
        [
          'actor_id' => $actorId,
          'actor_role' => $actorRole,
          'action_type' => 'reservation_approve',
        ]
      );

      $autoRejected = [];
      if ($changed) {
        $autoRejected = reject_pending_reservations_when_no_copies_left(
          $pdo,
          (int) $reservation['book_id'],
          $actorId,
          $actorRole
        );
      }

      $pdo->commit();

      if (!$changed) {
        return ['success' => false, 'reason_code' => 'status_changed'];
      }

      $approvalMailError = '';
      $approvalMailSent = send_reservation_approved_mail(
        (string) $reservation['borrower_email'],
        (string) $reservation['borrower_name'],
        (string) $reservation['book_title'],
        (string) ($reservation['book_author'] ?? ''),
        (string) ($reservation['expires_at'] ?? ''),
        $approvalMailError
      );
      if (!$approvalMailSent) {
        error_log('[LibrarianReservationManagementModule] approval email failed for reservation ' . $reservationId . ': ' . $approvalMailError);
      }

      $autoRejectMailFailures = 0;
      foreach ($autoRejected as $rejectedReservation) {
        $mailError = '';
        if (!send_reservation_unavailable_mail(
          (string) $rejectedReservation['borrower_email'],
          (string) $rejectedReservation['borrower_name'],
          (string) $rejectedReservation['book_title'],
          $mailError
        )) {
          $autoRejectMailFailures++;
          error_log('[LibrarianReservationManagementModule] auto-rejection email failed for reservation '
            . (int) $rejectedReservation['id'] . ': ' . $mailError);
        }
      }

      return [
        'success' => true,
        'action' => 'approve',
        'reservation_id' => $reservationId,
        'approval_mail_sent' => $approvalMailSent,
        'auto_rejected' => $autoRejected,
        'auto_rejected_count' => count($autoRejected),
        'auto_reject_mail_failures' => $autoRejectMailFailures,
      ];
    } catch (Throwable $e) {
      if ($pdo->inTransaction()) {
        $pdo->rollBack();
      }
      error_log('[LibrarianReservationManagementModule] approve failed: ' . $e->getMessage());
      return ['success' => false, 'reason_code' => 'unexpected_error'];
    }
  }

  /**
   * @return array<string, mixed>
   */
  public static function reject(PDO $pdo, int $reservationId, int $actorId, string $actorRole, string $rejectionReason = ''): array
  {
    $rejectionReason = trim($rejectionReason);
    if (strlen($rejectionReason) > 255) {
      $rejectionReason = substr($rejectionReason, 0, 255);
    }

    $pdo->beginTransaction();

    try {
      expire_stale_reservations($pdo);
      $reservation = self::lockReservation($pdo, $reservationId);

      if (!$reservation) {
        $pdo->rollBack();
        return ['success' => false, 'reason_code' => 'not_found'];
      }

      if ((string) ($reservation['status'] ?? '') !== RESERVATION_STATUS_PENDING) {
        $pdo->commit();
        return ['success' => false, 'reason_code' => 'already_processed'];
      }

      $meta = [
        'actor_id' => $actorId,
        'actor_role' => $actorRole,
        'action_type' => 'reservation_reject',
      ];
      if ($rejectionReason !== '') {
        $meta['rejection_reason'] = $rejectionReason;
      }

      $changed = transition_reservation_status(
        $pdo,
        $reservationId,
        RESERVATION_STATUS_PENDING,
        RESERVATION_STATUS_REJECTED,
        $meta
      );

      $pdo->commit();

      return $changed
        ? ['success' => true, 'action' => 'reject', 'reservation_id' => $reservationId]
        : ['success' => false, 'reason_code' => 'status_changed'];
    } catch (Throwable $e) {
      if ($pdo->inTransaction()) {
        $pdo->rollBack();
      }
      error_log('[LibrarianReservationManagementModule] reject failed: ' . $e->getMessage());
      return ['success' => false, 'reason_code' => 'unexpected_error'];
    }
  }

  /**
   * @return array<string, mixed>
   */
  public static function pageModel(PDO $pdo): array
  {
    expire_stale_reservations($pdo);
    $pendingRows = get_pending_reservation_queue($pdo);

    $approvedStmt = $pdo->prepare(
      'SELECT r.id, r.user_id, r.book_id, r.reserved_at, r.approved_at, r.expires_at,
              u.full_name AS borrower_name, u.email AS borrower_email,
              b.title AS book_title, b.author AS book_author,
              (SELECT full_name FROM Users WHERE id = r.approved_by LIMIT 1) AS approved_by_name
         FROM Reservations r
         JOIN Users u ON r.user_id = u.id
         JOIN Books b ON r.book_id = b.id
        WHERE r.status = ?
          AND r.expires_at >= NOW()
        ORDER BY r.expires_at ASC'
    );
    $approvedStmt->execute([RESERVATION_STATUS_APPROVED]);
    $approvedRows = $approvedStmt->fetchAll();

    $processedStmt = $pdo->prepare(
      'SELECT r.id, r.status, r.reserved_at, r.approved_at, r.rejected_at, r.updated_at, r.rejection_reason,
              u.full_name AS borrower_name, u.email AS borrower_email,
              b.title AS book_title, b.author AS book_author,
              approver.full_name AS approved_by_name,
              rejecter.full_name AS rejected_by_name
         FROM Reservations r
         JOIN Users u ON r.user_id = u.id
         JOIN Books b ON r.book_id = b.id
    LEFT JOIN Users approver ON approver.id = r.approved_by
    LEFT JOIN Users rejecter ON rejecter.id = r.rejected_by
        WHERE r.status IN (?, ?, ?)
        ORDER BY COALESCE(r.updated_at, r.rejected_at, r.approved_at, r.reserved_at) DESC, r.id DESC'
    );
    $processedStmt->execute([RESERVATION_STATUS_APPROVED, RESERVATION_STATUS_REJECTED, RESERVATION_STATUS_FULFILLED]);
    $processedRows = $processedStmt->fetchAll();

    $rejectedCount = 0;
    foreach ($processedRows as $processedRow) {
      if (($processedRow['status'] ?? '') === RESERVATION_STATUS_REJECTED) {
        $rejectedCount++;
      }
    }

    return [
      'pending_rows' => $pendingRows,
      'approved_rows' => $approvedRows,
      'processed_rows' => $processedRows,
      'pending_count' => count($pendingRows),
      'approved_count' => count($approvedRows),
      'processed_count' => count($processedRows),
      'rejected_count' => $rejectedCount,
    ];
  }

  private static function lockReservation(PDO $pdo, int $reservationId): ?array
  {
    $stmt = $pdo->prepare(
      'SELECT r.id, r.book_id, r.status, r.expires_at,
              u.full_name AS borrower_name, u.email AS borrower_email,
              b.title AS book_title, b.author AS book_author
         FROM Reservations r
         JOIN Users u ON u.id = r.user_id
         JOIN Books b ON b.id = r.book_id
        WHERE r.id = ?
        FOR UPDATE'
    );
    $stmt->execute([$reservationId]);
    $row = $stmt->fetch();
    return $row === false ? null : $row;
  }
}
