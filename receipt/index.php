<?php

$allowed_roles = ['librarian', 'borrower'];
require_once __DIR__ . '/../includes/auth_guard.php';
require_once __DIR__ . '/../includes/receipts.php';

$pdo = get_db();
$viewer_id = (int) $_SESSION['user_id'];
$viewer_role = (string) $_SESSION['role'];

$tickets = get_receipt_tickets($pdo, $viewer_id, $viewer_role, 150);
$base_url = defined('BASE_URL') ? (string) constant('BASE_URL') : '/';

$current_page = 'receipt.index';
$pageTitle = 'Receipts | Library System';
$extraStyles = [
  'https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700&display=swap',
  $base_url . 'assets/css/borrower-redesign.css'
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <?php require_once __DIR__ . '/../includes/head.php'; ?>
</head>
<body class="dashboard-redesign borrower-dashboard-new">
  <div class="app-shell">
    <?php
    if ($viewer_role === 'admin') {
      require_once __DIR__ . '/../includes/sidebar-admin.php';
    } elseif ($viewer_role === 'librarian') {
      require_once __DIR__ . '/../includes/sidebar-librarian.php';
    } else {
      require_once __DIR__ . '/../includes/sidebar-borrower.php';
    }
    ?>

    <main class="main-content">
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
    </main>
  </div>
</body>
</html>
