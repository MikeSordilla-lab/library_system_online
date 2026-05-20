# LibraLink Workflow Test Guide

Use this as a quick smoke test after setup, deployment, or major changes.

## Setup

1. Start XAMPP Apache and MySQL.
2. Open `http://localhost/library_system_online/`.
3. Confirm `.env.mode` is `local`.
4. Confirm the database has:
   - at least 1 available book
   - at least 1 verified borrower
   - at least 1 librarian
   - the default admin: `admin@library.local` / `admin123`

Optional seed files:

```bash
database/infinityfree-import.sql
database/seed-users.sql
database/seed-books.sql or seed-books-50.php
database/seed-reservations.sql
```

## 1. Public Access

1. Visit the home page.
2. Open the public catalog.
3. Search for a book by title, author, or ISBN.
4. Register a new borrower account.
5. Log in with the borrower account.

Pass if:

- public pages load without PHP errors
- search/filter works
- registration creates a borrower
- borrower login redirects to `borrower/index.php`

## 2. Borrower Workflow

1. Log in as a verified borrower.
2. Go to `borrower/catalog.php`.
3. Search for an available book.
4. Click `Reserve This Book`.
5. Go to `borrower/my_books.php`.
6. Confirm the reservation appears under pending reservations.
7. Cancel one pending reservation.

Pass if:

- reservation creates successfully
- pending reservation appears in My Books
- cancel removes or updates the reservation status
- borrower cannot reserve missing/unavailable books incorrectly

## 3. Librarian Reservation Workflow

1. Log in as a librarian.
2. Go to `librarian/reservations.php`.
3. Confirm the borrower's pending reservation appears.
4. Approve the reservation.
5. Confirm it moves to `Approved - Awaiting Pickup`.
6. Create another pending reservation and reject it with a reason.

Pass if:

- pending queue is visible
- approve changes status to approved
- reject changes status to rejected
- processed reservations no longer stay in pending queue

## 4. Librarian Checkout Workflow

1. Go to `librarian/checkout.php`.
2. For an approved reservation, use the pickup/checkout action.
3. For a normal checkout, select a verified borrower and available book.
4. Submit checkout.
5. Confirm the success message and print link if shown.
6. Check `borrower/my_books.php` for that borrower.

Pass if:

- checkout creates an active loan
- available book copies decrease by 1
- approved reservation becomes fulfilled
- borrower sees the loan in My Books
- checkout is blocked for suspended/unverified borrowers, unpaid fines, overdue loans, or duplicate active loans

## 5. Borrower Renewal Workflow

1. Log in as the borrower with an active loan.
2. Go to `borrower/my_books.php`.
3. Click renew on an eligible loan.
4. Try renewing an overdue loan or a loan with a competing approved reservation.

Pass if:

- eligible renewal extends the due date
- ineligible renewal shows a clear blocked message
- renewal does not duplicate the loan

## 6. Librarian Check-In Workflow

1. Log in as librarian.
2. Go to `librarian/checkin.php`.
3. Return an active loan.
4. Return an overdue loan.
5. Open `librarian/records.php`.

Pass if:

- returned loan status becomes returned
- available book copies increase by 1
- overdue return calculates a fine
- returned loan appears in records

## 7. Fine Payment Workflow

1. Create or use a borrower with a returned overdue loan and unpaid fine.
2. Go to `librarian/checkout.php`.
3. Use the fine payment form.
4. Open `admin/reports.php`.

Pass if:

- fine payment marks returned unpaid fines as paid
- paid fine total appears in admin reports
- borrower can checkout again after fines are cleared

## 8. Admin Workflow

1. Log in as admin.
2. Go to `admin/index.php`.
3. Open `admin/users.php`.
4. Create a borrower, librarian, and admin test user.
5. Edit a user name/email.
6. Change a user role.
7. Suspend and reactivate a user.
8. Reset a user password.
9. Open `admin/settings.php` and change:
   - max borrow limit
   - fine per day
   - loan period days
   - reservation expiry days
10. Open `admin/audit-log.php`.
11. Open `admin/reports.php`.

Pass if:

- dashboard counts load
- user creation/editing works
- role/status/password actions work
- suspended users cannot log in
- settings save and affect future checkout/fine/reservation behavior
- audit log records admin and circulation actions
- reports show active loans and paid fines

## 9. Automated Regression Check

Run:

```bash
php tests/borrower_workflow_regression.php
```

Pass if output ends with:

```text
All borrower workflow regression tests passed.
```

## Final Checklist

- No PHP warnings or fatal errors.
- Login redirects match each role.
- Borrower can reserve, view, cancel, and renew.
- Librarian can approve/reject, checkout, checkin, and collect fines.
- Admin can manage users, settings, audit logs, and reports.
- Book copy counts stay correct after checkout and checkin.
- Reservation statuses move correctly: pending -> approved/rejected -> fulfilled/expired.
