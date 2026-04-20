-- InfinityFree all-in-one migration (receipts + reservations approval)
-- Intended target: InfinityFree shared hosting (MariaDB/MySQL via phpMyAdmin).
-- Run once via phpMyAdmin SQL tab.
-- Safe-ish re-run where IF NOT EXISTS is supported by your server version.
-- Notes:
--   - This script intentionally avoids PREPARE/EXECUTE dynamic SQL.
--   - Receipt extension columns use ALTER TABLE ... ADD COLUMN IF NOT EXISTS (no AFTER clauses).
--   - Reservation indexes use CREATE INDEX IF NOT EXISTS when supported.
--   - Charset/collation kept as utf8mb4 / utf8mb4_unicode_ci.

/* -------------------------------------------------------------------------- */
/* Receipts phase 1 base tables                                                */
/* -------------------------------------------------------------------------- */

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

/* -------------------------------------------------------------------------- */
/* Receipts compatibility/extension columns                                   */
/* -------------------------------------------------------------------------- */

ALTER TABLE `Receipt_Tickets`
  ADD COLUMN IF NOT EXISTS `status` VARCHAR(20) NOT NULL DEFAULT 'issued';

ALTER TABLE `Receipt_Tickets`
  ADD COLUMN IF NOT EXISTS `format` VARCHAR(20) NOT NULL DEFAULT 'thermal';

ALTER TABLE `Receipt_Tickets`
  ADD COLUMN IF NOT EXISTS `locale` VARCHAR(20) NOT NULL DEFAULT 'en_US';

ALTER TABLE `Receipt_Tickets`
  ADD COLUMN IF NOT EXISTS `timezone` VARCHAR(64) NOT NULL DEFAULT 'UTC';

ALTER TABLE `Receipt_Tickets`
  ADD COLUMN IF NOT EXISTS `payload_hash` CHAR(64) DEFAULT NULL;

ALTER TABLE `Receipt_Tickets`
  ADD COLUMN IF NOT EXISTS `payload_signature` CHAR(64) DEFAULT NULL;

ALTER TABLE `Receipt_Tickets`
  ADD COLUMN IF NOT EXISTS `branch_id` INT DEFAULT NULL;

ALTER TABLE `Receipt_Tickets`
  ADD COLUMN IF NOT EXISTS `channel` VARCHAR(30) NOT NULL DEFAULT 'web';

ALTER TABLE `Receipt_Ticket_Logs`
  ADD COLUMN IF NOT EXISTS `reason` VARCHAR(255) DEFAULT NULL;

ALTER TABLE `Receipt_Ticket_Logs`
  ADD COLUMN IF NOT EXISTS `job_target` VARCHAR(50) DEFAULT NULL;

ALTER TABLE `Receipt_Ticket_Logs`
  ADD COLUMN IF NOT EXISTS `target_status` VARCHAR(20) DEFAULT NULL;

ALTER TABLE `Receipt_Ticket_Logs`
  ADD COLUMN IF NOT EXISTS `error_message` VARCHAR(255) DEFAULT NULL;

ALTER TABLE `Receipt_Ticket_Logs`
  ADD COLUMN IF NOT EXISTS `channel` VARCHAR(30) NOT NULL DEFAULT 'web';

/* -------------------------------------------------------------------------- */
/* Reservations approval columns                                               */
/* -------------------------------------------------------------------------- */

ALTER TABLE `Reservations`
  ADD COLUMN IF NOT EXISTS `approved_at` DATETIME NULL;

ALTER TABLE `Reservations`
  ADD COLUMN IF NOT EXISTS `approved_by` INT NULL;

ALTER TABLE `Reservations`
  ADD COLUMN IF NOT EXISTS `rejected_at` DATETIME NULL;

ALTER TABLE `Reservations`
  ADD COLUMN IF NOT EXISTS `rejected_by` INT NULL;

ALTER TABLE `Reservations`
  ADD COLUMN IF NOT EXISTS `rejection_reason` VARCHAR(255) NULL;

/* -------------------------------------------------------------------------- */
/* Reservations indexes (idempotent where IF NOT EXISTS is supported)         */
/* -------------------------------------------------------------------------- */

CREATE INDEX IF NOT EXISTS `idx_reservations_book_status_reserved_at`
  ON `Reservations` (`book_id`, `status`, `reserved_at`);

CREATE INDEX IF NOT EXISTS `idx_reservations_user_status`
  ON `Reservations` (`user_id`, `status`);

-- If your server rejects CREATE INDEX IF NOT EXISTS syntax, use these once:
-- ALTER TABLE `Reservations`
--   ADD INDEX `idx_reservations_book_status_reserved_at` (`book_id`, `status`, `reserved_at`),
--   ADD INDEX `idx_reservations_user_status` (`user_id`, `status`);
-- Ignore duplicate-key-name error (1061) on re-run in that fallback mode.
