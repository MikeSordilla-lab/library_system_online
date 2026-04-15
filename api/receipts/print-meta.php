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

receipt_api_json(200, true, 'Print metadata loaded.', [
  'receipt_no' => (string) ($receipt['receipt_no'] ?? ''),
  'format' => (string) ($receipt['format'] ?? 'thermal'),
  'status' => (string) ($receipt['status'] ?? 'issued'),
  'urls' => [
    'html' => receipt_view_url($receipt),
    'kiosk' => receipt_kiosk_url($receipt),
    'pdf' => (defined('BASE_URL') ? (string) constant('BASE_URL') : '/') . 'api/receipts/pdf.php?no=' . rawurlencode((string) ($receipt['receipt_no'] ?? '')),
  ],
]);
