<?php

require_once __DIR__ . '/admin.php';
require_once __DIR__ . '/staff_approval.php';
require_once __DIR__ . '/constants.php';

/**
 * AdminUserManagementModule — Shared admin account lifecycle rules.
 */
class AdminUserManagementModule
{
  /**
   * @param array<int,string> $allowedRoles
   * @return array<string,mixed>
   */
  public static function changeRole(PDO $pdo, int $actorId, int $userId, string $newRole, array $allowedRoles): array
  {
    if (!in_array($newRole, $allowedRoles, true)) {
      return ['success' => false, 'reason_code' => 'invalid_role'];
    }

    try {
      $pdo->beginTransaction();

      $stmt = $pdo->prepare('SELECT id, role, is_verified, is_suspended, is_superadmin FROM Users WHERE id = ? FOR UPDATE');
      $stmt->execute([$userId]);
      $target = $stmt->fetch();

      if (!$target) {
        $pdo->rollBack();
        return ['success' => false, 'reason_code' => 'not_found'];
      }

      if (self::roleRequiresVerification($newRole) && (int) ($target['is_verified'] ?? 0) !== 1) {
        $pdo->rollBack();
        self::logStandalone($pdo, $actorId, 'role_change', $userId, 'failure:target_not_verified');
        return ['success' => false, 'reason_code' => 'target_not_verified'];
      }

      if (is_superadmin_user($target)) {
        $pdo->rollBack();
        self::logStandalone($pdo, $actorId, 'role_change', $userId, 'failure:protected_superadmin');
        return ['success' => false, 'reason_code' => 'protected_superadmin'];
      }

      if ((string) $target['role'] === ROLE_ADMIN && $newRole !== ROLE_ADMIN) {
        if (count_active_admins($pdo) <= 1) {
          $pdo->rollBack();
          self::logStandalone($pdo, $actorId, 'role_change', $userId, 'failure:last_active_admin');
          return ['success' => false, 'reason_code' => 'last_active_admin'];
        }
      }

      $upd = $pdo->prepare('UPDATE Users SET role = ? WHERE id = ? LIMIT 1');
      $upd->execute([$newRole, $userId]);

      log_admin_action($pdo, [
        'actor_id' => $actorId,
        'actor_role' => ROLE_ADMIN,
        'action_type' => 'role_change',
        'target_entity' => 'Users',
        'target_id' => $userId,
        'outcome' => 'success',
      ]);

      $pdo->commit();

      return [
        'success' => true,
        'user_id' => $userId,
        'previous_role' => (string) $target['role'],
        'new_role' => $newRole,
        'undo' => [
          'type' => 'role_change',
          'user_id' => $userId,
          'previous_role' => (string) $target['role'],
        ],
      ];
    } catch (Throwable $e) {
      if ($pdo->inTransaction()) {
        $pdo->rollBack();
      }
      error_log('[AdminUserManagementModule] changeRole failed: ' . $e->getMessage());
      return ['success' => false, 'reason_code' => 'unexpected_error'];
    }
  }

  /**
   * @return array<string,mixed>
   */
  public static function decideLibrarianRequest(PDO $pdo, int $actorId, int $userId, bool $approve, string $reason = ''): array
  {
    $approvalActorId = $actorId > 0 ? $actorId : null;

    try {
      $pdo->beginTransaction();

      if ($approve) {
        $upd = $pdo->prepare(
          'UPDATE Users
              SET is_verified = 1,
                  is_suspended = 0,
                  verified_at = COALESCE(verified_at, NOW()),
                  verification_token = NULL,
                  staff_approval_status = ?,
                  staff_approved_by = ?,
                  staff_approved_at = NOW(),
                  staff_rejected_by = NULL,
                  staff_rejected_at = NULL,
                  staff_rejection_reason = NULL
            WHERE id = ?
              AND role = ?
              AND staff_approval_status = ?
              AND COALESCE(is_superadmin, 0) = 0
            LIMIT 1'
        );
        $upd->execute([STAFF_APPROVAL_APPROVED, $approvalActorId, $userId, ROLE_LIBRARIAN, STAFF_APPROVAL_PENDING]);
      } else {
        $reason = trim($reason);
        $upd = $pdo->prepare(
          'UPDATE Users
              SET is_verified = 0,
                  is_suspended = 1,
                  verification_token = NULL,
                  staff_approval_status = ?,
                  staff_rejected_by = ?,
                  staff_rejected_at = NOW(),
                  staff_rejection_reason = ?
            WHERE id = ?
              AND role = ?
              AND staff_approval_status = ?
              AND COALESCE(is_superadmin, 0) = 0
            LIMIT 1'
        );
        $upd->execute([STAFF_APPROVAL_REJECTED, $approvalActorId, $reason !== '' ? $reason : null, $userId, ROLE_LIBRARIAN, STAFF_APPROVAL_PENDING]);
      }

      if ($upd->rowCount() < 1) {
        $pdo->rollBack();
        return ['success' => false, 'reason_code' => 'pending_request_not_found'];
      }

      log_admin_action($pdo, [
        'actor_id' => $approvalActorId,
        'actor_role' => ROLE_ADMIN,
        'action_type' => $approve ? 'librarian_request_approve' : 'librarian_request_reject',
        'target_entity' => 'Users',
        'target_id' => $userId,
        'outcome' => 'success',
      ]);

      $pdo->commit();

      return [
        'success' => true,
        'action' => $approve ? 'approve_librarian_request' : 'reject_librarian_request',
        'user_id' => $userId,
      ];
    } catch (Throwable $e) {
      if ($pdo->inTransaction()) {
        $pdo->rollBack();
      }
      error_log('[AdminUserManagementModule] decideLibrarianRequest failed: ' . $e->getMessage());
      return ['success' => false, 'reason_code' => 'unexpected_error', 'message' => $e->getMessage()];
    }
  }

  public static function roleRequiresVerification(string $role): bool
  {
    return in_array($role, [ROLE_ADMIN, ROLE_LIBRARIAN], true);
  }

  private static function logStandalone(PDO $pdo, int $actorId, string $actionType, int $targetId, string $outcome): void
  {
    log_admin_action($pdo, [
      'actor_id' => $actorId,
      'actor_role' => ROLE_ADMIN,
      'action_type' => $actionType,
      'target_entity' => 'Users',
      'target_id' => $targetId,
      'outcome' => $outcome,
    ]);
  }
}
