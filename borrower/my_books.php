<?php

/**
 * borrower/my_books.php — My Books: Full Borrowing Lifecycle (EPIC 1)
 *
 * Displays the complete lifecycle for the authenticated borrower:
 *   1. Active & Overdue Loans    — books currently borrowed + due dates
 *   2. Approved Reservations     — ready to be checked out
 *   3. Pending Reservations      — awaiting librarian decision
 *   4. Rejected Reservations     — declined by librarian (recent 10)
 *   5. Loan History              — returned books (last 30)
 *
 * POST action=return_request: (future hook — placeholder, actual return is
 * done by librarian via checkin.php)
 *
 * Protected: Borrower role only.
 */

$allowed_roles = ['borrower'];
require_once __DIR__ . '/../includes/auth_guard.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/circulation.php';
require_once __DIR__ . '/../includes/BorrowerAccountSummaryModule.php';

$pdo     = get_db();
$user_id = (int) $_SESSION['user_id'];

// Expire stale reservations before any display so statuses are accurate
expire_stale_reservations($pdo);

// Flash messages
$flash_error   = (string) ($_SESSION['flash_error']   ?? '');
$flash_success = (string) ($_SESSION['flash_success'] ?? '');
$flash_info    = (string) ($_SESSION['flash_info']    ?? '');
unset($_SESSION['flash_error'], $_SESSION['flash_success'], $_SESSION['flash_info']);

// Renewal block modal
$renewal_block = $_SESSION['renewal_block'] ?? null;
unset($_SESSION['renewal_block']);
$renewal_block_title = 'Renewal blocked';
$renewal_block_message = '';
if (is_array($renewal_block)) {
  $renewal_block_title = (string) ($renewal_block['title'] ?? $renewal_block_title);
  $renewal_block_message = (string) ($renewal_block['message'] ?? '');
} elseif (is_string($renewal_block)) {
  $renewal_block_message = $renewal_block;
}
$show_renewal_modal = $renewal_block_message !== '';

expire_stale_reservations($pdo);

$summary = BorrowerAccountSummaryModule::getSummary($pdo, $user_id);
$active_loans           = $summary['active_loans'];
$approved_reservations  = $summary['approved_reservations'];
$pending_reservations    = $summary['pending_reservations'];
$rejected_reservations  = $summary['rejected_reservations'];
$loan_history           = $summary['loan_history'];
$stats                  = $summary['stats'];

$current_page = 'borrower.my_books';
$pageTitle    = 'My Books | Library System';
$extraStyles = [
  'https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700&display=swap',
  BASE_URL . 'assets/css/borrower-redesign.css'
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <?php require_once __DIR__ . '/../includes/head.php'; ?>
</head>
<body class="dashboard-redesign borrower-dashboard-new">
  <div class="app-shell">
    <?php require_once __DIR__ . '/../includes/sidebar-borrower.php'; ?>
    <main class="main-content">
      <div class="rd-header">
        <div>
          <h1>My Books</h1>
          <p>Track every stage of your borrowing — from reservation to return.</p>
        </div>
      </div>

      <?php if ($flash_error !== ''): ?>
        <div class="flash flash-error" role="alert"><?= htmlspecialchars($flash_error, ENT_QUOTES, 'UTF-8') ?></div>
      <?php endif; ?>
      <?php if ($flash_success !== ''): ?>
        <div class="flash flash-success" role="status"><?= htmlspecialchars($flash_success, ENT_QUOTES, 'UTF-8') ?></div>
      <?php endif; ?>
      <?php if ($flash_info !== ''): ?>
        <div class="flash flash-info" role="status"><?= htmlspecialchars($flash_info, ENT_QUOTES, 'UTF-8') ?></div>
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

      <!-- Lifecycle Progress Indicator -->
      <div class="rd-card" style="margin-bottom: 2rem;">
        <div class="rd-lifecycle">
          <div class="rd-lifecycle-step <?= !empty($pending_reservations) ? 'active' : '' ?>">
            <svg class="icon" width="24" height="24" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/></svg>
            <div class="label">Reserved</div>
            <?php if (!empty($pending_reservations)): ?>
              <span class="rd-badge rd-b-blue"><?= count($pending_reservations) ?></span>
            <?php endif; ?>
          </div>
          <div class="rd-lifecycle-arrow">→</div>
          <div class="rd-lifecycle-step <?= !empty($approved_reservations) ? 'active' : '' ?>">
            <svg class="icon" width="24" height="24" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            <div class="label">Approved</div>
            <?php if (!empty($approved_reservations)): ?>
              <span class="rd-badge rd-b-green"><?= count($approved_reservations) ?></span>
            <?php endif; ?>
          </div>
          <div class="rd-lifecycle-arrow">→</div>
          <div class="rd-lifecycle-step <?= !empty($active_loans) ? 'active' : '' ?>">
            <svg class="icon" width="24" height="24" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"/></svg>
            <div class="label">Borrowed</div>
            <?php if (!empty($active_loans)): ?>
              <span class="rd-badge rd-b-orange"><?= count($active_loans) ?></span>
            <?php endif; ?>
          </div>
          <div class="rd-lifecycle-arrow">→</div>
          <div class="rd-lifecycle-step <?= !empty($loan_history) ? 'active' : '' ?>">
            <svg class="icon" width="24" height="24" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M3 10h10a5 5 0 015 5v2M3 10l6 6M3 10l6-6"/></svg>
            <div class="label">Returned</div>
            <?php if (!empty($loan_history)): ?>
              <span class="rd-badge"><?= count($loan_history) ?></span>
            <?php endif; ?>
          </div>
        </div>
      </div>

      <!-- Section 1: Active & Overdue Loans -->
      <div class="rd-section-title">
        <svg width="24" height="24" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253" /></svg>
        Currently Borrowed
        <?php if (!empty($active_loans)): ?>
          <span class="rd-badge rd-b-orange" style="margin-left:8px;"><?= count($active_loans) ?></span>
        <?php endif; ?>
      </div>
      <div class="rd-card" style="margin-bottom: 2rem;">
        <?php if (empty($active_loans)): ?>
          <div style="text-align:center; padding: 2rem 0; color: var(--rd-text-muted);">
            <div style="font-size: 3rem; margin-bottom: 1rem; opacity:0.5;">📚</div>
            <p>You have no books currently borrowed.</p>
            <a href="<?= BASE_URL ?>borrower/catalog.php" class="rd-btn rd-btn-primary" style="margin-top:1rem;display:inline-block;">Browse Catalog</a>
          </div>
        <?php else: ?>
          <div style="overflow-x:auto;">
            <table class="rd-table-glass">
              <thead>
                <tr>
                  <th>Book</th>
                  <th>Author</th>
                  <th>Checked Out</th>
                  <th>Due Date</th>
                  <th>Status</th>
                  <th>Days Left / Overdue</th>
                  <th>Action</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($active_loans as $loan): ?>
                  <?php
                    $is_overdue   = $loan['status'] === 'overdue' || (int)$loan['days_overdue'] > 0;
                    $days_overdue = (int)$loan['days_overdue'];
                    $due_ts       = strtotime($loan['due_date']);
                    $days_left    = (int)ceil(($due_ts - time()) / 86400);
                  ?>
                  <tr class="<?= $is_overdue ? 'rd-row-overdue' : '' ?>">
                    <td><strong><?= htmlspecialchars($loan['title'], ENT_QUOTES, 'UTF-8') ?></strong></td>
                    <td><?= htmlspecialchars($loan['author'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars(date('d M Y', strtotime($loan['checkout_date'])), ENT_QUOTES, 'UTF-8') ?></td>
                    <td><strong><?= htmlspecialchars(date('d M Y', strtotime($loan['due_date'])), ENT_QUOTES, 'UTF-8') ?></strong></td>
                    <td>
                      <?php if ($is_overdue): ?>
                        <span class="rd-badge rd-b-red">Overdue</span>
                      <?php else: ?>
                        <span class="rd-badge rd-b-blue">Borrowed</span>
                      <?php endif; ?>
                    </td>

                    <td>
                      <?php if ($is_overdue): ?>
                        <span style="color:#ef4444;font-weight:600;">
                          <?= $days_overdue ?> day<?= $days_overdue !== 1 ? 's' : '' ?> overdue
                        </span>
                      <?php else: ?>
                        <span style="color:#10b981;">
                          <?= max(0, $days_left) ?> day<?= $days_left !== 1 ? 's' : '' ?> left
                        </span>
                      <?php endif; ?>
                    </td>
                    <td>
                      <?php
                        $renew_count = 0;
                        if (array_key_exists('renew_count', $loan)) {
                            $renew_count = (int) $loan['renew_count'];
                        } elseif (array_key_exists('renewal_count', $loan)) {
                            $renew_count = (int) $loan['renewal_count'];
                        }

                        $max_renewals = defined('MAX_RENEWALS') ? (int) MAX_RENEWALS : 1;

                        $renew_eligible = $loan['status'] === 'active' && !$is_overdue
                            && strtotime($loan['due_date']) - time() <= 86400
                            && strtotime($loan['due_date']) > time()
                            && $renew_count < $max_renewals;
                      ?>
                      <?php if ($renew_eligible): ?>
                      <form method="POST" action="<?= htmlspecialchars(BASE_URL . 'borrower/renew.php', ENT_QUOTES, 'UTF-8') ?>" style="margin:0;">
                        <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                        <input type="hidden" name="loan_id" value="<?= (int) $loan['id'] ?>">
                        <input type="hidden" name="redirect_to" value="borrower/my_books.php">
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
          <p style="margin:1rem 0 0;font-size:0.85rem;color:var(--rd-text-muted);">
            To return a book, bring it to the library desk — a librarian will process the check-in.
          </p>
        <?php endif; ?>
      </div>

      <!-- Section 2: Approved Reservations (Ready for Pickup) — Redesigned -->
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
          Your reservation<?= count($approved_reservations) > 1 ? 's have' : ' has' ?> been approved.
          Visit the library to borrow <?= count($approved_reservations) > 1 ? 'these books' : 'this book' ?> before <strong>they expire</strong>.
        </p>
        <div class="rd-pickup-table-wrap">
          <table class="rd-pickup-table">
            <thead>
              <tr>
                <th>Book</th>
                <th>Approved On</th>
                <th>Pickup By</th>
                <th>Action</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($approved_reservations as $res): ?>
                <?php
                  $expires_ts     = strtotime($res['expires_at']);
                  $hrs_left       = (int)ceil(($expires_ts - time()) / 3600);
                  $days_left      = (int)ceil(($expires_ts - time()) / 86400);
                  $expires_soon   = $hrs_left <= 24 && $hrs_left > 0;
                  $expires_crit   = $hrs_left <= 6 && $hrs_left > 0;
                ?>
                <tr>
                  <td>
                    <span class="rd-pickup-book-title"><?= htmlspecialchars($res['title'], ENT_QUOTES, 'UTF-8') ?></span>
                    <span class="rd-pickup-book-author"><?= htmlspecialchars($res['author'] ?? '', ENT_QUOTES, 'UTF-8') ?></span>
                  </td>
                  <td class="rd-pickup-date"><?= $res['approved_at'] ? htmlspecialchars(date('d M Y', strtotime($res['approved_at'])), ENT_QUOTES, 'UTF-8') : '—' ?></td>
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
                  <td>
                    <form method="POST" action="<?= htmlspecialchars(BASE_URL . 'borrower/reserve.php', ENT_QUOTES, 'UTF-8') ?>" style="margin:0;">
                      <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                      <input type="hidden" name="action" value="cancel">
                      <input type="hidden" name="reservation_id" value="<?= (int)$res['id'] ?>">
                      <input type="hidden" name="redirect_to" value="borrower/my_books.php">
                      <button type="submit" class="rd-btn-danger-solid" onclick="return confirm('Cancel this approved reservation?')">Cancel</button>
                    </form>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
      <?php endif; ?>

      <!-- Section 3: Pending Reservations -->
      <div class="rd-section-title">
        <svg width="24" height="24" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
        Pending Reservations
        <?php if (!empty($pending_reservations)): ?>
          <span class="rd-badge rd-b-blue" style="margin-left:8px;"><?= count($pending_reservations) ?></span>
        <?php endif; ?>
      </div>
      <div class="rd-card" style="margin-bottom: 2rem;">
        <?php if (empty($pending_reservations)): ?>
          <div style="text-align:center; padding: 2rem 0; color: var(--rd-text-muted);">
            <div style="font-size: 3rem; margin-bottom: 1rem; opacity:0.5;">🔖</div>
            <p>No pending reservations.</p>
            <a href="<?= BASE_URL ?>borrower/catalog.php" class="rd-btn rd-btn-primary" style="margin-top:1rem;display:inline-block;">Browse Catalog</a>
          </div>
        <?php else: ?>
          <div style="overflow-x:auto;">
            <table class="rd-table-glass">
              <thead>
                <tr>
                  <th>Book</th>
                  <th>Author</th>
                  <th>Reserved On</th>
                  <th>Expires</th>
                  <th>Queue #</th>
                  <th>Status</th>
                  <th>Cancel</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($pending_reservations as $res): ?>
                  <tr>
                    <td><strong><?= htmlspecialchars($res['title'], ENT_QUOTES, 'UTF-8') ?></strong></td>
                    <td><?= htmlspecialchars($res['author'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars(date('d M Y', strtotime($res['reserved_at'])), ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars(date('d M Y', strtotime($res['expires_at'])), ENT_QUOTES, 'UTF-8') ?></td>
                    <td><span class="rd-badge rd-b-blue">#<?= (int)$res['queue_position'] ?></span></td>
                    <td><span class="rd-badge rd-b-orange">Awaiting Approval</span></td>
                    <td>
                      <form method="POST" action="<?= htmlspecialchars(BASE_URL . 'borrower/reserve.php', ENT_QUOTES, 'UTF-8') ?>" style="margin:0;">
                        <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                        <input type="hidden" name="action" value="cancel">
                        <input type="hidden" name="reservation_id" value="<?= (int)$res['id'] ?>">
                        <input type="hidden" name="redirect_to" value="borrower/my_books.php">
                        <button type="submit" class="rd-btn-action rd-btn-danger" onclick="return confirm('Cancel this reservation?')">Cancel</button>
                      </form>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </div>

      <!-- Section 4: Rejected Reservations -->
      <?php if (!empty($rejected_reservations)): ?>
      <div class="rd-section-title">
        <svg width="24" height="24" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 14l2-2m0 0l2-2m-2 2l-2-2m2 2l2 2m7-2a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
        Recently Rejected
        <span class="rd-badge rd-b-red" style="margin-left:8px;"><?= count($rejected_reservations) ?></span>
      </div>
      <div class="rd-card" style="margin-bottom: 2rem;">
        <div style="overflow-x:auto;">
          <table class="rd-table-glass">
            <thead>
              <tr>
                <th>Book</th>
                <th>Author</th>
                <th>Reserved On</th>
                <th>Rejected On</th>
                <th>Reason</th>
                <th>Action</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($rejected_reservations as $res): ?>
                <tr>
                  <td><strong><?= htmlspecialchars($res['title'], ENT_QUOTES, 'UTF-8') ?></strong></td>
                  <td><?= htmlspecialchars($res['author'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
                  <td><?= htmlspecialchars(date('d M Y', strtotime($res['reserved_at'])), ENT_QUOTES, 'UTF-8') ?></td>
                  <td><?= $res['rejected_at'] ? htmlspecialchars(date('d M Y', strtotime($res['rejected_at'])), ENT_QUOTES, 'UTF-8') : '—' ?></td>
                  <td>
                    <?php if (!empty($res['rejection_reason'])): ?>
                      <span title="<?= htmlspecialchars($res['rejection_reason'], ENT_QUOTES, 'UTF-8') ?>">
                        <?= htmlspecialchars(mb_strimwidth($res['rejection_reason'], 0, 60, '…'), ENT_QUOTES, 'UTF-8') ?>
                      </span>
                    <?php else: ?>
                      <span style="color:var(--rd-text-muted);">No reason given</span>
                    <?php endif; ?>
                  </td>
                  <td>
                    <a href="<?= htmlspecialchars(BASE_URL . 'borrower/catalog.php', ENT_QUOTES, 'UTF-8') ?>" class="rd-btn-action">Browse</a>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
      <?php endif; ?>

      <!-- Section 5: Loan History -->
      <div class="rd-section-title">
        <svg width="24" height="24" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z" /></svg>
        Loan History
        <span style="font-size:0.85rem; color:var(--rd-text-muted); margin-left:8px;">Last 30 returned books</span>
      </div>
      <div class="rd-card">
        <?php if (empty($loan_history)): ?>
          <div style="text-align:center; padding: 2rem 0; color: var(--rd-text-muted);">
            <div style="font-size: 3rem; margin-bottom: 1rem; opacity:0.5;">📖</div>
            <p>No returned books on record yet.</p>
          </div>
        <?php else: ?>
          <div style="overflow-x:auto;">
            <table class="rd-table-glass">
              <thead>
                <tr>
                  <th>Book</th>
                  <th>Author</th>
                  <th>Borrowed</th>
                  <th>Due</th>
                  <th>Returned</th>
                  <th>Fine</th>
                  <th>Fine Paid</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($loan_history as $h): ?>
                  <?php
                    $returned_late = strtotime($h['return_date']) > strtotime($h['due_date']);
                  ?>
                  <tr>
                    <td><strong><?= htmlspecialchars($h['title'], ENT_QUOTES, 'UTF-8') ?></strong></td>
                    <td><?= htmlspecialchars($h['author'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars(date('d M Y', strtotime($h['checkout_date'])), ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars(date('d M Y', strtotime($h['due_date'])), ENT_QUOTES, 'UTF-8') ?></td>
                    <td>
                      <?= htmlspecialchars(date('d M Y', strtotime($h['return_date'])), ENT_QUOTES, 'UTF-8') ?>
                      <?php if ($returned_late): ?>
                        <span class="rd-badge rd-b-orange" style="margin-left:4px;">Late</span>
                      <?php endif; ?>
                    </td>
                    <td>
                      <?php if ((float)$h['fine_amount'] > 0): ?>
                        <span style="color:#ef4444;font-weight:600;">
                          ₱<?= number_format((float)$h['fine_amount'], 2) ?>
                        </span>
                      <?php else: ?>
                        <span style="color:var(--rd-text-muted);">None</span>
                      <?php endif; ?>
                    </td>
                    <td>
                      <?php if ((float)$h['fine_amount'] > 0): ?>
                        <?php if ($h['fine_paid']): ?>
                          <span class="rd-badge rd-b-green">Paid</span>
                        <?php else: ?>
                          <span class="rd-badge rd-b-red">Unpaid</span>
                        <?php endif; ?>
                      <?php else: ?>
                        <span style="color:var(--rd-text-muted);">—</span>
                      <?php endif; ?>
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
    /* Additions for lifecycle component matching dashboard style */
    .rd-lifecycle {
      display: flex;
      align-items: center;
      justify-content: space-between;
      flex-wrap: wrap;
      gap: 1rem;
      padding: 1rem;
    }
    .rd-lifecycle-step {
      display: flex;
      flex-direction: column;
      align-items: center;
      gap: 0.5rem;
      padding: 1rem 1.5rem;
      border-radius: 12px;
      background: rgba(255,255,255,0.02);
      border: 1px solid rgba(255,255,255,0.05);
      min-width: 100px;
      opacity: 0.5;
      transition: all 0.2s;
    }
    .rd-lifecycle-step.active {
      opacity: 1;
      background: rgba(201, 168, 76, 0.05);
      border-color: rgba(201, 168, 76, 0.2);
    }
    .rd-lifecycle-step .icon { width: 24px; height: 24px; }
    .rd-lifecycle-step .label { font-size: 0.85rem; font-weight: 600; color: var(--rd-text-main); }
    .rd-lifecycle-arrow {
      color: var(--rd-text-muted);
      font-size: 1.5rem;
    }
    @media (max-width: 768px) {
      .rd-lifecycle { justify-content: center; }
      .rd-lifecycle-arrow { display: none; }
    }
  </style>

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

      if (closeBg) closeBg.addEventListener('click', closeModal);
      if (okBtn) okBtn.addEventListener('click', closeModal);
      document.addEventListener('keydown', function(event) {
        if (event.key === 'Escape') {
          closeModal();
        }
      });
    }());
  </script>
</body>
</html>
