# AFK: Wire borrower pages to new modules

## What to build

Wire all four borrower pages to the new modules extracted in slices 3–6:

- `borrower/reserve.php` → thin HTTP adapter for `BorrowerReservationModule` (done in slice 4)
- `borrower/index.php` → uses `BorrowerAccountSummaryModule.getSummary()` instead of Q1–Q7
- `borrower/my_books.php` → uses `BorrowerAccountSummaryModule.getSummary()` instead of sections 1–5
- `borrower/renew.php` → uses `RenewalEligibilityModule.check()` instead of inline eligibility logic

No new business logic — purely wiring existing behaviour through the new modules.

## Acceptance criteria

- [ ] `borrower/index.php` dashboard loads with no functional changes (same loans, reservations, stats displayed)
- [ ] `borrower/my_books.php` loads with no functional changes
- [ ] Reserve action (place) still works: receipt issued, queue position shown
- [ ] Reserve action (cancel) still works
- [ ] Renewal eligibility in UI matches renewal enforcement in handler (no silent blocks)
- [ ] All pages use `get_setting()` cache from slice 2 (no redundant DB queries)