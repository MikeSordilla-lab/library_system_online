<?php

define('BASE_URL', 'https://example.test/');
define('SMTP_HOST', '');
define('SMTP_PORT', 0);
define('SMTP_SECURE', 'tls');
define('SMTP_USER', '');
define('SMTP_PASS', '');
define('SMTP_FROM_EMAIL', 'library@example.test');
define('SMTP_FROM_NAME', 'LibraLink');

require_once __DIR__ . '/../includes/mailer.php';

function assert_true(bool $condition, string $message): void
{
  if (!$condition) {
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
  }
}

$captured = [];
$error = 'not reset';
$sent = send_php_mail_fallback(
  'borrower@example.test',
  "Borrower\nInjected",
  "Verify\r\nBcc: attacker@example.test",
  '<p>Your code is 123456.</p>',
  'Your code is 123456.',
  $error,
  static function (
    string $recipient,
    string $subject,
    string $body,
    string $headers,
    string $senderEmail
  ) use (&$captured): bool {
    $captured = compact('recipient', 'subject', 'body', 'headers', 'senderEmail');
    return true;
  }
);

assert_true($sent === true, 'Fallback mail sender should report success when mail callable succeeds.');
assert_true($error === '', 'Fallback success should clear the error.');
assert_true($captured['senderEmail'] === 'library@example.test', 'Fallback should use configured sender.');
assert_true(strpos($captured['headers'], 'From: =?UTF-8?B?') !== false, 'Fallback should encode sender display name.');
assert_true(strpos($captured['subject'], "\r") === false && strpos($captured['subject'], "\n") === false, 'Fallback subject should not contain raw newlines.');
assert_true(strpos($captured['recipient'], "\n") === false, 'Fallback recipient should not contain raw newlines.');

echo "mailer fallback tests passed\n";
