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

$mode = trim((string) ($_GET['mode'] ?? 'inline_html'));
$url = receipt_view_url($receipt);
$meta = receipt_pdf_meta($receipt);

log_receipt_ticket_event($pdo, (int) $receipt['id'], $actor['id'], $actor['role'], 'pdf_request', [
  'mode' => $mode,
  'fallback' => 'html',
], [
  'channel' => 'api',
]);

if ($mode === 'download_html') {
  $filename = 'receipt-' . preg_replace('/[^A-Za-z0-9\-]/', '-', (string) ($receipt['receipt_no'] ?? 'receipt')) . '.html';
  header('Content-Type: text/html; charset=UTF-8');
  header('Content-Disposition: attachment; filename="' . $filename . '"');
  echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Receipt HTML Download</title></head><body>';
  echo '<p>PDF generation unavailable in this deployment. Open printable HTML URL:</p>';
  echo '<p><a href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '</a></p>';
  echo '<p>Receipt #: ' . htmlspecialchars((string) ($receipt['receipt_no'] ?? ''), ENT_QUOTES, 'UTF-8') . '</p>';
  echo '</body></html>';
  exit;
}

receipt_api_json(200, true, 'PDF fallback response.', [
  'receipt_no' => (string) ($receipt['receipt_no'] ?? ''),
  'fallback' => [
    'type' => 'html',
    'url' => $url,
  ],
], $meta);
