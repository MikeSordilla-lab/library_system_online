# Task Plan: Reservation Queue, Book Availability, and Checkout Deepening

## Goal
Deepen three circulation/reservation modules so queue policy, hold capacity, and librarian checkout transactions have one authoritative implementation and are testable through module interfaces.

## Current Phase
Complete

## Scope
Completed first three architecture candidates:
1. Reservation Queue Module
2. Book Availability / Hold Capacity Module
3. Librarian Checkout Transaction Module

New scope from user request: implement Architecture Review Round 2 candidates 1-7:
1. Admin User Management Module
2. Librarian Check-In Transaction Module
3. Circulation Records Report Module
4. Catalog Discovery / Availability Module
5. Receipt Ticket Workflow / Presentation Modules
6. Application Bootstrap / Include Path Module
7. Librarian Reservation Management Module

Out of scope unless needed for integration:
- Database schema changes
- UI redesign
- Composer/package changes
- Renewal logic (not present in current borrower tree)

## Phases

### Phase 0: Planning Setup
- [x] Restore existing planning context
- [x] Run session catchup
- [x] Replace completed prior-task plan with this new implementation plan
- **Status:** complete

### Phase 1: Baseline & Safety Checks
- [x] Check git status and current modified files
- [x] Re-read relevant modules/pages before editing
- [x] Identify exact interfaces to introduce or extend
- [x] Add findings for current duplicate logic and risk points
- **Status:** complete

### Phase 2: Reservation Queue Module
- [x] Make queue policy authoritative in `includes/QueuePositionModule.php`
- [x] Add a pending queue fetch method for librarian pages
- [x] Align expiry and status handling across borrower/librarian queue displays
- [x] Update `includes/circulation.php` to delegate legacy queue calls
- [x] Update `librarian/reservations.php` if needed to use the module seam
- **Status:** complete

### Phase 3: Book Availability / Hold Capacity Module
- [x] Add a deep module for book availability and hold capacity rules
- [x] Replace duplicated approved-hold/copy calculations in reservation and checkout policy code
- [x] Expose catalog-friendly availability fields without duplicating policy
- [x] Update borrower catalog/reservation and librarian checkout integration points
- **Status:** complete

### Phase 4: Librarian Checkout Transaction Module
- [x] Extract direct checkout and approved-reservation pickup transaction behavior from `librarian/checkout.php`
- [x] Keep `librarian/checkout.php` as a thin HTTP adapter mapping results to flash messages
- [x] Preserve existing receipts, audit logs, row locks, and redirects
- **Status:** complete

### Phase 5: Regression Tests
- [x] Add/extend tests for queue position with pending/approved/expired and tie-breaks
- [x] Add/extend tests for availability with approved holds and no-copy cases
- [x] Add/extend tests for checkout success/failure and pickup fulfillment through module interface
- **Status:** complete

### Phase 6: Verification & Delivery
- [x] Run PHP syntax checks on modified files
- [x] Run relevant PHP regression tests
- [x] Run focused searches for old duplicate logic
- [x] Update planning files with results
- [x] Summarize changed files and verification
- **Status:** complete

## Decisions Made
| Decision | Rationale |
|----------|-----------|
| Preserve existing public helper functions where practical | Reduces caller risk while moving implementation behind deeper modules |
| Use `includes/` for new shared modules | Existing canonical shared helpers live there per project guidance |
| Avoid schema changes | User asked for architecture deepening, not migrations |
| Treat existing pages as adapters | Matches the desired deepening outcome and improves test surface |

## Errors Encountered
| Error | Attempt | Resolution |
|-------|---------|------------|
| None yet | - | - |

### Phase 7: Architecture Review Round 2
- [x] Restore planning context and run session catchup
- [x] Read architecture skill vocabulary and domain docs
- [x] Explore current codebase friction after recent deepening work
- [x] Record findings
- [x] Present numbered deepening opportunities without proposing interfaces
- **Status:** complete

### Phase 8: Implement Architecture Review Candidates 1-7
- [x] Restore planning context and run session catchup
- [x] Establish module interfaces and low-risk adapter boundaries
- [x] Implement Admin User Management module seam
- [x] Implement Librarian Check-In transaction module seam
- [x] Implement Circulation Records Report module seam
- [x] Implement Catalog Discovery / Availability module seam
- [x] Implement Receipt Ticket workflow/presentation module seam
- [x] Implement Application Bootstrap / Include Path cleanup
- [x] Implement Librarian Reservation Management module seam
- [x] Add/extend regression tests for new module interfaces
- [x] Run syntax and regression tests
- **Status:** complete

## Notes
- Existing planning files described a completed email/SweetAlert task and were replaced for this new work.
- `session-catchup.py` produced no output, so no unsynced context was detected.
- Architecture review uses the improve-codebase-architecture vocabulary: Module, Interface, Implementation, Depth, Seam, Adapter, Leverage, Locality.
- User requested implementing Architecture Review Round 2 candidates 1-7 in one pass; implement incrementally with page adapters preserved and no schema changes.
