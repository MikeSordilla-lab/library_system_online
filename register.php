<?php

/**
 * register.php — Borrower Self-Registration (US1, FR-001–FR-013)
 *
 * GET  : Display registration form
 * POST : Validate input → insert user → send verification email → redirect/confirm
 */

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/auth-helpers.php';
require_once __DIR__ . '/includes/mailer.php';

// Start session only to preserve flash messages across redirect (not for auth here)
if (session_status() === PHP_SESSION_NONE) {
  session_start();
}

// Redirect already-authenticated users away from this page
redirect_authenticated_user();

$errors      = [];
$old         = [];   // repopulate form fields on validation failure
$show_success = false;
$show_check_email = false;
$check_email_address = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_verify();

  // --- 1. Collect & sanitise raw input ---
  $full_name       = trim($_POST['full_name']       ?? '');
  $email           = trim($_POST['email']           ?? '');
  $password        = $_POST['password']             ?? '';
  $confirm_password = $_POST['confirm_password']    ?? '';

  $old = [
    'full_name' => $full_name,
    'email'     => $email,
  ];

  // --- 2. Server-side validation (FR-002) ---
  if ($full_name === '') {
    $errors[] = 'Full name is required.';
  }

  if ($email === '') {
    $errors[] = 'Email address is required.';
  } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $errors[] = 'Please enter a valid email address.';
  }

  if ($password === '') {
    $errors[] = 'Password is required.';
  } elseif (strlen($password) < 8) {
    $errors[] = 'Password must be at least 8 characters.';
  }

  if ($confirm_password === '') {
    $errors[] = 'Please confirm your password.';
  } elseif ($password !== $confirm_password) {
    $errors[] = 'Passwords do not match.';
  }

  if (empty($errors)) {

    try {
      $pdo = get_db();

      // --- 3. Duplicate email check (FR-003) ---
      $stmt = $pdo->prepare('SELECT id FROM Users WHERE email = ? LIMIT 1');
      $stmt->execute([$email]);
      if ($stmt->fetch()) {
        // Security: Use generic message to prevent email enumeration attacks
        $errors[] = 'Unable to complete registration. Please try another email address or contact support.';
      }
    } catch (PDOException $e) {
      error_log('[register.php] Duplicate check failed: ' . $e->getMessage());
      $errors[] = 'A server error occurred. Please try again.';
    }
  }

  if (empty($errors)) {

    try {
      $pdo = get_db();

      // --- 4. Hash password (FR-004) ---
      $password_hash = password_hash($password, PASSWORD_BCRYPT);

      // --- 5. Generate 6-digit numeric OTP (FR-005) ---
      $token = (string) random_int(100000, 999999);   // cryptographically random 6-digit code

      // --- 6. Insert user row (FR-006, FR-007) ---
      // role is hard-coded 'borrower' — never accepted from form input
      $stmt = $pdo->prepare(
        'INSERT INTO Users (full_name, email, password_hash, role, is_verified, verification_token)
                 VALUES (?, ?, ?, \'borrower\', 0, ?)'
      );
      $stmt->execute([$full_name, $email, $password_hash, $token]);
      $new_user_id = (int) $pdo->lastInsertId();

      // --- 7. Log REGISTER SUCCESS with email (FR-013) ---
      log_event($pdo, 'REGISTER', null, 'Users', $new_user_id, 'SUCCESS', null, $email);

      // --- 8. Send verification email (FR-008, FR-009) ---
      $email_sent = false;
      $email_error = '';

      // Build verification link with email pre-filled
      $verify_link = BASE_URL . 'verify.php?email=' . urlencode($email);

      $mail_body = '<!DOCTYPE html><html><body>'
        . '<p>Hello ' . htmlspecialchars($full_name, ENT_QUOTES, 'UTF-8') . ',</p>'
        . '<p>Thank you for registering. Enter the verification code below on the verification page to activate your account:</p>'
        . '<p style="font-size:32px;font-weight:bold;letter-spacing:8px;text-align:center;margin:24px 0;">'
        . htmlspecialchars($token, ENT_QUOTES, 'UTF-8')
        . '</p>'
        . '<p style="text-align:center;"><a href="' . htmlspecialchars($verify_link, ENT_QUOTES, 'UTF-8') . '" style="display:inline-block;padding:10px 20px;background-color:#4CAF50;color:white;text-decoration:none;border-radius:4px;">Verify Email</a></p>'
        . '<p style="text-align:center;">Or visit <a href="' . htmlspecialchars($verify_link, ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($verify_link, ENT_QUOTES, 'UTF-8') . '</a> and enter this code.</p>'
        . '<p>If you did not register, you can safely ignore this email.</p>'
        . '</body></html>';

      $mail_alt = "Hello {$full_name},\n\nYour verification code is: {$token}\n\n"
        . 'Visit ' . $verify_link . " and enter this code.\n\n"
        . "If you did not register, you can safely ignore this email.";

      $email_sent = send_smtp_mail(
        $email,
        $full_name,
        'Verify your Library System account',
        $mail_body,
        $mail_alt,
        $email_error
      );

      if (!$email_sent) {
        // --- 9. Log SMTP failure with email capture (FR-011) ---
        error_log('[register.php] PHPMailer failed for user ' . $new_user_id . ': ' . $email_error);
        log_event($pdo, 'EMAIL_SEND_FAILED', null, 'Users', $new_user_id, 'FAILURE', null, $email);

        // User record is saved (is_verified=0); show informative error (FR-011)
        $errors[] = 'Your account was created but we could not send the verification email. '
          . 'Please contact support to have your account manually verified.';
      } else {
        // --- 10. All done — show confirmation page (FR-012) ---
        $show_success = true;
        $show_check_email = true;
        $check_email_address = $email;
      }
    } catch (PDOException $e) {
      error_log('[register.php] DB insert failed: ' . $e->getMessage());
      // Log REGISTER FAILURE with email capture if we have a PDO connection
      try {
        log_event(get_db(), 'REGISTER', null, 'Users', null, 'FAILURE', null, $email);
      } catch (\Throwable $_) { /* DB unavailable — silently skip log */
      }
      $errors[] = 'Registration failed due to a server error. Please try again.';
    }
  } else {
    // Validation failed — log REGISTER FAILURE with email capture if DB reachable
    try {
      log_event(get_db(), 'REGISTER', null, 'Users', null, 'FAILURE', null, $email);
    } catch (\Throwable $_) { /* silently skip */
    }
  }
}

$pageTitle = 'Create Account | Library System';
$extraScripts = [
  ['src' => BASE_URL . 'assets/js/register.js', 'defer' => true],
];

$alertType = '';
$alertTitle = '';
$alertMessage = '';
$alertRedirect = '';

if ($show_success) {
  $alertType = 'success';
  $alertTitle = 'Success';
  $alertMessage = 'Registration successful! A verification email has been sent. Please check your inbox for your 6-digit code.';
  $alertRedirect = BASE_URL . 'check-email.php?email=' . urlencode((string) $email);
} elseif (!empty($errors)) {
  $alertType = 'error';
  $alertTitle = 'Registration Failed';
  $alertMessage = $errors[0];
}

$bodyAlertAttributes = '';
if ($alertType !== '') {
  $safeType = htmlspecialchars($alertType, ENT_QUOTES, 'UTF-8');
  $safeTitle = htmlspecialchars($alertTitle, ENT_QUOTES, 'UTF-8');
  $safeMessage = htmlspecialchars($alertMessage, ENT_QUOTES, 'UTF-8');
  $bodyAlertAttributes = ' data-alert-type="' . $safeType . '" data-alert-title="' . $safeTitle . '" data-alert-message="' . $safeMessage . '"';

  if ($alertRedirect !== '') {
    $safeRedirect = htmlspecialchars($alertRedirect, ENT_QUOTES, 'UTF-8');
    $bodyAlertAttributes .= ' data-alert-redirect="' . $safeRedirect . '"';
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
        <img src="<?= BASE_URL ?>assets/images/library_logo_cropped.png" alt="Library System Logo" class="auth-hero__logo" onerror="this.onerror=null;this.src='<?= BASE_URL ?>assets/images/logo.svg';">
        <span>Library System</span>
      </a>
      <hr class="auth-hero__divider">
      <p class="auth-hero__tagline">Join Our Library Community</p>
      <p class="auth-hero__subtext">Access thousands of books, journals, and resources.</p>
      <ul class="auth-hero__features">
        <li>24/7 Digital Access</li>
        <li>Reserve Books Online</li>
        <li>Access to Digital Resources</li>
        <li>Free Educational Events</li>
      </ul>
    </div>

    <!-- Right: form panel -->
    <div class="auth-panel">
      <div class="auth-panel__inner">
        <h1 class="auth-panel__heading">Create your account</h1>
        <p class="auth-panel__sub">Join the library — it only takes a moment.</p>

        <?php if ($show_success): ?>
          <div class="flash flash-success" role="alert" aria-live="polite" aria-atomic="true">
            Registration successful! A verification email has been sent to
            <strong><?= htmlspecialchars($email, ENT_QUOTES, 'UTF-8') ?></strong>.
            Please check your inbox for your 6-digit code, then
            <a href="<?= htmlspecialchars(BASE_URL . 'verify.php?email=' . urlencode((string) $email), ENT_QUOTES, 'UTF-8') ?>"
              class="auth-inline-link">verify here</a>.
          </div>
        <?php else: ?>

          <?php if (!empty($errors)): ?>
            <div class="flash flash-error" role="alert" aria-live="assertive" aria-atomic="true">
              <div>
                <strong>Please fix the following:</strong>
                <ul class="flash-list">
                  <?php foreach ($errors as $err): ?>
                    <li><?= htmlspecialchars($err, ENT_QUOTES, 'UTF-8') ?></li>
                  <?php endforeach; ?>
                </ul>
              </div>
            </div>
          <?php endif; ?>

          <form method="post" action="">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
            <div class="form-group">
              <label class="field-label" for="full_name">Full Name</label>
              <input class="field-input" type="text" id="full_name" name="full_name" required autofocus
                value="<?= htmlspecialchars($old['full_name'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
            </div>

            <div class="form-group">
              <label class="field-label" for="email">Email Address</label>
              <input class="field-input" type="email" id="email" name="email" required
                value="<?= htmlspecialchars($old['email'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
            </div>

            <div class="form-group">
              <label class="field-label" for="password">Password <span class="field-label__hint">(min. 8 characters)</span></label>
              <input class="field-input" type="password" id="password" name="password" required>
            </div>

            <div class="form-group">
              <label class="field-label" for="confirm_password">Confirm Password</label>
              <input class="field-input" type="password" id="confirm_password" name="confirm_password" required>
            </div>

            <button type="submit" class="btn-primary btn-block">Create Account</button>
          </form>

          <p class="auth-note auth-note--center">
            Already have an account?
            <a href="<?= htmlspecialchars(BASE_URL . 'login.php', ENT_QUOTES, 'UTF-8') ?>"
              class="auth-inline-link auth-inline-link--strong">Log in</a>
          </p>

        <?php endif; ?>
      </div>
    </div>

  </div>

  </body>

</html>