<?php

/**
 * admin/audit-log.php — Audit Log Viewer (US3, FR-012–FR-014)
 *
 * GET: Display paginated, sortable, date-range-filterable System_Logs table.
 *     - Sort: actor (u.full_name), action (sl.action_type), timestamp (sl.created_at)
 *     - Date filter: from/to YYYY-MM-DD applied as BETWEEN with inclusive end
 *     - Pagination: 50 rows per page
 *
 * Read-only — no edit or delete controls.
 * Protected: Admin role only.
 */

// RBAC guard
$allowed_roles = ['admin'];
require_once __DIR__ . '/../includes/auth_guard.php';
require_once __DIR__ . '/../includes/db.php';

$pdo = get_db();

// ---- Sort resolution (T009 / T010) ----------------------------------------
// Allowlist map prevents SQL injection from dynamic ORDER BY
$sort_map = [
  'actor'     => 'u.full_name',
  'action'    => 'sl.action_type',
  'timestamp' => 'sl.created_at',
];

$sort_key = isset($_GET['sort']) && array_key_exists($_GET['sort'], $sort_map)
  ? $_GET['sort']
  : 'timestamp';

$sort_col = $sort_map[$sort_key];

$sort_dir = (isset($_GET['dir']) && strtoupper($_GET['dir']) === 'ASC') ? 'ASC' : 'DESC';

// ---- Date range filter (T009 / T011) --------------------------------------
$from_raw = $_GET['from'] ?? '';
$to_raw   = $_GET['to']   ?? '';

$use_date_filter = false;
$from_dt = '';
$to_dt   = '';

if ($from_raw !== '' && $to_raw !== '' && strtotime($from_raw) !== false && strtotime($to_raw) !== false) {
  $use_date_filter = true;
  $from_dt = $from_raw . ' 00:00:00';
  $to_dt   = $to_raw   . ' 23:59:59';
}

// ---- Pagination (T009 / T011) ---------------------------------------------
$per_page = 50;
$page     = max(1, (int) ($_GET['page'] ?? 1));
$offset   = ($page - 1) * $per_page;

// ---- Build WHERE clause ---------------------------------------------------
$where_sql  = $use_date_filter ? 'WHERE sl.created_at BETWEEN :from_dt AND :to_dt' : '';
$date_params = $use_date_filter ? [':from_dt' => $from_dt, ':to_dt' => $to_dt]     : [];

// ---- Count query ----------------------------------------------------------
$count_sql = "SELECT COUNT(*) FROM `System_Logs` sl LEFT JOIN `Users` u ON sl.actor_id = u.id {$where_sql}";
$count_stmt = $pdo->prepare($count_sql);
$count_stmt->execute($date_params);
$total_rows  = (int) $count_stmt->fetchColumn();
$total_pages = max(1, (int) ceil($total_rows / $per_page));
$page        = min($page, $total_pages); // clamp

// Recalculate offset after clamp
$offset = ($page - 1) * $per_page;

// ---- Data query -----------------------------------------------------------
// Sort column is from the PHP allowlist — safe to interpolate
$data_sql = "SELECT sl.id, sl.created_at, sl.action_type, sl.target_entity,
                    sl.target_id, sl.outcome, sl.actor_role,
                    COALESCE(u.full_name, 'System') AS actor_name
             FROM `System_Logs` sl
             LEFT JOIN `Users` u ON sl.actor_id = u.id
             {$where_sql}
             ORDER BY {$sort_col} {$sort_dir}
             LIMIT {$per_page} OFFSET :offset";

$data_params = array_merge($date_params, [':offset' => $offset]);
$data_stmt   = $pdo->prepare($data_sql);
$data_stmt->execute($data_params);
$log_rows = $data_stmt->fetchAll();

// ---- Helper: build a URL preserving current GET params -------------------
function audit_url(array $overrides): string
{
  $params = [
    'sort' => $_GET['sort'] ?? 'timestamp',
    'dir'  => $_GET['dir']  ?? 'desc',
    'from' => $_GET['from'] ?? '',
    'to'   => $_GET['to']   ?? '',
    'page' => $_GET['page'] ?? '1',
  ];
  foreach ($overrides as $k => $v) {
    $params[$k] = $v;
  }
  // Remove empty values to keep URLs clean
  $params = array_filter($params, fn($v) => $v !== '' && $v !== null);
  return BASE_URL . 'admin/audit-log.php?' . http_build_query($params);
}

// ---- Helper: sort header link (T010) --------------------------------------
function sort_header(string $label, string $key, string $current_key, string $current_dir): string
{
  if ($key === $current_key) {
    $new_dir  = ($current_dir === 'ASC') ? 'desc' : 'asc';
    $arrow    = ($current_dir === 'ASC') ? ' ↑' : ' ↓';
  } else {
    $new_dir = 'asc';
    $arrow   = '';
  }
  $url = htmlspecialchars(audit_url(['sort' => $key, 'dir' => $new_dir, 'page' => '1']), ENT_QUOTES, 'UTF-8');
  return '<a href="' . $url . '" class="admin-audit-sort-link">'
    . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . $arrow . '</a>';
}

$dashboard_url = htmlspecialchars(BASE_URL . 'admin/index.php', ENT_QUOTES, 'UTF-8');
$logout_url    = htmlspecialchars(BASE_URL . 'logout.php',       ENT_QUOTES, 'UTF-8');
$clear_url     = htmlspecialchars(BASE_URL . 'admin/audit-log.php', ENT_QUOTES, 'UTF-8');
$current_page = 'admin.audit';
$pageTitle    = 'Audit Log | Library System';
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <?php require_once __DIR__ . '/../includes/head.php'; ?>
</head>

<body>
  <div class="app-shell">
    <?php require_once __DIR__ . '/../includes/sidebar-admin.php'; ?>
    <main class="main-content admin-audit-page">
      <div class="page-header">
        <h1>Audit Log</h1>
      </div>

      <div class="section-card">
        <div class="section-card__header">
          <span class="section-card__title">System Log Entries</span>
        </div>

        <!-- Date range filter (T011) -->
        <div class="filter-bar admin-audit-filter-wrap">
          <form method="get" action="<?= $clear_url ?>" class="admin-audit-filter-form">
            <?php if (!empty($_GET['sort'])): ?>
              <input type="hidden" name="sort" value="<?= htmlspecialchars($_GET['sort'], ENT_QUOTES, 'UTF-8') ?>">
            <?php endif; ?>
            <?php if (!empty($_GET['dir'])): ?>
              <input type="hidden" name="dir" value="<?= htmlspecialchars($_GET['dir'], ENT_QUOTES, 'UTF-8') ?>">
            <?php endif; ?>

            <label class="field-label admin-audit-filter-label" for="from">From:</label>
            <input class="field-input admin-audit-date-input" type="date" id="from" name="from"
              value="<?= htmlspecialchars($from_raw, ENT_QUOTES, 'UTF-8') ?>">

            <label class="field-label admin-audit-filter-label" for="to">To:</label>
            <input class="field-input admin-audit-date-input" type="date" id="to" name="to"
              value="<?= htmlspecialchars($to_raw, ENT_QUOTES, 'UTF-8') ?>">

            <button type="submit" class="btn-primary">Filter</button>
            <a href="<?= $clear_url ?>" class="btn-ghost">Clear</a>
          </form>
        </div>

        <?php if (empty($log_rows)): ?>
          <div class="empty-state">
            <span class="empty-state__icon">&#128203;</span>
            <p>No log entries found<?= $use_date_filter ? ' for the selected date range' : '' ?>.</p>
          </div>
        <?php else: ?>
          <div class="tbl-wrapper">
            <table class="tbl">
              <thead>
                <tr>
                  <th><?= sort_header('Timestamp', 'timestamp', $sort_key, $sort_dir) ?></th>
                  <th><?= sort_header('Actor', 'actor', $sort_key, $sort_dir) ?></th>
                  <th><?= sort_header('Action', 'action', $sort_key, $sort_dir) ?></th>
                  <th>Outcome</th>
                  <th>Target</th>
                  <th>Target ID</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($log_rows as $row): ?>
                  <tr>
                    <td data-label="Timestamp"><?= htmlspecialchars($row['created_at'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td data-label="Actor"><?= htmlspecialchars($row['actor_name'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td data-label="Action"><span class="badge badge-mono"><?= htmlspecialchars($row['action_type'], ENT_QUOTES, 'UTF-8') ?></span></td>
                    <td data-label="Outcome">
                      <?php if (strtoupper($row['outcome']) === 'SUCCESS'): ?>
                        <span class="badge badge-green"><?= htmlspecialchars($row['outcome'], ENT_QUOTES, 'UTF-8') ?></span>
                      <?php elseif (stripos($row['outcome'], 'fail') !== false): ?>
                        <span class="badge badge-red"><?= htmlspecialchars($row['outcome'], ENT_QUOTES, 'UTF-8') ?></span>
                      <?php else: ?>
                        <span class="badge badge-mono"><?= htmlspecialchars($row['outcome'], ENT_QUOTES, 'UTF-8') ?></span>
                      <?php endif; ?>
                    </td>
                    <td data-label="Target"><?= htmlspecialchars($row['target_entity'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
                    <td data-label="Target ID"><?= $row['target_id'] !== null ? (int) $row['target_id'] : '&mdash;' ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>

          <!-- Pagination (T011) -->
          <div class="admin-audit-pagination">
            <?php if ($page > 1): ?>
              <a href="<?= htmlspecialchars(audit_url(['page' => $page - 1]), ENT_QUOTES, 'UTF-8') ?>" class="btn-ghost">&larr; Prev</a>
            <?php endif; ?>
            <span>Page <?= $page ?> of <?= $total_pages ?></span>
            <?php if ($page < $total_pages): ?>
              <a href="<?= htmlspecialchars(audit_url(['page' => $page + 1]), ENT_QUOTES, 'UTF-8') ?>" class="btn-ghost">Next &rarr;</a>
            <?php endif; ?>
          </div>
        <?php endif; ?>
      </div>
    </main>
  </div>
</body>

</html>
