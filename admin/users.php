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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_verify();

  $post_action = $_POST['action'] ?? '';
  $allowed_roles_list = ['admin', 'librarian', 'borrower'];

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

      $stmt = $pdo->prepare('SELECT id, role, is_suspended, is_superadmin FROM Users WHERE id = ? FOR UPDATE');
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
        "SELECT id, role, is_suspended, is_superadmin
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
          $is_suspended = (int) $target['is_suspended'] === 1;

          if ($current_role === $bulk_new_role) {
            $skipped_noop++;
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

      $stmt = $pdo->prepare('SELECT id, role, is_suspended, is_superadmin FROM Users WHERE id = ? FOR UPDATE');
      $stmt->execute([$user_id]);
      $target = $stmt->fetch();

      if (!$target) {
        $pdo->rollBack();
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
if ($q !== '') {
  if (strlen($q) < 2) {
    $flash_error = 'Enter at least 2 characters to search.';
  } elseif (strlen($q) > 100) {
    $flash_error = 'Search text is too long (maximum 100 characters).';
  }
}

$where_sql = '';
$query_params = [];
if ($q !== '' && $flash_error === '') {
  $where_sql = ' WHERE full_name LIKE :q OR email LIKE :q';
  $query_params[':q'] = '%' . $q . '%';
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

$per_page = 25;
$total_pages = max(1, (int) ceil($total_users / $per_page));
$page = max(1, (int) ($_GET['page'] ?? 1));
if ($page > $total_pages) {
  $page = $total_pages;
}
$offset = ($page - 1) * $per_page;

$stmt = $pdo->prepare(
  'SELECT id, full_name, email, role, is_superadmin, is_verified, is_suspended, created_at
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

$build_page_url = static function (int $target_page) use ($self_url, $q): string {
  $params = ['page' => $target_page];
  if ($q !== '') {
    $params['q'] = $q;
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
  <?php require_once __DIR__ . '/../includes/head.php'; ?>
</head>

<body>
  <div class="app-shell">
    <?php require_once __DIR__ . '/../includes/sidebar-admin.php'; ?>
    <main class="main-content admin-users-page">
      <div class="users-layout">
      <div class="page-header users-page-header">
         <div>
           <h1>User Accounts</h1>
           <p>Add accounts and manage who can access the library.</p>
         </div>
         <div class="page-header__actions">
           <button type="button" class="btn-primary" id="create-user-trigger" aria-label="Add new user account">Add User</button>
         </div>
       </div>

      <nav class="admin-subnav page-tabs users-subnav" aria-label="Admin management navigation">
        <a href="<?= BASE_URL ?>admin/users.php" class="admin-subnav__item page-tabs__item active" aria-current="page">Users</a>
        <a href="<?= BASE_URL ?>admin/about.php" class="admin-subnav__item page-tabs__item">Profile</a>
        <a href="<?= BASE_URL ?>admin/change-password.php" class="admin-subnav__item page-tabs__item">Password</a>
      </nav>

      <div id="users-live-announcer" class="sr-only" role="status" aria-live="polite" aria-atomic="true"></div>

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
                  <span class="stat-card-icon">👥</span>
                  <strong><?= (int) $total_users ?></strong>
                  <span>Total</span>
                </li>
                <li class="stat-card-tooltip" data-title="Active users who can log in">
                  <span class="stat-card-icon">✓</span>
                  <strong><?= (int) $active_users ?></strong>
                  <span>Active</span>
                </li>
                <li class="stat-card-tooltip" data-title="Inactive users (archived or suspended)">
                  <span class="stat-card-icon">⏸</span>
                  <strong><?= (int) $inactive_users ?></strong>
                  <span>Inactive</span>
                </li>
                <li class="stat-card-tooltip" data-title="Users with admin privileges">
                  <span class="stat-card-icon">👑</span>
                  <strong><?= (int) $admin_users ?></strong>
                  <span>Admins</span>
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
              <div class="users-filter-actions">
                <button type="submit" class="btn-primary" aria-label="Search users">Search</button>
                <?php if ($q !== ''): ?>
                  <a href="<?= htmlspecialchars($self_url, ENT_QUOTES, 'UTF-8') ?>" class="btn-ghost" aria-label="Clear search">Clear</a>
                <?php endif; ?>
              </div>
            </form>
          </div>

           <?php if (empty($users)): ?>
              <div class="users-empty-state">
                <div class="users-empty-state__icon">👥</div>
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
                    <option value="delete" class="users-bulk-delete-option">Delete selected users</option>
                  </select>
                  <button type="submit" id="apply-bulk-action" class="btn-primary" disabled>Apply</button>
                  <button type="button" id="bulk-delete-confirm-btn" class="btn-accent users-bulk-toolbar__delete" style="display: none;" aria-label="Delete selected users">Delete Users</button>
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
                 <col class="users-table__col users-table__col--user">
                 <col class="users-table__col users-table__col--joined">
                 <col class="users-table__col users-table__col--role">
                 <col class="users-table__col users-table__col--verified">
                 <col class="users-table__col users-table__col--status">
                 <col class="users-table__col users-table__col--actions">
               </colgroup>
              <caption class="sr-only">User directory with role, status, verification, and account actions</caption>
               <thead>
                 <tr>
                   <th scope="col" class="users-table__th--select">
                     <span class="sr-only">Selection</span>
                   </th>
                   <th scope="col" class="users-table__th--user">User</th>
                   <th scope="col" class="users-table__th--joined">Joined</th>
                   <th scope="col" class="users-table__th--role">Role</th>
                   <th scope="col" class="users-table__th--verified">Verified</th>
                   <th scope="col" class="users-table__th--status">Status</th>
                   <th scope="col" class="users-table__th--actions">Actions</th>
                 </tr>
               </thead>
              <tbody>
                <?php foreach ($users as $u): ?>
                  <?php
                  $uid = (int) $u['id'];
                  $safe_name = htmlspecialchars((string) $u['full_name'], ENT_QUOTES, 'UTF-8');
                  $safe_email = htmlspecialchars((string) $u['email'], ENT_QUOTES, 'UTF-8');
                  $role = (string) $u['role'];
                  $verified = (int) ($u['is_verified'] ?? 0) === 1;
                  $suspended = (int) ($u['is_suspended'] ?? 0) === 1;
                  $is_superadmin_row = (int) ($u['is_superadmin'] ?? 0) === 1;
                  $created_at_raw = (string) ($u['created_at'] ?? '');
                  $created_at_stamp = $created_at_raw !== '' ? strtotime($created_at_raw) : false;
                  $created_at_text = $created_at_stamp ? date('M j, Y', $created_at_stamp) : 'Unknown date';
                  ?>
                  <tr class="users-row">
                    <td data-label="Select">
                      <?php if (!$is_superadmin_row): ?>
                        <label class="users-select-touch" for="user-select-<?= $uid ?>">
                          <span class="sr-only">Select <?= $safe_name ?></span>
                          <input id="user-select-<?= $uid ?>" type="checkbox" value="<?= $uid ?>" class="users-select-row" aria-label="Select <?= $safe_name ?>">
                        </label>
                      <?php else: ?>
                        <span class="users-select-disabled">Protected</span>
                      <?php endif; ?>
                    </td>
                    <td data-label="User">
                       <div class="users-identity">
                         <p class="users-identity__name"><?= $safe_name ?></p>
                         <p class="users-identity__email"><?= $safe_email ?></p>
                       </div>
                     </td>
                     <td data-label="Joined" class="users-cell users-cell--joined">
                       <span class="users-joined"><?= htmlspecialchars($created_at_text, ENT_QUOTES, 'UTF-8') ?></span>
                     </td>
                     <td data-label="Role" class="users-cell users-cell--role">
                      <div class="users-access">
                        <?php if ($role === 'admin'): ?>
                          <span class="badge badge-blue">Admin</span>
                        <?php elseif ($role === 'librarian'): ?>
                          <span class="badge badge-amber">Librarian</span>
                        <?php else: ?>
                          <span class="badge"><?= htmlspecialchars(ucfirst($role), ENT_QUOTES, 'UTF-8') ?></span>
                        <?php endif; ?>
                        <?php if ($is_superadmin_row): ?>
                          <span class="badge badge-red">Superadmin</span>
                        <?php endif; ?>
                      </div>
                    </td>
                    <td data-label="Verified" class="users-cell users-cell--verified">
                      <?php if ($verified): ?>
                        <span class="badge badge-green users-state users-state--ok">Verified</span>
                      <?php else: ?>
                        <span class="badge badge-red users-state users-state--warn">Not verified</span>
                      <?php endif; ?>
                    </td>
                    <td data-label="Status" class="users-cell users-cell--status">
                      <?php if ($suspended): ?>
                        <span class="badge badge-red users-state users-state--warn">Deactivated</span>
                      <?php else: ?>
                        <span class="badge badge-green users-state users-state--ok">Active</span>
                      <?php endif; ?>
                    </td>
                    <td data-label="Actions" class="users-actions-cell">
                      <?php if ($is_superadmin_row): ?>
                        <span class="empty-icon">Protected account</span>
                      <?php else: ?>
                        <div class="users-actions">
                          <?php if ($suspended): ?>
                            <form method="post" action="<?= htmlspecialchars($self_url, ENT_QUOTES, 'UTF-8') ?>">
                              <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                              <input type="hidden" name="action" value="activate">
                              <input type="hidden" name="user_id" value="<?= $uid ?>">
                              <input type="hidden" name="user_name" value="<?= $safe_name ?>">
                              <button type="submit" class="btn-confirm users-row-action-btn">Reactivate</button>
                            </form>
                          <?php else: ?>
                            <form method="post" action="<?= htmlspecialchars($self_url, ENT_QUOTES, 'UTF-8') ?>">
                              <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                              <input type="hidden" name="action" value="deactivate">
                              <input type="hidden" name="user_id" value="<?= $uid ?>">
                              <input type="hidden" name="user_name" value="<?= $safe_name ?>">
                              <button type="submit" class="btn-accent users-row-action-btn">Deactivate</button>
                            </form>
                          <?php endif; ?>

                          <details class="users-actions__more">
                            <summary>Role &amp; delete</summary>
                            <div class="users-actions__panel">
                              <form method="post" action="<?= htmlspecialchars($self_url, ENT_QUOTES, 'UTF-8') ?>" class="users-role-form" data-user-name="<?= $safe_name ?>" data-current-role="<?= htmlspecialchars($role, ENT_QUOTES, 'UTF-8') ?>">
                                <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                                <input type="hidden" name="action" value="role_change">
                                <input type="hidden" name="user_id" value="<?= $uid ?>">
                                <select name="new_role" class="field-select users-role-form__select">
                                  <option value="admin" <?= $role === 'admin' ? 'selected' : '' ?>>Admin</option>
                                  <option value="librarian" <?= $role === 'librarian' ? 'selected' : '' ?>>Librarian</option>
                                  <option value="borrower" <?= $role === 'borrower' ? 'selected' : '' ?>>Borrower</option>
                                </select>
                                <button type="submit" class="btn-ghost">Save role</button>
                              </form>

                              <p class="users-actions__label users-actions__label--danger">Permanent action</p>
                              <p class="users-actions__note">Delete only if this account should be removed permanently.</p>
                              <form method="post" action="<?= htmlspecialchars($self_url, ENT_QUOTES, 'UTF-8') ?>" class="users-delete-form" data-user-name="<?= $safe_name ?>">
                                <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                                <input type="hidden" name="action" value="delete_user">
                                <input type="hidden" name="user_id" value="<?= $uid ?>">
                                <label class="users-delete-form__confirm">
                                  <input type="checkbox" name="delete_confirm" value="1" required>
                                  Confirm permanent deletion.
                                </label>
                                <button type="submit" class="btn-ghost users-actions__delete">Delete Account</button>
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

           <?php if ($total_pages > 1): ?>
             <nav class="pagination users-pagination" aria-label="User directory pagination">
               <div class="pagination-info">
                 <span class="pagination-current">Page <?= (int) $page ?> of <?= (int) $total_pages ?></span>
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
             </nav>
           <?php endif; ?>
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
                <option value="borrower" <?= $create_user_old['role'] === 'borrower' ? 'selected' : '' ?>>Borrower — Check out books, manage loans</option>
                <option value="librarian" <?= $create_user_old['role'] === 'librarian' ? 'selected' : '' ?>>Librarian — Manage catalog and circulation</option>
                <option value="admin" <?= $create_user_old['role'] === 'admin' ? 'selected' : '' ?>>Admin — Manage users and system settings</option>
              </select>
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

  <script>
    (function() {
      const forms = document.querySelectorAll('.users-actions form, .users-create-form, .users-bulk-form, .users-undo__form, .users-warning__actions');
      const deactivateForms = document.querySelectorAll('form input[name="action"][value="deactivate"], form input[name="action"][value="activate"]');
      const bulkForm = document.getElementById('users-bulk-form');
      const selectAll = document.getElementById('users-select-all');
      const rowChecks = document.querySelectorAll('.users-select-row');
      const selectedCount = document.getElementById('users-selected-count');
      const bulkStatus = document.getElementById('users-bulk-status');
      const bulkToolbar = document.getElementById('users-bulk-toolbar');
      const bulkAction = document.getElementById('bulk_action');
      const bulkApply = document.getElementById('apply-bulk-action');
      const bulkSelectedContainer = document.getElementById('users-bulk-selected-container');
      const liveAnnouncer = document.getElementById('users-live-announcer');
      const roleForms = document.querySelectorAll('.users-role-form');
      const deleteForms = document.querySelectorAll('.users-delete-form');
      let hasInitializedBulkState = false;

      function announce(message) {
        if (!liveAnnouncer || !message) {
          return;
        }
        liveAnnouncer.textContent = '';
        window.setTimeout(function() {
          liveAnnouncer.textContent = message;
        }, 40);
      }

      function formatBulkActionLabel(actionValue, selected) {
        if (actionValue === 'activate') {
          return 'Activate ' + selected + ' user' + (selected === 1 ? '' : 's');
        }
        if (actionValue === 'deactivate') {
          return 'Deactivate ' + selected + ' user' + (selected === 1 ? '' : 's');
        }
        if (actionValue === 'role_borrower') {
          return 'Set ' + selected + ' user' + (selected === 1 ? '' : 's') + ' as Borrower';
        }
        if (actionValue === 'role_librarian') {
          return 'Set ' + selected + ' user' + (selected === 1 ? '' : 's') + ' as Librarian';
        }
        if (actionValue === 'role_admin') {
          return 'Set ' + selected + ' user' + (selected === 1 ? '' : 's') + ' as Admin';
        }
        return 'Apply action';
      }

      forms.forEach((form) => {
        if (!(form instanceof HTMLFormElement)) {
          return;
        }
        form.addEventListener('submit', function() {
          const submit = form.querySelector('button[type="submit"]');
          if (!submit) {
            return;
          }
          submit.disabled = true;
          submit.dataset.originalText = submit.textContent;
          submit.textContent = 'Working...';
        });
      });

      deactivateForms.forEach((input) => {
        const parentForm = input.closest('form');
        if (!parentForm) {
          return;
        }
        const isDeactivate = input.value === 'deactivate';
        parentForm.addEventListener('submit', function(e) {
          if (typeof sweetAlertUtils !== 'undefined') return; // Handled by SweetAlert handler below
          
          const hiddenName = parentForm.querySelector('input[name="user_name"]');
          const userName = hiddenName ? hiddenName.value : 'this account';
          const message = isDeactivate
            ? 'Deactivate "' + userName + '" now? They will immediately lose sign-in access.'
            : 'Reactivate "' + userName + '" now?';
          if (!window.confirm(message)) {
            e.preventDefault();
            const submit = parentForm.querySelector('button[type="submit"]');
            if (submit) {
              submit.disabled = false;
              if (submit.dataset.originalText) {
                submit.textContent = submit.dataset.originalText;
              }
            }
          }
        });
      });

      roleForms.forEach((form) => {
        form.addEventListener('submit', function(e) {
          if (typeof sweetAlertUtils !== 'undefined') return; // Handled by SweetAlert handler below
          
          const select = form.querySelector('select[name="new_role"]');
          if (!select) {
            return;
          }
          const nextRole = select.value;
          const userName = form.dataset.userName || 'this user';
          const currentRole = form.dataset.currentRole || '';
          if (currentRole === nextRole) {
            e.preventDefault();
            window.alert('No change detected. Choose a different role for ' + userName + '.');
            const submit = form.querySelector('button[type="submit"]');
            if (submit) {
              submit.disabled = false;
              if (submit.dataset.originalText) {
                submit.textContent = submit.dataset.originalText;
              }
            }
            return;
          }
          const message = 'Change role for "' + userName + '" from ' + currentRole + ' to ' + nextRole + '?';
          if (!window.confirm(message)) {
            e.preventDefault();
            const submit = form.querySelector('button[type="submit"]');
            if (submit) {
              submit.disabled = false;
              if (submit.dataset.originalText) {
                submit.textContent = submit.dataset.originalText;
              }
            }
          }
        });
      });

      deleteForms.forEach((form) => {
        form.addEventListener('submit', function(e) {
          if (typeof sweetAlertUtils !== 'undefined') return; // Handled by SweetAlert handler below
          
          const userName = form.dataset.userName || 'this account';
          const message = 'Delete "' + userName + '" permanently? This cannot be undone.';
          if (!window.confirm(message)) {
            e.preventDefault();
            const submit = form.querySelector('button[type="submit"]');
            if (submit) {
              submit.disabled = false;
              if (submit.dataset.originalText) {
                submit.textContent = submit.dataset.originalText;
              }
            }
          }
        });
      });

      function updateBulkState() {
        if (!selectedCount || !bulkApply || !bulkAction) {
          return;
        }
        const selected = Array.from(rowChecks).filter((box) => box.checked).length;
         selectedCount.textContent = selected + ' user' + (selected === 1 ? '' : 's') + ' selected';
         const hasAction = bulkAction.value !== '';
         bulkApply.disabled = !(selected > 0 && hasAction);
         bulkApply.textContent = formatBulkActionLabel(bulkAction.value, selected);
         rowChecks.forEach((box) => {
           const row = box.closest('tr');
           if (!row) {
             return;
           }
           row.classList.toggle('users-row--selected', box.checked);
           row.setAttribute('aria-selected', box.checked ? 'true' : 'false');
         });
         if (bulkStatus) {
           if (selected === 0) {
             bulkStatus.textContent = 'Select users to begin.';
           } else if (!hasAction) {
             bulkStatus.textContent = selected + ' user' + (selected === 1 ? '' : 's') + ' selected. Choose an action.';
           } else {
             bulkStatus.textContent = 'Ready: ' + formatBulkActionLabel(bulkAction.value, selected) + '.';
           }
         }
        if (hasInitializedBulkState) {
          announce(selected + ' users selected.');
        }
        if (selectAll) {
          const total = rowChecks.length;
          selectAll.checked = total > 0 && selected === total;
          selectAll.indeterminate = selected > 0 && selected < total;
        }
        hasInitializedBulkState = true;
      }

      if (selectAll) {
        selectAll.addEventListener('change', function() {
          rowChecks.forEach((box) => {
            box.checked = selectAll.checked;
          });
          updateBulkState();
        });
      }

      rowChecks.forEach((box) => {
        box.addEventListener('change', updateBulkState);
      });

      if (bulkAction) {
        bulkAction.addEventListener('change', updateBulkState);
      }

      if (bulkForm) {
        bulkForm.addEventListener('submit', function(e) {
          const selected = Array.from(rowChecks).filter((box) => box.checked).length;
          if (selected === 0) {
            e.preventDefault();
            window.alert('Select at least one user before applying a bulk action.');
            return;
          }
          if (!bulkAction || bulkAction.value === '') {
            e.preventDefault();
            window.alert('Choose a bulk action before applying.');
            return;
          }

          if (bulkSelectedContainer) {
            bulkSelectedContainer.innerHTML = '';
            rowChecks.forEach((box) => {
              if (!box.checked) {
                return;
              }
              const hidden = document.createElement('input');
              hidden.type = 'hidden';
              hidden.name = 'selected_users[]';
              hidden.value = box.value;
              bulkSelectedContainer.appendChild(hidden);
            });
          }

          const label = bulkAction.options[bulkAction.selectedIndex]?.text || 'this action';
          announce('Preparing to apply ' + label + ' to ' + selected + ' users.');
          if (!window.confirm('Apply "' + label + '" to ' + selected + ' selected user(s)?')) {
            e.preventDefault();
            if (bulkApply) {
              bulkApply.disabled = false;
              if (bulkApply.dataset.originalText) {
                bulkApply.textContent = bulkApply.dataset.originalText;
              }
            }
          }
        });
      }

      updateBulkState();

      // Modal functionality
      (function() {
        const modalOverlay = document.getElementById('create-user-modal-overlay');
        const modalDialog = document.getElementById('create-user-modal');
        const modalTrigger = document.getElementById('create-user-trigger');
        const modalCloseBtn = document.getElementById('create-user-modal-close');
        const modalCancelBtn = document.getElementById('modal-cancel-btn');
        const createUserForm = document.getElementById('create-user-form');
        const modalSubmitBtn = document.getElementById('modal-submit-btn');
        const formErrorDiv = document.getElementById('modal-form-error');

        if (!modalOverlay || !modalDialog || !modalTrigger) {
          return;
        }

        // Get all focusable elements in modal
        function getFocusableElements() {
          const focusableSelectors = [
            'a[href]',
            'button:not([disabled])',
            'input:not([disabled])',
            'select:not([disabled])',
            'textarea:not([disabled])',
            '[tabindex]:not([tabindex="-1"])'
          ].join(', ');
          return Array.from(modalDialog.querySelectorAll(focusableSelectors));
        }

        // Show modal
        function showModal() {
          modalOverlay.classList.add('active');
          modalOverlay.setAttribute('aria-hidden', 'false');
          document.body.style.overflow = 'hidden';
          
          // Focus on first input field
          setTimeout(function() {
            const firstInput = createUserForm.querySelector('input[type="text"], input[type="email"]');
            if (firstInput) {
              firstInput.focus();
            }
          }, 100);
        }

        // Hide modal
        function hideModal() {
          modalOverlay.classList.remove('active');
          modalOverlay.setAttribute('aria-hidden', 'true');
          document.body.style.overflow = '';
          clearFormErrors();
          createUserForm.reset();
          formErrorDiv.style.display = 'none';
          modalTrigger.focus();
        }

        // Clear form errors
        function clearFormErrors() {
          document.querySelectorAll('.field-error').forEach(function(el) {
            el.textContent = '';
          });
        }

        // Focus trap
        function handleKeyDown(e) {
          if (e.key !== 'Tab') {
            return;
          }

          const focusableElements = getFocusableElements();
          if (focusableElements.length === 0) {
            return;
          }

          const firstElement = focusableElements[0];
          const lastElement = focusableElements[focusableElements.length - 1];
          const activeElement = document.activeElement;

          if (e.shiftKey) {
            if (activeElement === firstElement) {
              e.preventDefault();
              lastElement.focus();
            }
          } else {
            if (activeElement === lastElement) {
              e.preventDefault();
              firstElement.focus();
            }
          }
        }

        // Handle escape key
        function handleEscapeKey(e) {
          if (e.key === 'Escape') {
            hideModal();
          }
        }

        // Handle click outside modal
        function handleClickOutside(e) {
          if (e.target === modalOverlay) {
            hideModal();
          }
        }

        // Validate form fields
        function validateForm() {
          clearFormErrors();
          const fullName = createUserForm.querySelector('input[name="full_name"]').value.trim();
          const email = createUserForm.querySelector('input[name="email"]').value.trim();
          const password = createUserForm.querySelector('input[name="password"]').value;
          const role = createUserForm.querySelector('select[name="role"]').value;
          let isValid = true;

          if (!fullName) {
            document.getElementById('full_name-error').textContent = 'Enter the person\'s full name.';
            isValid = false;
          }

          if (!email) {
            document.getElementById('email-error').textContent = 'Enter an email address.';
            isValid = false;
          } else if (!isValidEmail(email)) {
            document.getElementById('email-error').textContent = 'Email must be valid (e.g., jane@example.com).';
            isValid = false;
          }

          if (!password) {
            document.getElementById('password-error').textContent = 'Enter a password.';
            isValid = false;
          } else if (password.length < 8) {
            document.getElementById('password-error').textContent = 'Password must be at least 8 characters.';
            isValid = false;
          }

          if (!role) {
            document.getElementById('role-error').textContent = 'Choose an account type.';
            isValid = false;
          }

          return isValid;
        }

        // Email validation helper
        function isValidEmail(email) {
          const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
          return emailRegex.test(email);
        }

        // Handle form submission
        function handleSubmit(e) {
          e.preventDefault();

          if (!validateForm()) {
            formErrorDiv.textContent = 'Fix the errors above, then try again.';
            formErrorDiv.style.display = 'block';
            announce('Please fix the errors.');
            return;
          }

          formErrorDiv.style.display = 'none';
          modalSubmitBtn.disabled = true;
          modalSubmitBtn.dataset.originalText = modalSubmitBtn.textContent;
          modalSubmitBtn.textContent = 'Adding user...';

          // Submit normally so the server PRG flow sets flash messages correctly.
          // Success and error SweetAlert notices are displayed after redirect.
          announce('Submitting new user account.');
          createUserForm.submit();
        }

        // Event listeners
        modalTrigger.addEventListener('click', showModal);
        modalCloseBtn.addEventListener('click', hideModal);
        modalCancelBtn.addEventListener('click', hideModal);
        modalOverlay.addEventListener('keydown', handleKeyDown);
        modalOverlay.addEventListener('keydown', handleEscapeKey);
        modalOverlay.addEventListener('click', handleClickOutside);
        createUserForm.addEventListener('submit', handleSubmit);

        if (modalOverlay.getAttribute('data-open-on-load') === '1') {
          showModal();
          if (formErrorDiv && formErrorDiv.textContent.trim() !== '') {
            formErrorDiv.style.display = 'block';
            announce('Add user form has errors.');
          }
        }
       })();
    })();

    // ── ENHANCED FEATURES: Onboarding, Keyboard Shortcuts, Empty State ──
    (function() {
      'use strict';

      // Check if user has dismissed onboarding banner (localStorage)
      const ONBOARDING_KEY = 'libris_users_onboarding_dismissed';
      const onboardingBanner = document.getElementById('users-onboarding-banner');
      const dismissBtn = document.getElementById('dismiss-onboarding');
      const searchInput = document.getElementById('users-search-input');
      const createUserTrigger = document.getElementById('create-user-trigger');
      const createUserEmptyState = document.getElementById('create-user-empty-state');
      const bulkToolbar = document.getElementById('users-bulk-toolbar');
      const bulkForm = document.getElementById('users-bulk-form');
      const selectAllCheckbox = document.getElementById('users-select-all');
      const bulkDeleteBtn = document.getElementById('bulk-delete-confirm-btn');
      const bulkActionSelect = document.getElementById('bulk_action');
      const applyBulkActionBtn = document.getElementById('apply-bulk-action');

      // ── Show onboarding banner if not dismissed ──
      if (onboardingBanner) {
        const isDismissed = localStorage.getItem(ONBOARDING_KEY);
        if (!isDismissed) {
          onboardingBanner.style.display = 'grid';
        }
        
        if (dismissBtn) {
          dismissBtn.addEventListener('click', function() {
            localStorage.setItem(ONBOARDING_KEY, 'true');
            onboardingBanner.style.display = 'none';
          });
        }
      }

      // ── Auto-focus search input on page load ──
      if (searchInput) {
        // Small delay to ensure DOM is fully ready
        setTimeout(function() {
          searchInput.focus();
        }, 100);
      }

      // ── Keyboard shortcuts ──
      document.addEventListener('keydown', function(e) {
        // Cmd+K / Ctrl+K to focus search
        if ((e.metaKey || e.ctrlKey) && e.key === 'k') {
          e.preventDefault();
          if (searchInput) {
            searchInput.focus();
            searchInput.select();
          }
        }

        // Cmd+N / Ctrl+N to open "Add User" modal
        if ((e.metaKey || e.ctrlKey) && e.key === 'n') {
          e.preventDefault();
          if (createUserTrigger) {
            createUserTrigger.click();
          }
        }
      });

      // ── Handle "Add User" from empty state ──
      if (createUserEmptyState) {
        createUserEmptyState.addEventListener('click', function() {
          if (createUserTrigger) {
            createUserTrigger.click();
          }
        });
      }

       // ── Bulk delete functionality ──
       if (bulkDeleteBtn && bulkForm && bulkActionSelect) {
         bulkDeleteBtn.addEventListener('click', async function(e) {
           e.preventDefault();
           
           const selectedCheckboxes = document.querySelectorAll(
             '.users-bulk-form input[type="checkbox"][name="user_ids[]"]:checked'
           );
           
           if (selectedCheckboxes.length === 0) {
             await sweetAlertUtils.showError('No Users Selected', 'Please select at least one user to delete.');
             return;
           }

           const result = await sweetAlertUtils.confirmBulkDelete(selectedCheckboxes.length);
           if (result.isConfirmed) {
             bulkActionSelect.value = 'delete';
             applyBulkActionBtn.click();
           }
         });
       }

      // ── Update bulk toolbar visibility based on selection ──
      function updateBulkToolbarVisibility() {
        if (!bulkToolbar) return;

        const selectedCheckboxes = document.querySelectorAll(
          '.users-bulk-form input[type="checkbox"][name="user_ids[]"]:checked'
        );
        const hasSelection = selectedCheckboxes.length > 0;

        if (hasSelection) {
          bulkToolbar.classList.add('users-bulk-toolbar--has-selection');
          if (bulkDeleteBtn) {
            bulkDeleteBtn.style.display = 'inline-flex';
          }
        } else {
          bulkToolbar.classList.remove('users-bulk-toolbar--has-selection');
          if (bulkDeleteBtn) {
            bulkDeleteBtn.style.display = 'none';
          }
        }
      }

      // ── Listen to checkbox changes ──
      if (selectAllCheckbox) {
        selectAllCheckbox.addEventListener('change', updateBulkToolbarVisibility);
      }

      const userCheckboxes = document.querySelectorAll(
        '.users-bulk-form input[type="checkbox"][name="user_ids[]"]'
      );
      userCheckboxes.forEach(function(checkbox) {
        checkbox.addEventListener('change', updateBulkToolbarVisibility);
      });

      // Initial visibility update
      updateBulkToolbarVisibility();

      // ── Enhanced error recovery ──
      const flashError = document.querySelector('.flash.flash-error');
      if (flashError) {
        const errorText = flashError.textContent;
        
        // Add helpful guidance for common errors
        if (errorText.includes('active loan') || errorText.includes('loan')) {
          const suggestion = document.createElement('p');
          suggestion.style.marginTop = 'var(--space-2)';
          suggestion.style.fontSize = 'var(--text-xs)';
          suggestion.style.color = 'var(--muted)';
          suggestion.innerHTML = '<strong>Tip:</strong> Return the user\'s items first, or archive the account instead of deleting it.';
          flashError.appendChild(suggestion);
        }
      }

     })();

     // ── SWEETALERT2 INTEGRATION: Individual User Actions ──
     document.addEventListener('DOMContentLoaded', function() {
       'use strict';
       
       if (typeof sweetAlertUtils === 'undefined') {
         return; // Fail gracefully if SweetAlert is not loaded
       }

       // Handle role change forms
       const roleChangeForms = document.querySelectorAll('.users-role-form');
       roleChangeForms.forEach(function(form) {
         form.addEventListener('submit', async function(e) {
           e.preventDefault();

           const userName = form.getAttribute('data-user-name');
           const currentRole = form.getAttribute('data-current-role');
           const newRoleSelect = form.querySelector('select[name="new_role"]');
           const newRole = newRoleSelect.value;

           if (newRole === currentRole) {
             await sweetAlertUtils.showInfo('No Change', 'The new role is the same as the current role.');
             return;
           }

           const result = await sweetAlertUtils.confirmChangeRole(
             'Change User Role',
             userName,
             currentRole,
             newRole
           );

           if (result.isConfirmed) {
             form.submit();
           }
         });
       });

       // Handle delete forms
       const deleteUserForms = document.querySelectorAll('.users-delete-form');
       deleteUserForms.forEach(function(form) {
         form.addEventListener('submit', async function(e) {
           e.preventDefault();

           const userName = form.getAttribute('data-user-name');
           const confirmCheckbox = form.querySelector('input[name="delete_confirm"]');

           const result = await sweetAlertUtils.confirmDeleteUser(userName, 'Account will be permanently deleted');

           if (result.isConfirmed) {
             // Set the checkbox to confirm deletion
             confirmCheckbox.checked = true;
             form.submit();
           }
         });
       });

       // Handle deactivate forms
       const deactivateForms = document.querySelectorAll('form[action*="users.php"] input[name="action"][value="deactivate"]');
       deactivateForms.forEach(function(input) {
         const form = input.closest('form');
         if (form) {
           form.addEventListener('submit', async function(e) {
             e.preventDefault();

             const userName = form.querySelector('input[name="user_name"]').value;
             const result = await sweetAlertUtils.confirmSuspendUser(userName, 'Account will be deactivated');

             if (result.isConfirmed) {
               form.submit();
             }
           });
         }
       });

       // Handle activation forms
       const activateForms = document.querySelectorAll('form[action*="users.php"] input[name="action"][value="activate"]');
       activateForms.forEach(function(input) {
         const form = input.closest('form');
         if (form) {
           form.addEventListener('submit', async function(e) {
             e.preventDefault();

             const userName = form.querySelector('input[name="user_name"]').value;
             const result = await sweetAlertUtils.confirmAction(
               'Reactivate Account?',
               '<p>This account will be reactivated and the user can log in again.</p><p><strong>' + escapeHtml(userName) + '</strong></p>',
               'Reactivate',
               'Cancel'
             );

             if (result.isConfirmed) {
               form.submit();
             }
           });
         }
       });

       // Helper function to escape HTML
       function escapeHtml(text) {
         const map = {
           '&': '&amp;',
           '<': '&lt;',
           '>': '&gt;',
           '"': '&quot;',
           "'": '&#039;'
         };
         return text.replace(/[&<>"']/g, (char) => map[char]);
       }

       // Handle flash messages
       const flashSuccess = document.getElementById('users-flash-success');
       const flashError = document.getElementById('users-flash-error');

       if (flashSuccess) {
         const message = flashSuccess.getAttribute('data-message');
         if (message) {
           setTimeout(async function() {
             await sweetAlertUtils.showSuccess('Success', message, 3000);
           }, 300);
         }
       }

       if (flashError) {
         const message = flashError.getAttribute('data-message');
         if (message) {
           setTimeout(async function() {
             await sweetAlertUtils.showError('Error', message);
           }, 300);
         }
       }
     });
   </script>
 </body>


 </html>
