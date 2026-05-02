<?php

require_once __DIR__ . '/QueuePositionModule.php';
require_once __DIR__ . '/circulation.php';
require_once __DIR__ . '/constants.php';

class BorrowerAccountSummaryModule
{
  /**
   * Return a complete borrowing summary for a borrower.
   *
   * @param PDO $pdo    Active database connection
   * @param int $userId Borrower user ID
   * @return array{
   *   active_loans: array,
   *   approved_reservations: array,
   *   pending_reservations: array,
   *   rejected_reservations: array,
   *   loan_history: array,
   *   stats: array{
   *     currently_borrowed: int,
   *     total_borrowed: int,
   *     pending_count: int,
   *     due_soon_count: int,
   *     next_return: string|null
   *   }
   * }
   */
  public static function getSummary(PDO $pdo, int $userId): array
  {
    $hasRenewalCount = circulation_column_exists($pdo, 'renewal_count');
    $hasApprovedAt = reservation_column_exists($pdo, 'approved_at');

    $renewalSelect = $hasRenewalCount ? ', c.renewal_count' : '';
    $approvedAtSelect = $hasApprovedAt ? 'r.approved_at AS approved_at' : 'NULL AS approved_at';

    $activeStmt = $pdo->prepare(
      "SELECT c.id, c.checkout_date, c.due_date, c.status{$renewalSelect},
              b.title, b.author,
              DATEDIFF(NOW(), c.due_date) AS days_overdue
         FROM Circulation c
         JOIN Books b ON c.book_id = b.id
        WHERE c.user_id = ? AND c.status IN ('active','overdue')
        ORDER BY c.due_date ASC"
    );
    $activeStmt->execute([$userId]);
    $activeLoans = $activeStmt->fetchAll();

    foreach ($activeLoans as &$loan) {
      $loan['days_overdue'] = max(0, (int) ($loan['days_overdue'] ?? 0));
      $renewalCount = ($hasRenewalCount && isset($loan['renewal_count'])) ? (int) $loan['renewal_count'] : 0;
      $dueTs = strtotime($loan['due_date']);
      $loan['renewal_eligible'] = $loan['status'] === 'active'
        && $dueTs !== false && $dueTs <= (time() + 86400)
        && $dueTs > time()
        && $renewalCount < 1;
    }
    unset($loan);

    $approvedStmt = $pdo->prepare(
      "SELECT r.id, {$approvedAtSelect}, r.expires_at, b.title, b.author
         FROM Reservations r
         JOIN Books b ON r.book_id = b.id
        WHERE r.user_id = ? AND r.status = 'approved'
        ORDER BY r.expires_at ASC"
    );
    $approvedStmt->execute([$userId]);
    $approvedReservations = $approvedStmt->fetchAll();

    $pendingStmt = $pdo->prepare(
      "SELECT r.id, r.reserved_at, r.expires_at, r.book_id, b.title, b.author
         FROM Reservations r
         JOIN Books b ON r.book_id = b.id
        WHERE r.user_id = ? AND r.status = 'pending'
        ORDER BY r.reserved_at ASC"
    );
    $pendingStmt->execute([$userId]);
    $pendingReservations = $pendingStmt->fetchAll();

    $rejectedStmt = $pdo->prepare(
      "SELECT r.id, r.reserved_at, r.rejected_at, r.rejection_reason, b.title, b.author
         FROM Reservations r
         JOIN Books b ON r.book_id = b.id
        WHERE r.user_id = ? AND r.status = 'rejected'
        ORDER BY r.rejected_at DESC
        LIMIT 10"
    );
    $rejectedStmt->execute([$userId]);
    $rejectedReservations = $rejectedStmt->fetchAll();

    $historyStmt = $pdo->prepare(
      "SELECT c.id, c.checkout_date, c.due_date, c.return_date,
              c.fine_amount, c.fine_paid,
              b.title, b.author
         FROM Circulation c
         JOIN Books b ON c.book_id = b.id
        WHERE c.user_id = ? AND c.status = 'returned'
        ORDER BY c.return_date DESC
        LIMIT 20"
    );
    $historyStmt->execute([$userId]);
    $loanHistory = $historyStmt->fetchAll();

    $queuePositions = QueuePositionModule::computeBatch($pdo, $pendingReservations);
    foreach ($pendingReservations as &$res) {
      $resId = (int) $res['id'];
      $res['queue_position'] = $queuePositions[$resId] ?? 1;
    }
    unset($res);

    $qCurrentlyBorrowed = $pdo->prepare(
      "SELECT COUNT(*) FROM Circulation WHERE user_id = ? AND status IN ('active','overdue')"
    );
    $qCurrentlyBorrowed->execute([$userId]);

    $qTotalBorrowed = $pdo->prepare('SELECT COUNT(*) FROM Circulation WHERE user_id = ?');
    $qTotalBorrowed->execute([$userId]);

    $qDueSoon = $pdo->prepare(
      "SELECT MIN(due_date) AS next_return, COUNT(*) AS due_soon_count
         FROM Circulation
        WHERE user_id = ?
          AND status IN ('active','overdue')
          AND due_date <= DATE_ADD(NOW(), INTERVAL 3 DAY)"
    );
    $qDueSoon->execute([$userId]);
    $dueRow = $qDueSoon->fetch();

    return [
      'active_loans'            => $activeLoans,
      'approved_reservations'   => $approvedReservations,
      'pending_reservations'    => $pendingReservations,
      'rejected_reservations'  => $rejectedReservations,
      'loan_history'            => $loanHistory,
      'stats'                   => [
        'currently_borrowed' => (int) $qCurrentlyBorrowed->fetchColumn(),
        'total_borrowed'     => (int) $qTotalBorrowed->fetchColumn(),
        'pending_count'      => count($pendingReservations),
        'due_soon_count'     => (int) ($dueRow['due_soon_count'] ?? 0),
        'next_return'        => $dueRow['next_return'] ?? null,
      ],
    ];
  }
}