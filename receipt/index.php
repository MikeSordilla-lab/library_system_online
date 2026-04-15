<?php

$allowed_roles = ['admin', 'librarian', 'borrower'];
require_once __DIR__ . '/../includes/auth_guard.php';
require_once __DIR__ . '/../includes/receipts.php';

$pdo = get_db();
$viewer_id = (int) $_SESSION['user_id'];
$viewer_role = (string) $_SESSION['role'];

$tickets = get_receipt_tickets($pdo, $viewer_id, $viewer_role, 150);
$base_url = defined('BASE_URL') ? (string) constant('BASE_URL') : '/';

$current_page = 'receipt.index';
$pageTitle = 'Receipts | Library System';

?>
<!DOCTYPE html>
<html lang="en">
<head>
  <?php require_once __DIR__ . '/../includes/head.php'; ?>
</head>
<body>
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
      <div class="page-header">
        <h1>Receipts &amp; Tickets</h1>
        <p>View and reprint recent transaction tickets.</p>
      </div>

      <div class="section-card">
        <div class="section-card__header">
          <span class="section-card__title">Recent Receipts</span>
        </div>

        <?php if (empty($tickets)): ?>
          <div class="empty-state">
            <span class="empty-state__icon">&#128196;</span>
            <p>No receipts found.</p>
          </div>
        <?php else: ?>
          <div class="tbl-wrapper">
            <table class="tbl">
              <thead>
                <tr>
                  <th>Receipt #</th>
                  <th>Type</th>
                  <th>Issued</th>
                  <th>Borrower ID</th>
                  <th>Amount</th>
                  <th>Status/Format</th>
                  <th>Action</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($tickets as $ticket): ?>
                  <tr>
                    <td><?= htmlspecialchars((string) $ticket['receipt_no'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars((string) $ticket['type'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars((string) $ticket['issued_at'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= (int) $ticket['patron_user_id'] ?></td>
                    <td>
                      <?php if ($ticket['amount'] !== null): ?>
                        <?= htmlspecialchars(number_format((float) $ticket['amount'], 2), ENT_QUOTES, 'UTF-8') ?>
                        <?= htmlspecialchars((string) $ticket['currency'], ENT_QUOTES, 'UTF-8') ?>
                      <?php else: ?>
                        -
                      <?php endif; ?>
                    </td>
                    <td>
                      <?= htmlspecialchars((string) ($ticket['status'] ?? 'issued'), ENT_QUOTES, 'UTF-8') ?> /
                      <?= htmlspecialchars((string) ($ticket['format'] ?? 'thermal'), ENT_QUOTES, 'UTF-8') ?>
                    </td>
                    <td>
                      <a class="btn-confirm" style="padding:5px 12px; font-size:.8125rem;" href="<?= htmlspecialchars($base_url . 'receipt/view.php?no=' . rawurlencode((string) $ticket['receipt_no']), ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener">Open</a>
                      <a class="btn-confirm" style="padding:5px 12px; font-size:.8125rem; margin-left: 4px;" href="<?= htmlspecialchars($base_url . 'receipt/kiosk.php?no=' . rawurlencode((string) $ticket['receipt_no']), ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener">Kiosk</a>
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
