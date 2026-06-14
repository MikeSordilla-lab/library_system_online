<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/circulation.php';
require_once __DIR__ . '/../includes/LibrarianCheckoutModule.php';
require_once __DIR__ . '/../includes/LibrarianCheckinModule.php';
require_once __DIR__ . '/../includes/CatalogDiscoveryModule.php';
require_once __DIR__ . '/../includes/CirculationRecordsReportModule.php';
require_once __DIR__ . '/../includes/LibrarianReservationManagementModule.php';
require_once __DIR__ . '/../includes/AdminUserManagementModule.php';

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

function ensure_receipt_hmac_key(PDO $pdo): void
{
  $stmt = $pdo->prepare(
    'INSERT INTO Settings (`key`, `value`) VALUES (?, ?)
     ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)'
  );
  $stmt->execute(['receipt_hmac_key', 'workflow-regression-test-hmac-key']);
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

test_case($pdo, 'queue positions count pending and approved but ignore expired reservations', function (PDO $pdo): void {
  $firstUser = seed_user($pdo, 'queue-position-first');
  $approvedUser = seed_user($pdo, 'queue-position-approved');
  $expiredUser = seed_user($pdo, 'queue-position-expired');
  $tieUser = seed_user($pdo, 'queue-position-tie');
  $bookId = seed_book($pdo, 'queue-position', 3, 3);

  $firstId = seed_reservation($pdo, $firstUser, $bookId, RESERVATION_STATUS_PENDING);
  $approvedId = seed_reservation($pdo, $approvedUser, $bookId, RESERVATION_STATUS_APPROVED);
  $expiredId = seed_reservation($pdo, $expiredUser, $bookId, RESERVATION_STATUS_PENDING);
  $tieId = seed_reservation($pdo, $tieUser, $bookId, RESERVATION_STATUS_PENDING);

  $stmt = $pdo->prepare('UPDATE Reservations SET reserved_at = ?, expires_at = ? WHERE id = ?');
  $stmt->execute([date('Y-m-d H:i:s', strtotime('-3 minutes')), date('Y-m-d H:i:s', strtotime('+7 days')), $firstId]);
  $tieTimestamp = date('Y-m-d H:i:s', strtotime('-2 minutes'));
  $stmt->execute([$tieTimestamp, date('Y-m-d H:i:s', strtotime('+7 days')), $approvedId]);
  $stmt->execute([date('Y-m-d H:i:s', strtotime('-4 minutes')), date('Y-m-d H:i:s', strtotime('-1 day')), $expiredId]);
  $stmt->execute([$tieTimestamp, date('Y-m-d H:i:s', strtotime('+7 days')), $tieId]);

  $rowsStmt = $pdo->prepare('SELECT id, book_id, reserved_at FROM Reservations WHERE id IN (?, ?, ?, ?)');
  $rowsStmt->execute([$firstId, $approvedId, $expiredId, $tieId]);
  $positions = QueuePositionModule::computeBatch($pdo, $rowsStmt->fetchAll());

  assert_true(($positions[$firstId] ?? 0) === 1, 'First pending reservation should be first in queue.');
  assert_true(($positions[$approvedId] ?? 0) === 2, 'Approved reservation should keep its FIFO queue position.');
  assert_true(($positions[$tieId] ?? 0) === 3, 'Same-timestamp reservation should be ordered by id tie-break.');

  $queueRows = QueuePositionModule::getPendingQueue($pdo);
  $queueById = [];
  foreach ($queueRows as $queueRow) {
    $queueById[(int) $queueRow['id']] = $queueRow;
  }

  assert_true(isset($queueById[$firstId]), 'Non-expired pending reservation should appear in librarian queue.');
  assert_true(isset($queueById[$tieId]), 'Tie pending reservation should appear in librarian queue.');
  assert_true(!isset($queueById[$expiredId]), 'Expired pending reservation must not appear in librarian queue.');
  assert_true((int) $queueById[$tieId]['queue_position'] === 3, 'Librarian queue position should count approved holds ahead of pending reservations.');
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

test_case($pdo, 'approval removes competing pending reservations without decrementing copies', function (PDO $pdo): void {
  $librarianId = seed_user($pdo, 'queue-librarian', ROLE_LIBRARIAN);
  $firstUser = seed_user($pdo, 'queue-first');
  $secondUser = seed_user($pdo, 'queue-second');
  $bookId = seed_book($pdo, 'queue-one-copy', 1, 1);
  $firstReservation = seed_reservation($pdo, $firstUser, $bookId, RESERVATION_STATUS_PENDING);
  $secondReservation = seed_reservation($pdo, $secondUser, $bookId, RESERVATION_STATUS_PENDING);

  $changed = transition_reservation_status(
    $pdo,
    $firstReservation,
    RESERVATION_STATUS_PENDING,
    RESERVATION_STATUS_APPROVED,
    [
      'actor_id' => $librarianId,
      'actor_role' => ROLE_LIBRARIAN,
      'action_type' => 'reservation_approve',
    ]
  );
  assert_true($changed === true, 'First reservation should be approved.');

  $copiesStmt = $pdo->prepare('SELECT available_copies FROM Books WHERE id = ?');
  $copiesStmt->execute([$bookId]);
  assert_true((int) $copiesStmt->fetchColumn() === 1, 'Approving a reservation should not decrement available copies.');

  $removed = reject_pending_reservations_when_no_copies_left($pdo, $bookId, $librarianId, ROLE_LIBRARIAN);
  assert_true(count($removed) === 1, 'Exactly one competing reservation should be removed.');
  assert_true((int) $removed[0]['id'] === $secondReservation, 'Second reservation should be removed.');

  $stmt = $pdo->prepare('SELECT status, rejection_reason FROM Reservations WHERE id = ?');
  $stmt->execute([$secondReservation]);
  $row = $stmt->fetch();

  assert_true($row['status'] === RESERVATION_STATUS_REJECTED, 'Competing reservation should be rejected.');
  assert_true(strpos((string) $row['rejection_reason'], 'No available copy left') !== false, 'Rejection reason should explain copy availability.');

  $changed = transition_reservation_status(
    $pdo,
    $firstReservation,
    RESERVATION_STATUS_APPROVED,
    RESERVATION_STATUS_CANCELLED,
    [
      'actor_id' => $firstUser,
      'actor_role' => ROLE_BORROWER,
      'action_type' => 'reservation_cancel',
    ]
  );
  assert_true($changed === true, 'Cancelling an approved reservation should succeed.');

  $copiesStmt->execute([$bookId]);
  assert_true((int) $copiesStmt->fetchColumn() === 1, 'Cancelling an approved reservation should not change available copies.');
});

test_case($pdo, 'pickup decrements approved reservation copy when fulfilled', function (PDO $pdo): void {
  $librarianId = seed_user($pdo, 'pickup-librarian', ROLE_LIBRARIAN);
  $borrowerId = seed_user($pdo, 'pickup-borrower');
  $bookId = seed_book($pdo, 'pickup-one-copy', 1, 1);
  $reservationId = seed_reservation($pdo, $borrowerId, $bookId, RESERVATION_STATUS_APPROVED);

  $updStmt = $pdo->prepare('UPDATE Books SET available_copies = available_copies - 1 WHERE id = ? AND available_copies > 0');
  $updStmt->execute([$bookId]);
  assert_true($updStmt->rowCount() === 1, 'Pickup should decrement the available copy.');

  $changed = transition_reservation_status(
    $pdo,
    $reservationId,
    RESERVATION_STATUS_APPROVED,
    RESERVATION_STATUS_FULFILLED,
    [
      'actor_id' => $librarianId,
      'actor_role' => ROLE_LIBRARIAN,
      'action_type' => 'reservation_fulfill',
    ]
  );
  assert_true($changed === true, 'Pickup should fulfill the approved reservation.');

  $copiesStmt = $pdo->prepare('SELECT available_copies FROM Books WHERE id = ?');
  $copiesStmt->execute([$bookId]);
  assert_true((int) $copiesStmt->fetchColumn() === 0, 'Available copies should decrement only after pickup.');
});

test_case($pdo, 'librarian checkin module returns loan and assesses fine', function (PDO $pdo): void {
  ensure_receipt_hmac_key($pdo);
  set_setting($pdo, 'fine_per_day', '5.00');

  $librarianId = seed_user($pdo, 'module-checkin-librarian', ROLE_LIBRARIAN);
  $borrowerId = seed_user($pdo, 'module-checkin-borrower');
  $bookId = seed_book($pdo, 'module-checkin', 1, 0);
  $loanId = seed_loan($pdo, $borrowerId, $bookId, CIRCULATION_STATUS_ACTIVE, date('Y-m-d H:i:s', strtotime('-47 hours')), 0.00, 1);

  $result = LibrarianCheckinModule::process($pdo, $loanId, $librarianId, ROLE_LIBRARIAN);

  assert_true($result['success'] === true, 'Check-in module should return an active loan.');
  assert_true((int) ($result['days_late'] ?? 0) === 2, 'Check-in module should calculate days late.');
  assert_true(abs((float) ($result['fine_amount'] ?? 0.0) - 10.00) < 0.001, 'Check-in module should assess fine from settings.');
  assert_true((string) ($result['receipt']['receipt_no'] ?? '') !== '', 'Check-in module should issue a receipt.');

  $loanStmt = $pdo->prepare('SELECT status, fine_amount, fine_paid FROM Circulation WHERE id = ?');
  $loanStmt->execute([$loanId]);
  $loan = $loanStmt->fetch();
  assert_true($loan['status'] === CIRCULATION_STATUS_RETURNED, 'Check-in module should mark loan returned.');
  assert_true(abs((float) $loan['fine_amount'] - 10.00) < 0.001, 'Returned loan should store assessed fine.');
  assert_true((int) $loan['fine_paid'] === 0, 'Positive assessed fine should be unpaid.');

  $copiesStmt = $pdo->prepare('SELECT available_copies FROM Books WHERE id = ?');
  $copiesStmt->execute([$bookId]);
  assert_true((int) $copiesStmt->fetchColumn() === 1, 'Check-in module should restore an available copy.');
});

test_case($pdo, 'circulation records report module filters effective overdue status', function (PDO $pdo): void {
  set_setting($pdo, 'fine_per_day', '5.00');

  $borrowerId = seed_user($pdo, 'report-overdue');
  $bookId = seed_book($pdo, 'report-overdue');
  seed_loan($pdo, $borrowerId, $bookId, CIRCULATION_STATUS_ACTIVE, date('Y-m-d H:i:s', strtotime('-2 days')));

  $report = CirculationRecordsReportModule::build($pdo, [
    'status' => CIRCULATION_STATUS_OVERDUE,
    'search' => 'Workflow Report-overdue',
    'per_page' => 10,
  ]);

  assert_true((int) $report['total_records'] >= 1, 'Report module should include effectively overdue active loans.');
  assert_true((string) $report['records'][0]['effective_status'] === CIRCULATION_STATUS_OVERDUE, 'Report module should expose effective status.');
});

test_case($pdo, 'catalog discovery module uses approved holds for public availability', function (PDO $pdo): void {
  $approvedUser = seed_user($pdo, 'catalog-approved-holder');
  $bookId = seed_book($pdo, 'catalog-discovery-held', 1, 1);
  seed_reservation($pdo, $approvedUser, $bookId, RESERVATION_STATUS_APPROVED);

  $titleStmt = $pdo->prepare('SELECT title FROM Books WHERE id = ?');
  $titleStmt->execute([$bookId]);
  $title = (string) $titleStmt->fetchColumn();

  $available = CatalogDiscoveryModule::publicCatalog($pdo, [
    'q' => $title,
    'availability' => 'available',
  ], 12);
  assert_true((int) $available['total_books_count'] === 0, 'Held copy should not count as publicly available.');

  $unavailable = CatalogDiscoveryModule::publicCatalog($pdo, [
    'q' => $title,
    'availability' => 'unavailable',
  ], 12);
  assert_true((int) $unavailable['total_books_count'] === 1, 'Held copy should appear in unavailable filter.');
  assert_true((int) ($unavailable['books'][0]['reservable_copies'] ?? -1) === 0, 'Held copy should expose zero reservable copies.');
});

test_case($pdo, 'librarian reservation management page model uses queue module seam', function (PDO $pdo): void {
  $borrowerId = seed_user($pdo, 'reservation-model-borrower');
  $bookId = seed_book($pdo, 'reservation-model');
  $reservationId = seed_reservation($pdo, $borrowerId, $bookId, RESERVATION_STATUS_PENDING);

  $model = LibrarianReservationManagementModule::pageModel($pdo);
  $found = false;
  foreach ($model['pending_rows'] as $row) {
    if ((int) $row['id'] === $reservationId && (int) ($row['queue_position'] ?? 0) >= 1) {
      $found = true;
      break;
    }
  }

  assert_true($found, 'Reservation management model should include pending reservation with queue position.');
});

test_case($pdo, 'admin user management module exposes role verification rule', function (PDO $pdo): void {
  assert_true(AdminUserManagementModule::roleRequiresVerification(ROLE_ADMIN) === true, 'Admin role should require verification.');
  assert_true(AdminUserManagementModule::roleRequiresVerification(ROLE_LIBRARIAN) === true, 'Librarian role should require verification.');
  assert_true(AdminUserManagementModule::roleRequiresVerification(ROLE_BORROWER) === false, 'Borrower role should not require admin verification.');
});

test_case($pdo, 'librarian checkout module checks out direct loan', function (PDO $pdo): void {
  ensure_receipt_hmac_key($pdo);

  $librarianId = seed_user($pdo, 'module-checkout-librarian', ROLE_LIBRARIAN);
  $borrowerId = seed_user($pdo, 'module-checkout-borrower');
  $bookId = seed_book($pdo, 'module-checkout', 1, 1);

  $result = LibrarianCheckoutModule::checkout($pdo, $borrowerId, $bookId, $librarianId, ROLE_LIBRARIAN);

  assert_true($result['success'] === true, 'Checkout module should create a loan.');
  assert_true((int) ($result['loan_id'] ?? 0) > 0, 'Checkout module should return the new loan id.');
  assert_true((string) ($result['receipt']['receipt_no'] ?? '') !== '', 'Checkout module should issue a receipt.');

  $copiesStmt = $pdo->prepare('SELECT available_copies FROM Books WHERE id = ?');
  $copiesStmt->execute([$bookId]);
  assert_true((int) $copiesStmt->fetchColumn() === 0, 'Checkout module should decrement available copies.');

  $loanStmt = $pdo->prepare('SELECT status FROM Circulation WHERE id = ? AND user_id = ? AND book_id = ?');
  $loanStmt->execute([(int) $result['loan_id'], $borrowerId, $bookId]);
  assert_true($loanStmt->fetchColumn() === CIRCULATION_STATUS_ACTIVE, 'Checkout module should create an active circulation row.');
});

test_case($pdo, 'librarian checkout module fulfills approved pickup', function (PDO $pdo): void {
  ensure_receipt_hmac_key($pdo);

  $librarianId = seed_user($pdo, 'module-pickup-librarian', ROLE_LIBRARIAN);
  $borrowerId = seed_user($pdo, 'module-pickup-borrower');
  $bookId = seed_book($pdo, 'module-pickup', 1, 1);
  $reservationId = seed_reservation($pdo, $borrowerId, $bookId, RESERVATION_STATUS_APPROVED);

  $result = LibrarianCheckoutModule::pickup($pdo, $reservationId, $librarianId, ROLE_LIBRARIAN);

  assert_true($result['success'] === true, 'Pickup module should check out an approved reservation.');
  assert_true((int) ($result['reservation_id'] ?? 0) === $reservationId, 'Pickup module should report fulfilled reservation id.');

  $resStmt = $pdo->prepare('SELECT status FROM Reservations WHERE id = ?');
  $resStmt->execute([$reservationId]);
  assert_true($resStmt->fetchColumn() === RESERVATION_STATUS_FULFILLED, 'Pickup module should fulfill the reservation.');

  $copiesStmt = $pdo->prepare('SELECT available_copies FROM Books WHERE id = ?');
  $copiesStmt->execute([$bookId]);
  assert_true((int) $copiesStmt->fetchColumn() === 0, 'Pickup module should decrement only at pickup.');
});

test_case($pdo, 'librarian checkout module blocks another borrower approved pickup', function (PDO $pdo): void {
  $librarianId = seed_user($pdo, 'module-block-librarian', ROLE_LIBRARIAN);
  $approvedUser = seed_user($pdo, 'module-block-holder');
  $walkInUser = seed_user($pdo, 'module-block-walk-in');
  $bookId = seed_book($pdo, 'module-block', 1, 1);
  $reservationId = seed_reservation($pdo, $approvedUser, $bookId, RESERVATION_STATUS_APPROVED);

  $result = LibrarianCheckoutModule::checkout($pdo, $walkInUser, $bookId, $librarianId, ROLE_LIBRARIAN);

  assert_true($result['success'] === false, 'Checkout module should block a walk-in when another borrower has the approved hold.');
  assert_true($result['reason_code'] === 'reserved_for_another_borrower', 'Expected reserved_for_another_borrower reason.');
  assert_true((int) ($result['reservation_id'] ?? 0) === $reservationId, 'Blocked result should identify the protected reservation.');
});

test_case($pdo, 'borrower reservation rejects books with no available copies', function (PDO $pdo): void {
  require_once __DIR__ . '/../includes/BorrowerReservationModule.php';

  $userId = seed_user($pdo, 'no-copies');
  $bookId = seed_book($pdo, 'no-copies', 1, 0);

  $result = BorrowerReservationModule::place($pdo, $userId, $bookId, ROLE_BORROWER);

  assert_true($result['success'] === false, 'Reservation should fail when no copies are available.');
  assert_true($result['reason_code'] === 'no_available_copies', 'Expected no_available_copies reason.');
});

test_case($pdo, 'borrower reservation counts approved pickups against available copies', function (PDO $pdo): void {
  require_once __DIR__ . '/../includes/BorrowerReservationModule.php';

  $approvedUser = seed_user($pdo, 'approved-pickup');
  $requestingUser = seed_user($pdo, 'approved-pickup-later');
  $bookId = seed_book($pdo, 'approved-pickup-copy', 1, 1);
  seed_reservation($pdo, $approvedUser, $bookId, RESERVATION_STATUS_APPROVED);

  $result = BorrowerReservationModule::place($pdo, $requestingUser, $bookId, ROLE_BORROWER);

  assert_true($result['success'] === false, 'Reservation should fail when the only copy is already approved for pickup.');
  assert_true($result['reason_code'] === 'no_available_copies', 'Expected no_available_copies reason.');
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
