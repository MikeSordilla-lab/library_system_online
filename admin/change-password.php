<?php

$allowed_roles = ['admin'];
require_once __DIR__ . '/../includes/auth_guard.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/admin.php';
require_once __DIR__ . '/../includes/csrf.php';

$pdo = get_db();
ensure_superadmin_support($pdo);

$actor_id = (int) ($_SESSION['user_id'] ?? 0);
$current_page = 'admin.change-password';
$pageTitle = 'Change Password | Library System';
$self_url = BASE_URL . 'admin/change-password.php';

$flash_success = $_SESSION['flash_success'] ?? '';
$flash_error = $_SESSION['flash_error_msg'] ?? '';
unset($_SESSION['flash_success'], $_SESSION['flash_error_msg']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_verify();

  $current_password = (string) ($_POST['current_password'] ?? '');
  $new_password = (string) ($_POST['new_password'] ?? '');
  $confirm_password = (string) ($_POST['confirm_password'] ?? '');

  if ($current_password === '' || $new_password === '' || $confirm_password === '') {
    $_SESSION['flash_error_msg'] = 'All password fields are required.';
    header('Location: ' . $self_url);
    exit;
  }

  if (strlen($new_password) < 8) {
    $_SESSION['flash_error_msg'] = 'New password must be at least 8 characters.';
    header('Location: ' . $self_url);
    exit;
  }

  if ($new_password !== $confirm_password) {
    $_SESSION['flash_error_msg'] = 'New password and confirmation do not match.';
    header('Location: ' . $self_url);
    exit;
  }

  try {
    $pdo->beginTransaction();

    $stmt = $pdo->prepare('SELECT id, password_hash FROM Users WHERE id = ? FOR UPDATE');
    $stmt->execute([$actor_id]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($current_password, $user['password_hash'])) {
      $pdo->rollBack();
      $_SESSION['flash_error_msg'] = 'Current password is incorrect.';
      header('Location: ' . $self_url);
      exit;
    }

    if (password_verify($new_password, $user['password_hash'])) {
      $pdo->rollBack();
      $_SESSION['flash_error_msg'] = 'New password must be different from current password.';
      header('Location: ' . $self_url);
      exit;
    }

    $new_hash = password_hash($new_password, PASSWORD_BCRYPT);
    $upd = $pdo->prepare('UPDATE Users SET password_hash = ?, updated_at = NOW() WHERE id = ? LIMIT 1');
    $upd->execute([$new_hash, $actor_id]);

    log_admin_action($pdo, [
      'actor_id' => $actor_id,
      'actor_role' => 'admin',
      'action_type' => 'password_change',
      'target_entity' => 'Users',
      'target_id' => $actor_id,
      'outcome' => 'success',
    ]);

    $pdo->commit();
    $_SESSION['flash_success'] = 'Password updated successfully.';
  } catch (PDOException $e) {
    if ($pdo->inTransaction()) {
      $pdo->rollBack();
    }

    error_log('[admin/change-password.php] change_password DB error: ' . $e->getMessage());
    $_SESSION['flash_error_msg'] = 'A server error occurred while changing password.';
  }

  header('Location: ' . $self_url);
  exit;
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <?php require_once __DIR__ . '/../includes/head.php'; ?>
  <style>
    .admin-password-page {
      --ink: #0f0e0c;
      --paper: #f7f4ee;
      --cream: #ede9e0;
      --accent: #c8401a;
      --accent-dark: #9d3418;
      --muted: #8a8278;
      --line: #d9d2c9;
      --success: #2f855a;
      --warning: #b7791f;
      --danger: #c53030;
      --focus: rgba(200, 64, 26, 0.2);
    }

    .admin-password-page .content-wrapper {
      padding: 32px;
      background: linear-gradient(155deg, var(--paper) 0%, #f2eee7 100%);
      border-radius: 20px;
    }

    .admin-password-page .password-container {
      max-width: 980px;
      margin: 0 auto;
      display: grid;
      grid-template-columns: minmax(0, 1.35fr) minmax(280px, 1fr);
      gap: 20px;
      align-items: start;
    }

    .admin-password-page .card {
      background: #fff;
      padding: 34px;
      border-radius: 16px;
      box-shadow: 0 12px 28px rgba(15, 14, 12, 0.08);
      border: 1px solid rgba(15, 14, 12, 0.06);
    }

    .admin-password-page .title {
      margin: 0;
      font-size: 34px;
      font-weight: 800;
      line-height: 1.1;
      color: var(--ink);
    }

    .admin-password-page .subtitle {
      color: var(--muted);
      margin: 10px 0 24px;
      font-size: 14px;
    }

    .admin-password-page .input-group {
      margin-bottom: 20px;
    }

    .admin-password-page .input-group label {
      font-weight: 700;
      color: #28241f;
      margin-bottom: 7px;
      display: block;
      font-size: 14px;
    }

    .admin-password-page .field-shell {
      position: relative;
    }

    .admin-password-page .field-icon {
      position: absolute;
      left: 12px;
      top: 50%;
      transform: translateY(-50%);
      width: 18px;
      height: 18px;
      color: #6f665d;
      pointer-events: none;
      display: inline-flex;
      align-items: center;
      justify-content: center;
    }

    .admin-password-page .field-icon svg {
      width: 100%;
      height: 100%;
      fill: currentColor;
    }

    .admin-password-page .password-input {
      width: 100%;
      padding: 14px 44px 14px 40px;
      border-radius: 10px;
      border: 1px solid var(--line);
      color: var(--ink);
      background: #fff;
      transition: border-color 0.2s ease, box-shadow 0.2s ease, background-color 0.2s ease;
    }

    .admin-password-page .password-input::placeholder {
      color: #6e665d;
      opacity: 1;
    }

    .admin-password-page .password-input:focus {
      outline: none;
      border-color: var(--accent);
      box-shadow: 0 0 0 4px var(--focus);
      background-color: #fffcf8;
    }

    .admin-password-page .toggle-password {
      position: absolute;
      right: 6px;
      top: 50%;
      transform: translateY(-50%);
      width: 34px;
      height: 34px;
      border-radius: 8px;
      border: 1px solid transparent;
      background: transparent;
      color: #6f665d;
      cursor: pointer;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      transition: background-color 0.2s ease, color 0.2s ease, border-color 0.2s ease;
    }

    .admin-password-page .toggle-password:hover {
      background: #f5f1ea;
      border-color: var(--line);
      color: var(--ink);
    }

    .admin-password-page .toggle-password svg {
      width: 18px;
      height: 18px;
      fill: currentColor;
    }

    .admin-password-page .strength {
      height: 8px;
      background: #e6dfd5;
      border-radius: 10px;
      margin-top: 10px;
      overflow: hidden;
    }

    .admin-password-page .strength-bar {
      height: 100%;
      width: 0;
      background: var(--danger);
      border-radius: 10px;
      transition: width 0.35s ease, background-color 0.25s ease;
    }

    .admin-password-page .strength-text {
      font-size: 13px;
      color: var(--danger);
      margin-top: 6px;
      display: inline-block;
      font-weight: 600;
    }

    .admin-password-page .strength-text.strength-fair,
    .admin-password-page .strength-text.strength-good {
      color: var(--warning);
    }

    .admin-password-page .strength-text.strength-strong {
      color: var(--success);
    }

    .admin-password-page .actions {
      display: flex;
      gap: 10px;
      margin-top: 24px;
    }

    .admin-password-page .btn-primary {
      position: relative;
      isolation: isolate;
      overflow: hidden;
      background: linear-gradient(145deg, var(--accent) 0%, var(--accent-dark) 100%);
      color: #fff;
      padding: 12px;
      border-radius: 10px;
      border: none;
      flex: 1;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      gap: 8px;
      font-weight: 600;
      cursor: pointer;
      box-shadow: 0 10px 18px rgba(157, 52, 24, 0.25);
      transition: transform 0.15s ease, box-shadow 0.2s ease, filter 0.2s ease;
    }

    .admin-password-page .btn-primary:hover {
      transform: translateY(-1px);
      box-shadow: 0 14px 24px rgba(157, 52, 24, 0.28);
      filter: saturate(1.05);
    }

    .admin-password-page .btn-primary:active {
      transform: translateY(0);
      box-shadow: 0 6px 14px rgba(157, 52, 24, 0.22);
    }

    .admin-password-page .btn-primary .ripple {
      position: absolute;
      border-radius: 50%;
      transform: scale(0);
      background: rgba(255, 255, 255, 0.35);
      animation: admin-password-ripple 0.55s linear;
      pointer-events: none;
      z-index: 0;
    }

    .admin-password-page .btn-primary .btn-text,
    .admin-password-page .btn-primary .btn-loader {
      position: relative;
      z-index: 1;
    }

    .admin-password-page .btn-primary:disabled {
      opacity: 0.85;
      cursor: wait;
    }

    .admin-password-page .btn-secondary {
      background: transparent;
      border: 1px solid var(--line);
      color: #554d44;
      padding: 12px;
      border-radius: 10px;
      text-decoration: none;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      min-width: 105px;
      font-weight: 600;
      transition: background-color 0.2s ease, border-color 0.2s ease, color 0.2s ease;
    }

    .admin-password-page .btn-secondary:hover {
      background: #f6f1ea;
      border-color: #cbbfb2;
      color: #2f2b26;
    }

    .admin-password-page .btn-loader {
      width: 14px;
      height: 14px;
      border: 2px solid rgba(255, 255, 255, 0.45);
      border-top-color: #fff;
      border-radius: 50%;
      animation: admin-password-spin 0.7s linear infinite;
    }

    .admin-password-page .hidden {
      display: none;
    }

    .admin-password-page .tips-card {
      padding: 22px;
      background: #fcfaf6;
      border-radius: 16px;
      border: 1px solid rgba(15, 14, 12, 0.08);
      box-shadow: 0 8px 18px rgba(15, 14, 12, 0.06);
    }

    .admin-password-page .tips-card h3 {
      margin: 0 0 10px;
      font-size: 19px;
      color: var(--ink);
    }

    .admin-password-page .tips-card p {
      color: var(--muted);
      margin: 0 0 12px;
      font-size: 13px;
      line-height: 1.45;
    }

    .admin-password-page .tips-card ul {
      margin: 0;
      padding: 0;
      list-style: none;
      color: #3d352d;
      display: grid;
      gap: 10px;
    }

    .admin-password-page .tips-card li {
      display: flex;
      align-items: center;
      gap: 8px;
      line-height: 1.3;
    }

    .admin-password-page .tip-icon {
      width: 18px;
      height: 18px;
      border-radius: 999px;
      background: #ecf7ef;
      color: #1e7a49;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      flex-shrink: 0;
    }

    .admin-password-page .tip-icon svg {
      width: 12px;
      height: 12px;
      fill: currentColor;
    }

    .admin-password-page .tips-card details {
      margin-top: 12px;
      border-top: 1px solid #e4ddd3;
      padding-top: 10px;
    }

    .admin-password-page .tips-card summary {
      color: #5c544b;
      cursor: pointer;
      font-size: 13px;
      font-weight: 600;
      user-select: none;
    }

    @keyframes admin-password-spin {
      to {
        transform: rotate(360deg);
      }
    }

    @keyframes admin-password-ripple {
      to {
        transform: scale(4);
        opacity: 0;
      }
    }

    @media (prefers-reduced-motion: reduce) {

      .admin-password-page .password-input,
      .admin-password-page .btn-primary,
      .admin-password-page .btn-secondary,
      .admin-password-page .strength-bar {
        transition: none;
      }

      .admin-password-page .btn-primary .ripple,
      .admin-password-page .btn-loader {
        animation: none;
      }
    }

    @media (max-width: 768px) {
      .admin-password-page .content-wrapper {
        padding: 20px;
      }

      .admin-password-page .password-container {
        grid-template-columns: 1fr;
        gap: 16px;
      }

      .admin-password-page .card,
      .admin-password-page .tips-card {
        padding: 20px;
      }

      .admin-password-page .title {
        font-size: 30px;
      }

      .admin-password-page .actions {
        flex-wrap: wrap;
      }

      .admin-password-page .btn-secondary {
        width: 100%;
      }
    }
  </style>
</head>

<body>
  <div class="app-shell">
    <?php require_once __DIR__ . '/../includes/sidebar-admin.php'; ?>

    <main class="main-content admin-password-page">
      <div class="content-wrapper">
        <div class="password-container">
          <div class="card">
            <h1 class="title">Change Password</h1>
            <p class="subtitle">Keep your account secure by using a strong password.</p>

            <form method="post" action="<?= htmlspecialchars($self_url, ENT_QUOTES, 'UTF-8') ?>" id="passwordForm">
              <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">

              <div class="input-group">
                <label for="current_password">Current Password</label>
                <div class="field-shell">
                  <span class="field-icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24">
                      <path d="M17 8h-1V6a4 4 0 0 0-8 0v2H7a2 2 0 0 0-2 2v9a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2v-9a2 2 0 0 0-2-2zM10 6a2 2 0 1 1 4 0v2h-4V6zm2 9a1.5 1.5 0 1 1 0-3 1.5 1.5 0 0 1 0 3z" />
                    </svg>
                  </span>
                  <input class="password-input" type="password" id="current_password" name="current_password" required placeholder="Enter current password" autocomplete="current-password">
                  <button type="button" class="toggle-password" aria-label="Show current password" aria-pressed="false" title="Show password">
                    <svg viewBox="0 0 24 24" aria-hidden="true">
                      <path d="M12 5c-5.6 0-9.7 4.7-10.9 6.3-.1.2-.1.4 0 .6C2.3 13.5 6.4 18.2 12 18.2s9.7-4.7 10.9-6.3c.1-.2.1-.4 0-.6C21.7 9.7 17.6 5 12 5zm0 11a5 5 0 1 1 0-10 5 5 0 0 1 0 10zm0-8a3 3 0 1 0 0 6 3 3 0 0 0 0-6z" />
                    </svg>
                  </button>
                </div>
              </div>

              <div class="input-group">
                <label for="new_password">New Password</label>
                <div class="field-shell">
                  <span class="field-icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24">
                      <path d="M17 8h-1V6a4 4 0 0 0-8 0v2H7a2 2 0 0 0-2 2v9a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2v-9a2 2 0 0 0-2-2zM10 6a2 2 0 1 1 4 0v2h-4V6zm2 9a1.5 1.5 0 1 1 0-3 1.5 1.5 0 0 1 0 3z" />
                    </svg>
                  </span>
                  <input class="password-input" type="password" id="new_password" name="new_password" required placeholder="Create new password" minlength="8" autocomplete="new-password">
                  <button type="button" class="toggle-password" aria-label="Show new password" aria-pressed="false" title="Show password">
                    <svg viewBox="0 0 24 24" aria-hidden="true">
                      <path d="M12 5c-5.6 0-9.7 4.7-10.9 6.3-.1.2-.1.4 0 .6C2.3 13.5 6.4 18.2 12 18.2s9.7-4.7 10.9-6.3c.1-.2.1-.4 0-.6C21.7 9.7 17.6 5 12 5zm0 11a5 5 0 1 1 0-10 5 5 0 0 1 0 10zm0-8a3 3 0 1 0 0 6 3 3 0 0 0 0-6z" />
                    </svg>
                  </button>
                </div>

                <div class="strength">
                  <div class="strength-bar" id="strengthBar"></div>
                </div>
                <small class="strength-text" id="strengthText">Weak password</small>
              </div>

              <div class="input-group">
                <label for="confirm_password">Confirm Password</label>
                <div class="field-shell">
                  <span class="field-icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24">
                      <path d="M17 8h-1V6a4 4 0 0 0-8 0v2H7a2 2 0 0 0-2 2v9a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2v-9a2 2 0 0 0-2-2zM10 6a2 2 0 1 1 4 0v2h-4V6zm2 9a1.5 1.5 0 1 1 0-3 1.5 1.5 0 0 1 0 3z" />
                    </svg>
                  </span>
                  <input class="password-input" type="password" id="confirm_password" name="confirm_password" required placeholder="Confirm password" minlength="8" autocomplete="new-password">
                  <button type="button" class="toggle-password" aria-label="Show confirm password" aria-pressed="false" title="Show password">
                    <svg viewBox="0 0 24 24" aria-hidden="true">
                      <path d="M12 5c-5.6 0-9.7 4.7-10.9 6.3-.1.2-.1.4 0 .6C2.3 13.5 6.4 18.2 12 18.2s9.7-4.7 10.9-6.3c.1-.2.1-.4 0-.6C21.7 9.7 17.6 5 12 5zm0 11a5 5 0 1 1 0-10 5 5 0 0 1 0 10zm0-8a3 3 0 1 0 0 6 3 3 0 0 0 0-6z" />
                    </svg>
                  </button>
                </div>
              </div>

              <div class="actions">
                <button type="submit" class="btn-primary" id="submitBtn">
                  <span class="btn-text">Update Password</span>
                  <span class="btn-loader hidden" aria-hidden="true"></span>
                </button>
                <a href="<?= BASE_URL ?>admin/about.php" class="btn-secondary">Cancel</a>
              </div>
            </form>
          </div>

          <div class="tips-card">
            <h3>Security Tips</h3>
            <p>Use a memorable passphrase that is hard to guess but easy for you to recall.</p>
            <ul>
              <li>
                <span class="tip-icon" aria-hidden="true">
                  <svg viewBox="0 0 24 24">
                    <path d="M9 16.2 4.8 12l-1.4 1.4L9 19 21 7l-1.4-1.4z" />
                  </svg>
                </span>
                Use at least 8 to 12 characters
              </li>
              <li>
                <span class="tip-icon" aria-hidden="true">
                  <svg viewBox="0 0 24 24">
                    <path d="M9 16.2 4.8 12l-1.4 1.4L9 19 21 7l-1.4-1.4z" />
                  </svg>
                </span>
                Combine uppercase, numbers, and symbols
              </li>
              <li>
                <span class="tip-icon" aria-hidden="true">
                  <svg viewBox="0 0 24 24">
                    <path d="M9 16.2 4.8 12l-1.4 1.4L9 19 21 7l-1.4-1.4z" />
                  </svg>
                </span>
                Avoid names, birthdays, or easy patterns
              </li>
            </ul>
            <details>
              <summary>Show one more tip</summary>
              <ul>
                <li>
                  <span class="tip-icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24">
                      <path d="M9 16.2 4.8 12l-1.4 1.4L9 19 21 7l-1.4-1.4z" />
                    </svg>
                  </span>
                  Use a password manager for unique passwords
                </li>
              </ul>
            </details>
          </div>
        </div>
      </div>
    </main>
  </div>

  <?php if ($flash_success !== ''): ?>
    <script>
      Swal.fire({
        icon: 'success',
        title: 'Success',
        text: <?= json_encode($flash_success) ?>
      });
    </script>
  <?php endif; ?>

  <?php if ($flash_error !== ''): ?>
    <script>
      Swal.fire({
        icon: 'error',
        title: 'Error',
        text: <?= json_encode($flash_error) ?>
      });
    </script>
  <?php endif; ?>

  <script>
    document.querySelectorAll('.toggle-password').forEach((icon) => {
      const toggle = () => {
        const fieldShell = icon.closest('.field-shell');
        const input = fieldShell ? fieldShell.querySelector('input') : null;
        if (!input) return;

        input.type = input.type === 'password' ? 'text' : 'password';
        icon.setAttribute('aria-pressed', input.type === 'text' ? 'true' : 'false');
        icon.setAttribute('aria-label', input.type === 'text' ? 'Hide password' : 'Show password');
        icon.title = input.type === 'text' ? 'Hide password' : 'Show password';
      };

      icon.addEventListener('click', toggle);
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

        const colors = ['#c53030', '#d69e2e', '#b7791f', '#2f855a'];
        const labels = ['Weak', 'Fair', 'Good', 'Strong'];
        const classes = ['strength-weak', 'strength-fair', 'strength-good', 'strength-strong'];

        strengthBar.style.width = (strength * 25) + '%';
        strengthBar.style.background = colors[strength - 1] || 'red';
        strengthText.textContent = (labels[strength - 1] || 'Weak') + ' password';
        strengthText.className = 'strength-text ' + (classes[strength - 1] || 'strength-weak');
      });
    }

    const passwordForm = document.getElementById('passwordForm');
    const submitBtn = document.getElementById('submitBtn');

    if (submitBtn) {
      submitBtn.addEventListener('click', (event) => {
        const ripple = document.createElement('span');
        const rect = submitBtn.getBoundingClientRect();
        const size = Math.max(rect.width, rect.height);
        ripple.className = 'ripple';
        ripple.style.width = size + 'px';
        ripple.style.height = size + 'px';
        ripple.style.left = (event.clientX - rect.left - size / 2) + 'px';
        ripple.style.top = (event.clientY - rect.top - size / 2) + 'px';
        submitBtn.appendChild(ripple);
        window.setTimeout(() => {
          ripple.remove();
        }, 550);
      });
    }

    if (passwordForm) {
      passwordForm.addEventListener('submit', () => {
        const btn = document.getElementById('submitBtn');
        if (!btn) return;

        const btnText = btn.querySelector('.btn-text');
        const btnLoader = btn.querySelector('.btn-loader');

        if (btnText) btnText.textContent = 'Updating...';
        if (btnLoader) btnLoader.classList.remove('hidden');
        btn.disabled = true;
      });
    }
  </script>
</body>

</html>