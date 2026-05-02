<?php

/**
 * borrower/index.php — Borrower Dashboard (US5, FR-016–FR-019)
 *
 * Displays:
 *   - Active / overdue loans with due dates (T026)
 *   - Loan history with return dates and fine amounts (T027)
 *   - Pending reservations with queue position and cancel option (T028)
 *
 * Protected: Borrower role only (FR-029).
 */

// RBAC guard — must appear before any HTML output (FR-034)
$allowed_roles = ['borrower'];
require_once __DIR__ . '/../includes/auth_guard.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/circulation.php';

$pdo     = get_db();
$user_id = (int) $_SESSION['user_id'];

// Flash messages
$flash_error   = $_SESSION['flash_error']   ?? '';
$flash_success = $_SESSION['flash_success'] ?? '';
$renewal_block = $_SESSION['renewal_block'] ?? null;
unset($_SESSION['flash_error'], $_SESSION['flash_success'], $_SESSION['renewal_block']);

$renewal_block_title = 'Renewal blocked';
$renewal_block_message = '';
if (is_array($renewal_block)) {
  $renewal_block_title = (string) ($renewal_block['title'] ?? $renewal_block_title);
  $renewal_block_message = (string) ($renewal_block['message'] ?? '');
} elseif (is_string($renewal_block)) {
  $renewal_block_message = $renewal_block;
}
$show_renewal_modal = $renewal_block_message !== '';

// T026 — Active loans
$has_renewal_count = circulation_column_exists($pdo, 'renewal_count');
$renewal_select = $has_renewal_count ? ', c.renewal_count' : '';

$active_stmt = $pdo->prepare(
  'SELECT c.id, c.checkout_date, c.due_date, c.status' . $renewal_select . ', b.title
       FROM Circulation c
       JOIN Books       b ON c.book_id = b.id
      WHERE c.user_id = ? AND c.status IN (\'active\', \'overdue\')
      ORDER BY c.due_date ASC'
);
$active_stmt->execute([$user_id]);
$active_loans = $active_stmt->fetchAll();

// T027 — Loan history (returned, last 20)
$history_stmt = $pdo->prepare(
  'SELECT c.return_date, c.fine_amount, c.fine_paid, b.title
       FROM Circulation c
       JOIN Books       b ON c.book_id = b.id
      WHERE c.user_id = ? AND c.status = \'returned\'
      ORDER BY c.return_date DESC
      LIMIT 20'
);
$history_stmt->execute([$user_id]);
$loan_history = $history_stmt->fetchAll();

// Bug fix: expire stale pending reservations before displaying them so the
// pending list and queue positions reflect reality.
expire_stale_reservations($pdo);

// T028 — Pending reservations with queue position (optimized: avoid N+1 query)
// First, fetch all pending reservations for this user
$res_stmt = $pdo->prepare(
  'SELECT r.id, r.reserved_at, r.expires_at, r.book_id, b.title
   FROM Reservations r
   JOIN Books b ON r.book_id = b.id
   WHERE r.user_id = ? AND r.status = \'pending\'
   ORDER BY r.reserved_at ASC'
);
$res_stmt->execute([$user_id]);
$pending_reservations_raw = $res_stmt->fetchAll();

// Build a map of book_id => [reservation rows for that book] to calculate positions
$book_reservations = [];
foreach ($pending_reservations_raw as $res) {
  $book_id = (int) $res['book_id'];
  if (!isset($book_reservations[$book_id])) {
    $book_reservations[$book_id] = [];
  }
  $book_reservations[$book_id][] = $res;
}

// Now calculate queue position for each reservation (O(n) instead of O(n²))
$pending_reservations = [];
foreach ($pending_reservations_raw as $res) {
  $book_id = (int) $res['book_id'];
  $position = 1;
  foreach ($book_reservations[$book_id] as $other_res) {
    if ($other_res['reserved_at'] < $res['reserved_at']) {
      $position++;
    }
  }
  $res['queue_position'] = $position;
  $pending_reservations[] = $res;
}

// ─── Feature 007 dashboard queries ──────────────────────────────────────────

// Q1: Currently borrowed count
$q1 = $pdo->prepare(
  "SELECT COUNT(*) AS cnt FROM Circulation WHERE user_id = ? AND status IN ('active', 'overdue')"
);
$q1->execute([$user_id]);
$currently_borrowed = (int) $q1->fetchColumn();

// Q2: Total loans ever
$q2 = $pdo->prepare('SELECT COUNT(*) AS cnt FROM Circulation WHERE user_id = ?');
$q2->execute([$user_id]);
$total_borrowed = (int) $q2->fetchColumn();

// Q3: Next return date + due-soon count (within 3 days)
$q3 = $pdo->prepare(
  "SELECT MIN(due_date) AS next_return,
          COUNT(*) AS due_soon_count
     FROM Circulation
    WHERE user_id = ?
      AND status IN ('active', 'overdue')
      AND due_date <= DATE_ADD(NOW(), INTERVAL 3 DAY)"
);
$q3->execute([$user_id]);
$due_row        = $q3->fetch();
$next_return    = $due_row['next_return'] ?? null;
$due_soon_count = (int) ($due_row['due_soon_count'] ?? 0);

// Q4: Pending reservations count (from already-fetched $pending_reservations)
$pending_count = count($pending_reservations);

// Q4b: Approved reservations — ready for pickup (EPIC 1: librarian approval → borrower view)
$approved_at_select = reservation_column_exists($pdo, 'approved_at')
  ? 'r.approved_at AS approved_at'
  : 'NULL AS approved_at';

$approved_stmt = $pdo->prepare(
  "SELECT r.id, {$approved_at_select}, r.expires_at, b.title, b.author
     FROM Reservations r
     JOIN Books b ON r.book_id = b.id
    WHERE r.user_id = ? AND r.status = 'approved'
    ORDER BY r.expires_at ASC"
);
$approved_stmt->execute([$user_id]);
$approved_reservations = $approved_stmt->fetchAll();
$approved_count = count($approved_reservations);

// Q5: Category distribution for donut chart
$q5 = $pdo->prepare(
  "SELECT b.category, COUNT(*) AS cnt
     FROM Circulation c
     JOIN Books b ON c.book_id = b.id
    WHERE c.user_id = ?
    GROUP BY b.category
    ORDER BY cnt DESC"
);
$q5->execute([$user_id]);
$categories = $q5->fetchAll();

// Q6: Monthly checkout activity — last 12 months
$q6 = $pdo->prepare(
  "SELECT DATE_FORMAT(checkout_date, '%Y-%m') AS month, COUNT(*) AS cnt
     FROM Circulation
    WHERE user_id = ?
      AND checkout_date >= DATE_SUB(NOW(), INTERVAL 11 MONTH)
    GROUP BY month"
);
$q6->execute([$user_id]);
$monthly_raw = [];
foreach ($q6->fetchAll() as $row) {
  $monthly_raw[$row['month']] = (int) $row['cnt'];
}
// Fill all 12 slots even when no loans that month
$month_labels = [];
$month_values = [];
for ($i = 11; $i >= 0; $i--) {
  $key            = date('Y-m', strtotime("-{$i} months"));
  $month_labels[] = date('M Y', strtotime("-{$i} months"));
  $month_values[] = $monthly_raw[$key] ?? 0;
}

// Q7: Reading timeline — last 10 loans
$q7 = $pdo->prepare(
  "SELECT b.title, c.checkout_date, c.due_date, c.return_date, c.status
     FROM Circulation c
     JOIN Books b ON c.book_id = b.id
    WHERE c.user_id = ?
    ORDER BY c.checkout_date DESC
    LIMIT 10"
);
$q7->execute([$user_id]);
$timeline = $q7->fetchAll();

$name       = htmlspecialchars($_SESSION['full_name'], ENT_QUOTES, 'UTF-8');
$logout_url = htmlspecialchars(BASE_URL . 'logout.php', ENT_QUOTES, 'UTF-8');

$current_page = 'borrower.index';
$pageTitle    = 'My Account | Library System';
$extraStyles = [
  'https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700&family=Playfair+Display:wght@700&display=swap',
  BASE_URL . 'assets/css/borrower-redesign.css'
];
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <?php require_once __DIR__ . '/../includes/head.php'; ?>
  <style>
    .rd-modal {
      position: fixed;
      inset: 0;
      display: flex;
      align-items: center;
      justify-content: center;
      z-index: 9999;
      padding: 1rem;
    }
    .rd-modal-backdrop {
      position: absolute;
      inset: 0;
      background: rgba(0, 0, 0, 0.55);
      backdrop-filter: blur(4px);
    }
    .rd-modal-panel {
      position: relative;
      width: 100%;
      max-width: 520px;
      z-index: 1;
    }
  </style>
</head>

<body class="dashboard-redesign borrower-dashboard-new">
  <div class="app-shell">
    <?php require_once __DIR__ . '/../includes/sidebar-borrower.php'; ?>

    <main class="main-content">
      <div class="rd-header">
        <div>
          <h1>Dashboard</h1>
          <p>Welcome back, <?= $name ?>. Here's what's happening with your account.</p>
        </div>
        <div>
          <a class="rd-btn rd-btn-primary" href="<?= htmlspecialchars(BASE_URL . 'borrower/catalog.php', ENT_QUOTES, 'UTF-8') ?>">
            <svg width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor" style="margin-right:8px;vertical-align:middle;display:inline;"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" /></svg>
            <span style="vertical-align:middle;">Browse Catalog</span>
          </a>
        </div>
      </div>

      <?php if ($flash_error !== ''): ?>
        <div class="flash flash-error" role="alert" style="animation:slideDown 0.4s cubic-bezier(0.16,1,0.3,1) forwards;" aria-live="assertive"><?= htmlspecialchars($flash_error, ENT_QUOTES, 'UTF-8') ?></div>
      <?php endif; ?>
      <?php if ($flash_success !== ''): ?>
        <div class="flash flash-success" role="alert" style="animation:slideDown 0.4s cubic-bezier(0.16,1,0.3,1) forwards;" aria-live="polite"><?= htmlspecialchars($flash_success, ENT_QUOTES, 'UTF-8') ?></div>
      <?php endif; ?>

      <?php if ($show_renewal_modal): ?>
        <div id="renewal-block-modal" class="rd-modal" aria-hidden="false">
          <div class="rd-modal-backdrop" id="renewal-block-modal-close" aria-hidden="true"></div>
          <div class="rd-modal-panel rd-card" role="dialog" aria-modal="true" aria-labelledby="renewal-block-modal-title">
            <h2 id="renewal-block-modal-title" style="margin-top:0; color:var(--rd-primary);"><?= htmlspecialchars($renewal_block_title, ENT_QUOTES, 'UTF-8') ?></h2>
            <p style="color:var(--rd-text-muted); margin-bottom:1.5rem;">
              <?= htmlspecialchars($renewal_block_message, ENT_QUOTES, 'UTF-8') ?>
            </p>
            <div style="display:flex; justify-content:flex-end;">
              <button type="button" class="rd-btn rd-btn-primary" id="renewal-block-modal-ok">OK</button>
            </div>
          </div>
        </div>
      <?php endif; ?>

      <?php if ($due_soon_count > 0): ?>
        <div class="rd-alert" style="background: rgba(245, 158, 11, 0.1); border-left-color: var(--rd-warning);">
          <div class="rd-alert-icon">⚠️</div>
          <div>
            <h4 style="margin:0 0 0.25rem 0; color: #f59e0b; font-size:1.1rem; font-weight:600;">Heads up!</h4>
            <p style="margin:0; color: #fbbf24;">
              <?= $due_soon_count === 1 ? '1 book is' : $due_soon_count . ' books are' ?> due soon
              <?php if ($next_return !== null): ?>
                — next return: <strong><?= htmlspecialchars(date('d M Y', strtotime($next_return)), ENT_QUOTES, 'UTF-8') ?></strong>
              <?php endif; ?>
            </p>
          </div>
        </div>
      <?php endif; ?>

      <!-- Approved Reservations Alert — Redesigned -->
      <?php if (!empty($approved_reservations)): ?>
        <div class="rd-pickup-card">
          <div class="rd-pickup-header">
            <h4 class="rd-pickup-heading">
              <svg width="22" height="22" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
              Ready for Pickup
            </h4>
            <div class="rd-pickup-badge-group">
              <span class="rd-badge rd-b-green"><?= count($approved_reservations) ?> approved</span>
            </div>
          </div>
          <p class="rd-pickup-body">
            <?= count($approved_reservations) > 1 ? 'Your reservations have' : 'Your reservation has' ?> been approved by the librarian.
            Visit the library desk to borrow <?= count($approved_reservations) > 1 ? 'these books' : 'this book' ?> before <strong>they expire</strong>.
          </p>
          <div class="rd-pickup-table-wrap">
            <table class="rd-pickup-table">
              <thead>
                <tr><th>Book</th><th>Approved On</th><th>Pickup By</th></tr>
              </thead>
              <tbody>
                <?php foreach ($approved_reservations as $ar): ?>
                  <?php
                    $expires_ts     = strtotime($ar['expires_at']);
                    $hrs_left       = (int)ceil(($expires_ts - time()) / 3600);
                    $days_left      = (int)ceil(($expires_ts - time()) / 86400);
                    $expires_soon   = $hrs_left <= 24 && $hrs_left > 0;
                    $expires_crit   = $hrs_left <= 6 && $hrs_left > 0;
                  ?>
                  <tr>
                    <td><?= htmlspecialchars($ar['title'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td class="rd-pickup-date"><?= $ar['approved_at'] ? htmlspecialchars(date('d M Y', strtotime($ar['approved_at'])), ENT_QUOTES, 'UTF-8') : '—' ?></td>
                    <td>
                      <span class="rd-pickup-date <?= $expires_soon ? 'rd-pickup-date--urgent' : '' ?>">
                        <?= htmlspecialchars(date('d M Y', $expires_ts), ENT_QUOTES, 'UTF-8') ?>
                      </span>
                      <?php if ($expires_crit): ?>
                        <span class="rd-pickup-expiry-badge rd-pickup-expiry-badge--critical">⚠️ Expiring today!</span>
                      <?php elseif ($expires_soon): ?>
                        <span class="rd-pickup-expiry-badge">⏳ <?= $days_left ?> day<?= $days_left !== 1 ? 's' : '' ?> left</span>
                      <?php endif; ?>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>
      <?php endif; ?>

      <div class="rd-stats-grid">
        <div class="rd-card rd-stat">
          <div class="rd-stat-icon rd-i-blue">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M4 19.5v-15A2.5 2.5 0 016.5 2H20v20H6.5a2.5 2.5 0 010-5H20"/><path d="M12 6v7"/><path d="M9 9h6"/></svg>
          </div>
          <div class="rd-stat-val rd-stat-val-serif"><?= $currently_borrowed ?></div>
          <div class="rd-stat-title">Currently Borrowed</div>
          <div class="rd-stat-sub">active &amp; overdue</div>
        </div>
        <div class="rd-card rd-stat">
          <div class="rd-stat-icon rd-i-green">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M2 3h6a4 4 0 014 4v14a3 3 0 00-3-3H2z"/><path d="M22 3h-6a4 4 0 00-4 4v14a3 3 0 013-3h7z"/></svg>
          </div>
          <div class="rd-stat-val rd-stat-val-serif"><?= $total_borrowed ?></div>
          <div class="rd-stat-title">Total Books Read</div>
          <div class="rd-stat-sub">all time</div>
        </div>
        <div class="rd-card rd-stat">
          <div class="rd-stat-icon rd-i-orange">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/></svg>
          </div>
          <div class="rd-stat-val rd-stat-val-serif"><?= $due_soon_count ?></div>
          <div class="rd-stat-title">Due Soon</div>
          <div class="rd-stat-sub">within 3 days</div>
        </div>
        <div class="rd-card rd-stat">
          <div class="rd-stat-icon rd-i-purple">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21l-7-5-7 5V5a2 2 0 012-2h10a2 2 0 012 2z"/></svg>
          </div>
          <div class="rd-stat-val rd-stat-val-serif"><?= $pending_count ?></div>
          <div class="rd-stat-title">Reservations</div>
          <div class="rd-stat-sub">pending</div>
        </div>
      </div>

      <div class="rd-layout-grid rd-stagger">

        <!-- Left Column -->
        <div>
          <!-- Active Loans -->
          <div class="rd-section-title">

            <svg width="24" height="24" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253" /></svg>
            Active Loans
          </div>
          <div class="rd-card" style="margin-bottom: 2rem;">
            <?php if (empty($active_loans)): ?>
              <div style="text-align:center; padding: 2rem 0; color: var(--rd-text-muted);">
                <div style="font-size: 3rem; margin-bottom: 1rem; opacity:0.5;">📚</div>
                <p>No active or overdue loans.</p>
              </div>
            <?php else: ?>
              <div style="overflow-x:auto;">
                <table class="rd-table-glass">
                  <thead>
                    <tr>
                      <th>Book</th>
                      <th>Checked Out</th>
                      <th>Due Date</th>
                      <th>Status</th>
                      <th>Action</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php foreach ($active_loans as $loan): ?>
                      <tr>
                        <td><strong><?= htmlspecialchars($loan['title'], ENT_QUOTES, 'UTF-8') ?></strong><br><small style="color:var(--rd-text-muted);">#<?= (int) $loan['id'] ?></small></td>
                        <td><?= htmlspecialchars($loan['checkout_date'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars($loan['due_date'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td>
                          <?php if ($loan['status'] === 'overdue'): ?>
                            <span class="rd-badge rd-b-red">Overdue</span>
                          <?php else: ?>
                            <span class="rd-badge rd-b-blue">Active</span>
                          <?php endif; ?>
                        </td>
                        <td>
                          <?php
                            $renew_count = $has_renewal_count && isset($loan['renewal_count']) ? (int)$loan['renewal_count'] : 0;
                            $renew_eligible = $loan['status'] === 'active'
                                && strtotime($loan['due_date']) - time() <= 86400
                                && strtotime($loan['due_date']) > time()
                                && $renew_count < 1;
                          ?>
                          <?php if ($renew_eligible): ?>
                          <form method="POST" action="<?= htmlspecialchars(BASE_URL . 'borrower/renew.php', ENT_QUOTES, 'UTF-8') ?>" style="margin:0;">
                            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                            <input type="hidden" name="loan_id" value="<?= (int) $loan['id'] ?>">
                            <button type="submit" class="rd-btn-action">Renew</button>
                          </form>
                          <?php else: ?>
                            <span style="color:var(--rd-text-muted);font-size:0.85rem;">Not available</span>
                          <?php endif; ?>
                        </td>
                        </tr>
                      <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            <?php endif; ?>
          </div>

          <!-- Pending Reservations -->
          <div class="rd-section-title">
            <svg width="24" height="24" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
            Pending Reservations
          </div>
          <div class="rd-card" style="margin-bottom: 2rem;">
            <?php if (empty($pending_reservations)): ?>
              <div style="text-align:center; padding: 2rem 0; color: var(--rd-text-muted);">
                <div style="font-size: 3rem; margin-bottom: 1rem; opacity:0.5;">🔖</div>
                <p>No pending reservations.</p>
              </div>
            <?php else: ?>
              <div style="overflow-x:auto;">
                <table class="rd-table-glass">
                  <thead>
                    <tr>
                      <th>Book</th>
                      <th>Reserved</th>
                      <th>Expires</th>
                      <th>Queue #</th>
                      <th>Cancel</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php foreach ($pending_reservations as $res): ?>
                      <tr>
                        <td><strong><?= htmlspecialchars($res['title'], ENT_QUOTES, 'UTF-8') ?></strong></td>
                        <td><?= htmlspecialchars($res['reserved_at'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars($res['expires_at'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td><span class="rd-badge rd-b-orange">#<?= (int) $res['queue_position'] ?></span></td>
                        <td>
                          <form method="POST" action="<?= htmlspecialchars(BASE_URL . 'borrower/reserve.php', ENT_QUOTES, 'UTF-8') ?>" style="margin:0;">
                            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                            <input type="hidden" name="action" value="cancel">
                            <input type="hidden" name="reservation_id" value="<?= (int) $res['id'] ?>">
                            <button type="submit" class="rd-btn-action rd-btn-danger">Cancel</button>
                          </form>
                        </td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            <?php endif; ?>
          </div>

          <!-- Charts Area -->
          <div class="rd-section-title">
            <svg width="24" height="24" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z" /></svg>
            Reading Insights
          </div>
          <div class="rd-charts">
            <div class="rd-card">
              <h4 style="margin-top:0; margin-bottom:1rem;color:var(--rd-primary);font-weight:600;">Books by Category</h4>
              <div class="rd-chart-wrap">
                <canvas id="categoryChart"></canvas>
              </div>
            </div>
            <div class="rd-card">
              <h4 style="margin-top:0; margin-bottom:1rem;color:var(--rd-primary);font-weight:600;">Monthly Activity</h4>
              <div class="rd-chart-wrap">
                <canvas id="monthlyChart"></canvas>
              </div>
            </div>
          </div>
        </div>

        <!-- Right Column -->
        <div>
          <!-- Timeline -->
          <div class="rd-section-title">
            <svg width="24" height="24" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
            Activity Timeline
          </div>
          <div class="rd-card" style="margin-bottom: 2rem;">
            <?php if (!empty($timeline)): ?>
              <ul class="rd-timeline">
                <?php foreach ($timeline as $item): ?>
                  <?php
                  $dotClass = match ($item['status']) {
                    'active'   => 'rd-dot-active',
                    'overdue'  => 'rd-dot-overdue',
                    'returned' => 'rd-dot-returned',
                    default    => '',
                  };
                  $dateLabel = $item['return_date']
                    ? date('d M Y', strtotime($item['return_date']))
                    : 'Due ' . date('d M Y', strtotime($item['due_date']));
                  ?>
                  <li class="rd-timeline-item">
                    <div class="rd-timeline-dot <?= $dotClass ?>"></div>
                    <span class="rd-timeline-title"><?= htmlspecialchars($item['title'], ENT_QUOTES, 'UTF-8') ?></span>
                    <span class="rd-timeline-date"><?= htmlspecialchars($dateLabel, ENT_QUOTES, 'UTF-8') ?></span>
                  </li>
                <?php endforeach; ?>
              </ul>
            <?php else: ?>
              <p style="color:var(--rd-text-muted); text-align:center;">No recent activity.</p>
            <?php endif; ?>
          </div>

        </div>

      </div>

    </main>
  </div>

  <script>
    (function() {
      var modal = document.getElementById('renewal-block-modal');
      if (!modal) return;

      var closeBg = document.getElementById('renewal-block-modal-close');
      var okBtn = document.getElementById('renewal-block-modal-ok');

      function closeModal() {
        modal.style.display = 'none';
        modal.setAttribute('aria-hidden', 'true');
      }

      window.closeRenewalBlockModal = closeModal;

      if (window.renewalBlockModalHandlersBound) return;
      window.renewalBlockModalHandlersBound = true;

      if (closeBg) closeBg.addEventListener('click', closeModal);
      if (okBtn) okBtn.addEventListener('click', closeModal);
      document.addEventListener('keydown', function(event) {
        if (event.key === 'Escape') {
          closeModal();
        }
      });
    }());
  </script>

  <!-- ── Chart.js ─────────────────────────────────────────────────────────── -->
  <script src="<?= htmlspecialchars(BASE_URL, ENT_QUOTES, 'UTF-8') ?>assets/js/chart.min.js"></script>
  <script>
    (function() {
      if (typeof Chart === 'undefined') return;

      Chart.defaults.font.family = "'Outfit', system-ui, sans-serif";
      Chart.defaults.color = '#8a8278';

      // Category Chart
      var catCtx = document.getElementById('categoryChart');
      if (catCtx && <?= count($categories) ?> > 0) {
        var catLabels = <?= json_encode(array_map(fn($r) => $r['category'] ?: 'Uncategorised', $categories), JSON_HEX_TAG | JSON_HEX_AMP) ?>;
        var catValues = <?= json_encode(array_map(fn($r) => (int) $r['cnt'], $categories), JSON_HEX_TAG | JSON_HEX_AMP) ?>;
        new Chart(catCtx, {
          type: 'doughnut',
          data: {
            labels: catLabels,
            datasets: [{
              data: catValues,
              backgroundColor: ['#c9a84c', '#dfc476', '#b8942d', '#9b7625', '#fef08a', '#10b981', '#34d399'],
              borderWidth: 0,
              hoverOffset: 4
            }]
          },
          options: {
            responsive: true,
            maintainAspectRatio: false,
            cutout: '70%',
            plugins: {
              legend: { position: 'bottom', labels: { boxWidth: 12, padding: 15 } }
            }
          }
        });
      }

      // Monthly Chart
      var monthCtx = document.getElementById('monthlyChart');
      if (monthCtx) {
        var monthLabels = <?= json_encode($month_labels, JSON_HEX_TAG | JSON_HEX_AMP) ?>;
        var monthValues = <?= json_encode($month_values, JSON_HEX_TAG | JSON_HEX_AMP) ?>;

        let gradient = monthCtx.getContext('2d').createLinearGradient(0, 0, 0, 400);
        gradient.addColorStop(0, 'rgba(201, 168, 76, 0.8)');
        gradient.addColorStop(1, 'rgba(201, 168, 76, 0.1)');

        new Chart(monthCtx, {
          type: 'bar',
          data: {
            labels: monthLabels.map(l => l.split(' ')[0]),
            datasets: [{
              label: 'Borrowed',
              data: monthValues,
              backgroundColor: gradient,
              borderRadius: 6,
              borderWidth: 0
            }]
          },
          options: {
            responsive: true,
            maintainAspectRatio: false,
            scales: {
              y: { beginAtZero: true, grid: { color: 'rgba(255,255,255,0.03)', drawBorder: false }, ticks: { stepSize: 1 } },
              x: { grid: { display: false, drawBorder: false } }
            },
            plugins: { legend: { display: false } }
          }
        });
      }

    }());
  </script>
  </body>

</html>
