# Business Logic Audit — LibraLink (Three User Types)

**Date:** 2026-05-25  
**Scope:** All business logic, role separation, and data flow between `admin`, `librarian`, and `borrower` roles.  
**Method:** Source code review of all PHP files in `admin/`, `librarian/`, `borrower/`, `includes/`, `src/`, plus database schema.

---

## 1. Role-Based Access Control (RBAC)

### Guard Mechanism (`includes/auth_guard.php`)
- Enforced at the very top of every protected page via `$allowed_roles = ['admin']` pattern.
- Re-validates session against DB every request: `SELECT id, role FROM Users WHERE id = ? AND is_verified = 1 AND is_suspended = 0`.
- Stale/deleted/suspended accounts are auto-logged-out.
- Role synced from DB on every request (catches admin-initiated role changes).
- CSRF, rate limiting, and security headers applied globally via `bootstrap.php`.

### Role Boundaries
| Page | Guard | Access |
|------|-------|--------|
| `admin/*` | `['admin']` | Admin only |
| `librarian/*` | `['librarian']` | Librarian only |
| `borrower/*` | `['borrower']` | Borrower only |
| `index.php`, `catalog.php`, `login.php`, `register.php` | Public | Unauthenticated |
| `logout.php` | N/A | Any authenticated |

**No role overlap** — every authenticated page is single-role-gated. There is no page accessible to multiple roles (admin + librarian, etc.) outside of public pages.

---

## 2. Borrower Business Logic

### 2.1 Registration (`register.php`)
- **Account type**: Self-registration allows `borrower` (immediate, with OTP) or `librarian` (requires admin approval).
- **Rate limit**: 5 attempts per 15 minutes (session sliding window).
- **Validation**: Name required, email format, password complexity (8+ chars, uppercase, lowercase, digit, special char), confirmation match.
- **Duplicate email**: Generic error message — no email enumeration.
- **OTP**: 6-digit cryptographically random code, stored as `verification_token`.
- **Borrower**: Inserted with `is_verified=0` → must verify via OTP email before login.
- **Librarian**: Inserted with `is_verified=0`, `is_suspended=1`, `staff_approval_status='pending'` — locked until admin approves.

### 2.2 Login (`login.php`)
- **Identifier**: Email OR `full_name` (username-style login).
- **Lockout**: 5 failed attempts → 15-minute `locked_until`.
- **Unverified block**: Specific message for unverified accounts (allowed by spec).
- **Suspended block**: Flash error, redirect to login.
- **Session regeneration**: `session_regenerate_id(true)` after successful auth.
- **Audit logging**: LOGIN_SUCCESS and LOGIN_FAILED with email capture.

### 2.3 Dashboard (`borrower/index.php`)
- Displays: active loans, approved/pending/rejected reservations, loan history, stats (currently borrowed, total borrowed, due soon count, next return, total fines).
- Activity charts: category distribution (donut), monthly checkout activity (last 12 months bar), reading timeline.
- Renewal modal with error messaging.
- Calls `BorrowerAccountSummaryModule` which queries all loans + reservations + history.

### 2.4 Catalog (`borrower/catalog.php`)
- Search by title/author/ISBN/category with LIKE wildcards.
- Filter by category, availability (available/not_available).
- Sort by title, newest, popular.
- Paginated (12 per page).
- Shows `has_active_reservation` flag per book.
- Reserve buttons per book + multi-select for bulk reservation (up to 10).

### 2.5 Reservations (`borrower/reserve.php` → `BorrowerReservationModule`)
- **Place** (`BorrowerReservationModule::place()`):
  - Checks: book exists, book is borrowable (`total_copies >= 1`), no unpaid fines, no overdue loans, not at `max_borrow_limit`, no duplicate reservation, no duplicate active loan.
  - Creates reservation with status `pending`, `expires_at = NOW() + reservation_expiry_days`.
  - Computes FIFO queue position.
  - Issues receipt ticket.
- **Place Bulk** (`placeBulk()`): Up to 10 books, each validated independently.
- **Cancel** (`cancel()`):
  - Ownership check: only the borrower who placed it can cancel.
  - Only `pending` or `approved` reservations can be cancelled.
  - Uses `transition_reservation_status` with optimistic concurrency.

### 2.6 Renewals (`borrower/renew.php` → `RenewalEligibilityModule`)
- Eligibility checks (strict order):
  1. Loan exists and belongs to user.
  2. No unpaid fines (delinquent).
  3. Not overdue (`status != 'overdue'` and `due_date > NOW()`).
  4. Status is `active`.
  5. `renewal_count < MAX_RENEWALS` (defined as 1).
  6. Within 1 day of due date (`time() >= due_ts - 86400`).
  7. No competing reservation (pending/approved from another user for same book).
- On success: extends `due_date` by `loan_period_days`, sets status to `active`, increments `renewal_count`.

### 2.7 Profile Management
- Change own name, email, password, birthdate, address, phone, avatar.
- Cannot change own role.

---

## 3. Librarian Business Logic

### 3.1 Dashboard (`librarian/index.php`)
- Stats: total books, total copies, available copies, total loans, active loans, overdue loans, returned today, pending reservations, approved pickups, total borrowers.

### 3.2 Checkout (`librarian/checkout.php`)

**Direct checkout flow:**
1. Expires stale reservations.
2. Locks book row `FOR UPDATE`, verifies `available_copies >= 1`.
3. Locks borrower row, verifies `role='borrower'`, `is_verified=1`, `is_suspended=0`.
4. **Delinquency check**: Unpaid fines > 0 → blocked.
5. **Overdue check**: Overdue active/overdue loans → blocked.
6. **Max borrow limit**: `active_loans + pending/approved reservations >= max_borrow_limit` → blocked.
7. **Duplicate loan**: Existing active/overdue loan for same user+book → blocked.
8. Creates loan with `due_date = NOW() + loan_period_days`.
9. Decrements `available_copies`.
10. Fulfills any reservation the borrower has for this book (approved priority, fallback pending).
11. Issues receipt ticket.
12. All in one PDO transaction.

**Pickup flow (from reservation):**
- Librarian selects an approved reservation from the "Ready for Pickup" table.
- Same validations as checkout, but `user_id` and `book_id` are derived from the reservation.
- Reservation is marked `fulfilled`.

**Reservation policy** (`checkout_reservation_policy()`):
- Before checkout, checks if another borrower has an approved reservation for the same book.
- If yes, checkout is blocked with `reason_code: 'reserved_for_another_borrower'`.

### 3.3 Check-In / Return (`librarian/checkin.php`)

1. **Bulk overdue update** (on GET): All active loans past `due_date` are marked `status='overdue'`.
2. **Fine calculation**:
   - `days_late = ceil((return_ts - due_ts) / 86400)`.
   - `fine = max(0, days_late * fine_per_day)`.
   - Rounded to 2 decimals.
3. **Transactional update**:
   - `complete_checkin_loan()`: Sets `return_date=NOW()`, `status='returned'`, `fine_amount`, `fine_paid=0` (if fine > 0) or `fine_paid=1` (if no fine).
   - Restores `available_copies = LEAST(available_copies + 1, total_copies)` (safety clamp).
4. **Reservation alert**: If pending reservations exist for this book, librarian is notified to "place it on the Hold shelf."
5. Issues receipt ticket.

### 3.4 Catalog Management (`librarian/catalog*.php`)
- **Add**: Validates title, author, ISBN uniqueness, category, copies.
- **Edit**: Updates book details, ISBN uniqueness enforced on edit.
- **Delete**: Removes book from catalog.
- All CRUD operations audited via `log_circulation()`.

### 3.5 Reservation Management (`librarian/reservations.php`)

**Pending queue:**
- FIFO order (`reserved_at ASC, id ASC`).
- Each pending reservation shows FIFO queue position (computed by correlated subquery).
- **Approve**: `transition_reservation_status(pending → approved)`, records `approved_at`, `approved_by`.
- **Reject**: `transition_reservation_status(pending → rejected)`, records `rejected_at`, `rejected_by`, optional `rejection_reason`.
- Uses optimistic concurrency: `UPDATE ... WHERE id = ? AND status = ?`.

**Awaiting Pickup tab:**
- Shows approved reservations with expiry, approved-by info.
- Expiring-soon highlighting (≤24 hours).

**History tab:**
- Shows approve/reject/fulfilled records with processed-by names.

### 3.6 Fine Management

**Clear individual fine** (`librarian/clear_fines.php`):
- POST: Marks single circulation row `fine_paid=1`, `fine_paid_at=NOW()`.
- Validates: loan exists, `fine_amount > 0`, `fine_paid = 0`.
- Uses SweetAlert2 confirmation dialog.
- Issues receipt ticket.

**Pay all fines** (`librarian/pay-fine.php`):
- POST: Calls `mark_returned_positive_fines_paid()` which finds all returned loans with `fine_paid=0` and `fine_amount > 0` for a user, marks them paid in one transaction.
- Uses `FOR UPDATE` row locks.

### 3.7 Borrower Management (`librarian/borrowers.php`)
- View borrower list (name, email, status, loans count).
- Edit borrower info (name, email).
- Reset borrower password.
- Delete borrower account.
- All operations scoped to `role='borrower'` only.

---

## 4. Admin Business Logic

### 4.1 Dashboard (`admin/index.php`)
- Total users, total books (sum of copies), active loans, pending reservations.

### 4.2 User Management (`admin/users.php` — 2,445 lines)
- **Create user**: Full form with role selection, auto-verified for admin/librarian roles.
- **Change role**: Admin can change any user's role. Guarded by:
  - Cannot change role of superadmin.
  - Cannot demote the last active admin.
- **Suspend/Reinstate**: Marks `is_suspended`. Emails notification via PHPMailer.
- **Delete user**: Full deletion. Guarded by:
  - Cannot delete superadmin.
  - Cannot delete the last active admin.
  - Warns if user has active loans.
- **Undo**: Session-stored undo token for last action (expires after timeout).
- **Superadmin protection**: `is_superadmin=1` users cannot be deleted, demoted, suspended, or changed by undo.
- **Last-admin guard**: `count_active_admins()` ensures ≥1 non-suspended admin always exists.

### 4.3 Settings (`admin/settings.php`)
- Editable: `max_borrow_limit` (int ≥ 1), `fine_per_day` (decimal ≥ 0), `loan_period_days` (int ≥ 1), `reservation_expiry_days` (int ≥ 1).
- Only changed values are updated + logged. No-op if no changes detected.

### 4.4 Reports (`admin/reports.php`)
- Total fines collected (sum of `fine_amount` where `fine_paid = 1`).
- Active loans count.

### 4.5 Audit Log (`admin/audit-log.php`)
- Full `System_Logs` table viewer with search and date filter.

### 4.6 Librarian Registration Approval (`admin/users.php`)
- Admin sees pending librarian requests in the user list.
- Approve: sets `staff_approval_status='approved'`, `is_suspended=0`, `is_verified=1`.
- Reject: sets `staff_approval_status='rejected'`, with optional reason.

---

## 5. Cross-Cutting Business Rules

### 5.1 Fine Rules
| Action | Blocked by unpaid fines? | Blocked by overdue books? |
|--------|--------------------------|---------------------------|
| Checkout | Yes (≥ ₱0.01) | Yes (≥ 1 overdue loan) |
| Place reservation | Yes | Yes |
| Renew loan | Yes | Yes (loan must be active) |
| Login | No | No |
| View catalog | No | No |

### 5.2 Max Borrow Limit
- Default: 5 (set in `Settings` table).
- Calculation: `active_loans + pending_reservations + approved_reservations >= max_borrow_limit`.
- Counted differently in `checkout.php` vs `BorrowerReservationModule::place()`:
  - Checkout: `active/overdue loans + pending/approved reservations (excluding this book) >= max_limit`.
  - Reservation: `active/overdue loans + pending/approved reservations >= max_limit`.
  - **Note**: `checkout.php` excludes current book from reservation count; `place()` does not. This is because when placing a reservation, the book isn't checked out yet, so it shouldn't be excluded from count. When checking out, the reservation for the current book will be fulfilled, so it's excluded. This is correct logic.

### 5.3 Reservation Lifecycle
```
pending ──→ approved ──→ fulfilled (via checkout/pickup)
  │            │
  │            └──→ expired (if expires_at < NOW())
  │
  ├──→ rejected (by librarian)
  ├──→ cancelled (by borrower)
  └──→ expired (if expires_at < NOW())
```

### 5.4 Loan Status Lifecycle
```
active ──→ overdue (auto-updated on checkin page GET, or when due_date < NOW())
  │            │
  │            └──→ returned (via checkin with fine assessment)
  │
  └──→ returned (via checkin, no fine if returned before due)
```

### 5.5 Fine Payment Lifecycle
- Fines assessed at return time only.
- `fine_paid` is set automatically: `1` if fine = 0, `0` if fine > 0.
- Librarian manually marks as paid via `clear_fines.php` or `pay-fine.php`.
- Active/overdue loans' fines are NOT yet assessed (no `return_date`).
- `get_unpaid_fines_total()` sums `fine_amount WHERE fine_paid=0`.

---

## 6. Security & Integrity Mechanisms

| Mechanism | Where | What it prevents |
|-----------|-------|------------------|
| `FOR UPDATE` row locks | checkout, checkin, reservation place/cancel, fine payment | Race conditions on concurrent requests |
| `UPDATE ... WHERE id = ? AND status = ?` | transition_reservation_status() | Lost updates / double-processing |
| `LEAST(available_copies + 1, total_copies)` | checkin | Copies exceeding total_copies |
| PDO transactions | All write operations | Partial writes on failure |
| Prepared statements | Every query | SQL injection |
| `htmlspecialchars()` with ENT_QUOTES | All output | XSS |
| CSRF tokens | All POST forms | Cross-site request forgery |
| Session regeneration | login.php | Session fixation |
| Rate limiting | login.php, register.php | Brute force |
| Generic error messages | login.php, register.php | User enumeration |
| `hash_equals()` | undo token comparison, password change | Timing attacks |
| Password complexity | register.php | Weak passwords |

---

## 7. Identified Findings & Observations

### Minor Issues / Design Observations

1. **`find` in `checkout.php` line 404**: `$q = fn($sql) => $pdo->query($sql)->fetchColumn();` — uses raw SQL without parameterization for count queries. Low risk as these use hardcoded SQL with no user input, but inconsistent with the rest of the codebase.

2. **`fine.daily_rate` setting (infinityfree-import.sql:257)**: There's a second fine rate setting `'fine.daily_rate' => '1.00'` that appears to be unused/dead. The actual code reads `'fine_per_day'`. This is likely a legacy or unused setting.

3. **`max_loan_days` setting (infinityfree-import.sql:260)**: Set to 7 in defaults but code uses `loan_period_days`. This appears to be a legacy/unused setting.

4. **Overdue status bulk update (`checkin.php:159-162`)**: Runs `UPDATE Circulation SET status='overdue' WHERE status='active' AND due_date < NOW()` on every GET request. This is a side-effect on a read request. Consider moving to a cron job or triggering on login/checkout instead.

5. **`SECONDS_PER_HOUR`, `SECONDS_PER_MINUTE` constants defined but not used anywhere** in the codebase.

6. **`$GLOBALS['_APP_ENV']` pattern (config.php)** — The env variable fallback chain exists for shared hosting compatibility, but it introduces a second source of truth beyond `getenv()`.

7. **Staff approval columns added dynamically** (`ensure_staff_approval_support()`) — schema migrations run on page load rather than as a one-time migration. This is pragmatic but non-standard.

8. **Renewal eligibility** — checks `renewal_count` column existence via `circulation_column_exists()` every request, which involves a `SHOW COLUMNS` query. Cached per request via static variable, but still a schema reflection pattern.

9. **`pay-fine.php` vs `clear_fines.php`**: Two separate endpoints for fine payment — `clear_fines.php` handles individual fine clearing (POST per row), `pay-fine.php` handles bulk (all fines for a user). The `pay-fine.php` endpoint is not directly linked from the UI (UI uses `clear_fines.php` with individual buttons). **Note**: The checkout flow gives a flash message about unpaid fines but does not provide a direct link to fine payment.

10. **Reservation queue tie-breaking**: Uses `reserved_at ASC, id ASC` for FIFO ordering. This is correct but if two reservations have the same `reserved_at` timestamp (rare), the lower `id` wins, which could theoretically be influenced by insertion order in a transaction.

### Architecture Observations

11. **Admin has no direct checkout/checkin capability** — this is by design; admins manage users and settings while librarians handle circulation operations.

12. **No bulk checkout flow** — each checkout must be done one book at a time through the librarian form.

13. **No automatic fine accumulation** — fines are only calculated at return time. A book could be overdue for months and accrue no fine until returned.

14. **The reservation approval step** requires a librarian to manually approve → creates a "hold shelf" stage before checkout. This introduces latency but allows librarians to manage physical book availability.

15. **Reduced/fine payment separation**: `fine_paid` and `fine_paid_at` are on the `Circulation` row, not a separate payments table. This makes it hard to track partial payments or payment history.

16. **`log_circulation()` vs `log_event()`**: Two separate logging functions — `log_circulation()` (in circulation.php) inserts System_Logs with 6 columns; `log_event()` (in db.php) inserts System_Logs with 7 columns (includes `email_address`). This is a minor inconsistency; some events are logged without email capture.

17. **`src/middleware/` directory** contains only `security_headers.php`. The actual middleware (`auth_guard.php`, `csrf.php`) lives in `includes/`. The directory structure is not fully migrated to `src/`.

18. **No explicit `updated_at` on `Circulation` table** — unlike `Users`, `Books`, `Reservations`, and `Settings`.

---

## 8. Summary

| Aspect | Borrower | Librarian | Admin |
|--------|----------|-----------|-------|
| **Registration** | Self-service (OTP) | Requires admin approval | Created by admin |
| **Catalog** | View only | Full CRUD | View only |
| **Checkout** | N/A | Process + pickups | N/A |
| **Check-in** | N/A | Process + fines | N/A |
| **Reservations** | Place/Cancel | Approve/Reject | View counts |
| **Fines** | Pay (at library) | Clear/process payments | Set rates |
| **Users** | Own profile | View borrowers | Full CRUD |
| **Settings** | N/A | N/A | Edit all |
| **Reports** | Own stats | Circulation records | Fines + loans |
| **Audit** | N/A | N/A | Full audit log |
