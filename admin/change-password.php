<?php

$allowed_roles = ["admin"];
require_once __DIR__ . "/../includes/auth_guard.php";
require_once __DIR__ . "/../includes/db.php";
require_once __DIR__ . "/../includes/admin.php";
require_once __DIR__ . "/../includes/csrf.php";
require_once __DIR__ . "/../src/utils/helpers.php";

$pdo = get_db();
ensure_superadmin_support($pdo);

$actor_id = (int) ($_SESSION["user_id"] ?? 0);
$current_page = "admin.change-password";
$pageTitle = "Change Password | LibraLink";
$self_url = BASE_URL . "admin/change-password.php";

$flash_success = $_SESSION["flash_success"] ?? "";
$flash_error = $_SESSION["flash_error_msg"] ?? "";
unset($_SESSION["flash_success"], $_SESSION["flash_error_msg"]);

if ($_SERVER["REQUEST_METHOD"] === "POST") {
  csrf_verify();

  $current_password = (string) ($_POST["current_password"] ?? "");
  $new_password = (string) ($_POST["new_password"] ?? "");
  $confirm_password = (string) ($_POST["confirm_password"] ?? "");

  if (
    $current_password === "" ||
    $new_password === "" ||
    $confirm_password === ""
  ) {
    $_SESSION["flash_error_msg"] = "All password fields are required.";
    header("Location: " . $self_url);
    exit();
  }

  if (strlen($new_password) < 8) {
    $_SESSION["flash_error_msg"] =
      "New password must be at least 8 characters.";
    header("Location: " . $self_url);
    exit();
  }

  $pw_validation = validate_password_complexity($new_password);
  if (!$pw_validation["valid"]) {
    $_SESSION["flash_error_msg"] =
      "Password does not meet complexity requirements.";
    header("Location: " . $self_url);
    exit();
  }

  if ($new_password !== $confirm_password) {
    $_SESSION["flash_error_msg"] =
      "New password and confirmation do not match.";
    header("Location: " . $self_url);
    exit();
  }

  try {
    $pdo->beginTransaction();

    $stmt = $pdo->prepare(
      "SELECT id, password_hash FROM Users WHERE id = ? FOR UPDATE",
    );
    $stmt->execute([$actor_id]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($current_password, $user["password_hash"])) {
      $pdo->rollBack();
      $_SESSION["flash_error_msg"] = "Current password is incorrect.";
      header("Location: " . $self_url);
      exit();
    }

    if (password_verify($new_password, $user["password_hash"])) {
      $pdo->rollBack();
      $_SESSION["flash_error_msg"] =
        "New password must be different from current password.";
      header("Location: " . $self_url);
      exit();
    }

    $new_hash = password_hash($new_password, PASSWORD_BCRYPT);
    $upd = $pdo->prepare(
      "UPDATE Users SET password_hash = ?, updated_at = NOW() WHERE id = ? LIMIT 1",
    );
    $upd->execute([$new_hash, $actor_id]);

    log_admin_action($pdo, [
      "actor_id" => $actor_id,
      "actor_role" => "admin",
      "action_type" => "password_change",
      "target_entity" => "Users",
      "target_id" => $actor_id,
      "outcome" => "success",
    ]);

    $pdo->commit();
    session_regenerate_id(true);
    $_SESSION["flash_success"] = "Password updated successfully.";
  } catch (PDOException $e) {
    if ($pdo->inTransaction()) {
      $pdo->rollBack();
    }

    error_log(
      "[admin/change-password.php] change_password DB error: " .
        $e->getMessage(),
    );
    $_SESSION["flash_error_msg"] =
      "A server error occurred while changing password.";
  }

  header("Location: " . $self_url);
  exit();
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <?php
  $extraStyles = [
    "https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700&display=swap",
    BASE_URL . "assets/css/borrower-redesign.css",
    BASE_URL . "assets/css/admin-redesign.css",
  ];
  require_once __DIR__ . "/../includes/head.php";
  ?>
  <style>
    .admin-form-field { margin-bottom: 20px; }
    .field-label { display: block; margin-bottom: 8px; font-weight: 600; color: var(--rd-text); }
    .field-shell { position: relative; display: flex; align-items: center; }
    .field-icon { position: absolute; left: 14px; color: var(--rd-primary); display: flex; opacity: 0.7; pointer-events: none; }
    .field-icon svg { width: 18px; height: 18px; }
    .admin-password-page .field-input { width: 100%; padding: 13px 44px 13px 50px; border: 1px solid var(--rd-border); border-radius: 10px; font-size: 15px; background: rgba(255,255,255,0.04); color: var(--rd-text); box-sizing: border-box; }
    .admin-password-page .field-input:focus { outline: 2px solid var(--rd-primary); border-color: var(--rd-primary); box-shadow: 0 0 0 3px rgba(201,168,76,0.15); }
    .admin-password-page .field-input::placeholder { color: var(--rd-text-muted); opacity: 0.7; }
    .toggle-password { position: absolute; right: 10px; background: none; border: none; cursor: pointer; padding: 8px; color: var(--rd-text-muted); display: flex; transition: color 0.2s; }
    .toggle-password:hover { color: var(--rd-primary); }
    .toggle-password svg { width: 20px; height: 20px; }
    @media (max-width: 768px) {
      .admin-password-page > div[style*="grid-template-columns"] { grid-template-columns: 1fr !important; }
    }
  </style>
</head>

<body class="admin-themed">
  <div class="app-shell">
    <?php require_once __DIR__ . "/../includes/sidebar-admin.php"; ?>

    <main class="main-content admin-password-page">
      <div class="page-header">
        <h1>Change Password</h1>
        <p class="page-header__lede">Keep your account secure by using a strong password.</p>
      </div>

      <?php if ($flash_success !== ""): ?>
        <div class="flash-success"><?= htmlspecialchars(
          $flash_success,
          ENT_QUOTES,
          "UTF-8",
        ) ?></div>
      <?php endif; ?>
      <?php if ($flash_error !== ""): ?>
        <div class="flash-error"><?= htmlspecialchars(
          $flash_error,
          ENT_QUOTES,
          "UTF-8",
        ) ?></div>
      <?php endif; ?>

      <div style="display: grid; grid-template-columns: 1fr 340px; gap: 20px; align-items: start;">
        <section class="section-card">
          <div class="section-card__header">
            <span class="section-card__title">Update Your Password</span>
          </div>
          <form method="post" action="<?= htmlspecialchars(
            $self_url,
            ENT_QUOTES,
            "UTF-8",
          ) ?>" id="passwordForm" style="padding: 20px;">
            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">

            <div class="admin-form-field">
              <label class="field-label" for="current_password">Current Password</label>
              <div class="field-shell">
                <span class="field-icon" aria-hidden="true">
                  <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect>
                    <path d="M7 11V7a5 5 0 0 1 10 0v4"></path>
                  </svg>
                </span>
                <input class="field-input" type="password" id="current_password" name="current_password" required placeholder="Enter current password" autocomplete="current-password">
                <button type="button" class="toggle-password" aria-label="Show password">
                  <svg class="icon-show" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path>
                    <circle cx="12" cy="12" r="3"></circle>
                  </svg>
                  <svg class="icon-hide" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:none;">
                    <path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"></path>
                    <line x1="1" y1="1" x2="23" y2="23"></line>
                  </svg>
                </button>
              </div>
            </div>

            <div class="admin-form-field">
              <label class="field-label" for="new_password">New Password</label>
              <div class="field-shell">
                <span class="field-icon" aria-hidden="true">
                  <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect>
                    <path d="M7 11V7a5 5 0 0 1 10 0v4"></path>
                  </svg>
                </span>
                <input class="field-input" type="password" id="new_password" name="new_password" required placeholder="Create new password (min 8 chars)" minlength="8" autocomplete="new-password">
                <button type="button" class="toggle-password" aria-label="Show password">
                  <svg class="icon-show" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path>
                    <circle cx="12" cy="12" r="3"></circle>
                  </svg>
                  <svg class="icon-hide" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:none;">
                    <path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"></path>
                    <line x1="1" y1="1" x2="23" y2="23"></line>
                  </svg>
                </button>
              </div>
              <div class="strength" style="margin-top: 8px;">
                <div class="strength-bar" id="strengthBar" style="height: 4px; background: rgba(255,255,255,0.1); border-radius: 2px; overflow: hidden;">
                  <div style="height: 100%; width: 0%; background: #c53030; transition: all 0.3s;"></div>
                </div>
              </div>
              <small class="strength-text" id="strengthText" style="color: var(--rd-text-muted); font-size: 12px;">Enter a password</small>
            </div>

            <div class="admin-form-field">
              <label class="field-label" for="confirm_password">Confirm Password</label>
              <div class="field-shell">
                <span class="field-icon" aria-hidden="true">
                  <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect>
                    <path d="M7 11V7a5 5 0 0 1 10 0v4"></path>
                  </svg>
                </span>
                <input class="field-input" type="password" id="confirm_password" name="confirm_password" required placeholder="Confirm new password" minlength="8" autocomplete="new-password">
                <button type="button" class="toggle-password" aria-label="Show password">
                  <svg class="icon-show" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path>
                    <circle cx="12" cy="12" r="3"></circle>
                  </svg>
                  <svg class="icon-hide" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:none;">
                    <path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"></path>
                    <line x1="1" y1="1" x2="23" y2="23"></line>
                  </svg>
                </button>
              </div>
            </div>

            <div style="display: flex; gap: 12px; margin-top: 24px;">
              <button type="submit" class="btn-primary" id="submitBtn">
                <span class="btn-text">Update Password</span>
              </button>
              <a href="<?= BASE_URL ?>admin/about.php" class="btn-ghost">Cancel</a>
            </div>
          </form>
        </section>

        <aside class="section-card" style="position: sticky; top: 20px;">
          <div class="section-card__header">
            <span class="section-card__title">Security Tips</span>
          </div>
          <div style="padding: 16px 20px;">
            <p style="color: var(--rd-text-muted); margin: 0 0 16px;">Use a memorable passphrase that is hard to guess but easy for you to recall.</p>
            <ul style="list-style: none; padding: 0; margin: 0 0 16px;">
              <li style="display: flex; align-items: center; gap: 10px; padding: 8px 0; color: var(--rd-text-muted); font-size: 14px;">
                <span style="color: var(--rd-success);">&#10003;</span> Use at least 8 to 12 characters
              </li>
              <li style="display: flex; align-items: center; gap: 10px; padding: 8px 0; color: var(--rd-text-muted); font-size: 14px;">
                <span style="color: var(--rd-success);">&#10003;</span> Combine uppercase, numbers & symbols
              </li>
              <li style="display: flex; align-items: center; gap: 10px; padding: 8px 0; color: var(--rd-text-muted); font-size: 14px;">
                <span style="color: var(--rd-success);">&#10003;</span> Avoid names, birthdays, or patterns
              </li>
            </ul>
            <details style="margin-top: 12px;">
              <summary style="cursor: pointer; color: var(--rd-primary); font-weight: 600; font-size: 14px;">One more tip</summary>
              <p style="margin-top: 10px; color: var(--rd-text-muted); font-size: 14px;">Use a password manager for unique passwords across all accounts.</p>
            </details>
          </div>
        </aside>
      </div>
    </main>
  </div>

  <script>
    document.querySelectorAll('.toggle-password').forEach((btn) => {
      btn.addEventListener('click', () => {
        const fieldShell = btn.closest('.field-shell');
        const input = fieldShell ? fieldShell.querySelector('input') : null;
        if (!input) return;

        const isPassword = input.type === 'password';
        input.type = isPassword ? 'text' : 'password';

        const showIcon = btn.querySelector('.icon-show');
        const hideIcon = btn.querySelector('.icon-hide');
        if (showIcon && hideIcon) {
          showIcon.style.display = isPassword ? 'none' : 'block';
          hideIcon.style.display = isPassword ? 'block' : 'none';
        }

        btn.setAttribute('aria-pressed', isPassword ? 'true' : 'false');
        btn.setAttribute('aria-label', isPassword ? 'Hide password' : 'Show password');
      });
    });

    const passwordInput = document.getElementById('new_password');
    const strengthBar = document.getElementById('strengthBar');
    const strengthText = document.getElementById('strengthText');

    if (passwordInput && strengthBar && strengthText) {
      passwordInput.addEventListener('input', () => {
        const val = passwordInput.value;
        let strength = 0;

        if (val.length >= 8) strength++;
        if (/[A-Z]/.test(val)) strength++;
        if (/[0-9]/.test(val)) strength++;
        if (/[^A-Za-z0-9]/.test(val)) strength++;

        const colors = ['#ef4444', '#f59e0b', '#eab308', '#22c55e'];
        const labels = ['Weak', 'Fair', 'Good', 'Strong'];

        const bar = strengthBar.querySelector('div');
        bar.style.width = (strength * 25) + '%';
        bar.style.background = colors[strength - 1] || '#ef4444';
        strengthText.textContent = (labels[strength - 1] || 'Enter a password');
        strengthText.style.color = strength > 0 ? colors[strength - 1] : '#b0a99f';
      });
    }

    const passwordForm = document.getElementById('passwordForm');
    const submitBtn = document.getElementById('submitBtn');

    if (passwordForm) {
      passwordForm.addEventListener('submit', () => {
        const btnText = submitBtn ? submitBtn.querySelector('.btn-text') : null;
        if (btnText) btnText.textContent = 'Updating...';
        if (submitBtn) submitBtn.disabled = true;
      });
    }
  </script>
</body>

</html>
