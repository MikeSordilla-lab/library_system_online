-- Create reservations table if it does not exist (shared-host safe)
-- Run this before migration-reservations-approval-phase1.sql when table is missing.

SET @schema_name := DATABASE();

SET @reservations_exists := (
  SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.TABLES
   WHERE TABLE_SCHEMA = @schema_name
     AND LOWER(TABLE_NAME) = 'reservations'
);

SET @create_reservations := IF(
  @reservations_exists > 0,
  'SELECT "skip create reservations: already exists"',
  'CREATE TABLE `reservations` (
    `id` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` INT(10) UNSIGNED NOT NULL,
    `book_id` INT(10) UNSIGNED NOT NULL,
    `reserved_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `expires_at` DATETIME NOT NULL,
    `status` ENUM("pending","fulfilled","cancelled") NOT NULL DEFAULT "pending",
    PRIMARY KEY (`id`),
    KEY `idx_user_id` (`user_id`),
    KEY `idx_book_id` (`book_id`),
    KEY `idx_status` (`status`),
    KEY `idx_expires_at` (`expires_at`),
    KEY `idx_book_status` (`book_id`, `status`),
    KEY `idx_book_reserved` (`book_id`, `reserved_at`, `status`)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
);

PREPARE stmt FROM @create_reservations;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
