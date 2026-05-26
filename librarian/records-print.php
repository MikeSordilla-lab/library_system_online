<?php

/**
 * librarian/records-print.php — Printable Circulation Records Form
 *
 * GET: Render a clean, print-friendly page of circulation records.
 *      Supports the same filters as records.php:
 *        ?status=      active|overdue|returned (optional)
 *        ?search=      borrower name / book / email (optional)
 *        ?date_from=   MM-DD-YY or YYYY-MM-DD (optional)
 *        ?date_to=     MM-DD-YY or YYYY-MM-DD (optional)
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
$filter_report_type = circulation_normalize_report_type(trim((string) ($_GET['report_type'] ?? 'circulation_records')));
$report_action = trim((string) ($_GET['action'] ?? 'preview'));

function records_print_filter_date_to_sql(string $value): string
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

function records_print_filter_date_display(string $sql_date): string
{
  $date = records_print_filter_date_to_sql($sql_date);
  if ($date === '') {
    return '';
  }
  return DateTimeImmutable::createFromFormat('!Y-m-d', $date)->format('m-d-y');
}

$filter_date_from_sql = records_print_filter_date_to_sql($filter_date_from);
$filter_date_to_sql = records_print_filter_date_to_sql($filter_date_to);
$filter_date_from = records_print_filter_date_display($filter_date_from_sql);
$filter_date_to = records_print_filter_date_display($filter_date_to_sql);

$allowed_statuses = ['', 'active', 'overdue', 'returned', 'returned_late'];
if (!in_array($filter_status, $allowed_statuses, true)) {
  $filter_status = '';
}
if (!in_array($report_action, ['preview', 'print', 'pdf'], true)) {
  $report_action = 'preview';
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
     LIMIT 5000"
);
$stmt->execute($params);
$raw_records = $stmt->fetchAll();

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
// Totals
// ---------------------------------------------------------------------------
$summary = circulation_report_summary($records);

$library_name = get_setting($pdo, 'library_name', 'LibraLink');
$prepared_by  = (string) ($_SESSION['full_name'] ?? 'Librarian');
$prepared_role = (string) ($_SESSION['role'] ?? 'librarian');
$generated_at = date('m-d-y g:i A');
$report_type_label = circulation_report_type_label($filter_report_type);

// Build a human-readable filter description
$filter_desc_parts = [];
if ($filter_status !== '') $filter_desc_parts[] = 'Status: ' . circulation_status_label($filter_status);
if ($filter_search !== '') $filter_desc_parts[] = 'Search: "' . htmlspecialchars($filter_search, ENT_QUOTES, 'UTF-8') . '"';
if ($filter_date_from !== '') $filter_desc_parts[] = 'From: ' . $filter_date_from;
if ($filter_date_to   !== '') $filter_desc_parts[] = 'To: '   . $filter_date_to;
$filter_desc_parts[] = 'Report Type: ' . htmlspecialchars($report_type_label, ENT_QUOTES, 'UTF-8');
$filter_desc = count($filter_desc_parts) > 0 ? implode(' &nbsp;|&nbsp; ', $filter_desc_parts) : 'All records';

// Build back URL
$back_params = http_build_query(array_filter([
  'status'    => $filter_status,
  'search'    => $filter_search,
  'date_from' => $filter_date_from,
  'date_to'   => $filter_date_to,
  'report_type' => $filter_report_type,
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
      grid-template-columns: repeat(4, 1fr);
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
    .total-chip--collected { border-left: 4px solid #14b8a6; }
    .total-chip--unpaid { border-left: 4px solid #fb7185; }

    .audit-grid {
      display: grid;
      grid-template-columns: repeat(2, 1fr);
      gap: 8px 18px;
      background: #fbfaf8;
      border: 1px solid #e8e2da;
      border-radius: 6px;
      padding: 14px 16px;
      margin-bottom: 24px;
      font-size: 12px;
      font-family: 'Outfit', system-ui, sans-serif;
    }

    .audit-grid strong { color: #1a1a1a; }

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
    .status-badge--returned_late { background: #fef3c7; color: #92400e; }

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
      <div class="form-header__title"><?= htmlspecialchars($report_type_label, ENT_QUOTES, 'UTF-8') ?></div>
      <div class="form-header__subtitle">Official record of library book loans</div>
      <div class="form-meta">
        <span><strong>Generated:</strong> <?= htmlspecialchars($generated_at, ENT_QUOTES, 'UTF-8') ?></span>
        <span><strong>Prepared by:</strong> <?= htmlspecialchars($prepared_by, ENT_QUOTES, 'UTF-8') ?></span>
        <span><strong>Type:</strong> <?= htmlspecialchars($report_type_label, ENT_QUOTES, 'UTF-8') ?></span>
      </div>
    </div>

    <!-- Report audit trail -->
    <div class="audit-grid">
      <div><strong>Generated By:</strong> <?= htmlspecialchars($prepared_by, ENT_QUOTES, 'UTF-8') ?></div>
      <div><strong>Role:</strong> <?= htmlspecialchars(ucfirst($prepared_role), ENT_QUOTES, 'UTF-8') ?></div>
      <div><strong>Date and Time Generated:</strong> <?= htmlspecialchars($generated_at, ENT_QUOTES, 'UTF-8') ?></div>
      <div><strong>Report Type:</strong> <?= htmlspecialchars($report_type_label, ENT_QUOTES, 'UTF-8') ?></div>
      <div style="grid-column:1 / -1;"><strong>Filters Used:</strong> <?= $filter_desc ?></div>
    </div>

    <!-- Totals -->
    <div class="totals-section">
      <h2>Totals</h2>
      <div class="totals-grid">
        <div class="total-chip">
          <div class="total-chip__value"><?= number_format($summary['total_records']) ?></div>
          <div class="total-chip__label">Total Records</div>
        </div>
        <div class="total-chip total-chip--active">
          <div class="total-chip__value"><?= number_format($summary['active']) ?></div>
          <div class="total-chip__label">Active</div>
        </div>
        <div class="total-chip total-chip--overdue">
          <div class="total-chip__value"><?= number_format($summary['overdue']) ?></div>
          <div class="total-chip__label">Overdue</div>
        </div>
        <div class="total-chip total-chip--returned">
          <div class="total-chip__value"><?= number_format($summary['returned'] + $summary['returned_late']) ?></div>
          <div class="total-chip__label">Returned</div>
        </div>
        <div class="total-chip total-chip--fines">
          <div class="total-chip__value">&#8369;<?= number_format($summary['total_fines'], 2) ?></div>
          <div class="total-chip__label">Total Fines</div>
        </div>
        <div class="total-chip total-chip--collected">
          <div class="total-chip__value">&#8369;<?= number_format($summary['collected_fines'], 2) ?></div>
          <div class="total-chip__label">Collected Fines</div>
        </div>
        <div class="total-chip total-chip--unpaid">
          <div class="total-chip__value">&#8369;<?= number_format($summary['unpaid_fines'], 2) ?></div>
          <div class="total-chip__label">Unpaid Fines</div>
        </div>
      </div>
    </div>

    <!-- Records table -->
    <div class="records-section">
      <h2>Records</h2>
      <?php if (empty($records)): ?>
        <div class="empty-state">No circulation records found for the selected filters. Try adjusting the status or date range.</div>
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
              <th>Days Late</th>
              <th>Fine (&#8369;)</th>
              <th>Fine Breakdown</th>
              <th>Paid</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($records as $r): ?>
              <?php
              $badge   = 'status-badge--' . strtolower((string) $r['effective_status']);
              $status  = (string) $r['status_label'];
              $fine    = (float) ($r['calculated_fine'] ?? 0);
              $paid    = (int) ($r['fine_paid'] ?? 0) === 1 ? 'Yes' : 'No';
              $co_date = !empty($r['checkout_date']) ? date('m-d-y', strtotime((string) $r['checkout_date'])) : '—';
              $due_d   = !empty($r['due_date'])      ? date('m-d-y', strtotime((string) $r['due_date']))      : '—';
              $ret_d   = !empty($r['return_date'])   ? date('m-d-y', strtotime((string) $r['return_date']))   : '—';
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
                <td><?= number_format((int) ($r['days_late'] ?? 0)) ?></td>
                <td><?= number_format($fine, 2) ?></td>
                <td><?= htmlspecialchars((string) ($r['fine_breakdown'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
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

<?php if ($report_action === 'print' || $report_action === 'pdf'): ?>
<script>
  window.addEventListener('load', function() {
    window.print();
  });
</script>
<?php endif; ?>
</body>

</html>
