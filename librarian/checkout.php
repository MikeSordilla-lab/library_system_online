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

  $action = (string) ($_POST['action'] ?? 'checkout');
  $user_id = (int) ($_POST['user_id'] ?? 0);
  $book_id = (int) ($_POST['book_id'] ?? 0);
  $pickup_reservation_id = (int) ($_POST['reservation_id'] ?? 0);

  $actor_id   = (int) $_SESSION['user_id'];
  $actor_role = (string) $_SESSION['role'];

  if (!in_array($action, ['checkout', 'pickup'], true)) {
    $_SESSION['flash_error'] = 'Invalid checkout action request.';
    header('Location: ' . BASE_URL . 'librarian/checkout.php');
    exit;
  }

  if ($action === 'checkout' && ($user_id < 1 || $book_id < 1)) {
    $_SESSION['flash_error'] = 'Please select both a Borrower and a Book.';
    header('Location: ' . BASE_URL . 'librarian/checkout.php');
    exit;
  }

  if ($action === 'pickup' && $pickup_reservation_id < 1) {
    $_SESSION['flash_error'] = 'Please select an approved reservation to pick up.';
    header('Location: ' . BASE_URL . 'librarian/checkout.php');
    exit;
  }

  $pdo->beginTransaction();

  try {
    // Expire stale reservations first
    expire_stale_reservations($pdo);

    if ($action === 'pickup') {
      $pickup_stmt = $pdo->prepare(
        'SELECT id, user_id, book_id, status
           FROM Reservations
          WHERE id = ?
          FOR UPDATE'
      );
      $pickup_stmt->execute([$pickup_reservation_id]);
      $pickup_reservation = $pickup_stmt->fetch();

      if (!$pickup_reservation) {
        $pdo->rollBack();
        $_SESSION['flash_error'] = 'Approved reservation not found.';
        header('Location: ' . BASE_URL . 'librarian/checkout.php');
        exit;
      }

      if ((string) $pickup_reservation['status'] !== RESERVATION_STATUS_APPROVED) {
        $pdo->commit();
        $_SESSION['flash_info'] = 'Reservation is no longer approved for pickup.';
        header('Location: ' . BASE_URL . 'librarian/checkout.php');
        exit;
      }

      $user_id = (int) $pickup_reservation['user_id'];
      $book_id = (int) $pickup_reservation['book_id'];
    }

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
      'SELECT SUM(fine_amount) as total_unpaid FROM Circulation WHERE user_id = ? AND fine_paid = 0 AND fine_amount > 0'
    );
    $fines_stmt->execute([$user_id]);
    $unpaid_fines = (float) ($fines_stmt->fetchColumn() ?: 0.0);

    if ($unpaid_fines > 0.0) {
      $pdo->rollBack();
      $_SESSION['flash_error'] = 'Borrower has outstanding unpaid fines (' . format_circulation_money($pdo, $unpaid_fines) . ') and cannot borrow another book until paid.';
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

    if ($action === 'pickup') {
      $reservationToFulfill = [
        'id' => $pickup_reservation_id,
        'status' => RESERVATION_STATUS_APPROVED,
      ];
    } else {
      $reservationToFulfill = checkout_reservation_to_fulfill($pdo, $user_id, $book_id);
    }

    if ((int) $book['available_copies'] < 1) {
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

    $reservationPolicy = checkout_reservation_policy($pdo, $user_id, $book_id);
    if (!$reservationPolicy['allowed']) {
      log_circulation($pdo, [
        'actor_id'      => $actor_id,
        'actor_role'    => $actor_role,
        'action_type'   => 'checkout',
        'target_entity' => 'Reservations',
        'target_id'     => (int) ($reservationPolicy['reservation_id'] ?? 0),
        'outcome'       => 'failure',
      ]);
      $pdo->commit();
      $_SESSION['flash_error'] = 'This book is approved for another borrower pickup.';
      header('Location: ' . BASE_URL . 'librarian/checkout.php');
      exit;
    }

    // T011 — Create the loan, decrement available_copies, then log success
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
    $_SESSION['flash_success'] = $action === 'pickup'
      ? 'Reservation picked up and checked out successfully.'
      : 'Book checked out successfully.';
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

// Fetch approved reservations waiting for the borrower to pick up the book
$pickup_stmt = $pdo->prepare(
  'SELECT r.id, r.user_id, r.book_id, r.approved_at, r.expires_at,
          u.full_name AS borrower_name, u.email AS borrower_email,
          b.title AS book_title, b.author AS book_author, b.available_copies
     FROM Reservations r
     JOIN Users u ON r.user_id = u.id
     JOIN Books b ON r.book_id = b.id
    WHERE r.status = ?
    ORDER BY r.expires_at ASC, r.id ASC'
);
$pickup_stmt->execute([RESERVATION_STATUS_APPROVED]);
$ready_pickups = $pickup_stmt->fetchAll();

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
$pageTitle    = 'Check Out | LibraLink';
$extraStyles = [
  'https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700&display=swap',
  BASE_URL . 'assets/css/borrower-redesign.css',
  BASE_URL . 'assets/css/librarian-redesign.css'
];
?>
<?php
// ── Helper: build checkout filter URL ──────────────────────────────────
function co_filter_url($base_url, $search, $status, $date, $page = '') {
    $params = [];
    if ($search !== '') $params['co_search'] = $search;
    if ($status  !== '') $params['co_status']  = $status;
    if ($date    !== '') $params['co_date']    = $date;
    if ($page    !== '') $params['co_page']    = $page;
    return $base_url . ($params ? '?' . http_build_query($params) : '');
}

// ── Checked-Out Books — Paginated Query ─────────────────────────────────
$co_search = trim((string) ($_GET['co_search'] ?? ''));
$co_status = trim((string) ($_GET['co_status'] ?? ''));
$co_date   = trim((string) ($_GET['co_date'] ?? ''));
$co_page   = max(1, (int) ($_GET['co_page'] ?? 1));
$co_per_page = 15;

$allowed_status = ['', 'active', 'due_today', 'overdue'];
$allowed_date   = ['', 'this_week', 'this_month'];
if (!in_array($co_status, $allowed_status, true)) $co_status = '';
if (!in_array($co_date,   $allowed_date,   true)) $co_date   = '';

// ── Counts for filter chips (unfiltered) ──
$co_chip_counts = [];
$q = fn($sql) => $pdo->query($sql)->fetchColumn();
$co_chip_counts['all']        = (int) $q("SELECT COUNT(*) FROM Circulation WHERE status IN ('active','overdue')");
$co_chip_counts['active']     = (int) $q("SELECT COUNT(*) FROM Circulation WHERE status='active' AND due_date>=NOW()");
$co_chip_counts['due_today']  = (int) $q("SELECT COUNT(*) FROM Circulation WHERE status IN ('active','overdue') AND DATE(due_date)=CURDATE()");
$co_chip_counts['overdue']    = (int) $q("SELECT COUNT(*) FROM Circulation WHERE status IN ('active','overdue') AND due_date<NOW()");
$co_chip_counts['this_week']  = (int) $q("SELECT COUNT(*) FROM Circulation WHERE status IN ('active','overdue') AND YEARWEEK(checkout_date,1)=YEARWEEK(CURDATE(),1)");
$co_chip_counts['this_month'] = (int) $q("SELECT COUNT(*) FROM Circulation WHERE status IN ('active','overdue') AND YEAR(checkout_date)=YEAR(CURDATE()) AND MONTH(checkout_date)=MONTH(CURDATE())");

// ── Build WHERE ──
$co_where   = ["c.status IN ('active', 'overdue')"];
$co_params  = [];

if ($co_search !== '') {
    $co_where[] = '(c.id = ? OR u.full_name LIKE ? OR u.email LIKE ? OR b.title LIKE ?)';
    $co_params[] = is_numeric($co_search) ? (int) $co_search : 0;
    $like = '%' . $co_search . '%';
    $co_params[] = $like; $co_params[] = $like; $co_params[] = $like;
}
if ($co_status === 'active') {
    $co_where[] = "c.status = 'active' AND c.due_date >= NOW()";
} elseif ($co_status === 'due_today') {
    $co_where[] = 'DATE(c.due_date) = CURDATE()';
} elseif ($co_status === 'overdue') {
    $co_where[] = 'c.due_date < NOW()';
}
if ($co_date === 'this_week') {
    $co_where[] = 'YEARWEEK(c.checkout_date, 1) = YEARWEEK(CURDATE(), 1)';
} elseif ($co_date === 'this_month') {
    $co_where[] = 'YEAR(c.checkout_date) = YEAR(CURDATE()) AND MONTH(c.checkout_date) = MONTH(CURDATE())';
}

$co_where_sql = 'WHERE ' . implode(' AND ', $co_where);

// ── Total filtered count (for pagination) ──
$count_stmt = $pdo->prepare(
    "SELECT COUNT(*)
       FROM Circulation c
       JOIN Users u ON u.id = c.user_id
       JOIN Books b ON b.id = c.book_id
       {$co_where_sql}"
);
$count_stmt->execute($co_params);
$co_filtered_total = (int) $count_stmt->fetchColumn();

$co_total_pages = max(1, (int) ceil($co_filtered_total / $co_per_page));
$co_page        = min($co_page, $co_total_pages);
$co_offset      = ($co_page - 1) * $co_per_page;

// ── Fetch page data ──
$data_stmt = $pdo->prepare(
    "SELECT c.id, c.checkout_date, c.due_date, c.status,
            u.full_name AS borrower_name, u.email AS borrower_email,
            b.title AS book_title
       FROM Circulation c
       JOIN Users u ON u.id = c.user_id
       JOIN Books b ON b.id = c.book_id
       {$co_where_sql}
       ORDER BY c.due_date ASC, c.checkout_date DESC
       LIMIT {$co_per_page} OFFSET {$co_offset}"
);
$data_stmt->execute($co_params);
$checked_out_loans = $data_stmt->fetchAll();

// ── Row-level status labels ──
$co_today   = date('Y-m-d');
$co_now_ts  = time();
foreach ($checked_out_loans as &$co_loan) {
    $co_due_ts = strtotime($co_loan['due_date']);
    $co_loan['_is_overdue']   = $co_due_ts < $co_now_ts;
    $co_loan['_is_due_today'] = date('Y-m-d', $co_due_ts) === $co_today;
}
unset($co_loan);

// ── Chip definitions ──
$co_chips = [
    ['key' => 'all',        'label' => 'All',        'count_key' => 'all',        'status' => '',    'date' => ''],
    ['key' => 'active',     'label' => 'Active',      'count_key' => 'active',     'status' => 'active',    'date' => ''],
    ['key' => 'due_today',  'label' => 'Due Today',   'count_key' => 'due_today',  'status' => 'due_today', 'date' => ''],
    ['key' => 'overdue',    'label' => 'Overdue',     'count_key' => 'overdue',    'status' => 'overdue',   'date' => ''],
    ['key' => 'this_week',  'label' => 'This Week',   'count_key' => 'this_week',  'status' => '',    'date' => 'this_week'],
    ['key' => 'this_month', 'label' => 'This Month',  'count_key' => 'this_month', 'status' => '',    'date' => 'this_month'],
];

$is_active_chip = function($key) use ($co_status, $co_date) {
    if ($key === 'all') return $co_status === '' && $co_date === '';
    if (in_array($key, ['active','due_today','overdue'], true)) return $co_status === $key && $co_date === '';
    return $co_status === '' && $co_date === $key;
};

$co_base_url = BASE_URL . 'librarian/checkout.php';
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
          <span class="section-card__title">Approved Reservations Ready for Pickup</span>
          <?php if (!empty($ready_pickups)): ?>
            <span class="badge badge-green"><?= count($ready_pickups) ?></span>
          <?php endif; ?>
        </div>
        <div style="padding: var(--space-6)">
          <?php if (empty($ready_pickups)): ?>
            <p>No approved reservations are waiting for pickup.</p>
          <?php else: ?>
            <div class="tbl-wrapper">
              <table class="tbl">
                <thead>
                  <tr>
                    <th>Borrower</th>
                    <th>Email</th>
                    <th>Book</th>
                    <th>Approved</th>
                    <th>Expires</th>
                    <th>Copies</th>
                    <th>Action</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($ready_pickups as $pickup):
                    $expires_ts = strtotime((string) $pickup['expires_at']);
                    $expires_soon = $expires_ts !== false && $expires_ts > time() && (int) ceil(($expires_ts - time()) / 3600) <= 24;
                    $copies_available = (int) $pickup['available_copies'];
                    $copies_label = $copies_available > 0 ? ($copies_available . ' available') : 'Reserved copy';
                  ?>
                    <tr<?= $expires_soon ? ' style="background:rgba(201,168,76,.1);"' : '' ?>>
                      <td data-label="Borrower"><?= htmlspecialchars((string) $pickup['borrower_name'], ENT_QUOTES, 'UTF-8') ?></td>
                      <td data-label="Email"><?= htmlspecialchars((string) $pickup['borrower_email'], ENT_QUOTES, 'UTF-8') ?></td>
                      <td data-label="Book">
                        <?= htmlspecialchars((string) $pickup['book_title'], ENT_QUOTES, 'UTF-8') ?>
                        <?php if (!empty($pickup['book_author'])): ?>
                          <span style="display:block;color:var(--color-muted);font-size:.85rem;"><?= htmlspecialchars((string) $pickup['book_author'], ENT_QUOTES, 'UTF-8') ?></span>
                        <?php endif; ?>
                      </td>
                      <td data-label="Approved">
                        <?= !empty($pickup['approved_at']) ? htmlspecialchars(date('m-d-y', strtotime((string) $pickup['approved_at'])), ENT_QUOTES, 'UTF-8') : '—' ?>
                      </td>
                      <td data-label="Expires">
                        <strong><?= $expires_ts !== false ? htmlspecialchars(date('m-d-y', $expires_ts), ENT_QUOTES, 'UTF-8') : htmlspecialchars((string) $pickup['expires_at'], ENT_QUOTES, 'UTF-8') ?></strong>
                        <?php if ($expires_soon): ?>
                          <span class="badge badge-amber" style="margin-left:4px;">Expiring soon</span>
                        <?php endif; ?>
                      </td>
                      <td data-label="Copies"><?= htmlspecialchars($copies_label, ENT_QUOTES, 'UTF-8') ?></td>
                      <td data-label="Action">
                        <form method="POST" action="<?= htmlspecialchars(BASE_URL . 'librarian/checkout.php', ENT_QUOTES, 'UTF-8') ?>" class="table-action-form">
                          <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                          <input type="hidden" name="action" value="pickup">
                          <input type="hidden" name="reservation_id" value="<?= (int) $pickup['id'] ?>">
                          <button type="submit" class="btn-confirm">Pickup</button>
                        </form>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>
        </div>
      </div>

      <div class="section-card" style="margin-top: var(--space-6)">
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
              <input type="hidden" name="action" value="checkout">

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

      <!-- ── Checked-Out Books History — Unified Panel ──────────────────── -->
      <div class="section-card co-panel" style="margin-top: var(--space-6)">
        <div class="section-card__header co-panel__header">
          <span class="section-card__title">Checked-Out Books History</span>
          <span class="co-panel__count">
            <strong><?= number_format($co_filtered_total) ?></strong>
            record<?= $co_filtered_total !== 1 ? 's' : '' ?>
          </span>
        </div>

        <!-- Search & Filter Toolbar -->
        <div class="co-toolbar">
          <form method="GET" action="<?= htmlspecialchars($co_base_url, ENT_QUOTES, 'UTF-8') ?>">
            <div class="co-toolbar__row">
              <div class="co-toolbar__search-wrap">
                <input class="co-toolbar__search" type="text" name="co_search"
                       placeholder="Search loan #, borrower, email, or book..."
                       value="<?= htmlspecialchars($co_search, ENT_QUOTES, 'UTF-8') ?>">
              </div>
              <div class="co-toolbar__select-wrap">
                <select name="co_status" class="co-toolbar__select">
                  <option value="">All Status</option>
                  <option value="active" <?= $co_status === 'active' ? 'selected' : '' ?>>Active</option>
                  <option value="due_today" <?= $co_status === 'due_today' ? 'selected' : '' ?>>Due Today</option>
                  <option value="overdue" <?= $co_status === 'overdue' ? 'selected' : '' ?>>Overdue</option>
                </select>
              </div>
              <div class="co-toolbar__select-wrap">
                <select name="co_date" class="co-toolbar__select">
                  <option value="">All Dates</option>
                  <option value="this_week" <?= $co_date === 'this_week' ? 'selected' : '' ?>>This Week</option>
                  <option value="this_month" <?= $co_date === 'this_month' ? 'selected' : '' ?>>This Month</option>
                </select>
              </div>
              <button type="submit" class="btn-primary co-toolbar__btn">Search</button>
              <a href="<?= htmlspecialchars($co_base_url, ENT_QUOTES, 'UTF-8') ?>" class="btn-ghost co-toolbar__btn">Reset</a>
            </div>
          </form>
        </div>

        <!-- Filter Chips -->
        <div class="co-chips">
          <?php foreach ($co_chips as $chip):
            $chip_url = co_filter_url($co_base_url, $co_search, $chip['status'], $chip['date']);
            $chip_count = $co_chip_counts[$chip['count_key']] ?? 0;
          ?>
            <a href="<?= htmlspecialchars($chip_url, ENT_QUOTES, 'UTF-8') ?>"
               class="co-chip <?= $is_active_chip($chip['key']) ? 'co-chip--active' : '' ?>">
              <?= htmlspecialchars($chip['label'], ENT_QUOTES, 'UTF-8') ?>
              <span class="co-chip__count"><?= number_format($chip_count) ?></span>
            </a>
          <?php endforeach; ?>
        </div>

        <!-- Table -->
        <div class="co-table-wrap">
          <?php if (empty($checked_out_loans)): ?>
            <div class="empty-state">
              <span class="empty-state__icon">&#128218;</span>
              <p>No checked-out books found.</p>
            </div>
          <?php else: ?>
            <table class="co-table">
              <thead>
                <tr>
                  <th>Loan #</th>
                  <th>Borrower</th>
                  <th>Email</th>
                  <th>Book Title</th>
                  <th>Check-Out Date</th>
                  <th>Due Date</th>
                  <th>Copies</th>
                  <th>Status</th>
                  <th class="co-actions-th">Action</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($checked_out_loans as $co_loan):
                  $co_id = (int) $co_loan['id'];
                  $co_checkout = !empty($co_loan['checkout_date']) ? date('m-d-y', strtotime($co_loan['checkout_date'])) : '—';
                  $co_due      = !empty($co_loan['due_date'])      ? date('m-d-y', strtotime($co_loan['due_date']))      : '—';
                  if ($co_loan['_is_overdue']) {
                      $co_badge_class = 'co-badge--overdue';
                      $co_status_label = 'Overdue';
                      $co_row_class = 'co-row--overdue';
                  } elseif ($co_loan['_is_due_today']) {
                      $co_badge_class = 'co-badge--due-today';
                      $co_status_label = 'Due Today';
                      $co_row_class = '';
                  } else {
                      $co_badge_class = 'co-badge--active';
                      $co_status_label = 'Active';
                      $co_row_class = '';
                  }
                ?>
                  <tr class="<?= $co_row_class ?>">
                    <td data-label="Loan #"><?= $co_id ?></td>
                    <td data-label="Borrower"><?= htmlspecialchars($co_loan['borrower_name'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td data-label="Email"><?= htmlspecialchars($co_loan['borrower_email'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td data-label="Book Title"><?= htmlspecialchars($co_loan['book_title'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td data-label="Check-Out Date"><?= htmlspecialchars($co_checkout, ENT_QUOTES, 'UTF-8') ?></td>
                    <td data-label="Due Date"><?= htmlspecialchars($co_due, ENT_QUOTES, 'UTF-8') ?></td>
                    <td data-label="Copies">1</td>
                    <td data-label="Status"><span class="co-badge <?= $co_badge_class ?>"><?= $co_status_label ?></span></td>
                    <td data-label="Action" class="co-actions-td">
                      <a class="co-btn-details" href="<?= htmlspecialchars(BASE_URL . 'librarian/checkin.php?loan_id=' . $co_id, ENT_QUOTES, 'UTF-8') ?>">View Details</a>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>

            <!-- Pagination -->
            <div class="co-pagination">
              <div class="co-pagination__info">
                Showing <strong><?= $co_filtered_total > 0 ? number_format($co_offset + 1) : 0 ?></strong>–<strong><?= number_format(min($co_offset + $co_per_page, $co_filtered_total)) ?></strong>
                of <strong><?= number_format($co_filtered_total) ?></strong> record<?= $co_filtered_total !== 1 ? 's' : '' ?>
              </div>
              <?php if ($co_total_pages > 1): ?>
              <nav class="co-pagination__nav" aria-label="Checked-out books pagination">
                <?php if ($co_page > 1): ?>
                  <a class="co-pagination__btn" href="<?= htmlspecialchars(co_filter_url($co_base_url, $co_search, $co_status, $co_date, $co_page - 1), ENT_QUOTES, 'UTF-8') ?>" aria-label="Go to previous page">&larr; Previous</a>
                <?php else: ?>
                  <span class="co-pagination__btn co-pagination__btn--disabled" aria-disabled="true">&larr; Previous</span>
                <?php endif; ?>

                <div class="co-pagination__pages">
                  <?php
                  $co_p_start = max(1, $co_page - 2);
                  $co_p_end   = min($co_total_pages, $co_page + 2);
                  for ($i = $co_p_start; $i <= $co_p_end; $i++):
                  ?>
                    <?php if ($i === $co_page): ?>
                      <span class="co-pagination__current" aria-current="page"><?= (int) $i ?></span>
                    <?php else: ?>
                      <a class="co-pagination__link" href="<?= htmlspecialchars(co_filter_url($co_base_url, $co_search, $co_status, $co_date, $i), ENT_QUOTES, 'UTF-8') ?>"><?= (int) $i ?></a>
                    <?php endif; ?>
                  <?php endfor; ?>
                </div>

                <?php if ($co_page < $co_total_pages): ?>
                  <a class="co-pagination__btn" href="<?= htmlspecialchars(co_filter_url($co_base_url, $co_search, $co_status, $co_date, $co_page + 1), ENT_QUOTES, 'UTF-8') ?>" aria-label="Go to next page">Next &rarr;</a>
                <?php else: ?>
                  <span class="co-pagination__btn co-pagination__btn--disabled" aria-disabled="true">Next &rarr;</span>
                <?php endif; ?>
              </nav>
              <?php endif; ?>
            </div>
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
    });
  </script>
</body>

</html>
