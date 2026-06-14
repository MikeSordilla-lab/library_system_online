# Findings: Reservation Queue, Book Availability, and Checkout Deepening

## Initial Context
- User asked to implement the first three architecture opportunities:
  1. Reservation Queue Module
  2. Book Availability / Hold Capacity Module
  3. Librarian Checkout Transaction Module
- Domain docs read earlier identify LibraLink concepts: Borrowers, Librarians, Books, Circulation, Reservations, Receipt Tickets, System Logs.
- No ADRs were found under `docs/adr/`.

## Known Baseline From Prior Exploration
- Queue display is split:
  - Borrower pages use `includes/QueuePositionModule.php` through `BorrowerAccountSummaryModule`.
  - Librarian pending queue uses `get_pending_reservation_queue()` in `includes/circulation.php`.
  - Current implementations differ on open statuses and expiry handling.
- Book availability is interpreted in several places:
  - `borrower/catalog.php` uses `available_copies > 0`.
  - `BorrowerReservationModule::place()` subtracts approved holds from available copies.
  - `checkout_reservation_policy()` blocks checkout when another borrower has an approved hold.
  - `librarian/checkout.php` separately checks raw `available_copies`.
- `librarian/checkout.php` is a large HTTP adapter plus transaction implementation; it owns validation, locking, checkout/pickup branching, copy decrement, reservation fulfillment, audit logs, receipt issuance, and flash redirects.

## Constraints
- PHP 8.x, MySQL PDO, no Composer.
- Shared helpers/modules should live in `includes/`.
- Public/role pages use includes and session flash messages.
- Must preserve existing behavior and audit/receipt side effects.
- No database migrations for this task.

## Open Questions / To Confirm During Phase 1
- Exact current shape of `librarian/checkout.php` POST branch and all flash messages to preserve.
- Whether tests can safely call new checkout module using existing MySQL test setup.
- Whether catalog needs policy-aware availability display or only reserve-button gating.

## Phase 1 Baseline Findings
- Current resume state: `git status --short` shows in-progress code changes in `includes/QueuePositionModule.php`, `includes/BorrowerReservationModule.php`, `includes/circulation.php`, new `includes/BookAvailabilityModule.php`, and planning files.
- `QueuePositionModule` now has `getPendingQueue(PDO): array` and legacy `get_pending_reservation_queue()` delegates to it.
- New `BookAvailabilityModule` centralizes book snapshots, approved-hold counts, checkout policy, FIFO approved-hold lookup, and catalog SQL/helper availability fields.
- `BorrowerReservationModule::place()` now delegates reserve-capacity checks to `BookAvailabilityModule::canReserve()`.
- `reject_pending_reservations_when_no_copies_left()`, `checkout_reservation_policy()`, and `checkout_reservation_to_fulfill()` now delegate hold-capacity/approved-hold policy to `BookAvailabilityModule`.
- `borrower/catalog.php` previously filtered, badged, and enabled reserve actions based on raw `b.available_copies`; it now needs verification after being updated to use policy-aware reservable-copy SQL from `BookAvailabilityModule`.
- `librarian/checkout.php` was the monolithic transaction owner; it now needs verification after being refactored into a thin adapter around `LibrarianCheckoutModule::process()`.
- Existing `tests/borrower_workflow_regression.php` now includes queue-position assertions for pending/approved/expired reservations plus same-timestamp tie-breaks, and module-level assertions for direct checkout, approved pickup fulfillment, and another-borrower approved-hold blocking.
- `LibrarianCheckoutModule` can run inside an existing transaction for regression tests, while production callers get module-owned begin/commit/rollback behavior.
- `librarian/reservations.php` already calls `expire_stale_reservations()` before fetching pending rows and uses `get_pending_reservation_queue()`, so the delegated queue module is wired into the librarian pending queue.
- `git status --short` initially shows only planning-file modifications.
- `QueuePositionModule::compute()` and `computeBatch()` already count `pending` + `approved` reservations with `expires_at >= NOW()` and tie-break by `reserved_at ASC, id ASC`.
- Legacy `get_pending_reservation_queue()` in `includes/circulation.php` only counts `pending` reservations and does not exclude expired reservations in its queue-position subquery.
- `BorrowerReservationModule::place()` manually locks the book, checks `total_copies`, counts approved holds, and subtracts holds from `available_copies`; this is a good seam for a Book Availability / Hold Capacity Module.
- `reject_pending_reservations_when_no_copies_left()` repeats the approved-hold subtraction rule from reservation placement.
- `checkout_reservation_policy()` and `checkout_reservation_to_fulfill()` each implement approved-hold ordering independently.
- `librarian/checkout.php` POST branch maps many result cases to exact flash messages; the checkout module should return reason codes so the page can preserve those messages.
- Existing tests in `tests/borrower_workflow_regression.php` already use transaction-wrapped fixtures and cover checkout reservation policy, reservation fulfillment, approved-hold capacity, and borrower reservation no-copy cases. Extending this file is lower friction than adding a new harness.
- `borrower/catalog.php` filters and buttons currently use raw `b.available_copies`; a policy-aware availability module can add reservable copy counts while preserving the page query structure.
- `Reservations.expires_at` is `NOT NULL` in the canonical schema, so non-expired predicates can use `expires_at >= NOW()` without a null fallback.

## Interface Decisions Before Editing
| Module | Interface shape | Notes |
|--------|-----------------|-------|
| `QueuePositionModule` | Add `getPendingQueue(PDO): array`; keep `compute()` and `computeBatch()` | Existing callers continue working; legacy `get_pending_reservation_queue()` can delegate. |
| `BookAvailabilityModule` | Add availability snapshots plus `canReserve()`, `checkoutPolicy()`, and approved-hold lookup methods | Centralizes approved-hold counting and FIFO approved-hold selection. |
| `LibrarianCheckoutModule` | Add `checkout()`, `pickup()`, and `process()` returning result arrays with `reason_code` | Page adapter maps reason codes to existing flash messages; module owns transaction and receipt/audit side effects. |

## Architecture Review Round 2 Findings
- Root `CONTEXT.md` / `CONTEXT-MAP.md` was absent at review start and `docs/adr/` is absent; use vocabulary from `docs/business-logic-audit.md` and `docs/prd-borrower-architectural-deepening.md` as available domain language.
- `CONTEXT.md` was created during implementation to record terms used by the deepened modules: Admin User Management, Librarian Check-In, Circulation Records Report, Catalog Discovery, Receipt Ticket, Application Bootstrap, and Librarian Reservation Management.
- Skill vocabulary loaded: Module, Interface, Implementation, Depth, Seam, Adapter, Leverage, Locality; avoid substituting component/service/API/boundary.
- Architecture review should present candidates only and ask which one to explore; do not propose interfaces yet.
- Largest active PHP files after PHPMailer are `admin/users.php` (~115 KB), `borrower/my_books.php` (~56 KB), root `catalog.php` (~55 KB), `librarian/reservations.php` (~41 KB), `borrower/catalog.php` (~35 KB), `librarian/borrowers.php` (~30 KB), `includes/receipts.php` (~30 KB), `librarian/checkout.php` (~29 KB), and `librarian/checkin.php` (~27 KB).
- `BorrowerAccountSummaryModule` already deepens borrower account state for `borrower/index.php` and `borrower/my_books.php`, but it remains query-heavy and does not include renewal eligibility in the current code read.
- `borrower/renew.php` does not exist in the current tree despite older docs referencing it; renewal appears absent or not wired in the borrower directory.
- `librarian/checkin.php` still owns check-in transaction behavior, fine calculation, available-copy restoration, receipt issuance, and pending-reservation alert, while also mutating overdue loans on GET.
- `admin/users.php` centralizes many Admin User Management workflows inline: undo, bulk update, create, staff approval, edit credentials, admin password reset, verification change, role changes, suspend/reinstate, delete, filtering, and rendering.
- `catalog.php` (public OPAC) independently implements search/filter/sort/discovery queries and uses raw `available_copies`; borrower catalog now uses `BookAvailabilityModule`, so public catalog availability semantics can drift.
- `includes/receipts.php` is a large module with many function-level interfaces covering issue/idempotency/integrity, lookup, access, payload masking, view model, public data, print jobs, and reprints; deletion test suggests some behaviour is real depth, but the single file has low locality between issuing and presentation.
- `librarian/records.php` builds report filtering and pagination inline, then delegates status/fine calculations to `circulation_*` helper functions. `librarian/records-print.php` likely repeats part of this query/filter logic.
- Test coverage exists for borrower workflows, records report helper logic, staff approval pure helpers, receipts currency, pagination, CSRF, mailer, and avatar display; large page-owned workflows like Admin User Management, check-in, public catalog, and reservation approval have little direct module test surface.
- `librarian/records.php` and `librarian/records-print.php` duplicate date parsing, filter normalization, SQL construction, report enrichment, report-type/status filtering, and summary building. This is a strong deletion-test candidate for a Circulation Records Report module.
- `librarian/borrowers.php` owns Borrower Management transactions and deletion cascade cleanup. Admin User Management owns overlapping user lifecycle rules, with different helper/logging choices (`log_event` vs `log_admin_action`).
- `includes/settings.php` already has request-scoped caching and invalidation; do not re-suggest old settings-cache work from the PRD.
- Raw availability predicates remain in public `catalog.php` and librarian checkout GET book picker. Some raw copy validation in catalog add/edit is a different book-inventory editing concern, not the approved-hold availability policy.
- `bootstrap.php` documents itself as the central application bootstrap but requires several missing `src/` files (`src/config/constants.php`, `src/utils/avatar.php`, `src/utils/circulation.php`, `src/utils/settings.php`, `src/middleware/auth_guard.php`, `src/middleware/csrf.php`). Current active pages mostly include `includes/*` directly, while receipt API endpoints use `api/receipts/_bootstrap.php`.
- The codebase has an unresolved seam between `includes/` and `src/`: `src/config` and a few `src/utils` files are active, but canonical domain helpers live in `includes/`. This makes bootstrap/config locality weak and can mislead future maintainers.

### Architecture Candidate Notes
1. Admin User Management: `admin/users.php` has many inline workflows and repeated protected-account/last-admin/undo/logging behaviour. Strong candidate for a deep module with page adapter.
2. Librarian Check-In: `librarian/checkin.php` still owns transaction behaviour, fine assessment, copy restoration, receipt issuance, reservation alert, and overdue mutation on GET. Strong candidate after checkout deepening.
3. Circulation Records Report: `librarian/records.php` and `librarian/records-print.php` duplicate report filter/date/query/enrich/summary behaviour. Strong candidate for a report module used by screen and print adapters.
4. Catalog Discovery / Availability: public `catalog.php` and borrower catalog have separate query/filter/discovery implementations and different availability semantics after borrower catalog moved to hold-aware availability.
5. Receipt Tickets: `includes/receipts.php`, receipt pages, and receipt JSON endpoints have real behaviour but many function-level seams and duplicated adapter logic; candidate for deeper workflow/presentation modules.
6. Application Bootstrap / Include Path: `bootstrap.php` is stale and not the active seam for pages; several required `src/` files are missing. Candidate for canonical bootstrap/include-path deepening.
7. Librarian Reservation Management: `librarian/reservations.php` still owns approve/reject transactions, mail side effects, auto-reject, and list/history queries even though queue policy is now centralized.

### Implementation Notes for Candidates 1-7
- Admin User Management: added `includes/AdminUserManagementModule.php`; routed Admin role changes and librarian request approve/reject decisions through it while preserving page flash/redirect behavior.
- Librarian Check-In: added `includes/LibrarianCheckinModule.php`; `librarian/checkin.php` now delegates return transaction/fine/copy/receipt behavior and overdue marking through the module seam.
- Circulation Records Report: added `includes/CirculationRecordsReportModule.php`; screen and print records pages now share one report assembly implementation.
- Catalog Discovery / Availability: added `includes/CatalogDiscoveryModule.php`; public catalog and librarian checkout picker now use hold-aware availability; borrower catalog already used `BookAvailabilityModule` SQL helpers.
- Receipt Ticket Workflow / Presentation: added `includes/ReceiptTicketWorkflowModule.php`; receipt create/reprint API endpoints and receipt index listing now use the module seam.
- Application Bootstrap / Include Path: updated `bootstrap.php` to load existing canonical `includes/` files instead of missing `src/` files.
- Librarian Reservation Management: added `includes/LibrarianReservationManagementModule.php`; `librarian/reservations.php` now delegates approve/reject/list model behavior through it.
