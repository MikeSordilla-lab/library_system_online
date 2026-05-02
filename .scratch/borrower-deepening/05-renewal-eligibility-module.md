# AFK: Extract RenewalEligibilityModule

## What to build

Extract `RenewalEligibilityModule` into `includes/RenewalEligibilityModule.php` with one method:

- `check(PDO $pdo, int $userId, int $loanId): RenewalEligibility` — returns `{eligible: bool, reason_code: string|null, new_due_date: string|null}`

Reason codes: `ok`, `delinquent`, `overdue`, `already_renewed`, `too_early`, `competing_reservation`.

Used by `borrower/index.php` and `borrower/my_books.php` to show/hide the Renew button AND by `borrower/renew.php` to enforce eligibility — eliminating the current UI/handler drift.

## Acceptance criteria

- [ ] `check()` returns `{eligible: true, reason_code: null, new_due_date: 'YYYY-MM-DD'}` when all 6 conditions pass
- [ ] Returns `delinquent` when borrower has unpaid fines
- [ ] Returns `overdue` when book is past due date
- [ ] Returns `already_renewed` when loan.renewed_count >= 1
- [ ] Returns `too_early` when due date is more than 1 day away
- [ ] Returns `competing_reservation` when another user has a pending reservation for the same book
- [ ] `borrower/index.php` and `borrower/my_books.php` use the same `check()` for button visibility
- [ ] `borrower/renew.php` uses the same `check()` for enforcement — UI and handler never disagree