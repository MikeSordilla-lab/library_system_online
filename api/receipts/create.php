<?php

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../../src/utils/rate_limit.php';
require_once __DIR__ . '/../../includes/ReceiptTicketWorkflowModule.php';

receipt_api_require_method('POST');

if (!rate_limit('api_receipts_create', 20, 60)) {
  receipt_api_json(429, false, 'Too many requests. Please try again later.');
}

$pdo = get_db();
receipt_api_phase1_guard($pdo);
$actor = receipt_api_actor($pdo);
$body = receipt_api_read_json_body();

$result = ReceiptTicketWorkflowModule::createFromRequest($pdo, $actor, $body);
if (!empty($result['success'])) {
  $receipt = $result['receipt'];
  receipt_api_json(201, true, 'Receipt created.', [
    'receipt' => receipt_public_api_data($pdo, $receipt, true),
    'urls' => [
      'html' => receipt_view_url($receipt),
      'kiosk' => receipt_kiosk_url($receipt),
    ],
  ]);
}

switch ((string) ($result['reason_code'] ?? 'create_failed')) {
  case 'role_not_allowed':
  case 'borrower_patron_mismatch':
  case 'created_receipt_forbidden':
    receipt_api_json(403, false, $result['reason_code'] === 'borrower_patron_mismatch'
      ? 'Borrowers can only create receipts for themselves.'
      : ($result['reason_code'] === 'created_receipt_forbidden' ? 'Not allowed to access created receipt.' : 'Role is not allowed to create receipts.'));
    break;
  case 'missing_required_fields':
    receipt_api_json(422, false, 'type, patron_user_id, reference_table, reference_id are required.');
    break;
  case 'invalid_payload':
    receipt_api_json(422, false, (string) ($result['message'] ?? 'Invalid receipt payload.'));
    break;
  case 'create_failed':
  default:
    receipt_api_json(500, false, 'Failed to create receipt.');
}
