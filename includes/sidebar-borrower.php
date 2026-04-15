<?php

/**
 * includes/sidebar-borrower.php — Borrower role sidebar partial
 *
 * Variables consumed:
 *   $_SESSION['full_name']  — user's display name
 *   $_SESSION['role']       — user role (expected: 'borrower')
 *   $current_page           — dot-notation page key (e.g. 'borrower.index')
 *
 * Usage: require_once __DIR__ . '/../includes/sidebar-borrower.php';
 */

// Avatar initials (1 char for single-word names, 2 chars for multi-word)
$_sb_parts    = explode(' ', trim($_SESSION['full_name'] ?? ''));
$_sb_parts    = array_values(array_filter($_sb_parts));
$_sb_initials = strtoupper(
  substr($_sb_parts[0] ?? '', 0, 1) .
    (count($_sb_parts) > 1 ? substr($_sb_parts[count($_sb_parts) - 1], 0, 1) : '')
);
$_sb_name = htmlspecialchars($_SESSION['full_name'] ?? '', ENT_QUOTES, 'UTF-8');
$_sb_role = htmlspecialchars($_SESSION['role']      ?? '', ENT_QUOTES, 'UTF-8');

// Active class helper
$_sb_nav = fn(string $key) =>
$key === ($current_page ?? '') ? 'sidebar-item active' : 'sidebar-item';
?>
<nav class="sidebar" aria-label="Borrower navigation">
  <a href="<?= BASE_URL ?>borrower/index.php" class="sidebar__brand" style="text-decoration: none; color: inherit;" aria-label="Go to borrower dashboard">
    <span class="sidebar__brand-logo-wrap" aria-hidden="true">
      <img src="<?= BASE_URL ?>assets/images/library_logo_cropped.png" alt="Library System Logo" class="sidebar__brand-logo" onerror="this.onerror=null;this.src='<?= BASE_URL ?>assets/images/logo.svg';">
    </span>
    <span class="sidebar__brand-text">Library System</span>
  </a>

  <div class="sidebar__nav-links">
    <a href="<?= BASE_URL ?>borrower/index.php" class="<?= $_sb_nav('borrower.index') ?>">
      My Dashboard
    </a>
    <a href="<?= BASE_URL ?>borrower/catalog.php" class="<?= $_sb_nav('borrower.catalog') ?>">
      Browse Catalog
    </a>
    <a href="<?= BASE_URL ?>receipt/index.php" class="<?= $_sb_nav('receipt.index') ?>">
      My Receipts
    </a>
  </div>

  <div class="sidebar__avatar-section">
    <div class="sidebar-avatar">
      <div class="sidebar-avatar__initials"><?= htmlspecialchars($_sb_initials, ENT_QUOTES, 'UTF-8') ?></div>
      <div class="sidebar-avatar__details">
        <div class="sidebar-avatar__name"><?= $_sb_name ?></div>
        <div class="sidebar-avatar__role"><?= $_sb_role ?></div>
      </div>
    </div>
    <a href="<?= BASE_URL ?>logout.php" class="sidebar__logout-btn" aria-label="Log out">
      <span class="sidebar__logout-icon" aria-hidden="true">&#x21AA;</span>
      <span>Log Out</span>
    </a>
  </div>
</nav>
