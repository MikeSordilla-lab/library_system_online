<?php

/**
 * admin/index.php — Admin Dashboard
 *
 * Protected: Admin role only.
 */

$allowed_roles = ['admin'];
require_once __DIR__ . '/../includes/auth_guard.php';

$escape = static fn($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');

$admin_name = (string) ($_SESSION['full_name'] ?? 'Administrator');

$pdo = get_db();
$total_users = (int) $pdo->query('SELECT COUNT(*) FROM Users')->fetchColumn();
$total_books = (int) $pdo->query('SELECT COALESCE(SUM(total_copies), 0) FROM Books')->fetchColumn();
$active_loans = (int) $pdo->query("SELECT COUNT(*) FROM Circulation WHERE status IN ('active','overdue')")->fetchColumn();
$pending_reservations = (int) $pdo->query("SELECT COUNT(*) FROM Reservations WHERE status = 'pending'")->fetchColumn();

$current_page = 'admin.index';
$pageTitle = 'Dashboard | Library System';
$includeSweetAlert = false;
$extraStyles = [
  'https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700&display=swap',
  BASE_URL . 'assets/css/borrower-redesign.css',
  BASE_URL . 'assets/css/admin-redesign.css'
];
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <?php require_once __DIR__ . '/../includes/head.php'; ?>
</head>

<body class="admin-themed">
  <div class="app-shell">
    <?php require_once __DIR__ . '/../includes/sidebar-admin.php'; ?>
    <main class="main-content">
      <div class="page-header">
        <div>
          <h1>Dashboard</h1>
          <p>Welcome back, <?= $escape($admin_name) ?>. Here’s a quick snapshot of the library system.</p>
        </div>
        <div>
          <a class="btn-primary" href="<?= $escape(BASE_URL . 'admin/users.php') ?>">Manage Users</a>
        </div>
      </div>

      <div class="stat-grid">
        <div class="stat-card">
          <div class="rd-stat-icon rd-i-blue">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">
              <path d="M17 20h5v-2a4 4 0 0 0-4-4h-1" />
              <path d="M9 20H2v-2a4 4 0 0 1 4-4h1" />
              <circle cx="9" cy="7" r="3" />
              <circle cx="17" cy="7" r="3" />
            </svg>
          </div>
          <div class="stat-value"><?= number_format($total_users) ?></div>
          <div class="stat-label">Total Users</div>
          <div class="stat-stripe stat-stripe--blue"></div>
        </div>
        <div class="stat-card">
          <div class="rd-stat-icon rd-i-purple">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">
              <path d="M4 19.5v-15A2.5 2.5 0 0 1 6.5 2H20v20H6.5a2.5 2.5 0 0 1 0-5H20" />
              <path d="M12 6v7" />
              <path d="M9 9h6" />
            </svg>
          </div>
          <div class="stat-value"><?= number_format($total_books) ?></div>
          <div class="stat-label">Total Books</div>
          <div class="stat-stripe stat-stripe--gold"></div>
        </div>
        <div class="stat-card">
          <div class="rd-stat-icon rd-i-green">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">
              <circle cx="12" cy="12" r="10" />
              <path d="M12 6v6l4 2" />
            </svg>
          </div>
          <div class="stat-value"><?= number_format($active_loans) ?></div>
          <div class="stat-label">Active Loans</div>
          <div class="stat-stripe stat-stripe--sage"></div>
        </div>
        <div class="stat-card">
          <div class="rd-stat-icon rd-i-purple">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">
              <path d="M19 21l-7-5-7 5V5a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2z" />
            </svg>
          </div>
          <div class="stat-value"><?= number_format($pending_reservations) ?></div>
          <div class="stat-label">Pending Reservations</div>
          <div class="stat-stripe stat-stripe--gold"></div>
        </div>
      </div>

      <div class="quick-grid">
        <div class="quick-action-card">
          <h3>User Management</h3>
          <div class="actions">
            <a href="<?= $escape(BASE_URL . 'admin/users.php') ?>" class="btn-primary">User Accounts</a>
            <a href="<?= $escape(BASE_URL . 'admin/about.php') ?>" class="btn-ghost">Admin Profile</a>
            <a href="<?= $escape(BASE_URL . 'admin/change-password.php') ?>" class="btn-ghost">Change Password</a>
          </div>
        </div>
        <div class="quick-action-card">
          <h3>System Oversight</h3>
          <div class="actions">
            <a href="<?= $escape(BASE_URL . 'admin/reports.php') ?>" class="btn-primary">Reports</a>
            <a href="<?= $escape(BASE_URL . 'admin/audit-log.php') ?>" class="btn-ghost">Audit Log</a>
            <a href="<?= $escape(BASE_URL . 'admin/settings.php') ?>" class="btn-ghost">Settings</a>
          </div>
        </div>
      </div>
    </main>
  </div>
</body>

</html>
