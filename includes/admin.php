<?php

/**
 * includes/admin.php — Shared Admin Helper Functions
 *
 * Feature: 005-admin-auditing
 *
 * Provides:
 *   count_active_admins()        — Count non-suspended admin accounts (last-Admin guard)
 *   save_setting()               — Update a Settings value and log the change
 *   log_admin_action()           — Insert a System_Logs row for admin write actions
 *   send_account_status_email()  — PHPMailer notification for suspend/reinstate
 *
 * Note: get_setting() is provided by includes/settings.php
 *
 * Usage:
 *   require_once __DIR__ . '/admin.php';
 */

if (defined('ADMIN_PHP_LOADED')) {
  return;
}
define('ADMIN_PHP_LOADED', true);

// Load settings helper (includes get_setting function)
require_once __DIR__ . '/settings.php';

/**
 * Count the number of active (non-suspended) Admin accounts.
 * Used as a guard before demoting or suspending an Admin user.
 *
 * @param PDO $pdo Active PDO connection
 * @return int Number of active Admin accounts
 */
function count_active_admins(PDO $pdo): int
{
  $stmt = $pdo->prepare(
    "SELECT COUNT(*) FROM `Users` WHERE `role` = 'admin' AND `is_suspended` = 0"
  );
  $stmt->execute();
  return (int) $stmt->fetchColumn();
}

/**
 * Ensure superadmin support exists in schema and data.
 *
 * Safe to call multiple times; expensive checks are memoized per request.
 */
function ensure_superadmin_support(PDO $pdo): void
{
  static $initialized = false;
  if ($initialized) {
    return;
  }

  // Add column if it does not exist yet.
  $col_stmt = $pdo->query("SHOW COLUMNS FROM `Users` LIKE 'is_superadmin'");
  if (!$col_stmt->fetch()) {
    $pdo->exec("ALTER TABLE `Users` ADD COLUMN `is_superadmin` TINYINT(1) NOT NULL DEFAULT 0 AFTER `role`");
  }

  // Protect configured superadmin email account when possible.
  if (defined('SUPERADMIN_EMAIL') && SUPERADMIN_EMAIL !== '') {
    $stmt = $pdo->prepare(
      "UPDATE `Users`
          SET `is_superadmin` = 1
        WHERE `email` = ?
          AND `role` = 'admin'"
    );
    $stmt->execute([SUPERADMIN_EMAIL]);
  }

  // Fallback: ensure at least one protected admin exists.
  $count_stmt = $pdo->query("SELECT COUNT(*) FROM `Users` WHERE `role` = 'admin' AND `is_superadmin` = 1");
  $superadmin_count = (int) $count_stmt->fetchColumn();

  if ($superadmin_count === 0) {
    $oldest_admin_stmt = $pdo->query(
      "SELECT `id`
         FROM `Users`
        WHERE `role` = 'admin'
        ORDER BY `created_at` ASC, `id` ASC
        LIMIT 1"
    );
    $oldest_admin_id = (int) $oldest_admin_stmt->fetchColumn();
    if ($oldest_admin_id > 0) {
      $upd = $pdo->prepare("UPDATE `Users` SET `is_superadmin` = 1 WHERE `id` = ?");
      $upd->execute([$oldest_admin_id]);
    }
  }

  $initialized = true;
}

/**
 * Return true if a fetched user row is marked as protected superadmin.
 */
function is_superadmin_user(array $user_row): bool
{
  return isset($user_row['is_superadmin']) && (int) $user_row['is_superadmin'] === 1;
}

/**
 * Update a Settings value and log the change as a System_Logs entry.
 *
 * IMPORTANT: Must be called inside an already-open PDO transaction.
 * The caller is responsible for beginTransaction() and commit()/rollBack().
 *
 * @param PDO    $pdo        Active PDO connection (with open transaction)
 * @param string $key        Settings key to update
 * @param string $new_value  New value to store
 * @param int    $actor_id   Users.id of the admin performing the action
 * @param string $actor_role Role of the actor (always 'admin' in this feature)
 */
function save_setting(PDO $pdo, string $key, string $new_value, int $actor_id, string $actor_role): void
{
  $old_value = get_setting($pdo, $key, '');

  $stmt = $pdo->prepare("UPDATE `Settings` SET `value` = ? WHERE `key` = ?");
  $stmt->execute([$new_value, $key]);

  log_admin_action($pdo, [
    'actor_id'      => $actor_id,
    'actor_role'    => $actor_role,
    'action_type'   => 'settings_update',
    'target_entity' => 'Settings',
    'target_id'     => null,
    'outcome'       => $key . ':' . $old_value . '→' . $new_value,
  ]);
}

/**
 * Insert a System_Logs row for an admin write action.
 *
 * Expected keys in $params:
 *   actor_id      int|null   — Users.id of the acting admin
 *   actor_role    string     — Role snapshot ('admin')
 *   action_type   string     — e.g. 'role_change', 'account_suspend', 'account_reinstate', 'settings_update'
 *   target_entity string|null — 'Users' or 'Settings'
 *   target_id     int|null   — Users.id for user actions; null for settings
 *   outcome       string     — 'success', 'failure:reason', or 'key:old→new'
 *
 * @param PDO   $pdo    Active PDO connection
 * @param array $params Log entry fields (see above)
 */
function log_admin_action(PDO $pdo, array $params): void
{
  $stmt = $pdo->prepare(
    'INSERT INTO `System_Logs`
            (`actor_id`, `actor_role`, `action_type`, `target_entity`, `target_id`, `outcome`)
         VALUES
            (:actor_id, :actor_role, :action_type, :target_entity, :target_id, :outcome)'
  );
  $stmt->execute([
    ':actor_id'      => $params['actor_id']      ?? null,
    ':actor_role'    => $params['actor_role']     ?? null,
    ':action_type'   => $params['action_type'],
    ':target_entity' => $params['target_entity']  ?? null,
    ':target_id'     => $params['target_id']      ?? null,
    ':outcome'       => $params['outcome'],
  ]);
}

/**
 * Change an admin user's password after verifying current password.
 *
 * Returns:
 *   ['ok' => true, 'message' => '...'] on success
 *   ['ok' => false, 'message' => '...'] on validation/DB failure
 */
function change_user_password(PDO $pdo, int $actor_id, string $current_password, string $new_password, string $confirm_password): array
{
  if ($current_password === '' || $new_password === '' || $confirm_password === '') {
    return ['ok' => false, 'message' => 'All password fields are required.'];
  }

  if (strlen($new_password) < 8) {
    return ['ok' => false, 'message' => 'New password must be at least 8 characters.'];
  }

  if (!hash_equals($new_password, $confirm_password)) {
    return ['ok' => false, 'message' => 'New password and confirmation do not match.'];
  }

  try {
    $pdo->beginTransaction();

    $stmt = $pdo->prepare('SELECT id, password_hash FROM Users WHERE id = ? FOR UPDATE');
    $stmt->execute([$actor_id]);
    $user = $stmt->fetch();

    $existing_hash = (string) ($user['password_hash'] ?? '');
    if (!$user || $existing_hash === '' || !password_verify($current_password, $existing_hash)) {
      $pdo->rollBack();
      return ['ok' => false, 'message' => 'Current password is incorrect.'];
    }

    if (password_verify($new_password, $existing_hash)) {
      $pdo->rollBack();
      return ['ok' => false, 'message' => 'New password must be different from current password.'];
    }

    $new_hash = password_hash($new_password, PASSWORD_BCRYPT);
    $upd = $pdo->prepare('UPDATE Users SET password_hash = ?, updated_at = NOW() WHERE id = ? LIMIT 1');
    $upd->execute([$new_hash, $actor_id]);

    log_admin_action($pdo, [
      'actor_id' => $actor_id,
      'actor_role' => 'admin',
      'action_type' => 'password_change',
      'target_entity' => 'Users',
      'target_id' => $actor_id,
      'outcome' => 'success',
    ]);

    $pdo->commit();
    return ['ok' => true, 'message' => 'Password updated successfully.'];
  } catch (PDOException $e) {
    if ($pdo->inTransaction()) {
      $pdo->rollBack();
    }
    error_log('[admin.php] change_user_password() DB error: ' . $e->getMessage());
    return ['ok' => false, 'message' => 'A server error occurred while changing password.'];
  }
}

/**
 * Send a suspension or reinstatement notification email via PHPMailer.
 *
 * @param string $to_email Recipient email address
 * @param string $to_name  Recipient display name
 * @param string $action   'suspended' or 'reinstated'
 * @return bool true on success, false on failure (non-fatal — caller must not roll back)
 */
function send_account_status_email(string $to_email, string $to_name, string $action): bool
{
  try {
    $include_base = dirname(__DIR__) . '/includes/';
    require_once $include_base . 'phpmailer/src/Exception.php';
    require_once $include_base . 'phpmailer/src/PHPMailer.php';
    require_once $include_base . 'phpmailer/src/SMTP.php';

    // Ensure config constants are available
    if (!defined('SMTP_HOST')) {
      require_once dirname(__DIR__) . '/config.php';
    }

    $mail = new \PHPMailer\PHPMailer\PHPMailer(true);

    $mail->isSMTP();
    $mail->Host       = SMTP_HOST;
    $mail->SMTPAuth   = true;
    $mail->Username   = SMTP_USER;
    $mail->Password   = SMTP_PASS;
    $mail->SMTPSecure = SMTP_SECURE;
    $mail->Port       = SMTP_PORT;

    $mail->setFrom(SMTP_FROM_EMAIL, SMTP_FROM_NAME);
    $mail->addAddress($to_email, $to_name);
    $mail->isHTML(true);

    $safe_name = htmlspecialchars($to_name, ENT_QUOTES, 'UTF-8');

    if ($action === 'suspended') {
      $mail->Subject = 'Your Library System account has been suspended';
      $mail->Body    = '<!DOCTYPE html><html><body>'
        . '<p>Hello ' . $safe_name . ',</p>'
        . '<p>Your Library System account has been <strong>suspended</strong> by an administrator.</p>'
        . '<p>You will not be able to log in until your account is reinstated. '
        . 'Please contact the library if you believe this is in error.</p>'
        . '</body></html>';
      $mail->AltBody = "Hello {$to_name},\n\nYour Library System account has been suspended by an administrator.\n"
        . "You will not be able to log in until your account is reinstated.\n"
        . "Please contact the library if you believe this is in error.";
    } else {
      // reinstated
      $mail->Subject = 'Your Library System account has been reinstated';
      $mail->Body    = '<!DOCTYPE html><html><body>'
        . '<p>Hello ' . $safe_name . ',</p>'
        . '<p>Your Library System account has been <strong>reinstated</strong>. '
        . 'You may now log in normally.</p>'
        . '</body></html>';
      $mail->AltBody = "Hello {$to_name},\n\nYour Library System account has been reinstated.\n"
        . "You may now log in normally.";
    }

    $mail->send();
    return true;
  } catch (\PHPMailer\PHPMailer\Exception $e) {
    error_log('[admin.php] send_account_status_email() PHPMailer failed for ' . $to_email . ': ' . $e->getMessage());
    return false;
  } catch (\Throwable $e) {
    error_log('[admin.php] send_account_status_email() error for ' . $to_email . ': ' . $e->getMessage());
    return false;
  }
}
