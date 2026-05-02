<?php

/**
 * borrower/reserve.php — Place or Cancel a Book Reservation (US4, FR-017–FR-019)
 *
 * POST-only endpoint; all GET requests are redirected to borrower/catalog.php.
 *
 * Thin HTTP adapter: delegates all business logic to BorrowerReservationModule.
 *
 * Protected: Borrower role only (FR-029).
 */

$allowed_roles = ['borrower'];
require_once __DIR__ . '/../includes/auth_guard.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/circulation.php';
require_once __DIR__ . '/../includes/BorrowerReservationModule.php';

$pdo = get_db();

// T022 — Reject GET; only POST is accepted
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  header('Location: ' . BASE_URL . 'borrower/catalog.php');
  exit;
}

csrf_verify();

$action     = (string) ($_POST['action'] ?? '');
$user_id    = (int) $_SESSION['user_id'];
$actor_role = (string) $_SESSION['role'];

if ($action === 'place') {
  $book_id = (int) ($_POST['book_id'] ?? 0);

  if ($book_id < 1) {
    $_SESSION['flash_error'] = 'Invalid book reference.';
    header('Location: ' . BASE_URL . 'borrower/catalog.php');
    exit;
  }

  $pdo->beginTransaction();
  try {
    $result = BorrowerReservationModule::place($pdo, $user_id, $book_id, $actor_role);
    if (!$result['success']) {
      $pdo->rollBack();
      $_SESSION['flash_error'] = _reserve_place_error($result['reason_code'], $pdo, $user_id);
      header('Location: ' . BASE_URL . 'borrower/catalog.php');
      exit;
    }
    $pdo->commit();
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('[reserve.php] place failed: ' . $e->getMessage());
    $_SESSION['flash_error'] = 'An unexpected error occurred. Please try again.';
    header('Location: ' . BASE_URL . 'borrower/catalog.php');
    exit;
  }

  $_SESSION['flash_success'] = 'Reservation placed. You are #' . $result['queue_position'] . ' in line.';
  $_SESSION['flash_receipt_no'] = (string) ($result['receipt']['receipt_no'] ?? '');
  header('Location: ' . BASE_URL . 'borrower/catalog.php');
  exit;
}

if ($action === 'cancel') {
  $reservation_id = (int) ($_POST['reservation_id'] ?? 0);
  $cancel_redirect = _sanitize_borrower_redirect((string) ($_POST['redirect_to'] ?? ''));

  if ($reservation_id < 1) {
    $_SESSION['flash_error'] = 'Invalid reservation reference.';
    header('Location: ' . BASE_URL . $cancel_redirect);
    exit;
  }

  $pdo->beginTransaction();
  try {
    $result = BorrowerReservationModule::cancel($pdo, $user_id, $reservation_id, $actor_role);
    if (!$result['success']) {
      $pdo->rollBack();
      $_SESSION['flash_error'] = _reserve_cancel_error($result['reason_code']);
      header('Location: ' . BASE_URL . $cancel_redirect);
      exit;
    }
    $pdo->commit();
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('[reserve.php] cancel failed: ' . $e->getMessage());
    $_SESSION['flash_error'] = 'An error occurred. Please try again.';
    header('Location: ' . BASE_URL . $cancel_redirect);
    exit;
  }

  $_SESSION['flash_success'] = 'Reservation cancelled.';
  header('Location: ' . BASE_URL . $cancel_redirect);
  exit;
}

header('Location: ' . BASE_URL . 'borrower/catalog.php');
exit;

function _reserve_place_error(string $reason_code, PDO $pdo, int $userId): string
{
  switch ($reason_code) {
    case 'delinquent':
      $fines = get_unpaid_fines_total($pdo, $userId);
      return 'You have outstanding unpaid fines (₱' . number_format($fines, 2) . ') and cannot reserve books.';
    case 'overdue_loans':
      return 'You have overdue books that must be returned before reserving new ones.';
    case 'max_limit':
      $max = (int) get_setting($pdo, 'max_borrow_limit', '3');
      return "You have reached your maximum allowed active loans and reservations ({$max}).";
    case 'duplicate_res':
      return 'You already have an active reservation request for this book.';
    case 'duplicate_loan':
      return 'You already have an active loan for this book.';
    default:
      return 'Cannot place reservation. Please try again.';
  }
}

function _reserve_cancel_error(string $reason_code): string
{
  switch ($reason_code) {
    case 'not_found':
      return 'Reservation not found.';
    case 'not_cancellable':
      return 'Reservation already changed and can no longer be cancelled.';
    default:
      return 'Cannot cancel reservation. Please try again.';
  }
}

/**
 * Sanitize and validate a borrower-relative redirect path.
 * Rejects paths with control characters, path traversal, or non-borrower prefixes.
 */
function _sanitize_borrower_redirect(string $value): string
{
  $default = 'borrower/index.php';
  $candidate = trim($value);
  if ($candidate === '') {
    return $default;
  }
  if (preg_match('/[\x00-\x1F\x7F]/', $candidate) === 1) {
    return $default;
  }
  if (preg_match('/^(?:[a-z][a-z0-9+\-.]*:|\/\/|\\\\)/i', $candidate) === 1) {
    return $default;
  }
  if (strpos($candidate, '..') !== false || strpos($candidate, '//') !== false) {
    return $default;
  }
  if (!str_starts_with($candidate, 'borrower/')) {
    return $default;
  }
  return $candidate;
}