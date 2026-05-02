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
    <main class="main-content admin-reports-page">
      <div class="page-header">
        <h1>Reports</h1>
      </div>

      <div class="section-card">
        <div class="section-card__header">
          <span class="section-card__title">Operational Overview</span>
        </div>
        <div class="admin-reports-grid">

          <div class="admin-reports-metric-card">
            <div class="admin-reports-metric-label">Total Fines Collected</div>
            <div class="admin-reports-metric-value">
              <span class="admin-reports-currency">&#8369;</span><?= number_format($total_fines, 2) ?>
            </div>
            <div class="admin-reports-metric-help">Sum of all paid overdue fines</div>
          </div>

          <div class="admin-reports-metric-card">
            <div class="admin-reports-metric-label">Active Loans</div>
            <div class="admin-reports-metric-value"><?= $active_loans ?></div>
            <div class="admin-reports-metric-help">Books currently checked out</div>
          </div>

        </div>
      </div>
    </main>
  </div>
</body>

</html>
