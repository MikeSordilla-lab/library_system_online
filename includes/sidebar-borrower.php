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

// Active class helper — includes gold left border indicator
$_sb_nav = fn(string $key) =>
$key === ($current_page ?? '') ? 'sidebar-item active' : 'sidebar-item';
?>
<style>

    /* ── Sidebar style touch-ups ── */
    .sidebar-item {
      position: relative;
      transition: background 0.25s ease, padding-left 0.25s ease;
    }
    .sidebar-item.active {
      background: linear-gradient(90deg, rgba(201,168,76,0.10) 0%, rgba(201,168,76,0.03) 100%);
      border-left: 3px solid var(--rd-primary);
      padding-left: calc(1.5rem - 3px);
      box-shadow: inset 0 0 20px rgba(201,168,76,0.05);
    }
    .sidebar-item:not(.active):hover {
      background: rgba(201,168,76,0.05);
      border-left: 3px solid rgba(201,168,76,0.3);
      padding-left: calc(1.5rem - 3px);
    }
    .sidebar-avatar__initials {
      transition: transform 0.3s ease;
    }
    .sidebar-avatar:hover .sidebar-avatar__initials {
      transform: scale(1.1);
    }
    .sidebar-avatar__details .sidebar-avatar__name {
      font-weight: 600;
    }
    .sidebar-avatar__details .sidebar-avatar__role {
      font-size: 0.7rem;
      text-transform: uppercase;
      letter-spacing: 0.05em;
      opacity: 0.5;
    }
    .sidebar-toggle {
      background: rgba(28,26,23,0.9);
      border: 1px solid rgba(201,168,76,0.3);
      backdrop-filter: blur(10px);
    }
    .sidebar-toggle span {
      display: block;
      width: 20px;
      height: 2px;
      background: var(--rd-primary, #c9a84c);
      border-radius: 2px;
      transition: all 0.3s ease;
      transform-origin: center;
    }
    .sidebar-toggle[aria-expanded="true"] span:nth-child(1) {
      transform: translateY(7px) rotate(45deg);
    }
    .sidebar-toggle[aria-expanded="true"] span:nth-child(2) {
      opacity: 0;
      transform: scaleX(0);
    }
    .sidebar-toggle[aria-expanded="true"] span:nth-child(3) {
      transform: translateY(-7px) rotate(-45deg);
    }
    .sidebar-toggle span + span {
      margin-top: 4px;
    }
    @media (max-width: 1024px) {
      .sidebar {
        background: linear-gradient(180deg, #1c1a17 0%, #0f0e0c 100%);
      }
    }
  </style>

<nav class="sidebar" aria-label="Borrower navigation">
  <a href="<?= BASE_URL ?>borrower/index.php" class="sidebar__brand" aria-label="Go to borrower dashboard">
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
    <a href="<?= BASE_URL ?>borrower/my_books.php" class="<?= $_sb_nav('borrower.my_books') ?>">
      My Books
    </a>
    <a href="<?= BASE_URL ?>receipt/index.php" class="<?= $_sb_nav('receipt.index') ?>">
      My Receipts
    </a>
    <a href="<?= BASE_URL ?>borrower/profile.php" class="<?= $_sb_nav('borrower.profile') ?>">
      My Profile
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
