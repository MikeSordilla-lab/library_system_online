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

function circulation_currency_code(PDO $pdo): string
{
  $code = strtoupper(trim(get_setting($pdo, 'currency_code', 'PHP')));
  return $code !== '' ? $code : 'PHP';
}

function circulation_currency_symbol(PDO $pdo): string
{
  $code = circulation_currency_code($pdo);
  $symbols = [
    'PHP' => '₱',
    'USD' => '$',
  ];
  return $symbols[$code] ?? ($code . ' ');
}

function format_circulation_money(PDO $pdo, float $amount): string
{
  return circulation_currency_symbol($pdo) . number_format($amount, 2);
}

function circulation_report_type_options(): array
{
  return [
    'circulation_records' => 'Circulation Records',
    'returned_books' => 'Returned Books',
    'overdue_records' => 'Overdue Records',
    'fines_report' => 'Fines Report',
    'borrower_history' => 'Borrower History',
  ];
}

function circulation_normalize_report_type(string $type): string
{
  return array_key_exists($type, circulation_report_type_options()) ? $type : 'circulation_records';
}

function circulation_report_type_label(string $type): string
{
  $options = circulation_report_type_options();
  return $options[circulation_normalize_report_type($type)];
}

function circulation_status_label(string $status): string
{
  $labels = [
    CIRCULATION_STATUS_ACTIVE => 'Active',
    CIRCULATION_STATUS_OVERDUE => 'Overdue',
    CIRCULATION_STATUS_RETURNED => 'Returned',
    CIRCULATION_STATUS_RETURNED_LATE => 'Returned Late',
  ];
  return $labels[$status] ?? ucfirst(str_replace('_', ' ', $status));
}

function circulation_late_days(string $dueDate, ?string $returnDate = null, ?DateTimeImmutable $now = null): int
{
  if (trim($dueDate) === '') {
    return 0;
  }

  try {
    $due = new DateTimeImmutable($dueDate);
    $end = trim((string) $returnDate) !== '' ? new DateTimeImmutable((string) $returnDate) : ($now ?? new DateTimeImmutable('now'));
  } catch (Throwable $e) {
    return 0;
  }

  $lateSeconds = $end->getTimestamp() - $due->getTimestamp();
  if ($lateSeconds <= 0) {
    return 0;
  }

  return (int) ceil($lateSeconds / SECONDS_PER_DAY);
}

function circulation_effective_status(array $record, ?DateTimeImmutable $now = null): string
{
  $dueDate = (string) ($record['due_date'] ?? '');
  $returnDate = trim((string) ($record['return_date'] ?? ''));
  $storedStatus = (string) ($record['status'] ?? CIRCULATION_STATUS_ACTIVE);

  if ($returnDate !== '') {
    return circulation_late_days($dueDate, $returnDate, $now) > 0
      ? CIRCULATION_STATUS_RETURNED_LATE
      : CIRCULATION_STATUS_RETURNED;
  }

  if ($dueDate !== '' && circulation_late_days($dueDate, null, $now) > 0) {
    return CIRCULATION_STATUS_OVERDUE;
  }

  return in_array($storedStatus, [CIRCULATION_STATUS_ACTIVE, CIRCULATION_STATUS_OVERDUE, CIRCULATION_STATUS_RETURNED], true)
    ? $storedStatus
    : CIRCULATION_STATUS_ACTIVE;
}

function circulation_calculated_fine(int $daysLate, float $dailyRate): float
{
  return round(max(0, $daysLate) * max(0.0, $dailyRate), 2);
}

function circulation_enrich_report_record(array $record, float $dailyRate, ?DateTimeImmutable $now = null): array
{
  $effectiveStatus = circulation_effective_status($record, $now);
  $returnDate = trim((string) ($record['return_date'] ?? ''));
  $daysLate = circulation_late_days((string) ($record['due_date'] ?? ''), $returnDate !== '' ? $returnDate : null, $now);
  $fine = circulation_calculated_fine($daysLate, $dailyRate);

  $record['effective_status'] = $effectiveStatus;
  $record['status_label'] = circulation_status_label($effectiveStatus);
  $record['days_late'] = $daysLate;
  $record['daily_fine_rate'] = $dailyRate;
  $record['calculated_fine'] = $fine;
  $record['fine_breakdown'] = $daysLate . ' day' . ($daysLate === 1 ? '' : 's') . ' late x ' . number_format($dailyRate, 2) . ' = ' . number_format($fine, 2);

  return $record;
}

function circulation_report_summary(array $records): array
{
  $summary = [
    'total_records' => count($records),
    'active' => 0,
    'overdue' => 0,
    'returned' => 0,
    'returned_late' => 0,
    'total_fines' => 0.0,
    'collected_fines' => 0.0,
    'unpaid_fines' => 0.0,
  ];

  foreach ($records as $record) {
    $status = (string) ($record['effective_status'] ?? $record['status'] ?? '');
    if (array_key_exists($status, $summary)) {
      $summary[$status]++;
    }

    $fine = (float) ($record['calculated_fine'] ?? $record['fine_amount'] ?? 0);
    $summary['total_fines'] += $fine;

    if ((int) ($record['fine_paid'] ?? 0) === 1) {
      $summary['collected_fines'] += $fine;
    } else {
      $summary['unpaid_fines'] += $fine;
    }
  }

  return $summary;
}

function circulation_record_matches_report_type(array $record, string $reportType): bool
{
  $status = (string) ($record['effective_status'] ?? '');

  switch (circulation_normalize_report_type($reportType)) {
    case 'returned_books':
      return in_array($status, [CIRCULATION_STATUS_RETURNED, CIRCULATION_STATUS_RETURNED_LATE], true);
    case 'overdue_records':
      return $status === CIRCULATION_STATUS_OVERDUE;
    case 'fines_report':
      return (float) ($record['calculated_fine'] ?? 0) > 0.0;
    case 'borrower_history':
    case 'circulation_records':
    default:
      return true;
  }
}

/**
 * Mark only returned loans with positive unpaid fines as paid.
 *
 * @return array{total: float, rows: int}
 */
function mark_returned_positive_fines_paid(PDO $pdo, int $userId): array
{
  if (!$pdo->inTransaction()) {
    throw new RuntimeException('mark_returned_positive_fines_paid must be called inside an active transaction.');
  }

  $selectStmt = $pdo->prepare(
    "SELECT id, fine_amount
       FROM Circulation
      WHERE user_id = ?
        AND status = 'returned'
        AND fine_paid = 0
        AND fine_amount > 0
      FOR UPDATE"
  );
  $selectStmt->execute([$userId]);
  $fineRows = $selectStmt->fetchAll();

  $total = 0.0;
  $fineIds = [];
  foreach ($fineRows as $row) {
    $total += (float) $row['fine_amount'];
    $fineIds[] = (int) $row['id'];
  }

  if ($total <= 0.0) {
    return ['total' => 0.0, 'rows' => 0];
  }

  $placeholders = implode(', ', array_fill(0, count($fineIds), '?'));
  $upd = $pdo->prepare(
    "UPDATE Circulation
        SET fine_paid = 1,
            fine_paid_at = NOW()
      WHERE id IN ($placeholders)
        AND fine_paid = 0"
  );
  $upd->execute($fineIds);

  return ['total' => $total, 'rows' => $upd->rowCount()];
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
 * Return whether a checkout can proceed under reservation rules.
 *
 * @return array{allowed: bool, reason_code: string|null, reservation_id?: int}
 */
function checkout_reservation_policy(PDO $pdo, int $userId, int $bookId): array
{
  if (!$pdo->inTransaction()) {
    throw new RuntimeException('checkout_reservation_policy must be called inside an active transaction.');
  }

  $orderSql = reservation_column_exists($pdo, 'approved_at')
    ? 'approved_at ASC, reserved_at ASC, id ASC'
    : 'reserved_at ASC, id ASC';

  $stmt = $pdo->prepare(
    "SELECT id, user_id, status
       FROM Reservations
      WHERE book_id = ?
        AND status = ?
      ORDER BY {$orderSql}
      LIMIT 1
      FOR UPDATE"
  );
  $stmt->execute([$bookId, RESERVATION_STATUS_APPROVED]);
  $approved = $stmt->fetch();

  if ($approved && (int) $approved['user_id'] !== $userId) {
    return [
      'allowed' => false,
      'reason_code' => 'reserved_for_another_borrower',
      'reservation_id' => (int) $approved['id'],
    ];
  }

  return [
    'allowed' => true,
    'reason_code' => null,
    'reservation_id' => $approved ? (int) $approved['id'] : null,
  ];
}

function checkout_reservation_to_fulfill(PDO $pdo, int $userId, int $bookId): ?array
{
  if (!$pdo->inTransaction()) {
    throw new RuntimeException('checkout_reservation_to_fulfill must be called inside an active transaction.');
  }

  $orderSql = reservation_column_exists($pdo, 'approved_at')
    ? 'approved_at ASC, reserved_at ASC, id ASC'
    : 'reserved_at ASC, id ASC';

  $stmt = $pdo->prepare(
    "SELECT id, status
       FROM Reservations
      WHERE user_id = ?
        AND book_id = ?
        AND status = ?
      ORDER BY {$orderSql}
      LIMIT 1
      FOR UPDATE"
  );
  $stmt->execute([$userId, $bookId, RESERVATION_STATUS_APPROVED]);
  $row = $stmt->fetch();
  return $row === false ? null : $row;
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

function complete_checkin_loan(PDO $pdo, int $loanId, float $fine): bool
{
  $finePaid = $fine > 0.0 ? 0 : 1;
  $finePaidAtSql = $fine > 0.0 ? 'NULL' : 'NOW()';

  $stmt = $pdo->prepare(
    "UPDATE Circulation
        SET return_date = NOW(),
            status = 'returned',
            fine_amount = ?,
            fine_paid = ?,
            fine_paid_at = {$finePaidAtSql}
      WHERE id = ?
        AND status IN ('active', 'overdue')"
  );
  $stmt->execute([$fine, $finePaid, $loanId]);

  return $stmt->rowCount() === 1;
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
