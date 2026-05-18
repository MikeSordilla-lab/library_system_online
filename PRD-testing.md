# Testing Product Requirements Document (PRD) - LibraLink Library System

## 1. Overview
This document outlines the Product Requirements for testing the LibraLink Library System across all defined user roles. It serves as a guide for QA and test automation, ensuring all user flows, permissions, and business logic are functioning as expected.

## 2. User Roles & Access Levels

| Role | Access Level / Path | Primary Responsibilities |
|------|---------------------|--------------------------|
| **Public** | `/public/*` | Browsing catalog, Registration, Login, Password Recovery |
| **Borrower** | `/borrower/*` | Reserving books, viewing active loans, renewing books, profile management |
| **Librarian** | `/librarian/*` | Circulation (checkout/checkin), catalog management, reservation approvals, managing fines |
| **Admin** | `/admin/*` | System configuration, user management, audit logs, reports |

---

## 3. Feature Breakdown & Test Cases

### 3.1. Public (Unauthenticated Users)
**Core Objectives:** Secure entry points, catalog visibility, and account recovery.

#### Features to Test:
*   **Authentication Flow:**
    *   **Login (`login.php`):** Verify login with valid credentials, invalid credentials, disabled/suspended accounts.
    *   **Registration (`register.php`):** Verify account creation, duplicate email prevention, role assignment (should default to Borrower).
    *   **Password Recovery (`forgot-password.php`, `reset-password.php`, `verify.php`):** Verify reset token generation, email sending (via PHPMailer), and successful password update.
*   **Public Catalog (`catalog.php`, `book-cover-public.php`):**
    *   **Searching:** Verify keyword search returns accurate results.
    *   **Visibility:** Ensure book details (title, author, availability) are visible without login.
*   **Security / Access Control:**
    *   Verify unauthenticated users are redirected to `login.php` when attempting to access `/borrower`, `/librarian`, or `/admin` routes.

### 3.2. Borrower Role
**Core Objectives:** Self-service borrowing lifecycle, queue positioning, and profile management.

#### Features to Test:
*   **Dashboard / Profile (`borrower/index.php`, `borrower/profile.php`):**
    *   Verify dashboard displays accurate current loans, fines, and reservations.
    *   Verify profile updates (name, password changes).
    *   Verify avatar upload and rendering (`includes/avatar.php`).
*   **Reservation System (`borrower/reserve.php`, `borrower/catalog.php`):**
    *   Verify a borrower can reserve an available book.
    *   Verify queue positioning logic if a book is currently checked out (`includes/QueuePositionModule.php`).
    *   Verify prevention of reserving the same book twice simultaneously.
*   **Active Loans & Renewals (`borrower/my_books.php`, `borrower/renew.php`):**
    *   Verify list of active loans matches database records.
    *   Verify a borrower can renew a book successfully (`includes/RenewalEligibilityModule.php`).
    *   Verify renewal limits (e.g., cannot renew if a hold exists, or max renewals reached).

### 3.3. Librarian Role
**Core Objectives:** Day-to-day library operations, inventory management, and transaction handling.

#### Features to Test:
*   **Circulation (`librarian/checkout.php`, `librarian/checkin.php`):**
    *   **Checkout:** Verify successful checkout to a valid borrower, decrements available inventory.
    *   **Checkout Validation:** Ensure a librarian cannot checkout a book to a suspended borrower or a book that is unavailable/reserved by someone else.
    *   **Check-in:** Verify successful return, increments available inventory, and calculates any applicable overdue fines.
*   **Catalog Management (`librarian/catalog.php`, `-add`, `-edit`, `-delete`):**
    *   **Add Book:** Verify successful creation of a new book record with required metadata (ISBN, Title, Author, Copies).
    *   **Edit Book:** Verify updating details reflects immediately.
    *   **Delete Book:** Verify soft-delete or prevention of deletion if active loans exist.
*   **Reservation Management (`librarian/reservations.php`):**
    *   Verify viewing and approving/rejecting pending reservations.
    *   Verify state transitions (e.g., Pending -> Ready for Pickup -> Checked Out).
*   **Fines & Records (`librarian/pay-fine.php`, `librarian/records.php`, `librarian/print-record.php`):**
    *   Verify processing of fine payments clears the borrower's debt.
    *   Verify transaction history log accuracy.
    *   Verify printing functionality/receipt generation (`includes/receipt_pdf.php`).

### 3.4. Admin Role
**Core Objectives:** System oversight, user lifecycle management, and security auditing.

#### Features to Test:
*   **User Management (`admin/users.php`):**
    *   Verify admin can create new users (Borrowers, Librarians).
    *   Verify admin can edit user roles.
    *   Verify admin can suspend or delete users (and verify suspended users lose access immediately via `auth_guard.php`).
*   **System Reports & Settings (`admin/reports.php`, `admin/settings.php`):**
    *   Verify generation of aggregate statistics (total books, active loans, overdue fines).
    *   Verify updating system-wide configurations limits (e.g., max checkout days, fine amounts).
*   **Audit Logging (`admin/audit-log.php`):**
    *   Verify significant system actions (login, checkout, setting changes, user suspension) are reliably recorded with timestamps and user IDs.

## 4. Cross-Cutting Concerns (Global Testing)

*   **Authentication Persistence:** Verify sessions are maintained accurately and expire appropriately.
*   **CSRF Protection (`includes/csrf.php`):** Verify all state-changing POST requests (forms, API calls) require and validate a CSRF token.
*   **Role-Based Access Control (RBAC):** Attempt horizontal and vertical privilege escalation.
    *   *Example:* A Borrower attempting to POST to a Librarian checkout endpoint.
    *   *Example:* A Librarian attempting to access the Admin audit log.
*   **Environment Configuration (`config.php`):** Verify `.env.local` vs `.env.production` behaviors (e.g., error reporting is hidden in production).

## 5. End-to-End (E2E) Flow Example
1.  **Librarian** adds a new Book to the catalog.
2.  **Borrower** searches for the book and reserves it.
3.  **Librarian** approves the reservation.
4.  **Borrower** picks up the book (Librarian checks it out).
5.  Time passes -> Book becomes overdue.
6.  **Borrower** attempts to renew but fails due to overdue status.
7.  **Librarian** checks the book back in and assesses the fine.
8.  **Borrower** pays the fine, **Librarian** clears it.