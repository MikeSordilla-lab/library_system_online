<?php

/**
 * public/verify.php — Email verification with 6-digit OTP.
 *
 * GET:  Accepts verify.php?email=user@example.com, validates the account state,
 *       then shows OTP form.
 * POST: Verifies OTP for the same email, activates account, clears OTP token.
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/constants.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/mailer.php';

$error = '';
$notice = '';

// Keep the verification identity stable across requests.
// Priority: hidden POST field -> query string fallback.
$email = trim((string) ($_POST['email'] ?? $_GET['email'] ?? ''));

/**
 * Fetch one user by email via prepared statement.
 */
function find_user_for_verification(PDO $pdo, string $email): ?array
{
  $stmt = $pdo->prepare(
    'SELECT id, full_name, email, is_verified, verification_token
       FROM Users
      WHERE email = ?
      LIMIT 1'
  );
  $stmt->execute([$email]);
  $row = $stmt->fetch();
  return $row === false ? null : $row;
}

try {
  $pdo = get_db();
} catch (PDOException $e) {
  error_log('[verify.php] DB connection error: ' . $e->getMessage());
  http_response_code(500);
  exit('Server error. Please try again later.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_verify();

  $intent = trim((string) ($_POST['intent'] ?? 'verify'));

  if ($intent === 'resend') {
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
      $error = 'Enter your email address first, then request a new code.';
    } else {
      try {
        $user = find_user_for_verification($pdo, $email);

        if ($user === null) {
          $error = 'We could not find an account for that email address.';
        } elseif ((int) $user['is_verified'] === 1) {
          header('Location: ' . BASE_URL . 'login.php?message=verified');
          exit;
        } else {
          $token = (string) random_int(100000, 999999);

          $update = $pdo->prepare('UPDATE Users SET verification_token = ? WHERE id = ? LIMIT 1');
          $update->execute([$token, (int) $user['id']]);

          $verify_link = BASE_URL . 'verify.php?email=' . urlencode($email);
          $mail_body = '<!DOCTYPE html><html><body>'
            . '<p>Hello ' . htmlspecialchars((string) $user['full_name'], ENT_QUOTES, 'UTF-8') . ',</p>'
            . '<p>Your new verification code is:</p>'
            . '<p style="font-size:32px;font-weight:bold;letter-spacing:8px;text-align:center;margin:24px 0;">'
            . htmlspecialchars($token, ENT_QUOTES, 'UTF-8')
            . '</p>'
            . '<p style="text-align:center;"><a href="' . htmlspecialchars($verify_link, ENT_QUOTES, 'UTF-8') . '" style="display:inline-block;padding:10px 20px;background-color:#4CAF50;color:white;text-decoration:none;border-radius:4px;">Verify Email</a></p>'
            . '<p style="text-align:center;">Or visit <a href="' . htmlspecialchars($verify_link, ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($verify_link, ENT_QUOTES, 'UTF-8') . '</a> and enter this code.</p>'
            . '<p>If you did not request this, you can ignore this message.</p>'
            . '</body></html>';

          $mail_alt = "Hello {$user['full_name']},\n\nYour new verification code is: {$token}\n\n"
            . 'Visit ' . $verify_link . " and enter this code.\n\n"
            . "If you did not request this, you can ignore this message.";

          $email_error = '';
          $email_sent = send_smtp_mail(
            (string) $user['email'],
            (string) $user['full_name'],
            'Your new Library System verification code',
            $mail_body,
            $mail_alt,
            $email_error
          );

          if ($email_sent) {
            log_event(
              $pdo,
              'EMAIL_VERIFICATION_RESENT',
              (int) $user['id'],
              'Users',
              (int) $user['id'],
              'SUCCESS',
              null,
              $email
            );
            $notice = 'A new verification code has been sent. Please check your inbox and spam folder.';
          } else {
            error_log('[verify.php] Resend email failed for user ' . (int) $user['id'] . ': ' . $email_error);
            log_event(
              $pdo,
              'EMAIL_VERIFICATION_RESEND_FAILED',
              (int) $user['id'],
              'Users',
              (int) $user['id'],
              'FAILURE',
              null,
              $email
            );
            $error = 'We could not send a new code right now. Please try again in a minute.';
          }
        }
      } catch (PDOException $e) {
        error_log('[verify.php] Resend DB error: ' . $e->getMessage());
        $error = 'A server error occurred. Please try again.';
      }
    }
  } else {

    $otp = trim((string) ($_POST['otp'] ?? implode('', array_map(
      fn($i) => trim((string) ($_POST['otp_' . $i] ?? '')),
      range(0, OTP_LENGTH - 1)
    ))));

    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
      $error = 'Please enter the same email address you used during registration.';
    } elseif (!ctype_digit($otp) || strlen($otp) !== OTP_LENGTH) {
      $error = 'Please enter a valid 6-digit verification code.';
    } else {
      try {
        $user = find_user_for_verification($pdo, $email);

        if ($user === null) {
          $error = 'We could not find an account for this verification link.';
        } elseif ((int) $user['is_verified'] === 1) {
          header('Location: ' . BASE_URL . 'login.php?message=verified');
          exit;
        } elseif (!hash_equals((string) ($user['verification_token'] ?? ''), $otp)) {
          $error = 'The code you entered is incorrect. Please try again.';
        } else {
          $update = $pdo->prepare(
            'UPDATE Users
              SET is_verified = 1,
                  verified_at = NOW(),
                  verification_token = NULL
            WHERE id = ? AND is_verified = 0'
          );
          $update->execute([(int) $user['id']]);

          log_event(
            $pdo,
            'EMAIL_VERIFIED',
            (int) $user['id'],
            'Users',
            (int) $user['id'],
            'SUCCESS',
            null,
            $email
          );

          header('Location: ' . BASE_URL . 'login.php?message=verified');
          exit;
        }
      } catch (PDOException $e) {
        error_log('[verify.php] DB error: ' . $e->getMessage());
        $error = 'A server error occurred. Please try again.';
      }
    }
  }
} else {
  if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
    try {
      $user = find_user_for_verification($pdo, $email);
      if ($user === null) {
        $error = 'We could not find an account for this verification link.';
      } elseif ((int) $user['is_verified'] === 1) {
        header('Location: ' . BASE_URL . 'login.php?message=verified');
        exit;
      }
    } catch (PDOException $e) {
      error_log('[verify.php] DB error: ' . $e->getMessage());
      $error = 'A server error occurred. Please try again.';
    }
  }
}

$pageTitle = 'Verify Email | Library System';
$extraScripts = [
  ['src' => BASE_URL . 'assets/js/verify.js', 'defer' => true],
];
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <?php require_once __DIR__ . '/../includes/head.php'; ?>
</head>

<body>
  <div class="auth-wrap page-fade-in">

    <div class="auth-hero" aria-hidden="true">
      <a href="../index.php" class="auth-hero__brand auth-hero__brand--linked">
        <img src="<?= BASE_URL ?>assets/images/logo.svg" alt="Library System Logo" class="auth-hero__logo">
        <span>Library System</span>
      </a>
      <hr class="auth-hero__divider">
      <p class="auth-hero__tagline">
        "Not all readers are leaders, but all leaders are readers."
      </p>
    </div>

    <div class="auth-panel">
      <div class="auth-panel__inner">
        <div class="verify-card">
          <h1 class="auth-panel__heading">Verify your email</h1>
          <p class="auth-panel__sub">Enter the 6-digit code we sent to your inbox.</p>

          <?php if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)): ?>
            <p class="verify-email-line">
              Verifying:
              <strong><?= htmlspecialchars($email, ENT_QUOTES, 'UTF-8') ?></strong>
            </p>
          <?php endif; ?>

          <?php if ($error !== ''): ?>
            <div class="flash flash-error">
              <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
            </div>
          <?php endif; ?>

          <?php if ($notice !== ''): ?>
            <div class="flash flash-success">
              <?= htmlspecialchars($notice, ENT_QUOTES, 'UTF-8') ?>
            </div>
          <?php endif; ?>

          <form method="post" action="" id="verify-form" novalidate>
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">

            <div class="form-group">
              <label class="field-label" for="email">Email Address</label>
              <input
                class="field-input"
                type="email"
                id="email"
                name="email"
                required
                value="<?= htmlspecialchars($email, ENT_QUOTES, 'UTF-8') ?>"
                placeholder="you@example.com">
            </div>

            <div class="form-group">
              <label class="field-label" for="otp_0">Verification Code</label>
              <fieldset id="otp-group" class="otp-group">
                <legend class="sr-only">Enter the 6-digit verification code</legend>
                <input type="text" maxlength="1" inputmode="numeric" pattern="[0-9]" class="otp-digit" name="otp_0" id="otp_0" aria-label="Digit 1 of 6" autocomplete="one-time-code">
                <input type="text" maxlength="1" inputmode="numeric" pattern="[0-9]" class="otp-digit" name="otp_1" id="otp_1" aria-label="Digit 2 of 6">
                <input type="text" maxlength="1" inputmode="numeric" pattern="[0-9]" class="otp-digit" name="otp_2" id="otp_2" aria-label="Digit 3 of 6">
                <input type="text" maxlength="1" inputmode="numeric" pattern="[0-9]" class="otp-digit" name="otp_3" id="otp_3" aria-label="Digit 4 of 6">
                <input type="text" maxlength="1" inputmode="numeric" pattern="[0-9]" class="otp-digit" name="otp_4" id="otp_4" aria-label="Digit 5 of 6">
                <input type="text" maxlength="1" inputmode="numeric" pattern="[0-9]" class="otp-digit" name="otp_5" id="otp_5" aria-label="Digit 6 of 6">
              </fieldset>
              <input type="hidden" name="otp" id="otp-value">
            </div>

            <button type="submit" class="btn-primary btn-block">Verify Account</button>
            <button type="submit" name="intent" value="resend" class="btn-ghost btn-block" formnovalidate>Resend Code</button>
          </form>

          <p class="auth-note auth-note--center">
            Already verified?
            <a href="<?= htmlspecialchars(BASE_URL . 'login.php', ENT_QUOTES, 'UTF-8') ?>" class="auth-inline-link auth-inline-link--strong">Log in</a>
          </p>
        </div>
      </div>
    </div>
  </div>

  <style>
    .verify-card {
      background: #fff;
      border: 1px solid rgba(15, 14, 12, 0.08);
      border-radius: 14px;
      padding: 28px 24px;
      box-shadow: 0 8px 24px rgba(15, 14, 12, 0.08);
    }

    .verify-email-line {
      margin: 8px 0 16px;
      color: #5f5850;
      font-size: 0.95rem;
    }
  </style>
</body>

</html>