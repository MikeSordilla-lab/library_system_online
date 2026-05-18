# Product Requirements Document (PRD)
## 1. Product Overview
**Product Name:** LibraLink
**Description:** LibraLink is a comprehensive, web-based Library Management System designed to streamline the borrowing lifecycle for both library patrons (borrowers) and administrators (librarians). The system facilitates catalog browsing, user authentication, book circulation, reservation management, and automated receipt generation.

## 2. Target Audience & User Roles
The application serves three primary user groups:
*   **Public (Guests):** Can browse the public catalog, view book details, and register for a new account or log in.
*   **Borrower (Patrons):** Authenticated users who can view their active loans, review borrowing history, reserve books, and manage their profile/avatars.
*   **Librarian / Admin:** Staff members responsible for inventory, circulation, user management, and system administration.

## 3. Technology Stack
*   **Backend:** PHP 8.x
*   **Database:** MySQL / MariaDB (PDO, utf8mb4)
*   **Frontend:** Vanilla HTML, CSS, JavaScript (Dynamic UI with micro-animations)
*   **Utilities:** PHPMailer for email services

## 4. Key Features & Functional Requirements

### 4.1. Authentication & Security
*   **Role-Based Access Control (RBAC):** Strict segregation between Guest, Borrower, and Librarian portals using authentication guards.
*   **Session Management:** Secure login/logout with automatic sync of user roles and suspension states.
*   **CSRF Protection:** Integrated middleware to prevent Cross-Site Request Forgery across all state-mutating requests.

### 4.2. Catalog & Discovery (Public/Borrower)
*   Browse the complete inventory of books.
*   View specific details about each book including cover images, titles, authors, and availability.
*   Search and filter books efficiently.

### 4.3. Circulation Management (Librarian)
*   **Checkout & Return:** Librarians can manually issue loans to borrowers and process returns.
*   **Receipts:** The system automatically generates printable checkout/return receipts (`Receipt_Tickets`). Receipts support idempotency, multiple formats (thermal), and can be opened in a printable browser view.
*   **System Logging:** Detailed audit trails (`System_Logs` and `Receipt_Ticket_Logs`) tracking actor IDs, event types, and meta-data for every circulation action.

### 4.4. Reservation System (Borrower & Librarian)
*   **Placing Reservations:** Borrowers can reserve books that are currently checked out.
*   **Approval Workflow:** Librarians review reservations and can either approve or reject them. The system records `approved_at`, `approved_by`, `rejected_at`, and `rejection_reason`.
*   **Queue Management:** Books status updates automatically upon reservation fulfillment.

### 4.5. User Profile & Settings (Borrower)
*   Manage account details.
*   Upload and update profile avatars (`utils/avatar.php`).
*   Review personal loan history (both active and historical records).

## 5. Non-Functional Requirements
*   **Environment Configuration:** Automatic detection of local vs. production environments (`.env.local` vs `.env.production`).
*   **Data Integrity:** Robust foreign key constraints (e.g., ON DELETE CASCADE) and transaction-safe operations.
*   **Performance:** Proper database indexing on high-traffic columns (`idx_reservations_book_status_reserved_at`, `idx_receipt_tickets_actor_issued`, etc.).

## 6. Success Metrics
*   Smooth and error-free completion of the book checkout and return process.
*   Accurate and responsive display of user loan history.
*   Secure isolation of administrative routes from public or unauthorized access.
