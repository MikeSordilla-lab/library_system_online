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
  header('Location: ' . BASE_URL . 'librarian/checkout.php');
  exit;
}

$borrower_stmt = $pdo->prepare('SELECT id, full_name, email FROM Users WHERE id = ? LIMIT 1');
$borrower_stmt->execute([$user_id]);
$borrower = $borrower_stmt->fetch();

if (!$borrower) {
  $_SESSION['flash_error'] = 'Borrower not found.';
  header('Location: ' . BASE_URL . 'librarian/checkout.php');
  exit;
}

$pdo->beginTransaction();

try {
  // Get total fines
  $stmt = $pdo->prepare("SELECT SUM(fine_amount) FROM Circulation WHERE user_id = ? AND fine_paid = 0");
  $stmt->execute([$user_id]);
  $total_fines = (float) $stmt->fetchColumn();

  if ($total_fines > 0) {
    // Update fines to paid
    $upd = $pdo->prepare("UPDATE Circulation SET fine_paid = 1, fine_paid_at = NOW() WHERE user_id = ? AND fine_paid = 0");
    $upd->execute([$user_id]);
    
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
      'currency'        => 'USD',
      'format'          => 'a4',
      'channel'         => 'librarian_console',
      'payload'         => [
        'patron_name'   => (string) ($borrower['full_name'] ?? ''),
        'patron_email'  => (string) ($borrower['email'] ?? ''),
        'total_fines'   => $total_fines,
        'settled_scope' => 'all_unpaid_fines',
      ],
    ]);
    
    $pdo->commit();
    $_SESSION['flash_success'] = 'Successfully cleared fines ($' . number_format($total_fines, 2) . ') for user.';
    $receipt_no = (string) ($receipt['receipt_no'] ?? '');
    $close_to = rawurlencode('librarian/checkout.php');
    header('Location: ' . BASE_URL . 'receipt/view.php?no=' . rawurlencode($receipt_no) . '&close_to=' . $close_to . '&autofocus_close=1');
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

header('Location: ' . BASE_URL . 'librarian/checkout.php');
exit;
