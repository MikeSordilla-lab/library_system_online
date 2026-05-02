# AFK: Extract BorrowerAccountSummaryModule

## What to build

Extract `BorrowerAccountSummaryModule` into `includes/BorrowerAccountSummaryModule.php` with one method:

- `getSummary(PDO $pdo, int $userId): BorrowerAccountSummary` — returns structured array:

```
{
  active_loans: [...],        // includes days_overdue, renewal_eligible
  approved_reservations: [...],
  pending_reservations: [...], // includes queue_position via QueuePositionModule
  rejected_reservations: [...],
  loan_history: [...],
  stats: {                     // currently_borrowed, total_borrowed, pending_count, due_soon_count, next_return }
}
```

Replaces Q1–Q7 in `borrower/index.php` and sections 1–5 in `borrower/my_books.php`.

## Acceptance criteria

- [ ] Returned structure contains all expected keys
- [ ] active_loans, approved_reservations, pending_reservations, rejected_reservations counts are non-negative integers
- [ ] pending_reservations entries include queue_position >= 1
- [ ] active_loans entries include days_overdue (0 if not overdue) and renewal_eligible (bool)
- [ ] Both `borrower/index.php` and `borrower/my_books.php` call `getSummary()` — no duplicated query logic