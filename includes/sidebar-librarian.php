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
  <a href="<?= BASE_URL ?>librarian/index.php" class="sidebar__brand" aria-label="Go to librarian dashboard">
    <span class="sidebar__brand-logo-wrap" aria-hidden="true">
      <img src="<?= BASE_URL ?>assets/images/library_logo_cropped.png" alt="LibraLink Logo" class="sidebar__brand-logo" onerror="this.onerror=null;this.src='<?= BASE_URL ?>assets/images/logo.svg';">
    </span>
    <span class="sidebar__brand-text">LibraLink</span>
  </a>

  <div class="sidebar__nav-links">
    <a href="<?= BASE_URL ?>librarian/index.php" class="<?= $_sb_nav('librarian.index') ?>">
      <span class="sidebar-item__icon" aria-hidden="true">
        <svg viewBox="0 0 24 24" fill="none" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round">
          <path d="M3 11.5L12 4l9 7.5" />
          <path d="M5 10.5V20h14v-9.5" />
          <path d="M10 20v-5h4v5" />
        </svg>
      </span>
      <span>Dashboard</span>
    </a>

    <div class="sidebar__section-label">Catalog</div>
    <a href="<?= BASE_URL ?>librarian/catalog.php" class="<?= $_sb_nav('librarian.catalog') ?>">
      <span class="sidebar-item__icon" aria-hidden="true">
        <svg viewBox="0 0 24 24" fill="none" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round">
          <path d="M4 5.5A2.5 2.5 0 0 1 6.5 3H20v17H6.5A2.5 2.5 0 0 0 4 22V5.5Z" />
          <path d="M4 18.5A2.5 2.5 0 0 1 6.5 16H20" />
        </svg>
      </span>
      <span>Book Catalog</span>
    </a>

    <div class="sidebar__section-label">Circulation</div>
    <a href="<?= BASE_URL ?>librarian/checkout.php" class="<?= $_sb_nav('librarian.checkout') ?>">
      <span class="sidebar-item__icon" aria-hidden="true">
        <svg viewBox="0 0 24 24" fill="none" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round">
          <path d="M3 7h14a4 4 0 0 1 0 8H3" />
          <path d="M15 11l3 3-3 3" />
          <path d="M3 7V5" />
          <path d="M3 15v2" />
        </svg>
      </span>
      <span>Process Check-Out</span>
    </a>
    <a href="<?= BASE_URL ?>librarian/checkin.php" class="<?= $_sb_nav('librarian.checkin') ?>">
      <span class="sidebar-item__icon" aria-hidden="true">
        <svg viewBox="0 0 24 24" fill="none" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round">
          <path d="M21 7H7a4 4 0 0 0 0 8h14" />
          <path d="M9 11l-3 3 3 3" />
          <path d="M21 7V5" />
          <path d="M21 15v2" />
        </svg>
      </span>
      <span>Process Returns</span>
    </a>
    <a href="<?= BASE_URL ?>librarian/reservations.php" class="<?= $_sb_nav('librarian.reservations') ?>">
      <span class="sidebar-item__icon" aria-hidden="true">
        <svg viewBox="0 0 24 24" fill="none" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round">
          <rect x="3" y="5" width="18" height="16" rx="2" />
          <path d="M8 3v4M16 3v4M3 10h18" />
          <path d="M8 14h3M13 14h3M8 18h3" />
        </svg>
      </span>
      <span>Reservations</span>
    </a>
    <a href="<?= BASE_URL ?>receipt/index.php" class="<?= $_sb_nav('receipt.index') ?>">
      <span class="sidebar-item__icon" aria-hidden="true">
        <svg viewBox="0 0 24 24" fill="none" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round">
          <path d="M7 3h10l1 2h3v3h-1v13l-2-1.5L16 21l-2-1.5L12 21l-2-1.5L8 21l-2-1.5L4 21V8H3V5h3l1-2Z" />
          <path d="M8 10h8" />
          <path d="M8 14h8" />
        </svg>
      </span>
      <span>Receipts</span>
    </a>
    <a href="<?= BASE_URL ?>librarian/records.php" class="<?= $_sb_nav('librarian.records') ?>">
      <span class="sidebar-item__icon" aria-hidden="true">
        <svg viewBox="0 0 24 24" fill="none" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round">
          <path d="M5 4h14v16H5z" />
          <path d="M8 9h8M8 13h8M8 17h5" />
          <path d="M14 4v4h4" />
        </svg>
      </span>
      <span>Generate Records</span>
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
