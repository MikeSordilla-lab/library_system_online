<?php

/**
 * Shared verification-code email builder/sender.
 */

if (!defined('BASE_URL')) {
  require_once __DIR__ . '/../config.php';
}

require_once __DIR__ . '/mailer.php';

if (!function_exists('send_verification_code_email')) {
  function send_verification_code_email(
    string $email,
    string $fullName,
    string $token,
    string &$error = '',
    string $subject = 'Verify your LibraLink account'
  ): bool {
    $verify_link = BASE_URL . 'verify.php?email=' . urlencode($email);

    $mail_body = '<!DOCTYPE html><html><body>'
      . '<p>Hello ' . htmlspecialchars($fullName, ENT_QUOTES, 'UTF-8') . ',</p>'
      . '<p>Enter the verification code below on the verification page to activate your account:</p>'
      . '<p style="font-size:32px;font-weight:bold;letter-spacing:8px;text-align:center;margin:24px 0;">'
      . htmlspecialchars($token, ENT_QUOTES, 'UTF-8')
      . '</p>'
      . '<p style="text-align:center;"><a href="' . htmlspecialchars($verify_link, ENT_QUOTES, 'UTF-8') . '" style="display:inline-block;padding:10px 20px;background-color:#4CAF50;color:white;text-decoration:none;border-radius:4px;">Verify Email</a></p>'
      . '<p style="text-align:center;">Or visit <a href="' . htmlspecialchars($verify_link, ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($verify_link, ENT_QUOTES, 'UTF-8') . '</a> and enter this code.</p>'
      . '<p>If you did not request this, you can safely ignore this email.</p>'
      . '</body></html>';

    $mail_alt = "Hello {$fullName},\n\nYour verification code is: {$token}\n\n"
      . 'Visit ' . $verify_link . " and enter this code.\n\n"
      . "If you did not request this, you can safely ignore this email.";

    return send_smtp_mail($email, $fullName, $subject, $mail_body, $mail_alt, $error);
  }
}
