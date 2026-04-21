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

$allowed_statuses = ['', 'active', 'overdue', 'returned'];
if (!in_array($filter_status, $allowed_statuses, true)) {
    $filter_status = '';
}

// ---------------------------------------------------------------------------
// Build query
// ---------------------------------------------------------------------------
$where_parts = [];
$params = [];

if ($filter_status !== '') {
    $where_parts[] = 'c.status = ?';
    $params[] = $filter_status;
}

if ($filter_search !== '') {
    $where_parts[] = '(u.full_name LIKE ? OR b.title LIKE ? OR u.email LIKE ?)';
    $like = '%' . $filter_search . '%';
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}

if ($filter_date_from !== '') {
    $where_parts[] = 'DATE(c.checkout_date) >= ?';
    $params[] = $filter_date_from;
}

if ($filter_date_to !== '') {
    $where_parts[] = 'DATE(c.checkout_date) <= ?';
    $params[] = $filter_date_to;
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
     LIMIT 500"
);
$records_stmt->execute($params);
$records = $records_stmt->fetchAll();

// ---------------------------------------------------------------------------
// Summary stats (filtered)
// ---------------------------------------------------------------------------
$total_records  = count($records);
$total_active   = 0;
$total_overdue  = 0;
$total_returned = 0;
$total_fines    = 0.0;

foreach ($records as $r) {
    if ($r['status'] === 'active')   $total_active++;
    if ($r['status'] === 'overdue')  $total_overdue++;
    if ($r['status'] === 'returned') $total_returned++;
    $total_fines += (float) ($r['fine_amount'] ?? 0);
}

$library_name = get_setting($pdo, 'library_name', 'Library System');
$prepared_by  = (string) ($_SESSION['full_name'] ?? 'Librarian');
$generated_at = date('F d, Y h:i A');

$current_page = 'librarian.records';
$pageTitle    = 'Generate Records | Library System';
$includeSweetAlert = false;
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <?php require_once __DIR__ . '/../includes/head.php'; ?>
  <style>
    /* ---- Records page layout ---- */
    .records-filter-card {
      background: #fff;
      border: 1px solid var(--border);
      border-radius: var(--radius);
      padding: var(--space-5) var(--space-6);
      margin-bottom: var(--space-5);
    }

    .records-filter-row {
      display: flex;
      flex-wrap: wrap;
      gap: var(--space-3) var(--space-4);
      align-items: flex-end;
    }

    .records-filter-group {
      display: flex;
      flex-direction: column;
      gap: var(--space-1);
      flex: 1 1 160px;
      min-width: 140px;
    }

    .records-filter-group label {
      font-size: var(--text-xs);
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: .04em;
      color: var(--muted);
    }

    .records-stat-row {
      display: flex;
      flex-wrap: wrap;
      gap: var(--space-3);
      margin-bottom: var(--space-5);
    }

    .records-stat-chip {
      background: var(--surface);
      border: 1px solid var(--border);
      border-radius: var(--radius);
      padding: var(--space-3) var(--space-4);
      display: flex;
      flex-direction: column;
      align-items: center;
      min-width: 110px;
      flex: 1;
    }

    .records-stat-chip__value {
      font-size: var(--text-2xl);
      font-weight: 700;
      color: var(--ink);
      line-height: 1.1;
    }

    .records-stat-chip__label {
      font-size: var(--text-xs);
      color: var(--muted);
      text-transform: uppercase;
      letter-spacing: .04em;
      margin-top: var(--space-1);
    }

    .records-stat-chip--active   { border-left: 4px solid #3b82f6; }
    .records-stat-chip--overdue  { border-left: 4px solid #ef4444; }
    .records-stat-chip--returned { border-left: 4px solid #22c55e; }
    .records-stat-chip--fines    { border-left: 4px solid #f59e0b; }

    /* Table */
    .records-table-wrap {
      overflow-x: auto;
    }

    .records-table {
      width: 100%;
      border-collapse: collapse;
      font-size: var(--text-sm);
    }

    .records-table th {
      background: var(--surface);
      text-align: left;
      padding: var(--space-3) var(--space-4);
      font-size: var(--text-xs);
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: .04em;
      color: var(--muted);
      border-bottom: 2px solid var(--border);
      white-space: nowrap;
    }

    .records-table td {
      padding: var(--space-3) var(--space-4);
      border-bottom: 1px solid var(--border);
      color: var(--ink);
      vertical-align: middle;
    }

    .records-table tr:last-child td {
      border-bottom: 0;
    }

    .records-table tr:hover td {
      background: var(--paper);
    }

    /* Status badges */
    .badge {
      display: inline-block;
      padding: 2px 10px;
      border-radius: 999px;
      font-size: var(--text-xs);
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: .04em;
    }
    .badge--active   { background: #dbeafe; color: #1d4ed8; }
    .badge--overdue  { background: #fee2e2; color: #b91c1c; }
    .badge--returned { background: #dcfce7; color: #15803d; }

    .btn-sm {
      font-size: var(--text-xs);
      padding: var(--space-1) var(--space-3);
      border-radius: var(--radius-sm);
      border: 1px solid var(--border);
      background: #fff;
      color: var(--ink);
      cursor: pointer;
      text-decoration: none;
      white-space: nowrap;
    }
    .btn-sm:hover { background: var(--surface); }

    .records-actions-bar {
      display: flex;
      justify-content: space-between;
      align-items: center;
      flex-wrap: wrap;
      gap: var(--space-3);
      margin-bottom: var(--space-4);
    }

    .records-count-label {
      font-size: var(--text-sm);
      color: var(--muted);
    }

    /* ---- Print styles ---- */
    @page { size: 8.5in 13in; margin: 0.5in; }

    @media print {
      .no-print { display: none !important; }

      body { background: #fff !important; }

      .app-shell { display: block !important; }
      .sidebar   { display: none !important; }
      .main-content { margin: 0 !important; padding: 0 !important; }

      .print-header {
        text-align: center;
        margin-bottom: 16px;
        border-bottom: 2px solid #000;
        padding-bottom: 10px;
      }

      .print-header__title {
        font-size: 20px;
        font-weight: 700;
      }

      .print-header__subtitle {
        font-size: 12px;
        color: #555;
        margin-top: 4px;
      }

      .records-stat-row { display: none; }
      .records-filter-card { display: none; }
      .records-actions-bar { display: none; }
      .records-table th, .records-table td { font-size: 11px; padding: 4px 6px; }
      .print-meta { font-size: 10px; color: #666; text-align: right; margin-top: 12px; }

      /* Hide the print button column header and cells */
      .col-action { display: none; }
    }
  </style>
</head>

<body>
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
              </select>
            </div>

            <div class="records-filter-group">
              <label for="filter-date-from">Checkout From</label>
              <input
                class="field-input"
                type="date"
                id="filter-date-from"
                name="date_from"
                value="<?= htmlspecialchars($filter_date_from, ENT_QUOTES, 'UTF-8') ?>"
              >
            </div>

            <div class="records-filter-group">
              <label for="filter-date-to">Checkout To</label>
              <input
                class="field-input"
                type="date"
                id="filter-date-to"
                name="date_to"
                value="<?= htmlspecialchars($filter_date_to, ENT_QUOTES, 'UTF-8') ?>"
              >
            </div>

            <div style="display:flex;gap:var(--space-2);align-items:flex-end;flex-shrink:0;">
              <button type="submit" class="btn-primary">Apply</button>
              <a href="<?= htmlspecialchars(BASE_URL . 'librarian/records.php', ENT_QUOTES, 'UTF-8') ?>" class="btn-ghost">Reset</a>
            </div>
          </div>
        </form>
      </div>

      <!-- Summary stat chips -->
      <div class="records-stat-row no-print">
        <div class="records-stat-chip">
          <span class="records-stat-chip__value"><?= number_format($total_records) ?></span>
          <span class="records-stat-chip__label">Total</span>
        </div>
        <div class="records-stat-chip records-stat-chip--active">
          <span class="records-stat-chip__value"><?= number_format($total_active) ?></span>
          <span class="records-stat-chip__label">Active</span>
        </div>
        <div class="records-stat-chip records-stat-chip--overdue">
          <span class="records-stat-chip__value"><?= number_format($total_overdue) ?></span>
          <span class="records-stat-chip__label">Overdue</span>
        </div>
        <div class="records-stat-chip records-stat-chip--returned">
          <span class="records-stat-chip__value"><?= number_format($total_returned) ?></span>
          <span class="records-stat-chip__label">Returned</span>
        </div>
        <div class="records-stat-chip records-stat-chip--fines">
          <span class="records-stat-chip__value">&#8369;<?= number_format($total_fines, 2) ?></span>
          <span class="records-stat-chip__label">Total Fines</span>
        </div>
      </div>

      <!-- Table actions bar -->
      <div class="records-actions-bar no-print">
        <span class="records-count-label">
          Showing <strong><?= number_format($total_records) ?></strong> record<?= $total_records !== 1 ? 's' : '' ?>
          <?php if ($filter_status !== '' || $filter_search !== '' || $filter_date_from !== '' || $filter_date_to !== ''): ?>
            <em>(filtered)</em>
          <?php endif; ?>
        </span>
        <?php
          $print_params = http_build_query(array_filter([
            'status'    => $filter_status,
            'search'    => $filter_search,
            'date_from' => $filter_date_from,
            'date_to'   => $filter_date_to,
          ]));
          $print_url = htmlspecialchars(
            BASE_URL . 'librarian/records-print.php' . ($print_params ? '?' . $print_params : ''),
            ENT_QUOTES, 'UTF-8'
          );
        ?>
        <a class="btn-primary" href="<?= $print_url ?>" target="_blank" rel="noopener noreferrer">
          &#128438; Generate Printable Form
        </a>
      </div>

      <!-- Records Table -->
      <div class="section-card">
        <div class="records-table-wrap">
          <?php if (empty($records)): ?>
            <div style="padding: var(--space-8); text-align:center; color: var(--muted);">
              No circulation records found<?= ($filter_status !== '' || $filter_search !== '' || $filter_date_from !== '' || $filter_date_to !== '') ? ' matching your filters.' : '.' ?>
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
                <?php foreach ($records as $r): ?>
                  <?php
                    $status_label = ucfirst((string) $r['status']);
                    $badge_class  = 'badge--' . strtolower((string) $r['status']);
                    $fine_amt     = (float) ($r['fine_amount'] ?? 0);
                    $fine_paid    = (int) ($r['fine_paid'] ?? 0) === 1 ? 'Yes' : 'No';
                    $checkout_fmt = !empty($r['checkout_date']) ? date('M d, Y', strtotime((string) $r['checkout_date'])) : '—';
                    $due_fmt      = !empty($r['due_date'])      ? date('M d, Y', strtotime((string) $r['due_date']))      : '—';
                    $return_fmt   = !empty($r['return_date'])   ? date('M d, Y', strtotime((string) $r['return_date']))   : '—';
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
      </div>

    </main>
  </div>
</body>

</html>