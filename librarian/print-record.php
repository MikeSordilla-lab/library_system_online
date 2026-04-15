<?php

/**
 * librarian/print-record.php — Printable Circulation Record Form
 *
 * GET: Render a print-friendly form for a single circulation record.
 * Protected: Librarian role only.
 */

$allowed_roles = ['librarian'];
require_once __DIR__ . '/../includes/auth_guard.php';
require_once __DIR__ . '/../includes/circulation.php';

$pdo = get_db();

$loan_id = filter_input(
  INPUT_GET,
  'loan_id',
  FILTER_VALIDATE_INT,
  ['options' => ['min_range' => 1]]
);
$loan_id = $loan_id !== false && $loan_id !== null ? (int) $loan_id : 0;

$type_raw = strtolower(trim((string) ($_GET['type'] ?? 'record')));
$record_type = in_array($type_raw, ['checkout', 'checkin', 'record'], true) ? $type_raw : 'record';
$autoprint = isset($_GET['autoprint']) && $_GET['autoprint'] === '1';

$back_path = 'librarian/index.php';
if ($record_type === 'checkout') {
  $back_path = 'librarian/checkout.php';
} elseif ($record_type === 'checkin') {
  $back_path = 'librarian/checkin.php';
}

$record = null;
if ($loan_id > 0) {
  $stmt = $pdo->prepare(
    'SELECT c.id, c.user_id, c.book_id, c.checkout_date, c.due_date, c.return_date, c.status, c.fine_amount, c.fine_paid,
            u.full_name AS borrower_name, u.email AS borrower_email,
            b.title AS book_title, b.author AS book_author, b.isbn AS book_isbn, b.category AS book_category
       FROM Circulation c
       JOIN Users u ON u.id = c.user_id
       JOIN Books b ON b.id = c.book_id
      WHERE c.id = ?
      LIMIT 1'
  );
  $stmt->execute([$loan_id]);
  $record = $stmt->fetch();
}

if ($record) {
  try {
    log_circulation($pdo, [
      'actor_id'      => (int) $_SESSION['user_id'],
      'actor_role'    => (string) $_SESSION['role'],
      'action_type'   => 'print_record',
      'target_entity' => 'Circulation',
      'target_id'     => (int) $record['id'],
      'outcome'       => 'success',
    ]);
  } catch (Throwable $e) {
    error_log('[print-record.php] Failed to write print log: ' . $e->getMessage());
  }
}

$library_name = get_setting($pdo, 'library_name', 'Library System');
$prepared_by = (string) ($_SESSION['full_name'] ?? 'Librarian');
$base_url = defined('BASE_URL') ? (string) constant('BASE_URL') : '/';

$pageTitle = 'Printable Record | Library System';
$includeSweetAlert = false;
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <?php require_once __DIR__ . '/../includes/head.php'; ?>
  <style>
    @page {
      size: 8.5in 13in;
      margin: 0.5in;
    }

    .print-record-page {
      background: var(--paper);
      padding: var(--space-6);
    }

    .print-sheet {
      max-width: 900px;
      margin: 0 auto;
      background: #fff;
      border: 1px solid var(--border);
      border-radius: var(--radius);
      box-shadow: var(--shadow-sm);
      padding: var(--space-8);
    }

    .print-sheet__header {
      border-bottom: 2px solid var(--ink);
      padding-bottom: var(--space-4);
      margin-bottom: var(--space-6);
      text-align: center;
    }

    .print-sheet__library {
      font-family: var(--font-serif);
      font-size: var(--text-2xl);
      font-weight: 700;
      color: var(--ink);
    }

    .print-sheet__title {
      margin-top: var(--space-2);
      font-size: var(--text-sm);
      letter-spacing: .08em;
      text-transform: uppercase;
      color: var(--muted);
      font-weight: 700;
    }

    .print-grid {
      display: grid;
      grid-template-columns: repeat(2, minmax(0, 1fr));
      gap: var(--space-4) var(--space-6);
      margin-bottom: var(--space-6);
    }

    .print-field {
      border: 1px solid var(--border);
      border-radius: var(--radius-sm);
      padding: var(--space-3) var(--space-4);
      background: #fff;
    }

    .print-field__label {
      display: block;
      font-size: var(--text-xs);
      font-weight: 700;
      letter-spacing: .03em;
      text-transform: uppercase;
      color: var(--muted);
      margin-bottom: var(--space-1);
    }

    .print-field__value {
      display: block;
      color: var(--ink);
      font-size: var(--text-base);
      word-break: break-word;
    }

    .print-signatures {
      margin-top: var(--space-10);
      display: grid;
      grid-template-columns: repeat(2, minmax(0, 1fr));
      gap: var(--space-8);
    }

    .print-signature-line {
      border-top: 1px solid #000;
      padding-top: var(--space-2);
      font-size: var(--text-sm);
      text-align: center;
    }

    .print-actions {
      max-width: 900px;
      margin: 0 auto var(--space-4);
      display: flex;
      justify-content: space-between;
      gap: var(--space-3);
      flex-wrap: wrap;
    }

    .print-actions a,
    .print-actions button {
      text-decoration: none;
    }

    .print-meta {
      margin-top: var(--space-6);
      font-size: var(--text-xs);
      color: var(--muted);
      text-align: right;
    }

    @media (max-width: 768px) {
      .print-record-page {
        padding: var(--space-4);
      }

      .print-sheet {
        padding: var(--space-5);
      }

      .print-grid,
      .print-signatures {
        grid-template-columns: 1fr;
      }
    }

    @media print {
      body {
        background: #fff !important;
      }

      .no-print {
        display: none !important;
      }

      .print-sheet {
        max-width: none;
        border: 0;
        border-radius: 0;
        box-shadow: none;
        margin: 0;
        padding: 0;
      }

      .print-record-page {
        padding: 0;
      }
    }
  </style>
</head>

<body class="print-record-page">
  <div class="print-actions no-print" aria-label="Print actions">
    <a class="btn-primary" href="<?= htmlspecialchars($base_url . $back_path, ENT_QUOTES, 'UTF-8') ?>">Back</a>
    <button class="btn-confirm" type="button" onclick="window.print()">Print Form</button>
  </div>

  <article class="print-sheet" role="document" aria-label="Printable circulation record form">
    <?php if (!$record): ?>
      <div class="section-card">
        <div class="section-card__header">
          <span class="section-card__title">Record Not Found</span>
        </div>
        <div style="padding: var(--space-6)">
          <p style="margin-bottom: var(--space-4)">The requested circulation record does not exist or cannot be loaded.</p>
          <a class="btn-primary no-print" href="<?= htmlspecialchars($base_url . $back_path, ENT_QUOTES, 'UTF-8') ?>">Return</a>
        </div>
      </div>
    <?php else: ?>
      <?php
      $status_label = ucfirst((string) $record['status']);
      $fine_amount = (float) ($record['fine_amount'] ?? 0);
      $fine_paid = (int) ($record['fine_paid'] ?? 0) === 1 ? 'Yes' : 'No';
      $checked_out = !empty($record['checkout_date']) ? date('F d, Y h:i A', strtotime((string) $record['checkout_date'])) : 'N/A';
      $due_date = !empty($record['due_date']) ? date('F d, Y h:i A', strtotime((string) $record['due_date'])) : 'N/A';
      $returned = !empty($record['return_date']) ? date('F d, Y h:i A', strtotime((string) $record['return_date'])) : 'Not returned';
      $generated_at = date('F d, Y h:i A');
      ?>
      <header class="print-sheet__header">
        <div class="print-sheet__library"><?= htmlspecialchars($library_name, ENT_QUOTES, 'UTF-8') ?></div>
        <div class="print-sheet__title">Circulation Record Printable Form</div>
      </header>

      <section class="print-grid" aria-label="Record details">
        <div class="print-field">
          <span class="print-field__label">Record Number</span>
          <span class="print-field__value">#<?= (int) $record['id'] ?></span>
        </div>
        <div class="print-field">
          <span class="print-field__label">Record Type</span>
          <span class="print-field__value"><?= htmlspecialchars(strtoupper($record_type), ENT_QUOTES, 'UTF-8') ?></span>
        </div>
        <div class="print-field">
          <span class="print-field__label">Borrower Name</span>
          <span class="print-field__value"><?= htmlspecialchars((string) $record['borrower_name'], ENT_QUOTES, 'UTF-8') ?></span>
        </div>
        <div class="print-field">
          <span class="print-field__label">Borrower Email</span>
          <span class="print-field__value"><?= htmlspecialchars((string) ($record['borrower_email'] ?? 'N/A'), ENT_QUOTES, 'UTF-8') ?></span>
        </div>
        <div class="print-field">
          <span class="print-field__label">Book Title</span>
          <span class="print-field__value"><?= htmlspecialchars((string) $record['book_title'], ENT_QUOTES, 'UTF-8') ?></span>
        </div>
        <div class="print-field">
          <span class="print-field__label">Author</span>
          <span class="print-field__value"><?= htmlspecialchars((string) ($record['book_author'] ?? 'N/A'), ENT_QUOTES, 'UTF-8') ?></span>
        </div>
        <div class="print-field">
          <span class="print-field__label">ISBN</span>
          <span class="print-field__value"><?= htmlspecialchars((string) ($record['book_isbn'] ?? 'N/A'), ENT_QUOTES, 'UTF-8') ?></span>
        </div>
        <div class="print-field">
          <span class="print-field__label">Category</span>
          <span class="print-field__value"><?= htmlspecialchars((string) ($record['book_category'] ?? 'N/A'), ENT_QUOTES, 'UTF-8') ?></span>
        </div>
        <div class="print-field">
          <span class="print-field__label">Checkout Date</span>
          <span class="print-field__value"><?= htmlspecialchars($checked_out, ENT_QUOTES, 'UTF-8') ?></span>
        </div>
        <div class="print-field">
          <span class="print-field__label">Due Date</span>
          <span class="print-field__value"><?= htmlspecialchars($due_date, ENT_QUOTES, 'UTF-8') ?></span>
        </div>
        <div class="print-field">
          <span class="print-field__label">Return Date</span>
          <span class="print-field__value"><?= htmlspecialchars($returned, ENT_QUOTES, 'UTF-8') ?></span>
        </div>
        <div class="print-field">
          <span class="print-field__label">Status</span>
          <span class="print-field__value"><?= htmlspecialchars($status_label, ENT_QUOTES, 'UTF-8') ?></span>
        </div>
        <div class="print-field">
          <span class="print-field__label">Fine Amount</span>
          <span class="print-field__value">PHP <?= htmlspecialchars(number_format($fine_amount, 2), ENT_QUOTES, 'UTF-8') ?></span>
        </div>
        <div class="print-field">
          <span class="print-field__label">Fine Paid</span>
          <span class="print-field__value"><?= htmlspecialchars($fine_paid, ENT_QUOTES, 'UTF-8') ?></span>
        </div>
      </section>

      <section class="print-signatures" aria-label="Signature lines">
        <div class="print-signature-line">Borrower Signature Over Printed Name</div>
        <div class="print-signature-line">Librarian Signature Over Printed Name</div>
      </section>

      <div class="print-meta">
        Prepared by: <?= htmlspecialchars($prepared_by, ENT_QUOTES, 'UTF-8') ?><br>
        Generated on: <?= htmlspecialchars($generated_at, ENT_QUOTES, 'UTF-8') ?>
      </div>
    <?php endif; ?>
  </article>

  <?php if ($autoprint && $record): ?>
    <script>
      window.addEventListener('load', function() {
        window.print();
      });
    </script>
  <?php endif; ?>
</body>

</html>
