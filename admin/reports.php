<?php

/**
 * admin/reports.php — Aggregate Reports Dashboard (US4, FR-016–FR-017)
 *
 * GET: Display two live aggregate statistics:
 *   1. Total fines collected (SUM of fine_amount WHERE fine_paid = 1)
 *   2. Current active loan count (COUNT WHERE status = 'active')
 *
 * No caching — queries run on every page load.
 * No POST handler needed.
 * Protected: Admin role only.
 */

// RBAC guard
$allowed_roles = ['admin'];
require_once __DIR__ . '/../includes/auth_guard.php';
require_once __DIR__ . '/../includes/db.php';

$pdo = get_db();

// ---- Aggregate queries (T012) ---------------------------------------------

// Total fines collected (paid fines only); COALESCE ensures 0.00 when table is empty
$stmt = $pdo->prepare("SELECT COALESCE(SUM(fine_amount), 0) AS total_fines FROM `Circulation` WHERE fine_paid = 1");
$stmt->execute();
$total_fines = (float) $stmt->fetchColumn();

// Active loans count
$stmt = $pdo->prepare("SELECT COUNT(*) AS active_loans FROM `Circulation` WHERE `status` = 'active'");
$stmt->execute();
$active_loans = (int) $stmt->fetchColumn();

$dashboard_url = htmlspecialchars(BASE_URL . 'admin/index.php', ENT_QUOTES, 'UTF-8');
$logout_url    = htmlspecialchars(BASE_URL . 'logout.php',       ENT_QUOTES, 'UTF-8');
$current_page = 'admin.reports';
$pageTitle    = 'Reports | Library System';
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <?php require_once __DIR__ . '/../includes/head.php'; ?>
</head>

<body>
  <div class="app-shell">
    <?php require_once __DIR__ . '/../includes/sidebar-admin.php'; ?>
    <main class="main-content">
      <div class="page-header">
        <h1>Reports</h1>
      </div>

      <div class="section-card">
        <div class="section-card__header">
          <span class="section-card__title">Operational Overview</span>
        </div>
        <div style="display:grid; grid-template-columns:repeat(auto-fit,minmax(220px,1fr)); gap:var(--space-5); padding:var(--space-6)">

          <div style="background:var(--cream); border-radius:var(--radius); padding:var(--space-6)">
            <div style="font-size:var(--text-xs); font-weight:600; color:var(--muted); text-transform:uppercase; letter-spacing:.05em; margin-bottom:var(--space-3)">Total Fines Collected</div>
            <div style="font-size:var(--text-4xl); font-weight:700; color:var(--ink); line-height:1.1">
              <span style="font-size:var(--text-xl); color:var(--muted)">&#8369;</span><?= number_format($total_fines, 2) ?>
            </div>
            <div style="font-size:var(--text-xs); color:var(--muted); margin-top:var(--space-2)">Sum of all paid overdue fines</div>
          </div>

          <div style="background:var(--cream); border-radius:var(--radius); padding:var(--space-6)">
            <div style="font-size:var(--text-xs); font-weight:600; color:var(--muted); text-transform:uppercase; letter-spacing:.05em; margin-bottom:var(--space-3)">Active Loans</div>
            <div style="font-size:var(--text-4xl); font-weight:700; color:var(--ink); line-height:1.1"><?= $active_loans ?></div>
            <div style="font-size:var(--text-xs); color:var(--muted); margin-top:var(--space-2)">Books currently checked out</div>
          </div>

        </div>
      </div>
    </main>
  </div>
</body>

</html>