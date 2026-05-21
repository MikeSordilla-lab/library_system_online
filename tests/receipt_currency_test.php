<?php

require_once __DIR__ . '/../includes/receipts.php';

function receipt_currency_assert(bool $condition, string $message): void
{
  if (!$condition) {
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
  }
}

receipt_currency_assert(receipt_display_currency('USD') === 'PHP', 'Legacy USD receipts should display as PHP.');
receipt_currency_assert(receipt_display_currency('') === 'PHP', 'Blank receipt currency should display as PHP.');
receipt_currency_assert(receipt_format_money(12.5, 'USD') === '12.50 PHP', 'Legacy USD money values should format as PHP.');
receipt_currency_assert(receipt_format_money(0, null) === '0.00 PHP', 'Null currency money values should format as PHP.');

echo "Receipt currency tests passed.\n";
