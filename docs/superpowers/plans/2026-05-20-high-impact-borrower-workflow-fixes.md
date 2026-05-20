# High-Impact Borrower Workflow Fixes Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Fix the highest-risk borrower workflow bugs across checkout, check-in, reservations, renewal, and fine payment while adding repeatable regression coverage.

**Architecture:** Keep the current PHP/PDO page-controller style, but extract small workflow helpers into `includes/circulation.php` where multiple controllers need the same rule. Add a lightweight CLI regression test harness because this repo does not currently include Composer or PHPUnit.

**Tech Stack:** PHP 8.x, PDO/MySQL, existing `includes/*` helpers, CLI `php` for regression tests.

---

## Scope

This plan fixes the high-impact issues from the audit:

- Fine payment marks future zero-fine loans as paid.
- Check-in can double-process the same return.
- Checkout can fulfill a pending reservation without approval.
- Checkout can bypass another borrower's approved reservation.
- Renewal ignores approved competing reservations.
- Reservation placement accepts direct POSTs for missing/unborrowable books.
- Fine currency messages are inconsistent.

Out of scope for this first pass:

- OTP expiry.
- Full reservation notification workflow.
- New UI screens.
- Composer/PHPUnit installation.

## File Structure

- Create: `tests/borrower_workflow_regression.php`
  - CLI regression harness using the real PDO connection.
  - Seeds temporary users/books/loans/reservations inside transactions and rolls back each test.
- Modify: `includes/circulation.php`
  - Add reusable helpers for currency formatting, book locking, fine clearing, checkout reservation policy, and check-in finalization.
- Modify: `includes/BorrowerReservationModule.php`
  - Validate and lock the book before inserting a reservation.
- Modify: `includes/RenewalEligibilityModule.php`
  - Treat pending and approved reservations by other borrowers as competing holds.
- Modify: `librarian/checkout.php`
  - Use shared checkout reservation policy.
  - Fulfill only approved reservations.
  - Use centralized currency formatting.
- Modify: `librarian/checkin.php`
  - Lock the loan inside the transaction.
  - Use conditional status update.
  - Reset fine payment state correctly when a fine is assessed.
  - Use centralized currency formatting.
- Modify: `librarian/pay-fine.php`
  - Clear only returned rows with positive unpaid fines.
  - Use centralized currency formatting and PHP currency code.
- Modify: `PRD-testing.md`
  - Add the high-impact regression command and scenarios.

---

### Task 1: Add Borrower Workflow Regression Harness

**Files:**
- Create: `tests/borrower_workflow_regression.php`

- [ ] **Step 1: Create the test directory**

Run:

```powershell
New-Item -ItemType Directory -Force -Path tests | Out-Null
```

Expected: `tests/` exists.

- [ ] **Step 2: Add the regression harness**

Create `tests/borrower_workflow_regression.php`:

```php
<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/circulation.php';

$pdo = get_db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

function assert_true(bool $condition, string $message): void
{
  if (!$condition) {
    throw new RuntimeException($message);
  }
}

function test_case(PDO $pdo, string $name, callable $fn): void
{
  $pdo->beginTransaction();
  try {
    $fn($pdo);
    $pdo->rollBack();
    echo "PASS {$name}\n";
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) {
      $pdo->rollBack();
    }
    echo "FAIL {$name}: {$e->getMessage()}\n";
    throw $e;
  }
}

function seed_user(PDO $pdo, string $suffix, string $role = 'borrower'): int
{
  $stmt = $pdo->prepare(
    'INSERT INTO Users (full_name, email, password_hash, role, is_verified, is_suspended)
     VALUES (?, ?, ?, ?, 1, 0)'
  );
  $email = 'workflow-' . $suffix . '-' . bin2hex(random_bytes(4)) . '@example.test';
  $stmt->execute([
    'Workflow ' . ucfirst($suffix),
    $email,
    password_hash('TestPass123!', PASSWORD_BCRYPT),
    $role,
  ]);
  return (int) $pdo->lastInsertId();
}

function seed_book(PDO $pdo, string $suffix, int $total = 1, int $available = 1): int
{
  $stmt = $pdo->prepare(
    'INSERT INTO Books (title, author, isbn, category, total_copies, available_copies)
     VALUES (?, ?, ?, ?, ?, ?)'
  );
  $stmt->execute([
    'Workflow Book ' . ucfirst($suffix) . ' ' . bin2hex(random_bytes(3)),
    'Workflow Author',
    'WF-' . strtoupper($suffix) . '-' . bin2hex(random_bytes(4)),
    'Testing',
    $total,
    $available,
  ]);
  return (int) $pdo->lastInsertId();
}

function seed_loan(PDO $pdo, int $userId, int $bookId, string $status, string $dueDate, float $fineAmount = 0.0, int $finePaid = 0): int
{
  $stmt = $pdo->prepare(
    'INSERT INTO Circulation (user_id, book_id, due_date, status, fine_amount, fine_paid)
     VALUES (?, ?, ?, ?, ?, ?)'
  );
  $stmt->execute([$userId, $bookId, $dueDate, $status, $fineAmount, $finePaid]);
  return (int) $pdo->lastInsertId();
}

function seed_reservation(PDO $pdo, int $userId, int $bookId, string $status): int
{
  $stmt = $pdo->prepare(
    'INSERT INTO Reservations (user_id, book_id, expires_at, status)
     VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 7 DAY), ?)'
  );
  $stmt->execute([$userId, $bookId, $status]);
  return (int) $pdo->lastInsertId();
}

test_case($pdo, 'fine payment updates only returned positive unpaid fines', function (PDO $pdo): void {
  $userId = seed_user($pdo, 'fine');
  $bookA = seed_book($pdo, 'fine-a');
  $bookB = seed_book($pdo, 'fine-b');

  $returnedFineLoan = seed_loan($pdo, $userId, $bookA, 'returned', date('Y-m-d H:i:s', strtotime('-10 days')), 25.00, 0);
  $activeZeroLoan = seed_loan($pdo, $userId, $bookB, 'active', date('Y-m-d H:i:s', strtotime('+7 days')), 0.00, 0);

  $result = mark_returned_positive_fines_paid($pdo, $userId);

  assert_true(abs($result['total'] - 25.00) < 0.001, 'Expected total paid fines to equal 25.00.');

  $stmt = $pdo->prepare('SELECT fine_paid FROM Circulation WHERE id = ?');
  $stmt->execute([$returnedFineLoan]);
  assert_true((int) $stmt->fetchColumn() === 1, 'Returned positive fine should be marked paid.');

  $stmt->execute([$activeZeroLoan]);
  assert_true((int) $stmt->fetchColumn() === 0, 'Active zero-fine loan must remain unpaid until returned.');
});

test_case($pdo, 'check-in assessed fine resets fine_paid to unpaid', function (PDO $pdo): void {
  $userId = seed_user($pdo, 'checkin-fine');
  $bookId = seed_book($pdo, 'checkin-fine');
  $loanId = seed_loan($pdo, $userId, $bookId, 'active', date('Y-m-d H:i:s', strtotime('-3 days')), 0.00, 1);

  complete_checkin_loan($pdo, $loanId, 15.00);

  $stmt = $pdo->prepare('SELECT status, fine_amount, fine_paid, fine_paid_at FROM Circulation WHERE id = ?');
  $stmt->execute([$loanId]);
  $row = $stmt->fetch();

  assert_true($row['status'] === 'returned', 'Loan should be returned.');
  assert_true(abs((float) $row['fine_amount'] - 15.00) < 0.001, 'Fine should be assessed.');
  assert_true((int) $row['fine_paid'] === 0, 'Assessed fine must be unpaid.');
  assert_true($row['fine_paid_at'] === null, 'Assessed fine must clear previous payment timestamp.');
});

test_case($pdo, 'checkout blocks another borrower approved reservation', function (PDO $pdo): void {
  $approvedUser = seed_user($pdo, 'approved-holder');
  $walkInUser = seed_user($pdo, 'walk-in');
  $bookId = seed_book($pdo, 'held-book');
  seed_reservation($pdo, $approvedUser, $bookId, RESERVATION_STATUS_APPROVED);

  $policy = checkout_reservation_policy($pdo, $walkInUser, $bookId);

  assert_true($policy['allowed'] === false, 'Walk-in checkout should be blocked by another approved reservation.');
  assert_true($policy['reason_code'] === 'reserved_for_another_borrower', 'Expected reserved_for_another_borrower reason.');
});

test_case($pdo, 'checkout fulfills only approved borrower reservation', function (PDO $pdo): void {
  $userId = seed_user($pdo, 'pending-checkout');
  $bookId = seed_book($pdo, 'pending-checkout');
  $pendingId = seed_reservation($pdo, $userId, $bookId, RESERVATION_STATUS_PENDING);

  $reservation = checkout_reservation_to_fulfill($pdo, $userId, $bookId);

  assert_true($reservation === null, 'Pending reservation must not be auto-fulfilled.');

  $stmt = $pdo->prepare('UPDATE Reservations SET status = ? WHERE id = ?');
  $stmt->execute([RESERVATION_STATUS_APPROVED, $pendingId]);

  $reservation = checkout_reservation_to_fulfill($pdo, $userId, $bookId);
  assert_true(is_array($reservation), 'Approved reservation should be selected for fulfillment.');
  assert_true((int) $reservation['id'] === $pendingId, 'Selected reservation should be the approved reservation.');
});

test_case($pdo, 'renewal blocks approved competing reservation', function (PDO $pdo): void {
  $currentUser = seed_user($pdo, 'renew-current');
  $waitingUser = seed_user($pdo, 'renew-waiting');
  $bookId = seed_book($pdo, 'renew-held', 1, 0);
  $loanId = seed_loan($pdo, $currentUser, $bookId, 'active', date('Y-m-d H:i:s', strtotime('+12 hours')), 0.00, 0);
  seed_reservation($pdo, $waitingUser, $bookId, RESERVATION_STATUS_APPROVED);

  require_once __DIR__ . '/../includes/RenewalEligibilityModule.php';
  $check = RenewalEligibilityModule::check($pdo, $currentUser, $loanId);

  assert_true($check['eligible'] === false, 'Renewal should be blocked.');
  assert_true($check['reason_code'] === 'competing_reservation', 'Expected competing_reservation reason.');
});

test_case($pdo, 'borrower reservation rejects missing book', function (PDO $pdo): void {
  require_once __DIR__ . '/../includes/BorrowerReservationModule.php';
  $userId = seed_user($pdo, 'missing-book');
  $result = BorrowerReservationModule::place($pdo, $userId, 999999999, ROLE_BORROWER);

  assert_true($result['success'] === false, 'Missing book reservation should fail.');
  assert_true($result['reason_code'] === 'book_not_found', 'Expected book_not_found reason.');
});

echo "All borrower workflow regression tests passed.\n";
```

- [ ] **Step 3: Run the harness to verify baseline failures**

Run:

```powershell
php tests/borrower_workflow_regression.php
```

Expected before implementation: FAIL because helpers such as `mark_returned_positive_fines_paid`, `complete_checkin_loan`, and `checkout_reservation_policy` do not exist yet.

- [ ] **Step 4: Commit**

Run:

```powershell
git add tests/borrower_workflow_regression.php
git commit -m "test: cover borrower workflow regressions"
```

Expected: commit created with the failing regression harness.

---

### Task 2: Add Shared Circulation Workflow Helpers

**Files:**
- Modify: `includes/circulation.php`

- [ ] **Step 1: Add currency and fine helpers after `get_overdue_loan_count()`**

Add this code after the `get_overdue_loan_count()` function:

```php
function circulation_currency_code(PDO $pdo): string
{
  $code = strtoupper(trim(get_setting($pdo, 'currency_code', 'PHP')));
  return $code !== '' ? $code : 'PHP';
}

function circulation_currency_symbol(PDO $pdo): string
{
  $code = circulation_currency_code($pdo);
  $symbols = [
    'PHP' => '₱',
    'USD' => '$',
  ];
  return $symbols[$code] ?? ($code . ' ');
}

function format_circulation_money(PDO $pdo, float $amount): string
{
  return circulation_currency_symbol($pdo) . number_format($amount, 2);
}

/**
 * Mark only returned loans with positive unpaid fines as paid.
 *
 * @return array{total: float, rows: int}
 */
function mark_returned_positive_fines_paid(PDO $pdo, int $userId): array
{
  $sumStmt = $pdo->prepare(
    "SELECT COALESCE(SUM(fine_amount), 0)
       FROM Circulation
      WHERE user_id = ?
        AND status = 'returned'
        AND fine_paid = 0
        AND fine_amount > 0"
  );
  $sumStmt->execute([$userId]);
  $total = (float) $sumStmt->fetchColumn();

  if ($total <= 0.0) {
    return ['total' => 0.0, 'rows' => 0];
  }

  $upd = $pdo->prepare(
    "UPDATE Circulation
        SET fine_paid = 1,
            fine_paid_at = NOW()
      WHERE user_id = ?
        AND status = 'returned'
        AND fine_paid = 0
        AND fine_amount > 0"
  );
  $upd->execute([$userId]);

  return ['total' => $total, 'rows' => $upd->rowCount()];
}
```

- [ ] **Step 2: Add checkout reservation helpers before `get_pending_reservation_queue()`**

Add this code before `get_pending_reservation_queue()`:

```php
/**
 * Return whether a checkout can proceed under reservation rules.
 *
 * @return array{allowed: bool, reason_code: string|null, reservation_id?: int}
 */
function checkout_reservation_policy(PDO $pdo, int $userId, int $bookId): array
{
  $stmt = $pdo->prepare(
    'SELECT id, user_id, status
       FROM Reservations
      WHERE book_id = ?
        AND status = ?
      ORDER BY approved_at ASC, reserved_at ASC, id ASC
      LIMIT 1
      FOR UPDATE'
  );
  $stmt->execute([$bookId, RESERVATION_STATUS_APPROVED]);
  $approved = $stmt->fetch();

  if ($approved && (int) $approved['user_id'] !== $userId) {
    return [
      'allowed' => false,
      'reason_code' => 'reserved_for_another_borrower',
      'reservation_id' => (int) $approved['id'],
    ];
  }

  return [
    'allowed' => true,
    'reason_code' => null,
    'reservation_id' => $approved ? (int) $approved['id'] : null,
  ];
}

function checkout_reservation_to_fulfill(PDO $pdo, int $userId, int $bookId): ?array
{
  $stmt = $pdo->prepare(
    'SELECT id, status
       FROM Reservations
      WHERE user_id = ?
        AND book_id = ?
        AND status = ?
      ORDER BY approved_at ASC, reserved_at ASC, id ASC
      LIMIT 1
      FOR UPDATE'
  );
  $stmt->execute([$userId, $bookId, RESERVATION_STATUS_APPROVED]);
  $row = $stmt->fetch();
  return $row === false ? null : $row;
}
```

- [ ] **Step 3: Add check-in finalization helper before `reservation_column_exists()`**

Add this code before `reservation_column_exists()`:

```php
function complete_checkin_loan(PDO $pdo, int $loanId, float $fine): bool
{
  $finePaid = $fine > 0.0 ? 0 : 1;
  $finePaidAtSql = $fine > 0.0 ? 'NULL' : 'NOW()';

  $stmt = $pdo->prepare(
    "UPDATE Circulation
        SET return_date = NOW(),
            status = 'returned',
            fine_amount = ?,
            fine_paid = ?,
            fine_paid_at = {$finePaidAtSql}
      WHERE id = ?
        AND status IN ('active', 'overdue')"
  );
  $stmt->execute([$fine, $finePaid, $loanId]);

  return $stmt->rowCount() === 1;
}
```

- [ ] **Step 4: Run the regression harness**

Run:

```powershell
php tests/borrower_workflow_regression.php
```

Expected: some tests still fail because controllers/modules have not yet been wired to the helpers.

- [ ] **Step 5: Commit**

Run:

```powershell
git add includes/circulation.php tests/borrower_workflow_regression.php
git commit -m "feat: add circulation workflow helpers"
```

Expected: commit created.

---

### Task 3: Harden Borrower Reservation Placement

**Files:**
- Modify: `includes/BorrowerReservationModule.php`
- Modify: `borrower/reserve.php`

- [ ] **Step 1: Lock and validate the book before limit checks**

In `BorrowerReservationModule::place()`, add this block immediately after `expire_stale_reservations($pdo);`:

```php
$bookStmt = $pdo->prepare(
  'SELECT id, total_copies, available_copies
     FROM Books
    WHERE id = ?
    FOR UPDATE'
);
$bookStmt->execute([$bookId]);
$book = $bookStmt->fetch();

if (!$book) {
  return ['success' => false, 'reason_code' => 'book_not_found'];
}

if ((int) $book['total_copies'] < 1) {
  return ['success' => false, 'reason_code' => 'book_not_borrowable'];
}
```

- [ ] **Step 2: Add borrower-facing error messages**

In `borrower/reserve.php`, update `_reserve_place_error()` with:

```php
case 'book_not_found':
  return 'Book not found. It may have been removed from the catalog.';
case 'book_not_borrowable':
  return 'This book is not currently borrowable.';
```

Place these before the `default` case.

- [ ] **Step 3: Run the focused regression test**

Run:

```powershell
php tests/borrower_workflow_regression.php
```

Expected: `borrower reservation rejects missing book` passes.

- [ ] **Step 4: Commit**

Run:

```powershell
git add includes/BorrowerReservationModule.php borrower/reserve.php
git commit -m "fix: validate books before reservation"
```

Expected: commit created.

---

### Task 4: Block Approved Holds During Renewal

**Files:**
- Modify: `includes/RenewalEligibilityModule.php`

- [ ] **Step 1: Update competing reservation query**

Replace the query at `includes/RenewalEligibilityModule.php` around the current `SELECT id FROM Reservations` block with:

```php
$resStmt = $pdo->prepare(
  'SELECT id FROM Reservations
    WHERE book_id = ?
      AND status IN (?, ?)
      AND user_id != ?
    LIMIT 1'
);
$resStmt->execute([
  (int) $loan['book_id'],
  RESERVATION_STATUS_PENDING,
  RESERVATION_STATUS_APPROVED,
  $userId,
]);
```

- [ ] **Step 2: Run the focused regression test**

Run:

```powershell
php tests/borrower_workflow_regression.php
```

Expected: `renewal blocks approved competing reservation` passes.

- [ ] **Step 3: Commit**

Run:

```powershell
git add includes/RenewalEligibilityModule.php tests/borrower_workflow_regression.php
git commit -m "fix: block renewal for approved holds"
```

Expected: commit created.

---

### Task 5: Enforce Reservation Policy During Checkout

**Files:**
- Modify: `librarian/checkout.php`

- [ ] **Step 1: Check approved holds before duplicate loan check**

In `librarian/checkout.php`, after the max-limit block and before the duplicate-loan query, insert:

```php
$reservationPolicy = checkout_reservation_policy($pdo, $user_id, $book_id);
if (!$reservationPolicy['allowed']) {
  $pdo->rollBack();
  $_SESSION['flash_error'] = 'This book is currently approved for pickup by another borrower and cannot be checked out to the selected borrower.';
  header('Location: ' . BASE_URL . 'librarian/checkout.php');
  exit;
}
```

- [ ] **Step 2: Fulfill only approved reservations**

Replace the current `SELECT id, status FROM Reservations ... status IN (?, ?)` block with:

```php
$reservationToFulfill = checkout_reservation_to_fulfill($pdo, $user_id, $book_id);

if ($reservationToFulfill) {
  transition_reservation_status(
    $pdo,
    (int) $reservationToFulfill['id'],
    RESERVATION_STATUS_APPROVED,
    RESERVATION_STATUS_FULFILLED,
    [
      'actor_id' => $actor_id,
      'actor_role' => $actor_role,
      'action_type' => 'reservation_fulfill',
    ]
  );
}
```

- [ ] **Step 3: Replace dollar fine message with formatter**

Change:

```php
$_SESSION['flash_error'] = 'Borrower has outstanding unpaid fines ($' . number_format($unpaid_fines, 2) . ') and cannot check out books.';
```

to:

```php
$_SESSION['flash_error'] = 'Borrower has outstanding unpaid fines (' . format_circulation_money($pdo, $unpaid_fines) . ') and cannot check out books.';
```

- [ ] **Step 4: Run the regression harness**

Run:

```powershell
php tests/borrower_workflow_regression.php
```

Expected: checkout policy tests pass.

- [ ] **Step 5: Manual checkout checks**

Manual checks:

1. Create borrower A and borrower B.
2. Create one available book.
3. Borrower A reserves it.
4. Librarian approves borrower A's reservation.
5. Try checkout to borrower B.
6. Expected: checkout is blocked with the approved pickup message.
7. Try checkout to borrower A.
8. Expected: checkout succeeds and reservation becomes `fulfilled`.

- [ ] **Step 6: Commit**

Run:

```powershell
git add librarian/checkout.php includes/circulation.php tests/borrower_workflow_regression.php
git commit -m "fix: enforce checkout hold policy"
```

Expected: commit created.

---

### Task 6: Make Check-In Idempotent and Fine State Correct

**Files:**
- Modify: `librarian/checkin.php`

- [ ] **Step 1: Move loan fetch inside the transaction and lock it**

Replace the pre-transaction loan fetch with a transaction-scoped lock:

```php
$pdo->beginTransaction();

try {
  $loan_stmt = $pdo->prepare(
    'SELECT c.id, c.user_id, c.book_id, c.due_date, c.status,
            b.title AS book_title,
            u.full_name AS borrower_name
       FROM Circulation c
       JOIN Books b ON c.book_id = b.id
       JOIN Users u ON c.user_id = u.id
      WHERE c.id = ?
      LIMIT 1
      FOR UPDATE'
  );
  $loan_stmt->execute([$loan_id]);
  $loan = $loan_stmt->fetch();

  if (!$loan || !in_array((string) $loan['status'], ['active', 'overdue'], true)) {
    $pdo->rollBack();
    $_SESSION['flash_error'] = 'Loan not found or has already been returned.';
    header('Location: ' . BASE_URL . 'librarian/checkin.php');
    exit;
  }

  if (empty($loan['book_id']) || (int) $loan['book_id'] < 1) {
    $pdo->rollBack();
    $_SESSION['flash_error'] = 'This loan record is missing a valid book reference and cannot be processed.';
    header('Location: ' . BASE_URL . 'librarian/checkin.php');
    exit;
  }
```

Keep fine calculation after this locked fetch.

- [ ] **Step 2: Use conditional check-in helper**

Replace the existing `UPDATE Circulation SET return_date = NOW(), status = 'returned', fine_amount = ? WHERE id = ?` block with:

```php
$updated = complete_checkin_loan($pdo, $loan_id, $fine);
if (!$updated) {
  $pdo->rollBack();
  $_SESSION['flash_error'] = 'Loan was already returned by another request.';
  header('Location: ' . BASE_URL . 'librarian/checkin.php');
  exit;
}
```

- [ ] **Step 3: Keep book copy restore after successful loan update**

Leave the `UPDATE Books SET available_copies = LEAST(...)` block after the successful `complete_checkin_loan()` call.

- [ ] **Step 4: Replace dollar fine message with formatter**

Change:

```php
$fine_msg = ($fine > 0.00)
  ? ' Fine assessed: $' . number_format($fine, 2) . '.'
  : ' No fine.';
```

to:

```php
$fine_msg = ($fine > 0.00)
  ? ' Fine assessed: ' . format_circulation_money($pdo, $fine) . '.'
  : ' No fine.';
```

- [ ] **Step 5: Run the regression harness**

Run:

```powershell
php tests/borrower_workflow_regression.php
```

Expected: check-in fine-state test passes.

- [ ] **Step 6: Manual double-submit check**

Manual checks:

1. Open `librarian/checkin.php`.
2. Submit the same return twice quickly, or replay the POST with the same `loan_id`.
3. Expected: one success, one “already returned” error.
4. Expected: one receipt/log for the successful return.
5. Expected: `Books.available_copies` increments only once.

- [ ] **Step 7: Commit**

Run:

```powershell
git add librarian/checkin.php includes/circulation.php tests/borrower_workflow_regression.php
git commit -m "fix: make checkin idempotent"
```

Expected: commit created.

---

### Task 7: Correct Fine Payment Scope and Currency

**Files:**
- Modify: `librarian/pay-fine.php`
- Modify: `librarian/checkout.php`

- [ ] **Step 1: Require borrower role for fine payment target**

Replace:

```php
$borrower_stmt = $pdo->prepare('SELECT id, full_name, email FROM Users WHERE id = ? LIMIT 1');
```

with:

```php
$borrower_stmt = $pdo->prepare("SELECT id, full_name, email FROM Users WHERE id = ? AND role = 'borrower' LIMIT 1");
```

- [ ] **Step 2: Use the fine payment helper**

Replace the `SELECT SUM(fine_amount)` and broad `UPDATE Circulation SET fine_paid = 1...` logic with:

```php
$payment = mark_returned_positive_fines_paid($pdo, $user_id);
$total_fines = (float) $payment['total'];

if ($total_fines > 0) {
  log_circulation($pdo, [
    'actor_id'      => $actor_id,
    'actor_role'    => $actor_role,
    'action_type'   => 'fine_payment',
    'target_entity' => 'Users',
    'target_id'     => $user_id,
    'outcome'       => 'success_amount_' . $total_fines,
  ]);

  $receipt = issue_receipt_ticket($pdo, [
    'type'            => 'fine_payment',
    'actor_user_id'   => $actor_id,
    'actor_role'      => $actor_role,
    'patron_user_id'  => $user_id,
    'reference_table' => 'Users',
    'reference_id'    => $user_id,
    'idempotency_key' => 'fine_payment:user:' . $user_id . ':at:' . date('YmdHi'),
    'amount'          => $total_fines,
    'currency'        => circulation_currency_code($pdo),
    'format'          => 'a4',
    'channel'         => 'librarian_console',
    'payload'         => [
      'patron_name'   => (string) ($borrower['full_name'] ?? ''),
      'patron_email'  => (string) ($borrower['email'] ?? ''),
      'total_fines'   => $total_fines,
      'currency'      => circulation_currency_code($pdo),
      'settled_scope' => 'returned_positive_unpaid_fines',
    ],
  ]);

  $pdo->commit();
  $_SESSION['flash_success'] = 'Successfully cleared fines (' . format_circulation_money($pdo, $total_fines) . ') for borrower.';
  $_SESSION['flash_receipt_no'] = (string) ($receipt['receipt_no'] ?? '');
  header('Location: ' . BASE_URL . 'librarian/checkout.php');
  exit;
}

$pdo->rollBack();
$_SESSION['flash_info'] = 'This borrower has no outstanding fines.';
```

- [ ] **Step 3: Ensure checkout fine list already scopes positive fines**

Confirm `librarian/checkout.php` keeps:

```php
WHERE c.fine_paid = 0 AND c.fine_amount > 0
```

No code change is required if this is still present.

- [ ] **Step 4: Run the regression harness**

Run:

```powershell
php tests/borrower_workflow_regression.php
```

Expected: all automated tests pass.

- [ ] **Step 5: Manual fine payment check**

Manual checks:

1. Return a late book and confirm a fine appears.
2. Pay the fine.
3. Expected: only returned positive fine rows are marked paid.
4. Borrow another book, return it late.
5. Expected: the new fine is unpaid until explicitly paid.
6. Expected: UI and receipt use PHP/₱ consistently.

- [ ] **Step 6: Commit**

Run:

```powershell
git add librarian/pay-fine.php librarian/checkout.php includes/circulation.php tests/borrower_workflow_regression.php
git commit -m "fix: scope fine payments"
```

Expected: commit created.

---

### Task 8: Update Testing Documentation

**Files:**
- Modify: `PRD-testing.md`

- [ ] **Step 1: Add a high-impact regression section**

Add this section after `## 5. End-to-End (E2E) Flow Example`:

```markdown
## 6. High-Impact Borrower Workflow Regression Command

Run this command after changing checkout, check-in, reservations, renewals, or fine payment:

```powershell
php tests/borrower_workflow_regression.php
```

Expected output:

```text
PASS fine payment updates only returned positive unpaid fines
PASS check-in assessed fine resets fine_paid to unpaid
PASS checkout blocks another borrower approved reservation
PASS checkout fulfills only approved borrower reservation
PASS renewal blocks approved competing reservation
PASS borrower reservation rejects missing book
All borrower workflow regression tests passed.
```

These tests intentionally use the configured local database and wrap each case in a transaction that rolls back.
```

- [ ] **Step 2: Run the regression harness one final time**

Run:

```powershell
php tests/borrower_workflow_regression.php
```

Expected: all tests pass.

- [ ] **Step 3: Commit**

Run:

```powershell
git add PRD-testing.md tests/borrower_workflow_regression.php
git commit -m "docs: document workflow regression tests"
```

Expected: commit created.

---

## Final Verification

- [ ] Run PHP syntax checks:

```powershell
php -l includes/circulation.php
php -l includes/BorrowerReservationModule.php
php -l includes/RenewalEligibilityModule.php
php -l librarian/checkout.php
php -l librarian/checkin.php
php -l librarian/pay-fine.php
php -l borrower/reserve.php
php -l tests/borrower_workflow_regression.php
```

Expected: every command prints `No syntax errors detected`.

- [ ] Run automated regression tests:

```powershell
php tests/borrower_workflow_regression.php
```

Expected: all tests pass.

- [ ] Manual workflow smoke test:

1. Register and verify a borrower.
2. Reserve a book as borrower.
3. Approve reservation as librarian.
4. Check out book to approved borrower.
5. Return book late.
6. Confirm fine is unpaid.
7. Pay fine.
8. Confirm borrower can borrow again.

Expected: workflow completes without bypassing holds, double-processing returns, or auto-paying future fines.

## Self-Review

- Spec coverage: all high-impact audit findings selected by the user are covered by tasks.
- Placeholder scan: no `TBD`, `TODO`, or vague “add tests” placeholders remain.
- Type consistency: helper names used by tests match helper names added to `includes/circulation.php`.
- Scope check: OTP expiry remains intentionally out of scope for this first high-impact workflow plan.
