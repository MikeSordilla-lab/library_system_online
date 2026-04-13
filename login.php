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

if (session_status() === PHP_SESSION_NONE) {
  session_start();
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

      // FR-021: Lookup by email using prepared statement
      $stmt = $pdo->prepare(
        'SELECT id, full_name, email, password_hash, role, is_verified, is_suspended
                 FROM Users
                WHERE email = ? OR full_name = ?
                LIMIT 1'
      );
      $stmt->execute([$identifier, $identifier]);
      $user = $stmt->fetch();

      $auth_ok = false;

      if ($user && password_verify($password, $user['password_hash'])) {

        // FR-022: Block unverified accounts
        if ((int) $user['is_verified'] !== 1) {
          // Non-generic message only for unverified — spec allows this (FR-025 covers "wrong creds")
          $error = 'Please verify your email before logging in.';
          $error_context = 'unverified';
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
          $auth_ok = true;
        }
      } else {
        // Wrong password OR non-existent email — FR-025: generic message, no leakage
        $error = 'Invalid email or password.';
        log_event($pdo, 'LOGIN_FAILED', null, null, null, 'FAILURE', null, $identifier);
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

$pageTitle = 'Sign In | Library System';
$extraScripts = [
  ['src' => BASE_URL . 'assets/js/login.js', 'defer' => true],
];

$alertType = '';
$alertMessage = '';
$alertEmail = '';
$alertCsrf = '';
$alertBaseUrl = '';
if ($error !== '') {
  $alertType = 'error';
  $alertMessage = $error;
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
            <a href="<?= htmlspecialchars(BASE_URL . 'catalog.php', ENT_QUOTES, 'UTF-8') ?>" class="link-secondary link-catalog">
              <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20" />
                <path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z" />
              </svg>
              Explore Without Signing In
            </a>
            <span class="bottom-links__divider" aria-hidden="true">•</span>
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