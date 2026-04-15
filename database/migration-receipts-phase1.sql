-- Phase 1 receipts/tickets schema
-- Backward-compatible: create tables if missing and extend columns if already present.

CREATE TABLE IF NOT EXISTS `Receipt_Tickets` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `receipt_no` VARCHAR(40) NOT NULL,
  `idempotency_key` VARCHAR(120) DEFAULT NULL,
  `type` VARCHAR(50) NOT NULL,
  `library_label` VARCHAR(255) NOT NULL,
  `actor_user_id` INT NOT NULL,
  `patron_user_id` INT NOT NULL,
  `reference_table` VARCHAR(60) NOT NULL,
  `reference_id` BIGINT NOT NULL,
  `amount` DECIMAL(10,2) DEFAULT NULL,
  `currency` CHAR(3) NOT NULL DEFAULT 'USD',
  `payload_json` LONGTEXT NOT NULL,
  `issued_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `ux_receipt_tickets_receipt_no` (`receipt_no`),
  UNIQUE KEY `ux_receipt_tickets_idempotency` (`idempotency_key`),
  KEY `idx_receipt_tickets_patron_issued` (`patron_user_id`, `issued_at`),
  KEY `idx_receipt_tickets_actor_issued` (`actor_user_id`, `issued_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `Receipt_Tickets`
  ADD COLUMN `status` VARCHAR(20) NOT NULL DEFAULT 'issued' AFTER `issued_at`;

ALTER TABLE `Receipt_Tickets`
  ADD COLUMN `format` VARCHAR(20) NOT NULL DEFAULT 'thermal' AFTER `status`;

ALTER TABLE `Receipt_Tickets`
  ADD COLUMN `locale` VARCHAR(20) NOT NULL DEFAULT 'en_US' AFTER `format`;

ALTER TABLE `Receipt_Tickets`
  ADD COLUMN `timezone` VARCHAR(64) NOT NULL DEFAULT 'UTC' AFTER `locale`;

ALTER TABLE `Receipt_Tickets`
  MODIFY COLUMN `currency` CHAR(3) NOT NULL DEFAULT 'USD';

ALTER TABLE `Receipt_Tickets`
  ADD COLUMN `payload_hash` CHAR(64) DEFAULT NULL AFTER `payload_json`;

ALTER TABLE `Receipt_Tickets`
  ADD COLUMN `payload_signature` CHAR(64) DEFAULT NULL AFTER `payload_hash`;

ALTER TABLE `Receipt_Tickets`
  ADD COLUMN `branch_id` INT DEFAULT NULL AFTER `patron_user_id`;

ALTER TABLE `Receipt_Tickets`
  ADD COLUMN `channel` VARCHAR(30) NOT NULL DEFAULT 'web' AFTER `branch_id`;

CREATE TABLE IF NOT EXISTS `Receipt_Ticket_Logs` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `receipt_id` BIGINT UNSIGNED NOT NULL,
  `actor_user_id` INT NOT NULL,
  `actor_role` VARCHAR(20) NOT NULL,
  `event_type` VARCHAR(30) NOT NULL,
  `meta_json` LONGTEXT DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_receipt_ticket_logs_receipt` (`receipt_id`, `created_at`),
  KEY `idx_receipt_ticket_logs_actor` (`actor_user_id`, `created_at`),
  CONSTRAINT `fk_receipt_ticket_logs_receipt`
    FOREIGN KEY (`receipt_id`) REFERENCES `Receipt_Tickets` (`id`)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `Receipt_Ticket_Logs`
  ADD COLUMN `reason` VARCHAR(255) DEFAULT NULL AFTER `event_type`;

ALTER TABLE `Receipt_Ticket_Logs`
  ADD COLUMN `job_target` VARCHAR(50) DEFAULT NULL AFTER `reason`;

ALTER TABLE `Receipt_Ticket_Logs`
  ADD COLUMN `target_status` VARCHAR(20) DEFAULT NULL AFTER `job_target`;

ALTER TABLE `Receipt_Ticket_Logs`
  ADD COLUMN `error_message` VARCHAR(255) DEFAULT NULL AFTER `target_status`;

ALTER TABLE `Receipt_Ticket_Logs`
  ADD COLUMN `channel` VARCHAR(30) NOT NULL DEFAULT 'web' AFTER `error_message`;

CREATE TABLE IF NOT EXISTS `Receipt_Print_Jobs` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `receipt_id` BIGINT UNSIGNED NOT NULL,
  `actor_user_id` INT NOT NULL,
  `actor_role` VARCHAR(20) NOT NULL,
  `target` VARCHAR(50) NOT NULL DEFAULT 'browser_print',
  `status` VARCHAR(20) NOT NULL DEFAULT 'queued',
  `error_message` VARCHAR(255) DEFAULT NULL,
  `channel` VARCHAR(30) NOT NULL DEFAULT 'web',
  `meta_json` LONGTEXT DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_receipt_print_jobs_receipt` (`receipt_id`, `created_at`),
  KEY `idx_receipt_print_jobs_actor` (`actor_user_id`, `created_at`),
  CONSTRAINT `fk_receipt_print_jobs_receipt`
    FOREIGN KEY (`receipt_id`) REFERENCES `Receipt_Tickets` (`id`)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
