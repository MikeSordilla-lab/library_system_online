<?php

/**
 * admin/index.php — Admin Dashboard
 *
 * Protected: Admin role only.
 */

$allowed_roles = ['admin'];
require_once __DIR__ . '/../includes/auth_guard.php';

$escape = static fn($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');

$admin_name_raw = (string) ($_SESSION['full_name'] ?? 'Administrator');
$admin_role_raw = ucfirst((string) ($_SESSION['role'] ?? 'admin'));

$developer_name_raw = (defined('DEVELOPER_NAME') && DEVELOPER_NAME !== '')
  ? (string) DEVELOPER_NAME
  : 'Library System Developer';
$developer_title_raw = (defined('DEVELOPER_TITLE') && DEVELOPER_TITLE !== '')
  ? (string) DEVELOPER_TITLE
  : 'Full-Stack Developer';
$developer_bio_raw = (defined('DEVELOPER_BIO') && DEVELOPER_BIO !== '')
  ? (string) DEVELOPER_BIO
  : 'I design and build secure, reliable systems that keep library operations efficient and easy to use.';
$developer_location_raw = (defined('DEVELOPER_LOCATION') && DEVELOPER_LOCATION !== '')
  ? (string) DEVELOPER_LOCATION
  : 'Tangub City, Philippines';
$developer_email_raw = (defined('DEVELOPER_EMAIL') && DEVELOPER_EMAIL !== '')
  ? (string) DEVELOPER_EMAIL
  : 'developer@example.com';
$developer_timezone_raw = (defined('DEVELOPER_TIMEZONE') && DEVELOPER_TIMEZONE !== '')
  ? (string) DEVELOPER_TIMEZONE
  : 'Asia/Manila';
$developer_experience_raw = (defined('DEVELOPER_YEARS_EXPERIENCE') && DEVELOPER_YEARS_EXPERIENCE !== '')
  ? (string) DEVELOPER_YEARS_EXPERIENCE
  : 'N/A';
$developer_status_raw = (defined('DEVELOPER_STATUS') && DEVELOPER_STATUS !== '')
  ? (string) DEVELOPER_STATUS
  : $developer_title_raw;
$developer_stack_raw = (defined('DEVELOPER_STACK') && DEVELOPER_STACK !== '')
  ? (string) DEVELOPER_STACK
  : 'PHP, MySQL, JavaScript, CSS';
$developer_focus_raw = (defined('DEVELOPER_FOCUS_AREAS') && DEVELOPER_FOCUS_AREAS !== '')
  ? (string) DEVELOPER_FOCUS_AREAS
  : '';
$developer_website_raw = (defined('DEVELOPER_WEBSITE') && DEVELOPER_WEBSITE !== '')
  ? (string) DEVELOPER_WEBSITE
  : '';
$developer_github_raw = (defined('DEVELOPER_GITHUB') && DEVELOPER_GITHUB !== '')
  ? (string) DEVELOPER_GITHUB
  : '';
$developer_linkedin_raw = (defined('DEVELOPER_LINKEDIN') && DEVELOPER_LINKEDIN !== '')
  ? (string) DEVELOPER_LINKEDIN
  : '';

$normalize_url = static function (string $url): string {
  $trimmed = trim($url);
  if ($trimmed === '') {
    return '';
  }
  if (!preg_match('#^https?://#i', $trimmed)) {
    return 'https://' . $trimmed;
  }
  return $trimmed;
};

$developer_links = [];
$website_url = $normalize_url($developer_website_raw);
$github_url = $normalize_url($developer_github_raw);
$linkedin_url = $normalize_url($developer_linkedin_raw);

if ($website_url !== '') {
  $developer_links[] = ['label' => 'Website', 'href' => $website_url];
}
if ($github_url !== '') {
  $developer_links[] = ['label' => 'GitHub', 'href' => $github_url];
}
if ($linkedin_url !== '') {
  $developer_links[] = ['label' => 'LinkedIn', 'href' => $linkedin_url];
}

$developer_photo_relative = 'assets/images/my_profile.jpg';
$developer_photo_fs_path = dirname(__DIR__) . '/' . $developer_photo_relative;
$developer_photo_url = is_file($developer_photo_fs_path) ? BASE_URL . $developer_photo_relative : '';
    
$developer_name_parts = preg_split('/\s+/', trim($developer_name_raw));
$developer_name_parts = is_array($developer_name_parts)
  ? array_values(array_filter($developer_name_parts, static fn($part) => $part !== ''))
  : [];
$developer_initials = 'DS';
if (count($developer_name_parts) >= 2) {
  $developer_initials = strtoupper(substr($developer_name_parts[0], 0, 1) . substr($developer_name_parts[count($developer_name_parts) - 1], 0, 1));
} elseif (count($developer_name_parts) === 1) {
  $developer_initials = strtoupper(substr($developer_name_parts[0], 0, 2));
}

$developer_stack_items = [];
foreach (explode(',', $developer_stack_raw) as $stack_item) {
  $stack_item = trim($stack_item);
  if ($stack_item !== '' && stripos($stack_item, 'sweetalert') === false) {
    $developer_stack_items[] = $stack_item;
  }
}
if (empty($developer_stack_items)) {
  $developer_stack_items = ['PHP', 'MySQL', 'JavaScript', 'CSS'];
}

$developer_focus_items = [];
foreach (explode(',', $developer_focus_raw) as $focus_item) {
  $focus_item = trim($focus_item);
  if ($focus_item !== '') {
    $developer_focus_items[] = $focus_item;
  }
}
if (empty($developer_focus_items)) {
  $developer_focus_items = [
    'Secure authentication and role-based access',
    'Readable, maintainable admin workflows',
    'Reliable circulation and account data integrity',
  ];
}

$developer_email_href = $developer_email_raw !== '' ? 'mailto:' . $developer_email_raw : '';

$current_page = 'admin.index';
$pageTitle = 'Dashboard | Library System';
$includeSweetAlert = false;
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
    <main class="main-content admin-about-dashboard">
      <section id="about-me" class="admin-about-dashboard__stage" aria-label="About the developer">
        <header class="admin-about-dashboard__header">
          <div class="admin-about-dashboard__header-top">
            <p class="admin-about-dashboard__eyebrow">About Me</p>
            <span class="admin-about-dashboard__session-pill"><?= $escape($admin_role_raw) ?></span>
          </div>
          <h1>Developer Profile</h1>
          <p>Welcome, <strong><?= $escape($admin_name_raw) ?></strong>. This dashboard is dedicated to professional developer information in a clear, school-library themed presentation.</p>
        </header>

        <article class="admin-about-dashboard__card">
          <div class="admin-about-dashboard__media">
            <div class="admin-about-dashboard__photo-frame">
              <?php if ($developer_photo_url !== ''): ?>
                <img src="<?= $escape($developer_photo_url) ?>" alt="Developer profile photo" loading="lazy">
              <?php else: ?>
                <div class="admin-about-dashboard__photo-fallback" aria-hidden="true"><?= $escape($developer_initials) ?></div>
              <?php endif; ?>
            </div>
          </div>

          <div class="admin-about-dashboard__content">
            <div class="admin-about-dashboard__identity">
              <p class="admin-about-dashboard__name"><?= $escape($developer_name_raw) ?></p>
              <p class="admin-about-dashboard__title"><?= $escape($developer_title_raw) ?></p>
            </div>
            <p class="admin-about-dashboard__section-lead">Professional Summary</p>
            <p class="admin-about-dashboard__bio"><?= $escape($developer_bio_raw) ?></p>

            <div class="admin-about-dashboard__meta-grid" role="list" aria-label="Developer details">
              <div class="admin-about-dashboard__meta-item" role="listitem">
                <span>Location</span>
                <strong><?= $escape($developer_location_raw) ?></strong>
              </div>
              <div class="admin-about-dashboard__meta-item" role="listitem">
                <span>Email</span>
                <strong>
                  <?php if ($developer_email_href !== ''): ?>
                    <a href="<?= $escape($developer_email_href) ?>"><?= $escape($developer_email_raw) ?></a>
                  <?php else: ?>
                    Not provided
                  <?php endif; ?>
                </strong>
              </div>
              <div class="admin-about-dashboard__meta-item" role="listitem">
                <span>Timezone</span>
                <strong><?= $escape($developer_timezone_raw) ?></strong>
              </div>
              <div class="admin-about-dashboard__meta-item" role="listitem">
                <span>Status</span>
                <strong><?= $escape($developer_status_raw) ?></strong>
              </div>
              <div class="admin-about-dashboard__meta-item" role="listitem">
                <span>Session Role</span>
                <strong><?= $escape($admin_role_raw) ?></strong>
              </div>
              <div class="admin-about-dashboard__meta-item" role="listitem">
                <span>Current Admin</span>
                <strong><?= $escape($admin_name_raw) ?></strong>
              </div>
            </div>

            <section class="admin-about-dashboard__section" aria-label="Technology stack">
              <h2>Technology Stack</h2>
              <div class="admin-about-dashboard__chip-list">
                <?php foreach ($developer_stack_items as $stack_item): ?>
                  <span class="admin-about-dashboard__chip"><?= $escape($stack_item) ?></span>
                <?php endforeach; ?>
              </div>
            </section>

            <section class="admin-about-dashboard__section" aria-label="Developer focus areas">
              <h2>Focus Areas</h2>
              <ul class="admin-about-dashboard__focus-list">
                <?php foreach ($developer_focus_items as $focus_item): ?>
                  <li><span><?= $escape($focus_item) ?></span></li>
                <?php endforeach; ?>
              </ul>
            </section>

            <?php if (!empty($developer_links)): ?>
              <section class="admin-about-dashboard__section" aria-label="Online presence">
                <h2>Online Presence</h2>
                <div class="admin-about-dashboard__links">
                  <?php foreach ($developer_links as $link): ?>
                    <a href="<?= $escape($link['href']) ?>" target="_blank" rel="noopener noreferrer"><?= $escape($link['label']) ?></a>
                  <?php endforeach; ?>
                </div>
              </section>
            <?php endif; ?>
          </div>
        </article>
      </section>
    </main>
  </div>
</body>

</html>