# PRD: Borrower Module Architectural Deepening

## Problem Statement

The borrower-facing module (`borrower/`) suffers from six architectural problems that reduce testability, increase maintenance cost, and create silent behavioural drift between UI and enforcement:

1. **Queue-position logic is triplicated** — identical O(n²) nested loops appear in `borrower/index.php`, `borrower/my_books.php`, and `borrower/reserve.php`. Changing the queue-counting rule requires three edits and three tests.

2. **`borrower/reserve.php` is a god-handler** — one 300-line file handles two unrelated workflows (`action=place`, `action=cancel`) with inline procedural logic. The booking policy (delinquent-borrower block, max-borrow-limit, duplicate prevention, queue position, receipt issuance) is invisible as a unit and untestable without HTTP.

3. **Renewal eligibility drift between UI and handler** — `borrower/index.php` computes the "Renew" button's eligibility using `strtotime($loan['due_date']) - time() <= 86400`, while `borrower/renew.php` uses `time() < $one_day_before_due`. The UI can display a Renew button that the handler will silently block.

4. **`get_setting()` has no request cache** — every call (loan period, reservation expiry, max borrow limit) hits the database. A single page makes 3–5 identical queries per request.

5. **Dashboard and My Books query the same domain objects differently** — `borrower/index.php` (Q1–Q7) and `borrower/my_books.php` (sections 1–5) both fetch active loans, loan history, pending and approved reservations with different shapes, limits, and logic. Data can be inconsistent between the two views.

6. **`includes/` and `src/utils/` duplicate each other** — `includes/circulation.php` (315 lines) is canonical; `src/utils/circulation.php` (83 lines) is a stripped subset with no callers. Maintainers cannot tell which to edit.

---

## Solution

Consolidate scattered, shallow modules into deep ones with coherent interfaces:

- Extract a `QueuePositionModule` so queue computation has one implementation.
- Extract a `BorrowerReservationModule` with `place()` and `cancel()` methods; `reserve.php` becomes a thin HTTP adapter.
- Extract a `RenewalEligibilityModule` with one `check()` method used by the dashboard, my_books, and the renewal handler.
- Add a request-scoped cache to `get_setting()` — zero call-site changes.
- Extract a `BorrowerAccountSummaryModule` that returns a canonical borrower account state used by both dashboard pages.
- Make `includes/` the canonical home for shared helpers; remove the inactive `src/utils/` copies.

---

## User Stories

### Queue Position

1. As a librarian, I want queue positions to reflect only non-expired pending reservations, so that borrowers see accurate wait times.
2. As a borrower, I want my queue position to be shown when I reserve a book, so I know when to expect approval.
3. As a developer, I want queue-position logic defined in one place, so that a change to the algorithm does not require editing three files.
4. As a developer, I want queue-position computation tested in isolation, so I can verify edge cases (same timestamp, same book, expired entries) without starting a web server.

### Reservation Placement

5. As a borrower, I want to be blocked from reserving when I have unpaid fines, so that the library's fine policy is enforced automatically.
6. As a borrower, I want to be blocked from reserving when I have overdue books, so that returned books are prioritised.
7. As a borrower, I want to be blocked from reserving when I have reached my maximum loan+reservation limit, so that inventory is shared fairly.
8. As a borrower, I want a reservation to be rejected when I already have an active reservation or loan for the same book, so that duplicates are prevented.
9. As a librarian, I want the system to compute my queue position at reservation time, so that FIFO order is guaranteed.
10. As a borrower, I want a receipt issued when my reservation is placed, so that I have proof of the transaction.
11. As a developer, I want the full reservation booking policy (all blocks, queue computation, receipt) testable without HTTP, so that CI tests run in milliseconds.
12. As a developer, I want a new blocking rule (e.g., max 2 reservations per book) to require editing one method, so that the change is localised.

### Reservation Cancellation

13. As a borrower, I want to cancel my own pending or approved reservation, so that I can free my spot for others.
14. As a system, I want cancellation to transition the reservation status to `cancelled`, so that audit logs are accurate.
15. As a developer, I want the cancel workflow to use the same `BorrowerReservationModule` as place, so that the two workflows share validation logic.

### Renewal Eligibility

16. As a borrower, I want the Renew button to appear only when I am actually eligible to renew, so that I am not misled by the UI.
17. As a borrower, I want to be blocked from renewing when I have unpaid fines, so that fine policy is enforced at renewal time too.
18. As a borrower, I want to be blocked from renewing when the book is overdue, so that returns are prioritised over extensions.
19. As a borrower, I want to be blocked from renewing when I have already used my renewal, so that loan periods are predictable.
20. As a borrower, I want to be blocked from renewing more than one day before the due date, so that loans cannot be indefinitely extended.
21. As a borrower, I want to be blocked from renewing when another member has a pending reservation for the same book, so that demand is served fairly.
22. As a developer, I want the dashboard and the renewal handler to compute eligibility from the same function, so that the UI and enforcement never disagree.
23. As a developer, I want renewal eligibility testable without the web UI, so that I can verify all block scenarios in a unit test.

### Settings Caching

24. As a system, I want settings to be fetched once per request, so that repeated `get_setting()` calls do not generate redundant database queries.
25. As a developer, I want the cache to be invisible to callers, so that no existing call sites need to change.
26. As a developer, I want `set_setting()` to invalidate the cache for that key, so that stale values are never returned.

### Borrower Account Summary

27. As a borrower, I want the dashboard and My Books page to show consistent loan and reservation data, so that I am not confused by conflicting information.
28. As a developer, I want one query plan for borrower account state, so that performance is optimised in one place.
29. As a future API consumer (mobile app, third-party integration), I want a single module that returns the full borrower account state, so that API consumers do not reimplement the dashboard queries.

### Module Hygiene

30. As a developer, I want each shared helper to have exactly one canonical file location, so that I always know which file to edit.
31. As a developer, I want inactive duplicate files removed, so that the codebase does not mislead future maintainers.

---

## Implementation Decisions

### New Modules

**`QueuePositionModule`**
- Interface: `compute(PDO $pdo, int $bookId, string $reservedAt, int $reservationId): int`
- Returns 1-based FIFO position within the book's pending+approved reservation queue
- Counts only reservations where `status IN ('pending','approved') AND expires_at >= NOW()`
- Replaces the triplicated nested-loop implementations in borrower pages
- Called from `reserve.php` (at placement) and `my_books.php` (at display)

**`BorrowerReservationModule`**
- Interface: `place(PDO $pdo, int $userId, int $bookId): ReservationPlaceResult`
  - Returns `success` + `queue_position` + `receipt` on success
  - Returns `failure` + `reason_code` on block (delinquent, max_limit, duplicate_loan, duplicate_res, etc.)
- Interface: `cancel(PDO $pdo, int $userId, int $reservationId): CancellationResult`
  - Returns `success` or `failure` + `reason_code`
- All booking policy enforcement lives here: delinquent-fines block, overdue-loan block, max-limit, duplicate checks, queue computation, receipt issuance, audit logging
- `borrower/reserve.php` becomes a thin HTTP adapter: `parseRequest() → module.place() → setFlash() → redirect()`

**`RenewalEligibilityModule`**
- Interface: `check(PDO $pdo, int $userId, int $loanId): RenewalEligibility`
  - Returns `{ eligible: bool, reason_code: string|null, new_due_date: string|null }`
  - Reason codes: `ok`, `delinquent`, `overdue`, `already_renewed`, `too_early`, `competing_reservation`
- Used by `borrower/index.php` and `borrower/my_books.php` to show/hide the Renew button
- Used by `borrower/renew.php` to enforce eligibility (with the same result, preventing UI/enforcement drift)
- All 6 eligibility rules defined in one function; no branching duplication across pages

**`BorrowerAccountSummaryModule`**
- Interface: `getSummary(PDO $pdo, int $userId): BorrowerAccountSummary`
  - Returns structured array: `{ active_loans[], approved_reservations[], pending_reservations[], rejected_reservations[], loan_history[], stats{} }`
- `active_loans` includes `days_overdue`, `renewal_eligible` (from `RenewalEligibilityModule`)
- `pending_reservations` includes `queue_position` (from `QueuePositionModule`)
- `stats` includes `currently_borrowed`, `total_borrowed`, `pending_count`, `due_soon_count`, `next_return`
- Replaces Q1–Q7 in `borrower/index.php` and sections 1–5 in `borrower/my_books.php`
- Single source of truth for borrower account state; both pages call one function

**Settings cache**
- `get_setting(PDO $pdo, string $key, string $default = ''): string` — static array cache, keyed by `$key`
- Cache invalidated on `set_setting()` for the same key
- No interface change at call sites

### File Reorganisation

- Canonical helpers remain in `includes/`; all `require_once` paths updated if needed
- `src/utils/` copies that are not imported by any file are removed (not migrated — no active use)
- `includes/circulation.php` absorbs the canonical implementation; `src/utils/circulation.php` is removed

### Interfaces Modified

- No public function signatures are removed; all existing call sites continue to work
- New optional return fields may be added to existing result arrays (e.g., `queue_position` in reservation results)
- `check_renewal_eligibility()` in `includes/circulation.php` — new function, not replacing any existing one

---

## Testing Decisions

### Good tests

A test is good if it exercises observable behaviour through the module's interface, without inspecting internal state. Tests should survive refactors to the implementation.

- **For `QueuePositionModule`**: Assert that position = 1 when no earlier reservations exist; position = N when N-1 earlier reservations exist; expired reservations are excluded; same-timestamp tie-breaking by ID.
- **For `BorrowerReservationModule.place()`**: Assert success when all conditions pass; assert each failure reason (delinquent, max_limit, duplicate_loan, duplicate_res) returns the correct `reason_code`; assert idempotency (second call returns existing reservation, not duplicate).
- **For `BorrowerReservationModule.cancel()`**: Assert own pending/approved reservation is cancelled; assert own non-cancellable statuses are rejected; assert foreign or non-existent reservations are rejected.
- **For `RenewalEligibilityModule.check()`**: Assert each of the 6 blocking reasons returns the correct code; assert eligible case returns `eligible=true` and correct `new_due_date`.
- **For `BorrowerAccountSummaryModule.getSummary()`**: Assert the returned structure contains all expected keys; assert active/pending/approved counts are non-negative integers; assert queue positions are ≥ 1.

### Modules to test

Priority order:
1. `RenewalEligibilityModule` — most drift-prone, highest value
2. `BorrowerReservationModule.place()` — complex conditional logic
3. `QueuePositionModule` — triplicated, easy to regression-test in one place
4. `BorrowerAccountSummaryModule` — future API caller depends on it
5. `get_setting()` cache — invisible to users but critical for performance

### Prior art

The existing codebase has no test suite. These tests will be the first for the borrower layer. Follow the pattern of: prepare PDO with in-memory SQLite or mocked rows → call module function → assert return structure.

---

## Out of Scope

- Adding tests for librarian-side modules (checkin, checkout, reservation approval)
- Adding an API layer (the `BorrowerAccountSummaryModule` is designed to be callable from an API later, but the API routes themselves are out of scope)
- Changing the database schema
- Changing the reservation approval workflow (librarian side)
- Implementing PHPMailer or email notifications
- Implementing the `redirect_to` field in the renewal form (present in `my_books.php` but unused by `renew.php`)

---

## Further Notes

- All new modules live in `includes/` alongside existing helpers — no new directories needed.
- No existing public function is removed; all changes are additive or internal-refactor.
- The `RenewalEligibilityModule` fix (Candidate 3) is the highest-urgency item because the current UI/handler drift means borrowers see a Renew button that silently fails.
- The `src/utils/` cleanup (Candidate 6) should be done first to avoid confusion during the other refactors.
- The deepening is all in-process (category 1 per DEEPENING.md — pure computation, no I/O beyond DB); tests can use PDO mocks or an in-memory SQLite database.