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

$pdo = get_db();

// ---------------------------------------------------------------------------
// POST handler — T014–T016
// ---------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_verify();

  $loan_id    = (int) ($_POST['loan_id'] ?? 0);
  $actor_id   = (int) $_SESSION['user_id'];
  $actor_role = (string) $_SESSION['role'];

  if ($loan_id < 1) {
    $_SESSION['flash_error'] = 'Invalid loan reference.';
    header('Location: ' . BASE_URL . 'librarian/checkin.php');
    exit;
  }

  // T014 — Fetch loan; reject if not found or already returned
  $loan_stmt = $pdo->prepare(
    'SELECT c.id, c.user_id, c.book_id, c.due_date, c.status,
            b.title AS book_title,
            u.full_name AS borrower_name
       FROM Circulation c
       JOIN Books b ON c.book_id = b.id
       JOIN Users u ON c.user_id = u.id
      WHERE c.id = ?
      LIMIT 1'
  );
  $loan_stmt->execute([$loan_id]);
  $loan = $loan_stmt->fetch();

  if (!$loan || $loan['status'] === 'returned') {
    $_SESSION['flash_error'] = 'Loan not found or has already been returned.';
    header('Location: ' . BASE_URL . 'librarian/checkin.php');
    exit;
  }

  // T015 — Calculate fine in PHP
  $return_ts = time();
  $due_ts    = strtotime($loan['due_date']);
  $days_late = max(0, (int) ceil(($return_ts - $due_ts) / 86400));
  $rate      = (float) get_setting($pdo, 'fine_per_day', '0.00');
  $fine      = round($days_late * $rate, 2);

  // T016 — Transactional update
  $pdo->beginTransaction();

  try {
    $upd_loan = $pdo->prepare(
      'UPDATE Circulation
                SET return_date  = NOW(),
                    status       = \'returned\',
                    fine_amount  = ?
              WHERE id = ?'
    );
    $upd_loan->execute([$fine, $loan_id]);

    $upd_book = $pdo->prepare(
      'UPDATE Books
                SET available_copies = LEAST(available_copies + 1, total_copies)
              WHERE id = ?'
    );
    $upd_book->execute([(int) $loan['book_id']]);

    log_circulation($pdo, [
      'actor_id'      => $actor_id,
      'actor_role'    => $actor_role,
      'action_type'   => 'checkin',
      'target_entity' => 'Circulation',
      'target_id'     => $loan_id,
      'outcome'       => 'success',
    ]);

    $receipt = issue_receipt_ticket($pdo, [
      'type'            => 'checkin',
      'actor_user_id'   => $actor_id,
      'actor_role'      => $actor_role,
      'patron_user_id'  => (int) $loan['user_id'],
      'reference_table' => 'Circulation',
      'reference_id'    => $loan_id,
      'format'          => 'thermal',
      'channel'         => 'librarian_console',
      'payload'         => [
        'loan_id'       => $loan_id,
        'book_id'       => (int) $loan['book_id'],
        'book_title'    => (string) ($loan['book_title'] ?? ''),
        'patron_name'   => (string) ($loan['borrower_name'] ?? ''),
        'due_date'      => (string) ($loan['due_date'] ?? ''),
        'returned_at'   => date('Y-m-d H:i:s'),
        'days_late'     => $days_late,
        'fine_amount'   => $fine,
      ],
    ]);

    $pdo->commit();

    // --- NEW LOGIC: Reservation Alert ---
    $res_check = $pdo->prepare("SELECT COUNT(*) FROM Reservations WHERE book_id = ? AND status = 'pending'");
    $res_check->execute([(int) $loan['book_id']]);
    $has_reservations = (int) $res_check->fetchColumn();

    $fine_msg = ($fine > 0.00)
      ? ' Fine assessed: $' . number_format($fine, 2) . '.'
      : ' No fine.';
      
    $alert_msg = ($has_reservations > 0) 
      ? ' ⚠️ Note: There are pending reservations for this book. Please place it on the Hold shelf.'
      : '';

    $_SESSION['flash_success'] = 'Book returned successfully. Loan ID: ' . $loan_id . '.' . $fine_msg . $alert_msg;
    $_SESSION['flash_receipt_no'] = (string) ($receipt['receipt_no'] ?? '');
    $_SESSION['flash_print_url'] = BASE_URL . 'librarian/print-record.php?loan_id=' . $loan_id . '&type=checkin';
    $_SESSION['flash_print_label'] = 'Print Return Record';
    header('Location: ' . BASE_URL . 'librarian/checkin.php');
    exit;
  } catch (Throwable $e) {
    $pdo->rollBack();
    error_log('[checkin.php] Transaction failed: ' . $e->getMessage());
    $_SESSION['flash_error'] = 'An unexpected error occurred. Please try again.';
    header('Location: ' . BASE_URL . 'librarian/checkin.php');
    exit;
  }
}

// ---------------------------------------------------------------------------
// GET handler — T013
// ---------------------------------------------------------------------------

// Bulk overdue UPDATE: mark active loans past due_date as overdue
$pdo->exec(
  "UPDATE Circulation SET status = 'overdue'
      WHERE status = 'active' AND due_date < NOW()"
);

// Query active + overdue loans joined with Users and Books
$loans_stmt = $pdo->prepare(
  'SELECT c.id, c.user_id, c.book_id, c.due_date, c.status,
            b.title, u.full_name
       FROM Circulation c
       JOIN Books       b ON c.book_id  = b.id
       JOIN Users       u ON c.user_id  = u.id
      WHERE c.status IN (\'active\', \'overdue\')
      ORDER BY c.due_date ASC'
);
$loans_stmt->execute([]);
$loans = $loans_stmt->fetchAll();

// Flash messages
$flash_error   = $_SESSION['flash_error']   ?? '';
$flash_success = $_SESSION['flash_success'] ?? '';
$flash_print_url = $_SESSION['flash_print_url'] ?? '';
$flash_print_label = $_SESSION['flash_print_label'] ?? 'Print Record';
$flash_receipt_no = $_SESSION['flash_receipt_no'] ?? '';
unset($_SESSION['flash_error'], $_SESSION['flash_success'], $_SESSION['flash_print_url'], $_SESSION['flash_print_label'], $_SESSION['flash_receipt_no']);

$logout_url = htmlspecialchars(BASE_URL . 'logout.php', ENT_QUOTES, 'UTF-8');
$current_page = 'librarian.checkin';
$pageTitle    = 'Check In | Library System';
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
                  <tr class="<?= $loan['status'] === 'overdue' ? 'row-overdue' : '' ?>">
                    <td data-label="Loan #"><?= (int) $loan['id'] ?></td>
                    <td data-label="Borrower"><?= htmlspecialchars($loan['full_name'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td data-label="Book"><?= htmlspecialchars($loan['title'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td data-label="Due Date"><?= htmlspecialchars($loan['due_date'], ENT_QUOTES, 'UTF-8') ?></td>
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
        <?php endif; ?>
      </div>
    </main>
  </div>
</body>

</html>





