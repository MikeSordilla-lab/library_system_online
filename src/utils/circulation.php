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
