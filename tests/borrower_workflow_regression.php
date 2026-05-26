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
  $isbnSuffix = substr(preg_replace('/[^A-Z0-9]/', '', strtoupper($suffix)) ?: 'BOOK', 0, 8);
  $stmt->execute([
    'Workflow Book ' . ucfirst($suffix) . ' ' . bin2hex(random_bytes(3)),
    'Workflow Author',
    'WF-' . $isbnSuffix . '-' . bin2hex(random_bytes(4)),
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
  $stmt = $pdo->prepare('UPDATE Circulation SET fine_paid_at = NOW() WHERE id = ?');
  $stmt->execute([$loanId]);

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

test_case($pdo, 'borrower summary renewal eligibility matches enforcement', function (PDO $pdo): void {
  require_once __DIR__ . '/../includes/BorrowerAccountSummaryModule.php';

  $earlyUser = seed_user($pdo, 'renew-summary-early');
  $earlyBook = seed_book($pdo, 'renew-summary-early');
  seed_loan($pdo, $earlyUser, $earlyBook, 'active', date('Y-m-d H:i:s', strtotime('+3 days')), 0.00, 0);

  $earlySummary = BorrowerAccountSummaryModule::getSummary($pdo, $earlyUser);
  assert_true($earlySummary['active_loans'][0]['renewal_eligible'] === false, 'Too-early loan must not show Renew button.');
  assert_true($earlySummary['active_loans'][0]['renewal_reason_code'] === 'too_early', 'Expected too_early reason.');

  $heldUser = seed_user($pdo, 'renew-summary-held');
  $waitingUser = seed_user($pdo, 'renew-summary-waiting');
  $heldBook = seed_book($pdo, 'renew-summary-held', 1, 0);
  seed_loan($pdo, $heldUser, $heldBook, 'active', date('Y-m-d H:i:s', strtotime('+12 hours')), 0.00, 0);
  seed_reservation($pdo, $waitingUser, $heldBook, RESERVATION_STATUS_PENDING);

  $heldSummary = BorrowerAccountSummaryModule::getSummary($pdo, $heldUser);
  assert_true($heldSummary['active_loans'][0]['renewal_eligible'] === false, 'Competing reservation must not show Renew button.');
  assert_true($heldSummary['active_loans'][0]['renewal_reason_code'] === 'competing_reservation', 'Expected competing_reservation reason.');
});

test_case($pdo, 'borrower reservation rejects missing book', function (PDO $pdo): void {
  require_once __DIR__ . '/../includes/BorrowerReservationModule.php';
  $userId = seed_user($pdo, 'missing-book');
  $missingBookId = (int) $pdo->query('SELECT COALESCE(MAX(id), 0) + 1 FROM Books')->fetchColumn();
  $result = BorrowerReservationModule::place($pdo, $userId, $missingBookId, ROLE_BORROWER);

  assert_true($result['success'] === false, 'Missing book reservation should fail.');
  assert_true($result['reason_code'] === 'book_not_found', 'Expected book_not_found reason.');
});

echo "All borrower workflow regression tests passed.\n";
