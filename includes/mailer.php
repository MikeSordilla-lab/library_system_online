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

if (!function_exists('send_smtp_mail')) {
  function mail_capture_enabled(): bool
  {
    return defined('DEBUG_MODE') && DEBUG_MODE
      && defined('MAIL_CAPTURE_ENABLED') && MAIL_CAPTURE_ENABLED
      && defined('MAIL_CAPTURE_DIR') && MAIL_CAPTURE_DIR !== '';
  }

  function mail_capture_write(string $toEmail, string $subject, string $htmlBody, string $altBody): void
  {
    if (!mail_capture_enabled()) {
      return;
    }

    $dir = rtrim((string) MAIL_CAPTURE_DIR, "\\/");
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
      error_log('[mailer.php] Unable to create mail capture directory: ' . $dir);
      return;
    }

    preg_match_all('/\b\d{6}\b/', $htmlBody . "\n" . $altBody, $codeMatches);
    preg_match_all('/https?:\/\/[^\s"<]+/', $htmlBody . "\n" . $altBody, $linkMatches);

    $payload = [
      'captured_at' => date('c'),
      'to' => $toEmail,
      'subject' => $subject,
      'codes' => array_values(array_unique($codeMatches[0] ?? [])),
      'links' => array_values(array_unique($linkMatches[0] ?? [])),
      'text' => $altBody,
      'html' => $htmlBody,
    ];

    $safeEmail = preg_replace('/[^A-Za-z0-9._-]+/', '_', strtolower($toEmail));
    $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
      return;
    }

    file_put_contents($dir . '/' . $safeEmail . '.json', $json);
    file_put_contents($dir . '/latest.json', $json);
  }

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

    mail_capture_write($toEmail, $subject, $htmlBody, $altBody);

    if (defined('MAIL_CAPTURE_ONLY') && MAIL_CAPTURE_ONLY && mail_capture_enabled()) {
      return true;
    }

    if (trim(SMTP_HOST) === '' || trim(SMTP_USER) === '' || trim(SMTP_FROM_EMAIL) === '' || (int) SMTP_PORT <= 0) {
      $error = 'SMTP configuration is incomplete.';
      if (mail_capture_enabled()) {
        return true;
      }
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
      $error = $e->getMessage();
      if (mail_capture_enabled()) {
        return true;
      }
      return false;
    } catch (\Throwable $e) {
      $error = $e->getMessage();
      if (mail_capture_enabled()) {
        return true;
      }
      return false;
    }
  }
}
