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

if (session_status() === PHP_SESSION_NONE) {
  session_start();
}

// Redirect logged-in users to their dashboard
redirect_authenticated_user();

$error   = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_verify();
  $email = trim($_POST['email'] ?? '');

  if ($email === '') {
    $error = 'Please enter your email address.';
  } else {
    try {
      $pdo = get_db();

      // Check if user exists
      $stmt = $pdo->prepare('SELECT id, email, full_name FROM Users WHERE email = ? LIMIT 1');
      $stmt->execute([$email]);
      $user = $stmt->fetch();

      if ($user) {
        // Generate secure reset token
        $reset_token = bin2hex(random_bytes(32));

        // Store reset token in database with expiration time calculated in MySQL (1 hour from NOW)
        // Using DATE_ADD in SQL avoids timezone mismatch issues between PHP and MySQL
        $stmt = $pdo->prepare(
          'UPDATE Users SET password_reset_token = ?, password_reset_expires = DATE_ADD(NOW(), INTERVAL 1 HOUR) WHERE id = ? LIMIT 1'
        );
        $stmt->execute([$reset_token, $user['id']]);

        // Send password reset email via PHPMailer
        $email_sent = false;
        $email_error = '';

        // Reset link
        $reset_link = BASE_URL . 'reset-password.php?token=' . urlencode($reset_token);

        $mail_body = '<!DOCTYPE html><html><body>'
          . '<p>Hello ' . htmlspecialchars($user['full_name'], ENT_QUOTES, 'UTF-8') . ',</p>'
          . '<p>We received a request to reset your password. Click the button below to create a new password:</p>'
          . '<p style="text-align:center;margin:24px 0;"><a href="' . htmlspecialchars($reset_link, ENT_QUOTES, 'UTF-8') . '" style="display:inline-block;padding:12px 24px;background-color:#4CAF50;color:white;text-decoration:none;border-radius:4px;font-weight:bold;">Reset Password</a></p>'
          . '<p>Or copy and paste this link into your browser:</p>'
          . '<p style="word-break:break-all;"><a href="' . htmlspecialchars($reset_link, ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($reset_link, ENT_QUOTES, 'UTF-8') . '</a></p>'
          . '<p style="color:#666;font-size:0.875rem;">This link will expire in 1 hour.</p>'
          . '<p style="color:#666;font-size:0.875rem;">If you did not request a password reset, you can safely ignore this email or contact support if you have concerns.</p>'
          . '</body></html>';

        $mail_alt = "Hello {$user['full_name']},\n\n"
          . "We received a request to reset your password.\n\n"
          . "Visit this link to reset your password:\n"
          . "{$reset_link}\n\n"
          . "This link will expire in 1 hour.\n\n"
          . "If you did not request a password reset, you can safely ignore this email.\n";

        $email_sent = send_smtp_mail(
          $user['email'],
          $user['full_name'],
          'Reset your Library System password',
          $mail_body,
          $mail_alt,
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
      } else {
        // Security: Don't reveal if email exists
        $success = 'If an account exists with this email, you will receive password reset instructions.';
        // Still log attempt (without user_id since account doesn't exist)
        log_event($pdo, 'PASSWORD_RESET_REQUESTED', null, null, null, 'INFO', null, $email);
      }
    } catch (PDOException $e) {
      error_log('[forgot-password.php] DB error: ' . $e->getMessage());
      $error = 'A server error occurred. Please try again.';
    }
  }
}

$pageTitle = 'Forgot Password | Library System';
$extraScripts = [
  ['src' => BASE_URL . 'assets/js/forgot-password.js', 'defer' => true],
];

$alertType = '';
$alertTitle = '';
$alertMessage = '';

if ($success !== '') {
  $alertType = 'success';
  $alertTitle = 'Password Reset';
  $alertMessage = $success;
} elseif ($error !== '') {
  $alertType = 'error';
  $alertTitle = 'Error';
  $alertMessage = $error;
}

$bodyAlertAttributes = '';
if ($alertType !== '') {
  $safeType = htmlspecialchars($alertType, ENT_QUOTES, 'UTF-8');
  $safeTitle = htmlspecialchars($alertTitle, ENT_QUOTES, 'UTF-8');
  $safeMessage = htmlspecialchars($alertMessage, ENT_QUOTES, 'UTF-8');
  $bodyAlertAttributes = ' data-alert-type="' . $safeType . '" data-alert-title="' . $safeTitle . '" data-alert-message="' . $safeMessage . '"';
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
        <img src="<?= BASE_URL ?>assets/images/library_logo_cropped.png" alt="Library System Logo" class="auth-hero__logo" onerror="this.onerror=null;this.src='<?= BASE_URL ?>assets/images/logo.svg';">
        <span>Library System</span>
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