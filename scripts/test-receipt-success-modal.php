<?php

$root = dirname(__DIR__);
$modalTemplate = $root . '/includes/receipt-success-modal.php';

function t_assert(bool $condition, string $message): void
{
  if (!$condition) {
    fwrite(STDERR, '[FAIL] ' . $message . PHP_EOL);
    exit(1);
  }
}

$content = file_get_contents($modalTemplate);
t_assert($content !== false, 'Modal template must be readable');

// Visible action button + required data attributes.
t_assert(strpos($content, '>Download PDF Receipt</button>') !== false, 'Modal should include visible Download PDF Receipt button label');
t_assert(strpos($content, 'data-receipt-download-btn') !== false, 'Modal should include data-receipt-download-btn attribute');
t_assert(strpos($content, 'data-pdf-url="<?= htmlspecialchars($receipt_modal_pdf_url, ENT_QUOTES, \'UTF-8\') ?>"') !== false, 'Modal should include data-pdf-url attribute binding');
t_assert(strpos($content, 'data-pdf-filename="<?= htmlspecialchars($receipt_modal_pdf_filename, ENT_QUOTES, \'UTF-8\') ?>"') !== false, 'Modal should include data-pdf-filename attribute binding');

// Download safeguards in script: loading/error/retry + duplicate-tap guard.
t_assert(strpos($content, "if (!downloadButton || isDownloading)") !== false, 'Download handler should guard duplicate taps while active');
t_assert(strpos($content, "isDownloading = true;") !== false, 'Download handler should set loading lock before request');
t_assert(strpos($content, "setDownloadState('loading', 'Preparing PDF receipt download...')") !== false, 'Download handler should expose loading state');
t_assert(strpos($content, "setDownloadState('error', message)") !== false, 'Download handler should expose error state');
t_assert(strpos($content, "data-receipt-download-retry") !== false, 'Modal should include retry button markup');
t_assert(strpos($content, "retryButton.hidden = state !== 'error';") !== false, 'Retry button visibility should be tied to error state');

fwrite(STDOUT, "[OK] receipt success modal static checks passed" . PHP_EOL);
exit(0);
