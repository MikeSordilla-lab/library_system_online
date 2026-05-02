<?php

/**
 * borrower/renew.php — Handle Book Renewal Requests (US5, FR-018)
 *
 * POST: Process loan renewal
 *   - Verify borrower owns the loan
 *   - Check loan status is active or overdue
 *   - Check no pending reservations from OTHER users for this book
 *   - Extend due date by loan period
 *   - Log the renewal action
 *
 * GET: Redirect to borrower dashboard
 *
 * Protected: Borrower role only.
 */

// RBAC guard
$allowed_roles = ['borrower'];
require_once __DIR__ . '/../includes/auth_guard.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/circulation.php';

$pdo = get_db();

// Only accept POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . BASE_URL . 'borrower/index.php');
    exit;
}

csrf_verify();

$user_id = (int) $_SESSION['user_id'];
$actor_role = (string) $_SESSION['role'];
$loan_id = (int) ($_POST['loan_id'] ?? 0);

// Validation
if ($loan_id < 1) {
    $_SESSION['flash_error'] = 'Invalid loan reference.';
    header('Location: ' . BASE_URL . 'borrower/index.php');
    exit;
}

// Max renewals allowed (US5: limit to single renewal per loan)
define('MAX_RENEWALS', 1);

try {
    $has_renewal_count = circulation_column_exists($pdo, 'renewal_count');

    $pdo->beginTransaction();

    $renewal_select = $has_renewal_count ? ', c.renewal_count' : '';

    // Fetch the loan with lock
    $loan_stmt = $pdo->prepare(
        'SELECT c.id, c.user_id, c.book_id, c.due_date, c.status' . $renewal_select . ',
                b.title
         FROM Circulation c
         JOIN Books b ON c.book_id = b.id
         WHERE c.id = ?
         FOR UPDATE'
    );
    $loan_stmt->execute([$loan_id]);
    $loan = $loan_stmt->fetch();

    // Check loan exists and belongs to user
    if (!$loan) {
        $pdo->rollBack();
        $_SESSION['renewal_block'] = [
            'title' => 'Renewal blocked',
            'message' => 'Loan not found. Please try again.',
        ];
        header('Location: ' . BASE_URL . 'borrower/index.php');
        exit;
    }

    if ((int) $loan['user_id'] !== $user_id) {
        log_circulation($pdo, [
            'actor_id'      => $user_id,
            'actor_role'    => $actor_role,
            'action_type'   => 'renewal',
            'target_entity' => 'Circulation',
            'target_id'     => $loan_id,
            'outcome'       => 'failure:unauthorized',
        ]);
        $pdo->rollBack();
        $_SESSION['renewal_block'] = [
            'title' => 'Renewal blocked',
            'message' => 'You do not have permission to renew this loan.',
        ];
        header('Location: ' . BASE_URL . 'borrower/index.php');
        exit;
    }

    // Block Delinquent Borrowers (Unpaid Fines)
    $unpaid_fines = get_unpaid_fines_total($pdo, $user_id);

    if ($unpaid_fines > 0.0) {
      $pdo->rollBack();
      $modal_message = 'You have outstanding unpaid fines (₱' . number_format($unpaid_fines, 2) . '). Please return any overdue books and settle the fines before renewing.';
      $_SESSION['renewal_block'] = [
        'title' => 'Renewal blocked',
        'message' => $modal_message,
      ];
      header('Location: ' . BASE_URL . 'borrower/index.php');
      exit;
    }

    $due_ts = strtotime($loan['due_date']);
    $is_overdue = ($loan['status'] === 'overdue') || ($loan['status'] === 'active' && $due_ts !== false && $due_ts < time());

    if ($is_overdue) {
        $pdo->rollBack();
        $modal_message = 'This loan is overdue. Please return the book and pay the overdue fines before renewing.';
        $_SESSION['renewal_block'] = [
            'title' => 'Renewal blocked',
            'message' => $modal_message,
        ];
        header('Location: ' . BASE_URL . 'borrower/index.php');
        exit;
    }

    // Check if the current loan is active
    if ($loan['status'] !== 'active') {
        $pdo->rollBack();
        $_SESSION['renewal_block'] = [
            'title' => 'Renewal blocked',
            'message' => 'This loan cannot be renewed (already returned or inactive).',
        ];
        header('Location: ' . BASE_URL . 'borrower/index.php');
        exit;
    }

    // Check renewal limit (gracefully handle if column doesn't exist)
    $current_renewals = ($has_renewal_count && isset($loan['renewal_count'])) ? (int) $loan['renewal_count'] : 0;
    if ($current_renewals >= MAX_RENEWALS) {
        $pdo->rollBack();
        $_SESSION['renewal_block'] = [
            'title' => 'Renewal blocked',
            'message' => 'You have reached the maximum renewal limit (1 time) for this book.',
        ];
        header('Location: ' . BASE_URL . 'borrower/index.php');
        exit;
    }

    // Renewal is only allowed within 1 day before the due date
    $due_ts_check = strtotime($loan['due_date']);
    $one_day_before_due = $due_ts_check - 86400; // 24 hours before due
    if (time() < $one_day_before_due) {
        $pdo->rollBack();
        $days_until_due = (int) ceil(($due_ts_check - time()) / 86400);
        $_SESSION['renewal_block'] = [
            'title' => 'Renewal blocked',
            'message' => 'This book can only be renewed within 1 day before its due date. You still have ' . $days_until_due . ' day' . ($days_until_due !== 1 ? 's' : '') . ' remaining. Please try again closer to the due date.',
        ];
        header('Location: ' . BASE_URL . 'borrower/index.php');
        exit;
    }

    // Check for pending reservations from OTHER users
    $res_stmt = $pdo->prepare(
        'SELECT id, user_id FROM Reservations
         WHERE book_id = ? AND status = \'pending\' AND user_id != ?
         LIMIT 1'
    );
    $res_stmt->execute([(int) $loan['book_id'], $user_id]);
    $other_reservation = $res_stmt->fetch();

    if ($other_reservation) {
        $pdo->rollBack();
        $_SESSION['renewal_block'] = [
            'title' => 'Renewal blocked',
            'message' => 'Cannot renew: this book has been reserved by another member.',
        ];
        header('Location: ' . BASE_URL . 'borrower/index.php');
        exit;
    }

    // Get loan period from settings
    $loan_days = get_loan_period($pdo);

    // Calculate new due date
    $current_due = new DateTime($loan['due_date']);
    $new_due = $current_due->add(new DateInterval('P' . $loan_days . 'D'));
    $new_due_date = $new_due->format('Y-m-d H:i:s');

    // Update the loan - handle renewal_count column gracefully
    $update_sql = 'UPDATE Circulation
         SET due_date = ?,
             status = \'active\'
         WHERE id = ?';
    $update_stmt = $pdo->prepare($update_sql);
    $update_stmt->execute([$new_due_date, $loan_id]);

    if ($has_renewal_count) {
        $renew_stmt = $pdo->prepare('UPDATE Circulation SET renewal_count = renewal_count + 1 WHERE id = ?');
        $renew_stmt->execute([$loan_id]);
    }

    // Log the renewal
    log_circulation($pdo, [
        'actor_id'      => $user_id,
        'actor_role'    => $actor_role,
        'action_type'   => 'renewal',
        'target_entity' => 'Circulation',
        'target_id'     => $loan_id,
        'outcome'       => 'success',
    ]);

    $pdo->commit();

    $_SESSION['flash_success'] = 'Book renewed successfully! New due date: ' . date('M d, Y', strtotime($new_due_date));

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('[renew.php] Renewal failed: ' . $e->getMessage());
    $_SESSION['renewal_block'] = [
        'title' => 'Renewal blocked',
        'message' => 'An unexpected error occurred. Please try again.',
    ];
}

header('Location: ' . BASE_URL . 'borrower/index.php');
exit;