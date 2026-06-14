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

require_once __DIR__ . '/email_templates.php';

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

if (!function_exists('smtp_config_value')) {
  function smtp_config_value(string $name, $default = '')
  {
    return defined($name) ? constant($name) : $default;
  }
}

if (!function_exists('smtp_candidate_ports')) {
  function smtp_candidate_ports(int $primaryPort, string $altPorts): array
  {
    $ports = [];
    foreach (array_merge([$primaryPort], preg_split('/\s*,\s*/', trim($altPorts)) ?: []) as $port) {
      $port = (int) $port;
      if ($port > 0 && $port <= 65535 && !in_array($port, $ports, true)) {
        $ports[] = $port;
      }
    }
    return $ports;
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

if (!function_exists('mailer_logo_absolute_url')) {
  function mailer_logo_absolute_url(): string
  {
    return rtrim(BASE_URL, '/') . '/assets/images/library_logo_cropped.png';
  }
}

if (!function_exists('mailer_logo_file_path')) {
  function mailer_logo_file_path(): string
  {
    $path = realpath(__DIR__ . '/../assets/images/library_logo_cropped.png');
    return $path !== false ? $path : '';
  }
}

if (!function_exists('mailer_prepare_inline_images')) {
  /**
   * Rewrite supported remote image URLs to CID references for PHPMailer SMTP.
   *
   * PHP mail() fallback keeps the original remote URLs because it does not build
   * multipart/related messages with inline attachments.
   *
   * @param array<int, array{path: string, cid: string, name: string, type: string}> $embeddedImages
   */
  function mailer_prepare_inline_images(string $htmlBody, array &$embeddedImages): string
  {
    $embeddedImages = [];

    $logoPath = mailer_logo_file_path();
    if ($logoPath === '' || !is_file($logoPath)) {
      return $htmlBody;
    }

    $logoUrl = mailer_logo_absolute_url();
    $escapedLogoUrl = htmlspecialchars($logoUrl, ENT_QUOTES, 'UTF-8');
    $cid = 'libralink-logo';
    $preparedHtml = $htmlBody;
    $matched = false;

    foreach (array_unique([$logoUrl, $escapedLogoUrl]) as $needle) {
      if ($needle !== '' && strpos($preparedHtml, $needle) !== false) {
        $preparedHtml = str_replace($needle, 'cid:' . $cid, $preparedHtml);
        $matched = true;
      }
    }

    if (!$matched) {
      return $htmlBody;
    }

    $embeddedImages[] = [
      'path' => $logoPath,
      'cid' => $cid,
      'name' => 'library_logo_cropped.png',
      'type' => 'image/png',
    ];

    return $preparedHtml;
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

if (!function_exists('send_smtp_mail_once')) {
  function send_smtp_mail_once(
    string $toEmail,
    string $toName,
    string $subject,
    string $htmlBody,
    string $altBody,
    int $port,
    string &$error = ''
  ): bool {
    $subject = mailer_clean_header_value($subject);
    $originalHtmlBody = $htmlBody;
    $embeddedImages = [];
    $preparedHtmlBody = mailer_prepare_inline_images($htmlBody, $embeddedImages);

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

      $mail->Port = $port;
      $mail->Timeout = max(5, (int) smtp_config_value('SMTP_TIMEOUT', 25));
      $mail->CharSet = 'UTF-8';

      $mail->setFrom(SMTP_FROM_EMAIL, SMTP_FROM_NAME);
      $mail->addAddress($toEmail, $toName !== '' ? $toName : $toEmail);

      $inlineImagesAttached = true;
      foreach ($embeddedImages as $image) {
        try {
          if (!$mail->addEmbeddedImage(
            (string) $image['path'],
            (string) $image['cid'],
            (string) $image['name'],
            'base64',
            (string) $image['type']
          )) {
            $inlineImagesAttached = false;
            break;
          }
        } catch (\Throwable $e) {
          $inlineImagesAttached = false;
          break;
        }
      }

      $mail->isHTML(true);
      $mail->Subject = $subject;
      $mail->Body = $inlineImagesAttached ? $preparedHtmlBody : $originalHtmlBody;
      $mail->AltBody = $altBody;

      $mail->send();
      $error = '';
      return true;
    } catch (\PHPMailer\PHPMailer\Exception $e) {
      $error = $e->getMessage();
      return false;
    } catch (\Throwable $e) {
      $error = $e->getMessage();
      return false;
    }
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
    $subject = mailer_clean_header_value($subject);

    if (!filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
      $error = 'Invalid recipient email address.';
      return false;
    }

    if (trim(SMTP_HOST) === '' || trim(SMTP_USER) === '' || trim(SMTP_PASS) === '' || trim(SMTP_FROM_EMAIL) === '' || (int) SMTP_PORT <= 0) {
      $smtp_error = 'SMTP configuration is incomplete.';
      $fallback_error = '';
      if (send_php_mail_fallback($toEmail, $toName, $subject, $htmlBody, $altBody, $fallback_error)) {
        return true;
      }

      $error = $smtp_error . ' Fallback failed: ' . $fallback_error;
      return false;
    }

    $smtp_errors = [];
    foreach (smtp_candidate_ports((int) SMTP_PORT, (string) smtp_config_value('SMTP_ALT_PORTS', '')) as $port) {
      $smtp_error = '';
      if (send_smtp_mail_once($toEmail, $toName, $subject, $htmlBody, $altBody, $port, $smtp_error)) {
        return true;
      }
      $smtp_errors[] = 'port ' . $port . ': ' . $smtp_error;
    }

    $fallback_error = '';
    if (send_php_mail_fallback($toEmail, $toName, $subject, $htmlBody, $altBody, $fallback_error)) {
      return true;
    }

    $error = 'SMTP failed: ' . implode(' | ', $smtp_errors) . ' Fallback failed: ' . $fallback_error;
    return false;
  }
}

if (!function_exists('mailer_format_datetime')) {
  function mailer_format_datetime(string $value): string
  {
    $timestamp = strtotime($value);
    return $timestamp ? date('m-d-Y h:i A', $timestamp) : 'the listed pickup deadline';
  }
}

if (!function_exists('send_registration_verification_mail')) {
  function send_registration_verification_mail(
    string $toEmail,
    string $toName,
    string $verificationCode,
    string $verifyUrl,
    string &$error = ''
  ): bool {
    $message = libralink_email_registration_verification($toName, $verificationCode, $verifyUrl);
    return send_smtp_mail($toEmail, $toName, $message['subject'], $message['html'], $message['text'], $error);
  }
}

if (!function_exists('send_resend_verification_mail')) {
  function send_resend_verification_mail(
    string $toEmail,
    string $toName,
    string $verificationCode,
    string $verifyUrl,
    string &$error = ''
  ): bool {
    $message = libralink_email_resent_verification($toName, $verificationCode, $verifyUrl);
    return send_smtp_mail($toEmail, $toName, $message['subject'], $message['html'], $message['text'], $error);
  }
}

if (!function_exists('send_password_reset_mail')) {
  function send_password_reset_mail(
    string $toEmail,
    string $toName,
    string $resetUrl,
    string &$error = ''
  ): bool {
    $message = libralink_email_password_reset($toName, $resetUrl);
    return send_smtp_mail($toEmail, $toName, $message['subject'], $message['html'], $message['text'], $error);
  }
}

if (!function_exists('send_reservation_approved_mail')) {
  function send_reservation_approved_mail(
    string $toEmail,
    string $toName,
    string $bookTitle,
    string $bookAuthor,
    string $expiresAt,
    string &$error = ''
  ): bool {
    $displayDeadline = mailer_format_datetime($expiresAt);
    $detailsUrl = rtrim(BASE_URL, '/') . '/borrower/my_books.php';
    $message = libralink_email_reservation_approved($toName, $bookTitle, $bookAuthor, $displayDeadline, $detailsUrl);

    return send_smtp_mail($toEmail, $toName, $message['subject'], $message['html'], $message['text'], $error);
  }
}

if (!function_exists('send_reservation_unavailable_mail')) {
  function send_reservation_unavailable_mail(
    string $toEmail,
    string $toName,
    string $bookTitle,
    string &$error = ''
  ): bool {
    $catalogUrl = rtrim(BASE_URL, '/') . '/catalog.php';
    $message = libralink_email_reservation_unavailable($toName, $bookTitle, $catalogUrl);

    return send_smtp_mail($toEmail, $toName, $message['subject'], $message['html'], $message['text'], $error);
  }
}

if (!function_exists('send_account_status_email')) {
  function send_account_status_email(string $to_email, string $to_name, string $action): bool
  {
    $error = '';
    $loginUrl = rtrim(BASE_URL, '/') . '/login.php';
    $message = libralink_email_account_status($to_name, $action, $loginUrl);
    $sent = send_smtp_mail($to_email, $to_name, $message['subject'], $message['html'], $message['text'], $error);

    if (!$sent) {
      error_log('[mailer.php] send_account_status_email() failed for ' . $to_email . ': ' . $error);
    }

    return $sent;
  }
}
