<?php

/**
 * librarian/checkin.php — Process Book Check-In / Return (US2, FR-010–FR-015)
 *
 * GET:  Bulk-update overdue loans, then display the active/overdue loan list
 *       with a Return button per row.
 * POST: Atomically record the return date, restore available_copies (with
 *       LEAST() guard), calculate any fine, and write a System_Logs audit entry.
 *
 * Protected: Librarian role only (FR-029).
 */

// RBAC guard — must appear before any HTML output (FR-034)
$allowed_roles = ['librarian'];
require_once __DIR__ . '/../includes/auth_guard.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/circulation.php';
require_once __DIR__ . '/../includes/receipts.php';
require_once __DIR__ . '/../includes/LibrarianCheckinModule.php';

$pdo = get_db();

// ---------------------------------------------------------------------------
// POST handler — T014–T016
// ---------------------------------------------------------------------------
function checkin_apply_result_flash(PDO $pdo, array $result): void
{
  $reason = (string) ($result['reason_code'] ?? '');
  if (empty($result['success'])) {
    switch ($reason) {
      case 'invalid_loan':
        $_SESSION['flash_error'] = 'Invalid loan reference. Please select a valid loan from the list.';
        return;
      case 'not_found_or_returned':
        $_SESSION['flash_error'] = 'Loan not found or has already been returned.';
        return;
      case 'missing_book_reference':
        $_SESSION['flash_error'] = 'This loan record is missing a valid book reference and cannot be processed.';
        return;
      case 'already_returned':
        $_SESSION['flash_error'] = 'Loan was already returned by another request.';
        return;
      case 'unexpected_error':
      default:
        $_SESSION['flash_error'] = 'An unexpected error occurred. Please try again.';
        return;
    }
  }

  $loanId = (int) ($result['loan_id'] ?? 0);
  $fine = (float) ($result['fine_amount'] ?? 0.0);
  $fineMsg = $fine > 0.00
    ? ' Total fine assessed: ' . format_circulation_money($pdo, $fine) . '. Borrower cannot borrow another book until this fine is paid.'
    : ' No fine.';

  $alertMsg = !empty($result['has_pending_reservations'])
    ? ' ⚠️ Note: There are pending reservations for this book. Please place it on the Hold shelf.'
    : '';

  $_SESSION['flash_success'] = 'Book returned successfully. Loan ID: ' . $loanId . '.' . $fineMsg . $alertMsg;
  $_SESSION['flash_receipt_no'] = (string) ($result['receipt']['receipt_no'] ?? '');
  $_SESSION['flash_print_url'] = BASE_URL . 'librarian/print-record.php?loan_id=' . $loanId . '&type=checkin';
  $_SESSION['flash_print_label'] = 'Print Return Record';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_verify();

  $loanId = filter_var($_POST['loan_id'] ?? '', FILTER_VALIDATE_INT);
  $loanId = ($loanId !== false && $loanId > 0) ? (int) $loanId : 0;

  $result = LibrarianCheckinModule::process(
    $pdo,
    $loanId,
    (int) $_SESSION['user_id'],
    (string) $_SESSION['role']
  );

  checkin_apply_result_flash($pdo, $result);
  header('Location: ' . BASE_URL . 'librarian/checkin.php');
  exit;
}

// ---------------------------------------------------------------------------
// GET handler — T013
// ---------------------------------------------------------------------------

// Bulk overdue update is routed through the check-in module seam.
LibrarianCheckinModule::markOverdueLoans($pdo);

// Count active + overdue total (for pagination)
$count_loans_stmt = $pdo->query(
  "SELECT COUNT(*) FROM Circulation WHERE status IN ('active', 'overdue')"
);
$loans_total = (int) $count_loans_stmt->fetchColumn();

$loans_per_page = 15;
$loans_page = max(1, (int) ($_GET['loans_page'] ?? 1));
$loans_total_pages = max(1, (int) ceil($loans_total / $loans_per_page));
$loans_page = min($loans_page, $loans_total_pages);
$loans_offset = ($loans_page - 1) * $loans_per_page;

// Query active + overdue loans joined with Users and Books
$loans_stmt = $pdo->prepare(
  'SELECT c.id, c.user_id, c.book_id, c.due_date, c.status,
            b.title, u.full_name
       FROM Circulation c
       JOIN Books       b ON c.book_id  = b.id
       JOIN Users       u ON c.user_id  = u.id
      WHERE c.status IN (\'active\', \'overdue\')
      ORDER BY c.due_date ASC
      LIMIT ? OFFSET ?'
);
$loans_stmt->execute([$loans_per_page, $loans_offset]);
$loans = $loans_stmt->fetchAll();

// ---------------------------------------------------------------------------
// Returned Books History query
// ---------------------------------------------------------------------------
$returned_filter = trim((string) ($_GET['returned_filter'] ?? ''));
$returned_search = trim((string) ($_GET['returned_search'] ?? ''));

$returned_allowed_filters = ['', 'today', 'with_fine', 'no_fine', 'this_week', 'this_month'];
if (!in_array($returned_filter, $returned_allowed_filters, true)) {
    $returned_filter = '';
}

$r_where = ["c.status = 'returned'"];
$r_params = [];

if ($returned_search !== '') {
    $like = '%' . $returned_search . '%';
    if (is_numeric($returned_search)) {
        $r_where[] = '(u.full_name LIKE ? OR b.title LIKE ? OR c.id = ?)';
        $r_params[] = $like;
        $r_params[] = $like;
        $r_params[] = (int) $returned_search;
    } else {
        $r_where[] = '(u.full_name LIKE ? OR b.title LIKE ?)';
        $r_params[] = $like;
        $r_params[] = $like;
    }
}

switch ($returned_filter) {
    case 'today':
        $r_where[] = 'DATE(c.return_date) = CURDATE()';
        break;
    case 'with_fine':
        $r_where[] = 'c.fine_amount > 0';
        break;
    case 'no_fine':
        $r_where[] = 'c.fine_amount = 0';
        break;
    case 'this_week':
        $r_where[] = 'YEARWEEK(c.return_date) = YEARWEEK(CURDATE())';
        break;
    case 'this_month':
        $r_where[] = 'YEAR(c.return_date) = YEAR(CURDATE()) AND MONTH(c.return_date) = MONTH(CURDATE())';
        break;
}

$r_sql = 'WHERE ' . implode(' AND ', $r_where);

// Count total returned (for pagination)
$count_stmt = $pdo->prepare(
    "SELECT COUNT(*)
       FROM Circulation c
       JOIN Users u ON c.user_id = u.id
       JOIN Books b ON c.book_id = b.id
       {$r_sql}"
);
$count_stmt->execute($r_params);
$returned_total = (int) $count_stmt->fetchColumn();

$returned_per_page = 15;
$returned_page = max(1, (int) ($_GET['returned_page'] ?? 1));
$returned_total_pages = max(1, (int) ceil($returned_total / $returned_per_page));
$returned_page = min($returned_page, $returned_total_pages);
$returned_offset = ($returned_page - 1) * $returned_per_page;

$returned_stmt = $pdo->prepare(
    "SELECT c.id, c.checkout_date, c.due_date, c.return_date, c.fine_amount, c.fine_paid,
            u.full_name, b.title
       FROM Circulation c
       JOIN Users u ON c.user_id = u.id
       JOIN Books b ON c.book_id = b.id
       {$r_sql}
       ORDER BY c.return_date DESC
       LIMIT ? OFFSET ?"
);
$returned_stmt->execute([...$r_params, $returned_per_page, $returned_offset]);
$returned_loans = $returned_stmt->fetchAll();

function returned_filter_url($filter_value, $search, $base_url, $page = '') {
    $params = [];
    if (isset($_GET['loans_page']) && $_GET['loans_page'] > 1) $params['loans_page'] = (int) $_GET['loans_page'];
    if ($search !== '') $params['returned_search'] = $search;
    if ($filter_value !== '') $params['returned_filter'] = $filter_value;
    if ($page !== '') $params['returned_page'] = $page;
    return $base_url . ($params ? '?' . http_build_query($params) : '');
}

// Flash messages
$flash_error   = $_SESSION['flash_error']   ?? '';
$flash_success = $_SESSION['flash_success'] ?? '';
$flash_print_url = $_SESSION['flash_print_url'] ?? '';
$flash_print_label = $_SESSION['flash_print_label'] ?? 'Print Record';
$flash_receipt_no = $_SESSION['flash_receipt_no'] ?? '';
unset($_SESSION['flash_error'], $_SESSION['flash_success'], $_SESSION['flash_print_url'], $_SESSION['flash_print_label'], $_SESSION['flash_receipt_no']);

$logout_url = htmlspecialchars(BASE_URL . 'logout.php', ENT_QUOTES, 'UTF-8');
$current_page = 'librarian.checkin';
$pageTitle    = 'Check In | LibraLink';
$extraStyles = [
  'https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700&display=swap',
  BASE_URL . 'assets/css/borrower-redesign.css',
  BASE_URL . 'assets/css/librarian-redesign.css'
];
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <?php require_once __DIR__ . '/../includes/head.php'; ?>
  <style>
    .returned-pagination { display:flex; align-items:center; justify-content:center; gap:0.75rem; padding:1rem 1.5rem; flex-wrap:wrap; }
    .returned-pagination__pages { display:flex; align-items:center; gap:4px; border:1px solid var(--rd-border); border-radius:8px; padding:4px; background:rgba(42,39,35,0.5); }
    .returned-pagination__link { display:inline-flex; align-items:center; justify-content:center; min-width:36px; min-height:36px; border-radius:8px; background:rgba(255,255,255,0.05); border:1px solid var(--rd-border); color:var(--rd-text); text-decoration:none; font-size:0.85rem; transition:all 0.2s; }
    .returned-pagination__link:hover { background:rgba(255,255,255,0.1); border-color:var(--rd-primary); color:var(--rd-primary); }
    .returned-pagination__current { display:inline-flex; align-items:center; justify-content:center; min-width:36px; min-height:36px; border-radius:8px; background:var(--rd-primary); color:#1c1a17; font-weight:700; font-size:0.85rem; }
    .returned-pagination__disabled { color:var(--rd-text-muted); padding:0.5rem 0.75rem; font-size:0.85rem; }

    /* ── Returned Books History — Search & Filter Panel ────────────── */
    .returned-search-area {
      padding: 1.75rem 1.75rem 0.75rem;
      background: #332F2A;
      border-bottom: 1px solid rgba(212, 175, 55, 0.35);
    }
    .returned-search-area .returned-search-label {
      display: block;
      margin: 0 0 0.75rem 0;
      font-size: 0.75rem;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: 0.04em;
      color: #B8B0A3;
    }
    .returned-search-area .returned-search-row {
      display: flex;
      flex-wrap: wrap;
      gap: 0.75rem;
      align-items: flex-end;
    }
    .returned-search-area .returned-search-field {
      flex: 1;
      min-width: 200px;
    }
    .returned-search-area .returned-search-field .field-input {
      background: #3B362F;
      border-color: rgba(255,255,255,0.08);
      color: #F5F0E6;
    }
    .returned-search-area .returned-search-field .field-input:focus {
      border-color: rgba(212, 175, 55, 0.6);
      box-shadow: 0 0 0 3px rgba(212, 175, 55, 0.12);
    }
    .returned-search-area .returned-search-field .field-input::placeholder {
      color: #B8B0A3;
      opacity: 1;
    }
    .returned-search-area .returned-search-actions {
      display: flex;
      gap: 0.5rem;
      flex-shrink: 0;
    }
    .returned-search-area .returned-chips-row {
      display: flex;
      flex-wrap: wrap;
      gap: 0.5rem;
      margin-top: 1rem;
      padding-bottom: 0.75rem;
    }
    .returned-search-area .returned-chip {
      display: inline-flex;
      align-items: center;
      border-radius: 9999px;
      padding: 0.4rem 1rem;
      font-size: 0.78rem;
      font-weight: 600;
      text-decoration: none;
      transition: all 0.2s ease;
      border: 1px solid rgba(255,255,255,0.08);
      background: rgba(255,255,255,0.06);
      color: #B8B0A3;
    }
    .returned-search-area .returned-chip:hover {
      background: rgba(212, 175, 55, 0.12);
      color: #D4AF37;
      border-color: rgba(212, 175, 55, 0.3);
    }
    .returned-search-area .returned-chip--active {
      background: rgba(212, 175, 55, 0.18);
      color: #D4AF37;
      border-color: #D4AF37;
    }
    .returned-search-area .returned-chip--active:hover {
      background: rgba(212, 175, 55, 0.25);
    }
  </style>
</head>

<body class="librarian-themed">
  <div class="app-shell">
    <?php require_once __DIR__ . '/../includes/sidebar-librarian.php'; ?>
    <main class="main-content">
      <div class="page-header">
        <h1>Process Returns</h1>
      </div>

      <?php if ($flash_error !== ''): ?>
        <div class="flash flash-error" role="alert" aria-live="assertive" aria-atomic="true"><?= htmlspecialchars($flash_error, ENT_QUOTES, 'UTF-8') ?></div>
      <?php endif; ?>
      <?php if ($flash_success !== ''): ?>
        <div class="flash flash-success" role="alert" aria-live="polite" aria-atomic="true">
          <?= htmlspecialchars($flash_success, ENT_QUOTES, 'UTF-8') ?>
          <?php if ($flash_print_url !== ''): ?>
            <div style="margin-top: var(--space-3);">
              <a
                class="btn-confirm"
                href="<?= htmlspecialchars($flash_print_url, ENT_QUOTES, 'UTF-8') ?>"
                target="_blank"
                rel="noopener noreferrer"
              ><?= htmlspecialchars($flash_print_label, ENT_QUOTES, 'UTF-8') ?></a>
            </div>
          <?php endif; ?>
        </div>
      <?php endif; ?>
      <?php
      $receipt_modal_title = 'Receipt issued';
      $receipt_modal_message = 'Book return was completed successfully. Print the ticket from this overlay.';
      $receipt_modal_print_label = 'Print Ticket';
      require __DIR__ . '/../includes/receipt-success-modal.php';
      ?>

      <div class="section-card">
        <div class="section-card__header">
          <span class="section-card__title">Active &amp; Overdue Loans</span>
        </div>

        <?php if (empty($loans)): ?>
          <div class="empty-state">
            <span class="empty-state__icon">&#10003;</span>
            <p>No active or overdue loans at this time.</p>
          </div>
        <?php else: ?>
          <div class="tbl-wrapper">
            <table class="tbl">
              <thead>
                <tr>
                  <th>Loan #</th>
                  <th>Borrower</th>
                  <th>Book</th>
                  <th>Due Date</th>
                  <th>Status</th>
                  <th>Action</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($loans as $loan): ?>
                  <?php $loan_due_ts = strtotime((string) $loan['due_date']); ?>
                  <tr class="<?= $loan['status'] === 'overdue' ? 'row-overdue' : '' ?>">
                    <td data-label="Loan #"><?= (int) $loan['id'] ?></td>
                    <td data-label="Borrower"><?= htmlspecialchars($loan['full_name'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td data-label="Book"><?= htmlspecialchars($loan['title'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td data-label="Due Date"><?= htmlspecialchars($loan_due_ts !== false ? date('m-d-y', $loan_due_ts) : (string) $loan['due_date'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td data-label="Status">
                      <?php if ($loan['status'] === 'overdue'): ?>
                        <span class="badge badge-amber">Overdue</span>
                      <?php else: ?>
                        <span class="badge badge-blue">Active</span>
                      <?php endif; ?>
                    </td>
                    <td data-label="Action">
                      <form method="POST" action="<?= htmlspecialchars(BASE_URL . 'librarian/checkin.php', ENT_QUOTES, 'UTF-8') ?>">
                        <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                        <input type="hidden" name="loan_id" value="<?= (int) $loan['id'] ?>">
                        <button type="submit" class="btn-confirm">Return</button>
                      </form>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>

          <?php if ($loans_total_pages > 1): ?>
          <nav class="returned-pagination" aria-label="Active loans pagination">
            <?php if ($loans_page > 1): ?>
              <a class="btn-ghost" href="<?= htmlspecialchars(BASE_URL . 'librarian/checkin.php?' . http_build_query(array_merge($_GET, ['loans_page' => $loans_page - 1])), ENT_QUOTES, 'UTF-8') ?>" aria-label="Go to previous page">&larr; Previous</a>
            <?php else: ?>
              <span class="returned-pagination__disabled" aria-disabled="true">&larr; Previous</span>
            <?php endif; ?>

            <div class="returned-pagination__pages">
              <?php
              $l_start = max(1, $loans_page - 2);
              $l_end = min($loans_total_pages, $loans_page + 2);
              for ($i = $l_start; $i <= $l_end; $i++):
              ?>
                <?php if ($i === $loans_page): ?>
                  <span class="returned-pagination__current" aria-current="page"><?= (int) $i ?></span>
                <?php else: ?>
                  <a class="returned-pagination__link" href="<?= htmlspecialchars(BASE_URL . 'librarian/checkin.php?' . http_build_query(array_merge($_GET, ['loans_page' => $i])), ENT_QUOTES, 'UTF-8') ?>"><?= (int) $i ?></a>
                <?php endif; ?>
              <?php endfor; ?>
            </div>

            <?php if ($loans_page < $loans_total_pages): ?>
              <a class="btn-ghost" href="<?= htmlspecialchars(BASE_URL . 'librarian/checkin.php?' . http_build_query(array_merge($_GET, ['loans_page' => $loans_page + 1])), ENT_QUOTES, 'UTF-8') ?>" aria-label="Go to next page">Next &rarr;</a>
            <?php else: ?>
              <span class="returned-pagination__disabled" aria-disabled="true">Next &rarr;</span>
            <?php endif; ?>
          </nav>
          <?php endif; ?>
        <?php endif; ?>
      </div>

      <!-- Returned Books History -->
      <div class="section-card" style="margin-top: var(--space-6);">
        <div class="section-card__header">
          <span class="section-card__title">Returned Books History</span>
        </div>

        <div class="returned-search-area">
          <form method="GET" action="<?= htmlspecialchars(BASE_URL . 'librarian/checkin.php', ENT_QUOTES, 'UTF-8') ?>">
            <span class="returned-search-label">Search (Name / Book / Loan #)</span>
            <div class="returned-search-row">
              <div class="returned-search-field">
                <input class="field-input" type="text" id="returned-search" name="returned_search"
                       placeholder="Search returned books..."
                       value="<?= htmlspecialchars($returned_search, ENT_QUOTES, 'UTF-8') ?>">
              </div>
              <div class="returned-search-actions">
                <button type="submit" class="btn-primary">Search</button>
                <a href="<?= htmlspecialchars(BASE_URL . 'librarian/checkin.php', ENT_QUOTES, 'UTF-8') ?>" class="btn-ghost">Reset</a>
              </div>
            </div>
            <div class="returned-chips-row">
              <?php $filter_chips = [
                '' => 'All Returned',
                'today' => 'Returned Today',
                'with_fine' => 'With Fine',
                'no_fine' => 'No Fine',
                'this_week' => 'This Week',
                'this_month' => 'This Month',
              ]; ?>
              <?php foreach ($filter_chips as $fc_val => $fc_label): ?>
                <a href="<?= htmlspecialchars(returned_filter_url($fc_val, $returned_search, BASE_URL . 'librarian/checkin.php'), ENT_QUOTES, 'UTF-8') ?>"
                   class="returned-chip<?= $returned_filter === $fc_val ? ' returned-chip--active' : '' ?>">
                  <?= htmlspecialchars($fc_label, ENT_QUOTES, 'UTF-8') ?>
                </a>
              <?php endforeach; ?>
            </div>
          </form>
        </div>

        <?php if (empty($returned_loans)): ?>
          <div class="empty-state">
            <span class="empty-state__icon">&#128230;</span>
            <p>No returned books found.</p>
          </div>
        <?php else: ?>
          <div class="tbl-wrapper">
            <table class="tbl">
              <thead>
                <tr>
                  <th>Loan #</th>
                  <th>Borrower</th>
                  <th>Book Title</th>
                  <th>Date Borrowed</th>
                  <th>Due Date</th>
                  <th>Date Returned</th>
                  <th>Fine (&#8369;)</th>
                  <th>Return Status</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($returned_loans as $rl): ?>
                  <?php
                    $rl_fine = (float) ($rl['fine_amount'] ?? 0);
                    $rl_fine_paid = (int) ($rl['fine_paid'] ?? 0);
                    $checkout_fmt = !empty($rl['checkout_date']) ? date('m-d-y', strtotime((string) $rl['checkout_date'])) : '—';
                    $due_fmt      = !empty($rl['due_date'])      ? date('m-d-y', strtotime((string) $rl['due_date']))      : '—';
                    $return_fmt   = !empty($rl['return_date'])   ? date('m-d-y', strtotime((string) $rl['return_date']))   : '—';
                  ?>
                  <tr>
                    <td data-label="Loan #"><?= (int) $rl['id'] ?></td>
                    <td data-label="Borrower"><?= htmlspecialchars((string) ($rl['full_name'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></td>
                    <td data-label="Book Title"><?= htmlspecialchars((string) ($rl['title'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></td>
                    <td data-label="Date Borrowed"><?= htmlspecialchars($checkout_fmt, ENT_QUOTES, 'UTF-8') ?></td>
                    <td data-label="Due Date"><?= htmlspecialchars($due_fmt, ENT_QUOTES, 'UTF-8') ?></td>
                    <td data-label="Date Returned"><?= htmlspecialchars($return_fmt, ENT_QUOTES, 'UTF-8') ?></td>
                    <td data-label="Fine (&#8369;)">
                      <?php if ($rl_fine > 0): ?>
                        <span style="color:var(--rd-warning);font-weight:600;">&#8369;<?= htmlspecialchars(number_format($rl_fine, 2), ENT_QUOTES, 'UTF-8') ?></span>
                      <?php else: ?>
                        <span style="color:var(--rd-text-muted);">&#8369;0.00</span>
                      <?php endif; ?>
                    </td>
                    <td data-label="Return Status">
                      <?php if ($rl_fine > 0 && !$rl_fine_paid): ?>
                        <span class="badge badge-amber">Unpaid Fine</span>
                      <?php elseif ($rl_fine > 0 && $rl_fine_paid): ?>
                        <span class="badge badge-green">Fine Paid</span>
                      <?php else: ?>
                        <span class="badge badge-green">Returned</span>
                      <?php endif; ?>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>

          <?php if ($returned_total_pages > 1): ?>
          <nav class="returned-pagination" aria-label="Returned books pagination">
            <?php if ($returned_page > 1): ?>
              <a class="btn-ghost" href="<?= htmlspecialchars(returned_filter_url($returned_filter, $returned_search, BASE_URL . 'librarian/checkin.php', $returned_page - 1), ENT_QUOTES, 'UTF-8') ?>" aria-label="Go to previous page">&larr; Previous</a>
            <?php else: ?>
              <span class="returned-pagination__disabled" aria-disabled="true">&larr; Previous</span>
            <?php endif; ?>

            <div class="returned-pagination__pages">
              <?php
              $r_start = max(1, $returned_page - 2);
              $r_end = min($returned_total_pages, $returned_page + 2);
              for ($i = $r_start; $i <= $r_end; $i++):
              ?>
                <?php if ($i === $returned_page): ?>
                  <span class="returned-pagination__current" aria-current="page"><?= (int) $i ?></span>
                <?php else: ?>
                  <a class="returned-pagination__link" href="<?= htmlspecialchars(returned_filter_url($returned_filter, $returned_search, BASE_URL . 'librarian/checkin.php', $i), ENT_QUOTES, 'UTF-8') ?>"><?= (int) $i ?></a>
                <?php endif; ?>
              <?php endfor; ?>
            </div>

            <?php if ($returned_page < $returned_total_pages): ?>
              <a class="btn-ghost" href="<?= htmlspecialchars(returned_filter_url($returned_filter, $returned_search, BASE_URL . 'librarian/checkin.php', $returned_page + 1), ENT_QUOTES, 'UTF-8') ?>" aria-label="Go to next page">Next &rarr;</a>
            <?php else: ?>
              <span class="returned-pagination__disabled" aria-disabled="true">Next &rarr;</span>
            <?php endif; ?>
          </nav>
          <?php endif; ?>
        <?php endif; ?>
      </div>
    </main>
  </div>
</body>

</html>





