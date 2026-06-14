<?php

/**
 * includes/email_templates.php — shared branded email templates.
 *
 * Builds email-client-friendly HTML and matching plain-text bodies for
 * user-facing account and reservation notifications. This file does not send
 * mail; delivery remains in includes/mailer.php.
 */

if (!function_exists('libralink_email_escape')) {
  function libralink_email_escape($value): string
  {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
  }
}

if (!function_exists('libralink_email_clean_subject')) {
  function libralink_email_clean_subject(string $value): string
  {
    if (function_exists('mailer_clean_header_value')) {
      return mailer_clean_header_value($value);
    }

    return trim(str_replace(["\r", "\n"], ' ', $value));
  }
}

if (!function_exists('libralink_email_base_url')) {
  function libralink_email_base_url(): string
  {
    $baseUrl = defined('BASE_URL') ? (string) BASE_URL : 'http://localhost/';
    return rtrim($baseUrl, '/') . '/';
  }
}

if (!function_exists('libralink_email_absolute_url')) {
  function libralink_email_absolute_url(string $pathOrUrl): string
  {
    $value = trim($pathOrUrl);
    if (preg_match('/^https?:\/\//i', $value)) {
      return $value;
    }

    return libralink_email_base_url() . ltrim($value, '/');
  }
}

if (!function_exists('libralink_email_brand_context')) {
  /**
   * @return array{brand_name: string, base_url: string, logo_url: string}
   */
  function libralink_email_brand_context(?PDO $pdo = null): array
  {
    $brandName = '';

    try {
      if ($pdo === null
        && function_exists('get_db')
        && defined('DB_HOST') && trim((string) DB_HOST) !== ''
        && defined('DB_NAME') && trim((string) DB_NAME) !== ''
        && defined('DB_USER') && trim((string) DB_USER) !== '') {
        $pdo = get_db();
      }

      if ($pdo instanceof PDO) {
        if (!function_exists('get_setting')) {
          $settingsPath = __DIR__ . '/settings.php';
          if (is_file($settingsPath)) {
            require_once $settingsPath;
          }
        }

        if (function_exists('get_setting')) {
          $brandName = trim((string) get_setting($pdo, 'library_name', ''));
        }
      }
    } catch (Throwable $e) {
      $brandName = '';
    }

    if ($brandName === '' && defined('SMTP_FROM_NAME')) {
      $brandName = trim((string) SMTP_FROM_NAME);
    }

    if ($brandName === '') {
      $brandName = 'LibraLink';
    }

    $baseUrl = libralink_email_base_url();

    return [
      'brand_name' => $brandName,
      'base_url' => $baseUrl,
      'logo_url' => $baseUrl . 'assets/images/library_logo_cropped.png',
    ];
  }
}

if (!function_exists('libralink_email_colors')) {
  /**
   * @return array<string, string>
   */
  function libralink_email_colors(string $variant): array
  {
    $palette = [
      'ink' => '#0f0e0c',
      'paper' => '#f7f4ee',
      'cream' => '#ede9e0',
      'accent' => '#c8401a',
      'gold' => '#c9a84c',
      'sage' => '#4a6741',
      'white' => '#fffdf8',
      'muted' => '#6f675e',
    ];

    $variantColors = [
      'success' => $palette['sage'],
      'accent' => $palette['accent'],
      'warning' => $palette['gold'],
      'danger' => $palette['accent'],
      'neutral' => $palette['ink'],
    ];

    $palette['variant'] = $variantColors[$variant] ?? $palette['accent'];
    $palette['button_text'] = '#ffffff';

    return $palette;
  }
}

if (!function_exists('libralink_email_text_block')) {
  /**
   * @param array<int, string> $lines
   */
  function libralink_email_text_block(array $lines): string
  {
    $normalized = [];
    foreach ($lines as $line) {
      $normalized[] = rtrim((string) $line);
    }
    return implode("\n", $normalized);
  }
}

if (!function_exists('libralink_email_render')) {
  /**
   * @param array<string, mixed> $message
   * @return array{subject: string, html: string, text: string, brand_name: string}
   */
  function libralink_email_render(array $message): array
  {
    $brand = $message['brand_context'] ?? libralink_email_brand_context();
    if (!is_array($brand)) {
      $brand = libralink_email_brand_context();
    }

    $brandName = (string) ($brand['brand_name'] ?? 'LibraLink');
    $logoUrl = (string) ($brand['logo_url'] ?? libralink_email_absolute_url('assets/images/library_logo_cropped.png'));
    $variant = (string) ($message['variant'] ?? 'accent');
    $colors = libralink_email_colors($variant);

    $subject = libralink_email_clean_subject((string) ($message['subject'] ?? $brandName));
    $preheader = (string) ($message['preheader'] ?? $subject);
    $eyebrow = (string) ($message['eyebrow'] ?? 'Library notice');
    $title = (string) ($message['title'] ?? $subject);
    $badge = (string) ($message['badge'] ?? 'Notice');
    $greetingName = trim((string) ($message['greeting_name'] ?? ''));
    if ($greetingName === '') {
      $greetingName = 'there';
    }

    $introLines = $message['intro_lines'] ?? [];
    if (!is_array($introLines)) {
      $introLines = [(string) $introLines];
    }

    $details = $message['details'] ?? [];
    if (!is_array($details)) {
      $details = [];
    }

    $steps = $message['steps'] ?? [];
    if (!is_array($steps)) {
      $steps = [];
    }

    $noteLines = $message['note_lines'] ?? [];
    if (!is_array($noteLines)) {
      $noteLines = $noteLines === '' ? [] : [(string) $noteLines];
    }

    $securityNote = trim((string) ($message['security_note'] ?? ''));
    $footerNote = trim((string) ($message['footer_note'] ?? ''));
    if ($footerNote === '') {
      $footerNote = 'This is an automated notice from ' . $brandName . '. Please do not reply to this email.';
    }

    $cta = $message['cta'] ?? null;
    $ctaLabel = '';
    $ctaUrl = '';
    if (is_array($cta)) {
      $ctaLabel = trim((string) ($cta['label'] ?? ''));
      $ctaUrl = trim((string) ($cta['url'] ?? ''));
    }

    $code = $message['code'] ?? null;
    $codeLabel = '';
    $codeValue = '';
    if (is_array($code)) {
      $codeLabel = trim((string) ($code['label'] ?? 'Code'));
      $codeValue = trim((string) ($code['value'] ?? ''));
    }

    $safeBrandName = libralink_email_escape($brandName);
    $safeLogoUrl = libralink_email_escape($logoUrl);
    $safePreheader = libralink_email_escape($preheader);
    $safeEyebrow = libralink_email_escape($eyebrow);
    $safeTitle = libralink_email_escape($title);
    $safeBadge = libralink_email_escape($badge);
    $safeGreeting = libralink_email_escape($greetingName);
    $safeFooter = libralink_email_escape($footerNote);

    $paragraphHtml = '';
    foreach ($introLines as $line) {
      $line = trim((string) $line);
      if ($line === '') {
        continue;
      }
      $paragraphHtml .= '<p style="margin:0 0 16px;font-size:16px;line-height:1.7;color:' . $colors['ink'] . ';">' . libralink_email_escape($line) . '</p>';
    }

    $codeHtml = '';
    if ($codeValue !== '') {
      $safeCodeLabel = libralink_email_escape($codeLabel !== '' ? $codeLabel : 'Code');
      $safeCodeValue = libralink_email_escape($codeValue);
      $codeHtml = '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="border-collapse:separate;border-spacing:0;margin:22px 0 24px;">'
        . '<tr><td align="center" style="padding:24px 18px;background:' . $colors['cream'] . ';border:1px dashed ' . $colors['gold'] . ';border-radius:18px;">'
        . '<div style="font-size:12px;line-height:1.4;font-weight:800;color:' . $colors['accent'] . ';text-transform:uppercase;letter-spacing:0.12em;">' . $safeCodeLabel . '</div>'
        . '<div style="margin-top:8px;font-size:38px;line-height:1;font-weight:900;letter-spacing:9px;color:' . $colors['ink'] . ';font-family:Arial,Helvetica,sans-serif;">' . $safeCodeValue . '</div>'
        . '</td></tr></table>';
    }

    $detailsHtml = '';
    if (!empty($details)) {
      $detailsRows = '';
      foreach ($details as $detail) {
        if (!is_array($detail)) {
          continue;
        }
        $label = trim((string) ($detail['label'] ?? ''));
        $value = trim((string) ($detail['value'] ?? ''));
        if ($label === '' && $value === '') {
          continue;
        }
        $detailsRows .= '<tr>'
          . '<td valign="top" style="padding:12px 0;border-bottom:1px solid #e3ddd2;font-size:13px;line-height:1.5;font-weight:800;color:' . $colors['muted'] . ';text-transform:uppercase;letter-spacing:0.06em;width:34%;">' . libralink_email_escape($label) . '</td>'
          . '<td valign="top" style="padding:12px 0;border-bottom:1px solid #e3ddd2;font-size:16px;line-height:1.5;font-weight:700;color:' . $colors['ink'] . ';">' . libralink_email_escape($value) . '</td>'
          . '</tr>';
      }

      if ($detailsRows !== '') {
        $detailsHtml = '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="border-collapse:collapse;margin:20px 0 24px;background:#ffffff;border:1px solid #e3ddd2;border-radius:18px;overflow:hidden;">'
          . '<tr><td style="padding:12px 22px 10px;">'
          . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="border-collapse:collapse;">' . $detailsRows . '</table>'
          . '</td></tr></table>';
      }
    }

    $stepsHtml = '';
    if (!empty($steps)) {
      $stepsTitle = trim((string) ($message['steps_title'] ?? 'What to do next'));
      $safeStepsTitle = libralink_email_escape($stepsTitle);
      $stepsRows = '';
      $stepNumber = 1;
      foreach ($steps as $step) {
        $step = trim((string) $step);
        if ($step === '') {
          continue;
        }
        $stepsRows .= '<tr>'
          . '<td valign="top" width="34" style="padding:7px 10px 7px 0;">'
          . '<span style="display:inline-block;width:24px;height:24px;border-radius:999px;background:' . $colors['variant'] . ';color:#ffffff;text-align:center;font-size:13px;line-height:24px;font-weight:800;">' . $stepNumber . '</span>'
          . '</td>'
          . '<td valign="top" style="padding:7px 0;font-size:15px;line-height:1.6;color:' . $colors['ink'] . ';">' . libralink_email_escape($step) . '</td>'
          . '</tr>';
        $stepNumber++;
      }

      if ($stepsRows !== '') {
        $stepsHtml = '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="border-collapse:separate;border-spacing:0;margin:18px 0 24px;background:#fff7e6;border-left:4px solid ' . $colors['gold'] . ';border-radius:16px;">'
          . '<tr><td style="padding:18px 20px;">'
          . '<div style="font-size:16px;line-height:1.4;font-weight:900;color:' . $colors['ink'] . ';margin-bottom:6px;">' . $safeStepsTitle . '</div>'
          . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="border-collapse:collapse;">' . $stepsRows . '</table>'
          . '</td></tr></table>';
      }
    }

    $noteHtml = '';
    if (!empty($noteLines)) {
      $noteTitle = trim((string) ($message['note_title'] ?? 'Good to know'));
      $safeNoteTitle = libralink_email_escape($noteTitle);
      $noteParagraphs = '';
      foreach ($noteLines as $line) {
        $line = trim((string) $line);
        if ($line === '') {
          continue;
        }
        $noteParagraphs .= '<p style="margin:0 0 8px;font-size:14px;line-height:1.65;color:' . $colors['muted'] . ';">' . libralink_email_escape($line) . '</p>';
      }

      if ($noteParagraphs !== '') {
        $noteHtml = '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="border-collapse:separate;border-spacing:0;margin:18px 0 24px;background:' . $colors['paper'] . ';border:1px solid #e3ddd2;border-radius:16px;">'
          . '<tr><td style="padding:18px 20px;">'
          . '<div style="font-size:15px;line-height:1.4;font-weight:900;color:' . $colors['ink'] . ';margin-bottom:8px;">' . $safeNoteTitle . '</div>'
          . $noteParagraphs
          . '</td></tr></table>';
      }
    }

    $ctaHtml = '';
    if ($ctaLabel !== '' && $ctaUrl !== '') {
      $safeCtaLabel = libralink_email_escape($ctaLabel);
      $safeCtaUrl = libralink_email_escape($ctaUrl);
      $ctaHtml = '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="border-collapse:collapse;margin:26px 0 0;">'
        . '<tr><td align="center" style="padding:0 0 16px;">'
        . '<a href="' . $safeCtaUrl . '" style="display:inline-block;padding:14px 26px;border-radius:999px;background:' . $colors['variant'] . ';color:' . $colors['button_text'] . ';font-size:15px;line-height:1.2;font-weight:900;text-decoration:none;">' . $safeCtaLabel . '</a>'
        . '</td></tr>'
        . '<tr><td align="center" style="padding:0;font-size:13px;line-height:1.7;color:' . $colors['muted'] . ';">'
        . 'If the button does not work, copy and paste this link into your browser:<br>'
        . '<a href="' . $safeCtaUrl . '" style="color:' . $colors['accent'] . ';text-decoration:underline;word-break:break-all;">' . $safeCtaUrl . '</a>'
        . '</td></tr></table>';
    }

    $securityHtml = '';
    if ($securityNote !== '') {
      $securityHtml = '<p style="margin:24px 0 0;font-size:13px;line-height:1.7;color:' . $colors['muted'] . ';">' . libralink_email_escape($securityNote) . '</p>';
    }

    $html = '<!doctype html>'
      . '<html lang="en">'
      . '<head><meta http-equiv="Content-Type" content="text/html; charset=UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>' . libralink_email_escape($subject) . '</title></head>'
      . '<body style="margin:0;padding:0;background:' . $colors['paper'] . ';color:' . $colors['ink'] . ';font-family:Arial,Helvetica,sans-serif;">'
      . '<div style="display:none;max-height:0;overflow:hidden;opacity:0;color:transparent;line-height:1px;font-size:1px;">' . $safePreheader . '</div>'
      . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="width:100%;border-collapse:collapse;background:' . $colors['paper'] . ';">'
      . '<tr><td align="center" style="padding:28px 12px;">'
      . '<table role="presentation" width="640" cellspacing="0" cellpadding="0" style="width:100%;max-width:640px;border-collapse:separate;border-spacing:0;background:' . $colors['white'] . ';border:1px solid #e3ddd2;border-radius:24px;overflow:hidden;">'
      . '<tr><td style="padding:28px 30px;background:' . $colors['ink'] . ';color:' . $colors['paper'] . ';">'
      . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="border-collapse:collapse;">'
      . '<tr>'
      . '<td valign="middle" style="font-size:0;line-height:0;">'
      . '<img src="' . $safeLogoUrl . '" width="46" height="46" alt="' . $safeBrandName . ' logo" style="display:inline-block;width:46px;height:46px;border:0;outline:none;text-decoration:none;vertical-align:middle;border-radius:12px;background:' . $colors['paper'] . ';">'
      . '<span style="display:inline-block;margin-left:12px;vertical-align:middle;font-size:20px;line-height:1.2;font-weight:900;letter-spacing:0.02em;color:' . $colors['paper'] . ';">' . $safeBrandName . '</span>'
      . '</td>'
      . '<td align="right" valign="middle" style="padding-left:12px;">'
      . '<span style="display:inline-block;padding:7px 13px;border-radius:999px;background:' . $colors['variant'] . ';color:#ffffff;font-size:11px;line-height:1.2;font-weight:900;text-transform:uppercase;letter-spacing:0.08em;">' . $safeBadge . '</span>'
      . '</td>'
      . '</tr></table>'
      . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="border-collapse:collapse;margin-top:28px;">'
      . '<tr><td>'
      . '<div style="font-size:13px;line-height:1.4;font-weight:900;color:' . $colors['gold'] . ';text-transform:uppercase;letter-spacing:0.12em;">' . $safeEyebrow . '</div>'
      . '<h1 style="margin:8px 0 0;font-size:32px;line-height:1.18;font-weight:900;color:#ffffff;">' . $safeTitle . '</h1>'
      . '</td></tr></table>'
      . '</td></tr>'
      . '<tr><td style="padding:30px;background:' . $colors['white'] . ';">'
      . '<p style="margin:0 0 16px;font-size:16px;line-height:1.7;color:' . $colors['ink'] . ';">Hello ' . $safeGreeting . ',</p>'
      . $paragraphHtml
      . $codeHtml
      . $detailsHtml
      . $stepsHtml
      . $noteHtml
      . $ctaHtml
      . $securityHtml
      . '</td></tr>'
      . '<tr><td style="padding:22px 30px;background:' . $colors['cream'] . ';font-size:12px;line-height:1.6;color:' . $colors['muted'] . ';text-align:center;">' . $safeFooter . '</td></tr>'
      . '</table>'
      . '</td></tr>'
      . '</table>'
      . '</body></html>';

    $text = [];
    $text[] = $brandName;
    $text[] = '';
    $text[] = $title;
    $text[] = '';
    $text[] = 'Hello ' . $greetingName . ',';
    $text[] = '';
    foreach ($introLines as $line) {
      $line = trim((string) $line);
      if ($line !== '') {
        $text[] = $line;
      }
    }

    if ($codeValue !== '') {
      $text[] = '';
      $text[] = ($codeLabel !== '' ? $codeLabel : 'Code') . ': ' . $codeValue;
    }

    if (!empty($details)) {
      $text[] = '';
      foreach ($details as $detail) {
        if (!is_array($detail)) {
          continue;
        }
        $label = trim((string) ($detail['label'] ?? ''));
        $value = trim((string) ($detail['value'] ?? ''));
        if ($label !== '' || $value !== '') {
          $text[] = ($label !== '' ? $label . ': ' : '') . $value;
        }
      }
    }

    if (!empty($steps)) {
      $stepsTitle = trim((string) ($message['steps_title'] ?? 'What to do next'));
      $text[] = '';
      $text[] = $stepsTitle . ':';
      $stepNumber = 1;
      foreach ($steps as $step) {
        $step = trim((string) $step);
        if ($step !== '') {
          $text[] = $stepNumber . '. ' . $step;
          $stepNumber++;
        }
      }
    }

    if (!empty($noteLines)) {
      $noteTitle = trim((string) ($message['note_title'] ?? 'Good to know'));
      $text[] = '';
      $text[] = $noteTitle . ':';
      foreach ($noteLines as $line) {
        $line = trim((string) $line);
        if ($line !== '') {
          $text[] = $line;
        }
      }
    }

    if ($ctaLabel !== '' && $ctaUrl !== '') {
      $text[] = '';
      $text[] = $ctaLabel . ': ' . $ctaUrl;
    }

    if ($securityNote !== '') {
      $text[] = '';
      $text[] = $securityNote;
    }

    $text[] = '';
    $text[] = $footerNote;

    return [
      'subject' => $subject,
      'html' => $html,
      'text' => libralink_email_text_block($text),
      'brand_name' => $brandName,
    ];
  }
}

if (!function_exists('libralink_email_registration_verification')) {
  /** @return array{subject: string, html: string, text: string, brand_name: string} */
  function libralink_email_registration_verification(string $toName, string $code, string $verifyUrl): array
  {
    $brand = libralink_email_brand_context();
    $brandName = (string) $brand['brand_name'];

    return libralink_email_render([
      'brand_context' => $brand,
      'subject' => 'Verify your ' . $brandName . ' account',
      'preheader' => 'Your verification code is ' . $code . '.',
      'eyebrow' => 'Email verification',
      'title' => 'Verify your email address',
      'badge' => 'Verify',
      'variant' => 'accent',
      'greeting_name' => $toName,
      'intro_lines' => [
        'Thank you for registering. Enter the verification code below on the verification page to activate your account.',
      ],
      'code' => [
        'label' => 'Verification code',
        'value' => $code,
      ],
      'cta' => [
        'label' => 'Verify email',
        'url' => $verifyUrl,
      ],
      'security_note' => 'If you did not register, you can safely ignore this email.',
    ]);
  }
}

if (!function_exists('libralink_email_resent_verification')) {
  /** @return array{subject: string, html: string, text: string, brand_name: string} */
  function libralink_email_resent_verification(string $toName, string $code, string $verifyUrl): array
  {
    $brand = libralink_email_brand_context();
    $brandName = (string) $brand['brand_name'];

    return libralink_email_render([
      'brand_context' => $brand,
      'subject' => 'Your new ' . $brandName . ' verification code',
      'preheader' => 'Your new verification code is ' . $code . '.',
      'eyebrow' => 'New verification code',
      'title' => 'Use your new verification code',
      'badge' => 'New code',
      'variant' => 'accent',
      'greeting_name' => $toName,
      'intro_lines' => [
        'Here is your new verification code. Enter it on the verification page to activate your account.',
      ],
      'code' => [
        'label' => 'New verification code',
        'value' => $code,
      ],
      'cta' => [
        'label' => 'Verify email',
        'url' => $verifyUrl,
      ],
      'security_note' => 'If you did not request this, you can ignore this message.',
    ]);
  }
}

if (!function_exists('libralink_email_password_reset')) {
  /** @return array{subject: string, html: string, text: string, brand_name: string} */
  function libralink_email_password_reset(string $toName, string $resetUrl): array
  {
    $brand = libralink_email_brand_context();
    $brandName = (string) $brand['brand_name'];

    return libralink_email_render([
      'brand_context' => $brand,
      'subject' => 'Reset your ' . $brandName . ' password',
      'preheader' => 'Use this secure link to reset your password. It expires in 1 hour.',
      'eyebrow' => 'Password reset',
      'title' => 'Reset your password',
      'badge' => 'Secure link',
      'variant' => 'accent',
      'greeting_name' => $toName,
      'intro_lines' => [
        'We received a request to reset your password. Click the button below to create a new password.',
      ],
      'cta' => [
        'label' => 'Reset password',
        'url' => $resetUrl,
      ],
      'note_title' => 'Link expiry',
      'note_lines' => [
        'This password reset link will expire in 1 hour.',
      ],
      'security_note' => 'If you did not request a password reset, you can safely ignore this email or contact the library if you have concerns.',
    ]);
  }
}

if (!function_exists('libralink_email_reservation_approved')) {
  /** @return array{subject: string, html: string, text: string, brand_name: string} */
  function libralink_email_reservation_approved(string $toName, string $bookTitle, string $bookAuthor, string $pickupDeadline, string $reservationsUrl): array
  {
    $displayTitle = $bookTitle !== '' ? $bookTitle : 'your reserved book';
    $displayAuthor = $bookAuthor !== '' ? $bookAuthor : 'Unknown author';
    $displayDeadline = $pickupDeadline !== '' ? $pickupDeadline : 'the listed pickup deadline';

    return libralink_email_render([
      'subject' => 'Reservation approved: ' . $displayTitle,
      'preheader' => 'Your reserved book is approved and ready for pickup before ' . $displayDeadline . '.',
      'eyebrow' => 'Ready for pickup',
      'title' => 'Your reservation is approved',
      'badge' => 'Approved',
      'variant' => 'success',
      'greeting_name' => $toName,
      'intro_lines' => [
        'Good news — your requested book has been approved by the librarian and is now waiting at the check-out desk.',
      ],
      'details' => [
        ['label' => 'Book', 'value' => $displayTitle],
        ['label' => 'Author', 'value' => $displayAuthor],
        ['label' => 'Pickup deadline', 'value' => $displayDeadline],
      ],
      'steps_title' => 'What to do next',
      'steps' => [
        'Visit the library check-out desk before the pickup deadline.',
        'Bring your library ID or account details for faster service.',
        'The book is not checked out until a librarian completes the checkout.',
      ],
      'cta' => [
        'label' => 'View my reservations',
        'url' => $reservationsUrl,
      ],
      'security_note' => 'Your reservation may expire after the pickup deadline.',
    ]);
  }
}

if (!function_exists('libralink_email_reservation_unavailable')) {
  /** @return array{subject: string, html: string, text: string, brand_name: string} */
  function libralink_email_reservation_unavailable(string $toName, string $bookTitle, string $catalogUrl): array
  {
    $displayTitle = $bookTitle !== '' ? $bookTitle : 'your requested book';

    return libralink_email_render([
      'subject' => 'Reservation unavailable: ' . $displayTitle,
      'preheader' => 'Your reservation could not be kept because available copies are currently committed.',
      'eyebrow' => 'Reservation update',
      'title' => 'Reservation currently unavailable',
      'badge' => 'Unavailable',
      'variant' => 'warning',
      'greeting_name' => $toName,
      'intro_lines' => [
        'We could not keep this reservation because available copies are currently committed to earlier approved reservations.',
        'You may reserve the book again later when a copy becomes available.',
      ],
      'details' => [
        ['label' => 'Book', 'value' => $displayTitle],
      ],
      'cta' => [
        'label' => 'Browse catalog',
        'url' => $catalogUrl,
      ],
    ]);
  }
}

if (!function_exists('libralink_email_account_status')) {
  /** @return array{subject: string, html: string, text: string, brand_name: string} */
  function libralink_email_account_status(string $toName, string $action, string $loginUrl = ''): array
  {
    $brand = libralink_email_brand_context();
    $brandName = (string) $brand['brand_name'];
    $normalizedAction = $action === 'suspended' ? 'suspended' : 'reinstated';

    if ($normalizedAction === 'suspended') {
      return libralink_email_render([
        'brand_context' => $brand,
        'subject' => 'Your ' . $brandName . ' account has been suspended',
        'preheader' => 'Your library account has been suspended by an administrator.',
        'eyebrow' => 'Account notice',
        'title' => 'Your account has been suspended',
        'badge' => 'Suspended',
        'variant' => 'danger',
        'greeting_name' => $toName,
        'intro_lines' => [
          'Your account has been suspended by an administrator.',
          'You will not be able to log in until your account is reinstated.',
        ],
        'note_title' => 'Need help?',
        'note_lines' => [
          'Please contact the library if you believe this is an error.',
        ],
      ]);
    }

    $message = [
      'brand_context' => $brand,
      'subject' => 'Your ' . $brandName . ' account has been reinstated',
      'preheader' => 'Your library account has been reinstated. You may now sign in normally.',
      'eyebrow' => 'Account restored',
      'title' => 'Your account has been reinstated',
      'badge' => 'Reinstated',
      'variant' => 'success',
      'greeting_name' => $toName,
      'intro_lines' => [
        'Your account has been reinstated. You may now log in normally.',
      ],
    ];

    if ($loginUrl !== '') {
      $message['cta'] = [
        'label' => 'Sign in',
        'url' => $loginUrl,
      ];
    }

    return libralink_email_render($message);
  }
}
