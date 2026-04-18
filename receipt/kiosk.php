<?php

$allowed_roles = ['librarian', 'borrower'];
require_once __DIR__ . '/../includes/auth_guard.php';
require_once __DIR__ . '/../includes/receipts.php';

$pdo = get_db();
$receipt_no = trim((string) ($_GET['no'] ?? ''));

if ($receipt_no === '') {
  http_response_code(400);
  exit('Missing receipt number.');
}

$receipt = get_receipt_ticket_by_number($pdo, $receipt_no);
if (!$receipt) {
  http_response_code(404);
  exit('Receipt not found.');
}

$viewer_id = (int) ($_SESSION['user_id'] ?? 0);
$viewer_role = (string) ($_SESSION['role'] ?? '');
if (!can_access_receipt_ticket($receipt, $viewer_id, $viewer_role)) {
  http_response_code(403);
  require_once __DIR__ . '/../403.php';
  exit;
}

log_receipt_ticket_event($pdo, (int) $receipt['id'], $viewer_id, $viewer_role, 'kiosk_view', [
  'source' => 'receipt/kiosk.php',
], [
  'channel' => 'kiosk',
]);

header('Location: ' . (defined('BASE_URL') ? (string) constant('BASE_URL') : '/') . 'receipt/view.php?no=' . rawurlencode($receipt_no) . '&compact=1');
exit;
