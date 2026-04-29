<?php

/**
 * librarian/pay-fine.php - Process Fine Payment Transaction
 *
 * POST: Clear all unpaid fines for a specific user.
 * Delegates to circulation orchestration layer.
 * Protected: Librarian role only.
 */

$allowed_roles = ['librarian'];
require_once __DIR__ . '/../includes/auth_guard.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/circulation.php';
require_once __DIR__ . '/../includes/receipts.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  header('Location: ' . BASE_URL . 'librarian/index.php');
  exit;
}

csrf_verify();

$pdo = get_db();
$user_id   = (int) ($_POST['user_id'] ?? 0);
$actor_id   = (int) $_SESSION['user_id'];
$actor_role = (string) $_SESSION['role'];

if ($user_id < 1) {
  $_SESSION['flash_error'] = 'Invalid user selected.';
  header('Location: ' . BASE_URL . 'librarian/checkout.php');
  exit;
}

$result = pay_user_fines($pdo, $user_id, $actor_id, $actor_role);

if ($result['success']) {
  $_SESSION['flash_success'] = 'Successfully cleared fines ($' . number_format($result['total_paid'], 2) . ') for user.';
  if ($result['receipt_no']) {
    $_SESSION['flash_receipt_no'] = $result['receipt_no'];
  }
} else {
  $_SESSION['flash_info'] = $result['message'];
}

header('Location: ' . BASE_URL . 'librarian/checkout.php');
exit;
