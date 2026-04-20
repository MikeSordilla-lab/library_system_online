-- InfinityFree import schema for Library System Online
-- Schema-only plus minimal bootstrap seeds (no production/user data dump)

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- Drop in dependency-safe order
DROP TABLE IF EXISTS `Receipt_Print_Jobs`;
DROP TABLE IF EXISTS `Receipt_Ticket_Logs`;
DROP TABLE IF EXISTS `Receipt_Tickets`;
DROP TABLE IF EXISTS `System_Logs`;
DROP TABLE IF EXISTS `Reservations`;
DROP TABLE IF EXISTS `Circulation`;
DROP TABLE IF EXISTS `book_covers`;
DROP TABLE IF EXISTS `Books`;
DROP TABLE IF EXISTS `Settings`;
DROP TABLE IF EXISTS `Users`;

SET FOREIGN_KEY_CHECKS = 1;

CREATE TABLE `Users` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `full_name` VARCHAR(150) NOT NULL,
  `email` VARCHAR(255) NOT NULL,
  `password_hash` VARCHAR(255) NOT NULL,
  `role` ENUM('admin','librarian','borrower') NOT NULL DEFAULT 'borrower',
  `is_superadmin` TINYINT(1) NOT NULL DEFAULT 0,
  `is_verified` TINYINT(1) NOT NULL DEFAULT 0,
  `is_suspended` TINYINT(1) NOT NULL DEFAULT 0,
  `verification_token` VARCHAR(50) DEFAULT NULL,
  `verified_at` DATETIME DEFAULT NULL,
  `birthdate` DATE DEFAULT NULL,
  `address` VARCHAR(255) DEFAULT NULL,
  `phone` VARCHAR(30) DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `password_reset_token` VARCHAR(64) DEFAULT NULL,
  `password_reset_expires` DATETIME DEFAULT NULL,
  `avatar_url` VARCHAR(500) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_users_email` (`email`),
  KEY `idx_users_role` (`role`),
  KEY `idx_users_is_suspended` (`is_suspended`),
  KEY `idx_users_verified_at` (`verified_at`),
  KEY `idx_users_password_reset_token` (`password_reset_token`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `Books` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `title` VARCHAR(255) NOT NULL,
  `author` VARCHAR(255) NOT NULL,
  `description` TEXT DEFAULT NULL,
  `isbn` VARCHAR(20) NOT NULL,
  `category` VARCHAR(100) NOT NULL,
  `total_copies` INT UNSIGNED NOT NULL DEFAULT 0,
  `available_copies` INT UNSIGNED NOT NULL DEFAULT 0,
  `cover_image` VARCHAR(300) DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_books_isbn` (`isbn`),
  KEY `idx_books_category` (`category`),
  FULLTEXT KEY `ft_books_title_author` (`title`, `author`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `book_covers` (
  `book_id` INT UNSIGNED NOT NULL,
  `image_data` LONGBLOB NOT NULL,
  `mime_type` VARCHAR(20) NOT NULL,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`book_id`),
  CONSTRAINT `fk_book_covers_book`
    FOREIGN KEY (`book_id`) REFERENCES `Books` (`id`)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `Circulation` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED NOT NULL,
  `book_id` INT UNSIGNED NOT NULL,
  `checkout_date` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `due_date` DATETIME NOT NULL,
  `return_date` DATETIME DEFAULT NULL,
  `status` ENUM('active','returned','overdue') NOT NULL DEFAULT 'active',
  `fine_amount` DECIMAL(8,2) NOT NULL DEFAULT 0.00,
  `fine_paid` TINYINT(1) NOT NULL DEFAULT 0,
  `fine_paid_at` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_circulation_user` (`user_id`),
  KEY `idx_circulation_book` (`book_id`),
  KEY `idx_circulation_status` (`status`),
  KEY `idx_circulation_due_date` (`due_date`),
  KEY `idx_circulation_user_status` (`user_id`, `status`),
  CONSTRAINT `fk_circulation_user`
    FOREIGN KEY (`user_id`) REFERENCES `Users` (`id`)
    ON DELETE CASCADE,
  CONSTRAINT `fk_circulation_book`
    FOREIGN KEY (`book_id`) REFERENCES `Books` (`id`)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `Reservations` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED NOT NULL,
  `book_id` INT UNSIGNED NOT NULL,
  `reserved_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `expires_at` DATETIME NOT NULL,
  `status` ENUM('pending','approved','rejected','expired','fulfilled','cancelled') NOT NULL DEFAULT 'pending',
  `approved_at` DATETIME DEFAULT NULL,
  `approved_by` INT UNSIGNED DEFAULT NULL,
  `rejected_at` DATETIME DEFAULT NULL,
  `rejected_by` INT UNSIGNED DEFAULT NULL,
  `rejection_reason` VARCHAR(255) DEFAULT NULL,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_reservations_user` (`user_id`),
  KEY `idx_reservations_book` (`book_id`),
  KEY `idx_reservations_status` (`status`),
  KEY `idx_reservations_expires_at` (`expires_at`),
  KEY `idx_reservations_book_status_reserved_at` (`book_id`, `status`, `reserved_at`),
  KEY `idx_reservations_user_status` (`user_id`, `status`),
  KEY `idx_reservations_approved_by` (`approved_by`),
  KEY `idx_reservations_rejected_by` (`rejected_by`),
  CONSTRAINT `fk_reservations_user`
    FOREIGN KEY (`user_id`) REFERENCES `Users` (`id`)
    ON DELETE CASCADE,
  CONSTRAINT `fk_reservations_book`
    FOREIGN KEY (`book_id`) REFERENCES `Books` (`id`)
    ON DELETE CASCADE,
  CONSTRAINT `fk_reservations_approved_by`
    FOREIGN KEY (`approved_by`) REFERENCES `Users` (`id`)
    ON DELETE SET NULL,
  CONSTRAINT `fk_reservations_rejected_by`
    FOREIGN KEY (`rejected_by`) REFERENCES `Users` (`id`)
    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `Settings` (
  `key` VARCHAR(100) NOT NULL,
  `value` VARCHAR(255) NOT NULL,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `System_Logs` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `actor_id` INT UNSIGNED DEFAULT NULL,
  `actor_role` VARCHAR(50) DEFAULT NULL,
  `action_type` VARCHAR(100) NOT NULL,
  `target_entity` VARCHAR(100) DEFAULT NULL,
  `target_id` INT UNSIGNED DEFAULT NULL,
  `email_address` VARCHAR(255) DEFAULT NULL,
  `outcome` VARCHAR(50) NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_system_logs_actor_id` (`actor_id`),
  KEY `idx_system_logs_action_type` (`action_type`),
  KEY `idx_system_logs_target_entity` (`target_entity`),
  KEY `idx_system_logs_email_address` (`email_address`),
  KEY `idx_system_logs_created_at` (`created_at`),
  CONSTRAINT `fk_system_logs_actor`
    FOREIGN KEY (`actor_id`) REFERENCES `Users` (`id`)
    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `Receipt_Tickets` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `receipt_no` VARCHAR(40) NOT NULL,
  `idempotency_key` VARCHAR(120) DEFAULT NULL,
  `type` VARCHAR(50) NOT NULL,
  `library_label` VARCHAR(255) NOT NULL,
  `actor_user_id` INT UNSIGNED NOT NULL,
  `patron_user_id` INT UNSIGNED NOT NULL,
  `branch_id` INT UNSIGNED DEFAULT NULL,
  `channel` VARCHAR(30) NOT NULL DEFAULT 'web',
  `reference_table` VARCHAR(60) NOT NULL,
  `reference_id` BIGINT UNSIGNED NOT NULL,
  `amount` DECIMAL(10,2) DEFAULT NULL,
  `currency` CHAR(3) NOT NULL DEFAULT 'USD',
  `payload_json` LONGTEXT NOT NULL,
  `payload_hash` CHAR(64) DEFAULT NULL,
  `payload_signature` CHAR(64) DEFAULT NULL,
  `issued_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `status` VARCHAR(20) NOT NULL DEFAULT 'issued',
  `format` VARCHAR(20) NOT NULL DEFAULT 'thermal',
  `locale` VARCHAR(20) NOT NULL DEFAULT 'en_US',
  `timezone` VARCHAR(64) NOT NULL DEFAULT 'UTC',
  PRIMARY KEY (`id`),
  UNIQUE KEY `ux_receipt_tickets_receipt_no` (`receipt_no`),
  UNIQUE KEY `ux_receipt_tickets_idempotency` (`idempotency_key`),
  KEY `idx_receipt_tickets_patron_issued` (`patron_user_id`, `issued_at`),
  KEY `idx_receipt_tickets_actor_issued` (`actor_user_id`, `issued_at`),
  CONSTRAINT `fk_receipt_tickets_actor_user`
    FOREIGN KEY (`actor_user_id`) REFERENCES `Users` (`id`)
    ON DELETE RESTRICT,
  CONSTRAINT `fk_receipt_tickets_patron_user`
    FOREIGN KEY (`patron_user_id`) REFERENCES `Users` (`id`)
    ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `Receipt_Ticket_Logs` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `receipt_id` BIGINT UNSIGNED NOT NULL,
  `actor_user_id` INT UNSIGNED NOT NULL,
  `actor_role` VARCHAR(20) NOT NULL,
  `event_type` VARCHAR(30) NOT NULL,
  `reason` VARCHAR(255) DEFAULT NULL,
  `job_target` VARCHAR(50) DEFAULT NULL,
  `target_status` VARCHAR(20) DEFAULT NULL,
  `error_message` VARCHAR(255) DEFAULT NULL,
  `channel` VARCHAR(30) NOT NULL DEFAULT 'web',
  `meta_json` LONGTEXT DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_receipt_ticket_logs_receipt` (`receipt_id`, `created_at`),
  KEY `idx_receipt_ticket_logs_actor` (`actor_user_id`, `created_at`),
  CONSTRAINT `fk_receipt_ticket_logs_receipt`
    FOREIGN KEY (`receipt_id`) REFERENCES `Receipt_Tickets` (`id`)
    ON DELETE CASCADE,
  CONSTRAINT `fk_receipt_ticket_logs_actor_user`
    FOREIGN KEY (`actor_user_id`) REFERENCES `Users` (`id`)
    ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `Receipt_Print_Jobs` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `receipt_id` BIGINT UNSIGNED NOT NULL,
  `actor_user_id` INT UNSIGNED NOT NULL,
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
    ON DELETE CASCADE,
  CONSTRAINT `fk_receipt_print_jobs_actor_user`
    FOREIGN KEY (`actor_user_id`) REFERENCES `Users` (`id`)
    ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Minimal default settings used by app logic/pages
INSERT INTO `Settings` (`key`, `value`) VALUES
  ('fine_per_day', '5.00'),
  ('fine.daily_rate', '1.00'),
  ('loan_period_days', '14'),
  ('max_borrow_limit', '5'),
  ('max_loan_days', '7'),
  ('reservation_expiry_days', '7'),
  ('library_name', 'Library System'),
  ('receipt_phase1_enabled', '1')
ON DUPLICATE KEY UPDATE
  `value` = VALUES(`value`),
  `updated_at` = CURRENT_TIMESTAMP;

-- Minimal admin bootstrap account from ADMIN-CREDENTIALS.md
-- Email: admin@library.local
-- Password: admin123
INSERT INTO `Users` (`full_name`, `email`, `password_hash`, `role`, `is_superadmin`, `is_verified`, `is_suspended`)
VALUES ('Super Admin', 'admin@library.local', '$2y$12$FZA0dhGBg14EERIo34BuqOK3vmKG10RB4gCu2EMTWx.Oed2VfE/Yu', 'admin', 1, 1, 0)
ON DUPLICATE KEY UPDATE
  `full_name` = VALUES(`full_name`),
  `password_hash` = VALUES(`password_hash`),
  `role` = VALUES(`role`),
  `is_superadmin` = VALUES(`is_superadmin`),
  `is_verified` = VALUES(`is_verified`),
  `is_suspended` = VALUES(`is_suspended`),
  `updated_at` = CURRENT_TIMESTAMP;
