<?php

/**
 * includes/mailer.php — shared SMTP mail sender for account flows.
 *
 * Tries PHPMailer (SMTP) first. Falls back to PHP mail() for shared
 * hosting environments that block outbound SMTP (e.g. InfinityFree).
 */

if (!defined('BASE_URL')) {
  require_once __DIR__ . '/../config.php';
}

if (!function_exists('smtp_normalize_secure')) {
  function smtp_normalize_secure(string $secure): string
  {
    $value = strtolower(trim($secure));
    return in_array($value, ['tls', 'ssl'], true) ? $value : '';
  }
}

if (!function_exists('smtp_normalize_password')) {
  function smtp_normalize_password(string $host, string $password): string
  {
    $hostLower = strtolower(trim($host));

    // Gmail app passwords are often copied with spaces between groups.
    if (strpos($hostLower, 'gmail.com') !== false || strpos($hostLower, 'googlemail.com') !== false) {
      return str_replace(' ', '', trim($password));
    }

    return trim($password);
  }
}

if (!function_exists('mailer_clean_header_value')) {
  function mailer_clean_header_value(string $value): string
  {
    return trim(str_replace(["\r", "\n"], ' ', $value));
  }
}

if (!function_exists('mailer_format_address')) {
  function mailer_format_address(string $email, string $name = ''): string
  {
    $email = trim($email);
    $name = mailer_clean_header_value($name);

    if ($name === '') {
      return $email;
    }

    return '=?UTF-8?B?' . base64_encode($name) . '?= <' . $email . '>';
  }
}

if (!function_exists('send_php_mail_fallback')) {
  function send_php_mail_fallback(
    string $toEmail,
    string $toName,
    string $subject,
    string $htmlBody,
    string $altBody,
    string &$error = '',
    ?callable $mailFunction = null
  ): bool {
    if (!function_exists('mail') && $mailFunction === null) {
      $error = 'PHP mail() is unavailable on this server.';
      return false;
    }

    $fromEmail = trim(SMTP_FROM_EMAIL);
    if (!filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
      $error = 'Fallback sender email is invalid.';
      return false;
    }

    $to = mailer_format_address($toEmail, $toName);
    $from = mailer_format_address($fromEmail, SMTP_FROM_NAME);
    $subject = mailer_clean_header_value($subject);
    $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
    $body = $htmlBody !== '' ? $htmlBody : nl2br(htmlspecialchars($altBody, ENT_QUOTES, 'UTF-8'));
    $headers = [
      'MIME-Version: 1.0',
      'Content-Type: text/html; charset=UTF-8',
      'From: ' . $from,
      'Reply-To: ' . $fromEmail,
      'X-Mailer: PHP/' . PHP_VERSION,
    ];
    $headersText = implode("\r\n", $headers);

    $mailFunction = $mailFunction ?? static function (
      string $recipient,
      string $mailSubject,
      string $mailBody,
      string $mailHeaders,
      string $senderEmail
    ): bool {
      if (DIRECTORY_SEPARATOR !== '\\') {
        $sentWithEnvelope = @mail($recipient, $mailSubject, $mailBody, $mailHeaders, '-f' . escapeshellarg($senderEmail));
        if ($sentWithEnvelope) {
          return true;
        }
      }

      return @mail($recipient, $mailSubject, $mailBody, $mailHeaders);
    };

    try {
      if ($mailFunction($to, $encodedSubject, $body, $headersText, $fromEmail)) {
        $error = '';
        return true;
      }
    } catch (\Throwable $e) {
      $error = 'PHP mail() failed: ' . $e->getMessage();
      return false;
    }

    $error = 'PHP mail() returned false.';
    return false;
  }
}

if (!function_exists('send_smtp_mail')) {
  function send_smtp_mail(
    string $toEmail,
    string $toName,
    string $subject,
    string $htmlBody,
    string $altBody,
    string &$error = ''
  ): bool {
    $error = '';

    if (!filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
      $error = 'Invalid recipient email address.';
      return false;
    }

    if (trim(SMTP_HOST) === '' || trim(SMTP_USER) === '' || trim(SMTP_FROM_EMAIL) === '' || (int) SMTP_PORT <= 0) {
      $smtp_error = 'SMTP configuration is incomplete.';
      $fallback_error = '';
      if (send_php_mail_fallback($toEmail, $toName, $subject, $htmlBody, $altBody, $fallback_error)) {
        return true;
      }

      $error = $smtp_error . ' Fallback failed: ' . $fallback_error;
      return false;
    }

    require_once __DIR__ . '/phpmailer/src/Exception.php';
    require_once __DIR__ . '/phpmailer/src/PHPMailer.php';
    require_once __DIR__ . '/phpmailer/src/SMTP.php';

    try {
      $mail = new \PHPMailer\PHPMailer\PHPMailer(true);

      $mail->isSMTP();
      $mail->Host = SMTP_HOST;
      $mail->SMTPAuth = true;
      $mail->Username = SMTP_USER;
      $mail->Password = smtp_normalize_password(SMTP_HOST, SMTP_PASS);

      $secure = smtp_normalize_secure(SMTP_SECURE);
      if ($secure !== '') {
        $mail->SMTPSecure = $secure;
      } else {
        $mail->SMTPAutoTLS = false;
      }

      $mail->Port = (int) SMTP_PORT;
      $mail->Timeout = 25;
      $mail->CharSet = 'UTF-8';

      $mail->setFrom(SMTP_FROM_EMAIL, SMTP_FROM_NAME);
      $mail->addAddress($toEmail, $toName !== '' ? $toName : $toEmail);

      $mail->isHTML(true);
      $mail->Subject = $subject;
      $mail->Body = $htmlBody;
      $mail->AltBody = $altBody;

      $mail->send();
      return true;
    } catch (\PHPMailer\PHPMailer\Exception $e) {
      $smtp_error = $e->getMessage();
    } catch (\Throwable $e) {
      $smtp_error = $e->getMessage();
    }

    $fallback_error = '';
    if (send_php_mail_fallback($toEmail, $toName, $subject, $htmlBody, $altBody, $fallback_error)) {
      return true;
    }

    $error = 'SMTP failed: ' . $smtp_error . ' Fallback failed: ' . $fallback_error;
    return false;
  }
}
