<?php

/**
 * librarian/checkout.php — Process Book Check-Out (US1, FR-005–FR-010)
 *
 * GET:  Display checkout form with verified Borrower list and available books.
 * POST: Atomically create a Circulation record, decrement available_copies,
 *       and write a System_Logs audit entry — all in one PDO transaction.
 *
 * Protected: Librarian role only (FR-029).
 */

// RBAC guard — must appear before any HTML output (FR-034)
$allowed_roles = ['librarian'];
require_once __DIR__ . '/../includes/auth_guard.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/circulation.php';
require_once __DIR__ . '/../includes/receipts.php';

$pdo = get_db();

// ---------------------------------------------------------------------------
// POST handler — T007–T011
// ---------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_verify();

  $user_id = (int) ($_POST['user_id'] ?? 0);
  $book_id = (int) ($_POST['book_id'] ?? 0);

  $actor_id   = (int) $_SESSION['user_id'];
  $actor_role = (string) $_SESSION['role'];

  if ($user_id < 1 || $book_id < 1) {
    $_SESSION['flash_error'] = 'Please select both a Borrower and a Book.';
    header('Location: ' . BASE_URL . 'librarian/checkout.php');
    exit;
  }

  $pdo->beginTransaction();

  try {
    // Expire stale reservations first
    expire_stale_reservations($pdo);

    // T008 — Lock the book row and check availability
    $book_stmt = $pdo->prepare(
      'SELECT id, title, available_copies, total_copies
               FROM Books
              WHERE id = ?
              FOR UPDATE'
    );
    $book_stmt->execute([$book_id]);
    $book = $book_stmt->fetch();

    if (!$book || (int) $book['available_copies'] < 1) {
      log_circulation($pdo, [
        'actor_id'      => $actor_id,
        'actor_role'    => $actor_role,
        'action_type'   => 'checkout',
        'target_entity' => 'Books',
        'target_id'     => $book_id,
        'outcome'       => 'failure',
      ]);
      $pdo->commit();
      $_SESSION['flash_error'] = 'No available copies for the selected book.';
      header('Location: ' . BASE_URL . 'librarian/checkout.php');
      exit;
    }

    // T009 — Verify borrower exists, is verified, and is not suspended
    $user_stmt = $pdo->prepare(
      'SELECT id FROM Users WHERE id = ? AND role = ? AND is_verified = 1 AND is_suspended = 0 LIMIT 1'
    );
    $user_stmt->execute([$user_id, 'borrower']);
    $borrower = $user_stmt->fetch();

    if (!$borrower) {
      log_circulation($pdo, [
        'actor_id'      => $actor_id,
        'actor_role'    => $actor_role,
        'action_type'   => 'checkout',
        'target_entity' => 'Users',
        'target_id'     => $user_id,
        'outcome'       => 'failure',
      ]);
      $pdo->commit();
      $_SESSION['flash_error'] = 'Selected Borrower not found, is not verified, or is suspended.';
      header('Location: ' . BASE_URL . 'librarian/checkout.php');
      exit;
    }

    // --- NEW LOGIC: Block Delinquent Borrowers (Unpaid Fines or Overdue Books) ---
    // Check for unpaid fines
    $fines_stmt = $pdo->prepare(
      'SELECT SUM(fine_amount) as total_unpaid FROM Circulation WHERE user_id = ? AND fine_paid = 0'
    );
    $fines_stmt->execute([$user_id]);
    $unpaid_fines = (float) ($fines_stmt->fetchColumn() ?: 0.0);

    if ($unpaid_fines > 0.0) {
      $pdo->rollBack();
      $_SESSION['flash_error'] = 'Borrower has outstanding unpaid fines ($' . number_format($unpaid_fines, 2) . ') and cannot check out books.';
      header('Location: ' . BASE_URL . 'librarian/checkout.php');
      exit;
    }

    // Check for currently overdue books (even if not yet returned/fined)
    $overdue_stmt = $pdo->prepare(
      'SELECT COUNT(*) FROM Circulation WHERE user_id = ? AND status IN (\'active\', \'overdue\') AND due_date < NOW()'
    );
    $overdue_stmt->execute([$user_id]);
    if ((int)$overdue_stmt->fetchColumn() > 0) {
      $pdo->rollBack();
      $_SESSION['flash_error'] = 'Borrower has overdue books that must be returned before checking out new ones.';
      header('Location: ' . BASE_URL . 'librarian/checkout.php');
      exit;
    }

    // --- NEW LOGIC: Enforce Max Borrow Limit ---
    $limit_stmt = $pdo->prepare(
      'SELECT COUNT(*) FROM Circulation WHERE user_id = ? AND status IN (\'active\', \'overdue\')'
    );
    $limit_stmt->execute([$user_id]);
    $current_loans = (int) $limit_stmt->fetchColumn();

    $res_limit_stmt = $pdo->prepare(
      'SELECT COUNT(*) FROM Reservations WHERE user_id = ? AND status IN (?, ?) AND book_id != ?'
    );
    $res_limit_stmt->execute([$user_id, RESERVATION_STATUS_PENDING, RESERVATION_STATUS_APPROVED, $book_id]);
    $current_res = (int) $res_limit_stmt->fetchColumn();

    $max_limit = (int) get_setting($pdo, 'max_borrow_limit', '3');

    if ($current_loans + $current_res >= $max_limit) {
      $pdo->rollBack();
      $_SESSION['flash_error'] = 'Borrower has reached their maximum allowed active loans and reservations (' . $max_limit . ').';
      header('Location: ' . BASE_URL . 'librarian/checkout.php');
      exit;
    }

    // T010 — Check for existing active/overdue loan for same (user_id, book_id)
    $dup_stmt = $pdo->prepare(
      'SELECT id FROM Circulation
              WHERE user_id = ? AND book_id = ? AND status IN (\'active\', \'overdue\')
              LIMIT 1'
    );
    $dup_stmt->execute([$user_id, $book_id]);
    $existing_loan = $dup_stmt->fetch();

    if ($existing_loan) {
      log_circulation($pdo, [
        'actor_id'      => $actor_id,
        'actor_role'    => $actor_role,
        'action_type'   => 'checkout',
        'target_entity' => 'Circulation',
        'target_id'     => (int) $existing_loan['id'],
        'outcome'       => 'failure',
      ]);
      $pdo->commit();
      $_SESSION['flash_error'] = 'This Borrower already has an active loan for this book.';
      header('Location: ' . BASE_URL . 'librarian/checkout.php');
      exit;
    }

    // T011 — Create the loan, decrement available_copies, write success log
    $loan_days = get_loan_period($pdo);

    $ins_stmt = $pdo->prepare(
      'INSERT INTO Circulation (user_id, book_id, due_date, status)
             VALUES (?, ?, NOW() + INTERVAL ? DAY, \'active\')'
    );
    $ins_stmt->execute([$user_id, $book_id, $loan_days]);
    $new_loan_id = (int) $pdo->lastInsertId();

    $loan_meta_stmt = $pdo->prepare(
      'SELECT c.due_date, b.title, u.full_name
         FROM Circulation c
         JOIN Books b ON c.book_id = b.id
         JOIN Users u ON c.user_id = u.id
        WHERE c.id = ?
        LIMIT 1'
    );
    $loan_meta_stmt->execute([$new_loan_id]);
    $loan_meta = $loan_meta_stmt->fetch();

    $upd_stmt = $pdo->prepare(
      'UPDATE Books SET available_copies = available_copies - 1 WHERE id = ?'
    );
    $upd_stmt->execute([$book_id]);

    // Fulfill reservation queue deterministically: approved first, fallback pending.
    $res_pick_stmt = $pdo->prepare(
      'SELECT id, status
         FROM Reservations
        WHERE user_id = ?
          AND book_id = ?
          AND status IN (?, ?)
        ORDER BY
          CASE status WHEN ? THEN 0 ELSE 1 END ASC,
          reserved_at ASC,
          id ASC
        LIMIT 1
        FOR UPDATE'
    );
    $res_pick_stmt->execute([
      $user_id,
      $book_id,
      RESERVATION_STATUS_APPROVED,
      RESERVATION_STATUS_PENDING,
      RESERVATION_STATUS_APPROVED,
    ]);
    $reservationToFulfill = $res_pick_stmt->fetch();

    if ($reservationToFulfill) {
      transition_reservation_status(
        $pdo,
        (int) $reservationToFulfill['id'],
        (string) $reservationToFulfill['status'],
        RESERVATION_STATUS_FULFILLED,
        [
          'actor_id' => $actor_id,
          'actor_role' => $actor_role,
          'action_type' => 'reservation_fulfill',
        ]
      );
    }

    log_circulation($pdo, [
      'actor_id'      => $actor_id,
      'actor_role'    => $actor_role,
      'action_type'   => 'checkout',
      'target_entity' => 'Circulation',
      'target_id'     => $new_loan_id,
      'outcome'       => 'success',
    ]);

    $receipt = issue_receipt_ticket($pdo, [
      'type'           => 'checkout',
      'actor_user_id'  => $actor_id,
      'actor_role'     => $actor_role,
      'patron_user_id' => $user_id,
      'reference_table' => 'Circulation',
      'reference_id'   => $new_loan_id,
      'format'         => 'thermal',
      'channel'        => 'librarian_console',
      'payload'        => [
        'loan_id'      => $new_loan_id,
        'book_id'      => $book_id,
        'book_title'   => (string) ($loan_meta['title'] ?? ''),
        'patron_name'  => (string) ($loan_meta['full_name'] ?? ''),
        'due_date'     => (string) ($loan_meta['due_date'] ?? ''),
        'loan_days'    => $loan_days,
      ],
    ]);

    $pdo->commit();

    $receipt_no = (string) ($receipt['receipt_no'] ?? '');
    $_SESSION['flash_receipt_no'] = $receipt_no;
    header('Location: ' . BASE_URL . 'librarian/checkout.php');
    exit;
  } catch (Throwable $e) {
    $pdo->rollBack();
    error_log('[checkout.php] Transaction failed: ' . $e->getMessage());
    $_SESSION['flash_error'] = 'An unexpected error occurred. Please try again.';
    header('Location: ' . BASE_URL . 'librarian/checkout.php');
    exit;
  }
}

// ---------------------------------------------------------------------------
// GET handler — T006
// ---------------------------------------------------------------------------

// Fetch verified and active borrowers
$borrower_stmt = $pdo->prepare(
  'SELECT id, full_name FROM Users
      WHERE role = ? AND is_verified = 1 AND is_suspended = 0
      ORDER BY full_name ASC'
);
$borrower_stmt->execute(['borrower']);
$borrowers = $borrower_stmt->fetchAll();

// Fetch available books
$books_stmt = $pdo->prepare(
  'SELECT id, title, available_copies FROM Books
      WHERE available_copies > 0
      ORDER BY title ASC'
);
$books_stmt->execute([]);
$books = $books_stmt->fetchAll();

// Fetch borrowers with unpaid fines
$fines_stmt = $pdo->prepare(
  'SELECT u.id, u.full_name, SUM(c.fine_amount) as total_fines 
   FROM Users u 
   JOIN Circulation c ON u.id = c.user_id 
   WHERE c.fine_paid = 0 AND c.fine_amount > 0 
   GROUP BY u.id, u.full_name 
   ORDER BY u.full_name ASC'
);
$fines_stmt->execute();
$users_with_fines = $fines_stmt->fetchAll();

// Flash messages
$flash_error   = $_SESSION['flash_error']   ?? '';
$flash_success = $_SESSION['flash_success'] ?? '';
$flash_info    = $_SESSION['flash_info'] ?? '';
$flash_print_url = $_SESSION['flash_print_url'] ?? '';
$flash_print_label = $_SESSION['flash_print_label'] ?? 'Print Record';
$flash_receipt_no = $_SESSION['flash_receipt_no'] ?? '';
unset($_SESSION['flash_error'], $_SESSION['flash_success'], $_SESSION['flash_info'], $_SESSION['flash_print_url'], $_SESSION['flash_print_label'], $_SESSION['flash_receipt_no']);

$name       = htmlspecialchars($_SESSION['full_name'], ENT_QUOTES, 'UTF-8');
$logout_url = htmlspecialchars(BASE_URL . 'logout.php', ENT_QUOTES, 'UTF-8');
$current_page = 'librarian.checkout';
$pageTitle    = 'Check Out | Library System';
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <?php require_once __DIR__ . '/../includes/head.php'; ?>
</head>

<body>
  <div class="app-shell">
    <?php require_once __DIR__ . '/../includes/sidebar-librarian.php'; ?>
    <main class="main-content">
      <div class="page-header">
        <h1>Process Check-Out</h1>
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
                rel="noopener noreferrer"><?= htmlspecialchars($flash_print_label, ENT_QUOTES, 'UTF-8') ?></a>
            </div>
          <?php endif; ?>
        </div>
      <?php endif; ?>
      <?php if ($flash_info !== ''): ?>
        <div class="flash flash-info" role="status" aria-live="polite" aria-atomic="true"><?= htmlspecialchars($flash_info, ENT_QUOTES, 'UTF-8') ?></div>
      <?php endif; ?>
      <?php
      $receipt_modal_title = 'Receipt issued';
      $receipt_modal_message = 'Checkout or fine payment was completed successfully. Print the ticket from this overlay.';
      $receipt_modal_print_label = 'Print Ticket';
      require __DIR__ . '/../includes/receipt-success-modal.php';
      ?>
      <div class="section-card">
        <div class="section-card__header">
          <span class="section-card__title">Checkout Form</span>
        </div>
        <div style="padding: var(--space-6)">
          <?php if (empty($borrowers)): ?>
            <p>No verified Borrowers are currently registered.</p>
          <?php elseif (empty($books)): ?>
            <p>No books with available copies are currently in the catalog.</p>
          <?php else: ?>
            <form method="POST" action="<?= htmlspecialchars(BASE_URL . 'librarian/checkout.php', ENT_QUOTES, 'UTF-8') ?>">
              <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">

              <div style="margin-bottom: var(--space-5)">
                <label class="field-label" for="user_id">Borrower</label>
                <select class="field-select select2-borrower" id="user_id" name="user_id" required>
                  <option value="">&mdash; Select a Borrower &mdash;</option>
                  <?php foreach ($borrowers as $b): ?>
                    <option value="<?= (int) $b['id'] ?>">
                      <?= htmlspecialchars($b['full_name'], ENT_QUOTES, 'UTF-8') ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>

              <div style="margin-bottom: var(--space-5)">
                <label class="field-label" for="book_id">Book (Available only)</label>
                <select class="field-select select2-book" id="book_id" name="book_id" required>
                  <option value="">&mdash; Select a Book &mdash;</option>
                  <?php foreach ($books as $bk): ?>
                    <option value="<?= (int) $bk['id'] ?>">
                      <?= htmlspecialchars($bk['title'], ENT_QUOTES, 'UTF-8') ?>
                      (<?= (int) $bk['available_copies'] ?> available)
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>

              <button type="submit" class="btn-primary">Check Out</button>
            </form>
          <?php endif; ?>
        </div>
      </div>

      <div class="section-card" style="margin-top: var(--space-6)">
        <div class="section-card__header">
          <span class="section-card__title">Clear Fines</span>
        </div>
        <div style="padding: var(--space-6)">
          <?php if (empty($users_with_fines)): ?>
            <p>No borrowers have outstanding fines.</p>
          <?php else: ?>
            <form method="POST" action="<?= htmlspecialchars(BASE_URL . 'librarian/pay-fine.php', ENT_QUOTES, 'UTF-8') ?>">
              <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">

              <div style="margin-bottom: var(--space-5)">
                <label class="field-label" for="fine_user_id">Select Borrower to Clear Fines</label>
                <select class="field-select select2-fines" id="fine_user_id" name="user_id" required>
                  <option value="">&mdash; Select a Borrower &mdash;</option>
                  <?php foreach ($users_with_fines as $u): ?>
                    <option value="<?= (int) $u['id'] ?>">
                      <?= htmlspecialchars($u['full_name'], ENT_QUOTES, 'UTF-8') ?> — ₱<?= number_format((float) $u['total_fines'], 2) ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>

              <button type="submit" class="btn-primary">Mark as Paid</button>
            </form>
          <?php endif; ?>
        </div>
      </div>
    </main>
  </div>

  <!-- Select2 — live searchable dropdowns -->
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css">
  <script src="https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
  <style>
    /* Match Select2 to the system's existing field style */
    .select2-container {
      width: 100% !important;
    }

    .select2-container--default .select2-selection--single {
      height: auto;
      min-height: 42px;
      border: 1px solid var(--border, #d1c9be);
      border-radius: var(--radius, 6px);
      background: #fff;
      padding: 8px 36px 8px 12px;
      font-size: var(--text-base, 14px);
      color: var(--ink, #1a1a1a);
      display: flex;
      align-items: center;
    }

    .select2-container--default .select2-selection--single .select2-selection__rendered {
      padding: 0;
      line-height: 1.4;
      color: var(--ink, #1a1a1a);
    }

    .select2-container--default .select2-selection--single .select2-selection__arrow {
      height: 100%;
      top: 0;
      right: 10px;
    }

    .select2-container--default.select2-container--focus .select2-selection--single,
    .select2-container--default.select2-container--open .select2-selection--single {
      border-color: var(--accent, #8b6f47);
      outline: none;
      box-shadow: 0 0 0 3px rgba(139, 111, 71, .15);
    }

    .select2-dropdown {
      border: 1px solid var(--border, #d1c9be);
      border-radius: var(--radius, 6px);
      box-shadow: 0 4px 16px rgba(0, 0, 0, .08);
      font-size: var(--text-base, 14px);
    }

    .select2-search--dropdown .select2-search__field {
      border: 1px solid var(--border, #d1c9be);
      border-radius: 4px;
      padding: 6px 10px;
      font-size: 14px;
    }

    .select2-search--dropdown .select2-search__field:focus {
      outline: none;
      border-color: var(--accent, #8b6f47);
    }

    .select2-container--default .select2-results__option--highlighted[aria-selected] {
      background-color: var(--accent, #8b6f47);
      color: #fff;
    }

    .select2-results__option {
      padding: 8px 12px;
    }
  </style>
  <script>
    $(function() {
      $('.select2-borrower').select2({
        placeholder: '— Type to search borrower —',
        allowClear: true,
        width: '100%'
      });
      $('.select2-book').select2({
        placeholder: '— Type to search book title —',
        allowClear: true,
        width: '100%'
      });
      $('.select2-fines').select2({
        placeholder: '— Type to search borrower —',
        allowClear: true,
        width: '100%'
      });
    });
  </script>
</body>

</html>