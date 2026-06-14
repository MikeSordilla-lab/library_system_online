<?php

declare(strict_types=1);

function calendar_input_assert(bool $condition, string $message): void
{
  if (!$condition) {
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
  }
}

$root = dirname(__DIR__);
$receiptPage = file_get_contents($root . '/receipt/index.php');
$recordsPage = file_get_contents($root . '/librarian/records.php');
$receiptWorkflowModule = file_get_contents($root . '/includes/ReceiptTicketWorkflowModule.php');
$recordsReportModule = file_get_contents($root . '/includes/CirculationRecordsReportModule.php');

calendar_input_assert(is_string($receiptPage), 'Could not read receipt index page.');
calendar_input_assert(is_string($recordsPage), 'Could not read librarian records page.');
calendar_input_assert(is_string($receiptWorkflowModule), 'Could not read receipt workflow module.');
calendar_input_assert(is_string($recordsReportModule), 'Could not read records report module.');

foreach ([
  'receipt date from' => [$receiptPage, 'id="receipt-date-from"', 'name="date_from"'],
  'receipt date to' => [$receiptPage, 'id="receipt-date-to"', 'name="date_to"'],
  'records date from' => [$recordsPage, 'id="filter-date-from"', 'name="date_from"'],
  'records date to' => [$recordsPage, 'id="filter-date-to"', 'name="date_to"'],
] as $label => [$source, $id, $name]) {
  $inputPattern = '/<input\b(?=[^>]*' . preg_quote($id, '/') . ')(?=[^>]*' . preg_quote($name, '/') . ')(?=[^>]*type="text")(?=[^>]*placeholder="mm-dd-yy")[^>]*>/s';
  calendar_input_assert((bool) preg_match($inputPattern, $source), "{$label} should stay visible as a mm-dd-yy text field.");
}

calendar_input_assert(substr_count($receiptPage, 'data-calendar-trigger') >= 2, 'Receipt filters should include clickable calendar trigger buttons.');
calendar_input_assert(substr_count($recordsPage, 'data-calendar-trigger') >= 2, 'Records filters should include clickable calendar trigger buttons.');
calendar_input_assert(str_contains($receiptPage, 'data-calendar-hidden'), 'Receipt filters should include hidden native date inputs.');
calendar_input_assert(str_contains($recordsPage, 'data-calendar-hidden'), 'Records filters should include hidden native date inputs.');
calendar_input_assert(str_contains($receiptPage . $receiptWorkflowModule, "->format('m-d-y')"), 'Receipt date values should render in mm-dd-yy.');
calendar_input_assert(str_contains($recordsPage . $recordsReportModule, "->format('m-d-y')"), 'Records date values should render in mm-dd-yy.');
calendar_input_assert(str_contains($receiptPage, 'date-calendar-fields.js'), 'Receipt filters should load calendar field behavior.');
calendar_input_assert(str_contains($recordsPage, 'date-calendar-fields.js'), 'Records filters should load calendar field behavior.');

echo "Date filter calendar input tests passed.\n";
