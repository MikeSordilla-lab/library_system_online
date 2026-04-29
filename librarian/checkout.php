<?php

/**
 * librarian/checkout.php — Process Book Check-Out (US1, FR-005–FR-010)
 *
 * GET:  Display checkout form with verified Borrower list and available books.
 * POST: Delegate to circulation orchestration layer.
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
// POST handler — delegate to orchestration layer
// ---------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_verify();

  $user_id    = (int) ($_POST['user_id'] ?? 0);
  $book_id    = (int) ($_POST['book_id'] ?? 0);
  $actor_id   = (int) $_SESSION['user_id'];
  $actor_role = (string) $_SESSION['role'];

  $result = perform_checkout($pdo, $user_id, $book_id, $actor_id, $actor_role);

  if ($result['success']) {
    if ($result['receipt_no']) {
      $_SESSION['flash_receipt_no'] = $result['receipt_no'];
    }
  } else {
    $_SESSION['flash_error'] = $result['message'];
  }

  header('Location: ' . BASE_URL . 'librarian/checkout.php');
  exit;
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
</head>

<body class="librarian-themed">
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
