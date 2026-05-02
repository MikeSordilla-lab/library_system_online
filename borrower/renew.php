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
require_once __DIR__ . '/../includes/RenewalEligibilityModule.php';

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

// Validate
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

    $check = RenewalEligibilityModule::check($pdo, $user_id, $loan_id);

    if (!$check['eligible']) {
        $pdo->rollBack();
        $_SESSION['renewal_block'] = [
            'title' => 'Renewal blocked',
            'message' => $check['reason'] ?? 'This loan cannot be renewed at this time.',
        ];
        header('Location: ' . BASE_URL . 'borrower/index.php');
        exit;
    }

    $new_due_date = $check['new_due_date'];

    // Get loan period from settings
    $loan_days = get_loan_period($pdo);

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