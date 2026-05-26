<?php

/**
 * librarian/records.php — Generate Circulation Records (Librarian)
 *
 * GET:  Display filterable list of all circulation records with summary stats.
 *       Supports filter by status, date range, and borrower name search.
 *       Allows printing individual records or printing the full filtered list.
 *
 * Protected: Librarian role only.
 */

$allowed_roles = ['librarian'];
require_once __DIR__ . '/../includes/auth_guard.php';
require_once __DIR__ . '/../includes/circulation.php';

$pdo = get_db();

// ---------------------------------------------------------------------------
// Filter inputs
// ---------------------------------------------------------------------------
$filter_status = trim((string) ($_GET['status'] ?? ''));
$filter_search = trim((string) ($_GET['search'] ?? ''));
$filter_date_from = trim((string) ($_GET['date_from'] ?? ''));
$filter_date_to   = trim((string) ($_GET['date_to'] ?? ''));
$filter_report_type = circulation_normalize_report_type(trim((string) ($_GET['report_type'] ?? 'circulation_records')));
$rows_per_page = (int) ($_GET['per_page'] ?? 10);
$current_report_page = max(1, (int) ($_GET['page'] ?? 1));

function records_filter_date_to_sql(string $value): string
{
    $value = trim($value);
    if ($value === '') {
        return '';
    }

    foreach (['!m-d-y', '!Y-m-d'] as $format) {
        $date = DateTimeImmutable::createFromFormat($format, $value);
        $errors = DateTimeImmutable::getLastErrors();
        if ($date instanceof DateTimeImmutable && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))) {
            return $date->format('Y-m-d');
        }
    }

    return '';
}

function records_filter_date_display(string $sql_date): string
{
    $date = records_filter_date_to_sql($sql_date);
    if ($date === '') {
        return '';
    }
    return DateTimeImmutable::createFromFormat('!Y-m-d', $date)->format('m-d-y');
}

$filter_date_from_sql = records_filter_date_to_sql($filter_date_from);
$filter_date_to_sql = records_filter_date_to_sql($filter_date_to);
$filter_date_from = records_filter_date_display($filter_date_from_sql);
$filter_date_to = records_filter_date_display($filter_date_to_sql);

$allowed_statuses = ['', 'active', 'overdue', 'returned', 'returned_late'];
if (!in_array($filter_status, $allowed_statuses, true)) {
    $filter_status = '';
}
if (!in_array($rows_per_page, [10, 25, 50], true)) {
    $rows_per_page = 10;
}

// ---------------------------------------------------------------------------
// Build query
// ---------------------------------------------------------------------------
$where_parts = [];
$params = [];

if ($filter_search !== '') {
    $where_parts[] = '(u.full_name LIKE ? OR b.title LIKE ? OR u.email LIKE ?)';
    $like = '%' . $filter_search . '%';
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}

if ($filter_date_from !== '') {
    $where_parts[] = 'DATE(c.checkout_date) >= ?';
    $params[] = $filter_date_from_sql;
}

if ($filter_date_to !== '') {
    $where_parts[] = 'DATE(c.checkout_date) <= ?';
    $params[] = $filter_date_to_sql;
}

$where_sql = count($where_parts) > 0 ? 'WHERE ' . implode(' AND ', $where_parts) : '';

$records_stmt = $pdo->prepare(
    "SELECT
        c.id,
        c.status,
        c.checkout_date,
        c.due_date,
        c.return_date,
        c.fine_amount,
        c.fine_paid,
        u.full_name   AS borrower_name,
        u.email       AS borrower_email,
        b.title       AS book_title,
        b.author      AS book_author,
        b.isbn        AS book_isbn
     FROM Circulation c
     JOIN Users u ON u.id = c.user_id
     JOIN Books b ON b.id = c.book_id
     {$where_sql}
     ORDER BY c.checkout_date DESC
     LIMIT 5000"
);
$records_stmt->execute($params);
$raw_records = $records_stmt->fetchAll();

$daily_fine_rate = (float) get_setting($pdo, 'fine_per_day', '0.00');
$now = new DateTimeImmutable('now');
$records = [];
foreach ($raw_records as $record) {
    $record = circulation_enrich_report_record($record, $daily_fine_rate, $now);
    if ($filter_status !== '' && (string) $record['effective_status'] !== $filter_status) {
        continue;
    }
    if (!circulation_record_matches_report_type($record, $filter_report_type)) {
        continue;
    }
    $records[] = $record;
}

// ---------------------------------------------------------------------------
// Summary stats (filtered)
// ---------------------------------------------------------------------------
$summary = circulation_report_summary($records);
$total_records = (int) $summary['total_records'];
$total_pages = max(1, (int) ceil($total_records / $rows_per_page));
$current_report_page = min($current_report_page, $total_pages);
$offset = ($current_report_page - 1) * $rows_per_page;
$paged_records = array_slice($records, $offset, $rows_per_page);
$page_start = $total_records > 0 ? $offset + 1 : 0;
$page_end = min($offset + $rows_per_page, $total_records);

$active_filter_chips = [
  'Report Type: ' . circulation_report_type_label($filter_report_type),
  'Status: ' . ($filter_status !== '' ? circulation_status_label($filter_status) : 'All Statuses'),
  'Checkout: ' . (
    $filter_date_from !== '' || $filter_date_to !== ''
      ? (($filter_date_from !== '' ? $filter_date_from : 'Any') . ' to ' . ($filter_date_to !== '' ? $filter_date_to : 'Any'))
      : 'All Dates'
  ),
];
if ($filter_search !== '') {
  $active_filter_chips[] = 'Search: ' . $filter_search;
}

$library_name = get_setting($pdo, 'library_name', 'LibraLink');
$prepared_by  = (string) ($_SESSION['full_name'] ?? 'Librarian');
$generated_at = date('m-d-y h:i A');
$report_type_label = circulation_report_type_label($filter_report_type);

$current_page = 'librarian.records';
$pageTitle    = 'Generate Records | LibraLink';
$includeSweetAlert = false;
$extraStyles = [
  'https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700&display=swap',
  BASE_URL . 'assets/css/borrower-redesign.css',
  BASE_URL . 'assets/css/librarian-redesign.css'
];
$extraScripts = [
  BASE_URL . 'assets/js/date-calendar-fields.js'
];
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <?php require_once __DIR__ . '/../includes/head.php'; ?>
  <style>
    /* Print-only: only what's NOT in librarian-redesign.css */
    @page { size: 8.5in 13in; margin: 0.5in; }

    @media print {
      body { background: #fff !important; color: #1a1a1a !important; }
      .app-shell { display: block !important; }
      .sidebar   { display: none !important; }
      .main-content { margin: 0 !important; padding: 0 !important; }

      .print-header {
        text-align: center;
        margin-bottom: 16px;
        border-bottom: 2px solid #000;
        padding-bottom: 10px;
      }

      .print-header__title { font-size: 20px; font-weight: 700; }
      .print-header__subtitle { font-size: 12px; color: #555; margin-top: 4px; }

      .records-table th, .records-table td { font-size: 11px !important; padding: 4px 6px !important; }
      .col-action { display: none !important; }
      .print-meta { font-size: 10px; color: #666; text-align: right; margin-top: 12px; }
    }

    .date-calendar-field {
      position: relative;
    }

    .date-calendar-field input[data-calendar-text] {
      padding-right: 3rem;
    }

    .date-calendar-field input[data-calendar-hidden] {
      position: absolute;
      right: 0.75rem;
      bottom: 0.5rem;
      width: 1px;
      height: 1px;
      opacity: 0;
      pointer-events: none;
    }

    .date-calendar-trigger {
      position: absolute;
      right: 0.45rem;
      bottom: 0.42rem;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      width: 2.25rem;
      height: 2.25rem;
      border: 0;
      border-radius: 0.4rem;
      background: transparent;
      color: var(--muted, #6b7280);
      cursor: pointer;
    }

    .date-calendar-trigger:hover,
    .date-calendar-trigger:focus-visible {
      background: rgba(196, 164, 69, 0.16);
      color: var(--ink, #111827);
      outline: none;
    }
  </style>
</head>

<body class="librarian-themed">
  <div class="app-shell">
    <?php require_once __DIR__ . '/../includes/sidebar-librarian.php'; ?>
    <main class="main-content">

      <!-- Print-only header -->
      <div class="print-header" style="display:none;" id="print-header">
        <div class="print-header__title"><?= htmlspecialchars($library_name, ENT_QUOTES, 'UTF-8') ?></div>
        <div class="print-header__subtitle">Circulation Records Report</div>
      </div>
      <script>
        // Show print header only during print
        window.addEventListener('beforeprint', function() {
          document.getElementById('print-header').style.display = 'block';
        });
        window.addEventListener('afterprint', function() {
          document.getElementById('print-header').style.display = 'none';
        });
      </script>

      <div class="page-header no-print">
        <h1>Generate Records</h1>
        <p>View and print circulation records</p>
      </div>

      <!-- Filter Card -->
      <div class="records-filter-card no-print">
        <form method="GET" action="">
          <div class="records-filter-row">
            <div class="records-filter-group">
              <label for="filter-search">Search (Name / Book / Email)</label>
              <input
                class="field-input"
                type="text"
                id="filter-search"
                name="search"
                placeholder="e.g. Juan dela Cruz"
                value="<?= htmlspecialchars($filter_search, ENT_QUOTES, 'UTF-8') ?>"
              >
            </div>

            <div class="records-filter-group">
              <label for="filter-status">Status</label>
              <select class="field-select" id="filter-status" name="status">
                <option value="">All Statuses</option>
                <option value="active"   <?= $filter_status === 'active'   ? 'selected' : '' ?>>Active</option>
                <option value="overdue"  <?= $filter_status === 'overdue'  ? 'selected' : '' ?>>Overdue</option>
                <option value="returned" <?= $filter_status === 'returned' ? 'selected' : '' ?>>Returned</option>
                <option value="returned_late" <?= $filter_status === 'returned_late' ? 'selected' : '' ?>>Returned Late</option>
              </select>
            </div>

            <div class="records-filter-group">
              <label for="filter-report-type">Report Type</label>
              <select class="field-select" id="filter-report-type" name="report_type">
                <?php foreach (circulation_report_type_options() as $type_value => $type_label): ?>
                  <option value="<?= htmlspecialchars($type_value, ENT_QUOTES, 'UTF-8') ?>" <?= $filter_report_type === $type_value ? 'selected' : '' ?>>
                    <?= htmlspecialchars($type_label, ENT_QUOTES, 'UTF-8') ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="records-filter-group">
              <label for="filter-date-from">Checkout From</label>
              <div class="date-calendar-field" data-calendar-field>
                <input
                  class="field-input"
                  type="text"
                  id="filter-date-from"
                  name="date_from"
                  inputmode="numeric"
                  maxlength="8"
                  pattern="\d{2}-\d{2}-\d{2}"
                  placeholder="mm-dd-yy"
                  value="<?= htmlspecialchars($filter_date_from, ENT_QUOTES, 'UTF-8') ?>"
                  data-calendar-text
                >
                <input type="date" tabindex="-1" aria-hidden="true" data-calendar-hidden>
                <button type="button" class="date-calendar-trigger" data-calendar-trigger aria-label="Open Checkout From calendar">
                  <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="3" y="4" width="18" height="18" rx="2"></rect><path d="M16 2v4M8 2v4M3 10h18"></path></svg>
                </button>
              </div>
            </div>

            <div class="records-filter-group">
              <label for="filter-date-to">Checkout To</label>
              <div class="date-calendar-field" data-calendar-field>
                <input
                  class="field-input"
                  type="text"
                  id="filter-date-to"
                  name="date_to"
                  inputmode="numeric"
                  maxlength="8"
                  pattern="\d{2}-\d{2}-\d{2}"
                  placeholder="mm-dd-yy"
                  value="<?= htmlspecialchars($filter_date_to, ENT_QUOTES, 'UTF-8') ?>"
                  data-calendar-text
                >
                <input type="date" tabindex="-1" aria-hidden="true" data-calendar-hidden>
                <button type="button" class="date-calendar-trigger" data-calendar-trigger aria-label="Open Checkout To calendar">
                  <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="3" y="4" width="18" height="18" rx="2"></rect><path d="M16 2v4M8 2v4M3 10h18"></path></svg>
                </button>
              </div>
            </div>

            <div style="display:flex;gap:var(--space-2);align-items:flex-end;flex-shrink:0;">
              <button type="submit" class="btn-primary">Apply</button>
              <a href="<?= htmlspecialchars(BASE_URL . 'librarian/records.php', ENT_QUOTES, 'UTF-8') ?>" class="btn-ghost">Reset</a>
            </div>
          </div>
          <div class="records-filter-chips" aria-label="Active report filters">
            <?php foreach ($active_filter_chips as $chip): ?>
              <span class="records-filter-chip"><?= htmlspecialchars($chip, ENT_QUOTES, 'UTF-8') ?></span>
            <?php endforeach; ?>
          </div>
        </form>
      </div>

      <!-- Summary stat chips -->
      <div class="records-stat-row no-print">
        <div class="records-stat-chip">
          <span class="records-stat-chip__value"><?= number_format($summary['total_records']) ?></span>
          <span class="records-stat-chip__label">Total</span>
        </div>
        <div class="records-stat-chip records-stat-chip--active">
          <span class="records-stat-chip__value"><?= number_format($summary['active']) ?></span>
          <span class="records-stat-chip__label">Active</span>
        </div>
        <div class="records-stat-chip records-stat-chip--overdue">
          <span class="records-stat-chip__value"><?= number_format($summary['overdue']) ?></span>
          <span class="records-stat-chip__label">Overdue</span>
        </div>
        <div class="records-stat-chip records-stat-chip--returned">
          <span class="records-stat-chip__value"><?= number_format($summary['returned'] + $summary['returned_late']) ?></span>
          <span class="records-stat-chip__label">Returned</span>
        </div>
        <div class="records-stat-chip records-stat-chip--fines">
          <span class="records-stat-chip__value">&#8369;<?= number_format($summary['total_fines'], 2) ?></span>
          <span class="records-stat-chip__label">Total Fines</span>
        </div>
        <div class="records-stat-chip records-stat-chip--collected">
          <span class="records-stat-chip__value">&#8369;<?= number_format($summary['collected_fines'], 2) ?></span>
          <span class="records-stat-chip__label">Collected Fines</span>
        </div>
        <div class="records-stat-chip records-stat-chip--unpaid">
          <span class="records-stat-chip__value">&#8369;<?= number_format($summary['unpaid_fines'], 2) ?></span>
          <span class="records-stat-chip__label">Unpaid Fines</span>
        </div>
      </div>

      <!-- Table actions bar -->
      <div class="records-actions-bar no-print">
        <span class="records-count-label">
          Showing <strong><?= number_format($page_start) ?>-<?= number_format($page_end) ?></strong>
          of <strong><?= number_format($total_records) ?></strong> record<?= $total_records !== 1 ? 's' : '' ?>
          <?php if ($filter_status !== '' || $filter_search !== '' || $filter_date_from !== '' || $filter_date_to !== '' || $filter_report_type !== 'circulation_records'): ?>
            <em>(filtered)</em>
          <?php endif; ?>
        </span>
        <?php
          $print_params = http_build_query(array_filter([
            'status'    => $filter_status,
            'search'    => $filter_search,
            'date_from' => $filter_date_from,
            'date_to'   => $filter_date_to,
            'report_type' => $filter_report_type,
          ]));
          $preview_url = htmlspecialchars(
            BASE_URL . 'librarian/records-print.php' . ($print_params ? '?' . $print_params : ''),
            ENT_QUOTES, 'UTF-8'
          );
          $pdf_url = htmlspecialchars(
            BASE_URL . 'librarian/records-print.php' . ($print_params ? '?' . $print_params . '&action=pdf' : '?action=pdf'),
            ENT_QUOTES, 'UTF-8'
          );
          $print_url = htmlspecialchars(
            BASE_URL . 'librarian/records-print.php' . ($print_params ? '?' . $print_params . '&action=print' : '?action=print'),
            ENT_QUOTES, 'UTF-8'
          );
        ?>
        <div class="records-report-actions">
          <a class="btn-ghost" href="<?= $preview_url ?>" target="_blank" rel="noopener noreferrer">Preview Report</a>
          <a class="btn-primary" href="<?= $pdf_url ?>" target="_blank" rel="noopener noreferrer">Export PDF</a>
          <a class="btn-primary" href="<?= $print_url ?>" target="_blank" rel="noopener noreferrer">Print Filtered Report</a>
        </div>
      </div>

      <!-- Records Table -->
      <div class="section-card">
        <div class="records-table-wrap">
          <?php if (empty($paged_records)): ?>
            <div class="records-empty-state">
              No circulation records found for the selected filters. Try adjusting the status or date range.
            </div>
          <?php else: ?>
            <table class="records-table" aria-label="Circulation records">
              <thead>
                <tr>
                  <th>#</th>
                  <th>Borrower</th>
                  <th>Book Title</th>
                  <th>Author</th>
                  <th>ISBN</th>
                  <th>Checkout Date</th>
                  <th>Due Date</th>
                  <th>Return Date</th>
                  <th>Status</th>
                  <th>Fine (&#8369;)</th>
                  <th>Fine Paid</th>
                  <th class="col-action no-print">Action</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($paged_records as $r): ?>
                  <?php
                    $status_label = (string) $r['status_label'];
                    $badge_class  = 'badge--' . strtolower((string) $r['effective_status']);
                    $fine_amt     = (float) ($r['calculated_fine'] ?? 0);
                    $fine_paid    = (int) ($r['fine_paid'] ?? 0) === 1 ? 'Yes' : 'No';
                    $checkout_fmt = !empty($r['checkout_date']) ? date('m-d-y', strtotime((string) $r['checkout_date'])) : '—';
                    $due_fmt      = !empty($r['due_date'])      ? date('m-d-y', strtotime((string) $r['due_date']))      : '—';
                    $return_fmt   = !empty($r['return_date'])   ? date('m-d-y', strtotime((string) $r['return_date']))   : '—';
                    $print_url    = htmlspecialchars(
                        BASE_URL . 'librarian/print-record.php?loan_id=' . (int) $r['id'] . '&type=record',
                        ENT_QUOTES, 'UTF-8'
                    );
                  ?>
                  <tr>
                    <td><?= (int) $r['id'] ?></td>
                    <td>
                      <div><?= htmlspecialchars((string) $r['borrower_name'], ENT_QUOTES, 'UTF-8') ?></div>
                      <div style="font-size:var(--text-xs);color:var(--muted);"><?= htmlspecialchars((string) ($r['borrower_email'] ?? ''), ENT_QUOTES, 'UTF-8') ?></div>
                    </td>
                    <td><?= htmlspecialchars((string) $r['book_title'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars((string) ($r['book_author'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars((string) ($r['book_isbn'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars($checkout_fmt, ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars($due_fmt, ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars($return_fmt, ENT_QUOTES, 'UTF-8') ?></td>
                    <td><span class="badge <?= $badge_class ?>"><?= htmlspecialchars($status_label, ENT_QUOTES, 'UTF-8') ?></span></td>
                    <td><?= htmlspecialchars(number_format($fine_amt, 2), ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars($fine_paid, ENT_QUOTES, 'UTF-8') ?></td>
                    <td class="col-action no-print">
                      <a class="btn-sm" href="<?= $print_url ?>" target="_blank" rel="noopener noreferrer">
                        Print Record
                      </a>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          <?php endif; ?>
        </div>
        <div class="records-pagination no-print">
          <form method="GET" class="records-per-page-form">
            <input type="hidden" name="search" value="<?= htmlspecialchars($filter_search, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="status" value="<?= htmlspecialchars($filter_status, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="date_from" value="<?= htmlspecialchars($filter_date_from, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="date_to" value="<?= htmlspecialchars($filter_date_to, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="report_type" value="<?= htmlspecialchars($filter_report_type, ENT_QUOTES, 'UTF-8') ?>">
            <label for="per-page">Rows per page</label>
            <select class="field-select" id="per-page" name="per_page" onchange="this.form.submit()">
              <?php foreach ([10, 25, 50] as $size): ?>
                <option value="<?= $size ?>" <?= $rows_per_page === $size ? 'selected' : '' ?>><?= $size ?></option>
              <?php endforeach; ?>
            </select>
          </form>
          <div class="records-pagination__nav">
            <?php
              $page_params = [
                'search' => $filter_search,
                'status' => $filter_status,
                'date_from' => $filter_date_from,
                'date_to' => $filter_date_to,
                'report_type' => $filter_report_type,
                'per_page' => $rows_per_page,
              ];
              $prev_url = BASE_URL . 'librarian/records.php?' . http_build_query(array_filter(array_merge($page_params, ['page' => $current_report_page - 1]), fn($v) => $v !== ''));
              $next_url = BASE_URL . 'librarian/records.php?' . http_build_query(array_filter(array_merge($page_params, ['page' => $current_report_page + 1]), fn($v) => $v !== ''));
            ?>
            <a class="btn-ghost <?= $current_report_page <= 1 ? 'records-pagination__disabled' : '' ?>" href="<?= htmlspecialchars($prev_url, ENT_QUOTES, 'UTF-8') ?>">Previous</a>
            <span class="records-pagination__page">Page <?= number_format($current_report_page) ?> of <?= number_format($total_pages) ?></span>
            <a class="btn-ghost <?= $current_report_page >= $total_pages ? 'records-pagination__disabled' : '' ?>" href="<?= htmlspecialchars($next_url, ENT_QUOTES, 'UTF-8') ?>">Next</a>
          </div>
        </div>
      </div>

    </main>
  </div>
</body>

</html>
