<?php

/**
 * borrower/my_books.php — My Books: Full Borrowing Lifecycle (EPIC 1)
 *
 * Displays the complete lifecycle for the authenticated borrower:
 *   1. Active & Overdue Loans    — books currently borrowed + due dates
 *   2. Approved Reservations     — ready to be checked out
 *   3. Pending Reservations      — awaiting librarian decision
 *   4. Rejected / Cancelled Reservations — declined by librarian or cancelled by borrower (recent 10)
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
$pageTitle    = 'My Books | LibraLink';
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

      <div class="rd-books-tabs" role="tablist" aria-label="My books sections">
        <button type="button" class="rd-books-tab active" data-books-tab="borrowed" role="tab" aria-selected="true" aria-controls="books-panel-borrowed">
          <svg class="rd-books-tab-icon" width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.25"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"/></svg>
          Borrowed
          <span><?= count($active_loans) + count($approved_reservations) ?></span>
        </button>
        <button type="button" class="rd-books-tab" data-books-tab="pending" role="tab" aria-selected="false" aria-controls="books-panel-pending">
          <svg class="rd-books-tab-icon" width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.25"><path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
          Pending
          <span><?= count($pending_reservations) ?></span>
        </button>
        <button type="button" class="rd-books-tab" data-books-tab="rejected" role="tab" aria-selected="false" aria-controls="books-panel-rejected">
          <svg class="rd-books-tab-icon" width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.25"><path stroke-linecap="round" stroke-linejoin="round" d="M10 14l2-2m0 0l2-2m-2 2l-2-2m2 2l2 2m7-2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
          Rejected / Cancelled
          <span><?= count($rejected_reservations) ?></span>
        </button>
        <button type="button" class="rd-books-tab" data-books-tab="history" role="tab" aria-selected="false" aria-controls="books-panel-history">
          <svg class="rd-books-tab-icon" width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.25"><path stroke-linecap="round" stroke-linejoin="round" d="M3 10h10a5 5 0 015 5v2M3 10l6 6M3 10l6-6"/></svg>
          History
          <span><?= count($loan_history) ?></span>
        </button>
      </div>

      <div class="rd-books-filterbar" aria-label="Filter visible books section">
        <div class="rd-books-search">
          <svg width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.25"><path stroke-linecap="round" stroke-linejoin="round" d="m21 21-4.35-4.35m1.35-5.65a7 7 0 1 1-14 0 7 7 0 0 1 14 0z"/></svg>
          <input id="books-search" type="search" placeholder="Search the selected tab">
        </div>
        <div class="rd-books-controls">
          <label class="sr-only" for="books-sort">Sort selected tab</label>
          <select id="books-sort" class="rd-books-select" aria-label="Sort selected tab"></select>
          <label class="sr-only" for="books-page-size">Rows per page</label>
          <select id="books-page-size" class="rd-books-select" aria-label="Rows per page">
            <option value="5">5 rows</option>
            <option value="10" selected>10 rows</option>
            <option value="25">25 rows</option>
          </select>
        </div>
      </div>
      <div class="rd-books-filterchips" id="books-filterchips" aria-label="Quick filters"></div>

      <section id="books-panel-borrowed" class="rd-books-panel active" data-books-panel="borrowed" role="tabpanel">
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
                    $renew_eligible = !empty($loan['renewal_eligible']);
                  ?>
                  <tr class="<?= $is_overdue ? 'rd-row-overdue' : '' ?>"
                      data-book-row
                      data-book-status="<?= $is_overdue ? 'borrowed overdue' : 'borrowed' ?><?= $renew_eligible ? ' renewable' : '' ?>"
                      data-sort-title="<?= htmlspecialchars($loan['title'], ENT_QUOTES, 'UTF-8') ?>"
                      data-sort-author="<?= htmlspecialchars($loan['author'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                      data-sort-due="<?= htmlspecialchars($loan['due_date'], ENT_QUOTES, 'UTF-8') ?>"
                      data-sort-status="<?= $is_overdue ? 'overdue' : 'borrowed' ?>">
                    <td>
                      <div style="display: flex; gap: 12px; align-items: flex-start;">
                        <img src="<?= htmlspecialchars(BASE_URL . 'public/book-cover.php?book_id=' . (int)$loan['book_id'], ENT_QUOTES, 'UTF-8') ?>"
                             alt="Cover"
                             onerror="this.onerror=null;this.src='<?= htmlspecialchars(BASE_URL . 'assets/images/placeholder-book.png', ENT_QUOTES, 'UTF-8') ?>';"
                             style="width: 40px; height: 60px; object-fit: cover; border-radius: 4px; flex-shrink: 0; border: 1px solid var(--rd-border, #e2e8f0);">
                        <div>
                          <strong><?= htmlspecialchars($loan['title'], ENT_QUOTES, 'UTF-8') ?></strong>
                        </div>
                      </div>
                    </td>
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
                <tr data-book-row
                    data-book-status="ready pickup"
                    data-sort-title="<?= htmlspecialchars($res['title'], ENT_QUOTES, 'UTF-8') ?>"
                    data-sort-author="<?= htmlspecialchars($res['author'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                    data-sort-due="<?= htmlspecialchars($res['expires_at'], ENT_QUOTES, 'UTF-8') ?>"
                    data-sort-status="ready pickup">
                  <td>
                    <div class="rd-book-cell">
                      <img src="<?= htmlspecialchars(BASE_URL . 'public/book-cover.php?book_id=' . (int)$res['book_id'], ENT_QUOTES, 'UTF-8') ?>"
                           alt="Cover"
                           onerror="this.onerror=null;this.src='<?= htmlspecialchars(BASE_URL . 'assets/images/placeholder-book.png', ENT_QUOTES, 'UTF-8') ?>';"
                           class="rd-book-thumb">
                      <div>
                        <span class="rd-pickup-book-title"><?= htmlspecialchars($res['title'], ENT_QUOTES, 'UTF-8') ?></span>
                        <span class="rd-pickup-book-author"><?= htmlspecialchars($res['author'] ?? '', ENT_QUOTES, 'UTF-8') ?></span>
                      </div>
                    </div>
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
      <p class="rd-filter-empty" data-filter-empty hidden>No matching records in this tab.</p>
      <div class="rd-books-pagination" data-pagination-for="borrowed" hidden></div>
      </section>

      <section id="books-panel-pending" class="rd-books-panel" data-books-panel="pending" role="tabpanel" hidden>
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
                  <?php
                    $pending_expires_ts = strtotime((string) $res['expires_at']);
                    $pending_hours_left = $pending_expires_ts === false ? 999 : (int) ceil(($pending_expires_ts - time()) / 3600);
                    $pending_expires_soon = $pending_hours_left <= 72 && $pending_hours_left > 0;
                  ?>
                  <tr data-book-row
                      data-book-status="pending awaiting approval<?= $pending_expires_soon ? ' expiring soon' : '' ?>"
                      data-sort-title="<?= htmlspecialchars($res['title'], ENT_QUOTES, 'UTF-8') ?>"
                      data-sort-author="<?= htmlspecialchars($res['author'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                      data-sort-reserved="<?= htmlspecialchars($res['reserved_at'], ENT_QUOTES, 'UTF-8') ?>"
                      data-sort-expires="<?= htmlspecialchars($res['expires_at'], ENT_QUOTES, 'UTF-8') ?>"
                      data-sort-queue="<?= (int)$res['queue_position'] ?>">
                    <td>
                      <div style="display: flex; gap: 12px; align-items: flex-start;">
                        <img src="<?= htmlspecialchars(BASE_URL . 'public/book-cover.php?book_id=' . (int)$res['book_id'], ENT_QUOTES, 'UTF-8') ?>"
                             alt="Cover"
                             onerror="this.onerror=null;this.src='<?= htmlspecialchars(BASE_URL . 'assets/images/placeholder-book.png', ENT_QUOTES, 'UTF-8') ?>';"
                             style="width: 40px; height: 60px; object-fit: cover; border-radius: 4px; flex-shrink: 0; border: 1px solid var(--rd-border, #e2e8f0);">
                        <div>
                          <strong><?= htmlspecialchars($res['title'], ENT_QUOTES, 'UTF-8') ?></strong>
                        </div>
                      </div>
                    </td>
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
      <p class="rd-filter-empty" data-filter-empty hidden>No matching pending reservations.</p>
      <div class="rd-books-pagination" data-pagination-for="pending" hidden></div>
      </section>

      <section id="books-panel-rejected" class="rd-books-panel" data-books-panel="rejected" role="tabpanel" hidden>
      <!-- Section 4: Rejected / Cancelled Reservations -->
      <div class="rd-section-title">
        <svg width="24" height="24" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 14l2-2m0 0l2-2m-2 2l-2-2m2 2l2 2m7-2a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
        Recently Rejected / Cancelled
        <?php if (!empty($rejected_reservations)): ?>
          <span class="rd-badge rd-b-red" style="margin-left:8px;"><?= count($rejected_reservations) ?></span>
        <?php endif; ?>
      </div>
      <div class="rd-card" style="margin-bottom: 2rem;">
        <?php if (empty($rejected_reservations)): ?>
          <div style="text-align:center; padding: 2rem 0; color: var(--rd-text-muted);">
            <p>No rejected or cancelled reservations.</p>
          </div>
        <?php else: ?>
        <div style="overflow-x:auto;">
          <table class="rd-table-glass">
            <thead>
              <tr>
                <th>Book</th>
                <th>Author</th>
                <th>Reserved On</th>
                <th>Ended On</th>
                <th>Outcome</th>
                <th>Reason</th>
                <th>Action</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($rejected_reservations as $res): ?>
                <?php
                  $reservation_status = (string) ($res['status'] ?? 'rejected');
                  $is_cancelled = $reservation_status === 'cancelled';
                  $ended_at = $is_cancelled
                    ? ($res['updated_at'] ?? $res['reserved_at'])
                    : ($res['rejected_at'] ?? $res['updated_at'] ?? $res['reserved_at']);
                ?>
                <tr data-book-row
                    data-book-status="<?= $is_cancelled ? 'cancelled canceled by user' : 'rejected denied not approved librarian' ?>"
                    data-sort-title="<?= htmlspecialchars($res['title'], ENT_QUOTES, 'UTF-8') ?>"
                    data-sort-author="<?= htmlspecialchars($res['author'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                    data-sort-reserved="<?= htmlspecialchars($res['reserved_at'], ENT_QUOTES, 'UTF-8') ?>"
                    data-sort-ended="<?= htmlspecialchars((string) $ended_at, ENT_QUOTES, 'UTF-8') ?>"
                    data-sort-outcome="<?= $is_cancelled ? 'cancelled' : 'rejected' ?>">
                  <td>
                    <div class="rd-book-cell">
                      <img src="<?= htmlspecialchars(BASE_URL . 'public/book-cover.php?book_id=' . (int)$res['book_id'], ENT_QUOTES, 'UTF-8') ?>"
                           alt="Cover"
                           onerror="this.onerror=null;this.src='<?= htmlspecialchars(BASE_URL . 'assets/images/placeholder-book.png', ENT_QUOTES, 'UTF-8') ?>';"
                           class="rd-book-thumb">
                      <strong><?= htmlspecialchars($res['title'], ENT_QUOTES, 'UTF-8') ?></strong>
                    </div>
                  </td>
                  <td><?= htmlspecialchars($res['author'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
                  <td><?= htmlspecialchars(date('d M Y', strtotime($res['reserved_at'])), ENT_QUOTES, 'UTF-8') ?></td>
                  <td><?= $ended_at ? htmlspecialchars(date('d M Y', strtotime((string) $ended_at)), ENT_QUOTES, 'UTF-8') : '—' ?></td>
                  <td>
                    <?php if ($is_cancelled): ?>
                      <span class="rd-badge rd-b-orange">Cancelled by you</span>
                    <?php else: ?>
                      <span class="rd-badge rd-b-red">Rejected by librarian</span>
                    <?php endif; ?>
                  </td>
                  <td>
                    <?php if ($is_cancelled): ?>
                      <span style="color:var(--rd-text-muted);">Cancelled from reservation</span>
                    <?php elseif (!empty($res['rejection_reason'])): ?>
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
        <?php endif; ?>
      </div>
      <p class="rd-filter-empty" data-filter-empty hidden>No matching rejected or cancelled reservations.</p>
      <div class="rd-books-pagination" data-pagination-for="rejected" hidden></div>
      </section>

      <section id="books-panel-history" class="rd-books-panel" data-books-panel="history" role="tabpanel" hidden>
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
            <table class="rd-table-glass" id="loan-history-table">
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
                  <tr data-book-row
                      data-book-status="<?= $returned_late ? 'late ' : '' ?><?= (float)$h['fine_amount'] > 0 ? ($h['fine_paid'] ? 'fine-paid' : 'fine-unpaid') : 'fine-none' ?>"
                      data-sort-title="<?= htmlspecialchars($h['title'], ENT_QUOTES, 'UTF-8') ?>"
                      data-sort-author="<?= htmlspecialchars($h['author'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                      data-sort-borrowed="<?= htmlspecialchars($h['checkout_date'], ENT_QUOTES, 'UTF-8') ?>"
                      data-sort-returned="<?= htmlspecialchars($h['return_date'], ENT_QUOTES, 'UTF-8') ?>"
                      data-sort-fine="<?= htmlspecialchars((string) (float)$h['fine_amount'], ENT_QUOTES, 'UTF-8') ?>">
                    <td>
                      <div class="rd-book-cell">
                        <img src="<?= htmlspecialchars(BASE_URL . 'public/book-cover.php?book_id=' . (int)$h['book_id'], ENT_QUOTES, 'UTF-8') ?>"
                             alt="Cover"
                             onerror="this.onerror=null;this.src='<?= htmlspecialchars(BASE_URL . 'assets/images/placeholder-book.png', ENT_QUOTES, 'UTF-8') ?>';"
                             class="rd-book-thumb">
                        <strong><?= htmlspecialchars($h['title'], ENT_QUOTES, 'UTF-8') ?></strong>
                      </div>
                    </td>
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
      <p class="rd-filter-empty" data-filter-empty hidden>No matching history records.</p>
      <div class="rd-books-pagination" data-pagination-for="history" hidden></div>
      </section>

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
    .rd-books-tabs {
      display: flex;
      flex-wrap: wrap;
      gap: 0.75rem;
      margin: 0 0 1.25rem;
      padding: 0.5rem;
      border: 1px solid rgba(201, 168, 76, 0.18);
      border-radius: 12px;
      background: rgba(255, 255, 255, 0.45);
    }
    .rd-books-tab {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      gap: 0.55rem;
      min-height: 42px;
      padding: 0.75rem 1.15rem;
      border: 1px solid rgba(201, 168, 76, 0.14);
      border-radius: 8px;
      background: rgba(76, 70, 62, 0.1);
      color: #8f8374;
      font: inherit;
      font-weight: 800;
      cursor: pointer;
      transition: all 0.2s ease;
    }
    .rd-books-tab-icon {
      width: 20px;
      height: 20px;
      flex: 0 0 auto;
    }
    .rd-books-tab:hover,
    .rd-books-tab.active {
      border-color: rgba(201, 168, 76, 0.5);
      background: var(--rd-surface);
      color: var(--rd-primary);
      box-shadow: 0 10px 24px rgba(44, 40, 33, 0.18);
      transform: translateY(-1px);
    }
    .rd-books-tab span {
      min-width: 28px;
      padding: 0.18rem 0.5rem;
      border-radius: 999px;
      background: rgba(201, 168, 76, 0.24);
      color: var(--rd-primary);
      font-size: 0.8rem;
      line-height: 1.4;
    }
    .rd-books-tab.active span,
    .rd-books-tab:hover span {
      background: rgba(201, 168, 76, 0.28);
      color: #f1d46e;
    }
    .rd-books-filterbar {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 0.75rem;
      margin: 0 0 1.25rem;
      flex-wrap: wrap;
    }
    .rd-books-search {
      display: flex;
      align-items: center;
      gap: 0.55rem;
      width: min(520px, 100%);
      min-height: 44px;
      padding: 0 0.8rem;
      border: 1px solid rgba(201, 168, 76, 0.24);
      border-radius: 10px;
      background: rgba(42, 39, 35, 0.08);
      color: #8f8374;
    }
    .rd-books-search:focus-within {
      border-color: rgba(201, 168, 76, 0.56);
      box-shadow: 0 0 0 3px rgba(201, 168, 76, 0.12);
    }
    .rd-books-search input {
      width: 100%;
      min-width: 180px;
      border: 0;
      outline: 0;
      background: transparent;
      color: #4c463e;
      font: inherit;
      font-weight: 600;
    }
    .rd-books-search input::placeholder {
      color: #9a8f80;
    }
    .rd-book-cell {
      display: flex;
      gap: 12px;
      align-items: flex-start;
    }
    .rd-book-thumb {
      width: 40px;
      height: 60px;
      object-fit: cover;
      border-radius: 4px;
      flex-shrink: 0;
      border: 1px solid var(--rd-border, #e2e8f0);
      background: rgba(255,255,255,0.35);
    }
    .rd-books-controls {
      display: flex;
      align-items: center;
      gap: 0.65rem;
      flex-wrap: wrap;
    }
    .rd-books-select {
      min-height: 44px;
      padding: 0 2.25rem 0 0.85rem;
      border: 1px solid rgba(201, 168, 76, 0.24);
      border-radius: 10px;
      background:
        linear-gradient(45deg, transparent 50%, #9a8f80 50%) right 1rem center / 6px 6px no-repeat,
        linear-gradient(135deg, #9a8f80 50%, transparent 50%) right 0.72rem center / 6px 6px no-repeat,
        rgba(42, 39, 35, 0.08);
      color: #4c463e;
      font: inherit;
      font-weight: 700;
      appearance: none;
      cursor: pointer;
    }
    .rd-books-select:focus {
      outline: 0;
      border-color: rgba(201, 168, 76, 0.56);
      box-shadow: 0 0 0 3px rgba(201, 168, 76, 0.12);
    }
    .rd-books-filterchips {
      display: flex;
      flex-wrap: wrap;
      gap: 0.55rem;
      margin: -0.45rem 0 1.25rem;
    }
    .rd-filter-chip {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      min-height: 34px;
      padding: 0.45rem 0.8rem;
      border: 1px solid rgba(201, 168, 76, 0.18);
      border-radius: 999px;
      background: rgba(76, 70, 62, 0.08);
      color: #7f7466;
      font: inherit;
      font-size: 0.86rem;
      font-weight: 800;
      cursor: pointer;
      transition: all 0.18s ease;
    }
    .rd-filter-chip:hover,
    .rd-filter-chip.active {
      border-color: rgba(201, 168, 76, 0.5);
      background: rgba(201, 168, 76, 0.18);
      color: var(--rd-primary);
    }
    .rd-books-panel[hidden] {
      display: none;
    }
    .rd-filter-empty {
      margin: 1rem 0 0;
      color: var(--rd-text-muted);
      font-weight: 700;
      text-align: center;
    }
    .rd-books-pagination {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 0.75rem;
      margin: 1rem 0 0;
      flex-wrap: wrap;
      color: var(--rd-text-muted);
      font-size: 0.9rem;
      font-weight: 700;
    }
    .rd-pagination-summary {
      white-space: nowrap;
    }
    .rd-pagination-center {
      display: flex;
      align-items: center;
      gap: 0.35rem;
    }
    .rd-pagination-pages {
      display: flex;
      align-items: center;
      gap: 0.35rem;
      flex-wrap: wrap;
    }
    .rd-page-btn {
      min-width: 36px;
      min-height: 34px;
      padding: 0.35rem 0.65rem;
      border: 1px solid rgba(201, 168, 76, 0.2);
      border-radius: 8px;
      background: rgba(76, 70, 62, 0.08);
      color: #6f6559;
      font: inherit;
      font-weight: 800;
      cursor: pointer;
      transition: all 0.15s ease;
    }
    .rd-page-btn:hover:not(:disabled),
    .rd-page-btn.active {
      border-color: rgba(201, 168, 76, 0.52);
      background: var(--rd-primary);
      color: #2c281f;
    }
    .rd-page-btn:disabled {
      opacity: 0.4;
      cursor: not-allowed;
      background: rgba(76, 70, 62, 0.06);
      color: #8f8374;
    }
    .rd-page-dots {
      color: var(--rd-text-muted);
      font-weight: 700;
      padding: 0 0.2rem;
    }
    .rd-pagination-rows-wrap {
      display: flex;
      align-items: center;
      gap: 0.5rem;
      white-space: nowrap;
    }
    .rd-pagination-rows-label {
      font-size: 0.85rem;
      color: var(--rd-text-muted);
      font-weight: 700;
    }
    .rd-pagination-rows-select {
      min-height: 34px;
      padding: 0 2rem 0 0.65rem;
      font-size: 0.85rem;
      width: auto;
      background-size: 5px 5px;
      background-position: right 0.7rem center, right 0.5rem center;
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
    .rd-lifecycle-step .label { font-size: 0.85rem; font-weight: 600; color: var(--rd-text); }
    .rd-lifecycle-arrow {
      color: var(--rd-text-muted);
      font-size: 1.5rem;
    }
    @media (max-width: 768px) {
      .rd-lifecycle { justify-content: center; }
      .rd-lifecycle-arrow { display: none; }
      .rd-books-tabs { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); }
      .rd-books-tab { width: 100%; }
      .rd-books-filterbar,
      .rd-books-controls,
      .rd-books-select {
        width: 100%;
      }
      .rd-books-pagination {
        flex-direction: column;
        align-items: center;
        gap: 0.6rem;
      }
      .rd-pagination-center {
        width: 100%;
        justify-content: center;
      }
      .rd-pagination-rows-wrap {
        width: 100%;
        justify-content: center;
      }
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

    (function() {
      var tabs = Array.prototype.slice.call(document.querySelectorAll('[data-books-tab]'));
      var panels = Array.prototype.slice.call(document.querySelectorAll('[data-books-panel]'));
      var search = document.getElementById('books-search');
      var sortSelect = document.getElementById('books-sort');
      var pageSizeSelect = document.getElementById('books-page-size');
      var chipsWrap = document.getElementById('books-filterchips');
      if (!tabs.length || !panels.length) return;

      var state = {
        tab: 'borrowed',
        filter: 'all',
        page: 1,
        pageSize: pageSizeSelect ? parseInt(pageSizeSelect.value, 10) || 10 : 10,
        sort: ''
      };

      var configs = {
        borrowed: {
          search: 'Search borrowed books',
          filters: [
            ['all', 'All'],
            ['borrowed', 'Borrowed'],
            ['overdue', 'Overdue'],
            ['renewable', 'Renewable'],
            ['ready', 'Ready pickup']
          ],
          sorts: [
            ['due-asc', 'Due date'],
            ['title-asc', 'Title A-Z'],
            ['author-asc', 'Author A-Z'],
            ['status-asc', 'Status']
          ],
          sorters: {
            'due-asc': { field: 'due', type: 'date', dir: 1 },
            'title-asc': { field: 'title', type: 'text', dir: 1 },
            'author-asc': { field: 'author', type: 'text', dir: 1 },
            'status-asc': { field: 'status', type: 'text', dir: 1 }
          }
        },
        pending: {
          search: 'Search pending reservations',
          filters: [
            ['all', 'All'],
            ['awaiting approval', 'Awaiting approval'],
            ['expiring soon', 'Expiring soon']
          ],
          sorts: [
            ['reserved-desc', 'Reserved newest'],
            ['reserved-asc', 'Reserved oldest'],
            ['expires-asc', 'Expires soonest'],
            ['queue-asc', 'Queue #']
          ],
          sorters: {
            'reserved-desc': { field: 'reserved', type: 'date', dir: -1 },
            'reserved-asc': { field: 'reserved', type: 'date', dir: 1 },
            'expires-asc': { field: 'expires', type: 'date', dir: 1 },
            'queue-asc': { field: 'queue', type: 'number', dir: 1 }
          }
        },
        rejected: {
          search: 'Search rejected or cancelled reservations',
          filters: [
            ['all', 'All'],
            ['rejected', 'Rejected by librarian'],
            ['cancelled', 'Cancelled by you']
          ],
          sorts: [
            ['ended-desc', 'Ended newest'],
            ['reserved-desc', 'Reserved newest'],
            ['outcome-asc', 'Outcome'],
            ['title-asc', 'Title A-Z']
          ],
          sorters: {
            'ended-desc': { field: 'ended', type: 'date', dir: -1 },
            'reserved-desc': { field: 'reserved', type: 'date', dir: -1 },
            'outcome-asc': { field: 'outcome', type: 'text', dir: 1 },
            'title-asc': { field: 'title', type: 'text', dir: 1 }
          }
        },
        history: {
          search: 'Search loan history',
          filters: [
            ['all', 'All'],
            ['late', 'Returned late'],
            ['fine-unpaid', 'Fine unpaid'],
            ['fine-paid', 'Fine paid']
          ],
          sorts: [
            ['returned-desc', 'Returned newest'],
            ['borrowed-desc', 'Borrowed newest'],
            ['fine-desc', 'Fine highest'],
            ['title-asc', 'Title A-Z']
          ],
          sorters: {
            'returned-desc': { field: 'returned', type: 'date', dir: -1 },
            'borrowed-desc': { field: 'borrowed', type: 'date', dir: -1 },
            'fine-desc': { field: 'fine', type: 'money', dir: -1 },
            'title-asc': { field: 'title', type: 'text', dir: 1 }
          }
        }
      };

      function getActivePanel() {
        return panels.filter(function(panel) {
          return !panel.hidden;
        })[0] || panels[0];
      }

      function activeConfig() {
        return configs[state.tab] || configs.borrowed;
      }

      function cellValue(row, col) {
        var cell = row.children[col];
        return cell ? cell.textContent.trim() : '';
      }

      function sortValue(row, sorter) {
        if (sorter.field) {
          return row.getAttribute('data-sort-' + sorter.field) || '';
        }
        return cellValue(row, sorter.col);
      }

      function parseDateValue(value) {
        if (!value || value === '—') return 0;
        var parsed = Date.parse(value);
        return Number.isNaN(parsed) ? 0 : parsed;
      }

      function parseNumberValue(value) {
        var clean = String(value || '').replace(/[^0-9.-]/g, '');
        var parsed = parseFloat(clean);
        return Number.isNaN(parsed) ? 0 : parsed;
      }

      function sortRows(rows) {
        var config = activeConfig();
        var sorter = config.sorters[state.sort];
        if (!sorter) return rows;

        return rows.slice().sort(function(a, b) {
          var av = sortValue(a, sorter);
          var bv = sortValue(b, sorter);

          if (sorter.type === 'date') {
            av = parseDateValue(av);
            bv = parseDateValue(bv);
          } else if (sorter.type === 'number' || sorter.type === 'money') {
            av = parseNumberValue(av);
            bv = parseNumberValue(bv);
          } else {
            av = av.toLowerCase();
            bv = bv.toLowerCase();
          }

          if (av < bv) return -1 * sorter.dir;
          if (av > bv) return 1 * sorter.dir;
          return (parseInt(a.dataset.originalIndex || '0', 10) - parseInt(b.dataset.originalIndex || '0', 10));
        });
      }

      function renderChips() {
        if (!chipsWrap) return;
        var config = activeConfig();
        chipsWrap.innerHTML = '';

        config.filters.forEach(function(item) {
          var button = document.createElement('button');
          button.type = 'button';
          button.className = 'rd-filter-chip' + (state.filter === item[0] ? ' active' : '');
          button.setAttribute('data-filter-value', item[0]);
          button.textContent = item[1];
          button.addEventListener('click', function() {
            state.filter = item[0];
            state.page = 1;
            renderChips();
            applyBooksFilter();
          });
          chipsWrap.appendChild(button);
        });
      }

      function renderSort() {
        if (!sortSelect) return;
        var config = activeConfig();
        sortSelect.innerHTML = '';

        config.sorts.forEach(function(item) {
          var option = document.createElement('option');
          option.value = item[0];
          option.textContent = 'Sort: ' + item[1];
          sortSelect.appendChild(option);
        });

        state.sort = config.sorts[0] ? config.sorts[0][0] : '';
        sortSelect.value = state.sort;
      }

      function renderPagination(panel, visibleRows, totalRows) {
        var pagination = panel.querySelector('[data-pagination-for]');
        if (!pagination) return;

        if (totalRows <= state.pageSize) {
          pagination.hidden = true;
          pagination.innerHTML = '';
          return;
        }

        var totalPages = Math.max(1, Math.ceil(totalRows / state.pageSize));
        state.page = Math.min(Math.max(1, state.page), totalPages);

        var start = totalRows === 0 ? 0 : ((state.page - 1) * state.pageSize) + 1;
        var end = Math.min(totalRows, state.page * state.pageSize);
        pagination.hidden = false;
        pagination.innerHTML = '';

        var summary = document.createElement('span');
        summary.className = 'rd-pagination-summary';
        summary.textContent = 'Showing ' + start + '\u2013' + end + ' of ' + totalRows + ' records';
        pagination.appendChild(summary);

        var center = document.createElement('div');
        center.className = 'rd-pagination-center';

        var pages = document.createElement('div');
        pages.className = 'rd-pagination-pages';

        function addButton(label, page, disabled, active) {
          var button = document.createElement('button');
          button.type = 'button';
          button.className = 'rd-page-btn' + (active ? ' active' : '');
          button.textContent = label;
          button.disabled = disabled;
          button.addEventListener('click', function() {
            state.page = page;
            applyBooksFilter();
          });
          pages.appendChild(button);
        }

        addButton('Previous', state.page - 1, state.page === 1, false);
        for (var i = 1; i <= totalPages; i += 1) {
          if (i === 1 || i === totalPages || Math.abs(i - state.page) <= 1) {
            addButton(String(i), i, false, i === state.page);
          } else if (i === state.page - 2 || i === state.page + 2) {
            var dots = document.createElement('span');
            dots.className = 'rd-page-dots';
            dots.textContent = '\u2026';
            pages.appendChild(dots);
          }
        }
        addButton('Next', state.page + 1, state.page === totalPages, false);

        center.appendChild(pages);

        var rowsPerPageWrap = document.createElement('div');
        rowsPerPageWrap.className = 'rd-pagination-rows-wrap';
        var rowsLabel = document.createElement('label');
        rowsLabel.textContent = 'Rows per page:';
        rowsLabel.className = 'rd-pagination-rows-label';
        var rowsSelect = document.createElement('select');
        rowsSelect.className = 'rd-books-select rd-pagination-rows-select';
        rowsSelect.setAttribute('aria-label', 'Rows per page');
        [5, 10, 25].forEach(function(val) {
          var opt = document.createElement('option');
          opt.value = String(val);
          opt.textContent = val;
          if (state.pageSize === val) opt.selected = true;
          rowsSelect.appendChild(opt);
        });
        rowsSelect.addEventListener('change', function() {
          state.pageSize = parseInt(rowsSelect.value, 10) || 10;
          state.page = 1;
          if (pageSizeSelect) pageSizeSelect.value = rowsSelect.value;
          applyBooksFilter();
        });
        rowsPerPageWrap.appendChild(rowsLabel);
        rowsPerPageWrap.appendChild(rowsSelect);
        pagination.appendChild(center);
        pagination.appendChild(rowsPerPageWrap);

        if (pageSizeSelect) pageSizeSelect.value = String(state.pageSize);
      }

      function applyBooksFilter() {
        var panel = getActivePanel();
        var query = search ? search.value.trim().toLowerCase() : '';
        var rows = Array.prototype.slice.call(panel.querySelectorAll('[data-book-row]'));
        var empty = panel.querySelector('[data-filter-empty]');
        var hasActiveFilter = query !== '';
        var configFilter = state.filter;
        var matchedRows;

        rows.forEach(function(row, index) {
          if (!row.dataset.originalIndex) {
            row.dataset.originalIndex = String(index);
          }
        });

        matchedRows = rows.filter(function(row) {
          var matchesText = query === '' || row.textContent.toLowerCase().indexOf(query) !== -1;
          var statusText = (row.getAttribute('data-book-status') || '').toLowerCase();
          var matchesFilter = configFilter === 'all' || statusText.indexOf(configFilter) !== -1;
          return matchesText && matchesFilter;
        });

        matchedRows = sortRows(matchedRows);
        matchedRows.forEach(function(row) {
          if (row.parentNode) {
            row.parentNode.appendChild(row);
          }
        });

        var totalMatched = matchedRows.length;
        var startIndex = (state.page - 1) * state.pageSize;
        var endIndex = startIndex + state.pageSize;

        if (startIndex >= totalMatched && totalMatched > 0) {
          state.page = Math.ceil(totalMatched / state.pageSize);
          startIndex = (state.page - 1) * state.pageSize;
          endIndex = startIndex + state.pageSize;
        }

        rows.forEach(function(row) {
          row.hidden = true;
        });

        matchedRows.forEach(function(row, index) {
          row.hidden = index < startIndex || index >= endIndex;
        });

        if (empty) {
          empty.hidden = (!hasActiveFilter && state.filter === 'all') || totalMatched > 0 || rows.length === 0;
        }

        renderPagination(panel, matchedRows.slice(startIndex, endIndex), totalMatched);
      }

      function activateTab(name) {
        state.tab = name;
        state.filter = 'all';
        state.page = 1;
        tabs.forEach(function(tab) {
          var isActive = tab.getAttribute('data-books-tab') === name;
          tab.classList.toggle('active', isActive);
          tab.setAttribute('aria-selected', isActive ? 'true' : 'false');
        });

        panels.forEach(function(panel) {
          var isActive = panel.getAttribute('data-books-panel') === name;
          panel.classList.toggle('active', isActive);
          panel.hidden = !isActive;
        });

        if (search) {
          search.value = '';
          search.placeholder = activeConfig().search;
        }
        renderSort();
        renderChips();
        applyBooksFilter();
      }

      tabs.forEach(function(tab) {
        tab.addEventListener('click', function() {
          activateTab(tab.getAttribute('data-books-tab'));
        });
      });

      if (search) {
        search.addEventListener('input', function() {
          state.page = 1;
          applyBooksFilter();
        });
      }
      if (sortSelect) {
        sortSelect.addEventListener('change', function() {
          state.sort = sortSelect.value;
          state.page = 1;
          applyBooksFilter();
        });
      }
      if (pageSizeSelect) {
        pageSizeSelect.addEventListener('change', function() {
          state.pageSize = parseInt(pageSizeSelect.value, 10) || 10;
          state.page = 1;
          applyBooksFilter();
        });
      }
      activateTab('borrowed');
    }());
  </script>
</body>
</html>
