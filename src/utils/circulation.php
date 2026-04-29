<?php

/**
 * src/utils/circulation.php — Shared Circulation Helpers (canonical)
 *
 * Feature: 004-circulation-ops
 *
 * Provides:
 *   get_loan_period()              — Return loan_period_days as int (T003)
 *   get_reservation_expiry()       — Return reservation_expiry_days as int (T004)
 *   log_circulation()              — Insert a System_Logs row for circulation events (T005)
 *   get_unpaid_fines_total()       — Return total unpaid fines for a borrower
 *   get_overdue_loan_count()       — Count active/overdue loans past due
 *   expire_stale_reservations()    — Transition stale open reservations to expired
 *   transition_reservation_status()— Conditional reservation state transition
 *   get_pending_reservation_queue()— Fetch pending reservations in FIFO order
 *   reservation_column_exists()    — Cached Reservations column check
 *
 * Note: get_setting() is provided by src/utils/settings.php
 *
 * Usage:
 *   require_once __DIR__ . '/circulation.php';
 */

if (defined('CIRCULATION_PHP_LOADED')) {
  return;
}
define('CIRCULATION_PHP_LOADED', true);

// Load settings + constants helpers (constants brought in via bootstrap; safe guard here too)
require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/../config/constants.php';

/**
 * Return the configured loan period in days.
 * Reads `loan_period_days` from Settings; falls back to 14.
 *
 * @param PDO $pdo Active PDO connection
 * @return int Number of days for a standard loan
 */
function get_loan_period(PDO $pdo): int
{
  return (int) get_setting($pdo, 'loan_period_days', '14');
}

/**
 * Return the configured reservation expiry period in days.
 * Reads `reservation_expiry_days` from Settings; falls back to 7.
 *
 * @param PDO $pdo Active PDO connection
 * @return int Number of days until a reservation expires
 */
function get_reservation_expiry(PDO $pdo): int
{
  return (int) get_setting($pdo, 'reservation_expiry_days', '7');
}

/**
 * Insert a System_Logs row for a circulation event.
 *
 * Required params keys:
 *   actor_id      (int)    — $_SESSION['user_id'] of the acting user
 *   actor_role    (string) — role string, e.g. 'librarian'
 *   action_type   (string) — e.g. 'checkout', 'checkin', 'reservation_place'
 *   target_entity (string) — e.g. 'Circulation', 'Books', 'Users', 'Reservations'
 *   target_id     (int)    — PK of the affected row
 *   outcome       (string) — 'success' or 'failure'
 *
 * @param PDO   $pdo    Active PDO connection (must be inside an open transaction
 *                      when called from within checkout/checkin handlers)
 * @param array $params Associative array of log field values
 *
 * @return void
 */
function log_circulation(PDO $pdo, array $params): void
{
  $stmt = $pdo->prepare(
    'INSERT INTO `System_Logs`
             (`actor_id`, `actor_role`, `action_type`, `target_entity`, `target_id`, `outcome`)
         VALUES (?, ?, ?, ?, ?, ?)'
  );
  $stmt->execute([
    (int)    ($params['actor_id']      ?? 0),
    (string) ($params['actor_role']    ?? ''),
    (string) ($params['action_type']   ?? ''),
    (string) ($params['target_entity'] ?? ''),
    (int)    ($params['target_id']     ?? 0),
    (string) ($params['outcome']       ?? ''),
  ]);
}

/**
 * Return total unpaid fines for a borrower.
 *
 * @param PDO $pdo Active PDO connection
 * @param int $user_id Borrower user ID
 * @return float Total unpaid fines, 0.0 if none
 */
function get_unpaid_fines_total(PDO $pdo, int $user_id): float
{
  $stmt = $pdo->prepare(
    'SELECT COALESCE(SUM(fine_amount), 0) FROM Circulation WHERE user_id = ? AND fine_paid = 0'
  );
  $stmt->execute([$user_id]);
  return (float) $stmt->fetchColumn();
}

/**
 * Count active/overdue loans that are already past due.
 *
 * @param PDO $pdo Active PDO connection
 * @param int $user_id Borrower user ID
 * @return int Number of overdue active/overdue loans
 */
function get_overdue_loan_count(PDO $pdo, int $user_id): int
{
  $stmt = $pdo->prepare(
    'SELECT COUNT(*) FROM Circulation WHERE user_id = ? AND status IN (\'active\', \'overdue\') AND due_date < NOW()'
  );
  $stmt->execute([$user_id]);
  return (int) $stmt->fetchColumn();
}

/**
 * Transition stale open reservations to expired (preferred) or cancelled fallback.
 *
 * @param PDO $pdo Active PDO connection
 * @return int Number of reservations transitioned
 */
function expire_stale_reservations(PDO $pdo): int
{
  $fromStates = reservation_open_statuses();
  $placeholders = implode(', ', array_fill(0, count($fromStates), '?'));

  $setParts = ['status = ?'];
  if (reservation_column_exists($pdo, 'updated_at')) {
    $setParts[] = 'updated_at = NOW()';
  }
  $setClause = implode(', ', $setParts);

  $sql = "UPDATE Reservations
             SET $setClause
            WHERE status IN ($placeholders)
              AND expires_at < NOW()";

  $params = array_merge([RESERVATION_STATUS_EXPIRED], $fromStates);

  try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return (int) $stmt->rowCount();
  } catch (Throwable $e) {
    // Compatibility fallback for legacy status enum schemas that do not include 'expired'.
    $fallback = "UPDATE Reservations
                    SET $setClause
                  WHERE status IN ($placeholders)
                    AND expires_at < NOW()";
    $fallbackStmt = $pdo->prepare($fallback);
    $fallbackStmt->execute(array_merge([RESERVATION_STATUS_CANCELLED], $fromStates));
    return (int) $fallbackStmt->rowCount();
  }
}

/**
 * Perform a conditional reservation state transition with optimistic concurrency.
 *
 * @param PDO    $pdo Active PDO connection
 * @param int    $reservationId Reservation ID
 * @param string $fromStatus Required current status
 * @param string $toStatus New status
 * @param array  $meta Optional actor/reason metadata
 * @return bool True when transitioned, false on state conflict/no row
 */
function transition_reservation_status(
  PDO $pdo,
  int $reservationId,
  string $fromStatus,
  string $toStatus,
  array $meta = []
): bool {
  $setParts = ['status = ?'];
  $params = [$toStatus];

  if (reservation_column_exists($pdo, 'updated_at')) {
    $setParts[] = 'updated_at = NOW()';
  }

  $actorId = (int) ($meta['actor_id'] ?? 0);

  if ($toStatus === RESERVATION_STATUS_APPROVED) {
    if (reservation_column_exists($pdo, 'approved_at')) {
      $setParts[] = 'approved_at = NOW()';
    }
    if (reservation_column_exists($pdo, 'approved_by')) {
      $setParts[] = 'approved_by = ?';
      $params[] = $actorId > 0 ? $actorId : null;
    }
  }

  if ($toStatus === RESERVATION_STATUS_REJECTED) {
    if (reservation_column_exists($pdo, 'rejected_at')) {
      $setParts[] = 'rejected_at = NOW()';
    }
    if (reservation_column_exists($pdo, 'rejected_by')) {
      $setParts[] = 'rejected_by = ?';
      $params[] = $actorId > 0 ? $actorId : null;
    }
    if (reservation_column_exists($pdo, 'rejection_reason') && array_key_exists('rejection_reason', $meta)) {
      $setParts[] = 'rejection_reason = ?';
      $params[] = (string) $meta['rejection_reason'];
    }
  }

  $params[] = $reservationId;
  $params[] = $fromStatus;

  $sql = 'UPDATE Reservations
             SET ' . implode(', ', $setParts) . '
           WHERE id = ? AND status = ?';

  $stmt = $pdo->prepare($sql);
  $stmt->execute($params);
  $changed = $stmt->rowCount() > 0;

  log_circulation($pdo, [
    'actor_id'      => (int) ($meta['actor_id'] ?? 0),
    'actor_role'    => (string) ($meta['actor_role'] ?? ''),
    'action_type'   => (string) ($meta['action_type'] ?? ('reservation_' . $toStatus)),
    'target_entity' => 'Reservations',
    'target_id'     => $reservationId,
    'outcome'       => $changed ? LOG_OUTCOME_SUCCESS : LOG_OUTCOME_FAILURE,
  ]);

  return $changed;
}

/**
 * Fetch pending reservations in strict FIFO order.
 *
 * @param PDO $pdo Active PDO connection
 * @return array<int, array<string, mixed>>
 */
function get_pending_reservation_queue(PDO $pdo): array
{
  $stmt = $pdo->prepare(
    'SELECT r.id, r.user_id, r.book_id, r.status, r.reserved_at, r.expires_at,
            (
              SELECT COUNT(*) + 1
                FROM Reservations q
               WHERE q.book_id = r.book_id
                 AND q.status = ?
                 AND (
                   q.reserved_at < r.reserved_at
                   OR (q.reserved_at = r.reserved_at AND q.id < r.id)
                 )
            ) AS queue_position,
            u.full_name AS borrower_name, u.email AS borrower_email,
            b.title AS book_title, b.author AS book_author
       FROM Reservations r
       JOIN Users u ON r.user_id = u.id
       JOIN Books b ON r.book_id = b.id
       WHERE r.status = ?
       ORDER BY r.reserved_at ASC, r.id ASC'
  );
  $stmt->execute([RESERVATION_STATUS_PENDING, RESERVATION_STATUS_PENDING]);
  return $stmt->fetchAll();
}

// ---------------------------------------------------------------------------
// High-level orchestration — replaces thick page-controller transaction logic
// ---------------------------------------------------------------------------

/**
 * Execute full checkout transaction: create loan, decrement stock, fulfill reservation queue.
 *
 * @param PDO    $pdo
 * @param int    $user_id   Borrower ID
 * @param int    $book_id   Book ID
 * @param int    $actor_id  Librarian/Admin acting user ID
 * @param string $actor_role
 * @return array{success: bool, loan_id: int|null, message: string, receipt_no: string|null}
 */
function perform_checkout(PDO $pdo, int $user_id, int $book_id, int $actor_id, string $actor_role): array
{
  if ($user_id < 1 || $book_id < 1) {
    return ['success' => false, 'loan_id' => null, 'message' => 'Invalid borrower or book selection.', 'receipt_no' => null];
  }

  $pdo->beginTransaction();
  try {
    // Expire stale reservations first
    expire_stale_reservations($pdo);

    // Lock the book row and check availability
    $book_stmt = $pdo->prepare(
      'SELECT id, title, available_copies, total_copies FROM Books WHERE id = ? FOR UPDATE'
    );
    $book_stmt->execute([$book_id]);
    $book = $book_stmt->fetch();

    if (!$book || (int) $book['available_copies'] < 1) {
      log_circulation($pdo, [
        'actor_id' => $actor_id, 'actor_role' => $actor_role,
        'action_type' => 'checkout', 'target_entity' => 'Books',
        'target_id' => $book_id, 'outcome' => 'failure',
      ]);
      $pdo->commit();
      return ['success' => false, 'loan_id' => null, 'message' => 'No available copies for the selected book.', 'receipt_no' => null];
    }

    // Verify borrower exists, is verified, and is not suspended
    $user_stmt = $pdo->prepare(
      'SELECT id FROM Users WHERE id = ? AND role = ? AND is_verified = 1 AND is_suspended = 0 LIMIT 1'
    );
    $user_stmt->execute([$user_id, ROLE_BORROWER]);
    $borrower = $user_stmt->fetch();

    if (!$borrower) {
      log_circulation($pdo, [
        'actor_id' => $actor_id, 'actor_role' => $actor_role,
        'action_type' => 'checkout', 'target_entity' => 'Users',
        'target_id' => $user_id, 'outcome' => 'failure',
      ]);
      $pdo->commit();
      return ['success' => false, 'loan_id' => null, 'message' => 'Borrower not found, is not verified, or is suspended.', 'receipt_no' => null];
    }

    // Check unpaid fines
    $fines_stmt = $pdo->prepare(
      'SELECT SUM(fine_amount) FROM Circulation WHERE user_id = ? AND fine_paid = 0'
    );
    $fines_stmt->execute([$user_id]);
    $unpaid_fines = (float) ($fines_stmt->fetchColumn() ?: 0.0);
    if ($unpaid_fines > 0.0) {
      $pdo->rollBack();
      return ['success' => false, 'loan_id' => null, 'message' => 'Borrower has outstanding unpaid fines ($' . number_format($unpaid_fines, 2) . ') and cannot check out books.', 'receipt_no' => null];
    }

    // Check overdue books
    $overdue_stmt = $pdo->prepare(
      "SELECT COUNT(*) FROM Circulation WHERE user_id = ? AND status IN ('active', 'overdue') AND due_date < NOW()"
    );
    $overdue_stmt->execute([$user_id]);
    if ((int) $overdue_stmt->fetchColumn() > 0) {
      $pdo->rollBack();
      return ['success' => false, 'loan_id' => null, 'message' => 'Borrower has overdue books that must be returned before checking out new ones.', 'receipt_no' => null];
    }

    // Enforce max borrow limit
    $limit_stmt = $pdo->prepare(
      "SELECT COUNT(*) FROM Circulation WHERE user_id = ? AND status IN ('active', 'overdue')"
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
      return ['success' => false, 'loan_id' => null, 'message' => 'Borrower has reached their maximum allowed active loans and reservations (' . $max_limit . ').', 'receipt_no' => null];
    }

    // Check for existing active/overdue loan for same book
    $dup_stmt = $pdo->prepare(
      "SELECT id FROM Circulation WHERE user_id = ? AND book_id = ? AND status IN ('active', 'overdue') LIMIT 1"
    );
    $dup_stmt->execute([$user_id, $book_id]);
    $existing_loan = $dup_stmt->fetch();

    if ($existing_loan) {
      log_circulation($pdo, [
        'actor_id' => $actor_id, 'actor_role' => $actor_role,
        'action_type' => 'checkout', 'target_entity' => 'Circulation',
        'target_id' => (int) $existing_loan['id'], 'outcome' => 'failure',
      ]);
      $pdo->commit();
      return ['success' => false, 'loan_id' => null, 'message' => 'This borrower already has an active loan for this book.', 'receipt_no' => null];
    }

    // Create the loan
    $loan_days = get_loan_period($pdo);
    $ins_stmt = $pdo->prepare(
      'INSERT INTO Circulation (user_id, book_id, due_date, status)
             VALUES (?, ?, NOW() + INTERVAL ? DAY, \'active\')'
    );
    $ins_stmt->execute([$user_id, $book_id, $loan_days]);
    $new_loan_id = (int) $pdo->lastInsertId();

    $upd_stmt = $pdo->prepare('UPDATE Books SET available_copies = available_copies - 1 WHERE id = ?');
    $upd_stmt->execute([$book_id]);

    // Fulfill reservation queue
    $res_pick_stmt = $pdo->prepare(
      'SELECT id, status
         FROM Reservations
        WHERE user_id = ? AND book_id = ? AND status IN (?, ?)
        ORDER BY CASE status WHEN ? THEN 0 ELSE 1 END ASC, reserved_at ASC, id ASC
        LIMIT 1
        FOR UPDATE'
    );
    $res_pick_stmt->execute([$user_id, $book_id, RESERVATION_STATUS_APPROVED, RESERVATION_STATUS_PENDING, RESERVATION_STATUS_APPROVED]);
    $reservationToFulfill = $res_pick_stmt->fetch();

    if ($reservationToFulfill) {
      transition_reservation_status($pdo, (int) $reservationToFulfill['id'], (string) $reservationToFulfill['status'], RESERVATION_STATUS_FULFILLED, [
        'actor_id' => $actor_id, 'actor_role' => $actor_role, 'action_type' => 'reservation_fulfill',
      ]);
    }

    log_circulation($pdo, [
      'actor_id' => $actor_id, 'actor_role' => $actor_role,
      'action_type' => 'checkout', 'target_entity' => 'Circulation',
      'target_id' => $new_loan_id, 'outcome' => 'success',
    ]);

    // Build receipt payload
    $loan_meta_stmt = $pdo->prepare(
      'SELECT c.due_date, b.title, u.full_name
         FROM Circulation c JOIN Books b ON c.book_id = b.id JOIN Users u ON c.user_id = u.id
        WHERE c.id = ? LIMIT 1'
    );
    $loan_meta_stmt->execute([$new_loan_id]);
    $loan_meta = $loan_meta_stmt->fetch();

    $receipt = [];
    if (function_exists('issue_receipt_ticket')) {
      $receipt = issue_receipt_ticket($pdo, [
        'type'            => 'checkout',
        'actor_user_id'   => $actor_id,
        'actor_role'      => $actor_role,
        'patron_user_id'  => $user_id,
        'reference_table' => 'Circulation',
        'reference_id'    => $new_loan_id,
        'format'          => 'thermal',
        'channel'         => 'librarian_console',
        'payload'         => [
          'loan_id'      => $new_loan_id,
          'book_id'      => $book_id,
          'book_title'   => (string) ($loan_meta['title'] ?? ''),
          'borrower_name' => (string) ($loan_meta['full_name'] ?? ''),
          'patron_name'  => (string) ($loan_meta['full_name'] ?? ''),
          'due_date'     => (string) ($loan_meta['due_date'] ?? ''),
          'loan_days'    => $loan_days,
        ],
      ]);
    }

    $pdo->commit();

    return [
      'success'    => true,
      'loan_id'    => $new_loan_id,
      'message'    => 'Checkout completed successfully.',
      'receipt_no' => (string) ($receipt['receipt_no'] ?? ''),
    ];
  } catch (Throwable $e) {
    $pdo->rollBack();
    error_log('[circulation.php] perform_checkout failed: ' . $e->getMessage());
    return ['success' => false, 'loan_id' => null, 'message' => 'An unexpected error occurred.', 'receipt_no' => null];
  }
}

/**
 * Execute full check-in transaction: record return, restore stock, calculate fine, issue receipt.
 *
 * @param PDO    $pdo
 * @param int    $loan_id    Circulation loan ID
 * @param int    $actor_id   Librarian/Admin acting user ID
 * @param string $actor_role
 * @return array{success: bool, message: string, fine: float, receipt_no: string|null}
 */
function perform_checkin(PDO $pdo, int $loan_id, int $actor_id, string $actor_role): array
{
  $loan_stmt = $pdo->prepare(
    'SELECT c.id, c.user_id, c.book_id, c.due_date, c.status,
            b.title AS book_title, u.full_name AS borrower_name
       FROM Circulation c
       JOIN Books  b ON c.book_id = b.id
       JOIN Users  u ON c.user_id = u.id
      WHERE c.id = ? LIMIT 1'
  );
  $loan_stmt->execute([$loan_id]);
  $loan = $loan_stmt->fetch();

  if (!$loan || $loan['status'] === 'returned') {
    return ['success' => false, 'message' => 'Loan not found or already returned.', 'fine' => 0.0, 'receipt_no' => null];
  }

  $return_ts = time();
  $due_ts    = strtotime($loan['due_date']);
  $days_late = max(0, (int) ceil(($return_ts - $due_ts) / 86400));
  $rate      = (float) get_setting($pdo, 'fine_per_day', '0.00');
  $fine      = round($days_late * $rate, 2);

  $pdo->beginTransaction();
  try {
    $upd_loan = $pdo->prepare(
      'UPDATE Circulation SET return_date = NOW(), status = \'returned\', fine_amount = ? WHERE id = ?'
    );
    $upd_loan->execute([$fine, $loan_id]);

    $upd_book = $pdo->prepare(
      'UPDATE Books SET available_copies = LEAST(available_copies + 1, total_copies) WHERE id = ?'
    );
    $upd_book->execute([(int) $loan['book_id']]);

    log_circulation($pdo, [
      'actor_id' => $actor_id, 'actor_role' => $actor_role,
      'action_type' => 'checkin', 'target_entity' => 'Circulation',
      'target_id' => $loan_id, 'outcome' => 'success',
    ]);

    $receipt = [];
    if (function_exists('issue_receipt_ticket')) {
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
          'borrower_name' => (string) ($loan['borrower_name'] ?? ''),
          'patron_name'   => (string) ($loan['borrower_name'] ?? ''),
          'due_date'      => (string) ($loan['due_date'] ?? ''),
          'return_date'   => date('Y-m-d H:i:s'),
          'fine_amount'   => (string) $fine,
          'days_late'     => (string) $days_late,
        ],
      ]);
    }

    $pdo->commit();

    return [
      'success'    => true,
      'message'    => 'Book returned successfully.',
      'fine'       => $fine,
      'receipt_no' => (string) ($receipt['receipt_no'] ?? ''),
    ];
  } catch (Throwable $e) {
    $pdo->rollBack();
    error_log('[circulation.php] perform_checkin failed: ' . $e->getMessage());
    return ['success' => false, 'message' => 'An unexpected error occurred.', 'fine' => 0.0, 'receipt_no' => null];
  }
}

/**
 * Mark all unpaid fines as paid for a borrower within a transaction.
 *
 * @param PDO    $pdo
 * @param int    $user_id    Borrower ID
 * @param int    $actor_id   Acting user ID
 * @param string $actor_role
 * @return array{success: bool, message: string, total_paid: float, receipt_no: string|null}
 */
function pay_user_fines(PDO $pdo, int $user_id, int $actor_id, string $actor_role): array
{
  $stmt = $pdo->prepare(
    "SELECT c.id, c.fine_amount, u.full_name
       FROM Circulation c
       JOIN Users u ON c.user_id = u.id
      WHERE c.user_id = ? AND c.fine_paid = 0 AND c.fine_amount > 0"
  );
  $stmt->execute([$user_id]);
  $loans = $stmt->fetchAll();

  if (empty($loans)) {
    return ['success' => false, 'message' => 'No unpaid fines for this borrower.', 'total_paid' => 0.0, 'receipt_no' => null];
  }

  $total = array_sum(array_column($loans, 'fine_amount'));

  $pdo->beginTransaction();
  try {
    $upd = $pdo->prepare('UPDATE Circulation SET fine_paid = 1 WHERE user_id = ? AND fine_paid = 0 AND fine_amount > 0');
    $upd->execute([$user_id]);

    log_circulation($pdo, [
      'actor_id' => $actor_id, 'actor_role' => $actor_role,
      'action_type' => 'fine_payment', 'target_entity' => 'Circulation',
      'target_id' => $user_id, 'outcome' => 'success',
    ]);

    $receipt = [];
    if (function_exists('issue_receipt_ticket')) {
      $receipt = issue_receipt_ticket($pdo, [
        'type'            => 'fine_payment',
        'actor_user_id'   => $actor_id,
        'actor_role'      => $actor_role,
        'patron_user_id'  => $user_id,
        'reference_table' => 'Circulation',
        'reference_id'    => $user_id,
        'format'          => 'thermal',
        'channel'         => 'librarian_console',
        'payload'         => [
          'total_paid'    => (string) $total,
          'loans_cleared' => count($loans),
          'borrower_name' => (string) ($loans[0]['full_name'] ?? ''),
          'patron_name'   => (string) ($loans[0]['full_name'] ?? ''),
        ],
      ]);
    }

    $pdo->commit();

    return [
      'success'    => true,
      'message'    => 'All fines marked as paid.',
      'total_paid' => $total,
      'receipt_no' => (string) ($receipt['receipt_no'] ?? ''),
    ];
  } catch (Throwable $e) {
    $pdo->rollBack();
    error_log('[circulation.php] pay_user_fines failed: ' . $e->getMessage());
    return ['success' => false, 'message' => 'An unexpected error occurred.', 'total_paid' => 0.0, 'receipt_no' => null];
  }
}

function reservation_column_exists(PDO $pdo, string $column): bool
{
  static $cache = [];
  if (array_key_exists($column, $cache)) {
    return $cache[$column];
  }

  // SHOW COLUMNS does not support bound placeholders on some MariaDB versions.
  if (!preg_match('/\A[A-Za-z0-9_]+\z/', $column)) {
    $cache[$column] = false;
    return false;
  }

  $escapedColumn = str_replace(['\\', "'"], ['\\\\', "\\'"], $column);
  $stmt = $pdo->query("SHOW COLUMNS FROM Reservations LIKE '{$escapedColumn}'");
  $cache[$column] = $stmt->fetch() !== false;

  return $cache[$column];
}
