  -- Safe production migration for librarian registration approval.
  -- Re-runnable: adds only missing Users columns/index.

  SET @schema_name := DATABASE();

SET @add_staff_approval_status := IF(
  EXISTS(SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=@schema_name AND TABLE_NAME='Users' AND COLUMN_NAME='staff_approval_status'),
  'SELECT "skip Users.staff_approval_status"',
  'ALTER TABLE `Users` ADD COLUMN `staff_approval_status` VARCHAR(20) NOT NULL DEFAULT ''none'' AFTER `is_suspended`'
);
PREPARE stmt FROM @add_staff_approval_status; EXECUTE stmt; DEALLOCATE PREPARE stmt;

ALTER TABLE `Users` MODIFY COLUMN `staff_approval_status` VARCHAR(20) NOT NULL DEFAULT 'none';

  SET @add_staff_approved_by := IF(
    EXISTS(SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=@schema_name AND TABLE_NAME='Users' AND COLUMN_NAME='staff_approved_by'),
    'SELECT "skip Users.staff_approved_by"',
    'ALTER TABLE `Users` ADD COLUMN `staff_approved_by` INT UNSIGNED NULL AFTER `staff_approval_status`'
  );
  PREPARE stmt FROM @add_staff_approved_by; EXECUTE stmt; DEALLOCATE PREPARE stmt;

  SET @add_staff_approved_at := IF(
    EXISTS(SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=@schema_name AND TABLE_NAME='Users' AND COLUMN_NAME='staff_approved_at'),
    'SELECT "skip Users.staff_approved_at"',
    'ALTER TABLE `Users` ADD COLUMN `staff_approved_at` DATETIME NULL AFTER `staff_approved_by`'
  );
  PREPARE stmt FROM @add_staff_approved_at; EXECUTE stmt; DEALLOCATE PREPARE stmt;

  SET @add_staff_rejected_by := IF(
    EXISTS(SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=@schema_name AND TABLE_NAME='Users' AND COLUMN_NAME='staff_rejected_by'),
    'SELECT "skip Users.staff_rejected_by"',
    'ALTER TABLE `Users` ADD COLUMN `staff_rejected_by` INT UNSIGNED NULL AFTER `staff_approved_at`'
  );
  PREPARE stmt FROM @add_staff_rejected_by; EXECUTE stmt; DEALLOCATE PREPARE stmt;

  SET @add_staff_rejected_at := IF(
    EXISTS(SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=@schema_name AND TABLE_NAME='Users' AND COLUMN_NAME='staff_rejected_at'),
    'SELECT "skip Users.staff_rejected_at"',
    'ALTER TABLE `Users` ADD COLUMN `staff_rejected_at` DATETIME NULL AFTER `staff_rejected_by`'
  );
  PREPARE stmt FROM @add_staff_rejected_at; EXECUTE stmt; DEALLOCATE PREPARE stmt;

  SET @add_staff_rejection_reason := IF(
    EXISTS(SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=@schema_name AND TABLE_NAME='Users' AND COLUMN_NAME='staff_rejection_reason'),
    'SELECT "skip Users.staff_rejection_reason"',
    'ALTER TABLE `Users` ADD COLUMN `staff_rejection_reason` VARCHAR(255) NULL AFTER `staff_rejected_at`'
  );
  PREPARE stmt FROM @add_staff_rejection_reason; EXECUTE stmt; DEALLOCATE PREPARE stmt;

  ALTER TABLE `Users` MODIFY COLUMN `staff_rejection_reason` VARCHAR(255) NULL;

  SET @add_idx_users_staff_approval := IF(
    EXISTS(SELECT 1 FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA=@schema_name AND TABLE_NAME='Users' AND INDEX_NAME='idx_users_staff_approval'),
    'SELECT "skip idx_users_staff_approval"',
    'CREATE INDEX `idx_users_staff_approval` ON `Users` (`staff_approval_status`, `role`)'
  );
  PREPARE stmt FROM @add_idx_users_staff_approval; EXECUTE stmt; DEALLOCATE PREPARE stmt;

  CREATE TABLE IF NOT EXISTS `System_Logs` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `actor_id` INT UNSIGNED DEFAULT NULL,
    `actor_role` VARCHAR(50) DEFAULT NULL,
    `action_type` VARCHAR(100) NOT NULL,
    `target_entity` VARCHAR(100) DEFAULT NULL,
    `target_id` INT UNSIGNED DEFAULT NULL,
    `email_address` VARCHAR(255) DEFAULT NULL,
    `outcome` VARCHAR(50) NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

  SET @add_log_actor_id := IF(
    EXISTS(SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=@schema_name AND TABLE_NAME='System_Logs' AND COLUMN_NAME='actor_id'),
    'SELECT "skip System_Logs.actor_id"',
    'ALTER TABLE `System_Logs` ADD COLUMN `actor_id` INT UNSIGNED DEFAULT NULL AFTER `id`'
  );
  PREPARE stmt FROM @add_log_actor_id; EXECUTE stmt; DEALLOCATE PREPARE stmt;

  SET @add_log_actor_role := IF(
    EXISTS(SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=@schema_name AND TABLE_NAME='System_Logs' AND COLUMN_NAME='actor_role'),
    'SELECT "skip System_Logs.actor_role"',
    'ALTER TABLE `System_Logs` ADD COLUMN `actor_role` VARCHAR(50) DEFAULT NULL AFTER `actor_id`'
  );
  PREPARE stmt FROM @add_log_actor_role; EXECUTE stmt; DEALLOCATE PREPARE stmt;

  SET @add_log_action_type := IF(
    EXISTS(SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=@schema_name AND TABLE_NAME='System_Logs' AND COLUMN_NAME='action_type'),
    'SELECT "skip System_Logs.action_type"',
    'ALTER TABLE `System_Logs` ADD COLUMN `action_type` VARCHAR(100) NOT NULL AFTER `actor_role`'
  );
  PREPARE stmt FROM @add_log_action_type; EXECUTE stmt; DEALLOCATE PREPARE stmt;

  SET @add_log_target_entity := IF(
    EXISTS(SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=@schema_name AND TABLE_NAME='System_Logs' AND COLUMN_NAME='target_entity'),
    'SELECT "skip System_Logs.target_entity"',
    'ALTER TABLE `System_Logs` ADD COLUMN `target_entity` VARCHAR(100) DEFAULT NULL AFTER `action_type`'
  );
  PREPARE stmt FROM @add_log_target_entity; EXECUTE stmt; DEALLOCATE PREPARE stmt;

  SET @add_log_target_id := IF(
    EXISTS(SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=@schema_name AND TABLE_NAME='System_Logs' AND COLUMN_NAME='target_id'),
    'SELECT "skip System_Logs.target_id"',
    'ALTER TABLE `System_Logs` ADD COLUMN `target_id` INT UNSIGNED DEFAULT NULL AFTER `target_entity`'
  );
  PREPARE stmt FROM @add_log_target_id; EXECUTE stmt; DEALLOCATE PREPARE stmt;

  SET @add_log_email_address := IF(
    EXISTS(SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=@schema_name AND TABLE_NAME='System_Logs' AND COLUMN_NAME='email_address'),
    'SELECT "skip System_Logs.email_address"',
    'ALTER TABLE `System_Logs` ADD COLUMN `email_address` VARCHAR(255) DEFAULT NULL AFTER `target_id`'
  );
  PREPARE stmt FROM @add_log_email_address; EXECUTE stmt; DEALLOCATE PREPARE stmt;

  SET @add_log_outcome := IF(
    EXISTS(SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=@schema_name AND TABLE_NAME='System_Logs' AND COLUMN_NAME='outcome'),
    'SELECT "skip System_Logs.outcome add"',
    'ALTER TABLE `System_Logs` ADD COLUMN `outcome` VARCHAR(50) NOT NULL DEFAULT ''info'' AFTER `email_address`'
  );
  PREPARE stmt FROM @add_log_outcome; EXECUTE stmt; DEALLOCATE PREPARE stmt;

  ALTER TABLE `System_Logs` MODIFY COLUMN `outcome` VARCHAR(50) NOT NULL;

  SET @add_log_created_at := IF(
    EXISTS(SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=@schema_name AND TABLE_NAME='System_Logs' AND COLUMN_NAME='created_at'),
    'SELECT "skip System_Logs.created_at"',
    'ALTER TABLE `System_Logs` ADD COLUMN `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER `outcome`'
  );
  PREPARE stmt FROM @add_log_created_at; EXECUTE stmt; DEALLOCATE PREPARE stmt;
