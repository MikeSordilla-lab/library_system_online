# LibraLink Context

Last updated: 06-14-2026

## Domain glossary

- **Borrower** — A library member who can browse the catalog, reserve books, view loans, and receive receipt tickets.
- **Librarian** — A staff user who manages circulation workflows: check-out, check-in, reservations, borrower records, fines, and circulation reports.
- **Admin** — A system manager who controls users, staff approval, settings, and audit visibility.
- **Book** — A catalog item with total copies and available copies.
- **Reservation** — A borrower request for a book. Open reservation states are `pending` and `approved`; closed states include `rejected`, `expired`, `fulfilled`, and `cancelled`.
- **Reservation Queue** — FIFO ordering for open, non-expired reservations for the same book. Ties are ordered by reservation id.
- **Approved Hold** — An approved, non-expired reservation that consumes a physical copy until pickup, expiry, cancellation, or fulfillment.
- **Circulation** — Loan lifecycle records for checked-out books, including due dates, return dates, status, and fines.
- **Check-Out** — Librarian transaction that creates a circulation row, decrements book availability, fulfills an approved hold when applicable, logs the action, and issues a receipt ticket.
- **Check-In** — Librarian transaction that returns a circulation row, assesses any fine, restores book availability, logs the action, and issues a receipt ticket.
- **Fine** — Money owed by a borrower after a late return. Fines are assessed at check-in and paid later.
- **Receipt Ticket** — Auditable proof of a workflow action, with integrity data, public/view models, and optional reprint logs.
- **Catalog Discovery** — Search, filter, sort, and discovery-list behavior for public and borrower catalog surfaces.
- **Admin User Management** — Admin workflows for account creation, role changes, staff approval, verification, suspension, deletion, and undo.
- **Librarian Reservation Management** — Librarian workflows for reviewing, approving, rejecting, and monitoring reservations.
- **Circulation Records Report** — Filtered, enriched report model for circulation records used by screen and print adapters.
- **Application Bootstrap** — Canonical loading path for config, constants, database access, helpers, middleware, session, and current-user context.
