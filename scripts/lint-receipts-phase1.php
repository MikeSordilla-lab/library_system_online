<?php

$root = dirname(__DIR__);

$files = [
  'includes/receipts.php',
  'includes/receipt_pdf.php',
  'receipt/view.php',
  'receipt/kiosk.php',
  'receipt/index.php',
  'librarian/visitor-pass.php',
  'api/receipts/_bootstrap.php',
  'api/receipts/create.php',
  'api/receipts/get.php',
  'api/receipts/reprint.php',
  'api/receipts/print-meta.php',
  'api/receipts/qr.php',
  'api/receipts/pdf.php',
];

$errors = 0;
foreach ($files as $rel) {
  $path = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
  if (!file_exists($path)) {
    echo '[MISS] ' . $rel . PHP_EOL;
    $errors++;
    continue;
  }

  $cmd = 'php -l ' . escapeshellarg($path) . ' 2>&1';
  $output = [];
  $code = 0;
  exec($cmd, $output, $code);
  if ($code !== 0) {
    echo '[FAIL] ' . $rel . PHP_EOL;
    foreach ($output as $line) {
      echo '       ' . $line . PHP_EOL;
    }
    $errors++;
  } else {
    echo '[OK]   ' . $rel . PHP_EOL;
  }
}

if ($errors > 0) {
  exit(1);
}

echo 'All Phase 1 receipt files passed php -l.' . PHP_EOL;
