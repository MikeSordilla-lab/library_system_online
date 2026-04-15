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

$as_text = ((string) ($_GET['format'] ?? 'json')) === 'text';
$qr_payload = build_receipt_qr_payload($receipt);

if ($as_text) {
  header('Content-Type: text/plain; charset=UTF-8');
  echo $qr_payload;
  exit;
}

receipt_api_json(200, true, 'QR payload generated.', [
  'receipt_no' => (string) ($receipt['receipt_no'] ?? ''),
  'qr_payload' => $qr_payload,
]);
