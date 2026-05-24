<?php

$allowed_roles = ['librarian', 'borrower'];
require_once __DIR__ . '/../includes/auth_guard.php';
require_once __DIR__ . '/../includes/receipts.php';

$pdo = get_db();
$viewer_id = (int) $_SESSION['user_id'];
$viewer_role = (string) $_SESSION['role'];

$q = isset($_GET['q']) ? trim((string) $_GET['q']) : '';
$filter_status = isset($_GET['status']) ? trim((string) $_GET['status']) : '';
$filter_type = isset($_GET['type']) ? trim((string) $_GET['type']) : '';
$date_from = isset($_GET['date_from']) ? trim((string) $_GET['date_from']) : '';
$date_to = isset($_GET['date_to']) ? trim((string) $_GET['date_to']) : '';
$page = max(1, (int) ($_GET['page'] ?? 1));
$per_page = isset($_GET['per_page']) ? max(5, min(100, (int) $_GET['per_page'])) : 10;
$valid_per_page = [10, 25, 50];
if (!in_array($per_page, $valid_per_page, true)) {
    $per_page = 10;
}
$offset = ($page - 1) * $per_page;

$datePattern = '/^\d{4}-\d{2}-\d{2}$/';
if ($date_from !== '' && !preg_match($datePattern, $date_from)) {
  $date_from = '';
}
if ($date_to !== '' && !preg_match($datePattern, $date_to)) {
  $date_to = '';
}

$allowed_statuses = [];
$allowed_types = [];
try {
  $base_option_sql = '';
  $base_option_params = [];
  if ($viewer_role === 'borrower') {
    $base_option_sql = ' WHERE patron_user_id = ?';
    $base_option_params = [$viewer_id];
  }

  $status_stmt = $pdo->prepare("SELECT DISTINCT status FROM Receipt_Tickets{$base_option_sql} ORDER BY status ASC");
  $status_stmt->execute($base_option_params);
  $allowed_statuses = array_values(array_filter(array_map('strval', $status_stmt->fetchAll(PDO::FETCH_COLUMN) ?: [])));

  $type_stmt = $pdo->prepare("SELECT DISTINCT type FROM Receipt_Tickets{$base_option_sql} ORDER BY type ASC");
  $type_stmt->execute($base_option_params);
  $allowed_types = array_values(array_filter(array_map('strval', $type_stmt->fetchAll(PDO::FETCH_COLUMN) ?: [])));
} catch (Throwable $e) {
  $allowed_statuses = [];
  $allowed_types = [];
}

$conditions = [];
$params = [];
if ($viewer_role === 'borrower') {
  $conditions[] = 'patron_user_id = :viewer_id';
  $params[':viewer_id'] = $viewer_id;
}

if ($q !== '') {
  $conditions[] = '(receipt_no LIKE :q_receipt OR type LIKE :q_type OR status LIKE :q_status)';
  $params[':q_receipt'] = '%' . $q . '%';
  $params[':q_type'] = '%' . $q . '%';
  $params[':q_status'] = '%' . $q . '%';
}

if ($filter_status !== '' && in_array($filter_status, $allowed_statuses, true)) {
  $conditions[] = 'status = :status';
  $params[':status'] = $filter_status;
} elseif ($filter_status !== '') {
  $filter_status = '';
}

if ($filter_type !== '' && in_array($filter_type, $allowed_types, true)) {
  $conditions[] = 'type = :type';
  $params[':type'] = $filter_type;
} elseif ($filter_type !== '') {
  $filter_type = '';
}

$date_to_sql = '';
if ($date_to !== '') {
  $date_to_ts = strtotime($date_to . ' +1 day');
  if ($date_to_ts !== false) {
    $date_to_sql = date('Y-m-d', $date_to_ts);
  } else {
    $date_to = '';
  }
}

if ($date_from !== '') {
  $conditions[] = 'issued_at >= :date_from';
  $params[':date_from'] = $date_from . ' 00:00:00';
}
if ($date_to !== '' && $date_to_sql !== '') {
  $conditions[] = 'issued_at < :date_to';
  $params[':date_to'] = $date_to_sql . ' 00:00:00';
}

$where_sql = $conditions !== [] ? 'WHERE ' . implode(' AND ', $conditions) : '';

$count_sql = "SELECT COUNT(*) FROM Receipt_Tickets {$where_sql}";
$count_stmt = $pdo->prepare($count_sql);
foreach ($params as $key => $value) {
  $count_stmt->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
}
$count_stmt->execute();
$total_tickets = (int) $count_stmt->fetchColumn();
$total_pages = max(1, (int) ceil($total_tickets / $per_page));
$page = min($page, $total_pages);
$offset = ($page - 1) * $per_page;

$tickets_stmt = $pdo->prepare(
  'SELECT ' . receipt_select_clause($pdo) . '
   FROM Receipt_Tickets
   ' . $where_sql . '
   ORDER BY issued_at DESC, id DESC
   LIMIT ' . (int) $per_page . ' OFFSET ' . (int) $offset
);
foreach ($params as $key => $value) {
  $tickets_stmt->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
}
$tickets_stmt->execute();
$tickets = $tickets_stmt->fetchAll() ?: [];

$base_url = defined('BASE_URL') ? (string) constant('BASE_URL') : '/';
$shown_start = $total_tickets > 0 ? $offset + 1 : 0;
$shown_end = $total_tickets > 0 ? $offset + count($tickets) : 0;
$has_filters = $q !== '' || $filter_status !== '' || $filter_type !== '' || $date_from !== '' || $date_to !== '';

function build_receipt_page_url(int $p): string
{
  global $base_url, $q, $filter_status, $filter_type, $date_from, $date_to, $per_page;
  $query = ['page' => $p];
  if ($per_page !== 10) {
    $query['per_page'] = $per_page;
  }
  if ($q !== '') {
    $query['q'] = $q;
  }
  if ($filter_status !== '') {
    $query['status'] = $filter_status;
  }
  if ($filter_type !== '') {
    $query['type'] = $filter_type;
  }
  if ($date_from !== '') {
    $query['date_from'] = $date_from;
  }
  if ($date_to !== '') {
    $query['date_to'] = $date_to;
  }
  return $base_url . 'receipt/?' . http_build_query($query);
}

$current_page = 'receipt.index';
$pageTitle = 'Receipts | LibraLink';
$extraStyles = [
  'https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700&display=swap',
  $base_url . 'assets/css/borrower-redesign.css'
];

ob_start();
?>
<style>
.rd-breadcrumb {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  margin-bottom: 1rem;
  font-size: 0.875rem;
  color: var(--rd-text-muted, #6b7280);
}
.rd-breadcrumb a {
  color: var(--rd-primary, #3b82f6);
  text-decoration: none;
}
.rd-breadcrumb a:hover {
  text-decoration: underline;
}
.rd-breadcrumb-sep {
  color: var(--rd-text-muted, #9ca3af);
}
.receipt-filters {
  display: flex;
  flex-direction: column;
  gap: 1rem;
  margin-bottom: 1.5rem;
}
.receipt-filters__row {
  display: flex;
  gap: 0.75rem;
  flex-wrap: wrap;
  align-items: end;
}
.receipt-filters__field {
  display: flex;
  flex: 1 1 180px;
  flex-direction: column;
  gap: 0.35rem;
}
.receipt-filters__field label {
  font-size: 0.78rem;
  font-weight: 600;
  color: var(--rd-text-muted, #6b7280);
}
.receipt-filters__field input,
.receipt-filters__field select {
  width: 100%;
  padding: 0.7rem 0.85rem;
  border: 1px solid var(--rd-border, #e5e7eb);
  border-radius: 0.5rem;
  background: var(--rd-surface, #fff);
  color: var(--rd-text, #111827);
  font-family: inherit;
  font-size: 0.95rem;
}
.receipt-filters__actions {
  display: flex;
  gap: 0.5rem;
  flex: 0 0 auto;
  flex-wrap: wrap;
}
.receipt-results {
  margin-bottom: 1rem;
  color: var(--rd-text-muted, #6b7280);
  font-size: 0.9rem;
}
.receipt-pagination {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 1rem;
  padding: 1rem 1.25rem;
  border-top: 1px solid var(--rd-border);
  flex-wrap: wrap;
}
.receipt-pagination__controls {
  display: flex;
  align-items: center;
  gap: 0.3rem;
  flex-wrap: wrap;
}
.receipt-pg-btn {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  padding: 0.45rem 0.85rem;
  font-size: 0.8125rem;
  font-weight: 500;
  color: var(--rd-text);
  background: transparent;
  border: 1px solid var(--rd-border);
  border-radius: 0.5rem;
  text-decoration: none;
  transition: all 0.15s ease;
  min-width: 2.25rem;
  cursor: pointer;
  user-select: none;
}
.receipt-pg-btn:hover:not(.receipt-pg-disabled):not(.receipt-pg-current) {
  background: var(--rd-primary-light);
  border-color: var(--rd-primary);
  color: #1c1a17;
}
.receipt-pg-current {
  background: var(--rd-primary);
  border-color: var(--rd-primary);
  color: #1c1a17;
  cursor: default;
  font-weight: 600;
}
.receipt-pg-disabled {
  color: var(--rd-text-muted);
  cursor: not-allowed;
  opacity: 0.5;
  border-color: transparent;
}
.receipt-pg-ellipsis {
  padding: 0 0.25rem;
  color: var(--rd-text-muted);
}
.receipt-pagination__per-page {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  font-size: 0.8125rem;
  color: var(--rd-text-muted);
}
.receipt-per-page-select {
  padding: 0.4rem 0.6rem;
  border: 1px solid var(--rd-border);
  border-radius: 0.4rem;
  background: var(--rd-surface);
  color: var(--rd-text);
  font-family: inherit;
  font-size: 0.8125rem;
  cursor: pointer;
}
.receipt-per-page-select:focus {
  outline: none;
  border-color: var(--rd-primary);
}
@media (max-width: 640px) {
  .receipt-pagination {
    flex-direction: column;
    align-items: stretch;
  }
  .receipt-pagination__controls {
    justify-content: center;
  }
  .receipt-pagination__per-page {
    justify-content: center;
  }
  .receipt-filters__actions,
  .receipt-filters__actions .rd-btn {
    width: 100%;
  }
}
</style>
<?php
$pageStyles = ob_get_clean();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <?php require_once __DIR__ . '/../includes/head.php'; ?>
  <?php if (!empty($pageStyles)): ?>
    <?= $pageStyles ?>
  <?php endif; ?>
</head>
<body class="dashboard-redesign borrower-dashboard-new">
  <div class="app-shell">
    <?php
    if ($viewer_role === 'librarian') {
      require_once __DIR__ . '/../includes/sidebar-librarian.php';
    } else {
      require_once __DIR__ . '/../includes/sidebar-borrower.php';
    }
    ?>

<main class="main-content">
      <div class="rd-breadcrumb">
        <a href="<?= htmlspecialchars($base_url, ENT_QUOTES, 'UTF-8') ?>">Home</a>
        <span class="rd-breadcrumb-sep">›</span>
        <span>Receipts</span>
      </div>

      <div class="rd-header">
        <div>
          <h1>Receipts &amp; Tickets</h1>
          <p>View and reprint recent transaction tickets.</p>
        </div>
      </div>

      <div class="rd-section-title">
        <svg width="24" height="24" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" /></svg>
        Recent Receipts
      </div>

      <form class="receipt-filters rd-card" method="GET" action="<?= htmlspecialchars($base_url . 'receipt/', ENT_QUOTES, 'UTF-8') ?>" aria-label="Filter receipts">
        <div class="receipt-filters__row">
          <div class="receipt-filters__field">
            <label for="receipt-q">Search</label>
            <input id="receipt-q" type="search" name="q" value="<?= htmlspecialchars($q, ENT_QUOTES, 'UTF-8') ?>" placeholder="Receipt #, type, or status">
          </div>
          <div class="receipt-filters__field">
            <label for="receipt-status">Status</label>
            <select id="receipt-status" name="status">
              <option value="">All statuses</option>
              <?php foreach ($allowed_statuses as $status): ?>
                <option value="<?= htmlspecialchars($status, ENT_QUOTES, 'UTF-8') ?>" <?= $filter_status === $status ? 'selected' : '' ?>>
                  <?= htmlspecialchars(ucfirst($status), ENT_QUOTES, 'UTF-8') ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="receipt-filters__field">
            <label for="receipt-type">Type</label>
            <select id="receipt-type" name="type">
              <option value="">All types</option>
              <?php foreach ($allowed_types as $type): ?>
                <option value="<?= htmlspecialchars($type, ENT_QUOTES, 'UTF-8') ?>" <?= $filter_type === $type ? 'selected' : '' ?>>
                  <?= htmlspecialchars(ucwords(str_replace('_', ' ', $type)), ENT_QUOTES, 'UTF-8') ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
        <div class="receipt-filters__row">
          <div class="receipt-filters__field">
            <label for="receipt-date-from">From</label>
            <input id="receipt-date-from" type="date" name="date_from" value="<?= htmlspecialchars($date_from, ENT_QUOTES, 'UTF-8') ?>">
          </div>
          <div class="receipt-filters__field">
            <label for="receipt-date-to">To</label>
            <input id="receipt-date-to" type="date" name="date_to" value="<?= htmlspecialchars($date_to, ENT_QUOTES, 'UTF-8') ?>">
          </div>
          <div class="receipt-filters__actions">
            <button type="submit" class="rd-btn rd-btn-primary">Apply</button>
            <?php if ($has_filters): ?>
              <a class="rd-btn" style="background:var(--rd-surface); border:1px solid var(--rd-border); color:var(--rd-text); text-decoration:none;" href="<?= htmlspecialchars($base_url . 'receipt/', ENT_QUOTES, 'UTF-8') ?>">Clear</a>
            <?php endif; ?>
          </div>
        </div>
      </form>

      <div class="receipt-results" role="status" aria-live="polite">
        Showing <strong><?= (int) $shown_start ?></strong>-<strong><?= (int) $shown_end ?></strong> of <strong><?= (int) $total_tickets ?></strong> receipts
      </div>

      <div class="rd-card" style="margin-bottom: 2rem;">
        <?php if (empty($tickets)): ?>
          <div style="text-align:center; padding: 2rem 0; color: var(--rd-text-muted);">
            <div style="font-size: 3rem; margin-bottom: 1rem; opacity:0.5;">📄</div>
            <p><?= $has_filters ? 'No receipts match your filters.' : 'No receipts found.' ?></p>
          </div>
        <?php else: ?>
          <div style="overflow-x:auto;">
            <table class="rd-table-glass">
              <thead>
                <tr>
                  <th>Receipt #</th>
                  <th>Type</th>
                  <th>Issued</th>
                  <?php if ($viewer_role !== 'borrower'): ?>
                    <th>Borrower ID</th>
                  <?php endif; ?>
                  <th>Amount</th>
                  <th>Status</th>
                  <th>Action</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($tickets as $ticket): ?>
                  <tr>
                    <td><strong><?= htmlspecialchars((string) $ticket['receipt_no'], ENT_QUOTES, 'UTF-8') ?></strong></td>
                    <td><?= htmlspecialchars((string) $ticket['type'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars((string) $ticket['issued_at'], ENT_QUOTES, 'UTF-8') ?></td>
                    <?php if ($viewer_role !== 'borrower'): ?>
                      <td><?= (int) $ticket['patron_user_id'] ?></td>
                    <?php endif; ?>
                    <td>
                      <?php if ($ticket['amount'] !== null): ?>
                        <span style="color:#10b981;font-weight:600;">
                          <?= htmlspecialchars(receipt_format_money($ticket['amount'], $ticket['currency'] ?? null), ENT_QUOTES, 'UTF-8') ?>
                        </span>
                      <?php else: ?>
                        <span style="color:var(--rd-text-muted);">—</span>
                      <?php endif; ?>
                    </td>
                    <td>
                      <div style="display:flex; gap:0.25rem; flex-wrap:wrap; align-items:center;">
                        <span class="rd-badge rd-b-blue">
                          <?= htmlspecialchars((string) ($ticket['status'] ?? 'issued'), ENT_QUOTES, 'UTF-8') ?>
                        </span>
                      </div>
                    </td>
                    <td>
                      <div style="display:flex; gap:0.25rem; flex-wrap:wrap; align-items:center;">
                        <a class="rd-btn-action" href="<?= htmlspecialchars($base_url . 'receipt/view.php?no=' . rawurlencode((string) $ticket['receipt_no']), ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener">Open</a>
                      </div>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
        <?php if ($total_tickets > 0): ?>
        <div class="receipt-pagination">
          <div class="receipt-pagination__controls">
            <?php if ($page > 1): ?>
              <a class="receipt-pg-btn" href="<?= htmlspecialchars(build_receipt_page_url($page - 1), ENT_QUOTES, 'UTF-8') ?>">Previous</a>
            <?php else: ?>
              <span class="receipt-pg-btn receipt-pg-disabled">Previous</span>
            <?php endif; ?>

            <?php
            $start = max(1, $page - 2);
            $end = min($total_pages, $page + 2);
            if ($start > 1): ?>
              <a class="receipt-pg-btn receipt-pg-num" href="<?= htmlspecialchars(build_receipt_page_url(1), ENT_QUOTES, 'UTF-8') ?>">1</a>
              <?php if ($start > 2): ?>
                <span class="receipt-pg-ellipsis">…</span>
              <?php endif; ?>
            <?php endif; ?>

            <?php for ($i = $start; $i <= $end; $i++): ?>
              <?php if ($i === $page): ?>
                <span class="receipt-pg-btn receipt-pg-current"><?= (int) $i ?></span>
              <?php else: ?>
                <a class="receipt-pg-btn receipt-pg-num" href="<?= htmlspecialchars(build_receipt_page_url($i), ENT_QUOTES, 'UTF-8') ?>"><?= (int) $i ?></a>
              <?php endif; ?>
            <?php endfor; ?>

            <?php if ($end < $total_pages): ?>
              <?php if ($end < $total_pages - 1): ?>
                <span class="receipt-pg-ellipsis">…</span>
              <?php endif; ?>
              <a class="receipt-pg-btn receipt-pg-num" href="<?= htmlspecialchars(build_receipt_page_url($total_pages), ENT_QUOTES, 'UTF-8') ?>"><?= (int) $total_pages ?></a>
            <?php endif; ?>

            <?php if ($page < $total_pages): ?>
              <a class="receipt-pg-btn" href="<?= htmlspecialchars(build_receipt_page_url($page + 1), ENT_QUOTES, 'UTF-8') ?>">Next</a>
            <?php else: ?>
              <span class="receipt-pg-btn receipt-pg-disabled">Next</span>
            <?php endif; ?>
          </div>

          <div class="receipt-pagination__per-page">
            <label for="per-page-select">Rows per page:</label>
            <select id="per-page-select" class="receipt-per-page-select" onchange="var u=new URL(window.location.href);u.searchParams.set('per_page',this.value);u.searchParams.delete('page');window.location.href=u.toString();">
              <option value="10"<?= $per_page === 10 ? ' selected' : '' ?>>10</option>
              <option value="25"<?= $per_page === 25 ? ' selected' : '' ?>>25</option>
              <option value="50"<?= $per_page === 50 ? ' selected' : '' ?>>50</option>
            </select>
          </div>
        </div>
        <?php endif; ?>
      </div>
    </main>
  </div>
</body>
</html>
