<?php

/**
 * login.php — Login Form & Handler (US3, FR-020–FR-026, FR-028)
 *
 * GET : Display login form; show ?message=verified notice if present (FR-016)
 * POST: Authenticate user → regenerate session → redirect to role landing page
 */

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/auth-helpers.php';
require_once __DIR__ . '/includes/staff_approval.php';
require_once __DIR__ . '/src/utils/rate_limit.php';

if (session_status() === PHP_SESSION_NONE) {
  $isHttps = (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
      || ((string) ($_SERVER['SERVER_PORT'] ?? '')) === '443';
  session_set_cookie_params([
      'httponly' => true,
      'secure' => $isHttps,
      'samesite' => 'Lax',
  ]);
  session_start();
}

$isLoginSubmission = $_SERVER['REQUEST_METHOD'] === 'POST';
$loginRateLimit = $isLoginSubmission
  ? rate_limit_check('login', 5, 900)
  : rate_limit_status('login', 5, 900);
rate_limit_headers($loginRateLimit, 5);

if (!$loginRateLimit['allowed']) {
  http_response_code(429);

  $retryAfterSeconds = (int) ($loginRateLimit['retry_after'] ?? 900);
  $retryMinutes = max(1, (int) ceil($retryAfterSeconds / 60));
  $retryCopy = $retryMinutes === 1 ? 'about 1 minute' : 'about ' . $retryMinutes . ' minutes';
  $pageTitle = 'Too Many Login Attempts | LibraLink';
  $includeSweetAlert = false;
  ?>
  <!DOCTYPE html>
  <html lang="en">

  <head>
    <?php require_once __DIR__ . '/includes/head.php'; ?>
  </head>

  <body>
    <main class="auth-wrap auth-lockout page-fade-in" aria-labelledby="lockout-title">
      <section class="auth-hero auth-lockout__hero" aria-label="LibraLink security notice">
        <a href="<?= htmlspecialchars(BASE_URL . 'index.php', ENT_QUOTES, 'UTF-8') ?>" class="auth-hero__brand auth-hero__brand--linked">
          <img src="<?= BASE_URL ?>assets/images/library_logo_cropped.png" alt="LibraLink Logo" class="auth-hero__logo" onerror="this.onerror=null;this.src='<?= BASE_URL ?>assets/images/logo.svg';">
          <span>LibraLink</span>
        </a>
        <hr class="auth-hero__divider">
        <p class="auth-hero__tagline">We pause sign-ins when activity looks unusual, so library accounts stay protected.</p>
        <ul class="auth-hero__features">
          <li>Your loans and reservations stay safe</li>
          <li>The pause clears automatically</li>
          <li>No account changes were made</li>
        </ul>
      </section>

      <section class="auth-panel auth-lockout__panel" role="alert" aria-live="polite" aria-atomic="true">
        <div class="auth-panel__inner auth-lockout__inner">
          <div class="auth-lockout__mark" aria-hidden="true">
            <svg viewBox="0 0 24 24" width="30" height="30" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
              <path d="M12 3 4.5 6.2v5.7c0 4.6 3.1 7.2 7.5 9.1 4.4-1.9 7.5-4.5 7.5-9.1V6.2L12 3Z" />
              <path d="M9.6 12.1 11.3 14l3.4-4" />
            </svg>
          </div>

          <p class="auth-lockout__status">Sign-in paused</p>
          <h1 id="lockout-title" class="auth-panel__heading auth-lockout__heading">Too many login attempts</h1>
          <p class="auth-panel__sub auth-lockout__sub">
            For your protection, LibraLink has temporarily paused new sign-in attempts from this browser. Try again in <?= htmlspecialchars($retryCopy, ENT_QUOTES, 'UTF-8') ?>.
          </p>

          <div class="auth-lockout__notice">
            <strong>Nothing is wrong with your account.</strong>
            <span>If you forgot your password, wait for the pause to end, then reset it before trying again.</span>
          </div>

          <div class="auth-lockout__actions" aria-label="Next steps">
            <a href="<?= htmlspecialchars(BASE_URL . 'index.php', ENT_QUOTES, 'UTF-8') ?>" class="btn-primary btn-block">Return to library home</a>
            <a href="<?= htmlspecialchars(BASE_URL . 'forgot-password.php', ENT_QUOTES, 'UTF-8') ?>" class="auth-lockout__secondary">Reset password later</a>
          </div>

          <p class="auth-note auth-note--center auth-lockout__footnote">
            This limit helps prevent unauthorized access to borrower and staff accounts.
          </p>
        </div>
      </section>
    </main>
  </body>

  </html>
  <?php
  exit;
}

// FR-020: Redirect already-logged-in users to their role landing page
redirect_authenticated_user();

$error          = '';
$notice         = '';
$old_identifier = '';
$error_context  = '';
$unverified_email = '';

// Feature 005: Display flash error from suspended-account redirect
if (!empty($_SESSION['flash_error'])) {
  $error = $_SESSION['flash_error'];
  unset($_SESSION['flash_error']);
}

// FR-016: Show "verified" notice when redirected from verify.php
if (isset($_GET['message']) && $_GET['message'] === 'verified') {
  $notice = 'Your email has been verified. You may now log in.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_verify();

  $identifier = trim($_POST['email'] ?? '');
  $password   = $_POST['password'] ?? '';
  $old_identifier = $identifier;

  // Basic presence check
  if ($identifier === '' || $password === '') {
    $error = 'Invalid email or password.';
  } else {

    try {
      $pdo = get_db();
      $staffApprovalSelect = staff_column_exists($pdo, 'staff_approval_status')
        ? 'staff_approval_status'
        : "'" . STAFF_APPROVAL_NONE . "' AS staff_approval_status";

      // FR-021: Lookup by email using prepared statement
      $stmt = $pdo->prepare(
        'SELECT id, full_name, email, password_hash, role, is_verified, is_suspended, ' . $staffApprovalSelect . ',
                failed_login_attempts, locked_until
         FROM Users
         WHERE email = ? OR full_name = ?
         LIMIT 1'
      );
      $stmt->execute([$identifier, $identifier]);
      $user = $stmt->fetch();

      $auth_ok = false;

      // Check if account is locked due to too many failed attempts
      if ($user && $user['locked_until'] !== null) {
        $lock_time = strtotime($user['locked_until']);
        if ($lock_time !== false && $lock_time > time()) {
          $error = 'Account locked. Please try again later.';
          log_event($pdo, 'LOGIN_BLOCKED_LOCKED', (int) $user['id'], 'Users', (int) $user['id'], 'FAILURE', $user['role'], $identifier);
          $user = null;
        }
      }

      if ($user && password_verify($password, $user['password_hash'])) {

        // FR-022: Block unverified accounts
        if ((int) $user['is_verified'] !== 1) {
          // Non-generic message only for unverified — spec allows this (FR-025 covers "wrong creds")
          $error = staff_login_block_message($user);
          $error_context = staff_login_block_context($user);
          $unverified_email = (string) $user['email'];
          // Still log as LOGIN_FAILED with null actor (FR-026), capturing email for audit trail
          log_event($pdo, 'LOGIN_FAILED', null, null, null, 'FAILURE', null, $identifier);
        } elseif ((int) $user['is_suspended'] === 1) {
          // Feature 005: Block suspended accounts — checked after password_verify to avoid
          // confirming account existence to unauthenticated users
          $_SESSION['flash_error'] = 'Your account has been suspended. Please contact the library.';
          header('Location: ' . BASE_URL . 'login.php');
          exit;
        } else {
          // Reset failed login attempts on successful auth
          $reset_stmt = $pdo->prepare('UPDATE Users SET failed_login_attempts = 0, locked_until = NULL WHERE id = ?');
          $reset_stmt->execute([(int) $user['id']]);
          $auth_ok = true;
        }
      } else {
        // Timing normalization to prevent timing attacks
        usleep(random_int(0, 100000));

        // Wrong password OR non-existent email — FR-025: generic message, no leakage
        $error = 'Invalid email or password.';
        log_event($pdo, 'LOGIN_FAILED', null, null, null, 'FAILURE', null, $identifier);

        // Increment failed login attempts and lock if needed
        if ($user) {
          $attempts = (int) ($user['failed_login_attempts'] ?? 0) + 1;
          if ($attempts >= 5) {
            $unlock_time = date('Y-m-d H:i:s', strtotime('+15 minutes'));
            $lock_stmt = $pdo->prepare('UPDATE Users SET failed_login_attempts = ?, locked_until = ? WHERE id = ?');
            $lock_stmt->execute([$attempts, $unlock_time, (int) $user['id']]);
          } else {
            $attemp_stmt = $pdo->prepare('UPDATE Users SET failed_login_attempts = ? WHERE id = ?');
            $attemp_stmt->execute([$attempts, (int) $user['id']]);
          }
        }
      }

      if ($auth_ok) {
        // FR-023: Regenerate session ID to prevent fixation
        session_regenerate_id(true);

        $role = resolve_role((string) $user['role']);

        // Set the session keys including email for audit logging
        $_SESSION['user_id']   = (int) $user['id'];
        $_SESSION['email']     = $user['email'];
        $_SESSION['role']      = $role;
        $_SESSION['full_name'] = $user['full_name'];

        // FR-026: Log success BEFORE redirect with email capture
        log_event($pdo, 'LOGIN_SUCCESS', (int) $user['id'], 'Users', (int) $user['id'], 'SUCCESS', $user['role'], $user['email']);
        // FR-024: Redirect to role landing page
        header('Location: ' . BASE_URL . role_landing_path($role));
        exit;
      }
    } catch (PDOException $e) {
      error_log('[login.php] DB error: ' . $e->getMessage());
      $error = 'A server error occurred. Please try again.';
    }
  }
}

$pageTitle = 'Sign In | LibraLink';
$extraScripts = [
  ['src' => BASE_URL . 'assets/js/login.js', 'defer' => true],
];

$alertType = '';
$alertMessage = '';
$alertEmail = '';
$alertCsrf = '';
$alertBaseUrl = '';
$alertContext = '';
if ($error !== '') {
  $alertType = 'error';
  $alertMessage = $error;
  $alertContext = $error_context;
  if ($error_context === 'unverified' && $unverified_email !== '') {
    $alertEmail = $unverified_email;
    $alertCsrf = csrf_token();
    $alertBaseUrl = BASE_URL;
  }
} elseif ($notice !== '') {
  $alertType = 'success';
  $alertMessage = $notice;
}

$bodyAlertAttributes = '';
if ($alertType !== '') {
  $safeAlertType = htmlspecialchars($alertType, ENT_QUOTES, 'UTF-8');
  $safeAlertMessage = htmlspecialchars($alertMessage, ENT_QUOTES, 'UTF-8');
  $bodyAlertAttributes = ' data-alert-type="' . $safeAlertType . '" data-alert-message="' . $safeAlertMessage . '"';
  if ($alertContext !== '') {
    $safeAlertContext = htmlspecialchars($alertContext, ENT_QUOTES, 'UTF-8');
    $bodyAlertAttributes .= ' data-alert-context="' . $safeAlertContext . '"';
  }
  if ($alertEmail !== '') {
    $safeAlertEmail = htmlspecialchars($alertEmail, ENT_QUOTES, 'UTF-8');
    $bodyAlertAttributes .= ' data-alert-email="' . $safeAlertEmail . '"';
  }
  if ($alertCsrf !== '') {
    $safeAlertCsrf = htmlspecialchars($alertCsrf, ENT_QUOTES, 'UTF-8');
    $bodyAlertAttributes .= ' data-alert-csrf="' . $safeAlertCsrf . '"';
  }
  if ($alertBaseUrl !== '') {
    $safeAlertBaseUrl = htmlspecialchars($alertBaseUrl, ENT_QUOTES, 'UTF-8');
    $bodyAlertAttributes .= ' data-base-url="' . $safeAlertBaseUrl . '"';
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
        <h1 class="auth-panel__heading">Welcome back</h1>
        <p class="auth-panel__sub">Sign in to your library account.</p>

        <form method="post" action="">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
          <div class="form-group">
            <label class="field-label" for="email">Email or Username</label>
            <input class="field-input" type="text" id="email" name="email" required autofocus
              autocomplete="username"
              value="<?= htmlspecialchars($old_identifier, ENT_QUOTES, 'UTF-8') ?>">
          </div>

          <div class="form-group">
            <label class="field-label" for="password">Password</label>
            <input class="field-input" type="password" id="password" name="password" required autocomplete="current-password">
          </div>

          <button type="submit" class="btn-primary btn-block">Sign In</button>
        </form>

        <?php if ($error_context === 'unverified' && $unverified_email !== ''): ?>
          <div class="flash flash-error" role="alert" aria-live="polite" aria-atomic="true">
            <p>We sent a verification code to <strong><?= htmlspecialchars($unverified_email, ENT_QUOTES, 'UTF-8') ?></strong>.</p>
            <form method="post" action="<?= htmlspecialchars(BASE_URL . 'verify.php', ENT_QUOTES, 'UTF-8') ?>">
              <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
              <input type="hidden" name="intent" value="resend">
              <input type="hidden" name="email" value="<?= htmlspecialchars($unverified_email, ENT_QUOTES, 'UTF-8') ?>">
              <button type="submit" class="btn-ghost btn-block" formnovalidate>Resend Verification Email</button>
            </form>
            <p class="auth-note auth-note--center" style="margin-top: 12px;">
              <a href="<?= htmlspecialchars(BASE_URL . 'verify.php?email=' . urlencode($unverified_email), ENT_QUOTES, 'UTF-8') ?>" class="auth-inline-link auth-inline-link--strong">Enter verification code</a>
            </p>
          </div>
        <?php endif; ?>

        <div class="bottom-links">
          <!-- Primary CTA: Register -->
          <a href="<?= htmlspecialchars(BASE_URL . 'register.php', ENT_QUOTES, 'UTF-8') ?>" class="btn-register">
            Join Our Community
          </a>

          <!-- Secondary & Tertiary Actions -->
          <div class="bottom-links__secondary">
            <a href="<?= htmlspecialchars(BASE_URL . 'forgot-password.php', ENT_QUOTES, 'UTF-8') ?>" class="link-secondary link-forgot">
              <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <circle cx="12" cy="12" r="10" />
                <line x1="12" y1="16" x2="12" y2="12" />
                <line x1="12" y1="8" x2="12.01" y2="8" />
              </svg>
              Can't Access Your Account?
            </a>
          </div>
        </div>
      </div>
    </div>

  </div>

  </body>

</html>
