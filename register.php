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
require_once __DIR__ . '/includes/staff_approval.php';
require_once __DIR__ . '/src/utils/helpers.php';
require_once __DIR__ . '/src/utils/rate_limit.php';

// Start session only to preserve flash messages across redirect (not for auth here)
if (session_status() === PHP_SESSION_NONE) {
  session_start();
}

if (!rate_limit('register', 5, 900)) {
  http_response_code(429);
  exit('Too many registration attempts. Please try again later.');
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
  $account_type    = staff_normalize_registration_role((string) ($_POST['account_type'] ?? 'borrower'));
  $password        = $_POST['password']             ?? '';
  $confirm_password = $_POST['confirm_password']    ?? '';

  $old = [
    'full_name' => $full_name,
    'email'     => $email,
    'account_type' => $account_type,
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
  } else {
    $pw_validation = validate_password_complexity($password);
    if (!$pw_validation['valid']) {
      foreach ($pw_validation['errors'] as $err) {
        $errors[] = $err;
      }
    }
  }

  if ($confirm_password === '') {
    $errors[] = 'Please confirm your password.';
  } elseif ($password !== $confirm_password) {
    $errors[] = 'Passwords do not match.';
  }

  if (empty($errors)) {

    try {
      $pdo = get_db();
      ensure_staff_approval_support($pdo);

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
      ensure_staff_approval_support($pdo);
      $registration_state = staff_registration_state($account_type);

      // --- 4. Hash password (FR-004) ---
      $password_hash = password_hash($password, PASSWORD_BCRYPT);

      // --- 5. Generate 6-digit numeric OTP (FR-005) ---
      $token = (string) random_int(100000, 999999);   // cryptographically random 6-digit code

      // --- 6. Insert user row (FR-006, FR-007) ---
      // Public registration only allows borrower accounts or pending librarian requests.
      $stmt = $pdo->prepare(
        'INSERT INTO Users
          (full_name, email, password_hash, role, is_verified, is_suspended, verification_token, staff_approval_status)
         VALUES
          (?, ?, ?, ?, ?, ?, ?, ?)'
      );
      $stmt->execute([
        $full_name,
        $email,
        $password_hash,
        $registration_state['role'],
        $registration_state['is_verified'],
        $registration_state['is_suspended'],
        $account_type === 'borrower' ? $token : null,
        $registration_state['staff_approval_status'],
      ]);
      $new_user_id = (int) $pdo->lastInsertId();

      // --- 7. Log REGISTER SUCCESS with email (FR-013) ---
      log_event($pdo, 'REGISTER', null, 'Users', $new_user_id, 'SUCCESS', null, $email);

      if ($account_type === 'librarian') {
        $show_success = true;
        $show_check_email = false;
        $check_email_address = $email;
        log_event($pdo, 'LIBRARIAN_REQUEST_PENDING', null, 'Users', $new_user_id, 'SUCCESS', null, $email);
      } else {
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
        'Verify your LibraLink account',
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

$pageTitle = 'Create Account | LibraLink';
$extraStyles = [
  BASE_URL . 'assets/css/register.css',
];
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
  if (($old['account_type'] ?? 'borrower') === 'librarian' || !$show_check_email) {
    $alertMessage = 'Librarian registration submitted. Please wait for admin approval before logging in.';
    $alertRedirect = BASE_URL . 'login.php';
  } else {
    $alertMessage = 'Registration successful! A verification email has been sent. Please check your inbox for your 6-digit code.';
    $alertRedirect = BASE_URL . 'check-email.php?email=' . urlencode((string) $email);
  }
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

<body class="register-page"<?= $bodyAlertAttributes ?>>
  <div class="auth-wrap page-fade-in">

    <!-- Left: hero image panel -->
    <div class="auth-hero" aria-hidden="true">
      <a href="index.php" class="auth-hero__brand auth-hero__brand--linked">
        <img src="<?= BASE_URL ?>assets/images/library_logo_cropped.png" alt="LibraLink Logo" class="auth-hero__logo" onerror="this.onerror=null;this.src='<?= BASE_URL ?>assets/images/logo.svg';">
        <span>LibraLink</span>
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
            <?php if ($show_check_email): ?>
              Registration successful! A verification email has been sent to
              <strong><?= htmlspecialchars($email, ENT_QUOTES, 'UTF-8') ?></strong>.
              Please check your inbox for your 6-digit code, then
              <a href="<?= htmlspecialchars(BASE_URL . 'verify.php?email=' . urlencode((string) $email), ENT_QUOTES, 'UTF-8') ?>"
                class="auth-inline-link">verify here</a>.
            <?php else: ?>
              Librarian registration submitted for
              <strong><?= htmlspecialchars($email, ENT_QUOTES, 'UTF-8') ?></strong>.
              Please wait for admin approval before logging in.
            <?php endif; ?>
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
              <span class="field-label">Account Type</span>
              <div class="account-type-options" role="radiogroup" aria-label="Choose account type">
                <label class="account-type-card">
                  <input type="radio" name="account_type" value="borrower" <?= ($old['account_type'] ?? 'borrower') === 'borrower' ? 'checked' : '' ?>>
                  <span class="account-type-card__body">
                    <span class="account-type-card__icon" aria-hidden="true">
                      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20" />
                        <path d="M4 4.5A2.5 2.5 0 0 1 6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5z" />
                      </svg>
                    </span>
                    <span class="account-type-card__title">Borrower</span>
                  </span>
                </label>

                <label class="account-type-card">
                  <input type="radio" name="account_type" value="librarian" <?= ($old['account_type'] ?? '') === 'librarian' ? 'checked' : '' ?>>
                  <span class="account-type-card__body">
                    <span class="account-type-card__icon" aria-hidden="true">
                      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M8 21h8" />
                        <path d="M12 17v4" />
                        <path d="M7 4h10" />
                        <path d="M6 8h12" />
                        <path d="M8 12h8" />
                        <path d="M10 16h4" />
                      </svg>
                    </span>
                    <span class="account-type-card__title">Librarian</span>
                  </span>
                </label>
              </div>
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
