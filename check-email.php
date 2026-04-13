<?php

/**
 * check-email.php — Post-registration email verification prompt
 *
 * GET: Display instructions to verify a newly created account.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/auth-helpers.php';
require_once __DIR__ . '/includes/csrf.php';

if (session_status() === PHP_SESSION_NONE) {
  session_start();
}

// Redirect already-logged-in users to their dashboard
redirect_authenticated_user();

$email = trim((string) ($_GET['email'] ?? ''));
$email_valid = $email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL);

$pageTitle = 'Check Your Email | Library System';
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <?php require_once __DIR__ . '/includes/head.php'; ?>
</head>

<body>
  <div class="auth-wrap page-fade-in">

    <div class="auth-hero" aria-hidden="true">
      <a href="index.php" class="auth-hero__brand auth-hero__brand--linked">
        <img src="<?= BASE_URL ?>assets/images/library_logo_cropped.png" alt="Library System Logo" class="auth-hero__logo" onerror="this.onerror=null;this.src='<?= BASE_URL ?>assets/images/logo.svg';">
        <span>Library System</span>
      </a>
      <hr class="auth-hero__divider">
      <p class="auth-hero__tagline">Almost there — verify your email</p>
      <p class="auth-hero__subtext">We need to confirm your address before you can sign in.</p>
    </div>

    <div class="auth-panel">
      <div class="auth-panel__inner">
        <h1 class="auth-panel__heading">Check your email</h1>
        <p class="auth-panel__sub">We sent a 6-digit verification code to your inbox.</p>

        <div class="flash flash-success" role="alert" aria-live="polite" aria-atomic="true">
          <strong>Verify your account to continue.</strong>
          <?php if ($email_valid): ?>
            <p style="margin-top:10px;">
              Email sent to <strong><?= htmlspecialchars($email, ENT_QUOTES, 'UTF-8') ?></strong>.
            </p>
          <?php endif; ?>
          <p style="margin-top:10px;">
            Open the verification page and enter your code.
          </p>
        </div>

        <div style="margin-top: 18px;">
          <a href="<?= htmlspecialchars(BASE_URL . 'verify.php' . ($email_valid ? ('?email=' . urlencode($email)) : ''), ENT_QUOTES, 'UTF-8') ?>"
            class="btn-primary btn-block">Verify Email</a>
        </div>

        <?php if ($email_valid): ?>
          <form method="post" action="<?= htmlspecialchars(BASE_URL . 'verify.php', ENT_QUOTES, 'UTF-8') ?>" style="margin-top: 12px;">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="intent" value="resend">
            <input type="hidden" name="email" value="<?= htmlspecialchars($email, ENT_QUOTES, 'UTF-8') ?>">
            <button type="submit" class="btn-ghost btn-block" formnovalidate>Resend Verification Email</button>
          </form>
        <?php endif; ?>

        <p class="auth-note auth-note--center" style="margin-top: 18px;">
          Already verified?
          <a href="<?= htmlspecialchars(BASE_URL . 'login.php', ENT_QUOTES, 'UTF-8') ?>"
            class="auth-inline-link auth-inline-link--strong">Log in</a>
        </p>
      </div>
    </div>

  </div>
</body>

</html>