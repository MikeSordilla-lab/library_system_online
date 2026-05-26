<?php

/**
 * librarian/clear_fines.php — Dedicated Clear Fines page
 *
 * GET:  Display table of fines with search, filter, and individual Clear action.
 * POST: Clear a single fine (mark fine_paid = 1) with receipt generation.
 *
 * Protected: Librarian role only.
 */

$allowed_roles = ['librarian'];
require_once __DIR__ . '/../includes/auth_guard.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/circulation.php';
require_once __DIR__ . '/../includes/receipts.php';

$pdo = get_db();

// ---------------------------------------------------------------------------
// POST handler — clear an individual fine
// ---------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_verify();

  $loan_id   = filter_var($_POST['loan_id'] ?? '', FILTER_VALIDATE_INT);
  $loan_id   = ($loan_id !== false && $loan_id > 0) ? (int) $loan_id : 0;
  $actor_id   = (int) $_SESSION['user_id'];
  $actor_role = (string) $_SESSION['role'];

  if ($loan_id < 1) {
    $_SESSION['flash_error'] = 'Invalid loan reference.';
    header('Location: ' . BASE_URL . 'librarian/clear_fines.php');
    exit;
  }

  $loan_stmt = $pdo->prepare(
    'SELECT c.id, c.user_id, c.fine_amount, c.status,
            u.full_name, u.email
       FROM Circulation c
       JOIN Users u ON c.user_id = u.id
      WHERE c.id = ?
        AND c.fine_amount > 0
        AND c.fine_paid = 0
      LIMIT 1'
  );
  $loan_stmt->execute([$loan_id]);
  $loan = $loan_stmt->fetch();

  if (!$loan) {
    $_SESSION['flash_error'] = 'Fine record not found or already paid.';
    header('Location: ' . BASE_URL . 'librarian/clear_fines.php');
    exit;
  }

  $pdo->beginTransaction();

  try {
    $upd = $pdo->prepare(
      'UPDATE Circulation
          SET fine_paid = 1,
              fine_paid_at = NOW()
        WHERE id = ?
          AND fine_paid = 0'
    );
    $upd->execute([$loan_id]);

    if ($upd->rowCount() < 1) {
      $pdo->rollBack();
      $_SESSION['flash_error'] = 'Fine was already paid by another request.';
      header('Location: ' . BASE_URL . 'librarian/clear_fines.php');
      exit;
    }

    $amount = (float) $loan['fine_amount'];

    log_circulation($pdo, [
      'actor_id'      => $actor_id,
      'actor_role'    => $actor_role,
      'action_type'   => 'fine_payment',
      'target_entity' => 'Circulation',
      'target_id'     => $loan_id,
      'outcome'       => 'success_amount_' . $amount,
    ]);

    $receipt = issue_receipt_ticket($pdo, [
      'type'            => 'fine_payment',
      'actor_user_id'   => $actor_id,
      'actor_role'      => $actor_role,
      'patron_user_id'  => (int) $loan['user_id'],
      'reference_table' => 'Circulation',
      'reference_id'    => $loan_id,
      'idempotency_key' => 'fine_payment:loan:' . $loan_id . ':at:' . date('YmdHi'),
      'amount'          => $amount,
      'currency'        => receipt_default_currency(),
      'format'          => 'a4',
      'channel'         => 'librarian_console',
      'payload'         => [
        'patron_name'   => (string) ($loan['full_name'] ?? ''),
        'patron_email'  => (string) ($loan['email'] ?? ''),
        'total_fines'   => $amount,
        'settled_scope' => 'single_fine_clearance',
      ],
    ]);

    $pdo->commit();
    $_SESSION['flash_success'] = 'Fine cleared — ' . receipt_format_money($amount) . ' marked as paid.';
    $receipt_no = (string) ($receipt['receipt_no'] ?? '');
    $_SESSION['flash_receipt_no'] = $receipt_no;
  } catch (Throwable $e) {
    $pdo->rollBack();
    error_log('[clear_fines.php] Transaction failed: ' . $e->getMessage());
    $_SESSION['flash_error'] = 'Failed to clear fine. Please try again.';
  }

  header('Location: ' . BASE_URL . 'librarian/clear_fines.php');
  exit;
}

// ---------------------------------------------------------------------------
// GET handler — fetch fines with search & filter
// ---------------------------------------------------------------------------

$search_query = trim($_GET['search'] ?? '');
$filter       = trim($_GET['filter'] ?? 'all');

$where_conditions = ['c.fine_amount > 0'];
$params           = [];

if ($search_query !== '') {
  $where_conditions[] = '(u.full_name LIKE ? OR u.email LIKE ? OR b.title LIKE ? OR CAST(c.id AS CHAR) LIKE ?)';
  $like = '%' . $search_query . '%';
  $params[] = $like;
  $params[] = $like;
  $params[] = $like;
  $params[] = '%' . $search_query . '%';
}

switch ($filter) {
  case 'unpaid':
    $where_conditions[] = 'c.fine_paid = 0';
    break;
  case 'paid':
    $where_conditions[] = 'c.fine_paid = 1';
    break;
  case 'overdue':
    $where_conditions[] = "c.status = 'overdue'";
    break;
  case 'this_week':
    $where_conditions[] = 'YEARWEEK(c.checkout_date, 1) = YEARWEEK(CURDATE(), 1)';
    break;
  case 'this_month':
    $where_conditions[] = 'YEAR(c.checkout_date) = YEAR(CURDATE()) AND MONTH(c.checkout_date) = MONTH(CURDATE())';
    break;
  case 'all':
  default:
    break;
}

$where_sql = implode(' AND ', $where_conditions);

$fines_sql = "
  SELECT c.id AS loan_id,
         u.id AS user_id,
         u.full_name AS borrower_name,
         u.email AS borrower_email,
         b.title AS book_title,
         c.fine_amount,
         c.status AS loan_status,
         c.due_date,
         c.return_date,
         c.checkout_date,
         c.fine_paid,
         c.fine_paid_at
    FROM Circulation c
    JOIN Users u ON c.user_id = u.id
    JOIN Books b ON c.book_id = b.id
   WHERE {$where_sql}
   ORDER BY c.fine_paid ASC, c.checkout_date DESC
";

$fines_stmt = $pdo->prepare($fines_sql);
$fines_stmt->execute($params);
$fines = $fines_stmt->fetchAll();

$fine_reason = function (string $status): string {
  return $status === 'overdue' ? 'Overdue Fine' : 'Late Return Fine';
};

// Flash messages
$flash_error   = $_SESSION['flash_error']   ?? '';
$flash_success = $_SESSION['flash_success'] ?? '';
$flash_info    = $_SESSION['flash_info']    ?? '';
$flash_receipt_no = $_SESSION['flash_receipt_no'] ?? '';
unset($_SESSION['flash_error'], $_SESSION['flash_success'],
      $_SESSION['flash_info'], $_SESSION['flash_receipt_no']);

$current_page = 'librarian.clear_fines';
$pageTitle    = 'Clear Fines | LibraLink';
$extraStyles  = [
  'https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700&display=swap',
  BASE_URL . 'assets/css/borrower-redesign.css',
  BASE_URL . 'assets/css/librarian-redesign.css',
];
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <?php require_once __DIR__ . '/../includes/head.php'; ?>
  <style>
    .librarian-themed .cat-toolbar {
      margin-bottom: 1.5rem;
    }

    .fines-total-card {
      display: inline-flex;
      align-items: center;
      gap: 0.5rem;
      padding: 0.5rem 1.25rem;
      background: rgba(201, 168, 76, 0.1);
      border: 1px solid rgba(201, 168, 76, 0.25);
      border-radius: 10px;
      color: var(--rd-primary);
      font-weight: 600;
      font-size: 0.95rem;
      white-space: nowrap;
    }

    .librarian-themed .tbl td .btn-sm {
      padding: 0.4rem 0.9rem;
      font-size: 0.8rem;
      border-radius: 8px;
    }

    .librarian-themed .badge-gray {
      background: rgba(255, 255, 255, 0.08);
      color: var(--rd-text-muted);
    }

    @media (max-width: 768px) {
      .fines-total-card {
        width: 100%;
        justify-content: center;
      }
      .librarian-themed .tbl td .btn-sm {
        width: 100%;
        justify-content: center;
      }
    }
  </style>
</head>

<body class="librarian-themed">
  <div class="app-shell">
    <?php require_once __DIR__ . '/../includes/sidebar-librarian.php'; ?>
    <main class="main-content">
      <div class="page-header">
        <div>
          <h1>Clear Fines</h1>
          <p>View borrowers with outstanding fines and process fine payments.</p>
        </div>
        <?php
        $unpaid_total = 0;
        foreach ($fines as $f) {
          if (!$f['fine_paid']) {
            $unpaid_total += (float) $f['fine_amount'];
          }
        }
        if ($unpaid_total > 0):
        ?>
          <div class="fines-total-card">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/></svg>
            Total Unpaid: <?= receipt_format_money($unpaid_total) ?>
          </div>
        <?php endif; ?>
      </div>

      <?php if ($flash_error !== ''): ?>
        <div class="flash flash-error" role="alert"><?= htmlspecialchars($flash_error, ENT_QUOTES, 'UTF-8') ?></div>
      <?php endif; ?>
      <?php if ($flash_success !== ''): ?>
        <div class="flash flash-success" role="alert"><?= htmlspecialchars($flash_success, ENT_QUOTES, 'UTF-8') ?></div>
      <?php endif; ?>
      <?php if ($flash_info !== ''): ?>
        <div class="flash flash-info" role="status"><?= htmlspecialchars($flash_info, ENT_QUOTES, 'UTF-8') ?></div>
      <?php endif; ?>
      <?php
      $receipt_modal_title = 'Receipt issued';
      $receipt_modal_message = 'Fine payment was processed successfully.';
      $receipt_modal_print_label = 'Print Ticket';
      require __DIR__ . '/../includes/receipt-success-modal.php';
      ?>

      <div class="cat-toolbar">
        <div class="cat-toolbar__search-wrap">
          <span class="cat-toolbar__search-icon" aria-hidden="true">&#x1F50D;</span>
          <input
            class="cat-toolbar__search"
            type="search"
            id="search-input"
            placeholder="Search borrower, email, book, or loan #..."
            value="<?= htmlspecialchars($search_query, ENT_QUOTES, 'UTF-8') ?>"
            autofocus
          >
        </div>

        <select class="cat-toolbar__select" id="filter-select">
          <option value="all"         <?= $filter === 'all'         ? 'selected' : '' ?>>All Fines</option>
          <option value="unpaid"      <?= $filter === 'unpaid'      ? 'selected' : '' ?>>Unpaid</option>
          <option value="paid"        <?= $filter === 'paid'        ? 'selected' : '' ?>>Paid</option>
          <option value="overdue"     <?= $filter === 'overdue'     ? 'selected' : '' ?>>Overdue Fine</option>
          <option value="this_week"   <?= $filter === 'this_week'   ? 'selected' : '' ?>>This Week</option>
          <option value="this_month"  <?= $filter === 'this_month'  ? 'selected' : '' ?>>This Month</option>
        </select>

        <button type="button" class="cat-toolbar__reset" id="clear-filters-btn">Clear</button>
      </div>

      <div class="section-card">
        <div class="section-card__header">
          <span class="section-card__title">Fine Records</span>
          <?php if (count($fines) > 0): ?>
            <span class="badge <?= $filter === 'paid' ? 'badge-green' : 'badge-amber' ?>"><?= count($fines) ?> record<?= count($fines) !== 1 ? 's' : '' ?></span>
          <?php endif; ?>
        </div>
        <div style="padding: var(--space-6)">
          <?php if (empty($fines)): ?>
            <div class="empty-state">
              <span class="empty-state__icon" aria-hidden="true">&#x2705;</span>
              <p>No borrowers have outstanding fines.</p>
              <?php if ($filter !== 'all' || $search_query !== ''): ?>
                <p><a href="<?= BASE_URL ?>librarian/clear_fines.php">Clear all filters</a></p>
              <?php endif; ?>
            </div>
          <?php else: ?>
            <div class="tbl-wrapper">
              <table class="tbl" id="fines-table">
                <thead>
                  <tr>
                    <th>Borrower Name</th>
                    <th>Email</th>
                    <th>Loan #</th>
                    <th>Book Title</th>
                    <th>Fine Amount</th>
                    <th>Fine Reason</th>
                    <th>Date Assessed</th>
                    <th>Status</th>
                    <th>Action</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($fines as $row):
                    $is_unpaid = !((int) $row['fine_paid']);
                    $assessed_date = !empty($row['return_date']) ? $row['return_date'] : $row['checkout_date'];
                  ?>
                    <tr class="<?= $is_unpaid ? 'row-overdue' : '' ?>">
                      <td data-label="Borrower Name">
                        <?= htmlspecialchars((string) ($row['borrower_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
                      </td>
                      <td data-label="Email">
                        <?= htmlspecialchars((string) ($row['borrower_email'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
                      </td>
                      <td data-label="Loan #">
                        <span class="cat-chip">#<?= (int) $row['loan_id'] ?></span>
                      </td>
                      <td data-label="Book Title">
                        <?= htmlspecialchars((string) ($row['book_title'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
                      </td>
                      <td data-label="Fine Amount">
                        <strong style="color: <?= $is_unpaid ? 'var(--rd-danger)' : 'var(--rd-success)' ?>">
                          <?= receipt_format_money((float) $row['fine_amount']) ?>
                        </strong>
                      </td>
                      <td data-label="Fine Reason">
                        <?= htmlspecialchars($fine_reason((string) ($row['loan_status'] ?? '')), ENT_QUOTES, 'UTF-8') ?>
                      </td>
                      <td data-label="Date Assessed">
                        <?= !empty($assessed_date) ? htmlspecialchars(date('d M Y', strtotime((string) $assessed_date)), ENT_QUOTES, 'UTF-8') : '—' ?>
                      </td>
                      <td data-label="Status">
                        <?php if ($is_unpaid): ?>
                          <span class="badge badge-red">Unpaid</span>
                        <?php else: ?>
                          <span class="badge badge-green">Paid</span>
                        <?php endif; ?>
                      </td>
                      <td data-label="Action">
                        <?php if ($is_unpaid): ?>
                          <button
                            type="button"
                            class="btn-confirm btn-sm clear-fine-btn"
                            data-loan-id="<?= (int) $row['loan_id'] ?>"
                            data-borrower="<?= htmlspecialchars((string) ($row['borrower_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                            data-amount="<?= receipt_format_money((float) $row['fine_amount']) ?>"
                          >
                            Clear Fine
                          </button>
                        <?php else: ?>
                          <span class="badge badge-gray">Cleared</span>
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

  <form method="POST" id="clear-fine-form" style="display:none;">
    <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
    <input type="hidden" name="loan_id" id="clear-loan-id" value="">
  </form>

  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
  <script>
    (function() {
      const searchInput = document.getElementById('search-input');
      const filterSelect = document.getElementById('filter-select');
      const clearBtn = document.getElementById('clear-filters-btn');
      const clearFineForm = document.getElementById('clear-fine-form');
      const clearLoanId = document.getElementById('clear-loan-id');

      function reloadWithParams() {
        const params = new URLSearchParams();
        const q = searchInput.value.trim();
        if (q) params.set('search', q);
        const f = filterSelect.value;
        if (f && f !== 'all') params.set('filter', f);
        const qs = params.toString();
        window.location.href = '<?= BASE_URL ?>librarian/clear_fines.php' + (qs ? '?' + qs : '');
      }

      searchInput.addEventListener('keydown', function(e) {
        if (e.key === 'Enter') {
          e.preventDefault();
          reloadWithParams();
        }
      });

      filterSelect.addEventListener('change', reloadWithParams);

      clearBtn.addEventListener('click', function() {
        searchInput.value = '';
        filterSelect.value = 'all';
        reloadWithParams();
      });

      document.querySelectorAll('.clear-fine-btn').forEach(function(btn) {
        btn.addEventListener('click', function(e) {
          const loanId = this.getAttribute('data-loan-id');
          const borrower = this.getAttribute('data-borrower');
          const amount = this.getAttribute('data-amount');

          Swal.fire({
            title: 'Clear Fine?',
            html: 'Clear fine of <strong>' + amount + '</strong> for <strong>' + borrower + '</strong>?',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#10b981',
            cancelButtonColor: '#6b7280',
            confirmButtonText: 'Yes, clear fine',
            cancelButtonText: 'Cancel',
            background: '#2a2723',
            color: '#f0ebd8',
            iconColor: '#fbbf24',
            reverseButtons: true,
          }).then(function(result) {
            if (result.isConfirmed) {
              clearLoanId.value = loanId;
              clearFineForm.submit();
            }
          });
        });
      });
    })();
  </script>
</body>
</html>
