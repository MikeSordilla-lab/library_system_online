<?php

$root = dirname(__DIR__);
require_once $root . '/includes/receipt_pdf.php';

function t_assert(bool $condition, string $message): void
{
  if (!$condition) {
    fwrite(STDERR, '[FAIL] ' . $message . PHP_EOL);
    exit(1);
  }
}

$filename = receipt_pdf_filename('RCPT-2026-0001');
t_assert($filename === 'receipt_RCPT-2026-0001.pdf', 'Filename format should match receipt_<ticket>.pdf');

$model = [
  'receipt_no' => 'RCPT-2026-0001',
  'type_label' => 'Checkout Ticket',
  'issued_at_formatted' => 'Apr 20, 2026 09:00 AM UTC',
  'status' => 'issued',
  'format' => 'thermal',
  'patron' => [
    'name' => 'Jo** Doe',
    'email' => 'jo***@example.com',
  ],
  'amount' => 0,
  'currency' => 'USD',
  'channel' => 'card',
  'library_label' => 'Downtown Library Branch',
  'reference_table' => 'Loans',
  'reference_id' => 42,
  'details' => [
    ['Book', 'Clean Code'],
    ['Due Date', '2026-04-27'],
  ],
  'payload_public' => [
    'tax_amount' => '0.00 USD',
    'payment_method' => 'Card',
    'items' => [
      ['name' => 'Clean Code'],
      ['name' => 'Bookmark'],
    ],
  ],
];

$lines = receipt_pdf_lines_from_model($model);
t_assert(in_array('Receipt No: RCPT-2026-0001', $lines, true), 'PDF lines should include receipt number');
t_assert(in_array('Ticket Number: RCPT-2026-0001', $lines, true), 'PDF lines should include ticket number label');
t_assert(in_array('Date/Time: Apr 20, 2026 09:00 AM UTC', $lines, true), 'PDF lines should include date/time when available');
t_assert(in_array('Customer Name: Jo** Doe', $lines, true), 'PDF lines should include customer name when available');
t_assert(in_array('Items/Services: Clean Code, Bookmark', $lines, true), 'PDF lines should include items/services when available');
t_assert(in_array('Taxes: 0.00 USD', $lines, true), 'PDF lines should include taxes when available');
t_assert(in_array('Total Amount: 0.00 USD', $lines, true), 'PDF lines should include total amount when available');
t_assert(in_array('Payment Method: Card', $lines, true), 'PDF lines should include payment method when available');
t_assert(in_array('Business Info: Downtown Library Branch', $lines, true), 'PDF lines should include business info when available');
t_assert(in_array('Type: Checkout Ticket', $lines, true), 'PDF lines should include type label');

$pdf = receipt_pdf_render($lines, 'Receipt RCPT-2026-0001');
t_assert(strncmp($pdf, '%PDF-1.4', 8) === 0, 'Rendered content should start with PDF header');
t_assert(strpos($pdf, "%\xE2\xE3\xCF\xD3") !== false, 'Rendered PDF should include binary marker line');
t_assert(strpos($pdf, '(Receipt #:) Tj') !== false && strpos($pdf, '(RCPT-2026-0001) Tj') !== false, 'Rendered PDF should include receipt number in header key-value layout');
t_assert(strpos($pdf, '(Payment Method) Tj') !== false && strpos($pdf, '(Card) Tj') !== false, 'Rendered PDF should include payment method in key-value layout');
t_assert(strpos($pdf, '/ProcSet [/PDF /Text]') !== false, 'Rendered PDF page resources should include ProcSet with PDF and Text');
t_assert(strpos($pdf, "q\n0.55 w") !== false, 'Rendered PDF should start drawing commands inside graphics state');
t_assert(strpos($pdf, '/F2 18 Tf') !== false, 'Rendered PDF should use bold Helvetica for heading hierarchy');
t_assert(strpos($pdf, '/F1 10 Tf') !== false, 'Rendered PDF should use regular Helvetica for body values');
t_assert(strpos($pdf, "\nQ\nendstream") !== false, 'Rendered PDF should restore graphics state before stream end');
t_assert(strpos($pdf, '(Library Receipt) Tj') !== false, 'Rendered PDF should include visible title line in text stream');
t_assert(strpos($pdf, '(TOTAL AMOUNT) Tj') !== false, 'Rendered PDF should include emphasized summary section label');
t_assert(strpos($pdf, '/F2 20 Tf') !== false, 'Rendered PDF should emphasize total amount using larger bold font');
t_assert(strpos($pdf, '/Info 7 0 R') !== false, 'Rendered PDF trailer should include indirect info reference');
t_assert(strpos($pdf, '/Title (Receipt RCPT-2026-0001)') !== false, 'Rendered PDF should include info object title');
t_assert(strpos($pdf, 'xref') !== false, 'Rendered PDF should contain xref table');
t_assert(strpos($pdf, '%%EOF') !== false, 'Rendered PDF should contain EOF marker');

$longValueModel = $model;
$longValueModel['payload_public']['business_name'] = str_repeat('Central Library Annex ', 20);
$longValueModel['payload_public']['items'] = [[
  'name' => str_repeat('Very long catalog item name ', 25),
]];
$longLines = receipt_pdf_lines_from_model($longValueModel);
$longPdf = receipt_pdf_render($longLines, 'Receipt RCPT-2026-0001 LONG');
t_assert(strpos($longPdf, 'Continued fields truncated for compatibility') !== false || strpos($longPdf, 'Very long catalog item name') !== false, 'Rendered PDF should either truncate with indicator or wrap long content safely');

$nonAsciiJson = <<<'JSON'
{
  "receipt_no": "RCPT-2026-0099",
  "type_label": "Service Receipt",
  "issued_at_formatted": "Apr 20, 2026 09:30 AM UTC",
  "status": "issued",
  "format": "thermal",
  "patron": {
    "name": "Jos\u00E9 \u03A3",
    "email": "jos\u00E9@ex\u00E4mple.com"
  },
  "currency": "USD",
  "payload_public": {
    "payment_method": "Card \u2014 chip",
    "items": [
      {"name": "Caf\u00E9 \u201CPremium\u201D"},
      {"name": "Late fee \u20B1 10"}
    ],
    "business_name": "Librar\u00FD \u21161",
    "taxes": "\u20AC1.25",
    "total_amount": "\u20AC12.50"
  }
}
JSON;

$nonAsciiModel = json_decode($nonAsciiJson, true);
t_assert(is_array($nonAsciiModel), 'Non-ASCII test fixture should decode properly');
$nonAsciiLines = receipt_pdf_lines_from_model($nonAsciiModel);
$nonAsciiPdf = receipt_pdf_render($nonAsciiLines, 'Receipt \u2014 RCPT-2026-0099');

t_assert(strpos($nonAsciiPdf, 'Receipt data unavailable') === false, 'Non-ASCII receipt should still render extracted lines');
t_assert(strpos($nonAsciiPdf, '(RCPT-2026-0099) Tj') !== false, 'Non-ASCII receipt should preserve receipt identifier value');
t_assert(strpos($nonAsciiPdf, '(Customer Name) Tj') !== false, 'Non-ASCII receipt should include customer label after normalization');
t_assert((bool) preg_match('/stream\n[\x20-\x7E\n\r\t]*endstream/s', $nonAsciiPdf), 'PDF content stream should stay printable ASCII-safe');

fwrite(STDOUT, "[OK] receipt_pdf helper tests passed" . PHP_EOL);
exit(0);
