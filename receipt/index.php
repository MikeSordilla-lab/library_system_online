<?php

$allowed_roles = ['librarian', 'borrower'];
require_once __DIR__ . '/../includes/auth_guard.php';
require_once __DIR__ . '/../includes/receipts.php';

$pdo = get_db();
$viewer_id = (int) $_SESSION['user_id'];
$viewer_role = (string) $_SESSION['role'];

$page = max(1, (int) ($_GET['page'] ?? 1));
$per_page = 20;
$per_page = max(5, min(100, $per_page));
$offset = ($page - 1) * $per_page;

$where_sql = '';
$count_params = [];
if ($viewer_role === 'borrower') {
  $where_sql = 'WHERE patron_user_id = ?';
  $count_params = [$viewer_id];
}

$count_sql = "SELECT COUNT(*) FROM Receipt_Tickets {$where_sql}";
$count_stmt = $pdo->prepare($count_sql);
$count_stmt->execute($count_params);
$total_tickets = (int) $count_stmt->fetchColumn();
$total_pages = max(1, (int) ceil($total_tickets / $per_page));
$page = min($page, $total_pages);
$offset = ($page - 1) * $per_page;

if ($viewer_role === 'librarian') {
  $tickets_stmt = $pdo->prepare(
    'SELECT ' . receipt_select_clause($pdo) . '
     FROM Receipt_Tickets
     ORDER BY issued_at DESC, id DESC
     LIMIT ' . (int) $per_page . ' OFFSET ' . (int) $offset
  );
  $tickets_stmt->execute();
  $tickets = $tickets_stmt->fetchAll() ?: [];
} else {
  $tickets_stmt = $pdo->prepare(
    'SELECT ' . receipt_select_clause($pdo) . '
     FROM Receipt_Tickets
     WHERE patron_user_id = ?
     ORDER BY issued_at DESC, id DESC
     LIMIT ' . (int) $per_page . ' OFFSET ' . (int) $offset
  );
  $tickets_stmt->execute([$viewer_id]);
  $tickets = $tickets_stmt->fetchAll() ?: [];
}

$base_url = defined('BASE_URL') ? (string) constant('BASE_URL') : '/';

function build_receipt_page_url(int $p): string
{
  global $base_url;
  return $base_url . 'receipt/?page=' . $p;
}

$current_page = 'receipt.index';
$pageTitle = 'Receipts | Library System';
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
.rd-pagination {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 1rem;
  padding: 1rem;
  background: var(--rd-card-bg, #fff);
  border: 1px solid var(--rd-border, #e5e7eb);
  border-radius: 0.75rem;
  flex-wrap: wrap;
}
.rd-pagination-info {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  font-size: 0.875rem;
  color: var(--rd-text-muted, #6b7280);
}
.rd-pagination-count {
  color: var(--rd-text-secondary, #9ca3af);
}
.rd-pagination-controls {
  display: flex;
  align-items: center;
  gap: 0.25rem;
  flex-wrap: wrap;
}
.rd-pagination-btn {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  padding: 0.375rem 0.625rem;
  font-size: 0.8125rem;
  font-weight: 500;
  color: var(--rd-primary, #3b82f6);
  background: transparent;
  border: 1px solid var(--rd-border, #e5e7eb);
  border-radius: 0.375rem;
  text-decoration: none;
  transition: all 0.15s ease;
  min-width: 2.25rem;
}
.rd-pagination-btn:hover:not(.rd-pagination-disabled):not(.rd-pagination-current) {
  background: var(--rd-primary-light, #eff6ff);
  border-color: var(--rd-primary, #3b82f6);
  color: var(--rd-primary-dark, #1d4ed8);
}
.rd-pagination-current {
  background: var(--rd-primary, #3b82f6);
  border-color: var(--rd-primary, #3b82f6);
  color: #fff;
  cursor: default;
}
.rd-pagination-disabled {
  color: var(--rd-text-muted, #9ca3af);
  cursor: not-allowed;
  opacity: 0.6;
}
.rd-pagination-ellipsis {
  padding: 0 0.25rem;
  color: var(--rd-text-muted, #9ca3af);
}
@media (max-width: 640px) {
  .rd-pagination {
    flex-direction: column;
    align-items: stretch;
  }
  .rd-pagination-info {
    justify-content: center;
  }
  .rd-pagination-controls {
    justify-content: center;
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

      <div class="rd-card" style="margin-bottom: 2rem;">
        <?php if (empty($tickets)): ?>
          <div style="text-align:center; padding: 2rem 0; color: var(--rd-text-muted);">
            <div style="font-size: 3rem; margin-bottom: 1rem; opacity:0.5;">📄</div>
            <p>No receipts found.</p>
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
                  <th>Status / Format</th>
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
                          <?= htmlspecialchars(number_format((float) $ticket['amount'], 2), ENT_QUOTES, 'UTF-8') ?>
                          <?= htmlspecialchars((string) $ticket['currency'], ENT_QUOTES, 'UTF-8') ?>
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
                        <span class="rd-badge rd-b-orange">
                          <?= htmlspecialchars((string) ($ticket['format'] ?? 'thermal'), ENT_QUOTES, 'UTF-8') ?>
                        </span>
                      </div>
                    </td>
                    <td>
                      <div style="display:flex; gap:0.25rem; flex-wrap:wrap; align-items:center;">
                        <a class="rd-btn-action" href="<?= htmlspecialchars($base_url . 'receipt/view.php?no=' . rawurlencode((string) $ticket['receipt_no']), ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener">Open</a>
                        <a class="rd-btn-action" href="<?= htmlspecialchars($base_url . 'receipt/kiosk.php?no=' . rawurlencode((string) $ticket['receipt_no']), ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener">Kiosk</a>
                      </div>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </div>

      <?php if ($total_pages > 1): ?>
        <nav class="rd-pagination" aria-label="Receipts pagination">
          <div class="rd-pagination-info">
            <span>Page <?= (int) $page ?> of <?= (int) $total_pages ?></span>
            <span class="rd-pagination-count">(<?= (int) $total_tickets ?> total)</span>
          </div>
          <div class="rd-pagination-controls">
            <?php if ($page > 1): ?>
              <a class="rd-pagination-btn" href="<?= htmlspecialchars(build_receipt_page_url(1), ENT_QUOTES, 'UTF-8') ?>" aria-label="First page">« First</a>
              <a class="rd-pagination-btn" href="<?= htmlspecialchars(build_receipt_page_url($page - 1), ENT_QUOTES, 'UTF-8') ?>" aria-label="Previous page">‹ Prev</a>
            <?php else: ?>
              <span class="rd-pagination-btn rd-pagination-disabled">« First</span>
              <span class="rd-pagination-btn rd-pagination-disabled">‹ Prev</span>
            <?php endif; ?>

            <?php
            $start = max(1, $page - 2);
            $end = min($total_pages, $page + 2);
            if ($start > 1): ?>
              <a class="rd-pagination-btn" href="<?= htmlspecialchars(build_receipt_page_url(1), ENT_QUOTES, 'UTF-8') ?>">1</a>
              <?php if ($start > 2): ?>
                <span class="rd-pagination-ellipsis">…</span>
              <?php endif; ?>
            <?php endif; ?>

            <?php for ($i = $start; $i <= $end; $i++): ?>
              <?php if ($i === $page): ?>
                <span class="rd-pagination-btn rd-pagination-current"><?= (int) $i ?></span>
              <?php else: ?>
                <a class="rd-pagination-btn" href="<?= htmlspecialchars(build_receipt_page_url($i), ENT_QUOTES, 'UTF-8') ?>"><?= (int) $i ?></a>
              <?php endif; ?>
            <?php endfor; ?>

            <?php if ($end < $total_pages): ?>
              <?php if ($end < $total_pages - 1): ?>
                <span class="rd-pagination-ellipsis">…</span>
              <?php endif; ?>
              <a class="rd-pagination-btn" href="<?= htmlspecialchars(build_receipt_page_url($total_pages), ENT_QUOTES, 'UTF-8') ?>"><?= (int) $total_pages ?></a>
            <?php endif; ?>

            <?php if ($page < $total_pages): ?>
              <a class="rd-pagination-btn" href="<?= htmlspecialchars(build_receipt_page_url($page + 1), ENT_QUOTES, 'UTF-8') ?>" aria-label="Next page">Next ›</a>
              <a class="rd-pagination-btn" href="<?= htmlspecialchars(build_receipt_page_url($total_pages), ENT_QUOTES, 'UTF-8') ?>" aria-label="Last page">Last »</a>
            <?php else: ?>
              <span class="rd-pagination-btn rd-pagination-disabled">Next ›</span>
              <span class="rd-pagination-btn rd-pagination-disabled">Last »</span>
            <?php endif; ?>
          </div>
        </nav>
      <?php endif; ?>
    </main>
  </div>
</body>
</html>
