<?php
/**
 * borrower/profile.php — Borrower Profile Page
 *
 * Displays borrower account info, reading stats, and password change.
 */

$allowed_roles = ['borrower'];
require_once __DIR__ . '/../includes/auth_guard.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/avatar.php';

$pdo     = get_db();
$user_id = (int) $_SESSION['user_id'];
$escape  = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');

$flash_error   = $_SESSION['flash_error']   ?? '';
$flash_success = $_SESSION['flash_success'] ?? '';
unset($_SESSION['flash_error'], $_SESSION['flash_success']);

// ── Handle POST requests ─────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_verify(); // Exits on failure, rotates token on success

  $action = $_POST['action'] ?? '';

  // ── POST: change password ──────────────────────────────────────────────────
  if ($action === 'change_password') {
    $current  = $_POST['current_password']  ?? '';
    $new      = $_POST['new_password']      ?? '';
    $confirm  = $_POST['confirm_password']  ?? '';

    if ($current === '' || $new === '' || $confirm === '') {
      $_SESSION['flash_error'] = 'All password fields are required.';
    } elseif (strlen($new) < 8) {
      $_SESSION['flash_error'] = 'New password must be at least 8 characters.';
    } elseif ($new !== $confirm) {
      $_SESSION['flash_error'] = 'New password and confirmation do not match.';
    } else {
      $stmt = $pdo->prepare('SELECT password_hash FROM Users WHERE id = ? LIMIT 1');
      $stmt->execute([$user_id]);
      $row = $stmt->fetch();
      if (!$row || !password_verify($current, (string)$row['password_hash'])) {
        $_SESSION['flash_error'] = 'Current password is incorrect.';
      } elseif (password_verify($new, (string)$row['password_hash'])) {
        $_SESSION['flash_error'] = 'New password must differ from current password.';
      } else {
        $hash = password_hash($new, PASSWORD_BCRYPT);
        $upd  = $pdo->prepare('UPDATE Users SET password_hash = ? WHERE id = ? LIMIT 1');
        $upd->execute([$hash, $user_id]);
        $_SESSION['flash_success'] = 'Password updated successfully.';
      }
    }
    header('Location: ' . BASE_URL . 'borrower/profile.php');
    exit;

  // ── POST: update name ──────────────────────────────────────────────────────
  } elseif ($action === 'update_name') {
    $new_name = trim($_POST['full_name'] ?? '');
    if ($new_name === '') {
      $_SESSION['flash_error'] = 'Name cannot be empty.';
    } elseif (strlen($new_name) > 120) {
      $_SESSION['flash_error'] = 'Name is too long (max 120 chars).';
    } else {
      $upd = $pdo->prepare('UPDATE Users SET full_name = ? WHERE id = ? LIMIT 1');
      $upd->execute([$new_name, $user_id]);
      $_SESSION['full_name']     = $new_name;
      $_SESSION['flash_success'] = 'Display name updated.';
    }
    header('Location: ' . BASE_URL . 'borrower/profile.php');
    exit;

  // ── POST: avatar upload ────────────────────────────────────────────────────
  } elseif ($action === 'upload_avatar') {
    $cur_stmt = $pdo->prepare('SELECT avatar_url FROM Users WHERE id = ? LIMIT 1');
    $cur_stmt->execute([$user_id]);
    $cur_row = $cur_stmt->fetch();
    $result  = process_avatar_upload($user_id, $cur_row['avatar_url'] ?? null, $_FILES['avatar'] ?? null, $pdo);
    if ($result['success']) {
      $_SESSION['flash_success'] = 'Profile photo updated.';
    } else {
      $_SESSION['flash_error'] = $result['message'];
    }
    header('Location: ' . BASE_URL . 'borrower/profile.php');
    exit;
  }
}

// ── Load user data ───────────────────────────────────────────────────────────
$stmt = $pdo->prepare(
  'SELECT full_name, email, role, is_suspended, created_at, avatar_url FROM Users WHERE id = ? LIMIT 1'
);
$stmt->execute([$user_id]);
$user = $stmt->fetch();

$full_name  = $user['full_name']  ?? $_SESSION['full_name'] ?? '';
$email      = $user['email']      ?? '';
$joined     = !empty($user['created_at']) ? date('F j, Y', strtotime($user['created_at'])) : 'Unknown';
$avatar_url = $user['avatar_url'] ?? null;
$avatar     = get_avatar_display($avatar_url, $full_name);

// ── Reading stats ─────────────────────────────────────────────────────────────
$total_stmt = $pdo->prepare("SELECT COUNT(*) FROM Circulation WHERE user_id = ?");
$total_stmt->execute([$user_id]);
$total_loans = (int)$total_stmt->fetchColumn();

$active_stmt = $pdo->prepare("SELECT COUNT(*) FROM Circulation WHERE user_id = ? AND status IN ('active','overdue')");
$active_stmt->execute([$user_id]);
$active_loans = (int)$active_stmt->fetchColumn();

$fine_stmt = $pdo->prepare("SELECT COALESCE(SUM(fine_amount),0) FROM Circulation WHERE user_id = ? AND fine_paid = 0");
$fine_stmt->execute([$user_id]);
$outstanding_fines = (float)$fine_stmt->fetchColumn();

$res_stmt = $pdo->prepare("SELECT COUNT(*) FROM Reservations WHERE user_id = ? AND status='pending'");
$res_stmt->execute([$user_id]);
$pending_res = (int)$res_stmt->fetchColumn();

$current_page = 'borrower.profile';
$pageTitle    = 'My Profile | Library System';
$extraStyles  = [
  'https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700&display=swap',
  BASE_URL . 'assets/css/borrower-redesign.css',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <?php require_once __DIR__ . '/../includes/head.php'; ?>
  <style>
    /* ── Profile-specific styles ── */
    .prof-wrap {
      max-width: 860px;
      margin: 0 auto;
    }

    .prof-banner {
      height: 120px;
      border-radius: var(--rd-radius) var(--rd-radius) 0 0;
      background: linear-gradient(120deg, #1c1a17 0%, #2a2720 40%, rgba(201,168,76,0.35) 100%),
                  repeating-linear-gradient(45deg, rgba(255,255,255,0.03) 0 10px, transparent 10px 20px);
    }

    .prof-hero {
      background: var(--rd-surface);
      border: 1px solid var(--rd-border);
      border-top: none;
      border-radius: 0 0 var(--rd-radius) var(--rd-radius);
      padding: 0 2rem 2rem;
      margin-bottom: 2rem;
    }

    .prof-identity {
      display: flex;
      align-items: flex-end;
      gap: 1.25rem;
      margin-top: -44px;
      margin-bottom: 1.75rem;
    }

    .prof-avatar-wrap {
      position: relative;
      width: 88px;
      height: 88px;
      border-radius: 50%;
      border: 3px solid var(--rd-primary);
      overflow: hidden;
      flex-shrink: 0;
      cursor: pointer;
      box-shadow: 0 0 0 4px var(--rd-bg), 0 8px 28px rgba(0,0,0,0.5), 0 0 20px rgba(201,168,76,0.15);
      background: rgba(42,39,35,0.9);
      transition: border-color 0.25s ease, box-shadow 0.25s ease, transform 0.25s ease;
    }

    .prof-avatar-wrap:hover {
      border-color: #dfc476;
      box-shadow: 0 0 0 4px var(--rd-bg), 0 12px 32px rgba(0,0,0,0.6), 0 0 28px rgba(201,168,76,0.3);
      transform: scale(1.04);
    }

    .prof-avatar-initials {
      width: 100%;
      height: 100%;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 2rem;
      font-weight: 800;
      color: var(--rd-primary);
      background: linear-gradient(145deg, rgba(201,168,76,0.15) 0%, rgba(201,168,76,0.05) 100%);
      border-radius: 50%;
    }

    .prof-avatar-img {
      width: 100%;
      height: 100%;
      object-fit: cover;
      border-radius: 50%;
    }

    .prof-avatar-overlay {
      position: absolute;
      inset: 0;
      background: rgba(0,0,0,0.6);
      display: flex;
      align-items: center;
      justify-content: center;
      color: #fff;
      font-size: 0.7rem;
      font-weight: 600;
      letter-spacing: 0.05em;
      opacity: 0;
      transition: opacity 0.2s;
      pointer-events: none;
      border-radius: 50%;
    }

    .prof-avatar-wrap:hover .prof-avatar-overlay { opacity: 1; }

    .prof-name-block {
      flex: 1;
      padding-bottom: 0.25rem;
      min-width: 0;
    }

    .prof-name-block h2 {
      margin: 0 0 0.25rem;
      font-size: 1.6rem;
      font-weight: 800;
      color: var(--rd-text-bold, #ffffff);
      text-shadow: 0 1px 4px rgba(0,0,0,0.5);
      min-height: 1.6rem;
      line-height: 1.2;
    }

    .prof-name-block p {
      margin: 0;
      color: var(--rd-text-muted);
      font-size: 0.9rem;
    }

    .prof-badge {
      display: inline-flex;
      align-items: center;
      gap: 0.4rem;
      padding: 0.3rem 0.8rem;
      border-radius: 999px;
      font-size: 0.75rem;
      font-weight: 700;
      background: rgba(16,185,129,0.12);
      color: #34d399;
      border: 1px solid rgba(16,185,129,0.25);
      margin-top: 0.5rem;
    }

    .prof-badge::before {
      content: '';
      width: 7px;
      height: 7px;
      border-radius: 50%;
      background: currentColor;
      box-shadow: 0 0 0 3px rgba(52,211,153,0.2);
    }

    /* Stats row */
    .prof-stats {
      display: grid;
      grid-template-columns: repeat(4, 1fr);
      gap: 1rem;
      margin-bottom: 2rem;
    }

    @media (max-width: 780px) {
      .prof-stats { grid-template-columns: repeat(2, 1fr); gap: 0.85rem; }
    }

    @media (max-width: 400px) {
      .prof-stats { grid-template-columns: 1fr; }
    }

    .prof-stat-card {
      background: var(--rd-surface);
      border: 1px solid var(--rd-border);
      border-radius: 16px;
      padding: 1.5rem 0.85rem;
      text-align: center;
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      gap: 0.35rem;
      min-height: 100px;
      transition: transform 0.25s ease, box-shadow 0.25s ease;
    }

    .prof-stat-card:hover {
      transform: translateY(-3px);
      box-shadow: var(--rd-shadow-hover);
    }

    .prof-stat-num {
      font-size: clamp(1.5rem, 3vw, 2rem);
      font-weight: 800;
      color: var(--rd-primary);
      line-height: 1.1;
      word-break: break-word;
      max-width: 100%;
      overflow-wrap: anywhere;
    }

    .prof-stat-label {
      font-size: 0.75rem;
      color: var(--rd-text-muted);
      font-weight: 600;
      text-transform: uppercase;
      letter-spacing: 0.06em;
      line-height: 1.2;
    }

    /* Panels */
    .prof-panels {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 1.5rem;
    }

    @media (max-width: 680px) {
      .prof-panels { grid-template-columns: 1fr; }
    }

    .prof-panel {
      background: var(--rd-surface);
      border: 1px solid var(--rd-border);
      border-radius: var(--rd-radius);
      padding: 1.75rem;
    }

    .prof-panel h3 {
      margin: 0 0 1.25rem;
      font-size: 1.1rem;
      font-weight: 700;
      color: var(--rd-primary);
      display: flex;
      align-items: center;
      gap: 0.5rem;
    }

    .prof-field {
      margin-bottom: 1rem;
    }

    .prof-field label {
      display: block;
      font-size: 0.8rem;
      font-weight: 600;
      color: var(--rd-text-muted);
      text-transform: uppercase;
      letter-spacing: 0.05em;
      margin-bottom: 0.4rem;
    }

    .prof-field input[type="text"],
    .prof-field input[type="password"] {
      width: 100%;
      padding: 0.7rem 1rem;
      background: rgba(255,255,255,0.05);
      border: 1px solid var(--rd-border);
      border-radius: 10px;
      color: var(--rd-text);
      font-family: inherit;
      font-size: 0.95rem;
      box-sizing: border-box;
      transition: border-color 0.2s, box-shadow 0.2s;
    }

    .prof-field input:focus {
      outline: none;
      border-color: var(--rd-primary);
      box-shadow: 0 0 0 3px rgba(201,168,76,0.15);
    }

    .prof-field .prof-static {
      padding: 0.7rem 0;
      color: var(--rd-text);
      font-size: 0.95rem;
      border-bottom: 1px solid rgba(255,255,255,0.05);
    }

    .prof-submit {
      width: 100%;
      padding: 0.8rem;
      border: none;
      border-radius: 12px;
      font-family: inherit;
      font-size: 0.95rem;
      font-weight: 700;
      cursor: pointer;
      background: linear-gradient(135deg, var(--rd-primary), #b8942d);
      color: #1c1a17;
      box-shadow: 0 6px 14px rgba(201,168,76,0.25);
      transition: transform 0.2s ease, box-shadow 0.2s ease;
      margin-top: 0.5rem;
    }

    .prof-submit:hover {
      transform: translateY(-2px);
      box-shadow: 0 10px 20px rgba(201,168,76,0.35);
    }

    .prof-info-row {
      display: flex;
      align-items: center;
      padding: 0.85rem 0;
      border-bottom: 1px solid rgba(255,255,255,0.06);
      font-size: 0.92rem;
    }

    .prof-info-row:last-child { border-bottom: none; }
    .prof-info-label {
      color: var(--rd-text-muted);
      flex: 0 0 160px;
      font-weight: 500;
    }
    .prof-info-val   {
      color: var(--rd-text-bold);
      font-weight: 600;
      flex: 1;
      text-align: right;
    }

    /* Responsive hero for mobile */
    @media (max-width: 480px) {
      .prof-identity {
        flex-direction: column;
        align-items: flex-start;
        gap: 1rem;
      }
      .prof-avatar-wrap {
        width: 72px;
        height: 72px;
      }
      .prof-hero {
        padding: 0 1.25rem 1.5rem;
      }
      .prof-info-label {
        flex: 0 0 120px;
        font-size: 0.82rem;
      }
      .prof-info-val {
        font-size: 0.85rem;
      }
      .prof-stat-card {
        padding: 1.15rem 0.65rem;
        min-height: 85px;
      }
    }

    /* Flash */
    .flash { padding: 1rem 1.25rem; border-radius: 12px; margin-bottom: 1.5rem; font-weight: 500; }
    .flash-error   { background: rgba(239,68,68,0.12); border: 1px solid rgba(239,68,68,0.3); color: #fca5a5; }
    .flash-success { background: rgba(16,185,129,0.12); border: 1px solid rgba(16,185,129,0.3); color: #6ee7b7; }

    /* sr-only */
    .sr-only { position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0; }
  </style>
</head>

<body class="dashboard-redesign borrower-dashboard-new">
  <div class="app-shell">
    <?php require_once __DIR__ . '/../includes/sidebar-borrower.php'; ?>

    <main class="main-content">
      <div class="prof-wrap">

        <!-- Header -->
        <div class="rd-header" style="margin-bottom:1.5rem;">
          <div>
            <h1>My Profile</h1>
            <p>Manage your account info and security settings.</p>
          </div>
          <a class="rd-btn rd-btn-primary" href="<?= $escape(BASE_URL . 'borrower/index.php') ?>">
            ← Dashboard
          </a>
        </div>

        <?php if ($flash_error !== ''): ?>
          <div class="flash flash-error" role="alert"><?= $escape($flash_error) ?></div>
        <?php endif; ?>
        <?php if ($flash_success !== ''): ?>
          <div class="flash flash-success" role="alert"><?= $escape($flash_success) ?></div>
        <?php endif; ?>

        <!-- Hero Card -->
        <div class="prof-banner"></div>
        <div class="prof-hero">
          <div class="prof-identity">
            <!-- Avatar (click to upload) -->
            <form method="POST" action="<?= $escape(BASE_URL . 'borrower/profile.php') ?>" enctype="multipart/form-data" id="avatarForm">
              <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
              <input type="hidden" name="action" value="upload_avatar">
              <input type="file" name="avatar" id="avatarFile" class="sr-only" accept="image/jpeg,image/png,image/gif,image/webp"
                     onchange="document.getElementById('avatarForm').submit()">
              <div class="prof-avatar-wrap" onclick="document.getElementById('avatarFile').click()" title="Change photo">
                <?php if ($avatar['type'] === 'image' && !empty($avatar['url'])): ?>
                  <img class="prof-avatar-img" src="<?= $escape($avatar['url']) ?>" alt="<?= $escape($full_name) ?> profile photo">
                <?php else: ?>
                  <div class="prof-avatar-initials"><?= $escape($avatar['initials']) ?></div>
                <?php endif; ?>
                <div class="prof-avatar-overlay">📷 Change</div>
              </div>
            </form>

            <div class="prof-name-block">
              <h2><?= $escape($full_name) ?></h2>
              <p><?= $escape($email) ?></p>
              <span class="prof-badge">Active Borrower</span>
            </div>
          </div>

          <!-- Account Info Row -->
          <div class="prof-info-row"><span class="prof-info-label">Member Since</span><span class="prof-info-val"><?= $escape($joined) ?></span></div>
          <div class="prof-info-row"><span class="prof-info-label">Role</span><span class="prof-info-val">Borrower</span></div>
          <div class="prof-info-row"><span class="prof-info-label">Email</span><span class="prof-info-val"><?= $escape($email ?: '—') ?></span></div>
          <div class="prof-info-row"><span class="prof-info-label">Outstanding Fines</span>
            <span class="prof-info-val" style="color:<?= $outstanding_fines > 0 ? 'var(--rd-danger)' : 'var(--rd-success)' ?>">
              ₱<?= number_format($outstanding_fines, 2) ?>
            </span>

          </div>
        </div>

        <!-- Stats -->
        <div class="prof-stats rd-stagger">

          <div class="prof-stat-card">
            <div class="prof-stat-num"><?= $total_loans ?></div>
            <div class="prof-stat-label">Total Loans</div>
          </div>
          <div class="prof-stat-card">
            <div class="prof-stat-num"><?= $active_loans ?></div>
            <div class="prof-stat-label">Active Loans</div>
          </div>
          <div class="prof-stat-card">
            <div class="prof-stat-num"><?= $pending_res ?></div>
            <div class="prof-stat-label">Reservations</div>
          </div>
          <div class="prof-stat-card">
            <div class="prof-stat-num" style="color:<?= $outstanding_fines > 0 ? 'var(--rd-danger)' : 'var(--rd-success)' ?>">
              ₱<?= number_format($outstanding_fines, 2) ?>
            </div>

            <div class="prof-stat-label">Unpaid Fines</div>
          </div>
        </div>

        <!-- Panels -->
        <div class="prof-panels">
          <!-- Update Name -->
          <div class="prof-panel">
            <h3>
              <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
              Update Name
            </h3>
            <form method="POST" action="<?= $escape(BASE_URL . 'borrower/profile.php') ?>">
              <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
              <input type="hidden" name="action" value="update_name">
              <div class="prof-field">
                <label for="full_name">Display Name</label>
                <input type="text" id="full_name" name="full_name"
                       value="<?= $escape($full_name) ?>"
                       required maxlength="120" autocomplete="name">
              </div>
              <div class="prof-field">
                <label>Email</label>
                <div class="prof-static"><?= $escape($email ?: 'Not set') ?></div>
              </div>
              <button type="submit" class="prof-submit">Save Name</button>
            </form>
          </div>

          <!-- Change Password -->
          <div class="prof-panel">
            <h3>
              <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
              Change Password
            </h3>
            <form method="POST" action="<?= $escape(BASE_URL . 'borrower/profile.php') ?>" id="pwForm">
              <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
              <input type="hidden" name="action" value="change_password">
              <div class="prof-field">
                <label for="current_password">Current Password</label>
                <input type="password" id="current_password" name="current_password" required autocomplete="current-password">
              </div>
              <div class="prof-field">
                <label for="new_password">New Password</label>
                <input type="password" id="new_password" name="new_password" required minlength="8" autocomplete="new-password">
              </div>
              <div class="prof-field">
                <label for="confirm_password">Confirm Password</label>
                <input type="password" id="confirm_password" name="confirm_password" required autocomplete="new-password">
              </div>
              <button type="submit" class="prof-submit">Update Password</button>
            </form>
          </div>
        </div><!-- /.prof-panels -->

      </div><!-- /.prof-wrap -->
    </main>
  </div>
</body>
</html>
