<?php

/**
 * librarian/index.php — Librarian Dashboard Placeholder (US4, FR-024)
 *
 * Protected: Librarian role only.
 * Full dashboard is built in a subsequent feature.
 */

// RBAC guard — must appear before any HTML output (FR-034)
$allowed_roles = ['librarian'];
require_once __DIR__ . '/../includes/auth_guard.php';

$name = htmlspecialchars($_SESSION['full_name'], ENT_QUOTES, 'UTF-8');
$logout_url   = htmlspecialchars(BASE_URL . 'logout.php', ENT_QUOTES, 'UTF-8');

// T024 — Stat card aggregate queries (read-only)
$pdo = get_db();
$totalBooks         = (int) $pdo->query('SELECT SUM(total_copies) FROM Books')->fetchColumn();
$activeLoans        = (int) $pdo->query("SELECT COUNT(*) FROM Circulation WHERE status IN ('active','overdue')")->fetchColumn();
$pendingReservations = (int) $pdo->query("SELECT COUNT(*) FROM Reservations WHERE status = 'pending'")->fetchColumn();

$current_page = 'librarian.index';
$pageTitle    = 'Dashboard | Library System';
$extraStyles = [
  'https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700&display=swap',
  BASE_URL . 'assets/css/borrower-redesign.css',
  BASE_URL . 'assets/css/librarian-redesign.css'
];
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <?php require_once __DIR__ . '/../includes/head.php'; ?>
</head>

<body class="librarian-themed">
  <div class="app-shell">
    <?php require_once __DIR__ . '/../includes/sidebar-librarian.php'; ?>
    <main class="main-content">
      <div class="page-header">
        <h1>Dashboard</h1>
        <p>Welcome back, <?= $name ?></p>
      </div>

      <!-- Stat cards -->
      <div class="stat-grid">
        <div class="stat-card">
          <div class="stat-value"><?= number_format($totalBooks) ?></div>
          <div class="stat-label">Total Books</div>
          <div class="stat-stripe stat-stripe--gold"></div>
        </div>
        <div class="stat-card">
          <div class="stat-value"><?= number_format($activeLoans) ?></div>
          <div class="stat-label">Active Loans</div>
          <div class="stat-stripe stat-stripe--blue"></div>
        </div>
        <div class="stat-card">
          <div class="stat-value"><?= number_format($pendingReservations) ?></div>
          <div class="stat-label">Reservations</div>
          <div class="stat-stripe stat-stripe--sage"></div>
        </div>
      </div>

      <!-- Quick action cards -->
      <div class="quick-grid">
        <div class="quick-action-card">
          <h3>Circulation</h3>
          <div class="actions">
            <a href="<?= BASE_URL ?>librarian/checkout.php" class="btn-primary">Process Check-Out</a>
            <a href="<?= BASE_URL ?>librarian/checkin.php" class="btn-ghost">Process Returns</a>
          </div>
        </div>
        <div class="quick-action-card">
          <h3>Catalog Management</h3>
          <div class="actions">
            <a href="<?= BASE_URL ?>librarian/catalog.php" class="btn-primary">Book Catalog</a>
          </div>
        </div>
      </div>
    </main>
  </div>
</body>

</html>