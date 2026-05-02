<?php

require_once __DIR__ . '/circulation.php';

define('MAX_RENEWALS', 1);

class RenewalEligibilityModule
{
  /**
   * Check whether a borrower can renew a specific loan.
   *
   * @param PDO   $pdo    Active database connection
   * @param int   $userId Borrower user ID
   * @param int   $loanId Loan/Circulation ID
   * @return array{eligible: bool, reason_code: string|null, new_due_date: string|null}
   *   reason_code is null when eligible=true; one of the strings below when eligible=false:
   *     'delinquent'            — borrower has unpaid fines
   *     'overdue'               — loan is past due date
   *     'not_active'            — loan status is not 'active'
   *     'already_renewed'       — loan.renewal_count >= MAX_RENEWALS
   *     'too_early'            — due date is more than 1 day away
   *     'competing_reservation' — another user has a pending reservation for the same book
   */
  public static function check(PDO $pdo, int $userId, int $loanId): array
  {
    $hasRenewalCount = circulation_column_exists($pdo, 'renewal_count');
    $renewalSelect = $hasRenewalCount ? ', c.renewal_count' : '';

    $loanStmt = $pdo->prepare(
      "SELECT c.id, c.user_id, c.book_id, c.due_date, c.status{$renewalSelect}, b.title
         FROM Circulation c
         JOIN Books b ON c.book_id = b.id
        WHERE c.id = ?"
    );
    $loanStmt->execute([$loanId]);
    $loan = $loanStmt->fetch();

    if (!$loan) {
      return ['eligible' => false, 'reason_code' => 'not_found', 'new_due_date' => null];
    }

    if ((int) $loan['user_id'] !== $userId) {
      return ['eligible' => false, 'reason_code' => 'not_found', 'new_due_date' => null];
    }

    $unpaidFines = get_unpaid_fines_total($pdo, $userId);
    if ($unpaidFines > 0.0) {
      return ['eligible' => false, 'reason_code' => 'delinquent', 'new_due_date' => null];
    }

    $dueTs = strtotime($loan['due_date']);
    $isOverdue = ($loan['status'] === 'overdue') || ($loan['status'] === 'active' && $dueTs !== false && $dueTs < time());
    if ($isOverdue) {
      return ['eligible' => false, 'reason_code' => 'overdue', 'new_due_date' => null];
    }

    if ($loan['status'] !== 'active') {
      return ['eligible' => false, 'reason_code' => 'not_active', 'new_due_date' => null];
    }

    $currentRenewals = ($hasRenewalCount && isset($loan['renewal_count'])) ? (int) $loan['renewal_count'] : 0;
    if ($currentRenewals >= MAX_RENEWALS) {
      return ['eligible' => false, 'reason_code' => 'already_renewed', 'new_due_date' => null];
    }

    $oneDayBeforeDue = $dueTs - 86400;
    if (time() < $oneDayBeforeDue) {
      return ['eligible' => false, 'reason_code' => 'too_early', 'new_due_date' => null];
    }

    $resStmt = $pdo->prepare(
      "SELECT id FROM Reservations
        WHERE book_id = ? AND status = 'pending' AND user_id != ?
        LIMIT 1"
    );
    $resStmt->execute([(int) $loan['book_id'], $userId]);
    if ($resStmt->fetch()) {
      return ['eligible' => false, 'reason_code' => 'competing_reservation', 'new_due_date' => null];
    }

    $loanDays = get_loan_period($pdo);
    $currentDue = new DateTime($loan['due_date']);
    $newDue = $currentDue->add(new DateInterval('P' . $loanDays . 'D'));
    $newDueDate = $newDue->format('Y-m-d H:i:s');

    return ['eligible' => true, 'reason_code' => null, 'new_due_date' => $newDueDate];
  }
}