-- Read-only checks for "reject librarian request" production failures.

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
