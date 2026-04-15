<?php

require_once __DIR__ . '/_bootstrap.php';

receipt_api_require_method('GET');

$pdo = get_db();
receipt_api_phase1_guard($pdo);
$actor = receipt_api_actor($pdo);

$receipt = receipt_api_receipt_from_request($pdo);
if (!$receipt) {
  receipt_api_json(404, false, 'Receipt not found.');
}

if (!can_access_receipt_ticket($receipt, $actor['id'], $actor['role'])) {
  receipt_api_json(403, false, 'Access denied for this receipt.');
}

log_receipt_ticket_event($pdo, (int) $receipt['id'], $actor['id'], $actor['role'], 'view', [
  'source' => 'api/receipts/get',
], [
  'channel' => 'api',
]);

receipt_api_json(200, true, 'Receipt loaded.', [
  'receipt' => receipt_public_api_data($pdo, $receipt, true),
]);
