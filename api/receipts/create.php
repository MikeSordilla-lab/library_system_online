<?php

require_once __DIR__ . '/_bootstrap.php';

receipt_api_require_method('POST');

$pdo = get_db();
receipt_api_phase1_guard($pdo);
$actor = receipt_api_actor($pdo);

if ($actor['role'] !== 'admin' && $actor['role'] !== 'librarian' && $actor['role'] !== 'borrower') {
  receipt_api_json(403, false, 'Role is not allowed to create receipts.');
}

$body = receipt_api_read_json_body();

$patron_user_id = isset($body['patron_user_id']) ? (int) $body['patron_user_id'] : $actor['id'];
if ($actor['role'] === 'borrower') {
  $patron_user_id = $actor['id'];
}

$type = trim((string) ($body['type'] ?? ''));
$reference_table = trim((string) ($body['reference_table'] ?? ''));
$reference_id = (int) ($body['reference_id'] ?? 0);

if ($type === '' || $reference_table === '' || $reference_id < 1 || $patron_user_id < 1) {
  receipt_api_json(422, false, 'type, patron_user_id, reference_table, reference_id are required.');
}

try {
  $receipt = issue_receipt_ticket($pdo, [
    'type' => $type,
    'actor_user_id' => $actor['id'],
    'actor_role' => $actor['role'],
    'patron_user_id' => $patron_user_id,
    'reference_table' => $reference_table,
    'reference_id' => $reference_id,
    'idempotency_key' => (string) ($body['idempotency_key'] ?? ''),
    'amount' => isset($body['amount']) ? (float) $body['amount'] : null,
    'currency' => (string) ($body['currency'] ?? 'USD'),
    'payload' => is_array($body['payload'] ?? null) ? $body['payload'] : [],
    'status' => (string) ($body['status'] ?? 'issued'),
    'format' => (string) ($body['format'] ?? 'thermal'),
    'locale' => (string) ($body['locale'] ?? 'en_US'),
    'timezone' => (string) ($body['timezone'] ?? 'UTC'),
    'branch_id' => isset($body['branch_id']) ? (int) $body['branch_id'] : null,
    'channel' => (string) ($body['channel'] ?? 'api'),
    'reason' => (string) ($body['reason'] ?? ''),
  ]);

  if (!can_access_receipt_ticket($receipt, $actor['id'], $actor['role'])) {
    receipt_api_json(403, false, 'Not allowed to access created receipt.');
  }

  receipt_api_json(201, true, 'Receipt created.', [
    'receipt' => receipt_public_api_data($pdo, $receipt, true),
    'urls' => [
      'html' => receipt_view_url($receipt),
      'kiosk' => receipt_kiosk_url($receipt),
    ],
  ]);
} catch (InvalidArgumentException $e) {
  receipt_api_json(422, false, $e->getMessage());
} catch (Throwable $e) {
  error_log('[api/receipts/create] ' . $e->getMessage());
  receipt_api_json(500, false, 'Failed to create receipt.');
}
