<?php

/**
 * includes/sidebar-librarian.php — Librarian role sidebar partial
 *
 * Variables consumed:
 *   $_SESSION['full_name']  — user's display name
 *   $_SESSION['role']       — user role (expected: 'librarian')
 *   $current_page           — dot-notation page key
 *
 * Usage: require_once __DIR__ . '/../includes/sidebar-librarian.php';
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
<nav class="sidebar" aria-label="Librarian navigation">
  <a href="<?= BASE_URL ?>index.php" class="sidebar__brand" style="text-decoration: none; color: inherit;">
    <img src="<?= BASE_URL ?>assets/images/logo.svg" alt="Library System Logo" style="height: 32px; width: auto; object-fit: contain; flex-shrink: 0;">
    <span class="sidebar__brand-text">Library System</span>
  </a>

  <div class="sidebar__nav-links">
    <a href="<?= BASE_URL ?>librarian/index.php" class="<?= $_sb_nav('librarian.index') ?>">
      Dashboard
    </a>

    <div class="sidebar__section-label">Catalog</div>
    <a href="<?= BASE_URL ?>librarian/catalog.php" class="<?= $_sb_nav('librarian.catalog') ?>">
      Book Catalog
    </a>

    <div class="sidebar__section-label">Circulation</div>
    <a href="<?= BASE_URL ?>librarian/checkout.php" class="<?= $_sb_nav('librarian.checkout') ?>">
      Process Check-Out
    </a>
    <a href="<?= BASE_URL ?>librarian/checkin.php" class="<?= $_sb_nav('librarian.checkin') ?>">
      Process Returns
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