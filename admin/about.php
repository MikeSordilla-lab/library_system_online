<?php

$allowed_roles = ['admin'];
require_once __DIR__ . '/../includes/auth_guard.php';
require_once __DIR__ . '/../includes/avatar.php';

$current_page = 'admin.about';
$pageTitle = 'Admin Profile | LibraLink';

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

$stats = [
  [
    'label' => 'Total Users',
    'value' => '—',
    'stripe' => 'gold',
    'icon' => '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>'
  ],
  [
    'label' => 'Active Loans',
    'value' => '—',
    'stripe' => 'blue',
    'icon' => '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/></svg>'
  ],
  [
    'label' => 'Pending Reservations',
    'value' => '—',
    'stripe' => 'sage',
    'icon' => '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 21l-7-5-7 5V5a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2z"/></svg>'
  ],
  [
    'label' => 'Overdue Books',
    'value' => '—',
    'stripe' => 'red',
    'icon' => '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>'
  ],
  [
    'label' => 'System Status',
    'value' => 'Active',
    'stripe' => 'sage',
    'icon' => '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>'
  ],
  [
    'label' => 'Library Hours',
    'value' => '8AM–5PM',
    'stripe' => 'blue',
    'icon' => '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>'
  ],
];

try {
  $pdo = get_db();
  $stmt = $pdo->query('SELECT COUNT(*) FROM Users');
  $stats[0]['value'] = $stmt->fetchColumn();

  $stmt = $pdo->query("SELECT COUNT(*) FROM Loans WHERE status = 'borrowed'");
  $stats[1]['value'] = $stmt->fetchColumn();

  $stmt = $pdo->query("SELECT COUNT(*) FROM Reservations WHERE status = 'pending'");
  $stats[2]['value'] = $stmt->fetchColumn();

  $stmt = $pdo->query("SELECT COUNT(*) FROM Loans WHERE status = 'overdue'");
  $stats[3]['value'] = $stmt->fetchColumn();
} catch (PDOException $e) {
  error_log('[admin/about.php] Stats load DB error: ' . $e->getMessage());
}

?>

<!DOCTYPE html>
<html lang="en">

<head>
  <?php
  $extraStyles = [
    'https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700&display=swap',
    BASE_URL . 'assets/css/borrower-redesign.css',
    BASE_URL . 'assets/css/admin-redesign.css'
  ];
  require_once __DIR__ . '/../includes/head.php';
  ?>
</head>

<body class="admin-themed">
  <div class="app-shell">
    <?php require_once __DIR__ . '/../includes/sidebar-admin.php'; ?>

    <main class="main-content admin-about-page">
      <div class="page-header">
        <h1>Admin Profile</h1>
        <p class="page-header__lede">Manage your account identity, security actions, and access details.</p>
      </div>

      <section class="section-card">
        <div class="section-card__header">
          <span class="section-card__title">Profile Details</span>
        </div>
        <div style="display: grid; grid-template-columns: auto 1fr; gap: 24px; align-items: start; padding: 20px;">
          <div style="text-align: center;">
            <?php if ($avatar['type'] === 'image' && !empty($avatar['url'])): ?>
              <img src="<?= $escape($avatar['url']) ?>" alt="<?= $escape($admin['name']) ?> profile photo" style="width: 120px; height: 120px; border-radius: 50%; object-fit: cover; border: 3px solid var(--rd-primary, #c9a84c);">
            <?php else: ?>
              <div style="width: 120px; height: 120px; border-radius: 50%; background: linear-gradient(135deg, var(--rd-primary, #c9a84c), #b8942d); display: flex; align-items: center; justify-content: center; font-size: 48px; font-weight: 700; color: #fff;"><?= $escape($initials) ?></div>
            <?php endif; ?>
            <p style="margin-top: 12px; font-size: 14px; color: #666;">Admin</p>
          </div>
          <div>
            <h2 style="margin: 0 0 8px; font-size: 28px; color: var(--ink, #1c1a17);"><?= $escape($admin['name']) ?></h2>
            <p style="margin: 0 0 8px; color: #666;"><?= $escape($admin['email']) ?></p>
            <span style="display: inline-block; padding: 4px 12px; border-radius: 20px; font-size: 13px; font-weight: 600; <?= $is_active ? 'background: #ebf7ef; color: #2f855a;' : 'background: #fef2f2; color: #c53030;' ?>"><?= $escape($admin['status']) ?></span>
            <p style="margin-top: 16px; color: #555;"><?= $escape($admin['bio']) ?></p>
            <div style="margin-top: 20px; display: flex; gap: 12px; flex-wrap: wrap;">
              <a href="<?= BASE_URL ?>admin/change-password.php" class="btn-primary" style="display: inline-flex; align-items: center; gap: 8px; padding: 10px 18px; border-radius: 10px; background: linear-gradient(135deg, #c9a84c, #b8942d); color: #fff; text-decoration: none; font-weight: 600;">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 8h-1V6a4 4 0 0 0-8 0v2H7a2 2 0 0 0-2 2v8a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2v-8a2 2 0 0 0-2-2z"/><circle cx="12" cy="15" r="1.5"/></svg>
                Change Password
              </a>
              <a href="<?= BASE_URL ?>admin/users.php" class="btn-secondary" style="display: inline-flex; align-items: center; gap: 8px; padding: 10px 18px; border-radius: 10px; background: transparent; border: 1px solid var(--rd-border); color: var(--rd-text); text-decoration: none; font-weight: 600;">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                Manage Users
              </a>
            </div>
          </div>
        </div>
      </section>

      <section class="stat-grid" style="margin-top: 24px;">
        <?php foreach ($stats as $stat): ?>
          <div class="stat-card">
            <div class="stat-stripe stat-stripe--<?= $escape($stat['stripe']) ?>"></div>
            <div class="stat-icon stat-icon--<?= $escape($stat['stripe']) ?>">
              <?= $stat['icon'] ?>
            </div>
            <div class="stat-value"><?= $escape($stat['value']) ?></div>
            <div class="stat-label"><?= $escape($stat['label']) ?></div>
          </div>
        <?php endforeach; ?>
      </section>

      <section class="section-card" style="margin-top: 24px;">
        <div class="section-card__header">
          <span class="section-card__title">About Developer</span>
        </div>
        <div style="padding: 20px;">
          <p style="margin: 0 0 16px; color: var(--rd-text); line-height: 1.7; font-size: 0.95rem;">
            The admin profile experience is maintained to keep operations reliable, secure, and easy to review for everyday library workflows.
          </p>
          <div style="display: flex; gap: 8px; flex-wrap: wrap; padding-top: 12px; border-top: 1px solid rgba(255, 255, 255, 0.06);">
            <?php foreach ($skills as $skill): ?>
              <span style="background: rgba(201, 168, 76, 0.1); border: 1px solid var(--rd-border); color: var(--rd-primary); border-radius: 999px; padding: 4px 12px; font-size: 12px; font-weight: 600;"><?= $escape($skill) ?></span>
            <?php endforeach; ?>
          </div>
        </div>
      </section>
    </main>
  </div>

  <script>
    (() => {
      const baseUrl = <?= json_encode(BASE_URL, JSON_UNESCAPED_SLASHES) ?>;
    })();
  </script>

</body>

</html>