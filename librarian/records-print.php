<?php

/**
 * librarian/records-print.php — Printable Circulation Records Form
 *
 * GET: Render a clean, print-friendly page of circulation records.
 *      Supports the same filters as records.php:
 *        ?status=      active|overdue|returned (optional)
 *        ?search=      borrower name / book / email (optional)
 *        ?date_from=   YYYY-MM-DD (optional)
 *        ?date_to=     YYYY-MM-DD (optional)
 *
 * Protected: Librarian role only.
 */

$allowed_roles = ['librarian'];
require_once __DIR__ . '/../includes/auth_guard.php';
require_once __DIR__ . '/../includes/circulation.php';

$pdo = get_db();

// ---------------------------------------------------------------------------
// Filter inputs (same as records.php)
// ---------------------------------------------------------------------------
$filter_status    = trim((string) ($_GET['status']    ?? ''));
$filter_search    = trim((string) ($_GET['search']    ?? ''));
$filter_date_from = trim((string) ($_GET['date_from'] ?? ''));
$filter_date_to   = trim((string) ($_GET['date_to']   ?? ''));

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

$stmt = $pdo->prepare(
  "SELECT
        c.id,
        c.status,
        c.checkout_date,
        c.due_date,
        c.return_date,
        c.fine_amount,
        c.fine_paid,
        u.full_name  AS borrower_name,
        u.email      AS borrower_email,
        b.title      AS book_title,
        b.author     AS book_author,
        b.isbn       AS book_isbn
     FROM Circulation c
     JOIN Users u ON u.id = c.user_id
     JOIN Books b ON b.id = c.book_id
     {$where_sql}
     ORDER BY c.checkout_date DESC
     LIMIT 500"
);
$stmt->execute($params);
$records = $stmt->fetchAll();

// ---------------------------------------------------------------------------
// Totals
// ---------------------------------------------------------------------------
$total   = count($records);
$active  = 0;
$overdue = 0;
$returned = 0;
$total_fines = 0.0;
$total_fines_paid = 0.0;

foreach ($records as $r) {
  if ($r['status'] === 'active')   $active++;
  if ($r['status'] === 'overdue')  $overdue++;
  if ($r['status'] === 'returned') $returned++;
  $total_fines += (float) ($r['fine_amount'] ?? 0);
  if ((int) ($r['fine_paid'] ?? 0) === 1) {
    $total_fines_paid += (float) ($r['fine_amount'] ?? 0);
  }
}

$library_name = get_setting($pdo, 'library_name', 'Library System');
$prepared_by  = (string) ($_SESSION['full_name'] ?? 'Librarian');
$generated_at = date('M d, Y g:i A');

// Build a human-readable filter description
$filter_desc_parts = [];
if ($filter_status !== '') $filter_desc_parts[] = 'Status: ' . ucfirst($filter_status);
if ($filter_search !== '') $filter_desc_parts[] = 'Search: "' . htmlspecialchars($filter_search, ENT_QUOTES, 'UTF-8') . '"';
if ($filter_date_from !== '') $filter_desc_parts[] = 'From: ' . date('M d, Y', strtotime($filter_date_from));
if ($filter_date_to   !== '') $filter_desc_parts[] = 'To: '   . date('M d, Y', strtotime($filter_date_to));
$filter_desc = count($filter_desc_parts) > 0 ? implode(' &nbsp;|&nbsp; ', $filter_desc_parts) : 'All records';

// Build back URL
$back_params = http_build_query(array_filter([
  'status'    => $filter_status,
  'search'    => $filter_search,
  'date_from' => $filter_date_from,
  'date_to'   => $filter_date_to,
]));
$back_url = htmlspecialchars(
  BASE_URL . 'librarian/records.php' . ($back_params ? '?' . $back_params : ''),
  ENT_QUOTES,
  'UTF-8'
);
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Circulation Records Printable Form | <?= htmlspecialchars($library_name, ENT_QUOTES, 'UTF-8') ?></title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="<?= htmlspecialchars(BASE_URL . 'assets/css/borrower-redesign.css', ENT_QUOTES, 'UTF-8') ?>">
  <link rel="stylesheet" href="<?= htmlspecialchars(BASE_URL . 'assets/css/librarian-redesign.css', ENT_QUOTES, 'UTF-8') ?>">
  <style>
    /* ── Page: dark theme for toolbar area ── */
    body {
      font-family: 'Outfit', system-ui, sans-serif;
      background: var(--rd-bg);
      color: var(--rd-text);
      padding: 32px 24px;
      font-size: 14px;
      margin: 0;
      min-height: 100vh;
    }

    /* ── Wrapper: white paper inside the dark page ── */
    .print-page {
      max-width: 960px;
      margin: 0 auto;
      background: #fff;
      border: 1px solid var(--rd-border);
      border-radius: var(--rd-radius);
      box-shadow: var(--rd-shadow);
      padding: 48px 56px;
      color: #1a1a1a;
    }

    /* ── No-print toolbar ── */
    .toolbar {
      max-width: 960px;
      margin: 0 auto 20px;
      display: flex;
      justify-content: space-between;
      align-items: center;
      gap: 12px;
      flex-wrap: wrap;
    }

    .toolbar .btn-back {
      background: rgba(255,255,255,0.05);
      border: 1px solid var(--rd-border);
      color: var(--rd-text);
      border-radius: 12px;
      padding: 0.65rem 1.25rem;
      font-size: 0.9rem;
      font-weight: 600;
      cursor: pointer;
      text-decoration: none;
      transition: all 0.3s;
    }

    .toolbar .btn-back:hover {
      background: rgba(255,255,255,0.1);
    }

    .toolbar .btn-print {
      background: linear-gradient(135deg, var(--rd-primary), #b8942d);
      color: #1c1a17;
      border: none;
      border-radius: 12px;
      padding: 0.65rem 1.5rem;
      font-size: 0.9rem;
      font-weight: 600;
      cursor: pointer;
      transition: all 0.3s;
    }

    .toolbar .btn-print:hover {
      transform: translateY(-2px);
      box-shadow: 0 8px 16px rgba(201,168,76,0.25);
    }

    /* ── Paper content: serif elegance ── */
    .paper-text {
      font-family: Georgia, 'Times New Roman', serif;
    }

    .form-header {
      border-bottom: 2px solid #1a1a1a;
      padding-bottom: 20px;
      margin-bottom: 28px;
    }

    .form-header__library {
      font-size: 13px;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: .1em;
      color: #666;
      margin-bottom: 6px;
      font-family: 'Outfit', system-ui, sans-serif;
    }

    .form-header__title {
      font-size: 28px;
      font-weight: 700;
      color: #1a1a1a;
      line-height: 1.2;
      font-family: 'Outfit', system-ui, sans-serif;
    }

    .form-header__subtitle {
      margin-top: 6px;
      font-size: 13px;
      color: #666;
      font-style: italic;
    }

    .form-meta {
      display: flex;
      justify-content: space-between;
      flex-wrap: wrap;
      gap: 8px;
      margin-top: 16px;
      font-size: 12px;
      color: #555;
    }

    .form-meta strong { color: #1a1a1a; }

    .filter-row {
      background: #f9f6f2;
      border: 1px solid #e8e2da;
      border-radius: 6px;
      padding: 10px 16px;
      margin-bottom: 24px;
      font-size: 12px;
      color: #555;
      font-family: 'Outfit', system-ui, sans-serif;
    }

    .filter-row strong { color: #1a1a1a; }

    .totals-section { margin-bottom: 28px; }

    .totals-section h2 {
      font-size: 16px;
      font-weight: 700;
      margin-bottom: 12px;
      font-family: 'Outfit', system-ui, sans-serif;
    }

    .totals-grid {
      display: grid;
      grid-template-columns: repeat(5, 1fr);
      gap: 10px;
    }

    .total-chip {
      border: 1px solid #ddd;
      border-radius: 6px;
      padding: 12px 14px;
      font-family: 'Outfit', system-ui, sans-serif;
    }

    .total-chip__value {
      font-size: 22px;
      font-weight: 700;
      color: #1a1a1a;
      line-height: 1;
    }

    .total-chip__label {
      font-size: 11px;
      color: #888;
      text-transform: uppercase;
      letter-spacing: .05em;
      margin-top: 4px;
    }

    .total-chip--active  { border-left: 4px solid #3b82f6; }
    .total-chip--overdue  { border-left: 4px solid #ef4444; }
    .total-chip--returned { border-left: 4px solid #22c55e; }
    .total-chip--fines    { border-left: 4px solid #f59e0b; }

    .records-section h2 {
      font-size: 16px;
      font-weight: 700;
      margin-bottom: 14px;
      font-family: 'Outfit', system-ui, sans-serif;
    }

    table {
      width: 100%;
      border-collapse: collapse;
      font-size: 12px;
      font-family: 'Outfit', system-ui, sans-serif;
    }

    thead th {
      background: #f5f0eb;
      text-align: left;
      padding: 8px 10px;
      font-size: 11px;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: .05em;
      color: #666;
      border-top: 1px solid #ddd;
      border-bottom: 2px solid #ccc;
    }

    tbody td {
      padding: 9px 10px;
      border-bottom: 1px solid #eee;
      vertical-align: top;
      color: #1a1a1a;
    }

    tbody tr:last-child td { border-bottom: none; }

    .status-badge {
      display: inline-block;
      padding: 2px 8px;
      border-radius: 999px;
      font-size: 10px;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: .04em;
    }

    .status-badge--active   { background: #dbeafe; color: #1d4ed8; }
    .status-badge--overdue  { background: #fee2e2; color: #b91c1c; }
    .status-badge--returned { background: #dcfce7; color: #15803d; }

    .borrower-email { font-size: 10px; color: #999; }

    .signature-section {
      margin-top: 48px;
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 60px;
    }

    .sig-line {
      border-top: 1px solid #1a1a1a;
      padding-top: 6px;
      text-align: center;
      font-size: 11px;
      color: #555;
      font-family: 'Outfit', system-ui, sans-serif;
    }

    .form-footer {
      margin-top: 32px;
      font-size: 11px;
      color: #aaa;
      text-align: right;
      font-family: 'Outfit', system-ui, sans-serif;
      border-top: 1px solid #eee;
      padding-top: 12px;
    }

    .empty-state {
      padding: 40px;
      text-align: center;
      color: #999;
      border: 1px dashed #ddd;
      border-radius: 6px;
    }

    @page { size: 8.5in 13in; margin: 0.5in; }

    @media print {
      body {
        background: #fff !important;
        padding: 0 !important;
      }

      .toolbar { display: none !important; }

      .print-page {
        border: none !important;
        border-radius: 0 !important;
        padding: 0 !important;
        box-shadow: none !important;
        max-width: none !important;
      }
    }
  </style>
</head>

<body>
  <!-- Toolbar (hidden on print) -->
  <div class="toolbar">
    <a class="btn-back" href="<?= $back_url ?>">&#8592; Back to Records</a>
    <button class="btn-print" type="button" onclick="window.print()">&#128438;&nbsp; Print / Save as PDF</button>
  </div>

  <div class="print-page">

    <!-- Header -->
    <div class="form-header">
      <div class="form-header__library"><?= htmlspecialchars($library_name, ENT_QUOTES, 'UTF-8') ?></div>
      <div class="form-header__title">Circulation Records Printable Form</div>
      <div class="form-header__subtitle">Official record of library book loans</div>
      <div class="form-meta">
        <span><strong>Generated:</strong> <?= htmlspecialchars($generated_at, ENT_QUOTES, 'UTF-8') ?></span>
        <span><strong>Prepared by:</strong> <?= htmlspecialchars($prepared_by, ENT_QUOTES, 'UTF-8') ?></span>
        <span><strong>Type:</strong> circulation</span>
      </div>
    </div>

    <!-- Active filters indicator -->
    <div class="filter-row">
      <strong>Filters applied:</strong>&nbsp; <?= $filter_desc ?>
    </div>

    <!-- Totals -->
    <div class="totals-section">
      <h2>Totals</h2>
      <div class="totals-grid">
        <div class="total-chip">
          <div class="total-chip__value"><?= number_format($total) ?></div>
          <div class="total-chip__label">Total Records</div>
        </div>
        <div class="total-chip total-chip--active">
          <div class="total-chip__value"><?= number_format($active) ?></div>
          <div class="total-chip__label">Active</div>
        </div>
        <div class="total-chip total-chip--overdue">
          <div class="total-chip__value"><?= number_format($overdue) ?></div>
          <div class="total-chip__label">Overdue</div>
        </div>
        <div class="total-chip total-chip--returned">
          <div class="total-chip__value"><?= number_format($returned) ?></div>
          <div class="total-chip__label">Returned</div>
        </div>
        <div class="total-chip total-chip--fines">
          <div class="total-chip__value">&#8369;<?= number_format($total_fines_paid, 2) ?></div>
          <div class="total-chip__label">Fines Collected</div>
        </div>
      </div>
    </div>

    <!-- Records table -->
    <div class="records-section">
      <h2>Records</h2>
      <?php if (empty($records)): ?>
        <div class="empty-state">No circulation records found for the selected filters.</div>
      <?php else: ?>
        <table>
          <thead>
            <tr>
              <th>Record #</th>
              <th>Borrower</th>
              <th>Book</th>
              <th>Author</th>
              <th>Checkout Date</th>
              <th>Due Date</th>
              <th>Return Date</th>
              <th>Status</th>
              <th>Fine (&#8369;)</th>
              <th>Paid</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($records as $r): ?>
              <?php
              $badge   = 'status-badge--' . strtolower((string) $r['status']);
              $status  = ucfirst((string) $r['status']);
              $fine    = (float) ($r['fine_amount'] ?? 0);
              $paid    = (int) ($r['fine_paid'] ?? 0) === 1 ? 'Yes' : 'No';
              $co_date = !empty($r['checkout_date']) ? date('M d, Y', strtotime((string) $r['checkout_date'])) : '—';
              $due_d   = !empty($r['due_date'])      ? date('M d, Y', strtotime((string) $r['due_date']))      : '—';
              $ret_d   = !empty($r['return_date'])   ? date('M d, Y', strtotime((string) $r['return_date']))   : '—';
              ?>
              <tr>
                <td>#<?= (int) $r['id'] ?></td>
                <td>
                  <?= htmlspecialchars((string) $r['borrower_name'], ENT_QUOTES, 'UTF-8') ?>
                  <?php if (!empty($r['borrower_email'])): ?>
                    <br><span class="borrower-email"><?= htmlspecialchars((string) $r['borrower_email'], ENT_QUOTES, 'UTF-8') ?></span>
                  <?php endif; ?>
                </td>
                <td><?= htmlspecialchars((string) $r['book_title'], ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= htmlspecialchars((string) ($r['book_author'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= htmlspecialchars($co_date, ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= htmlspecialchars($due_d,   ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= htmlspecialchars($ret_d,   ENT_QUOTES, 'UTF-8') ?></td>
                <td><span class="status-badge <?= $badge ?>"><?= htmlspecialchars($status, ENT_QUOTES, 'UTF-8') ?></span></td>
                <td><?= number_format($fine, 2) ?></td>
                <td><?= htmlspecialchars($paid, ENT_QUOTES, 'UTF-8') ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div>

    <!-- Signature lines -->
    <div class="signature-section">
      <div class="sig-line">Librarian Signature Over Printed Name</div>
      <div class="sig-line">Noted by / Approved by</div>
    </div>

    <!-- Footer -->
    <div class="form-footer">
      <?= htmlspecialchars($library_name, ENT_QUOTES, 'UTF-8') ?> &nbsp;&mdash;&nbsp;
      Prepared by: <?= htmlspecialchars($prepared_by, ENT_QUOTES, 'UTF-8') ?> &nbsp;&mdash;&nbsp;
      Generated: <?= htmlspecialchars($generated_at, ENT_QUOTES, 'UTF-8') ?>
    </div>

  </div><!-- /.print-page -->

</body>

</html>