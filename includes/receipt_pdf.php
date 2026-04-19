<?php

if (defined('RECEIPT_PDF_PHP_LOADED')) {
  return;
}
define('RECEIPT_PDF_PHP_LOADED', true);

function receipt_pdf_safe_token(string $value, string $fallback = 'receipt'): string
{
  $value = trim($value);
  if ($value === '') {
    return $fallback;
  }

  $value = preg_replace('/[^A-Za-z0-9_-]+/', '_', $value);
  $value = trim((string) $value, '_-');

  if ($value === '') {
    return $fallback;
  }

  return substr($value, 0, 96);
}

function receipt_pdf_filename(string $ticket_number): string
{
  return 'receipt_' . receipt_pdf_safe_token($ticket_number, 'unknown') . '.pdf';
}

function receipt_pdf_escape_text(string $value): string
{
  $value = receipt_pdf_normalize_text($value);
  $value = str_replace('\\', '\\\\', $value);
  $value = str_replace('(', '\\(', $value);
  $value = str_replace(')', '\\)', $value);
  return preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $value) ?? '';
}

function receipt_pdf_normalize_text(string $value): string
{
  $value = str_replace(["\r\n", "\r", "\n", "\t"], ' ', $value);

  if (function_exists('iconv')) {
    $utf8 = @iconv('UTF-8', 'UTF-8//IGNORE', $value);
    if ($utf8 !== false) {
      $value = $utf8;
    }

    $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
    if ($ascii !== false && $ascii !== '') {
      $value = $ascii;
    }
  }

  $value = preg_replace('/[\x00-\x1F\x7F]/', ' ', $value) ?? '';
  $value = preg_replace('/[^\x20-\x7E]/', '?', $value) ?? '';
  $value = preg_replace('/\s+/', ' ', $value) ?? '';

  return trim($value);
}

function receipt_pdf_scalar_to_string($value): string
{
  if ($value === null) {
    return '';
  }

  if (is_string($value)) {
    return trim($value);
  }

  if (is_int($value) || is_float($value)) {
    return trim((string) $value);
  }

  if (is_bool($value)) {
    return $value ? 'yes' : 'no';
  }

  return '';
}

function receipt_pdf_first_non_empty(array $candidates): string
{
  foreach ($candidates as $candidate) {
    $text = receipt_pdf_scalar_to_string($candidate);
    if ($text !== '') {
      return $text;
    }
  }
  return '';
}

function receipt_pdf_extract_items_services(array $model, array $payload): string
{
  $explicitList = [];
  foreach (['items', 'services'] as $key) {
    if (!isset($payload[$key])) {
      continue;
    }
    $value = $payload[$key];
    if (is_array($value)) {
      foreach ($value as $entry) {
        if (is_array($entry)) {
          $entryText = receipt_pdf_first_non_empty([
            $entry['name'] ?? null,
            $entry['title'] ?? null,
            $entry['label'] ?? null,
            $entry['service'] ?? null,
            $entry['item'] ?? null,
          ]);
          if ($entryText !== '') {
            $explicitList[] = $entryText;
          }
        } else {
          $entryText = receipt_pdf_scalar_to_string($entry);
          if ($entryText !== '') {
            $explicitList[] = $entryText;
          }
        }
      }
      continue;
    }

    $valueText = receipt_pdf_scalar_to_string($value);
    if ($valueText !== '') {
      $explicitList[] = $valueText;
    }
  }

  if ($explicitList !== []) {
    return implode(', ', array_slice(array_values(array_unique($explicitList)), 0, 5));
  }

  $details = $model['details'] ?? [];
  if (is_array($details)) {
    $detailValues = [];
    foreach ($details as $line) {
      if (!is_array($line) || count($line) < 2) {
        continue;
      }
      $valueText = receipt_pdf_scalar_to_string($line[1]);
      if ($valueText !== '') {
        $detailValues[] = $valueText;
      }
      if (count($detailValues) >= 5) {
        break;
      }
    }
    if ($detailValues !== []) {
      return implode(', ', $detailValues);
    }
  }

  return receipt_pdf_first_non_empty([
    $payload['item'] ?? null,
    $payload['service'] ?? null,
    $payload['book_title'] ?? null,
    $payload['description'] ?? null,
  ]);
}

function receipt_pdf_lines_from_model(array $model): array
{
  $payload = isset($model['payload_public']) && is_array($model['payload_public'])
    ? $model['payload_public']
    : [];

  $ticketNumber = receipt_pdf_first_non_empty([
    $model['receipt_no'] ?? null,
    $payload['ticket_number'] ?? null,
    $payload['ticket_no'] ?? null,
    $payload['receipt_no'] ?? null,
  ]);

  $issuedAt = receipt_pdf_first_non_empty([
    $model['issued_at_formatted'] ?? null,
    $model['issued_at'] ?? null,
    $payload['date_time'] ?? null,
    $payload['issued_at'] ?? null,
    $payload['transaction_date'] ?? null,
    $payload['created_at'] ?? null,
  ]);

  $customerName = receipt_pdf_first_non_empty([
    $model['patron']['name'] ?? null,
    $payload['customer_name'] ?? null,
    $payload['patron_name'] ?? null,
    $payload['visitor_name'] ?? null,
  ]);

  $itemsServices = receipt_pdf_extract_items_services($model, $payload);
  $taxes = receipt_pdf_first_non_empty([
    $payload['taxes'] ?? null,
    $payload['tax'] ?? null,
    $payload['tax_amount'] ?? null,
    $payload['vat'] ?? null,
    $payload['gst'] ?? null,
  ]);

  $totalAmount = '';
  $amount = $model['amount'] ?? null;
  if ($amount !== null && is_numeric($amount)) {
    $totalAmount = number_format((float) $amount, 2) . ' ' . (string) ($model['currency'] ?? 'USD');
  }
  if ($totalAmount === '') {
    $totalRaw = receipt_pdf_first_non_empty([
      $payload['total_amount'] ?? null,
      $payload['grand_total'] ?? null,
      $payload['total'] ?? null,
      $payload['amount'] ?? null,
    ]);
    if ($totalRaw !== '') {
      $totalAmount = $totalRaw;
      $currency = receipt_pdf_scalar_to_string($model['currency'] ?? '');
      if ($currency !== '' && is_numeric($totalRaw)) {
        $totalAmount = number_format((float) $totalRaw, 2) . ' ' . $currency;
      }
    }
  }

  $paymentMethod = receipt_pdf_first_non_empty([
    $payload['payment_method'] ?? null,
    $payload['payment_type'] ?? null,
    $payload['method'] ?? null,
    $payload['channel'] ?? null,
    $model['channel'] ?? null,
  ]);

  $businessInfo = receipt_pdf_first_non_empty([
    $model['library_label'] ?? null,
    $payload['business_info'] ?? null,
    $payload['business_name'] ?? null,
    $payload['merchant_name'] ?? null,
    $payload['branch_name'] ?? null,
    $payload['library_label'] ?? null,
  ]);

  $lines = [];
  $lines[] = 'Library Receipt';
  if ($ticketNumber !== '') {
    $lines[] = 'Ticket Number: ' . $ticketNumber;
    $lines[] = 'Receipt No: ' . $ticketNumber;
  }
  if ($issuedAt !== '') {
    $lines[] = 'Date/Time: ' . $issuedAt;
  }
  if ($customerName !== '') {
    $lines[] = 'Customer Name: ' . $customerName;
  }
  if ($itemsServices !== '') {
    $lines[] = 'Items/Services: ' . $itemsServices;
  }
  if ($taxes !== '') {
    $lines[] = 'Taxes: ' . $taxes;
  }
  if ($totalAmount !== '') {
    $lines[] = 'Total Amount: ' . $totalAmount;
  }
  if ($paymentMethod !== '') {
    $lines[] = 'Payment Method: ' . $paymentMethod;
  }
  if ($businessInfo !== '') {
    $lines[] = 'Business Info: ' . $businessInfo;
  }

  $lines[] = 'Type: ' . (string) ($model['type_label'] ?? ($model['type'] ?? 'Transaction'));
  $lines[] = 'Issued: ' . ($issuedAt !== '' ? $issuedAt : 'N/A');
  $lines[] = 'Status: ' . (string) ($model['status'] ?? 'issued');
  $lines[] = 'Format: ' . (string) ($model['format'] ?? 'thermal');
  $lines[] = 'Patron: ' . ($customerName !== '' ? $customerName : 'N/A');
  $lines[] = 'Patron Email: ' . (string) ($model['patron']['email'] ?? 'N/A');

  if ($totalAmount !== '') {
    $lines[] = 'Amount: ' . $totalAmount;
  } elseif ($amount !== null && is_numeric($amount)) {
    $lines[] = 'Amount: ' . number_format((float) $amount, 2) . ' ' . (string) ($model['currency'] ?? 'USD');
  }

  $referenceTable = (string) ($model['reference_table'] ?? '');
  $referenceId = (string) ($model['reference_id'] ?? '');
  if ($referenceTable !== '' || $referenceId !== '') {
    $lines[] = 'Reference: ' . $referenceTable . '#' . $referenceId;
  }

  $details = $model['details'] ?? [];
  if (is_array($details) && $details !== []) {
    $lines[] = 'Details:';
    $maxDetailLines = 10;
    $count = 0;
    foreach ($details as $line) {
      if (!is_array($line) || count($line) < 2) {
        continue;
      }
      $lines[] = '- ' . (string) $line[0] . ': ' . (string) $line[1];
      $count++;
      if ($count >= $maxDetailLines) {
        break;
      }
    }
  }

  return $lines;
}

function receipt_pdf_split_label_value(string $line): array
{
  $line = receipt_pdf_normalize_text($line);
  if ($line === '') {
    return ['', ''];
  }

  $pos = strpos($line, ':');
  if ($pos === false) {
    return ['', $line];
  }

  $label = trim(substr($line, 0, $pos));
  $value = trim(substr($line, $pos + 1));
  return [$label, $value];
}

function receipt_pdf_wrap_text(string $text, int $maxChars): array
{
  $text = receipt_pdf_normalize_text($text);
  if ($text === '') {
    return [''];
  }

  if ($maxChars < 8) {
    $maxChars = 8;
  }

  $words = preg_split('/\s+/', $text) ?: [];
  $lines = [];
  $current = '';

  foreach ($words as $word) {
    if ($word === '') {
      continue;
    }

    if (strlen($word) > $maxChars) {
      if ($current !== '') {
        $lines[] = $current;
        $current = '';
      }

      $chunks = str_split($word, $maxChars);
      foreach ($chunks as $chunkIndex => $chunk) {
        if ($chunkIndex === count($chunks) - 1) {
          $current = $chunk;
        } else {
          $lines[] = $chunk;
        }
      }
      continue;
    }

    $candidate = $current === '' ? $word : ($current . ' ' . $word);
    if (strlen($candidate) <= $maxChars) {
      $current = $candidate;
      continue;
    }

    $lines[] = $current;
    $current = $word;
  }

  if ($current !== '') {
    $lines[] = $current;
  }

  return $lines === [] ? [''] : $lines;
}

function receipt_pdf_render(array $lines, string $title = 'Receipt'): string
{
  $safeTitle = receipt_pdf_escape_text($title);
  if ($safeTitle === '') {
    $safeTitle = 'Receipt';
  }

  $normalizedLines = [];
  foreach ($lines as $line) {
    $line = receipt_pdf_normalize_text((string) $line);
    if ($line === '') {
      continue;
    }
    if (strlen($line) > 500) {
      $line = substr($line, 0, 500);
    }
    $normalizedLines[] = $line;
  }

  if ($normalizedLines === []) {
    $normalizedLines = ['Library Receipt', 'Receipt data unavailable'];
  }

  $labelToValue = [];
  $orderedPairs = [];
  foreach ($normalizedLines as $line) {
    [$label, $value] = receipt_pdf_split_label_value($line);
    if ($label !== '') {
      $lower = strtolower($label);
      if (!isset($labelToValue[$lower]) || $labelToValue[$lower] === '') {
        $labelToValue[$lower] = $value;
      }
      $orderedPairs[] = [$label, $value];
      continue;
    }
    $orderedPairs[] = ['', $line];
  }

  $libraryName = $labelToValue['business info'] ?? 'Library System';
  if ($libraryName === '') {
    $libraryName = 'Library System';
  }

  $receiptNumber = $labelToValue['receipt no'] ?? ($labelToValue['ticket number'] ?? 'N/A');
  if ($receiptNumber === '') {
    $receiptNumber = 'N/A';
  }

  $issuedDate = $labelToValue['date/time'] ?? ($labelToValue['issued'] ?? 'N/A');
  if ($issuedDate === '') {
    $issuedDate = 'N/A';
  }

  $totalAmount = $labelToValue['total amount'] ?? ($labelToValue['amount'] ?? 'N/A');
  if ($totalAmount === '') {
    $totalAmount = 'N/A';
  }

  $bodyPairs = [];
  foreach ($orderedPairs as $pair) {
    $label = $pair[0];
    $value = $pair[1];
    $lower = strtolower($label);

    if ($label === '') {
      if (strtolower($value) === 'library receipt') {
        continue;
      }
      $bodyPairs[] = ['', $value];
      continue;
    }

    if (
      $lower === 'receipt no' ||
      $lower === 'ticket number' ||
      $lower === 'date/time' ||
      $lower === 'issued' ||
      $lower === 'business info' ||
      $lower === 'total amount' ||
      $lower === 'amount'
    ) {
      continue;
    }

    if ($lower === 'details' && $value === '') {
      $bodyPairs[] = ['Details', ''];
      continue;
    }

    $bodyPairs[] = [$label, $value];
  }

  $pageWidth = 612;
  $pageHeight = 792;
  $left = 40;
  $right = $pageWidth - 40;
  $top = 760;
  $summaryBottom = 52;
  $summaryHeight = 72;
  $summaryTop = $summaryBottom + $summaryHeight;
  $minBodyY = $summaryTop + 18;

  $commands = [];
  $commands[] = 'q';
  $commands[] = '0.55 w';

  $headerHeight = 86;
  $headerBottom = $top - $headerHeight;
  $commands[] = sprintf('%.2f %.2f %.2f %.2f re S', (float) $left, (float) $headerBottom, (float) ($right - $left), (float) $headerHeight);
  $commands[] = sprintf('%.2f %.2f m %.2f %.2f l S', (float) $left, (float) ($headerBottom + 28), (float) $right, (float) ($headerBottom + 28));

  $commands[] = 'BT';
  $commands[] = '/F2 18 Tf';
  $commands[] = '1 0 0 1 52 736 Tm';
  $commands[] = '(' . receipt_pdf_escape_text('Library Receipt') . ') Tj';
  $commands[] = 'ET';

  $commands[] = 'BT';
  $commands[] = '/F1 11 Tf';
  $commands[] = '1 0 0 1 52 712 Tm';
  $commands[] = '(' . receipt_pdf_escape_text($libraryName) . ') Tj';
  $commands[] = 'ET';

  $commands[] = 'BT';
  $commands[] = '/F2 10 Tf';
  $commands[] = '1 0 0 1 330 724 Tm';
  $commands[] = '(' . receipt_pdf_escape_text('Receipt #:') . ') Tj';
  $commands[] = 'ET';
  $commands[] = 'BT';
  $commands[] = '/F1 10 Tf';
  $commands[] = '1 0 0 1 408 724 Tm';
  $commands[] = '(' . receipt_pdf_escape_text($receiptNumber) . ') Tj';
  $commands[] = 'ET';

  $commands[] = 'BT';
  $commands[] = '/F2 10 Tf';
  $commands[] = '1 0 0 1 330 707 Tm';
  $commands[] = '(' . receipt_pdf_escape_text('Date:') . ') Tj';
  $commands[] = 'ET';
  $commands[] = 'BT';
  $commands[] = '/F1 10 Tf';
  $commands[] = '1 0 0 1 408 707 Tm';
  $commands[] = '(' . receipt_pdf_escape_text($issuedDate) . ') Tj';
  $commands[] = 'ET';

  $commands[] = sprintf('%.2f %.2f m %.2f %.2f l S', (float) $left, (float) ($headerBottom - 12), (float) $right, (float) ($headerBottom - 12));

  $y = $headerBottom - 30;
  $labelX = 52;
  $valueX = 188;
  $lineStep = 14;
  $truncated = false;

  foreach ($bodyPairs as $pair) {
    $label = receipt_pdf_normalize_text($pair[0]);
    $value = receipt_pdf_normalize_text($pair[1]);

    if ($label === 'Details' && $value === '') {
      if ($y < ($minBodyY + 10)) {
        $truncated = true;
        break;
      }
      $commands[] = 'BT';
      $commands[] = '/F2 11 Tf';
      $commands[] = '1 0 0 1 ' . $labelX . ' ' . (int) $y . ' Tm';
      $commands[] = '(' . receipt_pdf_escape_text('Details') . ') Tj';
      $commands[] = 'ET';
      $y -= $lineStep;
      continue;
    }

    $wrapped = receipt_pdf_wrap_text($value, 56);
    if ($label === '') {
      $wrapped = receipt_pdf_wrap_text($value, 78);
    }

    foreach ($wrapped as $lineIndex => $wrappedLine) {
      if ($y < $minBodyY) {
        $truncated = true;
        break 2;
      }

      if ($label !== '' && $lineIndex === 0) {
        $commands[] = 'BT';
        $commands[] = '/F2 10 Tf';
        $commands[] = '1 0 0 1 ' . $labelX . ' ' . (int) $y . ' Tm';
        $commands[] = '(' . receipt_pdf_escape_text($label) . ') Tj';
        $commands[] = 'ET';
      }

      $textX = $label === '' ? $labelX : $valueX;
      $commands[] = 'BT';
      $commands[] = '/F1 10 Tf';
      $commands[] = '1 0 0 1 ' . $textX . ' ' . (int) $y . ' Tm';
      $commands[] = '(' . receipt_pdf_escape_text($wrappedLine) . ') Tj';
      $commands[] = 'ET';

      $y -= $lineStep;
    }
  }

  if ($truncated) {
    if ($y < $minBodyY) {
      $y = $minBodyY;
    }
    $commands[] = 'BT';
    $commands[] = '/F2 10 Tf';
    $commands[] = '1 0 0 1 52 ' . (int) $y . ' Tm';
    $commands[] = '(' . receipt_pdf_escape_text('--- Continued fields truncated for compatibility ---') . ') Tj';
    $commands[] = 'ET';
  }

  $commands[] = '0.75 w';
  $commands[] = sprintf('%.2f %.2f %.2f %.2f re S', (float) $left, (float) $summaryBottom, (float) ($right - $left), (float) $summaryHeight);
  $commands[] = sprintf('%.2f %.2f m %.2f %.2f l S', (float) $left, (float) ($summaryBottom + 40), (float) $right, (float) ($summaryBottom + 40));

  $commands[] = 'BT';
  $commands[] = '/F2 11 Tf';
  $commands[] = '1 0 0 1 52 112 Tm';
  $commands[] = '(' . receipt_pdf_escape_text('TOTAL AMOUNT') . ') Tj';
  $commands[] = 'ET';

  $commands[] = 'BT';
  $commands[] = '/F2 20 Tf';
  $commands[] = '1 0 0 1 52 78 Tm';
  $commands[] = '(' . receipt_pdf_escape_text($totalAmount) . ') Tj';
  $commands[] = 'ET';

  $commands[] = 'BT';
  $commands[] = '/F1 9 Tf';
  $commands[] = '1 0 0 1 330 62 Tm';
  $commands[] = '(' . receipt_pdf_escape_text('Generated by library_system_online') . ') Tj';
  $commands[] = 'ET';

  $commands[] = 'Q';

  $text = implode("\n", $commands);

  $objects = [];
  $objects[1] = "<< /Type /Catalog /Pages 2 0 R >>";
  $objects[2] = "<< /Type /Pages /Kids [3 0 R] /Count 1 >>";
  $objects[3] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 " . $pageHeight . "] /Resources << /ProcSet [/PDF /Text] /Font << /F1 4 0 R /F2 5 0 R >> >> /Contents 6 0 R >>";
  $objects[4] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>";
  $objects[5] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold >>";
  $objects[6] = "<< /Length " . strlen($text) . " >>\nstream\n" . $text . "\nendstream";
  $objects[7] = "<< /Title (" . $safeTitle . ") /Producer (library_system_online) /Creator (receipt_pdf_render) >>";

  $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
  $offsets = [0];
  $count = count($objects);

  for ($i = 1; $i <= $count; $i++) {
    $offsets[$i] = strlen($pdf);
    $pdf .= $i . " 0 obj\n" . $objects[$i] . "\nendobj\n";
  }

  $xrefOffset = strlen($pdf);
  $pdf .= "xref\n0 " . ($count + 1) . "\n";
  $pdf .= "0000000000 65535 f \n";
  for ($i = 1; $i <= $count; $i++) {
    $pdf .= sprintf('%010d 00000 n ', $offsets[$i]) . "\n";
  }

  $pdf .= "trailer\n<< /Size " . ($count + 1) . " /Root 1 0 R /Info 7 0 R >>\n";
  $pdf .= "startxref\n" . $xrefOffset . "\n%%EOF";

  return $pdf;
}
