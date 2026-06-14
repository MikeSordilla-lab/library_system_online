<?php

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../../src/utils/rate_limit.php';
require_once __DIR__ . '/../../includes/ReceiptTicketWorkflowModule.php';

receipt_api_require_method('POST');

$result = rate_limit_check('api_receipts_reprint', 20, 60);
rate_limit_headers($result, 20);
if (!$result['allowed']) {
  receipt_api_json(429, false, 'Too many requests. Please try again later.', [], [
    'retry_after' => $result['retry_after'],
  ]);
}

$pdo = get_db();
receipt_api_phase1_guard($pdo);
$actor = receipt_api_actor($pdo);
$body = receipt_api_read_json_body();

$result = ReceiptTicketWorkflowModule::reprintFromRequest($pdo, $actor, $body, 'api');
if (!empty($result['success'])) {
  $receipt = $result['receipt'];
  $reprint = $result['reprint'];
  receipt_api_json(200, true, 'Reprint logged.', [
    'receipt' => receipt_public_api_data($pdo, $receipt, true),
    'reprint' => [
      'reason' => $reprint['reason'],
      'target' => $reprint['target'],
      'job_id' => (int) (($reprint['job']['id'] ?? 0)),
    ],
  ]);
}

switch ((string) ($result['reason_code'] ?? 'reprint_failed')) {
  case 'not_found':
    receipt_api_json(404, false, 'Receipt not found.');
    break;
  case 'access_denied':
    receipt_api_json(403, false, 'Access denied for this receipt.');
    break;
  case 'reason_required':
    receipt_api_json(422, false, 'Reprint reason is required.');
    break;
  case 'invalid_payload':
    receipt_api_json(422, false, (string) ($result['message'] ?? 'Invalid reprint request.'));
    break;
  case 'reprint_failed':
  default:
    receipt_api_json(500, false, 'Failed to log reprint.');
}
