<?php

/**
 * includes/db.php — Shared PDO connection helper
 *
 * Usage: require_once __DIR__ . '/../includes/db.php';
 *        $pdo = get_db();
 *
 * Returns a singleton PDO instance configured with utf8mb4, ERRMODE_EXCEPTION,
 * FETCH_ASSOC, and EMULATE_PREPARES=false — matching the settings verified in
 * Feature 001 (index.php).
 *
 * All 13 constants are sourced from config.php (never hard-coded here).
 */

if (!defined('DB_HOST')) {
  // config.php not yet loaded — locate it relative to this file
  $config_path = __DIR__ . '/../config.php';
  if (!file_exists($config_path)) {
    error_log('[db.php] config.php not found at ' . $config_path);
    http_response_code(500);
    exit('Server configuration error. Please contact the administrator.');
  }
  require_once $config_path;
}

function get_db(): PDO
{
  static $pdo = null;
  if ($pdo === null) {
    if (DB_HOST === '' || DB_NAME === '' || DB_USER === '') {
      error_log('[db.php] Database configuration is incomplete.');
      http_response_code(500);
      exit('Database configuration error. Please update your server settings.');
    }

    $dsn = 'mysql:host=' . DB_HOST
      . ';port=' . DB_PORT
      . ';dbname=' . DB_NAME
      . ';charset=utf8mb4';
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
      PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
      PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
      PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
  }
  return $pdo;
}

/**
 * log_event — Insert a System_Logs row.
 *
 * @param PDO         $pdo            Active PDO connection
 * @param string      $action_type    e.g. 'REGISTER', 'LOGIN_SUCCESS' …
 * @param int|null    $actor_id       Users.id of the acting user, or NULL for anonymous
 * @param string|null $target_entity  e.g. 'Users', or NULL
 * @param int|null    $target_id      PK of the target row, or NULL
 * @param string      $outcome        'SUCCESS' | 'FAILURE' | 'INFO'
 * @param string|null $actor_role     Role of the acting user ('admin', 'librarian', 'borrower'), or NULL
 * @param string|null $email_address  Email address associated with the event (for audit trail)
 */
function log_event(
  PDO     $pdo,
  string  $action_type,
  ?int    $actor_id,
  ?string $target_entity,
  ?int    $target_id,
  string  $outcome,
  ?string $actor_role = null,
  ?string $email_address = null
): void {
  $stmt = $pdo->prepare(
    'INSERT INTO System_Logs
         (action_type, actor_id, actor_role, target_entity, target_id, email_address, outcome)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
  );
  $stmt->execute([
    $action_type,
    $actor_id,
    $actor_role,
    $target_entity,
    $target_id,
    $email_address,
    $outcome,
  ]);
}
