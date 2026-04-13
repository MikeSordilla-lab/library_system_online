<?php

/**
 * 403.php — Access Denied error page (FR-032)
 *
 * Rendered by auth_guard.php after calling http_response_code(403).
 * May also be visited directly — in that case sets its own 403 status.
 */

// Set 403 if this page is accessed directly (not included by auth_guard)
if (!defined('DB_HOST')) {
  http_response_code(403);
}

// Safe dashboard link: prefer BASE_URL constant if available, fall back to relative
$dashboard_url = defined('BASE_URL') ? BASE_URL . 'login.php' : 'login.php';
$role          = $_SESSION['role'] ?? null;
if ($role && defined('BASE_URL')) {
  $dashboard_url = BASE_URL . htmlspecialchars($role, ENT_QUOTES, 'UTF-8') . '/index.php';
}

$pageTitle = '403 Forbidden | Library System';
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <?php require_once(defined('BASE_URL') ? __DIR__ . '/includes/head.php' : __DIR__ . '/includes/head.php'); ?>
</head>

<body>
  <div class="page-center">
    <div class="card" style="text-align:center;">
      <div style="font-family:var(--font-serif); font-size:5rem; font-weight:900; color:var(--accent); line-height:1;">
        403
      </div>
      <h1 style="font-family:var(--font-serif); font-size:1.5rem; font-weight:700; margin:1rem 0 .5rem;">
        Access Denied
      </h1>
      <p style="font-size:.9375rem; color:var(--muted); line-height:1.6; margin-bottom:1.75rem;">
        You do not have permission to view this page.<br>
        Please contact the library administrator if you believe this is an error.
      </p>
      <a href="<?= htmlspecialchars($dashboard_url, ENT_QUOTES, 'UTF-8') ?>" class="btn-primary">
        Go to Dashboard
      </a>
    </div>
  </div>
</body>

</html>
</body>

</html>