<?php

require_once __DIR__ . '/constants.php';
require_once __DIR__ . '/circulation.php';
require_once __DIR__ . '/receipts.php';

/**
 * LibrarianCheckinModule — Transaction boundary for librarian book returns.
 */
class LibrarianCheckinModule
{
  /**
   * Return a loan, assess any fine, restore a copy, log, and issue a receipt.
   *
   * @return array<string, mixed>
   */
  public static function process(PDO $pdo, int $loanId, int $actorId, string $actorRole): array
  {
    if ($loanId < 1) {
      return [
        'success' => false,
        'reason_code' => 'invalid_loan',
      ];
    }

    $ownsTransaction = !$pdo->inTransaction();
    if ($ownsTransaction) {
      $pdo->beginTransaction();
    }

    try {
      $loan = self::lockLoan($pdo, $loanId);
      if (!$loan || (string) ($loan['status'] ?? '') === CIRCULATION_STATUS_RETURNED) {
        self::finishTransaction($pdo, $ownsTransaction, 'rollback');
        return [
          'success' => false,
          'reason_code' => 'not_found_or_returned',
        ];
      }

      $bookId = (int) ($loan['book_id'] ?? 0);
      if ($bookId < 1) {
        self::finishTransaction($pdo, $ownsTransaction, 'rollback');
        return [
          'success' => false,
          'reason_code' => 'missing_book_reference',
        ];
      }

      $fine = self::assessFine($pdo, (string) ($loan['due_date'] ?? ''));
      $updated = complete_checkin_loan($pdo, $loanId, (float) $fine['amount']);
      if (!$updated) {
        self::finishTransaction($pdo, $ownsTransaction, 'rollback');
        return [
          'success' => false,
          'reason_code' => 'already_returned',
        ];
      }

      self::restoreAvailableCopy($pdo, $bookId);

      log_circulation($pdo, [
        'actor_id' => $actorId,
        'actor_role' => $actorRole,
        'action_type' => 'checkin',
        'target_entity' => 'Circulation',
        'target_id' => $loanId,
        'outcome' => LOG_OUTCOME_SUCCESS,
      ]);

      $returnedAt = date('Y-m-d H:i:s');
      $receipt = issue_receipt_ticket($pdo, [
        'type' => 'checkin',
        'actor_user_id' => $actorId,
        'actor_role' => $actorRole,
        'patron_user_id' => (int) $loan['user_id'],
        'reference_table' => 'Circulation',
        'reference_id' => $loanId,
        'format' => 'thermal',
        'channel' => 'librarian_console',
        'payload' => [
          'loan_id' => $loanId,
          'book_id' => $bookId,
          'book_title' => (string) ($loan['book_title'] ?? ''),
          'patron_name' => (string) ($loan['borrower_name'] ?? ''),
          'due_date' => (string) ($loan['due_date'] ?? ''),
          'returned_at' => $returnedAt,
          'days_late' => (int) $fine['days_late'],
          'fine_amount' => (float) $fine['amount'],
        ],
      ]);

      self::finishTransaction($pdo, $ownsTransaction, 'commit');

      return [
        'success' => true,
        'loan_id' => $loanId,
        'book_id' => $bookId,
        'patron_user_id' => (int) $loan['user_id'],
        'book_title' => (string) ($loan['book_title'] ?? ''),
        'borrower_name' => (string) ($loan['borrower_name'] ?? ''),
        'days_late' => (int) $fine['days_late'],
        'fine_amount' => (float) $fine['amount'],
        'receipt' => $receipt,
        'has_pending_reservations' => self::pendingReservationCount($pdo, $bookId) > 0,
      ];
    } catch (Throwable $e) {
      if ($ownsTransaction && $pdo->inTransaction()) {
        $pdo->rollBack();
      }
      error_log('[LibrarianCheckinModule] Transaction failed: ' . $e->getMessage());
      return [
        'success' => false,
        'reason_code' => 'unexpected_error',
      ];
    }
  }

  /**
   * Mark active loans past due as overdue.
   */
  public static function markOverdueLoans(PDO $pdo): int
  {
    $stmt = $pdo->prepare(
      "UPDATE Circulation
          SET status = ?
        WHERE status = ?
          AND due_date < NOW()"
    );
    $stmt->execute([CIRCULATION_STATUS_OVERDUE, CIRCULATION_STATUS_ACTIVE]);
    return (int) $stmt->rowCount();
  }

  /**
   * @return array{days_late: int, amount: float}
   */
  public static function assessFine(PDO $pdo, string $dueDate, ?DateTimeImmutable $now = null): array
  {
    $daysLate = circulation_late_days($dueDate, null, $now);
    $rate = (float) get_setting($pdo, 'fine_per_day', '0.00');
    return [
      'days_late' => $daysLate,
      'amount' => circulation_calculated_fine($daysLate, $rate),
    ];
  }

  private static function lockLoan(PDO $pdo, int $loanId): ?array
  {
    $stmt = $pdo->prepare(
      'SELECT c.id, c.user_id, c.book_id, c.due_date, c.status,
              b.title AS book_title,
              u.full_name AS borrower_name
         FROM Circulation c
         JOIN Books b ON c.book_id = b.id
         JOIN Users u ON c.user_id = u.id
        WHERE c.id = ?
        FOR UPDATE'
    );
    $stmt->execute([$loanId]);
    $row = $stmt->fetch();
    return $row === false ? null : $row;
  }

  private static function restoreAvailableCopy(PDO $pdo, int $bookId): void
  {
    $stmt = $pdo->prepare(
      'UPDATE Books
          SET available_copies = LEAST(available_copies + 1, total_copies)
        WHERE id = ?'
    );
    $stmt->execute([$bookId]);
  }

  private static function pendingReservationCount(PDO $pdo, int $bookId): int
  {
    $stmt = $pdo->prepare(
      'SELECT COUNT(*)
         FROM Reservations
        WHERE book_id = ?
          AND status = ?
          AND expires_at >= NOW()'
    );
    $stmt->execute([$bookId, RESERVATION_STATUS_PENDING]);
    return (int) $stmt->fetchColumn();
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
