<?php

/**
 * librarian/pay-fine.php - Process Fine Payment Transaction
 *
 * POST: Clear all unpaid fines for a specific user.
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
$user_id = (int) ($_POST['user_id'] ?? 0);
$actor_id = (int) $_SESSION['user_id'];
$actor_role = (string) $_SESSION['role'];

if ($user_id < 1) {
  $_SESSION['flash_error'] = 'Invalid user selected.';
  header('Location: ' . BASE_URL . 'librarian/clear_fines.php');
  exit;
}

$borrower_stmt = $pdo->prepare('SELECT id, full_name, email FROM Users WHERE id = ? LIMIT 1');
$borrower_stmt->execute([$user_id]);
$borrower = $borrower_stmt->fetch();

if (!$borrower) {
  $_SESSION['flash_error'] = 'Borrower not found.';
  header('Location: ' . BASE_URL . 'librarian/clear_fines.php');
  exit;
}

$pdo->beginTransaction();

try {
  $payment = mark_returned_positive_fines_paid($pdo, $user_id);
  $total_fines = (float) $payment['total'];

  if ($total_fines > 0) {
    // Log payment
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
      'currency'        => receipt_default_currency(),
      'format'          => 'a4',
      'channel'         => 'librarian_console',
      'payload'         => [
        'patron_name'   => (string) ($borrower['full_name'] ?? ''),
        'patron_email'  => (string) ($borrower['email'] ?? ''),
        'total_fines'   => $total_fines,
        'settled_scope' => 'returned_positive_unpaid_fines',
      ],
    ]);
    
    $pdo->commit();
    $_SESSION['flash_success'] = 'Successfully cleared fines (' . receipt_format_money($total_fines) . ') for user.';
    $receipt_no = (string) ($receipt['receipt_no'] ?? '');
    $_SESSION['flash_receipt_no'] = $receipt_no;
    header('Location: ' . BASE_URL . 'librarian/clear_fines.php');
    exit;
  } else {
    $pdo->rollBack();
    $_SESSION['flash_info'] = 'This user has no outstanding fines.';
  }
} catch (Throwable $e) {
  $pdo->rollBack();
  error_log('[pay-fine.php] Transaction failed: ' . $e->getMessage());
  $_SESSION['flash_error'] = 'Failed to process payment.';
}

header('Location: ' . BASE_URL . 'librarian/clear_fines.php');
exit;
