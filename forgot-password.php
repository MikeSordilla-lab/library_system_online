<?php

/**
 * forgot-password.php — Password Reset Request Handler
 *
 * GET : Display password reset request form
 * POST: Send password reset email with secure token
 */

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/auth-helpers.php';
require_once __DIR__ . '/includes/mailer.php';
require_once __DIR__ . '/includes/staff_approval.php';
require_once __DIR__ . '/src/utils/rate_limit.php';

if (session_status() === PHP_SESSION_NONE) {
  session_start();
}

if (!rate_limit('forgot_password', 5, 900)) {
  http_response_code(429);
  exit('Too many password reset attempts. Please try again later.');
}

// Redirect logged-in users to their dashboard
redirect_authenticated_user();

$error   = '';
$success = '';
$verify_email = '';
$alert_context = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_verify();
  $email = trim($_POST['email'] ?? '');

  if ($email === '') {
    $error = 'Please enter your email address.';
  } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $error = 'Please enter a valid email address.';
  } else {
    try {
      $pdo = get_db();
      $staffApprovalSelect = staff_column_exists($pdo, 'staff_approval_status')
        ? 'staff_approval_status'
        : "'" . STAFF_APPROVAL_NONE . "' AS staff_approval_status";

      // Check if user exists
      $stmt = $pdo->prepare(
        'SELECT id, email, full_name, role, is_verified, ' . $staffApprovalSelect . '
           FROM Users
          WHERE email = ?
          LIMIT 1'
      );
      $stmt->execute([$email]);
      $user = $stmt->fetch();

      if ($user) {
        if ((int) $user['is_verified'] !== 1) {
          $alert_context = staff_login_block_context($user);
          if ($alert_context === 'unverified') {
            $verify_email = (string) $user['email'];
            $error = 'Please verify your account before requesting a password reset.';
          } else {
            $error = staff_login_block_message($user);
          }
          log_event($pdo, 'PASSWORD_RESET_BLOCKED_UNVERIFIED', (int) $user['id'], 'Users', (int) $user['id'], 'FAILURE', null, $email);
        } else {
          // Generate secure reset token
          $reset_token = bin2hex(random_bytes(32));

          // Store reset token in database with expiration time calculated in MySQL (1 hour from NOW)
          // Using DATE_ADD in SQL avoids timezone mismatch issues between PHP and MySQL
          $stmt = $pdo->prepare(
            'UPDATE Users SET password_reset_token = ?, password_reset_expires = DATE_ADD(NOW(), INTERVAL 1 HOUR) WHERE id = ? LIMIT 1'
          );
          $stmt->execute([$reset_token, $user['id']]);

          // Send password reset email via shared mailer/template layer
          $email_sent = false;
          $email_error = '';

          // Reset link
          $reset_link = BASE_URL . 'reset-password.php?token=' . urlencode($reset_token);

          $email_sent = send_password_reset_mail(
            (string) $user['email'],
            (string) $user['full_name'],
            $reset_link,
            $email_error
          );

          if (!$email_sent) {
            // Log SMTP failure
            error_log('[forgot-password.php] PHPMailer failed for user ' . $user['id'] . ': ' . $email_error);
            log_event($pdo, 'PASSWORD_RESET_EMAIL_FAILED', (int) $user['id'], 'Users', (int) $user['id'], 'FAILURE', null, $email);

            // Show generic message to user
            $success = 'If an account exists with this email, you will receive password reset instructions.';
          } else {
            // Log successful password reset request
            log_event($pdo, 'PASSWORD_RESET_REQUESTED', (int) $user['id'], 'Users', (int) $user['id'], 'SUCCESS', null, $email);

            $success = 'If an account exists with this email, you will receive password reset instructions.';
          }
        }
      } else {
        $error = 'No account was found with that email address.';
        log_event($pdo, 'PASSWORD_RESET_EMAIL_NOT_FOUND', null, null, null, 'FAILURE', null, $email);
      }
    } catch (PDOException $e) {
      error_log('[forgot-password.php] DB error: ' . $e->getMessage());
      $error = 'A server error occurred. Please try again.';
    }
  }
}

$pageTitle = 'Forgot Password | LibraLink';
$extraScripts = [
  ['src' => BASE_URL . 'assets/js/forgot-password.js', 'defer' => true],
];

$alertType = '';
$alertTitle = '';
$alertMessage = '';
$alertContext = '';

if ($success !== '') {
  $alertType = 'success';
  $alertTitle = 'Password Reset';
  $alertMessage = $success;
} elseif ($error !== '') {
  $alertType = 'error';
  $alertTitle = 'Error';
  $alertMessage = $error;
  $alertContext = $alert_context;
}

$bodyAlertAttributes = '';
if ($alertType !== '') {
  $safeType = htmlspecialchars($alertType, ENT_QUOTES, 'UTF-8');
  $safeTitle = htmlspecialchars($alertTitle, ENT_QUOTES, 'UTF-8');
  $safeMessage = htmlspecialchars($alertMessage, ENT_QUOTES, 'UTF-8');
  $bodyAlertAttributes = ' data-alert-type="' . $safeType . '" data-alert-title="' . $safeTitle . '" data-alert-message="' . $safeMessage . '"';
  if ($alertContext !== '') {
    $safeContext = htmlspecialchars($alertContext, ENT_QUOTES, 'UTF-8');
    $bodyAlertAttributes .= ' data-alert-context="' . $safeContext . '"';
  }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <?php require_once __DIR__ . '/includes/head.php'; ?>
</head>

<body<?= $bodyAlertAttributes ?>>
  <div class="auth-wrap page-fade-in">

    <!-- Left: hero image panel -->
    <div class="auth-hero" aria-hidden="true">
      <a href="index.php" class="auth-hero__brand auth-hero__brand--linked">
        <img src="<?= BASE_URL ?>assets/images/library_logo_cropped.png" alt="LibraLink Logo" class="auth-hero__logo" onerror="this.onerror=null;this.src='<?= BASE_URL ?>assets/images/logo.svg';">
        <span>LibraLink</span>
      </a>
      <hr class="auth-hero__divider">
      <p class="auth-hero__tagline">Your personal library portal</p>
      <ul class="auth-hero__features">
        <li>Access your loans and due dates</li>
        <li>Reserve books online</li>
        <li>Browse thousands of titles</li>
        <li>Track your reading history</li>
      </ul>
    </div>

    <!-- Right: form panel -->
    <div class="auth-panel">
      <div class="auth-panel__inner">
        <h1 class="auth-panel__heading">Reset your password</h1>
        <p class="auth-panel__sub">Enter your email to receive reset instructions.</p>

        <?php if ($success === ''): ?>
          <?php if ($error !== ''): ?>
            <div class="flash flash-error" role="alert" aria-live="assertive" aria-atomic="true">
              <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
              <?php if ($verify_email !== '' && $alert_context !== 'admin_approval'): ?>
                <a href="<?= htmlspecialchars(BASE_URL . 'verify.php?email=' . urlencode($verify_email), ENT_QUOTES, 'UTF-8') ?>" class="auth-inline-link auth-inline-link--strong">Verify account first</a>.
              <?php endif; ?>
            </div>
          <?php endif; ?>

          <form method="post" action="">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
            <div class="form-group">
              <label class="field-label" for="email">Email Address</label>
              <input class="field-input" type="email" id="email" name="email" required autofocus>
            </div>

            <button type="submit" class="btn-primary btn-block">Send Reset Link</button>
          </form>

          <p class="auth-note auth-note--center">
            Remember your password?
            <a href="<?= htmlspecialchars(BASE_URL . 'login.php', ENT_QUOTES, 'UTF-8') ?>"
              class="auth-inline-link auth-inline-link--strong">Back to sign in</a>
          </p>
        <?php else: ?>
          <p class="auth-note auth-note--center">
            <a href="<?= htmlspecialchars(BASE_URL . 'login.php', ENT_QUOTES, 'UTF-8') ?>"
              class="auth-inline-link auth-inline-link--strong">Back to sign in</a>
          </p>
        <?php endif; ?>
      </div>
    </div>

  </div>

  </body>

</html>
