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

require_once __DIR__ . '/../includes/avatar.php';

// Fetch user avatar from DB
$_sb_user_id = (int) ($_SESSION['user_id'] ?? 0);
$_sb_avatar_url = null;
if ($_sb_user_id > 0 && isset($GLOBALS['pdo'])) {
  $_sb_stmt = $GLOBALS['pdo']->prepare('SELECT avatar_url FROM Users WHERE id = ? LIMIT 1');
  $_sb_stmt->execute([$_sb_user_id]);
  $_sb_row = $_sb_stmt->fetch();
  $_sb_avatar_url = $_sb_row['avatar_url'] ?? null;
} elseif ($_sb_user_id > 0) {
  $pdo_sb = get_db();
  $_sb_stmt = $pdo_sb->prepare('SELECT avatar_url FROM Users WHERE id = ? LIMIT 1');
  $_sb_stmt->execute([$_sb_user_id]);
  $_sb_row = $_sb_stmt->fetch();
  $_sb_avatar_url = $_sb_row['avatar_url'] ?? null;
}

$_sb_full_name = $_SESSION['full_name'] ?? '';
$_sb_avatar = get_avatar_display($_sb_avatar_url, $_sb_full_name, 36);
$_sb_name = htmlspecialchars($_sb_full_name, ENT_QUOTES, 'UTF-8');
$_sb_role = htmlspecialchars($_SESSION['role'] ?? '', ENT_QUOTES, 'UTF-8');

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
    .sidebar-avatar__circle {
      width: 42px;
      height: 42px;
      border-radius: 50%;
      border: 2px solid var(--rd-primary);
      overflow: hidden;
      flex-shrink: 0;
      box-shadow: 0 0 0 3px var(--rd-bg, #1c1a17), 0 4px 14px rgba(0,0,0,0.4);
      transition: transform 0.3s ease, border-color 0.3s ease, box-shadow 0.3s ease;
    }
    .sidebar-avatar:hover .sidebar-avatar__circle {
      transform: scale(1.08);
      border-color: #dfc476;
      box-shadow: 0 0 0 3px var(--rd-bg, #1c1a17), 0 6px 18px rgba(0,0,0,0.6), 0 0 12px rgba(201,168,76,0.2);
    }
    .sidebar-avatar__img {
      width: 100%;
      height: 100%;
      object-fit: cover;
      display: block;
    }
    .sidebar-avatar__initials {
      width: 100%;
      height: 100%;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 1rem;
      font-weight: 700;
      color: var(--rd-primary);
      background: linear-gradient(145deg, rgba(201,168,76,0.15) 0%, rgba(201,168,76,0.05) 100%);
      transition: transform 0.3s ease;
      letter-spacing: 0.01em;
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
      <img src="<?= BASE_URL ?>assets/images/library_logo_cropped.png" alt="LibraLink Logo" class="sidebar__brand-logo" onerror="this.onerror=null;this.src='<?= BASE_URL ?>assets/images/logo.svg';">
    </span>
    <span class="sidebar__brand-text">LibraLink</span>
  </a>

  <div class="sidebar__nav-links">
    <a href="<?= BASE_URL ?>borrower/index.php" class="<?= $_sb_nav('borrower.index') ?>">
      <span class="sidebar-item__icon" aria-hidden="true">
        <svg viewBox="0 0 24 24" fill="none" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round">
          <path d="M3 11.5L12 4l9 7.5" />
          <path d="M5 10.5V20h14v-9.5" />
          <path d="M10 20v-5h4v5" />
        </svg>
      </span>
      <span>My Dashboard</span>
    </a>
    <a href="<?= BASE_URL ?>borrower/catalog.php" class="<?= $_sb_nav('borrower.catalog') ?>">
      <span class="sidebar-item__icon" aria-hidden="true">
        <svg viewBox="0 0 24 24" fill="none" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round">
          <path d="M4 5.5A2.5 2.5 0 0 1 6.5 3H20v17H6.5A2.5 2.5 0 0 0 4 22V5.5Z" />
          <path d="M4 18.5A2.5 2.5 0 0 1 6.5 16H20" />
        </svg>
      </span>
      <span>Browse Catalog</span>
    </a>
    <a href="<?= BASE_URL ?>borrower/my_books.php" class="<?= $_sb_nav('borrower.my_books') ?>">
      <span class="sidebar-item__icon" aria-hidden="true">
        <svg viewBox="0 0 24 24" fill="none" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round">
          <path d="M4 4h6v16H4z" />
          <path d="M10 4h10v16H10" />
          <path d="M7 8h1" />
          <path d="M14 8h4" />
        </svg>
      </span>
      <span>My Books</span>
    </a>
    <a href="<?= BASE_URL ?>receipt/index.php" class="<?= $_sb_nav('receipt.index') ?>">
      <span class="sidebar-item__icon" aria-hidden="true">
        <svg viewBox="0 0 24 24" fill="none" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round">
          <path d="M7 3h10l1 2h3v3h-1v13l-2-1.5L16 21l-2-1.5L12 21l-2-1.5L8 21l-2-1.5L4 21V8H3V5h3l1-2Z" />
          <path d="M8 10h8" />
          <path d="M8 14h8" />
        </svg>
      </span>
      <span>My Receipts</span>
    </a>
    <a href="<?= BASE_URL ?>borrower/profile.php" class="<?= $_sb_nav('borrower.profile') ?>">
      <span class="sidebar-item__icon" aria-hidden="true">
        <svg viewBox="0 0 24 24" fill="none" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round">
          <circle cx="12" cy="8" r="4" />
          <path d="M4 20c1.8-3.5 4.6-5 8-5s6.2 1.5 8 5" />
        </svg>
      </span>
      <span>My Profile</span>
    </a>
  </div>

  <div class="sidebar__avatar-section">
    <div class="sidebar-avatar">
      <div class="sidebar-avatar__circle">
        <?php if ($_sb_avatar['type'] === 'image' && !empty($_sb_avatar['url'])): ?>
          <img class="sidebar-avatar__img" src="<?= htmlspecialchars((string)$_sb_avatar['url'], ENT_QUOTES, 'UTF-8') ?>" alt="<?= $_sb_name ?> avatar">
        <?php else: ?>
          <div class="sidebar-avatar__initials"><?= htmlspecialchars((string)$_sb_avatar['initials'], ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>
      </div>
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
