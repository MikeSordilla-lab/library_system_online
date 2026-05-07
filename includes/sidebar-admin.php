<?php

/**
 * includes/sidebar-admin.php — Admin role sidebar partial
 *
 * Variables consumed:
 *   $_SESSION['full_name']  — user's display name
 *   $_SESSION['role']       — user role (expected: 'admin')
 *   $current_page           — dot-notation page key
 *
 * Usage: require_once __DIR__ . '/../includes/sidebar-admin.php';
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
<nav class="sidebar sidebar--admin sidebar--admin-simple" aria-label="Admin navigation">
  <a href="<?= BASE_URL ?>admin/index.php" class="sidebar__brand" aria-label="Go to admin dashboard">
    <span class="sidebar__brand-logo-wrap" aria-hidden="true">
      <img src="<?= BASE_URL ?>assets/images/library_logo_cropped.png" alt="LibraLink Logo" class="sidebar__brand-logo" onerror="this.onerror=null;this.src='<?= BASE_URL ?>assets/images/logo.svg';">
    </span>
    <span class="sidebar__brand-text">LibraLink</span>
  </a>

  <div class="sidebar__nav-links">
    <a href="<?= BASE_URL ?>admin/index.php" class="<?= $_sb_nav('admin.index') ?>">
      <span class="sidebar-item__icon" aria-hidden="true">
        <svg viewBox="0 0 24 24" fill="none" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round">
          <path d="M3 11.5L12 4l9 7.5" />
          <path d="M5 10.5V20h14v-9.5" />
          <path d="M10 20v-5h4v5" />
        </svg>
      </span>
      <span>Dashboard</span>
    </a>
    <a href="<?= BASE_URL ?>admin/about.php" class="<?= $_sb_nav('admin.about') ?>">
      <span class="sidebar-item__icon" aria-hidden="true">
        <svg viewBox="0 0 24 24" fill="none" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round">
          <circle cx="12" cy="8" r="4" />
          <path d="M4 20c1.8-3.5 4.6-5 8-5s6.2 1.5 8 5" />
        </svg>
      </span>
      <span>Profile Details</span>
    </a>
    <a href="<?= BASE_URL ?>admin/change-password.php" class="<?= $_sb_nav('admin.change-password') ?>">
      <span class="sidebar-item__icon" aria-hidden="true">
        <svg viewBox="0 0 24 24" fill="none" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round">
          <rect x="4" y="11" width="16" height="10" rx="2" />
          <path d="M8 11V8a4 4 0 1 1 8 0v3" />
          <circle cx="12" cy="16" r="1.2" />
        </svg>
      </span>
      <span>Password &amp; Security</span>
    </a>
    <a href="<?= BASE_URL ?>admin/users.php" class="<?= $_sb_nav('admin.users') ?>">
      <span class="sidebar-item__icon" aria-hidden="true">
        <svg viewBox="0 0 24 24" fill="none" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round">
          <circle cx="9" cy="9" r="3" />
          <circle cx="17" cy="10" r="2.5" />
          <path d="M3.5 19c1.5-3 3.8-4.5 6.5-4.5S15 16 16.5 19" />
          <path d="M14.5 19c.8-1.7 2.1-2.7 3.9-2.7 1.1 0 2.1.4 3.1 1.2" />
        </svg>
      </span>
      <span>Users</span>
    </a>
    <a href="<?= BASE_URL ?>admin/settings.php" class="<?= $_sb_nav('admin.settings') ?>">
      <span class="sidebar-item__icon" aria-hidden="true">
        <svg viewBox="0 0 24 24" fill="none" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round">
          <circle cx="12" cy="12" r="3.2" />
          <path d="M19.4 15a1 1 0 0 0 .2 1.1l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1 1 0 0 0-1.1-.2 1 1 0 0 0-.6.9V20a2 2 0 1 1-4 0v-.2a1 1 0 0 0-.6-.9 1 1 0 0 0-1.1.2l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1 1 0 0 0 .2-1.1 1 1 0 0 0-.9-.6H4a2 2 0 1 1 0-4h.2a1 1 0 0 0 .9-.6 1 1 0 0 0-.2-1.1l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1 1 0 0 0 1.1.2h0a1 1 0 0 0 .6-.9V4a2 2 0 1 1 4 0v.2a1 1 0 0 0 .6.9h0a1 1 0 0 0 1.1-.2l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1 1 0 0 0-.2 1.1v0a1 1 0 0 0 .9.6H20a2 2 0 1 1 0 4h-.2a1 1 0 0 0-.9.6Z" />
        </svg>
      </span>
      <span>Settings</span>
    </a>
    <a href="<?= BASE_URL ?>admin/audit-log.php" class="<?= $_sb_nav('admin.audit') ?>">
      <span class="sidebar-item__icon" aria-hidden="true">
        <svg viewBox="0 0 24 24" fill="none" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round">
          <path d="M5 4h14v16H5z" />
          <path d="M8 8h8M8 12h8M8 16h5" />
        </svg>
      </span>
      <span>Transaction Logs</span>
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
