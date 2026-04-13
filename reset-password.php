<?php

/**
 * reset-password.php — Password Reset Handler
 *
 * GET : Display password reset form with valid token
 * POST: Update password with valid reset token
 */

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/auth-helpers.php';

if (session_status() === PHP_SESSION_NONE) {
  session_start();
}

// Redirect logged-in users to their dashboard
redirect_authenticated_user();

$error      = '';
$success    = '';
$token      = trim($_GET['token'] ?? '');
$token_valid = false;
$reset_user = null;

// Validate token
if ($token === '') {
  $error = 'Invalid password reset link.';
} else {
  try {
    $pdo = get_db();

    // Check if token exists and is still valid
    $stmt = $pdo->prepare(
      'SELECT id, email, password_reset_expires FROM Users 
       WHERE password_reset_token = ? AND password_reset_expires > NOW() LIMIT 1'
    );
    $stmt->execute([$token]);
    $reset_user = $stmt->fetch();

    if ($reset_user) {
      $token_valid = true;
    } else {
      $error = 'This password reset link has expired or is invalid. Please request a new one.';
    }
  } catch (PDOException $e) {
    error_log('[reset-password.php] DB error: ' . $e->getMessage());
    $error = 'A server error occurred. Please try again.';
  }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $token_valid) {
  csrf_verify();
  $password = $_POST['password'] ?? '';
  $password_confirm = $_POST['password_confirm'] ?? '';

  // Validate passwords
  if ($password === '' || $password_confirm === '') {
    $error = 'Please fill in all fields.';
  } elseif (strlen($password) < 8) {
    $error = 'Password must be at least 8 characters long.';
  } elseif ($password !== $password_confirm) {
    $error = 'Passwords do not match.';
   } else {
    try {
      $pdo = get_db();

      // Start transaction to prevent TOCTOU race condition where token could
      // be used twice between validation and update
      $pdo->beginTransaction();

      try {
        // Re-verify token still valid within transaction with row lock
        $verify_stmt = $pdo->prepare(
          'SELECT id FROM Users 
           WHERE id = ? AND password_reset_token = ? AND password_reset_expires > NOW() 
           FOR UPDATE'
        );
        $verify_stmt->execute([$reset_user['id'], $token]);
        if (!$verify_stmt->fetch()) {
          $pdo->rollBack();
          $error = 'Password reset link has expired or is invalid.';
        } else {
          // Hash and update password, clear reset token
          $password_hash = password_hash($password, PASSWORD_BCRYPT);

          $stmt = $pdo->prepare(
            'UPDATE Users SET password_hash = ?, password_reset_token = NULL, password_reset_expires = NULL, updated_at = NOW() 
             WHERE id = ? LIMIT 1'
          );
          $stmt->execute([$password_hash, $reset_user['id']]);

          if ($stmt->rowCount() > 0) {
            // Log successful password reset
            log_event($pdo, 'PASSWORD_RESET_SUCCESS', (int) $reset_user['id'], 'Users', (int) $reset_user['id'], 'SUCCESS', null, $reset_user['email']);

            $success = 'Your password has been reset successfully. You can now log in with your new password.';
            $pdo->commit();
          } else {
            $pdo->rollBack();
            $error = 'Unable to reset password. Please try again.';
          }
        }
      } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
      }
    } catch (PDOException $e) {
      error_log('[reset-password.php] DB error: ' . $e->getMessage());
      $error = 'A server error occurred. Please try again.';
    }
  }
}

$pageTitle = 'Reset Password | Library System';
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <?php require_once __DIR__ . '/includes/head.php'; ?>
</head>

<body>
  <div class="auth-wrap page-fade-in">

    <!-- Left: hero image panel -->
    <div class="auth-hero" aria-hidden="true">
      <a href="index.php" class="auth-hero__brand auth-hero__brand--linked">
        <img src="<?= BASE_URL ?>assets/images/logo.svg" alt="Library System Logo" class="auth-hero__logo">
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
        <h1 class="auth-panel__heading">Set new password</h1>
        <p class="auth-panel__sub">Enter your new password below.</p>

        <?php if ($success !== ''): ?>
          <div class="flash flash-success">
            <?= htmlspecialchars($success, ENT_QUOTES, 'UTF-8') ?>
          </div>
          <p class="auth-note auth-note--center">
            <a href="<?= htmlspecialchars(BASE_URL . 'login.php', ENT_QUOTES, 'UTF-8') ?>"
              class="auth-inline-link auth-inline-link--strong">Go to sign in</a>
          </p>
        <?php elseif ($error !== ''): ?>
          <div class="flash flash-error">
            <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
          </div>
          <p class="auth-note auth-note--center">
            <a href="<?= htmlspecialchars(BASE_URL . 'forgot-password.php', ENT_QUOTES, 'UTF-8') ?>"
              class="auth-inline-link auth-inline-link--strong">Request a new reset link</a>
          </p>
        <?php else: ?>
          <form method="post" action="">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
            <div class="form-group">
              <label class="field-label" for="password">New Password</label>
              <input class="field-input" type="password" id="password" name="password" required autofocus
                placeholder="At least 8 characters">
            </div>

            <div class="form-group">
              <label class="field-label" for="password_confirm">Confirm Password</label>
              <input class="field-input" type="password" id="password_confirm" name="password_confirm" required
                placeholder="Confirm your new password">
            </div>

            <button type="submit" class="btn-primary btn-block">Reset Password</button>
          </form>

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

