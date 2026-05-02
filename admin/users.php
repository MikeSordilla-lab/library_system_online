<?php

/**
 * admin/users.php — User Management
 *
 * Features:
 * - Create users with role assignment
 * - Change role
 * - Deactivate / Activate
 * - Delete user (safe checks)
 * - Superadmin protection (cannot be role-changed, deactivated, or deleted)
 */

$allowed_roles = ['admin'];
require_once __DIR__ . '/../includes/auth_guard.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/admin.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/avatar.php';

$pdo = get_db();
ensure_superadmin_support($pdo);

$actor_id = (int) ($_SESSION['user_id'] ?? 0);
$self_url = BASE_URL . 'admin/users.php';
$current_page = 'admin.users';
$pageTitle = 'User Management | Library System';

$flash_success = '';
$flash_error = '';
$undo_action = null;
$create_user_error = '';
$create_user_old = [
  'full_name' => '',
  'email' => '',
  'role' => '',
  'is_verified' => 0,
];

if (!empty($_SESSION['flash_success'])) {
  $flash_success = (string) $_SESSION['flash_success'];
  unset($_SESSION['flash_success']);
}
if (!empty($_SESSION['flash_error_msg'])) {
  $flash_error = (string) $_SESSION['flash_error_msg'];
  unset($_SESSION['flash_error_msg']);
}

if (!empty($_SESSION['users_create_old']) && is_array($_SESSION['users_create_old'])) {
  $candidate_old = $_SESSION['users_create_old'];
  $create_user_old['full_name'] = trim((string) ($candidate_old['full_name'] ?? ''));
  $create_user_old['email'] = trim((string) ($candidate_old['email'] ?? ''));
  $create_user_old['role'] = trim((string) ($candidate_old['role'] ?? ''));
  $create_user_old['is_verified'] = !empty($candidate_old['is_verified']) ? 1 : 0;
}

if (!empty($_SESSION['users_create_error'])) {
  $create_user_error = (string) $_SESSION['users_create_error'];
}

unset($_SESSION['users_create_old'], $_SESSION['users_create_error']);

if (!in_array($create_user_old['role'], ['admin', 'librarian', 'borrower'], true)) {
  $create_user_old['role'] = '';
}

$role_meta = [
  'admin' => [
    'label' => 'Admin',
    'description' => 'Manage users and system settings.',
    'requires_verified' => true,
  ],
  'librarian' => [
    'label' => 'Librarian',
    'description' => 'Manage catalog, borrowing, and circulation tasks.',
    'requires_verified' => true,
  ],
  'borrower' => [
    'label' => 'Borrower',
    'description' => 'Search catalog, borrow books, and manage reservations.',
    'requires_verified' => false,
  ],
];

$role_requires_verification = static function (string $role) use ($role_meta): bool {
  return !empty($role_meta[$role]['requires_verified']);
};

if ($create_user_old['role'] !== '' && $role_requires_verification($create_user_old['role'])) {
  $create_user_old['is_verified'] = 1;
}

$persist_create_user_state = static function (string $error_message, array $old_input): void {
  $_SESSION['users_create_error'] = $error_message;
  $_SESSION['users_create_old'] = [
    'full_name' => trim((string) ($old_input['full_name'] ?? '')),
    'email' => trim((string) ($old_input['email'] ?? '')),
    'role' => trim((string) ($old_input['role'] ?? '')),
    'is_verified' => !empty($old_input['is_verified']) ? 1 : 0,
  ];
};

if (!empty($_SESSION['users_undo']) && is_array($_SESSION['users_undo'])) {
  $candidate_undo = $_SESSION['users_undo'];
  $undo_expires = (int) ($candidate_undo['expires_at'] ?? 0);
  if ($undo_expires > time()) {
    $undo_action = $candidate_undo;
  } else {
    unset($_SESSION['users_undo']);
  }
}

$show_deactivate_warning = false;
$warn_user = null;
$warn_loan_count = 0;
$focus_user_id = 0;

if (!empty($_SESSION['users_focus_user_id'])) {
  $focus_candidate = (int) $_SESSION['users_focus_user_id'];
  if ($focus_candidate > 0) {
    $focus_user_id = $focus_candidate;
  }
  unset($_SESSION['users_focus_user_id']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_verify();

  $post_action = $_POST['action'] ?? '';
  $allowed_roles_list = ['admin', 'librarian', 'borrower'];

  $posted_focus_user_id = (int) ($_POST['user_id'] ?? 0);
  if ($posted_focus_user_id > 0) {
    $_SESSION['users_focus_user_id'] = $posted_focus_user_id;
  }

  // -------------------------------------------------------------------------
  // Undo Last Change
  // -------------------------------------------------------------------------
  if ($post_action === 'undo_last_user_change') {
    $undo_token = (string) ($_POST['undo_token'] ?? '');
    $stored_undo = $_SESSION['users_undo'] ?? null;

    if (!is_array($stored_undo) || $undo_token === '' || !hash_equals((string) ($stored_undo['token'] ?? ''), $undo_token)) {
      $_SESSION['flash_error_msg'] = 'Undo is no longer available for that action.';
      header('Location: ' . $self_url);
      exit;
    }

    if ((int) ($stored_undo['expires_at'] ?? 0) <= time()) {
      unset($_SESSION['users_undo']);
      $_SESSION['flash_error_msg'] = 'Undo window expired. Please make a new change if needed.';
      header('Location: ' . $self_url);
      exit;
    }

    $undo_type = (string) ($stored_undo['type'] ?? '');
    $undo_user_id = (int) ($stored_undo['user_id'] ?? 0);
    $previous_role = (string) ($stored_undo['previous_role'] ?? '');
    $previous_status = (int) ($stored_undo['previous_status'] ?? -1);

    try {
      $pdo->beginTransaction();

      $stmt = $pdo->prepare('SELECT id, role, is_verified, is_suspended, is_superadmin FROM Users WHERE id = ? FOR UPDATE');
      $stmt->execute([$undo_user_id]);
      $target = $stmt->fetch();

      if (!$target) {
        $pdo->rollBack();
        unset($_SESSION['users_undo']);
        $_SESSION['flash_error_msg'] = 'The account for that undo action no longer exists.';
        header('Location: ' . $self_url);
        exit;
      }

      if (is_superadmin_user($target)) {
        $pdo->rollBack();
        unset($_SESSION['users_undo']);
        $_SESSION['flash_error_msg'] = 'Protected accounts cannot be changed by undo.';
        header('Location: ' . $self_url);
        exit;
      }

      if ($undo_type === 'role_change' && in_array($previous_role, $allowed_roles_list, true)) {
        if ((string) $target['role'] === 'admin' && $previous_role !== 'admin') {
          $active_admin_count = count_active_admins($pdo);
          if ($active_admin_count <= 1) {
            $pdo->rollBack();
            $_SESSION['flash_error_msg'] = 'Undo blocked: this would demote the last active admin account.';
            header('Location: ' . $self_url);
            exit;
          }
        }

        $upd = $pdo->prepare('UPDATE Users SET role = ? WHERE id = ? LIMIT 1');
        $upd->execute([$previous_role, $undo_user_id]);

        log_admin_action($pdo, [
          'actor_id' => $actor_id,
          'actor_role' => 'admin',
          'action_type' => 'role_change_undo',
          'target_entity' => 'Users',
          'target_id' => $undo_user_id,
          'outcome' => 'success',
        ]);
      } elseif ($undo_type === 'status_change' && ($previous_status === 0 || $previous_status === 1)) {
        if ($previous_status === 1 && (string) $target['role'] === 'admin' && (int) $target['is_suspended'] === 0) {
          $active_admin_count = count_active_admins($pdo);
          if ($active_admin_count <= 1) {
            $pdo->rollBack();
            $_SESSION['flash_error_msg'] = 'Undo blocked: this would deactivate the last active admin account.';
            header('Location: ' . $self_url);
            exit;
          }
        }

        $upd = $pdo->prepare('UPDATE Users SET is_suspended = ? WHERE id = ? LIMIT 1');
        $upd->execute([$previous_status, $undo_user_id]);

        log_admin_action($pdo, [
          'actor_id' => $actor_id,
          'actor_role' => 'admin',
          'action_type' => 'account_status_undo',
          'target_entity' => 'Users',
          'target_id' => $undo_user_id,
          'outcome' => 'success',
        ]);
      } else {
        $pdo->rollBack();
        unset($_SESSION['users_undo']);
        $_SESSION['flash_error_msg'] = 'Undo data is invalid or unavailable.';
        header('Location: ' . $self_url);
        exit;
      }

      $pdo->commit();
      unset($_SESSION['users_undo']);
      $_SESSION['flash_success'] = 'Last change was undone.';
    } catch (PDOException $e) {
      if ($pdo->inTransaction()) {
        $pdo->rollBack();
      }
      error_log('[admin/users.php] undo DB error: ' . $e->getMessage());
      $_SESSION['flash_error_msg'] = 'Undo could not be completed right now. Please try again.';
    }

    header('Location: ' . $self_url);
    exit;
  }

  // -------------------------------------------------------------------------
  // Bulk Update
  // -------------------------------------------------------------------------
  if ($post_action === 'bulk_update') {
    $bulk_action = (string) ($_POST['bulk_action'] ?? '');
    $selected_users = $_POST['selected_users'] ?? [];

    if (!is_array($selected_users) || empty($selected_users)) {
      $_SESSION['flash_error_msg'] = 'Select at least one user before applying a bulk action.';
      header('Location: ' . $self_url);
      exit;
    }

    $selected_ids = [];
    foreach ($selected_users as $candidate_id) {
      $id = (int) $candidate_id;
      if ($id > 0) {
        $selected_ids[] = $id;
      }
    }
    $selected_ids = array_values(array_unique($selected_ids));

    if (empty($selected_ids)) {
      $_SESSION['flash_error_msg'] = 'Bulk action did not include valid user IDs.';
      header('Location: ' . $self_url);
      exit;
    }

    if (count($selected_ids) > 200) {
      $_SESSION['flash_error_msg'] = 'Select up to 200 users per bulk action.';
      header('Location: ' . $self_url);
      exit;
    }

    $bulk_new_role = null;
    if (str_starts_with($bulk_action, 'role_')) {
      $bulk_new_role = substr($bulk_action, 5);
      if (!in_array($bulk_new_role, $allowed_roles_list, true)) {
        $_SESSION['flash_error_msg'] = 'Choose a valid bulk role action.';
        header('Location: ' . $self_url);
        exit;
      }
    } elseif (!in_array($bulk_action, ['activate', 'deactivate'], true)) {
      $_SESSION['flash_error_msg'] = 'Choose a valid bulk action before applying.';
      header('Location: ' . $self_url);
      exit;
    }

    try {
      $pdo->beginTransaction();

      $placeholders = implode(',', array_fill(0, count($selected_ids), '?'));
      $stmt = $pdo->prepare(
        "SELECT id, role, is_verified, is_suspended, is_superadmin
           FROM Users
          WHERE id IN ($placeholders)
          FOR UPDATE"
      );
      $stmt->execute($selected_ids);
      $targets = $stmt->fetchAll();

      $targets_by_id = [];
      foreach ($targets as $target_row) {
        $targets_by_id[(int) $target_row['id']] = $target_row;
      }

      $changed = 0;
      $skipped_protected = 0;
      $skipped_noop = 0;
      $skipped_missing = 0;
      $skipped_role_validation = 0;
      $active_admin_count = count_active_admins($pdo);

      foreach ($selected_ids as $target_id) {
        if (!isset($targets_by_id[$target_id])) {
          $skipped_missing++;
          continue;
        }
        $target = $targets_by_id[$target_id];

        if (is_superadmin_user($target)) {
          $skipped_protected++;
          continue;
        }

        if ($bulk_action === 'activate') {
          if ((int) $target['is_suspended'] === 0) {
            $skipped_noop++;
            continue;
          }
          $upd = $pdo->prepare('UPDATE Users SET is_suspended = 0 WHERE id = ? LIMIT 1');
          $upd->execute([$target_id]);
          $changed++;
          continue;
        }

        if ($bulk_action === 'deactivate') {
          if ((int) $target['is_suspended'] === 1) {
            $skipped_noop++;
            continue;
          }

          if ((string) $target['role'] === 'admin') {
            if ($active_admin_count <= 1) {
              $skipped_protected++;
              continue;
            }
            $active_admin_count--;
          }

          $upd = $pdo->prepare('UPDATE Users SET is_suspended = 1 WHERE id = ? LIMIT 1');
          $upd->execute([$target_id]);
          $changed++;
          continue;
        }

        if ($bulk_new_role !== null) {
          $current_role = (string) $target['role'];
          $is_verified_target = (int) ($target['is_verified'] ?? 0) === 1;
          $is_suspended = (int) $target['is_suspended'] === 1;

          if ($current_role === $bulk_new_role) {
            $skipped_noop++;
            continue;
          }

          if ($role_requires_verification($bulk_new_role) && !$is_verified_target) {
            $skipped_role_validation++;
            continue;
          }

          if ($current_role === 'admin' && $bulk_new_role !== 'admin' && !$is_suspended) {
            if ($active_admin_count <= 1) {
              $skipped_protected++;
              continue;
            }
            $active_admin_count--;
          }

          $upd = $pdo->prepare('UPDATE Users SET role = ? WHERE id = ? LIMIT 1');
          $upd->execute([$bulk_new_role, $target_id]);
          $changed++;
          continue;
        }
      }

      log_admin_action($pdo, [
        'actor_id' => $actor_id,
        'actor_role' => 'admin',
        'action_type' => 'users_bulk_update',
        'target_entity' => 'Users',
        'target_id' => 0,
        'outcome' => 'success',
      ]);

      $pdo->commit();

      $segments = [];
      $segments[] = $changed . ' updated';
      if ($skipped_noop > 0) {
        $segments[] = $skipped_noop . ' already in target state';
      }
      if ($skipped_protected > 0) {
        $segments[] = $skipped_protected . ' blocked by protection rules';
      }
      if ($skipped_missing > 0) {
        $segments[] = $skipped_missing . ' not found';
      }
      if ($skipped_role_validation > 0) {
        $segments[] = $skipped_role_validation . ' blocked by role verification rules';
      }

      $_SESSION['flash_success'] = 'Bulk update complete: ' . implode(', ', $segments) . '.';
    } catch (PDOException $e) {
      if ($pdo->inTransaction()) {
        $pdo->rollBack();
      }
      error_log('[admin/users.php] bulk_update DB error: ' . $e->getMessage());
      $_SESSION['flash_error_msg'] = 'Bulk action failed. Please try again.';
    }

    header('Location: ' . $self_url);
    exit;
  }

  // -------------------------------------------------------------------------
  // Create User
  // -------------------------------------------------------------------------
  if ($post_action === 'create_user') {
    $full_name = trim((string) ($_POST['full_name'] ?? ''));
    $email = trim((string) ($_POST['email'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    $role = (string) ($_POST['role'] ?? 'borrower');
    $is_verified = isset($_POST['is_verified']) ? 1 : 0;

    $old_create_user_input = [
      'full_name' => $full_name,
      'email' => $email,
      'role' => $role,
      'is_verified' => $is_verified,
    ];

    if ($full_name === '' || $email === '' || $password === '') {
      $persist_create_user_state('Name, email, and password are required.', $old_create_user_input);
      header('Location: ' . $self_url);
      exit;
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
      $persist_create_user_state('Please provide a valid email address.', $old_create_user_input);
      header('Location: ' . $self_url);
      exit;
    }
    if (!in_array($role, $allowed_roles_list, true)) {
      $persist_create_user_state('Select a valid role.', $old_create_user_input);
      header('Location: ' . $self_url);
      exit;
    }
    if ($role_requires_verification($role) && $is_verified !== 1) {
      $persist_create_user_state('Admin and Librarian accounts must start as verified.', $old_create_user_input);
      header('Location: ' . $self_url);
      exit;
    }
    if (strlen($password) < 8) {
      $persist_create_user_state('Password must be at least 8 characters.', $old_create_user_input);
      header('Location: ' . $self_url);
      exit;
    }

    try {
      $pdo->beginTransaction();

      $dup_stmt = $pdo->prepare('SELECT id FROM Users WHERE email = ? LIMIT 1');
      $dup_stmt->execute([$email]);
      if ($dup_stmt->fetch()) {
        $pdo->rollBack();
        $persist_create_user_state('A user with that email already exists.', $old_create_user_input);
        header('Location: ' . $self_url);
        exit;
      }

      $password_hash = password_hash($password, PASSWORD_BCRYPT);
      $is_superadmin = ($role === 'admin' && strcasecmp($email, SUPERADMIN_EMAIL) === 0) ? 1 : 0;

      $insert = $pdo->prepare(
        'INSERT INTO Users
          (full_name, email, password_hash, role, is_superadmin, is_verified, is_suspended, verification_token, verified_at)
         VALUES
          (?, ?, ?, ?, ?, ?, 0, NULL, CASE WHEN ? = 1 THEN NOW() ELSE NULL END)'
      );
      $insert->execute([$full_name, $email, $password_hash, $role, $is_superadmin, $is_verified, $is_verified]);
      $new_user_id = (int) $pdo->lastInsertId();

      log_admin_action($pdo, [
        'actor_id' => $actor_id,
        'actor_role' => 'admin',
        'action_type' => 'user_create',
        'target_entity' => 'Users',
        'target_id' => $new_user_id,
        'outcome' => 'success',
      ]);

      $pdo->commit();
      unset($_SESSION['users_create_old'], $_SESSION['users_create_error']);
      $_SESSION['flash_success'] = 'User account created.';
    } catch (PDOException $e) {
      if ($pdo->inTransaction()) {
        $pdo->rollBack();
      }
      error_log('[admin/users.php] create_user DB error: ' . $e->getMessage());
      $persist_create_user_state('We could not create the account right now. Please try again.', $old_create_user_input);
    }

    header('Location: ' . $self_url);
    exit;
  }

  // -------------------------------------------------------------------------
  // Update User Credentials
  // -------------------------------------------------------------------------
  if ($post_action === 'update_user_credentials') {
    $user_id = (int) ($_POST['user_id'] ?? 0);
    $full_name = trim((string) ($_POST['full_name'] ?? ''));
    $email = trim((string) ($_POST['email'] ?? ''));

    if ($user_id <= 0 || $full_name === '' || $email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
      try {
        $pdo->beginTransaction();
        log_admin_action($pdo, [
          'actor_id' => $actor_id,
          'actor_role' => 'admin',
          'action_type' => 'user_credentials_update',
          'target_entity' => 'Users',
          'target_id' => $user_id > 0 ? $user_id : null,
          'outcome' => 'failure:validation',
        ]);
        $pdo->commit();
      } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
          $pdo->rollBack();
        }
        error_log('[admin/users.php] update_user_credentials validation log error: ' . $e->getMessage());
      }

      $_SESSION['flash_error_msg'] = 'Enter a valid full name and email before saving credentials.';
      header('Location: ' . $self_url);
      exit;
    }

    try {
      $pdo->beginTransaction();

      $stmt = $pdo->prepare('SELECT id, full_name, email, is_superadmin FROM Users WHERE id = ? FOR UPDATE');
      $stmt->execute([$user_id]);
      $target = $stmt->fetch();

      if (!$target) {
        $pdo->rollBack();
        $pdo->beginTransaction();
        log_admin_action($pdo, [
          'actor_id' => $actor_id,
          'actor_role' => 'admin',
          'action_type' => 'user_credentials_update',
          'target_entity' => 'Users',
          'target_id' => $user_id,
          'outcome' => 'failure:user_not_found',
        ]);
        $pdo->commit();
        $_SESSION['flash_error_msg'] = 'That user account was not found.';
        header('Location: ' . $self_url);
        exit;
      }

      if (is_superadmin_user($target)) {
        $pdo->rollBack();
        $pdo->beginTransaction();
        log_admin_action($pdo, [
          'actor_id' => $actor_id,
          'actor_role' => 'admin',
          'action_type' => 'user_credentials_update',
          'target_entity' => 'Users',
          'target_id' => $user_id,
          'outcome' => 'failure:protected_superadmin',
        ]);
        $pdo->commit();
        $_SESSION['flash_error_msg'] = 'You cannot update credentials for the Superadmin account.';
        header('Location: ' . $self_url);
        exit;
      }

      $dup_stmt = $pdo->prepare('SELECT id FROM Users WHERE email = ? AND id <> ? LIMIT 1');
      $dup_stmt->execute([$email, $user_id]);
      if ($dup_stmt->fetch()) {
        $pdo->rollBack();
        $pdo->beginTransaction();
        log_admin_action($pdo, [
          'actor_id' => $actor_id,
          'actor_role' => 'admin',
          'action_type' => 'user_credentials_update',
          'target_entity' => 'Users',
          'target_id' => $user_id,
          'outcome' => 'failure:duplicate_email',
        ]);
        $pdo->commit();
        $_SESSION['flash_error_msg'] = 'A user with that email already exists.';
        header('Location: ' . $self_url);
        exit;
      }

      $upd = $pdo->prepare('UPDATE Users SET full_name = ?, email = ?, updated_at = NOW() WHERE id = ? LIMIT 1');
      $upd->execute([$full_name, $email, $user_id]);

      log_admin_action($pdo, [
        'actor_id' => $actor_id,
        'actor_role' => 'admin',
        'action_type' => 'user_credentials_update',
        'target_entity' => 'Users',
        'target_id' => $user_id,
        'outcome' => 'success',
      ]);

      $pdo->commit();

      if ($user_id === $actor_id) {
        $_SESSION['full_name'] = $full_name;
        $_SESSION['email'] = $email;
      }

      $_SESSION['flash_success'] = 'User credentials updated.';
    } catch (PDOException $e) {
      if ($pdo->inTransaction()) {
        $pdo->rollBack();
      }
      error_log('[admin/users.php] update_user_credentials DB error: ' . $e->getMessage());
      $_SESSION['flash_error_msg'] = 'We could not update credentials right now. Please try again.';
    }

    header('Location: ' . $self_url);
    exit;
  }

  // -------------------------------------------------------------------------
  // Admin Reset User Password
  // -------------------------------------------------------------------------
  if ($post_action === 'admin_reset_user_password') {
    $user_id = (int) ($_POST['user_id'] ?? 0);
    $new_password = (string) ($_POST['new_password'] ?? '');
    $confirm_password = (string) ($_POST['confirm_password'] ?? '');

    if ($user_id <= 0 || $new_password === '' || $confirm_password === '' || strlen($new_password) < 8 || !hash_equals($new_password, $confirm_password)) {
      try {
        $pdo->beginTransaction();
        log_admin_action($pdo, [
          'actor_id' => $actor_id,
          'actor_role' => 'admin',
          'action_type' => 'admin_password_reset',
          'target_entity' => 'Users',
          'target_id' => $user_id > 0 ? $user_id : null,
          'outcome' => 'failure:validation',
        ]);
        $pdo->commit();
      } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
          $pdo->rollBack();
        }
        error_log('[admin/users.php] admin_reset_user_password validation log error: ' . $e->getMessage());
      }

      $_SESSION['flash_error_msg'] = 'Password reset requires a valid user, matching passwords, and at least 8 characters.';
      header('Location: ' . $self_url);
      exit;
    }

    try {
      $pdo->beginTransaction();

      $stmt = $pdo->prepare('SELECT id, is_superadmin, password_hash FROM Users WHERE id = ? FOR UPDATE');
      $stmt->execute([$user_id]);
      $target = $stmt->fetch();

      if (!$target) {
        $pdo->rollBack();
        $pdo->beginTransaction();
        log_admin_action($pdo, [
          'actor_id' => $actor_id,
          'actor_role' => 'admin',
          'action_type' => 'admin_password_reset',
          'target_entity' => 'Users',
          'target_id' => $user_id,
          'outcome' => 'failure:user_not_found',
        ]);
        $pdo->commit();
        $_SESSION['flash_error_msg'] = 'That user account was not found.';
        header('Location: ' . $self_url);
        exit;
      }

      if (is_superadmin_user($target)) {
        $pdo->rollBack();
        $pdo->beginTransaction();
        log_admin_action($pdo, [
          'actor_id' => $actor_id,
          'actor_role' => 'admin',
          'action_type' => 'admin_password_reset',
          'target_entity' => 'Users',
          'target_id' => $user_id,
          'outcome' => 'failure:protected_superadmin',
        ]);
        $pdo->commit();
        $_SESSION['flash_error_msg'] = 'You cannot reset the Superadmin password from this page.';
        header('Location: ' . $self_url);
        exit;
      }

      $existing_hash = (string) ($target['password_hash'] ?? '');
      if ($existing_hash !== '' && password_verify($new_password, $existing_hash)) {
        $pdo->rollBack();
        $pdo->beginTransaction();
        log_admin_action($pdo, [
          'actor_id' => $actor_id,
          'actor_role' => 'admin',
          'action_type' => 'admin_password_reset',
          'target_entity' => 'Users',
          'target_id' => $user_id,
          'outcome' => 'failure:password_reuse',
        ]);
        $pdo->commit();
        $_SESSION['flash_error_msg'] = 'New password must be different from the current password.';
        header('Location: ' . $self_url);
        exit;
      }

      $new_hash = password_hash($new_password, PASSWORD_BCRYPT);
      $upd = $pdo->prepare('UPDATE Users SET password_hash = ?, updated_at = NOW() WHERE id = ? LIMIT 1');
      $upd->execute([$new_hash, $user_id]);

      log_admin_action($pdo, [
        'actor_id' => $actor_id,
        'actor_role' => 'admin',
        'action_type' => 'admin_password_reset',
        'target_entity' => 'Users',
        'target_id' => $user_id,
        'outcome' => 'success',
      ]);

      $pdo->commit();
      $_SESSION['flash_success'] = 'User password updated.';
    } catch (PDOException $e) {
      if ($pdo->inTransaction()) {
        $pdo->rollBack();
      }
      error_log('[admin/users.php] admin_reset_user_password DB error: ' . $e->getMessage());
      $_SESSION['flash_error_msg'] = 'We could not update the password right now. Please try again.';
    }

    header('Location: ' . $self_url);
    exit;
  }

  // -------------------------------------------------------------------------
  // Update User Verification
  // -------------------------------------------------------------------------
  if ($post_action === 'update_user_verification') {
    $user_id = (int) ($_POST['user_id'] ?? 0);
    $requested_verified = (string) ($_POST['is_verified'] ?? '');

    if ($user_id <= 0 || !in_array($requested_verified, ['0', '1'], true)) {
      try {
        $pdo->beginTransaction();
        log_admin_action($pdo, [
          'actor_id' => $actor_id,
          'actor_role' => 'admin',
          'action_type' => 'user_verification_update',
          'target_entity' => 'Users',
          'target_id' => $user_id > 0 ? $user_id : null,
          'outcome' => 'failure:validation',
        ]);
        $pdo->commit();
      } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
          $pdo->rollBack();
        }
        error_log('[admin/users.php] update_user_verification validation log error: ' . $e->getMessage());
      }

      $_SESSION['flash_error_msg'] = 'Choose a valid verification status before saving.';
      header('Location: ' . $self_url);
      exit;
    }

    $new_verified = (int) $requested_verified;

    try {
      $pdo->beginTransaction();

      $stmt = $pdo->prepare('SELECT id, role, is_verified, is_superadmin FROM Users WHERE id = ? FOR UPDATE');
      $stmt->execute([$user_id]);
      $target = $stmt->fetch();

      if (!$target) {
        $pdo->rollBack();
        $pdo->beginTransaction();
        log_admin_action($pdo, [
          'actor_id' => $actor_id,
          'actor_role' => 'admin',
          'action_type' => 'user_verification_update',
          'target_entity' => 'Users',
          'target_id' => $user_id,
          'outcome' => 'failure:user_not_found',
        ]);
        $pdo->commit();
        $_SESSION['flash_error_msg'] = 'That user account was not found.';
        header('Location: ' . $self_url);
        exit;
      }

      if (is_superadmin_user($target)) {
        $pdo->rollBack();
        $pdo->beginTransaction();
        log_admin_action($pdo, [
          'actor_id' => $actor_id,
          'actor_role' => 'admin',
          'action_type' => 'user_verification_update',
          'target_entity' => 'Users',
          'target_id' => $user_id,
          'outcome' => 'failure:protected_superadmin',
        ]);
        $pdo->commit();
        $_SESSION['flash_error_msg'] = 'You cannot change verification for the Superadmin account.';
        header('Location: ' . $self_url);
        exit;
      }

      $current_role = (string) ($target['role'] ?? '');
      $current_verified = (int) ($target['is_verified'] ?? 0);

      if ($new_verified === $current_verified) {
        $pdo->rollBack();
        $_SESSION['flash_success'] = 'Verification status is already up to date.';
        header('Location: ' . $self_url);
        exit;
      }

      if ($new_verified === 0 && $role_requires_verification($current_role)) {
        $pdo->rollBack();
        $pdo->beginTransaction();
        log_admin_action($pdo, [
          'actor_id' => $actor_id,
          'actor_role' => 'admin',
          'action_type' => 'user_verification_update',
          'target_entity' => 'Users',
          'target_id' => $user_id,
          'outcome' => 'failure:role_requires_verified',
        ]);
        $pdo->commit();
        $_SESSION['flash_error_msg'] = 'Admin and Librarian accounts must remain verified.';
        header('Location: ' . $self_url);
        exit;
      }

      $upd = $pdo->prepare(
        'UPDATE Users
            SET is_verified = ?,
                verified_at = CASE WHEN ? = 1 THEN COALESCE(verified_at, NOW()) ELSE NULL END,
                updated_at = NOW()
          WHERE id = ?
          LIMIT 1'
      );
      $upd->execute([$new_verified, $new_verified, $user_id]);

      log_admin_action($pdo, [
        'actor_id' => $actor_id,
        'actor_role' => 'admin',
        'action_type' => 'user_verification_update',
        'target_entity' => 'Users',
        'target_id' => $user_id,
        'outcome' => 'success',
      ]);

      $pdo->commit();
      $_SESSION['flash_success'] = $new_verified === 1
        ? 'User marked as verified.'
        : 'User marked as not verified.';
    } catch (PDOException $e) {
      if ($pdo->inTransaction()) {
        $pdo->rollBack();
      }
      error_log('[admin/users.php] update_user_verification DB error: ' . $e->getMessage());
      $_SESSION['flash_error_msg'] = 'We could not update verification status right now. Please try again.';
    }

    header('Location: ' . $self_url);
    exit;
  }

  // -------------------------------------------------------------------------
  // Role Change
  // -------------------------------------------------------------------------
  if ($post_action === 'role_change') {
    $user_id = (int) ($_POST['user_id'] ?? 0);
    $new_role = (string) ($_POST['new_role'] ?? '');

    if (!in_array($new_role, $allowed_roles_list, true)) {
      $_SESSION['flash_error_msg'] = 'Select a valid role.';
      header('Location: ' . $self_url);
      exit;
    }

    try {
      $pdo->beginTransaction();

      $stmt = $pdo->prepare('SELECT id, role, is_verified, is_suspended, is_superadmin FROM Users WHERE id = ? FOR UPDATE');
      $stmt->execute([$user_id]);
      $target = $stmt->fetch();

      if (!$target) {
        $pdo->rollBack();
        $_SESSION['flash_error_msg'] = 'That user account was not found.';
        header('Location: ' . $self_url);
        exit;
      }

      $target_verified = (int) ($target['is_verified'] ?? 0) === 1;
      if ($role_requires_verification($new_role) && !$target_verified) {
        $pdo->rollBack();
        $pdo->beginTransaction();
        log_admin_action($pdo, [
          'actor_id' => $actor_id,
          'actor_role' => 'admin',
          'action_type' => 'role_change',
          'target_entity' => 'Users',
          'target_id' => $user_id,
          'outcome' => 'failure:target_not_verified',
        ]);
        $pdo->commit();
        $_SESSION['flash_error_msg'] = 'Admin and Librarian roles require a verified account first.';
        header('Location: ' . $self_url);
        exit;
      }

      if (is_superadmin_user($target)) {
        $pdo->rollBack();
        $pdo->beginTransaction();
        log_admin_action($pdo, [
          'actor_id' => $actor_id,
          'actor_role' => 'admin',
          'action_type' => 'role_change',
          'target_entity' => 'Users',
          'target_id' => $user_id,
          'outcome' => 'failure:protected_superadmin',
        ]);
        $pdo->commit();
        $_SESSION['flash_error_msg'] = 'You cannot change the Superadmin role.';
        header('Location: ' . $self_url);
        exit;
      }

      if ($target['role'] === 'admin' && $new_role !== 'admin') {
        $active_admin_count = count_active_admins($pdo);
        if ($active_admin_count <= 1) {
          $pdo->rollBack();
          $pdo->beginTransaction();
          log_admin_action($pdo, [
            'actor_id' => $actor_id,
            'actor_role' => 'admin',
            'action_type' => 'role_change',
            'target_entity' => 'Users',
            'target_id' => $user_id,
            'outcome' => 'failure:last_active_admin',
          ]);
          $pdo->commit();
          $_SESSION['flash_error_msg'] = 'You cannot demote the last active admin account.';
          header('Location: ' . $self_url);
          exit;
        }
      }

      $upd = $pdo->prepare('UPDATE Users SET role = ? WHERE id = ? LIMIT 1');
      $upd->execute([$new_role, $user_id]);

      log_admin_action($pdo, [
        'actor_id' => $actor_id,
        'actor_role' => 'admin',
        'action_type' => 'role_change',
        'target_entity' => 'Users',
        'target_id' => $user_id,
        'outcome' => 'success',
      ]);

      $pdo->commit();
      $undo_token = bin2hex(random_bytes(16));
      $_SESSION['users_undo'] = [
        'token' => $undo_token,
        'type' => 'role_change',
        'user_id' => $user_id,
        'previous_role' => (string) $target['role'],
        'expires_at' => time() + 300,
      ];
      $_SESSION['flash_success'] = 'User role updated.';
    } catch (PDOException $e) {
      if ($pdo->inTransaction()) {
        $pdo->rollBack();
      }
      error_log('[admin/users.php] role_change DB error: ' . $e->getMessage());
      $_SESSION['flash_error_msg'] = 'We could not update the role right now. Please try again.';
    }

    header('Location: ' . $self_url);
    exit;
  }

  // -------------------------------------------------------------------------
  // Deactivate / Activate
  // -------------------------------------------------------------------------
  if ($post_action === 'suspend' || $post_action === 'deactivate' || $post_action === 'reinstate' || $post_action === 'activate') {
    $is_deactivate = ($post_action === 'suspend' || $post_action === 'deactivate');
    $user_id = (int) ($_POST['user_id'] ?? 0);
    $confirmed = (string) ($_POST['confirmed'] ?? '0');

    // Warning mode (no update yet) if deactivating user with active loans.
    if ($is_deactivate && $confirmed !== '1') {
      $loan_stmt = $pdo->prepare("SELECT COUNT(*) FROM Circulation WHERE user_id = ? AND status = 'active'");
      $loan_stmt->execute([$user_id]);
      $active_loan_count = (int) $loan_stmt->fetchColumn();

      if ($active_loan_count > 0) {
        $stmt2 = $pdo->prepare('SELECT id, full_name, email, role, is_suspended, is_superadmin, created_at FROM Users WHERE id = ? LIMIT 1');
        $stmt2->execute([$user_id]);
        $warn_user = $stmt2->fetch();
        if ($warn_user) {
          $show_deactivate_warning = true;
          $warn_loan_count = $active_loan_count;
        }
      }
    }

    if (!$show_deactivate_warning) {
      try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare('SELECT id, full_name, email, role, is_suspended, is_superadmin FROM Users WHERE id = ? FOR UPDATE');
        $stmt->execute([$user_id]);
        $target = $stmt->fetch();

        if (!$target) {
          $pdo->rollBack();
          $_SESSION['flash_error_msg'] = 'That user account was not found.';
          header('Location: ' . $self_url);
          exit;
        }

        if ($is_deactivate) {
          if (is_superadmin_user($target)) {
            $pdo->rollBack();
            $pdo->beginTransaction();
            log_admin_action($pdo, [
              'actor_id' => $actor_id,
              'actor_role' => 'admin',
              'action_type' => 'account_deactivate',
              'target_entity' => 'Users',
              'target_id' => $user_id,
              'outcome' => 'failure:protected_superadmin',
            ]);
            $pdo->commit();
            $_SESSION['flash_error_msg'] = 'You cannot deactivate the Superadmin account.';
            header('Location: ' . $self_url);
            exit;
          }

          if ($target['role'] === 'admin') {
            $active_admin_count = count_active_admins($pdo);
            if ($active_admin_count <= 1) {
              $pdo->rollBack();
              $pdo->beginTransaction();
              log_admin_action($pdo, [
                'actor_id' => $actor_id,
                'actor_role' => 'admin',
                'action_type' => 'account_deactivate',
                'target_entity' => 'Users',
                'target_id' => $user_id,
                'outcome' => 'failure:last_active_admin',
              ]);
              $pdo->commit();
              $_SESSION['flash_error_msg'] = 'You cannot deactivate the last active admin account.';
              header('Location: ' . $self_url);
              exit;
            }
          }

          $upd = $pdo->prepare('UPDATE Users SET is_suspended = 1 WHERE id = ? LIMIT 1');
          $upd->execute([$user_id]);

          log_admin_action($pdo, [
            'actor_id' => $actor_id,
            'actor_role' => 'admin',
            'action_type' => 'account_deactivate',
            'target_entity' => 'Users',
            'target_id' => $user_id,
            'outcome' => 'success',
          ]);

          $pdo->commit();
          $undo_token = bin2hex(random_bytes(16));
          $_SESSION['users_undo'] = [
            'token' => $undo_token,
            'type' => 'status_change',
            'user_id' => $user_id,
            'previous_status' => (int) $target['is_suspended'],
            'expires_at' => time() + 300,
          ];
          send_account_status_email($target['email'], $target['full_name'], 'suspended');
          $_SESSION['flash_success'] = 'User account deactivated.';
        } else {
          $upd = $pdo->prepare('UPDATE Users SET is_suspended = 0 WHERE id = ? LIMIT 1');
          $upd->execute([$user_id]);

          log_admin_action($pdo, [
            'actor_id' => $actor_id,
            'actor_role' => 'admin',
            'action_type' => 'account_activate',
            'target_entity' => 'Users',
            'target_id' => $user_id,
            'outcome' => 'success',
          ]);

          $pdo->commit();
          $undo_token = bin2hex(random_bytes(16));
          $_SESSION['users_undo'] = [
            'token' => $undo_token,
            'type' => 'status_change',
            'user_id' => $user_id,
            'previous_status' => (int) $target['is_suspended'],
            'expires_at' => time() + 300,
          ];
          send_account_status_email($target['email'], $target['full_name'], 'reinstated');
          $_SESSION['flash_success'] = 'User account reactivated.';
        }
      } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
          $pdo->rollBack();
        }
        error_log('[admin/users.php] deactivate/activate DB error: ' . $e->getMessage());
        $_SESSION['flash_error_msg'] = 'We could not update account status right now. Please try again.';
      }

      header('Location: ' . $self_url);
      exit;
    }
  }

  // -------------------------------------------------------------------------
  // Delete User
  // -------------------------------------------------------------------------
  if ($post_action === 'delete_user') {
    $user_id = (int) ($_POST['user_id'] ?? 0);
    $delete_confirm = (string) ($_POST['delete_confirm'] ?? '0');

    if ($delete_confirm !== '1') {
      $_SESSION['flash_error_msg'] = 'Please confirm permanent deletion before continuing.';
      header('Location: ' . $self_url);
      exit;
    }

    try {
      $pdo->beginTransaction();

      $stmt = $pdo->prepare('SELECT id, full_name, role, is_suspended, is_superadmin FROM Users WHERE id = ? FOR UPDATE');
      $stmt->execute([$user_id]);
      $target = $stmt->fetch();

      if (!$target) {
        $pdo->rollBack();
        $_SESSION['flash_error_msg'] = 'That user account was not found.';
        header('Location: ' . $self_url);
        exit;
      }

      if ($user_id === $actor_id) {
        $pdo->rollBack();
        $_SESSION['flash_error_msg'] = 'You cannot delete your own account.';
        header('Location: ' . $self_url);
        exit;
      }

      if (is_superadmin_user($target)) {
        $pdo->rollBack();
        $pdo->beginTransaction();
        log_admin_action($pdo, [
          'actor_id' => $actor_id,
          'actor_role' => 'admin',
          'action_type' => 'account_delete',
          'target_entity' => 'Users',
          'target_id' => $user_id,
          'outcome' => 'failure:protected_superadmin',
        ]);
        $pdo->commit();
        $_SESSION['flash_error_msg'] = 'You cannot delete the Superadmin account.';
        header('Location: ' . $self_url);
        exit;
      }

      if ($target['role'] === 'admin' && (int) $target['is_suspended'] === 0) {
        $active_admin_count = count_active_admins($pdo);
        if ($active_admin_count <= 1) {
          $pdo->rollBack();
          $_SESSION['flash_error_msg'] = 'You cannot delete the last active admin account.';
          header('Location: ' . $self_url);
          exit;
        }
      }

      $dep_stmt = $pdo->prepare(
        'SELECT
            (SELECT COUNT(*) FROM Circulation WHERE user_id = ?) AS circulation_count,
            (SELECT COUNT(*) FROM Reservations WHERE user_id = ?) AS reservation_count'
      );
      $dep_stmt->execute([$user_id, $user_id]);
      $dep = $dep_stmt->fetch() ?: ['circulation_count' => 0, 'reservation_count' => 0];

      if ((int) $dep['circulation_count'] > 0 || (int) $dep['reservation_count'] > 0) {
        $pdo->rollBack();
        $_SESSION['flash_error_msg'] = 'This account has loan or reservation history. Deactivate it instead.';
        header('Location: ' . $self_url);
        exit;
      }

      $del = $pdo->prepare('DELETE FROM Users WHERE id = ? LIMIT 1');
      $del->execute([$user_id]);

      log_admin_action($pdo, [
        'actor_id' => $actor_id,
        'actor_role' => 'admin',
        'action_type' => 'account_delete',
        'target_entity' => 'Users',
        'target_id' => $user_id,
        'outcome' => 'success',
      ]);

      $pdo->commit();
      $_SESSION['flash_success'] = 'User account deleted.';
    } catch (PDOException $e) {
      if ($pdo->inTransaction()) {
        $pdo->rollBack();
      }
      error_log('[admin/users.php] delete_user DB error: ' . $e->getMessage());
      $_SESSION['flash_error_msg'] = 'We could not delete the account right now. Please try again.';
    }

    header('Location: ' . $self_url);
    exit;
  }
}

// GET / render
$q = trim((string) ($_GET['q'] ?? ''));
$q = preg_replace('/\s+/u', ' ', $q) ?? $q;
$role_filter = trim((string) ($_GET['role'] ?? ''));
$status_filter = trim((string) ($_GET['status'] ?? ''));

$allowed_role_filters = ['admin', 'librarian', 'borrower'];
$allowed_status_filters = ['active', 'inactive'];

if ($role_filter !== '' && !in_array($role_filter, $allowed_role_filters, true)) {
  $role_filter = '';
}
if ($status_filter !== '' && !in_array($status_filter, $allowed_status_filters, true)) {
  $status_filter = '';
}

if ($q !== '' && strlen($q) > 100) {
  $flash_error = 'Search text is too long (maximum 100 characters).';
}

$where_parts = [];
$query_params = [];
if ($q !== '' && $flash_error === '') {
  $escaped_q = addcslashes($q, '\\%_');
  $where_parts[] = "(full_name LIKE :q_name ESCAPE '\\\\' OR email LIKE :q_email ESCAPE '\\\\')";
  $query_params[':q_name'] = '%' . $escaped_q . '%';
  $query_params[':q_email'] = '%' . $escaped_q . '%';
}
if ($role_filter !== '') {
  $where_parts[] = 'role = :role';
  $query_params[':role'] = $role_filter;
}
if ($status_filter === 'active') {
  $where_parts[] = 'is_suspended = 0';
} elseif ($status_filter === 'inactive') {
  $where_parts[] = 'is_suspended = 1';
}

$where_sql = '';
if (!empty($where_parts)) {
  $where_sql = ' WHERE ' . implode(' AND ', $where_parts);
}

$summary_stmt = $pdo->prepare(
  'SELECT
      COUNT(*) AS total_users,
      SUM(CASE WHEN is_suspended = 0 THEN 1 ELSE 0 END) AS active_users,
      SUM(CASE WHEN is_suspended = 1 THEN 1 ELSE 0 END) AS inactive_users,
      SUM(CASE WHEN role = "admin" THEN 1 ELSE 0 END) AS admin_users
     FROM Users' . $where_sql
);
foreach ($query_params as $key => $value) {
  $summary_stmt->bindValue($key, $value, PDO::PARAM_STR);
}
$summary_stmt->execute();
$summary = $summary_stmt->fetch() ?: [];

$total_users = (int) ($summary['total_users'] ?? 0);
$active_users = (int) ($summary['active_users'] ?? 0);
$inactive_users = (int) ($summary['inactive_users'] ?? 0);
$admin_users = (int) ($summary['admin_users'] ?? 0);

$allowed_per_page = [10, 25, 50, 100];
$per_page = (int) ($_GET['per_page'] ?? 25);
if (!in_array($per_page, $allowed_per_page, true)) {
    $per_page = 25;
}
$total_pages = max(1, (int) ceil($total_users / $per_page));
$page = max(1, (int) ($_GET['page'] ?? 1));
if ($page > $total_pages) {
  $page = $total_pages;
}
$offset = ($page - 1) * $per_page;

$stmt = $pdo->prepare(
  'SELECT id, full_name, email, role, is_superadmin, is_verified, is_suspended, avatar_url, created_at
     FROM Users' . $where_sql . '
    ORDER BY created_at DESC, id DESC
    LIMIT :limit OFFSET :offset'
);
foreach ($query_params as $key => $value) {
  $stmt->bindValue($key, $value, PDO::PARAM_STR);
}
$stmt->bindValue(':limit', $per_page, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$users = $stmt->fetchAll();

$page_range_start = $total_users > 0 ? ($offset + 1) : 0;
$page_range_end = $offset + count($users);

$build_page_url = static function (int $target_page) use ($self_url, $q, $role_filter, $status_filter): string {
  $params = ['page' => $target_page];
  if ($q !== '') {
    $params['q'] = $q;
  }
  if ($role_filter !== '') {
    $params['role'] = $role_filter;
  }
  if ($status_filter !== '') {
    $params['status'] = $status_filter;
  }
  return $self_url . '?' . http_build_query($params);
};

$has_feedback = $flash_success !== ''
  || $flash_error !== ''
  || ($undo_action && !empty($undo_action['token']))
  || ($show_deactivate_warning && $warn_user);
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <?php
  $extraStyles = [
    'https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700&display=swap',
    BASE_URL . 'assets/css/borrower-redesign.css',
    BASE_URL . 'assets/css/admin-redesign.css'
  ];
  require_once __DIR__ . '/../includes/head.php';
?>
  <style>
    .admin-themed .admin-users-page {
      color-scheme: dark;
      --paper: var(--rd-bg);
      --cream: #2a2723;
      --card: var(--rd-surface);
      --ink: var(--rd-text);
      --muted: var(--rd-text-muted);
      --border: var(--rd-border);
      --accent: var(--rd-primary);
      --accent-dark: #b8942d;
      --gold: var(--rd-primary);
      --gold-dark: #b8942d;
      --sage: var(--rd-success);
      --sage-dark: #059669;
      color: var(--rd-text);
    }

    .admin-themed .admin-users-page .btn-primary {
      background: linear-gradient(135deg, var(--rd-primary), #b8942d);
      color: #1c1a17 !important;
      border: none;
      box-shadow: 0 8px 16px rgba(201, 168, 76, 0.25);
    }

    .admin-themed .admin-users-page .btn-primary:hover {
      transform: translateY(-2px);
      box-shadow: 0 12px 24px rgba(201, 168, 76, 0.35);
    }

    .admin-themed .admin-users-page .btn-ghost {
      background: rgba(255, 255, 255, 0.05);
      border: 1px solid var(--rd-border);
      color: var(--rd-text);
    }

    .admin-themed .admin-users-page .btn-ghost:hover {
      background: rgba(255, 255, 255, 0.1);
      color: var(--rd-text-bold);
      border-color: var(--rd-border);
    }

    .admin-themed .admin-users-page .users-stat-list strong {
      font-family: var(--rd-font-serif);
      color: var(--rd-text-bold);
    }

    .admin-themed .admin-users-page .users-stat-label {
      color: var(--rd-text-muted);
    }

    .admin-themed .admin-users-page .users-empty-state__icon {
      margin: 0 auto var(--space-4);
      opacity: 1;
    }
  </style>
</head>

<body class="admin-themed">
  <div class="app-shell">
    <?php require_once __DIR__ . '/../includes/sidebar-admin.php'; ?>
    <main class="main-content admin-users-page users-v2">
      <div class="users-layout" data-focus-user-id="<?= (int) $focus_user_id ?>">
      <div class="page-header users-page-header">
         <div>
           <h1>User Accounts</h1>
           <p>Add accounts and manage who can access the library.</p>
          </div>
        </div>

      <div id="users-live-announcer" class="sr-only" role="status" aria-live="polite" aria-atomic="true"></div>

      <noscript>
        <style>
          .users-nojs-only { display: block !important; }
          .users-manage-trigger { display: none !important; }
        </style>
      </noscript>

      <!-- Onboarding banner for first-time admins -->
      <div class="users-onboarding-banner" id="users-onboarding-banner" style="display: none;">
        <p><strong>Welcome to User Management!</strong> Here you can manage user accounts, assign roles, and control access. <strong>Tip:</strong> Use <kbd>Cmd+K</kbd> (Mac) or <kbd>Ctrl+K</kbd> (Windows) to quickly search users, or <kbd>Cmd+N</kbd>/<kbd>Ctrl+N</kbd> to add a new user.</p>
        <button type="button" class="users-onboarding-banner__dismiss" id="dismiss-onboarding" aria-label="Dismiss help banner">Got it</button>
      </div>

      <?php if ($has_feedback): ?>
        <div class="users-feedback-stack" aria-live="polite" aria-atomic="true">
          <?php if ($flash_success !== ''): ?>
            <div id="users-flash-success" data-message="<?= htmlspecialchars($flash_success, ENT_QUOTES, 'UTF-8') ?>" style="display:none;"></div>
            <div class="flash flash-success" role="alert"><?= htmlspecialchars($flash_success, ENT_QUOTES, 'UTF-8') ?></div>
          <?php endif; ?>
          <?php if ($flash_error !== ''): ?>
            <div id="users-flash-error" data-message="<?= htmlspecialchars($flash_error, ENT_QUOTES, 'UTF-8') ?>" style="display:none;"></div>
            <div class="flash flash-error" role="alert" aria-live="assertive"><?= htmlspecialchars($flash_error, ENT_QUOTES, 'UTF-8') ?></div>
          <?php endif; ?>

          <?php if ($undo_action && !empty($undo_action['token'])): ?>
            <div class="flash-info users-undo" role="status">
              <div>
                <strong>Need to roll back the last change?</strong>
                <p class="users-undo__text">Undo is available for 5 minutes after role and status updates.</p>
              </div>
              <form method="post" action="<?= htmlspecialchars($self_url, ENT_QUOTES, 'UTF-8') ?>" class="users-undo__form">
                <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                <input type="hidden" name="action" value="undo_last_user_change">
                <input type="hidden" name="undo_token" value="<?= htmlspecialchars((string) $undo_action['token'], ENT_QUOTES, 'UTF-8') ?>">
                <button type="submit" class="btn-ghost">Undo Last Change</button>
              </form>
            </div>
          <?php endif; ?>

          <?php if ($show_deactivate_warning && $warn_user): ?>
            <div class="flash-info users-warning" role="alert">
              <strong>This user has active loans</strong>
              <p class="users-warning__text">
                <strong><?= htmlspecialchars((string) $warn_user['full_name'], ENT_QUOTES, 'UTF-8') ?></strong>
                currently has <strong><?= (int) $warn_loan_count ?></strong> active loan(s). If you deactivate this account, the user cannot sign in, but their books are not returned automatically.
              </p>
              <form method="post" action="<?= htmlspecialchars($self_url, ENT_QUOTES, 'UTF-8') ?>" class="users-warning__actions">
                <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                <input type="hidden" name="action" value="deactivate">
                <input type="hidden" name="user_id" value="<?= (int) $warn_user['id'] ?>">
                <input type="hidden" name="confirmed" value="1">
                <button type="submit" class="btn-accent">Deactivate Account</button>
                <a href="<?= htmlspecialchars($self_url, ENT_QUOTES, 'UTF-8') ?>" class="btn-ghost">Cancel</a>
              </form>
            </div>
          <?php endif; ?>
        </div>
      <?php endif; ?>

        <div class="section-card users-toolbar users-overview-card" id="add-user-panel">
          <div class="section-card__body users-toolbar__body">
            <div class="users-toolbar__meta">
              <h2 class="users-toolbar__title">Summary</h2>
              <ul class="users-stat-list" aria-label="User account statistics">
                <li class="stat-card-tooltip" data-title="Total users in the system">
                  <span class="rd-stat-icon rd-i-blue" aria-hidden="true">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">
                      <path d="M17 20h5v-2a4 4 0 0 0-4-4h-1" />
                      <path d="M9 20H2v-2a4 4 0 0 1 4-4h1" />
                      <circle cx="9" cy="7" r="3" />
                      <circle cx="17" cy="7" r="3" />
                    </svg>
                  </span>
                  <strong><?= (int) $total_users ?></strong>
                  <span class="users-stat-label">Total Users</span>
                </li>
                <li class="stat-card-tooltip" data-title="Active users who can log in">
                  <span class="rd-stat-icon rd-i-green" aria-hidden="true">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">
                      <circle cx="12" cy="12" r="10" />
                      <path d="M9 12l2 2 4-4" />
                    </svg>
                  </span>
                  <strong><?= (int) $active_users ?></strong>
                  <span class="users-stat-label">Active Accounts</span>
                </li>
                <li class="stat-card-tooltip" data-title="Inactive users (archived or suspended)">
                  <span class="rd-stat-icon rd-i-orange" aria-hidden="true">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">
                      <circle cx="12" cy="12" r="10" />
                      <path d="M10 8v8" />
                      <path d="M14 8v8" />
                    </svg>
                  </span>
                  <strong><?= (int) $inactive_users ?></strong>
                  <span class="users-stat-label">Inactive Accounts</span>
                </li>
                <li class="stat-card-tooltip" data-title="Users with admin privileges">
                  <span class="rd-stat-icon rd-i-purple" aria-hidden="true">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">
                      <path d="M3 7l4 4 5-7 5 7 4-4v10H3z" />
                      <path d="M3 17h18" />
                    </svg>
                  </span>
                  <strong><?= (int) $admin_users ?></strong>
                  <span class="users-stat-label">Admin Accounts</span>
                </li>
              </ul>
             </div>
           </div>
         </div>

        <div class="section-card users-directory-card" id="users-directory">
          <div class="section-card__header users-directory-header">
            <div class="users-directory-header__content">
              <span class="section-card__title">Users</span>
              <p class="users-directory-header__meta">Showing <?= (int) $page_range_start ?>–<?= (int) $page_range_end ?> of <?= (int) $total_users ?> user(s)</p>
            </div>
            <div class="users-directory-header__actions">
              <button type="button" class="btn-primary users-directory-header__add-btn" id="create-user-trigger" aria-label="Add new user account">Add User</button>
            </div>
          </div>

          <div class="users-filter-bar-modern">
            <form method="get" action="<?= htmlspecialchars($self_url, ENT_QUOTES, 'UTF-8') ?>" class="users-filter-form-modern" role="search">
              <div class="users-filter-input-wrapper">
                <svg class="users-filter-icon" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2">
                  <circle cx="11" cy="11" r="8"></circle>
                  <path d="m21 21-4.35-4.35"></path>
                </svg>
                <input class="field-input users-filter-input" type="text" id="users-search-input" name="q" placeholder="Search by name or email" value="<?= htmlspecialchars($q, ENT_QUOTES, 'UTF-8') ?>" aria-label="Search users by name or email">
              </div>
              <div class="users-filter-selects" aria-label="Filter users by role and status">
                <label class="sr-only" for="users-role-filter">Filter by role</label>
                <select class="field-select" id="users-role-filter" name="role">
                  <option value="">All roles</option>
                  <option value="admin" <?= $role_filter === 'admin' ? 'selected' : '' ?>>Admin</option>
                  <option value="librarian" <?= $role_filter === 'librarian' ? 'selected' : '' ?>>Librarian</option>
                  <option value="borrower" <?= $role_filter === 'borrower' ? 'selected' : '' ?>>Borrower</option>
                </select>
                <label class="sr-only" for="users-status-filter">Filter by account status</label>
                <select class="field-select" id="users-status-filter" name="status">
                  <option value="">All statuses</option>
                  <option value="active" <?= $status_filter === 'active' ? 'selected' : '' ?>>Active</option>
                  <option value="inactive" <?= $status_filter === 'inactive' ? 'selected' : '' ?>>Deactivated</option>
                </select>
              </div>
              <div class="users-filter-actions">
                <button type="submit" class="btn-primary" aria-label="Search users">Search</button>
                <?php if ($q !== '' || $role_filter !== '' || $status_filter !== ''): ?>
                  <a href="<?= htmlspecialchars($self_url, ENT_QUOTES, 'UTF-8') ?>" class="btn-ghost" aria-label="Clear search">Clear</a>
                <?php endif; ?>
              </div>
            </form>
          </div>

           <?php if (empty($users)): ?>
              <div class="users-empty-state">
                <div class="users-empty-state__icon rd-stat-icon rd-i-blue" aria-hidden="true">
                  <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M17 20h5v-2a4 4 0 0 0-4-4h-1" />
                    <path d="M9 20H2v-2a4 4 0 0 1 4-4h1" />
                    <circle cx="9" cy="7" r="3" />
                    <circle cx="17" cy="7" r="3" />
                  </svg>
                </div>
                <h3 class="users-empty-state__title"><?= ($q !== '') ? 'No users found' : 'No users yet' ?></h3>
                <p class="users-empty-state__description">
                  <?= ($q !== '')
                        ? 'No results for <strong>"' . htmlspecialchars($q, ENT_QUOTES, 'UTF-8') . '"</strong>. Try a different name or email.'
                        : 'Add your first user account to get started managing the library.' ?>
                </p>
                <div class="users-empty-state__action">
                  <?php if ($q !== ''): ?>
                    <a href="<?= htmlspecialchars($self_url, ENT_QUOTES, 'UTF-8') ?>" class="btn-ghost">Clear search</a>
                  <?php else: ?>
                    <button type="button" class="btn-primary" id="create-user-empty-state" aria-label="Add new user account">Add User</button>
                  <?php endif; ?>
                </div>
              </div>
           <?php else: ?>
          <form method="post" action="<?= htmlspecialchars($self_url, ENT_QUOTES, 'UTF-8') ?>" class="users-bulk-form" id="users-bulk-form">
            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
            <input type="hidden" name="action" value="bulk_update">
            <div id="users-bulk-selected-container"></div>

             <div class="users-bulk-toolbar" id="users-bulk-toolbar" role="group" aria-label="Bulk user actions">
               <div class="users-bulk-toolbar__selection">
                 <label class="users-bulk-toolbar__check" for="users-select-all">
                   <input type="checkbox" id="users-select-all" aria-describedby="users-result-meta">
                   Select all on this page
                 </label>
                 <span id="users-selected-count" class="users-bulk-toolbar__count">No users selected</span>
                 <span id="users-bulk-status" class="users-bulk-toolbar__status">Select users to begin.</span>
               </div>

                <div class="users-bulk-toolbar__controls">
                  <label class="sr-only" for="bulk_action">Choose bulk action</label>
                   <select id="bulk_action" name="bulk_action" class="field-select" required>
                     <option value="">Choose action...</option>
                     <option value="activate">Activate selected users</option>
                     <option value="deactivate">Deactivate selected users</option>
                     <option value="role_borrower">Set as Borrower</option>
                     <option value="role_librarian">Set as Librarian</option>
                     <option value="role_admin">Set as Admin</option>
                   </select>
                   <button type="submit" id="apply-bulk-action" class="btn-primary" disabled>Apply</button>
                 </div>
              </div>
            <p class="users-result-meta" id="users-result-meta" aria-live="polite" aria-atomic="true">
              Showing <?= (int) $page_range_start ?>-<?= (int) $page_range_end ?> of <?= (int) $total_users ?> user(s).
              <?php if ($total_pages > 1): ?>
                Page <?= (int) $page ?> of <?= (int) $total_pages ?>.
              <?php endif; ?>
            </p>
          </form>

          <div class="tbl-wrapper users-table-shell">
            <table class="tbl users-table" aria-describedby="users-result-meta">
              <colgroup>
                <col class="users-table__col users-table__col--select">
                <col class="users-table__col users-table__col--identity">
                <col class="users-table__col users-table__col--access">
                <col class="users-table__col users-table__col--trust">
                <col class="users-table__col users-table__col--operations">
              </colgroup>
              <caption class="sr-only">User directory with identity, access policy, trust state, and operations</caption>
              <thead>
                <tr>
                  <th scope="col" class="users-table__th--select">
                    <span class="sr-only">Selection</span>
                  </th>
                  <th scope="col" class="users-table__th--identity">Identity</th>
                  <th scope="col" class="users-table__th--access">Access</th>
                  <th scope="col" class="users-table__th--trust">Trust</th>
                  <th scope="col" class="users-table__th--operations">Operations</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($users as $u): ?>
                  <?php
                  $uid = (int) $u['id'];
                  $safe_name = htmlspecialchars((string) $u['full_name'], ENT_QUOTES, 'UTF-8');
                  $safe_email = htmlspecialchars((string) $u['email'], ENT_QUOTES, 'UTF-8');
                  $role = (string) $u['role'];
                  $role_label = (string) ($role_meta[$role]['label'] ?? ucfirst($role));
                  $role_description = (string) ($role_meta[$role]['description'] ?? '');
                  $role_requires_verified_flag = $role_requires_verification($role);
                  $verified = (int) ($u['is_verified'] ?? 0) === 1;
                  $suspended = (int) ($u['is_suspended'] ?? 0) === 1;
                  $is_superadmin_row = (int) ($u['is_superadmin'] ?? 0) === 1;
                  $created_at_raw = (string) ($u['created_at'] ?? '');
                  $created_at_stamp = $created_at_raw !== '' ? strtotime($created_at_raw) : false;
                  $created_at_text = $created_at_stamp ? date('M j, Y', $created_at_stamp) : 'Unknown date';
                  ?>
                  <tr
                    class="users-row<?= $focus_user_id === $uid ? ' users-row--focus-target' : '' ?><?= !$verified ? ' users-row--needs-verification' : '' ?><?= $is_superadmin_row ? ' users-row--protected' : '' ?>"
                    id="user-row-<?= $uid ?>"
                    data-user-id="<?= $uid ?>"
                    data-user-name="<?= $safe_name ?>"
                    data-user-email="<?= $safe_email ?>"
                    data-user-role="<?= htmlspecialchars($role, ENT_QUOTES, 'UTF-8') ?>"
                    data-user-role-label="<?= htmlspecialchars($role_label, ENT_QUOTES, 'UTF-8') ?>"
                    data-user-role-description="<?= htmlspecialchars($role_description, ENT_QUOTES, 'UTF-8') ?>"
                    data-user-role-requires-verified="<?= $role_requires_verified_flag ? '1' : '0' ?>"
                    data-user-verified="<?= $verified ? '1' : '0' ?>"
                    data-user-suspended="<?= $suspended ? '1' : '0' ?>"
                    data-user-protected="<?= $is_superadmin_row ? '1' : '0' ?>"
                    data-user-created="<?= htmlspecialchars($created_at_text, ENT_QUOTES, 'UTF-8') ?>">
                    <td data-label="Selection" class="users-cell users-cell--select">
                      <?php if (!$is_superadmin_row): ?>
                        <label class="users-select-touch" for="user-select-<?= $uid ?>">
                          <span class="sr-only">Select <?= $safe_name ?></span>
                          <input id="user-select-<?= $uid ?>" type="checkbox" value="<?= $uid ?>" class="users-select-row" data-verified="<?= $verified ? '1' : '0' ?>" aria-label="Select <?= $safe_name ?>">
                        </label>
                      <?php else: ?>
                        <span class="sr-only">Protected account cannot be selected</span>
                      <?php endif; ?>
                    </td>

                    <td data-label="Identity" class="users-cell users-cell--identity">
                      <div class="users-identity">
                        <?php $avatar = get_avatar_display($u['avatar_url'] ?? null, (string) $u['full_name']); ?>
                        <?php if ($avatar['type'] === 'image'): ?>
                          <img src="<?= htmlspecialchars($avatar['url'], ENT_QUOTES, 'UTF-8') ?>" alt="" class="users-avatar-initial users-avatar-img" width="40" height="40" loading="lazy">
                        <?php else: ?>
                          <div class="users-avatar-initial" aria-hidden="true" data-initials="<?= htmlspecialchars(mb_substr((string) $u['full_name'], 0, 1), ENT_QUOTES, 'UTF-8') ?>" style="--avatar-color: hsl(<?= ($uid * 47 + 210) % 360 ?>, 55%, 62%)"><?= htmlspecialchars(mb_substr((string) $u['full_name'], 0, 1), ENT_QUOTES, 'UTF-8') ?></div>
                        <?php endif; ?>
                        <p class="users-identity__name"><?= $safe_name ?></p>
                        <p class="users-identity__email"><?= $safe_email ?></p>
                        <p class="users-identity__meta">Joined <?= htmlspecialchars($created_at_text, ENT_QUOTES, 'UTF-8') ?><?= $uid === $actor_id ? ' • You' : '' ?></p>
                      </div>
                    </td>

                    <td data-label="Access" class="users-cell users-cell--access">
                      <div class="users-access-block">
                        <div class="users-access">
                          <?php if ($role === 'admin'): ?>
                            <span class="badge badge-blue">Admin</span>
                          <?php elseif ($role === 'librarian'): ?>
                            <span class="badge badge-amber">Librarian</span>
                          <?php else: ?>
                            <span class="badge"><?= htmlspecialchars($role_label, ENT_QUOTES, 'UTF-8') ?></span>
                          <?php endif; ?>
                          <?php if ($is_superadmin_row): ?>
                            <span class="badge badge-red">Superadmin</span>
                          <?php endif; ?>
                          <?php if ($role_requires_verified_flag && !$verified): ?>
                            <span class="badge badge-red users-policy-chip">Needs verification</span>
                          <?php endif; ?>
                        </div>
                      </div>
                    </td>

                    <td data-label="Trust" class="users-cell users-cell--trust">
                      <div class="users-trust">
                        <?php if ($verified): ?>
                          <span class="badge badge-green users-state users-state--ok">Verified</span>
                        <?php else: ?>
                          <span class="badge badge-red users-state users-state--warn">Not verified</span>
                        <?php endif; ?>
                        <?php if ($suspended): ?>
                          <span class="badge badge-red users-state users-state--warn">Deactivated</span>
                        <?php else: ?>
                          <span class="badge badge-green users-state users-state--ok">Active</span>
                        <?php endif; ?>
                      </div>
                    </td>

                    <td data-label="Operations" class="users-cell users-actions-cell">
                      <?php if ($is_superadmin_row): ?>
                        <span class="users-protected-label">Protected account</span>
                      <?php else: ?>
                        <div class="users-ops">
                          <?php if ($suspended): ?>
                            <form method="post" action="<?= htmlspecialchars($self_url, ENT_QUOTES, 'UTF-8') ?>" class="users-quick-form" data-user-name="<?= $safe_name ?>" data-quick-action="activate">
                              <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                              <input type="hidden" name="action" value="activate">
                              <input type="hidden" name="user_id" value="<?= $uid ?>">
                              <input type="hidden" name="user_name" value="<?= $safe_name ?>">
                              <button type="submit" class="btn-confirm users-row-action-btn">Reactivate</button>
                            </form>
                          <?php else: ?>
                            <form method="post" action="<?= htmlspecialchars($self_url, ENT_QUOTES, 'UTF-8') ?>" class="users-quick-form" data-user-name="<?= $safe_name ?>" data-quick-action="deactivate">
                              <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                              <input type="hidden" name="action" value="deactivate">
                              <input type="hidden" name="user_id" value="<?= $uid ?>">
                              <input type="hidden" name="user_name" value="<?= $safe_name ?>">
                              <button type="submit" class="btn-accent users-row-action-btn">Deactivate</button>
                            </form>
                          <?php endif; ?>

                          <button type="button" class="btn-ghost users-manage-trigger" data-user-id="<?= $uid ?>" aria-haspopup="dialog" aria-controls="manage-user-modal">Manage</button>

                          <details class="users-actions-fallback users-nojs-only">
                            <summary>Manage (no JS)</summary>
                            <div class="users-actions-fallback__panel">
                              <form method="post" action="<?= htmlspecialchars($self_url, ENT_QUOTES, 'UTF-8') ?>" class="users-fallback-form">
                                <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                                <input type="hidden" name="action" value="update_user_credentials">
                                <input type="hidden" name="user_id" value="<?= $uid ?>">
                                <label class="sr-only" for="fallback-name-<?= $uid ?>">Full name</label>
                                <input id="fallback-name-<?= $uid ?>" type="text" name="full_name" class="field-input" value="<?= $safe_name ?>" required>
                                <label class="sr-only" for="fallback-email-<?= $uid ?>">Email</label>
                                <input id="fallback-email-<?= $uid ?>" type="email" name="email" class="field-input" value="<?= $safe_email ?>" required>
                                <button type="submit" class="btn-ghost">Save credentials</button>
                              </form>

                              <form method="post" action="<?= htmlspecialchars($self_url, ENT_QUOTES, 'UTF-8') ?>" class="users-fallback-form">
                                <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                                <input type="hidden" name="action" value="update_user_verification">
                                <input type="hidden" name="user_id" value="<?= $uid ?>">
                                <label class="sr-only" for="fallback-verification-<?= $uid ?>">Verification</label>
                                <select id="fallback-verification-<?= $uid ?>" name="is_verified" class="field-select">
                                  <option value="1" <?= $verified ? 'selected' : '' ?>>Verified</option>
                                  <option value="0" <?= !$verified ? 'selected' : '' ?> <?= $role_requires_verified_flag ? 'disabled' : '' ?>>Not verified</option>
                                </select>
                                <button type="submit" class="btn-ghost">Save verification</button>
                              </form>

                              <form method="post" action="<?= htmlspecialchars($self_url, ENT_QUOTES, 'UTF-8') ?>" class="users-fallback-form">
                                <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                                <input type="hidden" name="action" value="role_change">
                                <input type="hidden" name="user_id" value="<?= $uid ?>">
                                <label class="sr-only" for="fallback-role-<?= $uid ?>">Role</label>
                                <select id="fallback-role-<?= $uid ?>" name="new_role" class="field-select">
                                  <option value="admin" <?= $role === 'admin' ? 'selected' : '' ?> <?= !$verified && $role !== 'admin' ? 'disabled' : '' ?>>Admin</option>
                                  <option value="librarian" <?= $role === 'librarian' ? 'selected' : '' ?> <?= !$verified && $role !== 'librarian' ? 'disabled' : '' ?>>Librarian</option>
                                  <option value="borrower" <?= $role === 'borrower' ? 'selected' : '' ?>>Borrower</option>
                                </select>
                                <button type="submit" class="btn-ghost">Save role</button>
                              </form>

                              <form method="post" action="<?= htmlspecialchars($self_url, ENT_QUOTES, 'UTF-8') ?>" class="users-fallback-form">
                                <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                                <input type="hidden" name="action" value="admin_reset_user_password">
                                <input type="hidden" name="user_id" value="<?= $uid ?>">
                                <label class="sr-only" for="fallback-password-new-<?= $uid ?>">New password</label>
                                <input id="fallback-password-new-<?= $uid ?>" type="password" name="new_password" class="field-input" minlength="8" placeholder="New password (8+ chars)" required autocomplete="new-password">
                                <label class="sr-only" for="fallback-password-confirm-<?= $uid ?>">Confirm password</label>
                                <input id="fallback-password-confirm-<?= $uid ?>" type="password" name="confirm_password" class="field-input" minlength="8" placeholder="Confirm password" required autocomplete="new-password">
                                <button type="submit" class="btn-ghost">Update password</button>
                              </form>

                              <form method="post" action="<?= htmlspecialchars($self_url, ENT_QUOTES, 'UTF-8') ?>" class="users-fallback-form users-fallback-form--danger">
                                <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                                <input type="hidden" name="action" value="delete_user">
                                <input type="hidden" name="user_id" value="<?= $uid ?>">
                                <label class="users-delete-form__confirm">
                                  <input type="checkbox" name="delete_confirm" value="1" required>
                                  Confirm permanent deletion
                                </label>
                                <button type="submit" class="btn-ghost users-actions__delete">Delete account</button>
                              </form>
                            </div>
                          </details>
                        </div>
                      <?php endif; ?>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>

    <!-- Manage User Modal -->
    <div class="modal-overlay" id="manage-user-modal-overlay" role="presentation" aria-hidden="true" hidden>
      <div class="modal-dialog" id="manage-user-modal" role="dialog" aria-modal="true" aria-labelledby="manage-user-modal-title">
        <div class="modal-header">
          <div>
            <h2 class="modal-title" id="manage-user-modal-title">Manage User</h2>
            <p class="modal-subtitle">Update identity, access, verification, and account controls.</p>
          </div>
          <button type="button" class="modal-close-btn" id="manage-user-modal-close" aria-label="Close dialog">
            <svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2">
              <line x1="18" y1="6" x2="6" y2="18"></line>
              <line x1="6" y1="6" x2="18" y2="18"></line>
            </svg>
          </button>
        </div>
        <div class="modal-body">
          <section class="users-manage-summary" aria-live="polite" aria-atomic="true">
            <p class="users-manage-summary__name" id="users-manage-name">User account</p>
            <p class="users-manage-summary__email" id="users-manage-email"></p>
            <div class="users-manage-summary__chips">
              <span class="badge" id="users-manage-role-chip"></span>
              <span class="badge users-state" id="users-manage-verified-chip"></span>
              <span class="badge users-state" id="users-manage-status-chip"></span>
            </div>
            <p class="users-manage-summary__joined" id="users-manage-joined"></p>
          </section>

          <section class="users-manage-section">
            <h3 class="users-manage-section__title">Credentials</h3>
            <form method="post" action="<?= htmlspecialchars($self_url, ENT_QUOTES, 'UTF-8') ?>" id="users-manage-credentials-form" class="users-manage-form users-credentials-form">
              <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
              <input type="hidden" name="action" value="update_user_credentials">
              <input type="hidden" name="user_id" value="">
              <label class="sr-only" for="users-manage-full-name">Full name</label>
              <input id="users-manage-full-name" type="text" name="full_name" class="field-input" required>
              <label class="sr-only" for="users-manage-email-input">Email address</label>
              <input id="users-manage-email-input" type="email" name="email" class="field-input" required>
              <button type="submit" class="btn-ghost">Save credentials</button>
            </form>
          </section>

          <section class="users-manage-section">
            <h3 class="users-manage-section__title">Access Policy</h3>
            <form method="post" action="<?= htmlspecialchars($self_url, ENT_QUOTES, 'UTF-8') ?>" id="users-manage-verification-form" class="users-manage-form users-verification-form">
              <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
              <input type="hidden" name="action" value="update_user_verification">
              <input type="hidden" name="user_id" value="">
              <label class="sr-only" for="users-manage-verification">Verification status</label>
              <select id="users-manage-verification" name="is_verified" class="field-select users-verification-form__select">
                <option value="1">Verified</option>
                <option value="0">Not verified</option>
              </select>
              <p class="users-field-help users-verification-help" id="users-manage-verification-help"></p>
              <button type="submit" class="btn-ghost">Save verification</button>
            </form>

            <form method="post" action="<?= htmlspecialchars($self_url, ENT_QUOTES, 'UTF-8') ?>" id="users-manage-role-form" class="users-manage-form users-role-form">
              <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
              <input type="hidden" name="action" value="role_change">
              <input type="hidden" name="user_id" value="">
              <label class="sr-only" for="users-manage-role">Role</label>
              <select id="users-manage-role" name="new_role" class="field-select users-role-form__select">
                <option value="admin" data-role-label="Admin" data-requires-verified="1" data-role-description="<?= htmlspecialchars($role_meta['admin']['description'], ENT_QUOTES, 'UTF-8') ?>">Admin</option>
                <option value="librarian" data-role-label="Librarian" data-requires-verified="1" data-role-description="<?= htmlspecialchars($role_meta['librarian']['description'], ENT_QUOTES, 'UTF-8') ?>">Librarian</option>
                <option value="borrower" data-role-label="Borrower" data-requires-verified="0" data-role-description="<?= htmlspecialchars($role_meta['borrower']['description'], ENT_QUOTES, 'UTF-8') ?>">Borrower</option>
              </select>
              <p class="users-field-help users-role-help" id="users-manage-role-help"></p>
              <button type="submit" class="btn-ghost">Save role</button>
            </form>
          </section>

          <section class="users-manage-section">
            <h3 class="users-manage-section__title">Password Reset</h3>
            <form method="post" action="<?= htmlspecialchars($self_url, ENT_QUOTES, 'UTF-8') ?>" id="users-manage-password-form" class="users-manage-form users-password-reset-form">
              <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
              <input type="hidden" name="action" value="admin_reset_user_password">
              <input type="hidden" name="user_id" value="">
              <label class="sr-only" for="users-manage-password-new">New password</label>
              <input id="users-manage-password-new" type="password" name="new_password" class="field-input" minlength="8" placeholder="New password (8+ chars)" required autocomplete="new-password">
              <label class="sr-only" for="users-manage-password-confirm">Confirm password</label>
              <input id="users-manage-password-confirm" type="password" name="confirm_password" class="field-input" minlength="8" placeholder="Confirm password" required autocomplete="new-password">
              <button type="submit" class="btn-ghost">Update password</button>
            </form>
          </section>

          <section class="users-manage-section">
            <h3 class="users-manage-section__title">Account Status</h3>
            <div class="users-manage-status-actions">
              <form method="post" action="<?= htmlspecialchars($self_url, ENT_QUOTES, 'UTF-8') ?>" id="users-manage-activate-form" class="users-manage-form users-quick-form" data-quick-action="activate" hidden>
                <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                <input type="hidden" name="action" value="activate">
                <input type="hidden" name="user_id" value="">
                <input type="hidden" name="user_name" value="">
                <button type="submit" class="btn-confirm">Reactivate account</button>
              </form>

              <form method="post" action="<?= htmlspecialchars($self_url, ENT_QUOTES, 'UTF-8') ?>" id="users-manage-deactivate-form" class="users-manage-form users-quick-form" data-quick-action="deactivate" hidden>
                <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                <input type="hidden" name="action" value="deactivate">
                <input type="hidden" name="user_id" value="">
                <input type="hidden" name="user_name" value="">
                <button type="submit" class="btn-accent">Deactivate account</button>
              </form>
            </div>
          </section>

          <section class="users-manage-section users-manage-section--danger">
            <h3 class="users-manage-section__title users-manage-section__title--danger">Permanent Delete</h3>
            <p class="users-actions__note">Delete only when this account should be permanently removed.</p>
            <form method="post" action="<?= htmlspecialchars($self_url, ENT_QUOTES, 'UTF-8') ?>" id="users-manage-delete-form" class="users-manage-form users-delete-form" data-user-role="">
              <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
              <input type="hidden" name="action" value="delete_user">
              <input type="hidden" name="user_id" value="">
              <label class="users-delete-form__confirm">
                <input type="checkbox" id="users-manage-delete-confirm" name="delete_confirm" value="1" required>
                Confirm permanent deletion.
              </label>
              <button type="submit" class="btn-ghost users-actions__delete">Delete account</button>
            </form>
          </section>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn-ghost" id="manage-user-modal-cancel-btn">Close</button>
        </div>
      </div>
    </div>

             <nav class="pagination users-pagination" aria-label="User directory pagination">
               <div class="pagination-info">
                 <span class="pagination-current">Page <?= (int) $page ?> of <?= max(1, (int) $total_pages) ?></span>
               </div>
               <div class="pagination-controls">
                 <?php if ($page > 1): ?>
                   <a class="btn-ghost pagination-btn" href="<?= htmlspecialchars($build_page_url($page - 1), ENT_QUOTES, 'UTF-8') ?>" aria-label="Previous page">← Previous</a>
                 <?php else: ?>
                   <button class="btn-ghost pagination-btn" disabled aria-label="Previous page">← Previous</button>
                 <?php endif; ?>

                 <?php if ($page < $total_pages): ?>
                   <a class="btn-ghost pagination-btn" href="<?= htmlspecialchars($build_page_url($page + 1), ENT_QUOTES, 'UTF-8') ?>" aria-label="Next page">Next →</a>
                 <?php else: ?>
                   <button class="btn-ghost pagination-btn" disabled aria-label="Next page">Next →</button>
                 <?php endif; ?>
               </div>
               <div class="pagination-per-page">
                 <form method="get" action="<?= htmlspecialchars($self_url, ENT_QUOTES, 'UTF-8') ?>" class="pagination-per-page-form">
                   <?php if ($q !== ''): ?><input type="hidden" name="q" value="<?= htmlspecialchars($q, ENT_QUOTES, 'UTF-8') ?>"><?php endif; ?>
                   <?php if ($role_filter !== ''): ?><input type="hidden" name="role" value="<?= htmlspecialchars($role_filter, ENT_QUOTES, 'UTF-8') ?>"><?php endif; ?>
                   <?php if ($status_filter !== ''): ?><input type="hidden" name="status" value="<?= htmlspecialchars($status_filter, ENT_QUOTES, 'UTF-8') ?>"><?php endif; ?>
                   <label for="users-per-page-select" class="pagination-per-page-label">Per page</label>
                   <select id="users-per-page-select" name="per_page" class="field-select pagination-per-page-select">
                     <option value="10" <?= $per_page === 10 ? 'selected' : '' ?>>10</option>
                     <option value="25" <?= $per_page === 25 ? 'selected' : '' ?>>25</option>
                     <option value="50" <?= $per_page === 50 ? 'selected' : '' ?>>50</option>
                     <option value="100" <?= $per_page === 100 ? 'selected' : '' ?>>100</option>
                   </select>
                 </form>
               </div>
             </nav>
        <?php endif; ?>
       </div>
       </div>

    <!-- Create User Modal -->
    <div class="modal-overlay" id="create-user-modal-overlay" role="presentation" aria-hidden="true" data-open-on-load="<?= $create_user_error !== '' ? '1' : '0' ?>">
      <div class="modal-dialog" id="create-user-modal" role="dialog" aria-modal="true" aria-labelledby="create-user-modal-title">
        <div class="modal-header">
          <h2 class="modal-title" id="create-user-modal-title">Add User</h2>
          <button type="button" class="modal-close-btn" id="create-user-modal-close" aria-label="Close dialog">
            <svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2">
              <line x1="18" y1="6" x2="6" y2="18"></line>
              <line x1="6" y1="6" x2="18" y2="18"></line>
            </svg>
          </button>
        </div>
        <div class="modal-body">
          <form id="create-user-form" method="post" action="<?= htmlspecialchars($self_url, ENT_QUOTES, 'UTF-8') ?>" class="users-create-form-modal" novalidate>
            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
            <input type="hidden" name="action" value="create_user">

            <!-- Full Name Field -->
            <div class="field-group">
              <label for="modal-full_name" class="field-label">Full Name <span aria-label="required">*</span></label>
              <input
                type="text"
                id="modal-full_name"
                name="full_name"
                class="field-input"
                placeholder="e.g., Jane Smith"
                value="<?= htmlspecialchars((string) $create_user_old['full_name'], ENT_QUOTES, 'UTF-8') ?>"
                required
                aria-required="true"
                aria-describedby="full_name-error">
              <span id="full_name-error" class="field-error" role="alert"></span>
            </div>

            <!-- Email Field -->
            <div class="field-group">
              <label for="modal-email" class="field-label">Email Address <span aria-label="required">*</span></label>
              <input
                type="email"
                id="modal-email"
                name="email"
                class="field-input"
                placeholder="e.g., jane@example.com"
                value="<?= htmlspecialchars((string) $create_user_old['email'], ENT_QUOTES, 'UTF-8') ?>"
                required
                aria-required="true"
                aria-describedby="email-error">
              <span id="email-error" class="field-error" role="alert"></span>
            </div>

            <!-- Password Field -->
            <div class="field-group">
              <label for="modal-password" class="field-label">Password <span aria-label="required">*</span></label>
              <input
                type="password"
                id="modal-password"
                name="password"
                class="field-input"
                placeholder="8+ characters"
                required
                aria-required="true"
                aria-describedby="password-error password-help">
              <small id="password-help" class="field-help">Must be at least 8 characters</small>
              <span id="password-error" class="field-error" role="alert"></span>
            </div>

            <!-- Role Field -->
            <div class="field-group">
              <label for="modal-role" class="field-label">Account Type <span aria-label="required">*</span></label>
              <select
                id="modal-role"
                name="role"
                class="field-select"
                required
                aria-required="true"
                aria-describedby="role-error role-help">
                <option value="">Choose an account type</option>
                <option value="borrower" data-requires-verified="0" <?= $create_user_old['role'] === 'borrower' ? 'selected' : '' ?>>Borrower — Check out books, manage loans</option>
                <option value="librarian" data-requires-verified="1" <?= $create_user_old['role'] === 'librarian' ? 'selected' : '' ?>>Librarian — Manage catalog and circulation</option>
                <option value="admin" data-requires-verified="1" <?= $create_user_old['role'] === 'admin' ? 'selected' : '' ?>>Admin — Manage users and system settings</option>
              </select>
              <small id="role-help" class="field-help">Admin and Librarian accounts must be verified before access is granted.</small>
              <span id="role-error" class="field-error" role="alert"></span>
            </div>

            <!-- Verification Checkbox -->
            <div class="field-group">
              <label for="modal-is_verified" class="field-checkbox">
                <input
                  type="checkbox"
                  id="modal-is_verified"
                  name="is_verified"
                  value="1"
                  <?= (int) $create_user_old['is_verified'] === 1 ? 'checked' : '' ?>>
                <span>Mark email as verified</span>
              </label>
            </div>

            <!-- Form Error Message -->
            <div id="modal-form-error" class="field-error" role="alert" aria-live="polite" style="<?= $create_user_error !== '' ? 'display:block;' : 'display:none;' ?> margin-bottom: var(--space-4);"><?= htmlspecialchars($create_user_error, ENT_QUOTES, 'UTF-8') ?></div>
          </form>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn-ghost" id="modal-cancel-btn">Cancel</button>
          <button type="submit" form="create-user-form" class="btn-primary" id="modal-submit-btn">Add User</button>
        </div>
      </div>
    </div>

     </main>
  </div>

  <script src="<?= htmlspecialchars(BASE_URL . 'assets/js/admin-users.js', ENT_QUOTES, 'UTF-8') ?>"></script>
 </body>


 </html>
