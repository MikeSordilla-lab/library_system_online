<?php

/**
 * Development-only preview for branded email templates.
 * Renders every current user-facing email variant without sending mail.
 */

$isCli = PHP_SAPI === 'cli';
if ($isCli && empty($_SERVER['HTTP_HOST'])) {
  $_SERVER['HTTP_HOST'] = 'localhost';
  $_SERVER['SCRIPT_NAME'] = '/tests/email_template_preview.php';
  $_SERVER['REQUEST_URI'] = '/tests/email_template_preview.php';
}

if (!defined('BASE_URL')) {
  require_once __DIR__ . '/../config.php';
}

require_once __DIR__ . '/../includes/mailer.php';
$host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? 'localhost'));
$hostOnly = explode(':', $host, 2)[0];
$isLocalHost = in_array($hostOnly, ['localhost', '127.0.0.1', '::1'], true)
  || substr($hostOnly, -5) === '.test'
  || substr($hostOnly, -6) === '.local';
$isDebug = defined('DEBUG_MODE') && DEBUG_MODE;

if (!$isCli && !$isLocalHost && !$isDebug) {
  http_response_code(404);
  exit('Not found.');
}

$sampleVerifyUrl = BASE_URL . 'verify.php?email=' . urlencode('ada.reader@example.test');
$sampleResetUrl = BASE_URL . 'reset-password.php?token=' . str_repeat('a', 64);
$sampleReservationsUrl = BASE_URL . 'borrower/my_books.php';
$sampleCatalogUrl = BASE_URL . 'catalog.php';
$sampleLoginUrl = BASE_URL . 'login.php';

$emails = [
  'Registration verification email' => libralink_email_registration_verification('Ada Reader', '123456', $sampleVerifyUrl),
  'Resent verification code email' => libralink_email_resent_verification('Ada Reader', '654321', $sampleVerifyUrl),
  'Password reset email' => libralink_email_password_reset('Ada Reader', $sampleResetUrl),
  'Reservation approved email' => libralink_email_reservation_approved('Ada Reader', 'The Design of Everyday Things', 'Don Norman', '06-20-2026 03:30 PM', $sampleReservationsUrl),
  'Reservation unavailable email' => libralink_email_reservation_unavailable('Ada Reader', 'Clean Code', $sampleCatalogUrl),
  'Account suspended email' => libralink_email_account_status('Ada Reader', 'suspended', $sampleLoginUrl),
  'Account reinstated email' => libralink_email_account_status('Ada Reader', 'reinstated', $sampleLoginUrl),
];

if ($isCli) {
  foreach ($emails as $label => $email) {
    echo "== {$label} ==\n";
    echo 'Subject: ' . $email['subject'] . "\n";
    echo $email['text'] . "\n\n";
  }
  exit;
}

?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Email Template Preview | LibraLink</title>
  <style>
    body { margin: 0; padding: 24px; background: #f7f4ee; color: #0f0e0c; font-family: Arial, Helvetica, sans-serif; }
    .preview-shell { max-width: 1120px; margin: 0 auto; }
    .preview-header { margin-bottom: 24px; }
    .preview-card { margin: 0 0 28px; padding: 18px; background: #ffffff; border: 1px solid #ede9e0; border-radius: 18px; }
    .preview-card h2 { margin: 0 0 6px; font-size: 20px; }
    .preview-card p { margin: 0 0 14px; color: #6f675e; }
    iframe { display: block; width: 100%; height: 760px; border: 1px solid #ede9e0; border-radius: 14px; background: #ffffff; }
    details { margin-top: 12px; }
    pre { white-space: pre-wrap; word-break: break-word; padding: 14px; background: #f7f4ee; border-radius: 12px; }
  </style>
</head>
<body>
  <main class="preview-shell">
    <header class="preview-header">
      <h1>Email Template Preview</h1>
      <p>Development/test-only rendering of all branded user-facing email variants. No messages are sent.</p>
    </header>

    <?php foreach ($emails as $label => $email): ?>
      <section class="preview-card">
        <h2><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></h2>
        <p><strong>Subject:</strong> <?= htmlspecialchars($email['subject'], ENT_QUOTES, 'UTF-8') ?></p>
        <iframe title="<?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?>" srcdoc="<?= htmlspecialchars($email['html'], ENT_QUOTES, 'UTF-8') ?>"></iframe>
        <details>
          <summary>Plain text version</summary>
          <pre><?= htmlspecialchars($email['text'], ENT_QUOTES, 'UTF-8') ?></pre>
        </details>
      </section>
    <?php endforeach; ?>
  </main>
</body>
</html>
