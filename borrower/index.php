<?php

/**
 * borrower/index.php — Borrower Dashboard (US5, FR-016–FR-019)
 *
 * Redesigned: Action-centered layout (GPT feedback fix)
 *   TOP    — Your Status: Overdue → Due Soon → Active Loans (each with Renew/Details)
 *   MIDDLE — Your Reservations (queue position, Cancel/View)
 *   BOTTOM — Borrowing History (collapsed accordion)
 *
 * Protected: Borrower role only (FR-029).
 */

$allowed_roles = ['borrower'];
require_once __DIR__ . '/../includes/auth_guard.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/circulation.php';

$pdo     = get_db();
$user_id = (int) $_SESSION['user_id'];

$flash_error   = $_SESSION['flash_error']   ?? '';
$flash_success = $_SESSION['flash_success'] ?? '';
unset($_SESSION['flash_error'], $_SESSION['flash_success']);

// Active loans (overdue + active), ordered: overdue first, then by due date ASC
$active_stmt = $pdo->prepare(
  "SELECT c.id, c.checkout_date, c.due_date, c.status, b.title
       FROM Circulation c
       JOIN Books       b ON c.book_id = b.id
      WHERE c.user_id = ? AND c.status IN ('active', 'overdue')
      ORDER BY
        CASE c.status WHEN 'overdue' THEN 0 ELSE 1 END ASC,
        c.due_date ASC"
);
$active_stmt->execute([$user_id]);
$active_loans = $active_stmt->fetchAll();

// Separate into overdue / due-soon (≤3 days) / other active
$today         = new DateTimeImmutable('today');
$overdue_loans = [];
$due_soon      = [];
$other_active  = [];

foreach ($active_loans as $loan) {
  if ($loan['status'] === 'overdue') {
    $overdue_loans[] = $loan;
  } else {
    $due_date = new DateTimeImmutable($loan['due_date']);
    $diff     = (int) $today->diff($due_date)->days;
    if ($due_date >= $today && $diff <= 3) {
      $due_soon[] = $loan;
    } else {
      $other_active[] = $loan;
    }
  }
}

// Loan history (returned, last 20)
$history_stmt = $pdo->prepare(
  "SELECT c.return_date, c.fine_amount, c.fine_paid, b.title
       FROM Circulation c
       JOIN Books       b ON c.book_id = b.id
      WHERE c.user_id = ? AND c.status = 'returned'
      ORDER BY c.return_date DESC
      LIMIT 20"
);
$history_stmt->execute([$user_id]);
$loan_history = $history_stmt->fetchAll();

// Expire stale reservations before display
expire_stale_reservations($pdo);

// Pending reservations with queue position
$res_stmt = $pdo->prepare(
  "SELECT r.id, r.reserved_at, r.expires_at, r.book_id, b.title
   FROM Reservations r
   JOIN Books b ON r.book_id = b.id
   WHERE r.user_id = ? AND r.status = 'pending'
   ORDER BY r.reserved_at ASC"
);
$res_stmt->execute([$user_id]);
$pending_reservations_raw = $res_stmt->fetchAll();

$book_reservations = [];
foreach ($pending_reservations_raw as $res) {
  $book_id = (int) $res['book_id'];
  $book_reservations[$book_id][] = $res;
}

$pending_reservations = [];
foreach ($pending_reservations_raw as $res) {
  $book_id  = (int) $res['book_id'];
  $position = 1;
  foreach ($book_reservations[$book_id] as $other) {
    if ($other['reserved_at'] < $res['reserved_at']) $position++;
  }
  $res['queue_position'] = $position;
  $pending_reservations[] = $res;
}

// Helper: human-readable due-date label
function due_label(string $due_date_str): string
{
  $today    = new DateTimeImmutable('today');
  $due      = new DateTimeImmutable($due_date_str);
  $diff     = $today->diff($due);
  $days     = (int) $diff->days;
  $isPast   = $diff->invert === 1;

  if ($isPast) {
    return $days === 0 ? 'Due today' : $days . ' day' . ($days > 1 ? 's' : '') . ' overdue';
  }
  if ($days === 0) return 'Due today';
  if ($days === 1) return 'Due tomorrow';
  return 'Due in ' . $days . ' days';
}

$name        = htmlspecialchars($_SESSION['full_name'], ENT_QUOTES, 'UTF-8');
$renew_url   = htmlspecialchars(BASE_URL . 'borrower/renew.php',   ENT_QUOTES, 'UTF-8');
$reserve_url = htmlspecialchars(BASE_URL . 'borrower/reserve.php', ENT_QUOTES, 'UTF-8');

$current_page = 'borrower.index';
$pageTitle    = 'My Dashboard | Library System';
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <?php require_once __DIR__ . '/../includes/head.php'; ?>
  <style>
    /* ── Dashboard action-centered redesign ─────────────────────── */

    /* STATUS SECTION */
    .status-section {
      margin-bottom: var(--space-8, 2rem);
    }

    .status-section+.status-section {
      margin-top: var(--space-6, 1.5rem);
    }

    /* Group header strip */
    .loan-group-header {
      display: flex;
      align-items: center;
      gap: var(--space-3, .75rem);
      padding: var(--space-3, .75rem) var(--space-4, 1rem);
      border-radius: 8px 8px 0 0;
      font-weight: 700;
      font-size: .85rem;
      letter-spacing: .05em;
      text-transform: uppercase;
    }

    .loan-group-header--overdue {
      background: #fef2f2;
      color: #b91c1c;
      border: 1.5px solid #fecaca;
      border-bottom: none;
    }

    .loan-group-header--soon {
      background: #fffbeb;
      color: #92400e;
      border: 1.5px solid #fde68a;
      border-bottom: none;
    }

    .loan-group-header--active {
      background: #eff6ff;
      color: #1d4ed8;
      border: 1.5px solid #bfdbfe;
      border-bottom: none;
    }

    .loan-group-header__icon {
      font-size: 1.1rem;
    }

    .loan-group-header__count {
      margin-left: auto;
      background: rgba(0, 0, 0, .08);
      border-radius: 99px;
      padding: 1px 8px;
      font-size: .78rem;
    }

    /* Loan card list */
    .loan-card-list {
      list-style: none;
      margin: 0;
      padding: 0;
      border: 1.5px solid var(--border, #e2e8f0);
      border-top: none;
      border-radius: 0 0 8px 8px;
      overflow: hidden;
    }

    .loan-card-list--overdue {
      border-color: #fecaca;
    }

    .loan-card-list--soon {
      border-color: #fde68a;
    }

    .loan-card-list--active {
      border-color: #bfdbfe;
    }

    .loan-card {
      display: flex;
      align-items: center;
      gap: var(--space-4, 1rem);
      padding: var(--space-4, 1rem) var(--space-5, 1.25rem);
      background: #fff;
      border-bottom: 1px solid var(--border, #e2e8f0);
    }

    .loan-card:last-child {
      border-bottom: none;
    }

    .loan-card--overdue {
      background: #fff8f8;
    }

    .loan-card--soon {
      background: #fffdf0;
    }

    .loan-card__info {
      flex: 1;
      min-width: 0;
    }

    .loan-card__title {
      font-weight: 600;
      font-size: .96rem;
      color: var(--ink, #1e293b);
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
    }

    .loan-card__meta {
      margin-top: .2rem;
      font-size: .82rem;
      color: var(--ink-muted, #64748b);
    }

    .loan-card__meta .due-label {
      font-weight: 600;
    }

    .due-label--overdue {
      color: #b91c1c;
    }

    .due-label--soon {
      color: #92400e;
    }

    .due-label--active {
      color: #1d4ed8;
    }

    .loan-card__actions {
      display: flex;
      gap: var(--space-2, .5rem);
      flex-shrink: 0;
    }

    /* Inline renew form */
    .loan-card__actions form {
      margin: 0;
    }

    /* Tiny action buttons */
    .btn-action {
      display: inline-flex;
      align-items: center;
      gap: .3em;
      padding: .35rem .85rem;
      border-radius: 6px;
      font-size: .82rem;
      font-weight: 600;
      cursor: pointer;
      border: none;
      text-decoration: none;
      white-space: nowrap;
      transition: opacity .15s;
    }

    .btn-action:hover {
      opacity: .85;
    }

    .btn-action--renew {
      background: var(--brand, #4a6741);
      color: #fff;
    }

    .btn-action--details {
      background: transparent;
      color: var(--ink-muted, #64748b);
      border: 1.5px solid var(--border, #e2e8f0);
    }

    .btn-action--cancel {
      background: #fef2f2;
      color: #b91c1c;
      border: 1.5px solid #fecaca;
    }

    .btn-action--view {
      background: transparent;
      color: var(--ink-muted, #64748b);
      border: 1.5px solid var(--border, #e2e8f0);
    }

    /* Empty state inline */
    .loan-empty {
      padding: var(--space-5, 1.25rem) var(--space-5, 1.25rem);
      color: var(--ink-muted, #64748b);
      font-size: .9rem;
      background: #fff;
      border: 1.5px solid var(--border, #e2e8f0);
      border-top: none;
      border-radius: 0 0 8px 8px;
      text-align: center;
    }

    /* ── RESERVATION CARDS ─────────────────────────────────────── */
    .res-card-list {
      list-style: none;
      margin: 0;
      padding: 0;
      border: 1.5px solid var(--border, #e2e8f0);
      border-top: none;
      border-radius: 0 0 8px 8px;
      overflow: hidden;
    }

    .res-card {
      display: flex;
      align-items: center;
      gap: var(--space-4, 1rem);
      padding: var(--space-4, 1rem) var(--space-5, 1.25rem);
      background: #fff;
      border-bottom: 1px solid var(--border, #e2e8f0);
    }

    .res-card:last-child {
      border-bottom: none;
    }

    .res-card__queue {
      flex-shrink: 0;
      width: 2.4rem;
      height: 2.4rem;
      border-radius: 50%;
      background: var(--brand, #4a6741);
      color: #fff;
      display: flex;
      align-items: center;
      justify-content: center;
      font-weight: 700;
      font-size: .9rem;
    }

    .res-card__queue--first {
      background: #f59e0b;
    }

    .res-card__info {
      flex: 1;
      min-width: 0;
    }

    .res-card__title {
      font-weight: 600;
      font-size: .96rem;
      color: var(--ink, #1e293b);
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
    }

    .res-card__meta {
      margin-top: .2rem;
      font-size: .82rem;
      color: var(--ink-muted, #64748b);
    }

    .res-card__status {
      font-weight: 600;
    }

    .res-card__status--waiting {
      color: var(--ink-muted, #64748b);
    }

    .res-card__status--ready {
      color: #15803d;
    }

    .res-card__actions {
      display: flex;
      gap: var(--space-2, .5rem);
      flex-shrink: 0;
    }

    .res-card__actions form {
      margin: 0;
    }

    /* ── HISTORY ACCORDION ─────────────────────────────────────── */
    .history-accordion {
      border: 1.5px solid var(--border, #e2e8f0);
      border-radius: 8px;
      overflow: hidden;
    }

    .history-toggle {
      width: 100%;
      display: flex;
      align-items: center;
      gap: var(--space-3, .75rem);
      padding: var(--space-4, 1rem) var(--space-5, 1.25rem);
      background: var(--surface, #f8fafc);
      border: none;
      cursor: pointer;
      font-size: .9rem;
      font-weight: 600;
      color: var(--ink, #1e293b);
      text-align: left;
    }

    .history-toggle__arrow {
      margin-left: auto;
      font-size: .8rem;
      transition: transform .2s;
    }

    .history-accordion.is-open .history-toggle__arrow {
      transform: rotate(180deg);
    }

    .history-body {
      display: none;
    }

    .history-accordion.is-open .history-body {
      display: block;
    }

    /* ── SECTION LABEL ─────────────────────────────────────────── */
    .dash-section-label {
      font-size: .75rem;
      font-weight: 700;
      letter-spacing: .1em;
      text-transform: uppercase;
      color: var(--ink-muted, #64748b);
      margin-bottom: var(--space-3, .75rem);
      margin-top: var(--space-8, 2rem);
    }

    .dash-section-label:first-child {
      margin-top: 0;
    }

    /* ── MOBILE RESPONSIVE ─────────────────────────────────────── */
    @media (max-width: 600px) {
      .loan-card {
        flex-wrap: wrap;
        gap: var(--space-3, .75rem);
      }

      .loan-card__actions {
        width: 100%;
      }

      .btn-action {
        flex: 1;
        justify-content: center;
      }

      .res-card {
        flex-wrap: wrap;
      }

      .res-card__actions {
        width: 100%;
      }
    }
  </style>
</head>

<body>
  <div class="app-shell">
    <?php require_once __DIR__ . '/../includes/sidebar-borrower.php'; ?>

    <main class="main-content borrower-dashboard">

      <!-- ── Page Header ─────────────────────────────────────────────────── -->
      <section class="borrower-hero">
        <div class="page-header borrower-hero__content">
          <h1>My Dashboard</h1>
          <p>Welcome back, <?= $name ?>. Here's what needs your attention today.</p>
        </div>
        <div class="borrower-hero__actions" aria-label="Dashboard quick actions">
          <a class="btn-ghost is-current" href="<?= htmlspecialchars(BASE_URL . 'borrower/index.php', ENT_QUOTES, 'UTF-8') ?>" aria-current="page">Dashboard</a>
          <a class="btn-ghost" href="<?= htmlspecialchars(BASE_URL . 'borrower/catalog.php', ENT_QUOTES, 'UTF-8') ?>">Browse Catalog</a>
        </div>
      </section>

      <!-- ── Flash messages ─────────────────────────────────────────────── -->
      <?php if ($flash_error !== ''): ?>
        <div class="flash flash-error" role="alert" aria-live="assertive" aria-atomic="true"><?= htmlspecialchars($flash_error, ENT_QUOTES, 'UTF-8') ?></div>
      <?php endif; ?>
      <?php if ($flash_success !== ''): ?>
        <div class="flash flash-success" role="alert" aria-live="polite" aria-atomic="true"><?= htmlspecialchars($flash_success, ENT_QUOTES, 'UTF-8') ?></div>
      <?php endif; ?>

      <!-- ══════════════════════════════════════════════════════════
           TOP — YOUR STATUS
           ══════════════════════════════════════════════════════════ -->
      <p class="dash-section-label">📋 Your Status</p>

      <?php
      // Helper: render a loan card row
      function render_loan_card(array $loan, string $variant, string $due_label_str, string $renew_url): void
      {
        $variantClass   = 'loan-card--' . $variant;
        $dueLabelClass  = 'due-label--' . $variant;
        $title          = htmlspecialchars($loan['title'], ENT_QUOTES, 'UTF-8');
        $checkout_date  = htmlspecialchars(date('d M Y', strtotime($loan['checkout_date'])), ENT_QUOTES, 'UTF-8');
        $due_date_fmt   = htmlspecialchars(date('d M Y', strtotime($loan['due_date'])), ENT_QUOTES, 'UTF-8');
        $loan_id        = (int) $loan['id'];
      ?>
        <li class="loan-card <?= $variantClass ?>">
          <div class="loan-card__info">
            <div class="loan-card__title" title="<?= $title ?>"><?= $title ?></div>
            <div class="loan-card__meta">
              Checked out <?= $checkout_date ?> &nbsp;·&nbsp;
              <span class="due-label <?= $dueLabelClass ?>"><?= htmlspecialchars($due_label_str, ENT_QUOTES, 'UTF-8') ?></span>
              (<?= $due_date_fmt ?>)
            </div>
          </div>
          <div class="loan-card__actions">
            <form method="POST" action="<?= $renew_url ?>">
              <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
              <input type="hidden" name="loan_id" value="<?= $loan_id ?>">
              <button type="submit" class="btn-action btn-action--renew" title="Renew this loan">↺ Renew</button>
            </form>
          </div>
        </li>
      <?php
      }
      ?>

      <!-- ① OVERDUE ──────────────────────────────────────────────────────── -->
      <div class="status-section">
        <div class="loan-group-header loan-group-header--overdue">
          <span class="loan-group-header__icon">⚠️</span>
          <span>Overdue Books</span>
          <span class="loan-group-header__count"><?= count($overdue_loans) ?></span>
        </div>
        <?php if (empty($overdue_loans)): ?>
          <div class="loan-empty">No overdue books — great job! 🎉</div>
        <?php else: ?>
          <ul class="loan-card-list loan-card-list--overdue" aria-label="Overdue loans">
            <?php foreach ($overdue_loans as $loan): ?>
              <?php render_loan_card($loan, 'overdue', due_label($loan['due_date']), $renew_url); ?>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </div>

      <!-- ② DUE SOON ─────────────────────────────────────────────────────── -->
      <div class="status-section">
        <div class="loan-group-header loan-group-header--soon">
          <span class="loan-group-header__icon">⏳</span>
          <span>Due Soon</span>
          <span class="loan-group-header__count"><?= count($due_soon) ?></span>
        </div>
        <?php if (empty($due_soon)): ?>
          <div class="loan-empty">Nothing due within the next 3 days.</div>
        <?php else: ?>
          <ul class="loan-card-list loan-card-list--soon" aria-label="Due soon loans">
            <?php foreach ($due_soon as $loan): ?>
              <?php render_loan_card($loan, 'soon', due_label($loan['due_date']), $renew_url); ?>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </div>

      <!-- ③ ACTIVE LOANS ─────────────────────────────────────────────────── -->
      <div class="status-section">
        <div class="loan-group-header loan-group-header--active">
          <span class="loan-group-header__icon">📚</span>
          <span>Active Loans</span>
          <span class="loan-group-header__count"><?= count($other_active) ?></span>
        </div>
        <?php if (empty($other_active)): ?>
          <div class="loan-empty">No other active loans right now.</div>
        <?php else: ?>
          <ul class="loan-card-list loan-card-list--active" aria-label="Active loans">
            <?php foreach ($other_active as $loan): ?>
              <?php render_loan_card($loan, 'active', due_label($loan['due_date']), $renew_url); ?>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </div>

      <!-- ══════════════════════════════════════════════════════════
           MIDDLE — YOUR RESERVATIONS
           ══════════════════════════════════════════════════════════ -->
      <p class="dash-section-label">🔖 Your Reservations</p>

      <div class="loan-group-header loan-group-header--active" style="background:#f0fdf4;color:#166534;border-color:#bbf7d0;">
        <span class="loan-group-header__icon">🔖</span>
        <span>Pending Reservations</span>
        <span class="loan-group-header__count"><?= count($pending_reservations) ?></span>
      </div>

      <?php if (empty($pending_reservations)): ?>
        <div class="loan-empty" style="border-color:#bbf7d0;border-radius:0 0 8px 8px;">No pending reservations.</div>
      <?php else: ?>
        <ul class="res-card-list" aria-label="Pending reservations" style="border-color:#bbf7d0;">
          <?php foreach ($pending_reservations as $res):
            $pos         = (int) $res['queue_position'];
            $is_first    = $pos === 1;
            $title_esc   = htmlspecialchars($res['title'], ENT_QUOTES, 'UTF-8');
            $expires_fmt = $res['expires_at']
              ? htmlspecialchars(date('d M Y', strtotime($res['expires_at'])), ENT_QUOTES, 'UTF-8')
              : '—';
            $status_label = $is_first ? 'Ready for Pickup' : 'Waiting';
            $status_class = $is_first ? 'res-card__status--ready' : 'res-card__status--waiting';
          ?>
            <li class="res-card">
              <div class="res-card__queue <?= $is_first ? 'res-card__queue--first' : '' ?>" title="Queue position <?= $pos ?>">
                <?= $pos ?>
              </div>
              <div class="res-card__info">
                <div class="res-card__title" title="<?= $title_esc ?>"><?= $title_esc ?></div>
                <div class="res-card__meta">
                  Status: <span class="res-card__status <?= $status_class ?>"><?= $status_label ?></span>
                  &nbsp;·&nbsp; Queue position: <strong>#<?= $pos ?></strong>
                  <?php if ($res['expires_at']): ?>
                    &nbsp;·&nbsp; Expires: <?= $expires_fmt ?>
                  <?php endif; ?>
                </div>
              </div>
              <div class="res-card__actions">
                <form method="POST" action="<?= $reserve_url ?>">
                  <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                  <input type="hidden" name="action" value="cancel">
                  <input type="hidden" name="reservation_id" value="<?= (int) $res['id'] ?>">
                  <button type="submit" class="btn-action btn-action--cancel">✕ Cancel</button>
                </form>
              </div>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>

      <!-- ══════════════════════════════════════════════════════════
           BOTTOM — BORROWING HISTORY (Collapsed)
           ══════════════════════════════════════════════════════════ -->
      <p class="dash-section-label" style="margin-top:var(--space-10,2.5rem);">📖 Borrowing History</p>

      <div class="history-accordion" id="historyAccordion">
        <button
          class="history-toggle"
          aria-expanded="false"
          aria-controls="historyBody"
          onclick="toggleHistory()">
          <span>📖</span>
          <span>View Borrowing History</span>
          <span style="color:var(--ink-muted);font-weight:400;font-size:.82rem;margin-left:.5rem;">
            (<?= count($loan_history) ?> recent return<?= count($loan_history) !== 1 ? 's' : '' ?>)
          </span>
          <span class="history-toggle__arrow" aria-hidden="true">▼</span>
        </button>

        <div class="history-body" id="historyBody" role="region" aria-label="Borrowing history">
          <?php if (empty($loan_history)): ?>
            <div class="loan-empty" style="border-radius:0;border:none;border-top:1px solid var(--border,#e2e8f0);">
              No past loans on record.
            </div>
          <?php else: ?>
            <div class="tbl-wrapper">
              <table class="tbl" aria-label="Loan history table">
                <thead>
                  <tr>
                    <th>Book</th>
                    <th>Returned</th>
                    <th>Fine</th>
                    <th>Fine Paid</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($loan_history as $h): ?>
                    <tr>
                      <td data-label="Book"><?= htmlspecialchars($h['title'], ENT_QUOTES, 'UTF-8') ?></td>
                      <td data-label="Returned"><?= htmlspecialchars($h['return_date'] ? date('d M Y', strtotime($h['return_date'])) : 'N/A', ENT_QUOTES, 'UTF-8') ?></td>
                      <td data-label="Fine"><?= htmlspecialchars(number_format((float) $h['fine_amount'], 2), ENT_QUOTES, 'UTF-8') ?></td>
                      <td data-label="Fine Paid">
                        <?php if ($h['fine_paid']): ?>
                          <span class="badge badge-green">Yes</span>
                        <?php else: ?>
                          <span class="badge badge-red">No</span>
                        <?php endif; ?>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>
        </div>
      </div>

    </main>
  </div>

  <script>
    function toggleHistory() {
      var accordion = document.getElementById('historyAccordion');
      var btn = accordion.querySelector('.history-toggle');
      var isOpen = accordion.classList.toggle('is-open');
      btn.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
    }
  </script>
</body>

</html>