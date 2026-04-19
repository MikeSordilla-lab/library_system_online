<?php

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../../includes/receipt_pdf.php';

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

$mode = trim((string) ($_GET['mode'] ?? 'download'));
$url = receipt_view_url($receipt);
$meta = receipt_pdf_meta($receipt);
$receiptNo = (string) ($receipt['receipt_no'] ?? '');
$filename = receipt_pdf_filename($receiptNo);

if ($mode === 'meta') {
  receipt_api_json(200, true, 'PDF metadata loaded.', [
    'receipt_no' => $receiptNo,
    'filename' => $filename,
    'download_url' => (defined('BASE_URL') ? (string) constant('BASE_URL') : '/') . 'api/receipts/pdf.php?no=' . rawurlencode($receiptNo),
  ], $meta);
}

log_receipt_ticket_event($pdo, (int) $receipt['id'], $actor['id'], $actor['role'], 'pdf_request', [
  'mode' => $mode,
  'fallback' => 'pdf',
], [
  'channel' => 'api',
]);

if ($mode === 'download_html') {
  $htmlFilename = 'receipt-' . preg_replace('/[^A-Za-z0-9\-]/', '-', (string) ($receipt['receipt_no'] ?? 'receipt')) . '.html';
  header('Content-Type: text/html; charset=UTF-8');
  header('Content-Disposition: attachment; filename="' . $htmlFilename . '"');
  echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Receipt HTML Download</title></head><body>';
  echo '<p>HTML export mode. Open printable HTML URL:</p>';
  echo '<p><a href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '</a></p>';
  echo '<p>Receipt #: ' . htmlspecialchars((string) ($receipt['receipt_no'] ?? ''), ENT_QUOTES, 'UTF-8') . '</p>';
  echo '</body></html>';
  exit;
}

$model = build_receipt_view_model($pdo, $receipt, true);
$pdfLines = receipt_pdf_lines_from_model($model);
$pdfBinary = receipt_pdf_render($pdfLines, 'Receipt ' . $receiptNo);

while (ob_get_level() > 0) {
  ob_end_clean();
}

if (function_exists('ini_set')) {
  @ini_set('zlib.output_compression', '0');
}

header('Content-Type: application/pdf');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store, max-age=0');
header('Content-Disposition: attachment; filename="' . $filename . '"; filename*=UTF-8\'\'' . rawurlencode($filename));
header('Content-Length: ' . strlen($pdfBinary));
echo $pdfBinary;
exit;
