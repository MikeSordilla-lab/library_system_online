<?php

if (!defined('BASE_URL')) {
  define('BASE_URL', 'https://example.test/');
}
if (!defined('SMTP_HOST')) {
  define('SMTP_HOST', '');
  define('SMTP_PORT', 0);
  define('SMTP_ALT_PORTS', '');
  define('SMTP_SECURE', 'tls');
  define('SMTP_USER', '');
  define('SMTP_PASS', '');
  define('SMTP_FROM_EMAIL', 'library@example.test');
  define('SMTP_FROM_NAME', 'LibraLink');
  define('SMTP_TIMEOUT', 25);
}

require_once __DIR__ . '/../includes/mailer.php';

function email_templates_assert(bool $condition, string $message): void
{
  if (!$condition) {
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
  }
}

function email_templates_contains(string $haystack, string $needle, string $message): void
{
  email_templates_assert(strpos($haystack, $needle) !== false, $message);
}

$verifyUrl = BASE_URL . 'verify.php?email=' . urlencode('ada@example.test');
$resetUrl = BASE_URL . 'reset-password.php?token=' . str_repeat('b', 64);
$reservationsUrl = BASE_URL . 'borrower/my_books.php';
$catalogUrl = BASE_URL . 'catalog.php';
$loginUrl = BASE_URL . 'login.php';

$verification = libralink_email_registration_verification('Ada Reader', '123456', $verifyUrl);
email_templates_assert(stripos($verification['html'], '<!doctype html>') === 0, 'Verification template should render full HTML.');
email_templates_contains($verification['text'], 'Verify your email address', 'Verification template should render plain text.');
email_templates_contains($verification['html'], $verifyUrl, 'Verification CTA URL should appear in HTML.');
email_templates_contains($verification['text'], $verifyUrl, 'Verification CTA URL should appear in plain text.');
email_templates_contains($verification['html'], '123456', 'Verification code should appear in HTML.');
email_templates_contains($verification['text'], '123456', 'Verification code should appear in plain text.');
email_templates_assert(strpos($verification['text'], 'expire') === false, 'Verification email should not claim OTP expiry.');
email_templates_assert(function_exists('mailer_prepare_inline_images'), 'Mailer should expose inline-image preparation for SMTP delivery.');
$inlineImages = [];
$preparedVerificationHtml = mailer_prepare_inline_images($verification['html'], $inlineImages);
email_templates_contains($preparedVerificationHtml, 'cid:libralink-logo', 'SMTP-prepared email HTML should use an embedded logo CID.');
email_templates_assert(count($inlineImages) === 1, 'SMTP-prepared email should register one embedded logo image.');
email_templates_assert(is_file((string) ($inlineImages[0]['path'] ?? '')), 'Embedded logo image file should exist.');
email_templates_assert(($inlineImages[0]['cid'] ?? '') === 'libralink-logo', 'Embedded logo should use the expected CID.');

$escapedVerification = libralink_email_registration_verification('Ada <script>alert(1)</script>', '234567', $verifyUrl);
email_templates_contains($escapedVerification['html'], 'Ada &lt;script&gt;alert(1)&lt;/script&gt;', 'User names should be escaped in HTML.');
email_templates_assert(strpos($escapedVerification['html'], '<script>') === false, 'User names should not render raw script tags.');

$resend = libralink_email_resent_verification('Ada Reader', '654321', $verifyUrl);
email_templates_contains($resend['subject'], 'new', 'Resent verification subject should indicate a new code.');
email_templates_contains($resend['html'], '654321', 'Resent verification code should appear.');

$passwordReset = libralink_email_password_reset('Ada Reader', $resetUrl);
email_templates_contains($passwordReset['html'], $resetUrl, 'Password reset CTA URL should appear in HTML.');
email_templates_contains($passwordReset['text'], 'expire in 1 hour', 'Password reset plain text should mention 1-hour expiry.');

$bookTitle = "Clean <Code> & Testing\r\nBcc: attacker@example.test";
$reservationApproved = libralink_email_reservation_approved('Borrower', $bookTitle, 'Author <img src=x>', '06-20-2026 03:30 PM', $reservationsUrl);
email_templates_assert(strpos($reservationApproved['subject'], "\r") === false && strpos($reservationApproved['subject'], "\n") === false, 'Reservation subject should be header-cleaned.');
email_templates_contains($reservationApproved['html'], 'Clean &lt;Code&gt; &amp; Testing', 'Book title should be escaped in approved email HTML.');
email_templates_assert(strpos($reservationApproved['html'], '<img src=x>') === false, 'Book author should not render raw HTML.');
email_templates_contains($reservationApproved['html'], $reservationsUrl, 'Reservation approved CTA URL should appear.');

$reservationUnavailable = libralink_email_reservation_unavailable('Borrower', 'Unavailable <Book>', $catalogUrl);
email_templates_contains($reservationUnavailable['html'], 'Unavailable &lt;Book&gt;', 'Book title should be escaped in unavailable email HTML.');
email_templates_contains($reservationUnavailable['html'], $catalogUrl, 'Reservation unavailable catalog CTA should appear.');

$suspended = libralink_email_account_status('Borrower', 'suspended', $loginUrl);
email_templates_contains($suspended['text'], 'contact the library', 'Suspended email should tell user to contact the library.');
email_templates_assert(strpos($suspended['html'], $loginUrl) === false, 'Suspended email should not include a sign-in CTA.');

$reinstated = libralink_email_account_status('Borrower', 'reinstated', $loginUrl);
email_templates_contains($reinstated['html'], $loginUrl, 'Reinstated email should include sign-in CTA.');
email_templates_contains($reinstated['text'], 'Sign in', 'Reinstated plain text should include sign-in CTA label.');

$dirtySubject = "Verify\r\nBcc: attacker@example.test";
email_templates_assert(mailer_clean_header_value($dirtySubject) === 'Verify  Bcc: attacker@example.test', 'Header cleaner should remove CRLF from subjects.');

foreach ([
  'send_smtp_mail',
  'send_smtp_mail_once',
  'send_php_mail_fallback',
  'mailer_prepare_inline_images',
  'send_registration_verification_mail',
  'send_resend_verification_mail',
  'send_password_reset_mail',
  'send_reservation_approved_mail',
  'send_reservation_unavailable_mail',
  'send_account_status_email',
] as $functionName) {
  email_templates_assert(function_exists($functionName), $functionName . ' should exist.');
}

echo "email template tests passed\n";
