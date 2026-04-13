<?php

/**
 * borrower/reserve.php — Place or Cancel a Book Reservation (US4, FR-017–FR-019)
 *
 * POST-only endpoint; all GET requests are redirected to borrower/catalog.php.
 *
 * action=place   — Insert a Reservations row for the authenticated Borrower.
 *                  Blocks duplicates and existing active/overdue loans.
 * action=cancel  — Set a pending Reservation to 'cancelled' (own records only).
 *
 * Protected: Borrower role only (FR-029).
 */

// RBAC guard — must appear before any HTML output (FR-034)
$allowed_roles = ['borrower'];
require_once __DIR__ . '/../includes/auth_guard.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/circulation.php';

$pdo = get_db();

// T022 — Reject GET; only POST is accepted
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  header('Location: ' . BASE_URL . 'borrower/catalog.php');
  exit;
}

// Verify CSRF token before any processing
csrf_verify();

$action      = (string) ($_POST['action'] ?? '');
$user_id     = (int) $_SESSION['user_id'];
$actor_role  = (string) $_SESSION['role'];

// ---------------------------------------------------------------------------
// action = place — T023–T024
// ---------------------------------------------------------------------------
if ($action === 'place') {
  $book_id = (int) ($_POST['book_id'] ?? 0);

  if ($book_id < 1) {
    $_SESSION['flash_error'] = 'Invalid book reference.';
    header('Location: ' . BASE_URL . 'borrower/catalog.php');
    exit;
  }

  // Bug fix: wrap entire reservation flow (including cleanup) in a transaction to prevent
  // race conditions where two simultaneous requests could both pass duplicate checks
  // and insert duplicate reservations.
  $pdo->beginTransaction();

  try {
    // Expire stale pending reservations before duplicate checks so they do not block
    // a new reservation or inflate queue counts. This MUST be inside the transaction.
    $pdo->exec("UPDATE Reservations SET status = 'cancelled' WHERE status = 'pending' AND expires_at < NOW()");

    // T023 — Check no existing pending reservation for same (user_id, book_id)
    // Use FOR UPDATE lock to prevent concurrent duplicate inserts
    // --- NEW LOGIC: Block Delinquent Borrowers (Unpaid Fines or Overdue Books) ---
    $unpaid_fines = get_unpaid_fines_total($pdo, $user_id);

    if ($unpaid_fines > 0.0) {
      $pdo->rollBack();
      $_SESSION['flash_error'] = 'You have outstanding unpaid fines ($' . number_format($unpaid_fines, 2) . ') and cannot reserve books.';
      header('Location: ' . BASE_URL . 'borrower/catalog.php');
      exit;
    }

    if (get_overdue_loan_count($pdo, $user_id) > 0) {
      $pdo->rollBack();
      $_SESSION['flash_error'] = 'You have overdue books that must be returned before reserving new ones.';
      header('Location: ' . BASE_URL . 'borrower/catalog.php');
      exit;
    }

    // --- NEW LOGIC: Enforce Max Borrow Limit ---
    // Count active loans
    $limit_stmt = $pdo->prepare(
      'SELECT COUNT(*) FROM Circulation WHERE user_id = ? AND status IN (\'active\', \'overdue\')'
    );
    $limit_stmt->execute([$user_id]);
    $current_loans = (int) $limit_stmt->fetchColumn();
    
    // Count active reservations
    $res_limit_stmt = $pdo->prepare(
      'SELECT COUNT(*) FROM Reservations WHERE user_id = ? AND status = \'pending\''
    );
    $res_limit_stmt->execute([$user_id]);
    $current_res = (int) $res_limit_stmt->fetchColumn();

    $max_limit = (int) get_setting($pdo, 'max_borrow_limit', '3');

    if ($current_loans + $current_res >= $max_limit) {
      $pdo->rollBack();
      $_SESSION['flash_error'] = 'You have reached your maximum allowed active loans and reservations (' . $max_limit . ').';
      header('Location: ' . BASE_URL . 'borrower/catalog.php');
      exit;
    }

    $dup_res_stmt = $pdo->prepare(
      'SELECT id FROM Reservations
            WHERE user_id = ? AND book_id = ? AND status = \'pending\'
            FOR UPDATE'
    );
    $dup_res_stmt->execute([$user_id, $book_id]);
    if ($dup_res_stmt->fetch()) {
      $pdo->rollBack();
      $_SESSION['flash_error'] = 'You already have a pending reservation for this book.';
      header('Location: ' . BASE_URL . 'borrower/catalog.php');
      exit;
    }

    // T023 — Check no existing active/overdue loan for same (user_id, book_id)
    // Use FOR UPDATE lock to prevent concurrent duplicate inserts
    $dup_loan_stmt = $pdo->prepare(
      'SELECT id FROM Circulation
            WHERE user_id = ? AND book_id = ? AND status IN (\'active\', \'overdue\')
            FOR UPDATE'
    );
    $dup_loan_stmt->execute([$user_id, $book_id]);
    if ($dup_loan_stmt->fetch()) {
      $pdo->rollBack();
      $_SESSION['flash_error'] = 'You already have an active loan for this book.';
      header('Location: ' . BASE_URL . 'borrower/catalog.php');
      exit;
    }

    // T024 — Insert the reservation
    $expiry_days = get_reservation_expiry($pdo);

    $ins_stmt = $pdo->prepare(
      'INSERT INTO Reservations (user_id, book_id, expires_at, status)
           VALUES (?, ?, DATE_ADD(NOW(), INTERVAL ? DAY), \'pending\')'
    );
    $ins_stmt->execute([$user_id, $book_id, $expiry_days]);
    $new_res_id = (int) $pdo->lastInsertId();

    // Compute queue position: count non-expired pending reservations for the same
    // book that were placed before this one.  Bug fix: AND expires_at >= NOW()
    // excludes stale rows that slipped through before the cleanup above ran.
    $queue_stmt = $pdo->prepare(
      'SELECT COUNT(*) + 1 AS position
             FROM Reservations
            WHERE book_id = ?
              AND status = \'pending\'
              AND expires_at >= NOW()
              AND reserved_at < (SELECT reserved_at FROM Reservations WHERE id = ?)'
    );
    $queue_stmt->execute([$book_id, $new_res_id]);
    $queue_row      = $queue_stmt->fetch();
    $queue_position = (int) ($queue_row['position'] ?? 1);

    // Audit log (FR-025)
    log_circulation($pdo, [
      'actor_id'      => $user_id,
      'actor_role'    => $actor_role,
      'action_type'   => 'reservation_place',
      'target_entity' => 'Reservations',
      'target_id'     => $new_res_id,
      'outcome'       => 'success',
    ]);

    $pdo->commit();
  } catch (Throwable $e) {
    $pdo->rollBack();
    error_log('[reserve.php] place transaction failed: ' . $e->getMessage());
    $_SESSION['flash_error'] = 'An unexpected error occurred. Please try again.';
    header('Location: ' . BASE_URL . 'borrower/catalog.php');
    exit;
  }

  $_SESSION['flash_success'] = 'Reservation placed. You are #' . $queue_position . ' in line.';
  header('Location: ' . BASE_URL . 'borrower/catalog.php');
  exit;
}

// ---------------------------------------------------------------------------
// action = cancel — T025
// ---------------------------------------------------------------------------
if ($action === 'cancel') {
  $reservation_id = (int) ($_POST['reservation_id'] ?? 0);

  if ($reservation_id < 1) {
    $_SESSION['flash_error'] = 'Invalid reservation reference.';
    header('Location: ' . BASE_URL . 'borrower/index.php');
    exit;
  }

  // Fetch reservation; verify ownership and pending status
  $res_stmt = $pdo->prepare(
    'SELECT id, user_id, book_id, status FROM Reservations WHERE id = ? LIMIT 1'
  );
  $res_stmt->execute([$reservation_id]);
  $reservation = $res_stmt->fetch();

  if (!$reservation || (int) $reservation['user_id'] !== $user_id || $reservation['status'] !== 'pending') {
    $_SESSION['flash_error'] = 'Reservation not found or cannot be cancelled.';
    header('Location: ' . BASE_URL . 'borrower/index.php');
    exit;
  }

  // Cancel the reservation
  $upd_stmt = $pdo->prepare(
    'UPDATE Reservations SET status = \'cancelled\' WHERE id = ?'
  );
  $upd_stmt->execute([$reservation_id]);

  // Audit log (FR-026)
  log_circulation($pdo, [
    'actor_id'      => $user_id,
    'actor_role'    => $actor_role,
    'action_type'   => 'reservation_cancel',
    'target_entity' => 'Reservations',
    'target_id'     => $reservation_id,
    'outcome'       => 'success',
  ]);

  $_SESSION['flash_success'] = 'Reservation cancelled.';
  header('Location: ' . BASE_URL . 'borrower/index.php');
  exit;
}

// Unknown action — fall through to catalog
$_SESSION['flash_error'] = 'Unknown reservation action.';
header('Location: ' . BASE_URL . 'borrower/catalog.php');
exit;



