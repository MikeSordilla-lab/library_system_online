-- Phase 1 receipts/tickets compatibility extension (safe re-run script)
-- Uses INFORMATION_SCHEMA + prepared statements to avoid duplicate-column errors.

SET @schema_name := DATABASE();

SET @add_status := IF(
  EXISTS(SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=@schema_name AND TABLE_NAME='Receipt_Tickets' AND COLUMN_NAME='status'),
  'SELECT "skip Receipt_Tickets.status"',
  'ALTER TABLE `Receipt_Tickets` ADD COLUMN `status` VARCHAR(20) NOT NULL DEFAULT ''issued'' AFTER `issued_at`'
);
PREPARE stmt FROM @add_status; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @add_format := IF(
  EXISTS(SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=@schema_name AND TABLE_NAME='Receipt_Tickets' AND COLUMN_NAME='format'),
  'SELECT "skip Receipt_Tickets.format"',
  'ALTER TABLE `Receipt_Tickets` ADD COLUMN `format` VARCHAR(20) NOT NULL DEFAULT ''thermal'' AFTER `status`'
);
PREPARE stmt FROM @add_format; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @add_locale := IF(
  EXISTS(SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=@schema_name AND TABLE_NAME='Receipt_Tickets' AND COLUMN_NAME='locale'),
  'SELECT "skip Receipt_Tickets.locale"',
  'ALTER TABLE `Receipt_Tickets` ADD COLUMN `locale` VARCHAR(20) NOT NULL DEFAULT ''en_US'' AFTER `format`'
);
PREPARE stmt FROM @add_locale; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @add_timezone := IF(
  EXISTS(SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=@schema_name AND TABLE_NAME='Receipt_Tickets' AND COLUMN_NAME='timezone'),
  'SELECT "skip Receipt_Tickets.timezone"',
  'ALTER TABLE `Receipt_Tickets` ADD COLUMN `timezone` VARCHAR(64) NOT NULL DEFAULT ''UTC'' AFTER `locale`'
);
PREPARE stmt FROM @add_timezone; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @add_payload_hash := IF(
  EXISTS(SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=@schema_name AND TABLE_NAME='Receipt_Tickets' AND COLUMN_NAME='payload_hash'),
  'SELECT "skip Receipt_Tickets.payload_hash"',
  'ALTER TABLE `Receipt_Tickets` ADD COLUMN `payload_hash` CHAR(64) DEFAULT NULL AFTER `payload_json`'
);
PREPARE stmt FROM @add_payload_hash; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @add_payload_signature := IF(
  EXISTS(SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=@schema_name AND TABLE_NAME='Receipt_Tickets' AND COLUMN_NAME='payload_signature'),
  'SELECT "skip Receipt_Tickets.payload_signature"',
  'ALTER TABLE `Receipt_Tickets` ADD COLUMN `payload_signature` CHAR(64) DEFAULT NULL AFTER `payload_hash`'
);
PREPARE stmt FROM @add_payload_signature; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @add_branch_id := IF(
  EXISTS(SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=@schema_name AND TABLE_NAME='Receipt_Tickets' AND COLUMN_NAME='branch_id'),
  'SELECT "skip Receipt_Tickets.branch_id"',
  'ALTER TABLE `Receipt_Tickets` ADD COLUMN `branch_id` INT DEFAULT NULL AFTER `patron_user_id`'
);
PREPARE stmt FROM @add_branch_id; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @add_channel := IF(
  EXISTS(SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=@schema_name AND TABLE_NAME='Receipt_Tickets' AND COLUMN_NAME='channel'),
  'SELECT "skip Receipt_Tickets.channel"',
  'ALTER TABLE `Receipt_Tickets` ADD COLUMN `channel` VARCHAR(30) NOT NULL DEFAULT ''web'' AFTER `branch_id`'
);
PREPARE stmt FROM @add_channel; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @add_log_reason := IF(
  EXISTS(SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=@schema_name AND TABLE_NAME='Receipt_Ticket_Logs' AND COLUMN_NAME='reason'),
  'SELECT "skip Receipt_Ticket_Logs.reason"',
  'ALTER TABLE `Receipt_Ticket_Logs` ADD COLUMN `reason` VARCHAR(255) DEFAULT NULL AFTER `event_type`'
);
PREPARE stmt FROM @add_log_reason; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @add_log_job_target := IF(
  EXISTS(SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=@schema_name AND TABLE_NAME='Receipt_Ticket_Logs' AND COLUMN_NAME='job_target'),
  'SELECT "skip Receipt_Ticket_Logs.job_target"',
  'ALTER TABLE `Receipt_Ticket_Logs` ADD COLUMN `job_target` VARCHAR(50) DEFAULT NULL AFTER `reason`'
);
PREPARE stmt FROM @add_log_job_target; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @add_log_target_status := IF(
  EXISTS(SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=@schema_name AND TABLE_NAME='Receipt_Ticket_Logs' AND COLUMN_NAME='target_status'),
  'SELECT "skip Receipt_Ticket_Logs.target_status"',
  'ALTER TABLE `Receipt_Ticket_Logs` ADD COLUMN `target_status` VARCHAR(20) DEFAULT NULL AFTER `job_target`'
);
PREPARE stmt FROM @add_log_target_status; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @add_log_error := IF(
  EXISTS(SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=@schema_name AND TABLE_NAME='Receipt_Ticket_Logs' AND COLUMN_NAME='error_message'),
  'SELECT "skip Receipt_Ticket_Logs.error_message"',
  'ALTER TABLE `Receipt_Ticket_Logs` ADD COLUMN `error_message` VARCHAR(255) DEFAULT NULL AFTER `target_status`'
);
PREPARE stmt FROM @add_log_error; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @add_log_channel := IF(
  EXISTS(SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=@schema_name AND TABLE_NAME='Receipt_Ticket_Logs' AND COLUMN_NAME='channel'),
  'SELECT "skip Receipt_Ticket_Logs.channel"',
  'ALTER TABLE `Receipt_Ticket_Logs` ADD COLUMN `channel` VARCHAR(30) NOT NULL DEFAULT ''web'' AFTER `error_message`'
);
PREPARE stmt FROM @add_log_channel; EXECUTE stmt; DEALLOCATE PREPARE stmt;
