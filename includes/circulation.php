<?php

/**
 * includes/circulation.php — Shared Circulation Helpers
 *
 * Feature: 004-circulation-ops
 *
 * Provides:
 *   get_loan_period()         — Return loan_period_days as int (T003)
 *   get_reservation_expiry()  — Return reservation_expiry_days as int (T004)
 *   log_circulation()         — Insert a System_Logs row for circulation events (T005)
 *
 * Note: get_setting() is provided by includes/settings.php
 *
 * Usage:
 *   require_once __DIR__ . '/circulation.php';
 */

if (defined('CIRCULATION_PHP_LOADED')) {
  return;
}
define('CIRCULATION_PHP_LOADED', true);

// Load settings helper (includes get_setting function)
require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/constants.php';

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

/**
 * Cached Reservations table column existence check.
 *
 * @param PDO $pdo Active PDO connection
 * @param string $column Column name
 * @return bool
 */
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

/**
 * Cached Circulation table column existence check.
 *
 * @param PDO $pdo Active PDO connection
 * @param string $column Column name
 * @return bool
 */
function circulation_column_exists(PDO $pdo, string $column): bool
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
  $stmt = $pdo->query("SHOW COLUMNS FROM Circulation LIKE '{$escapedColumn}'");
  $cache[$column] = $stmt->fetch() !== false;

  return $cache[$column];
}
