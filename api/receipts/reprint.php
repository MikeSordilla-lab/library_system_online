<?php

require_once __DIR__ . '/_bootstrap.php';

receipt_api_require_method('POST');

$pdo = get_db();
receipt_api_phase1_guard($pdo);
$actor = receipt_api_actor($pdo);

$body = receipt_api_read_json_body();
$receipt_id = (int) ($body['id'] ?? 0);
$receipt_no = trim((string) ($body['no'] ?? ''));
$reason = trim((string) ($body['reason'] ?? ''));

if ($receipt_id > 0) {
  $receipt = get_receipt_ticket_by_id($pdo, $receipt_id);
} else {
  $receipt = $receipt_no !== '' ? get_receipt_ticket_by_number($pdo, $receipt_no) : null;
}

if (!$receipt) {
  receipt_api_json(404, false, 'Receipt not found.');
}

if (!can_access_receipt_ticket($receipt, $actor['id'], $actor['role'])) {
  receipt_api_json(403, false, 'Access denied for this receipt.');
}

if ($reason === '') {
  receipt_api_json(422, false, 'Reprint reason is required.');
}

try {
  $result = request_receipt_reprint($pdo, $receipt, $actor['id'], $actor['role'], $reason, [
    'target' => (string) ($body['target'] ?? 'browser_print'),
    'channel' => 'api',
  ]);

  receipt_api_json(200, true, 'Reprint logged.', [
    'receipt' => receipt_public_api_data($pdo, $receipt, true),
    'reprint' => [
      'reason' => $result['reason'],
      'target' => $result['target'],
      'job_id' => (int) (($result['job']['id'] ?? 0)),
    ],
  ]);
} catch (InvalidArgumentException $e) {
  receipt_api_json(422, false, $e->getMessage());
} catch (Throwable $e) {
  error_log('[api/receipts/reprint] ' . $e->getMessage());
  receipt_api_json(500, false, 'Failed to log reprint.');
}
