-- Diagnostics for "reject librarian request" production failures.
-- The final UPDATE is wrapped in a transaction and rolled back.

SHOW COLUMNS FROM `Users` WHERE Field IN (
  'id',
  'role',
  'is_superadmin',
  'is_verified',
  'is_suspended',
  'verification_token',
  'staff_approval_status',
  'staff_rejected_by',
  'staff_rejected_at',
  'staff_rejection_reason'
);

SHOW COLUMNS FROM `System_Logs` WHERE Field IN (
  'actor_id',
  'actor_role',
  'action_type',
  'target_entity',
  'target_id',
  'outcome'
);

SHOW CREATE TABLE `System_Logs`;

SELECT id, full_name, email, role, is_superadmin, is_verified, is_suspended, staff_approval_status
  FROM `Users`
 WHERE role = 'librarian'
 ORDER BY id DESC
 LIMIT 10;

SELECT @diagnose_pending_user_id := id
  FROM `Users`
 WHERE role = 'librarian'
   AND staff_approval_status = 'pending'
   AND COALESCE(is_superadmin, 0) = 0
 ORDER BY id DESC
 LIMIT 1;

SELECT @diagnose_actor_id := id
  FROM `Users`
 WHERE role = 'admin'
 ORDER BY is_superadmin DESC, id ASC
 LIMIT 1;

SELECT
  @diagnose_pending_user_id AS pending_user_id,
  @diagnose_actor_id AS actor_id;

START TRANSACTION;

UPDATE `Users`
   SET is_verified = 0,
       is_suspended = 1,
       verification_token = NULL,
       staff_approval_status = 'rejected',
       staff_rejected_by = @diagnose_actor_id,
       staff_rejected_at = NOW(),
       staff_rejection_reason = NULLIF('', '')
 WHERE id = @diagnose_pending_user_id
 LIMIT 1;

SELECT ROW_COUNT() AS diagnose_reject_rows_changed;

ROLLBACK;
