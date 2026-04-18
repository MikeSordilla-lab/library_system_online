<?php

$allowed_roles = ['admin'];
require_once __DIR__ . '/../includes/auth_guard.php';
require_once __DIR__ . '/../includes/avatar.php';

$current_page = 'admin.about';
$pageTitle = 'Admin Profile | Library System';

$escape = fn($v) => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');

$actor_id = (int) ($_SESSION['user_id'] ?? 0);

$admin = [
  'name' => (string) ($_SESSION['full_name'] ?? 'Administrator'),
  'role' => 'Administrator',
  'status' => 'Active',
  'email' => '',
  'location' => 'Tangub City, Philippines',
  'joined' => '',
  'bio' => 'Responsible for managing system users, monitoring activity, and maintaining smooth library operations.',
  'permissions' => 'Administrative Access',
  'avatar_url' => null,
];

try {
  $pdo = get_db();
  $stmt = $pdo->prepare('SELECT full_name, email, role, is_suspended, is_superadmin, created_at, avatar_url FROM Users WHERE id = ? LIMIT 1');
  $stmt->execute([$actor_id]);
  $row = $stmt->fetch();

  if ($row) {
    $admin['name'] = (string) ($row['full_name'] ?: $admin['name']);
    $admin['email'] = (string) ($row['email'] ?? '');
    $admin['role'] = ucfirst((string) ($row['role'] ?? 'admin'));
    $admin['status'] = ((int) ($row['is_suspended'] ?? 0) === 1) ? 'Suspended' : 'Active';
    $admin['joined'] = !empty($row['created_at']) ? date('F Y', strtotime((string) $row['created_at'])) : '';
    $admin['permissions'] = ((int) ($row['is_superadmin'] ?? 0) === 1) ? 'Superadmin Access' : 'Administrative Access';
    $admin['avatar_url'] = !empty($row['avatar_url']) ? (string) $row['avatar_url'] : null;
  }
} catch (PDOException $e) {
  error_log('[admin/about.php] Profile load DB error: ' . $e->getMessage());
}

$avatar = get_avatar_display($admin['avatar_url'], $admin['name']);
$initials = $avatar['initials'] !== '' ? $avatar['initials'] : strtoupper(substr($admin['name'], 0, 1));
$is_active = $admin['status'] === 'Active';

$skills = ['PHP', 'MySQL', 'CSS'];

?>

<!DOCTYPE html>
<html lang="en">

<head>
  <?php require_once __DIR__ . '/../includes/head.php'; ?>
  <style>
    .admin-profile-page {
      --ink: #0f0e0c;
      --paper: #f7f4ee;
      --cream: #ede9e0;
      --accent: #c8401a;
      --accent-dark: #9d3418;
      --gold: #c9a84c;
      --sage: #4a6741;
      --muted: #8a8278;
      --line: #ddd5ca;
      --surface: #fffdfa;
      --ok: #2f855a;
      --ok-bg: #ebf7ef;
      --shadow-soft: 0 12px 28px rgba(15, 14, 12, 0.08);
      --shadow-hover: 0 18px 38px rgba(15, 14, 12, 0.13);
    }

    .admin-profile-page {
      padding: 30px;
      background:
        radial-gradient(circle at 12% 0%, rgba(201, 168, 76, 0.14) 0%, rgba(201, 168, 76, 0) 38%),
        linear-gradient(165deg, #f8f5ef 0%, #f1ece3 100%);
    }

    .admin-profile-page .profile-layout {
      max-width: 1060px;
      margin: 0 auto;
      display: grid;
      gap: 18px;
    }

    .admin-profile-page .page-header {
      margin-bottom: 4px;
    }

    .admin-profile-page .page-header h1 {
      margin: 0 0 48px;
      font-size: 38px;
      line-height: 1.07;
      letter-spacing: -0.02em;
      color: var(--ink);
      font-weight: 800;
    }

    .admin-profile-page .page-header__lede {
      margin: 8px 0 0;
      color: #7f766b;
      font-size: 14px;
      max-width: 520px;
      line-height: 1.45;
    }

    .admin-profile-page .profile-card {
      background: var(--surface);
      border-radius: 20px;
      border: 1px solid rgba(15, 14, 12, 0.08);
      box-shadow: var(--shadow-soft);
      margin-top: 24px;
      overflow: hidden;
      transition: transform 0.22s ease, box-shadow 0.22s ease;
    }

    .admin-profile-page .profile-card:hover {
      transform: translateY(-2px);
      box-shadow: var(--shadow-hover);
    }

    .admin-profile-page .profile-banner {
      height: 92px;
      background:
        linear-gradient(120deg, rgba(200, 64, 26, 0.9) 0%, rgba(157, 52, 24, 0.86) 62%, rgba(74, 103, 65, 0.82) 100%),
        repeating-linear-gradient(45deg, rgba(255, 255, 255, 0.06) 0 10px, transparent 10px 20px);
    }

    .admin-profile-page .profile-card__body {
      display: grid;
      grid-template-columns: minmax(0, 1.3fr) minmax(260px, 0.8fr);
      gap: 14px;
      padding: 16px 20px 16px;
      margin-top: 0;
      align-items: stretch;
    }

    .admin-profile-page .profile-main {
      display: grid;
      gap: 10px;
      align-content: start;
    }

    .admin-profile-page .identity-row {
      display: flex;
      gap: 20px;
      align-items: center;
      margin-top: 0;
    }

    .admin-profile-page .avatar-wrap {
      position: relative;
      width: 102px;
      height: 102px;
      flex-shrink: 0;
      border-radius: 18px;
      overflow: hidden;
      border: 2px solid rgba(255, 255, 255, 0.95);
      box-shadow: 0 8px 16px rgba(15, 14, 12, 0.18);
      background: #efe7da;
      cursor: pointer;
      transition: transform 0.2s ease, box-shadow 0.2s ease;
    }

    .admin-profile-page .avatar-wrap:hover {
      transform: translateY(-2px);
      box-shadow: 0 12px 22px rgba(15, 14, 12, 0.22);
    }

    .admin-profile-page .profile-avatar,
    .admin-profile-page .profile-avatar-image {
      width: 100%;
      height: 100%;
      border-radius: inherit;
    }

    .admin-profile-page .profile-avatar {
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 34px;
      font-weight: 800;
      color: #312a21;
      background: linear-gradient(145deg, #f2e3cc 0%, #e3ceb1 100%);
      user-select: none;
    }

    .admin-profile-page .profile-avatar-image {
      object-fit: cover;
      transition: transform 0.24s ease;
    }

    .admin-profile-page .avatar-wrap:hover .profile-avatar-image {
      transform: scale(1.04);
    }

    .admin-profile-page .avatar-overlay {
      position: absolute;
      inset: 0;
      display: flex;
      align-items: center;
      justify-content: center;
      background: rgba(15, 14, 12, 0.58);
      color: #fff;
      font-size: 12px;
      letter-spacing: 0.02em;
      opacity: 0;
      transition: opacity 0.18s ease;
      pointer-events: none;
    }

    .admin-profile-page .avatar-wrap:hover .avatar-overlay,
    .admin-profile-page .avatar-wrap:focus-within .avatar-overlay {
      opacity: 1;
    }

    .admin-profile-page .avatar-edit-btn {
      position: absolute;
      right: 6px;
      bottom: 6px;
      width: 32px;
      height: 32px;
      border-radius: 999px;
      border: 1px solid rgba(255, 255, 255, 0.9);
      background: rgba(15, 14, 12, 0.85);
      color: #fff;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      cursor: pointer;
      transition: transform 0.2s ease, background-color 0.2s ease;
      z-index: 2;
    }

    .admin-profile-page .avatar-edit-btn svg {
      width: 16px;
      height: 16px;
      fill: currentColor;
    }

    .admin-profile-page .avatar-edit-btn:hover {
      background: var(--accent);
      transform: scale(1.06);
    }

    .admin-profile-page .avatar-edit-btn:focus-visible {
      outline: none;
      box-shadow: 0 0 0 4px rgba(200, 64, 26, 0.25);
    }

    .admin-profile-page .sr-only {
      position: absolute;
      width: 1px;
      height: 1px;
      padding: 0;
      margin: -1px;
      overflow: hidden;
      clip: rect(0, 0, 0, 0);
      white-space: nowrap;
      border: 0;
    }

    .admin-profile-page .profile-info h2 {
      margin: 0;
      color: var(--ink);
      font-size: 29px;
      line-height: 1.16;
      font-weight: 800;
    }

    .admin-profile-page .profile-role {
      margin: 4px 0 8px;
      color: #675f54;
      font-size: 14px;
    }

    .admin-profile-page .profile-info {
      padding-top: 0;
      min-height: 102px;
      display: flex;
      flex-direction: column;
      justify-content: center;
    }

    .admin-profile-page .status-badge {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      padding: 7px 12px;
      border-radius: 999px;
      font-size: 12px;
      font-weight: 800;
      background: #ddf3e6;
      color: var(--ok);
      border: 1px solid rgba(47, 133, 90, 0.24);
      width: fit-content;
    }

    .admin-profile-page .status-badge::before {
      content: '';
      width: 8px;
      height: 8px;
      border-radius: 999px;
      background: currentColor;
      box-shadow: 0 0 0 5px rgba(47, 133, 90, 0.14);
    }

    .admin-profile-page .status-badge.status--inactive {
      color: #b8322d;
      background: #fcebea;
      border-color: rgba(184, 50, 45, 0.22);
    }

    .admin-profile-page .profile-bio {
      margin: 0;
      color: #574f45;
      line-height: 1.6;
      max-width: 62ch;
      padding-bottom: 10px;
      border-bottom: 1px solid #e4dbd0;
    }

    .admin-profile-page .profile-actions {
      display: flex;
      flex-wrap: wrap;
      gap: 10px;
      margin-top: 6px;
      padding-top: 0;
      border-top: 0;
      align-items: center;
    }

    .admin-profile-page .profile-actions .btn {
      width: auto;
      flex: 1 1 220px;
    }

    .admin-profile-page .btn {
      border-radius: 10px;
      padding: 10px 14px;
      font-size: 14px;
      font-weight: 700;
      text-decoration: none;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      gap: 8px;
      transition: transform 0.16s ease, box-shadow 0.2s ease, background-color 0.2s ease, border-color 0.2s ease;
    }

    .admin-profile-page .btn svg {
      width: 15px;
      height: 15px;
      fill: currentColor;
    }

    .admin-profile-page .btn-primary {
      background: linear-gradient(145deg, #bf5b3a 0%, #a84b2f 100%);
      color: #fff;
      border: 1px solid transparent;
      box-shadow: 0 6px 14px rgba(157, 52, 24, 0.2);
    }

    .admin-profile-page .btn-primary:hover {
      transform: translateY(-1px);
      background: linear-gradient(145deg, #b35435 0%, #964227 100%);
      box-shadow: 0 10px 18px rgba(157, 52, 24, 0.24);
    }

    .admin-profile-page .btn-primary:active {
      transform: translateY(0);
      box-shadow: 0 6px 14px rgba(157, 52, 24, 0.22);
    }

    .admin-profile-page .btn-secondary {
      border: 1px solid #bfb2a3;
      color: #3f352c;
      background: #fcf8f2;
    }

    .admin-profile-page .btn-secondary:hover {
      background: #f1e8dc;
      border-color: #a89480;
      transform: translateY(-1px);
    }

    .admin-profile-page .profile-side {
      background: #f8f1e5;
      border: 1px solid #dccdb7;
      border-radius: 14px;
      padding: 15px;
      display: grid;
      gap: 8px;
      align-content: start;
      align-self: stretch;
    }

    .admin-profile-page .profile-side h3 {
      margin: 0;
      color: var(--ink);
      font-size: 16px;
      font-weight: 800;
    }

    .admin-profile-page .profile-side p {
      margin: 0;
      color: #665f55;
      font-size: 13px;
      line-height: 1.5;
    }

    .admin-profile-page .profile-side ul {
      margin: 0;
      padding: 0;
      list-style: none;
      display: grid;
      gap: 8px;
    }

    .admin-profile-page .profile-side li {
      display: flex;
      align-items: center;
      gap: 8px;
      color: #3f372d;
      font-size: 13px;
    }

    .admin-profile-page .profile-side li::before {
      content: '\2713';
      width: 18px;
      height: 18px;
      border-radius: 999px;
      background: rgba(74, 103, 65, 0.14);
      color: #385730;
      font-size: 11px;
      font-weight: 900;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      flex-shrink: 0;
    }

    .admin-profile-page .data-grid {
      display: grid;
      grid-template-columns: repeat(2, minmax(0, 1fr));
      gap: 14px;
    }

    .admin-profile-page .data-card {
      background: #fff;
      border: 1px solid #e6ddd1;
      border-radius: 14px;
      padding: 15px;
      box-shadow: 0 8px 18px rgba(15, 14, 12, 0.06);
      display: flex;
      flex-direction: column;
      gap: 10px;
      min-height: 126px;
      transition: transform 0.18s ease, box-shadow 0.2s ease;
    }

    .admin-profile-page .data-card:hover {
      transform: translateY(-2px);
      box-shadow: 0 12px 24px rgba(15, 14, 12, 0.1);
    }

    .admin-profile-page .data-head {
      display: flex;
      align-items: center;
      gap: 10px;
      min-height: 34px;
    }

    .admin-profile-page .data-icon {
      width: 34px;
      height: 34px;
      border-radius: 10px;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      background: #f4ece0;
      color: #5b4f41;
    }

    .admin-profile-page .data-icon svg {
      width: 18px;
      height: 18px;
      fill: currentColor;
    }

    .admin-profile-page .data-label {
      font-size: 12px;
      text-transform: uppercase;
      letter-spacing: 0.06em;
      color: #857c72;
      margin: 0;
    }

    .admin-profile-page .data-value {
      margin: 0;
      color: #191612;
      font-weight: 700;
      font-size: 15px;
      word-break: break-word;
    }

    .admin-profile-page .developer-card {
      background: #fffcf7;
      border: 1px solid #eadfce;
      border-radius: 16px;
      padding: 18px;
      box-shadow: 0 10px 22px rgba(15, 14, 12, 0.07);
      display: grid;
      gap: 10px;
    }

    .admin-profile-page .developer-card h3 {
      margin: 0;
      color: var(--ink);
      font-size: 19px;
    }

    .admin-profile-page .developer-card p {
      margin: 0;
      color: #5d564c;
      line-height: 1.65;
      font-size: 14px;
      max-width: 68ch;
    }

    .admin-profile-page .badge-row {
      display: flex;
      flex-wrap: wrap;
      gap: 8px;
      margin-top: 2px;
      padding-top: 10px;
      border-top: 1px dashed #decebb;
    }

    .admin-profile-page .tech-badge {
      background: #f2e8d9;
      border: 1px solid #d7c2a2;
      color: #44382d;
      border-radius: 999px;
      padding: 4px 10px;
      font-size: 12px;
      font-weight: 700;
      letter-spacing: 0.02em;
      transition: transform 0.18s ease, border-color 0.2s ease, background-color 0.2s ease;
    }

    .admin-profile-page .tech-badge:nth-child(3n + 1) {
      background: #f8eadf;
      border-color: #dfc3ab;
    }

    .admin-profile-page .tech-badge:nth-child(3n + 2) {
      background: #f2efde;
      border-color: #d4caa3;
    }

    .admin-profile-page .tech-badge:nth-child(3n + 3) {
      background: #e9efe3;
      border-color: #c6d3b7;
    }

    .admin-profile-page .tech-badge:hover {
      transform: translateY(-1px);
      border-color: #b8946a;
    }

    .admin-profile-page .sidebar--admin-simple .sidebar-item {
      position: relative;
      transition: transform 0.16s ease, background-color 0.2s ease;
      padding-left: 16px;
      display: flex;
      align-items: center;
      gap: 10px;
    }

    .admin-profile-page .sidebar-item-icon {
      width: 16px;
      height: 16px;
      color: #7a7065;
      flex-shrink: 0;
      display: inline-flex;
      align-items: center;
      justify-content: center;
    }

    .admin-profile-page .sidebar-item-icon svg {
      width: 100%;
      height: 100%;
      fill: currentColor;
    }

    .admin-profile-page .sidebar-item-label {
      line-height: 1;
    }

    .admin-profile-page .sidebar--admin-simple .sidebar-item:hover {
      transform: translateX(2px);
      background: #f7efe3;
    }

    .admin-profile-page .sidebar--admin-simple .sidebar-item.active {
      background: #efe3d3;
      color: #211d18;
      box-shadow: inset 0 0 0 1px #e0c8a8;
    }

    .admin-profile-page .sidebar--admin-simple .sidebar-item.active .sidebar-item-icon {
      color: var(--accent);
    }

    .admin-profile-page .sidebar--admin-simple .sidebar-item.active::before {
      content: '';
      position: absolute;
      left: 0;
      top: 8px;
      bottom: 8px;
      width: 5px;
      border-radius: 0 6px 6px 0;
      background: #b35233;
    }

    @media (max-width: 980px) {
      .admin-profile-page {
        padding: 20px;
      }

      .admin-profile-page .profile-card__body {
        grid-template-columns: 1fr;
        gap: 16px;
      }

      .admin-profile-page .profile-actions .btn {
        flex-basis: 100%;
      }

      .admin-profile-page .data-grid {
        grid-template-columns: 1fr;
      }
    }

    @media (max-width: 640px) {
      .admin-profile-page .page-header h1 {
        font-size: 32px;
      }

      .admin-profile-page .profile-card__body {
        padding: 14px 15px 14px;
      }

      .admin-profile-page .identity-row {
        gap: 14px;
        align-items: center;
      }

      .admin-profile-page .avatar-wrap {
        width: 92px;
        height: 92px;
      }

      .admin-profile-page .profile-info h2 {
        font-size: 25px;
      }
    }

    @media (prefers-reduced-motion: reduce) {

      .admin-profile-page .profile-card,
      .admin-profile-page .data-card,
      .admin-profile-page .btn,
      .admin-profile-page .avatar-wrap,
      .admin-profile-page .profile-avatar-image,
      .admin-profile-page .sidebar--admin-simple .sidebar-item {
        transition: none;
      }
    }
  </style>
</head>

<body>
  <div class="app-shell">
    <?php require_once __DIR__ . '/../includes/sidebar-admin.php'; ?>

    <main class="main-content admin-profile-page">
      <div class="profile-layout">

        <div class="page-header">
          <h1>Admin Profile</h1>
          <p class="page-header__lede">Manage your account identity, security actions, and access details.</p>
        </div>

        <section class="profile-card" aria-labelledby="profileIdentityHeading">
          <div class="profile-banner" aria-hidden="true"></div>

          <div class="profile-card__body">
            <div class="profile-main">
              <div class="identity-row">
                <div class="avatar-wrap" id="avatarWrap">
                  <?php if ($avatar['type'] === 'image' && !empty($avatar['url'])): ?>
                    <img id="profileAvatarImage" class="profile-avatar-image" src="<?= $escape($avatar['url']) ?>" alt="<?= $escape($admin['name']) ?> profile photo">
                  <?php else: ?>
                    <div id="profileAvatarInitials" class="profile-avatar" aria-label="Profile initials"><?= $escape($initials) ?></div>
                  <?php endif; ?>

                  <div class="avatar-overlay" aria-hidden="true">Change Photo</div>

                  <button type="button" class="avatar-edit-btn" id="avatarEditBtn" aria-label="Upload profile photo" title="Upload profile photo">
                    <svg viewBox="0 0 24 24" aria-hidden="true">
                      <path d="M9.4 4.8h5.2l1 1.7h2.4A1.9 1.9 0 0 1 20 8.4v9.2a1.9 1.9 0 0 1-1.9 1.9H5.9A1.9 1.9 0 0 1 4 17.6V8.4a1.9 1.9 0 0 1 1.9-1.9h2.5l1-1.7zm2.6 3a4.1 4.1 0 1 0 0 8.2 4.1 4.1 0 0 0 0-8.2zm0 1.7a2.4 2.4 0 1 1 0 4.8 2.4 2.4 0 0 1 0-4.8z" />
                    </svg>
                  </button>

                  <input id="avatarFileInput" class="sr-only" type="file" accept="image/jpeg,image/png,image/gif,image/webp">
                </div>

                <div class="profile-info">
                  <h2 id="profileIdentityHeading"><?= $escape($admin['name']) ?></h2>
                  <p class="profile-role"><?= $escape($admin['role']) ?></p>
                  <span class="status-badge<?= $is_active ? '' : ' status--inactive' ?>"><?= $escape($admin['status']) ?></span>
                </div>
              </div>

              <p class="profile-bio"><?= $escape($admin['bio']) ?></p>

              <div class="profile-actions">
                <a href="<?= BASE_URL ?>admin/change-password.php" class="btn btn-primary">
                  <svg viewBox="0 0 24 24" aria-hidden="true">
                    <path d="M17 8h-1V6a4 4 0 0 0-8 0v2H7a2 2 0 0 0-2 2v8a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2v-8a2 2 0 0 0-2-2zm-7-2a2 2 0 1 1 4 0v2h-4V6zm2 9.3a1.7 1.7 0 1 1 0-3.4 1.7 1.7 0 0 1 0 3.4z" />
                  </svg>
                  Change Password
                </a>
                <a href="<?= BASE_URL ?>admin/users.php" class="btn btn-secondary">
                  <svg viewBox="0 0 24 24" aria-hidden="true">
                    <path d="M16 11a3 3 0 1 0-2.9-3A3 3 0 0 0 16 11zm-8 0a3 3 0 1 0-2.9-3A3 3 0 0 0 8 11zm0 1.5c-2.1 0-6 1.1-6 3.2V18h8v-2.3c0-.9.3-1.7.9-2.3A9 9 0 0 0 8 12.5zm8 0c-.3 0-.6 0-.9.1 1.1.8 1.9 1.8 1.9 3.1V18h7v-2.3c0-2.1-3.9-3.2-8-3.2z" />
                  </svg>
                  Manage Users
                </a>
              </div>
            </div>

            <aside class="profile-side" aria-label="Security summary">
              <h3>Profile Security</h3>
              <p>Keep your profile photo current for audit visibility and maintain account security from the password section.</p>
              <ul>
                <li>Use a recent profile image</li>
                <li>Rotate password on schedule</li>
                <li>Review user access regularly</li>
              </ul>
            </aside>
          </div>
        </section>

        <section class="data-grid" aria-label="Account and access details">
          <article class="data-card">
            <div class="data-head">
              <span class="data-icon" aria-hidden="true">
                <svg viewBox="0 0 24 24">
                  <path d="M20 6H4a2 2 0 0 0-2 2v8a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V8a2 2 0 0 0-2-2zm0 3.3L12 13 4 9.3V8l8 3.7L20 8z" />
                </svg>
              </span>
              <p class="data-label">Email</p>
            </div>
            <p class="data-value"><?= $escape($admin['email'] !== '' ? $admin['email'] : 'Not set') ?></p>
          </article>

          <article class="data-card">
            <div class="data-head">
              <span class="data-icon" aria-hidden="true">
                <svg viewBox="0 0 24 24">
                  <path d="M12 2a7 7 0 0 0-7 7c0 4.8 5.6 11.3 6.2 12a1 1 0 0 0 1.6 0C13.4 20.3 19 13.8 19 9a7 7 0 0 0-7-7zm0 9.3A2.3 2.3 0 1 1 14.3 9 2.3 2.3 0 0 1 12 11.3z" />
                </svg>
              </span>
              <p class="data-label">Location</p>
            </div>
            <p class="data-value"><?= $escape($admin['location']) ?></p>
          </article>

          <article class="data-card">
            <div class="data-head">
              <span class="data-icon" aria-hidden="true">
                <svg viewBox="0 0 24 24">
                  <path d="M19 4h-1V2h-2v2H8V2H6v2H5a2 2 0 0 0-2 2v13a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6a2 2 0 0 0-2-2zm0 15H5V10h14zm0-11H5V6h14z" />
                </svg>
              </span>
              <p class="data-label">Member Since</p>
            </div>
            <p class="data-value"><?= $escape($admin['joined'] !== '' ? $admin['joined'] : 'Not available') ?></p>
          </article>

          <article class="data-card">
            <div class="data-head">
              <span class="data-icon" aria-hidden="true">
                <svg viewBox="0 0 24 24">
                  <path d="M12 1 3 5v6c0 5 3.8 9.7 9 11 5.2-1.3 9-6 9-11V5zm0 10.2A2.2 2.2 0 1 1 14.2 9 2.2 2.2 0 0 1 12 11.2z" />
                </svg>
              </span>
              <p class="data-label">Role</p>
            </div>
            <p class="data-value"><?= $escape($admin['role']) ?></p>
          </article>

          <article class="data-card">
            <div class="data-head">
              <span class="data-icon" aria-hidden="true">
                <svg viewBox="0 0 24 24">
                  <path d="M12 2 4 5v6c0 5.3 3.4 10.3 8 12 4.6-1.7 8-6.7 8-12V5zm0 4a2 2 0 1 1-2 2 2 2 0 0 1 2-2zm0 12.6a6.7 6.7 0 0 1-4.8-2.1c.1-1.6 3.2-2.5 4.8-2.5s4.7.9 4.8 2.5a6.7 6.7 0 0 1-4.8 2.1z" />
                </svg>
              </span>
              <p class="data-label">Permissions</p>
            </div>
            <p class="data-value"><?= $escape($admin['permissions']) ?></p>
          </article>

          <article class="data-card">
            <div class="data-head">
              <span class="data-icon" aria-hidden="true">
                <svg viewBox="0 0 24 24">
                  <path d="M12 2a10 10 0 1 0 10 10A10 10 0 0 0 12 2zm4.6 7.8-5.2 5.5a1 1 0 0 1-1.4 0l-2.6-2.7 1.4-1.3 1.9 2 4.5-4.8z" />
                </svg>
              </span>
              <p class="data-label">Account Status</p>
            </div>
            <p class="data-value"><?= $escape($admin['status']) ?></p>
          </article>
        </section>

        <section class="developer-card" aria-labelledby="aboutDeveloperHeading">
          <h3 id="aboutDeveloperHeading">About Developer</h3>
          <p>The admin profile experience is maintained to keep operations reliable, secure, and easy to review for everyday library workflows.</p>
          <p>Focus areas include account security, user administration, and interface consistency for fast decision-making under operational pressure.</p>
          <div class="badge-row" aria-label="Technology stack badges">
            <?php foreach ($skills as $skill): ?>
              <span class="tech-badge"><?= $escape($skill) ?></span>
            <?php endforeach; ?>
          </div>
        </section>
      </div>

    </main>
  </div>

  <script>
    (() => {
      const baseUrl = <?= json_encode(BASE_URL, JSON_UNESCAPED_SLASHES) ?>;
      const avatarInput = document.getElementById('avatarFileInput');
      const avatarEditButton = document.getElementById('avatarEditBtn');
      const avatarWrap = document.getElementById('avatarWrap');

      const sidebarIcons = {
        'Dashboard': 'M3 3h8v8H3zm10 0h8v5h-8zM3 13h5v8H3zm7 0h11v8H10z',
        'Profile Details': 'M12 2a5 5 0 1 1-5 5 5 5 0 0 1 5-5zm0 12c4.4 0 8 2 8 4.5V21H4v-2.5C4 16 7.6 14 12 14z',
        'Password & Security': 'M17 8h-1V6a4 4 0 0 0-8 0v2H7a2 2 0 0 0-2 2v8a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2v-8a2 2 0 0 0-2-2zm-7-2a2 2 0 1 1 4 0v2h-4z',
        'Users': 'M16 11a3 3 0 1 0-2.9-3A3 3 0 0 0 16 11zm-8 0a3 3 0 1 0-2.9-3A3 3 0 0 0 8 11zm0 1.5c-2.1 0-6 1.1-6 3.2V18h8v-2.3c0-.9.3-1.7.9-2.3A9 9 0 0 0 8 12.5zm8 0c-.3 0-.6 0-.9.1 1.1.8 1.9 1.8 1.9 3.1V18h7v-2.3c0-2.1-3.9-3.2-8-3.2z',
        'Settings': 'M19.4 13a7.6 7.6 0 0 0 0-2l2-1.5-2-3.5-2.4 1a8 8 0 0 0-1.7-1l-.4-2.6h-4l-.4 2.6a8 8 0 0 0-1.7 1l-2.4-1-2 3.5 2 1.5a7.6 7.6 0 0 0 0 2l-2 1.5 2 3.5 2.4-1a8 8 0 0 0 1.7 1l.4 2.6h4l.4-2.6a8 8 0 0 0 1.7-1l2.4 1 2-3.5zM12 15.5A3.5 3.5 0 1 1 15.5 12 3.5 3.5 0 0 1 12 15.5z',
        'Transaction Logs': 'M4 5h16v2H4zm0 6h16v2H4zm0 6h10v2H4z',
        'Logout': 'M15 3h-5a2 2 0 0 0-2 2v3h2V5h5v14h-5v-3H8v3a2 2 0 0 0 2 2h5a2 2 0 0 0 2-2V5a2 2 0 0 0-2-2zM13 12l-4-3v2H3v2h6v2z'
      };

      const sidebarItems = document.querySelectorAll('.sidebar--admin-simple .sidebar-item');
      sidebarItems.forEach((item) => {
        if (item.querySelector('.sidebar-item-icon')) {
          return;
        }

        const label = item.textContent ? item.textContent.trim() : '';
        const iconPath = sidebarIcons[label];
        if (!iconPath) {
          return;
        }

        const icon = document.createElement('span');
        icon.className = 'sidebar-item-icon';
        icon.setAttribute('aria-hidden', 'true');
        icon.innerHTML = '<svg viewBox="0 0 24 24"><path d="' + iconPath + '" /></svg>';

        const text = document.createElement('span');
        text.className = 'sidebar-item-label';
        text.textContent = label;

        item.textContent = '';
        item.append(icon, text);
      });

      if (!avatarInput || !avatarEditButton || !avatarWrap) {
        return;
      }

      avatarWrap.addEventListener('click', (event) => {
        const target = event.target;
        if (target instanceof HTMLElement && target.closest('button')) {
          return;
        }
        avatarInput.click();
      });

      avatarEditButton.addEventListener('click', () => {
        avatarInput.click();
      });

      avatarInput.addEventListener('change', async () => {
        const file = avatarInput.files && avatarInput.files[0] ? avatarInput.files[0] : null;
        if (!file) {
          return;
        }

        const allowed = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        if (!allowed.includes(file.type)) {
          Swal.fire({
            icon: 'error',
            title: 'Invalid file',
            text: 'Please choose a JPEG, PNG, GIF, or WebP image.'
          });
          avatarInput.value = '';
          return;
        }

        if (file.size > 5 * 1024 * 1024) {
          Swal.fire({
            icon: 'error',
            title: 'File too large',
            text: 'Image must be 5MB or smaller.'
          });
          avatarInput.value = '';
          return;
        }

        const formData = new FormData();
        formData.append('avatar', file);

        avatarEditButton.disabled = true;
        avatarEditButton.setAttribute('aria-busy', 'true');

        try {
          const response = await fetch(baseUrl + 'admin/upload-avatar.php', {
            method: 'POST',
            body: formData,
            credentials: 'same-origin'
          });

          const data = await response.json();

          if (!response.ok || !data.success) {
            throw new Error(data.message || 'Upload failed.');
          }

          const normalizedPath = String(data.avatar_url || '').replace(/^\/+/, '');
          const imageUrl = baseUrl + normalizedPath + '?v=' + Date.now();

          let img = document.getElementById('profileAvatarImage');
          const initials = document.getElementById('profileAvatarInitials');
          if (!img) {
            img = document.createElement('img');
            img.id = 'profileAvatarImage';
            img.className = 'profile-avatar-image';
            img.alt = 'Profile photo';
            avatarWrap.insertBefore(img, avatarWrap.firstChild);
          }
          img.src = imageUrl;

          if (initials) {
            initials.remove();
          }

          Swal.fire({
            icon: 'success',
            title: 'Photo updated',
            text: data.message || 'Profile photo uploaded successfully.'
          });
        } catch (error) {
          Swal.fire({
            icon: 'error',
            title: 'Upload failed',
            text: error instanceof Error ? error.message : 'Could not upload photo.'
          });
        } finally {
          avatarEditButton.disabled = false;
          avatarEditButton.removeAttribute('aria-busy');
          avatarInput.value = '';
        }
      });
    })();
  </script>

</body>

</html>
