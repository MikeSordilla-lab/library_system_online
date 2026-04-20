-- Reservations approval metadata + queue index support (idempotent where possible)
-- Safe to re-run on MySQL/MariaDB using INFORMATION_SCHEMA checks.

SET @schema_name := DATABASE();

-- Detect actual table name with correct case (Linux hosts can be case-sensitive).
SET @reservations_table := (
  SELECT TABLE_NAME
    FROM INFORMATION_SCHEMA.TABLES
   WHERE TABLE_SCHEMA = @schema_name
     AND LOWER(TABLE_NAME) = 'reservations'
   ORDER BY
     CASE
       WHEN TABLE_NAME = 'Reservations' THEN 0
       WHEN TABLE_NAME = 'reservations' THEN 1
       ELSE 2
     END
   LIMIT 1
);

SET @reservations_table_quoted := IF(
  @reservations_table IS NULL,
  NULL,
  CONCAT('`', REPLACE(@reservations_table, '`', '``'), '`')
);

SET @add_approved_at := IF(
  @reservations_table IS NULL,
  'SELECT "skip reservations migration: table not found"',
  IF(
    EXISTS(
      SELECT 1
        FROM INFORMATION_SCHEMA.COLUMNS
       WHERE TABLE_SCHEMA = @schema_name
         AND TABLE_NAME = @reservations_table
         AND COLUMN_NAME = 'approved_at'
    ),
    'SELECT "skip reservations.approved_at"',
    CONCAT('ALTER TABLE ', @reservations_table_quoted, ' ADD COLUMN `approved_at` DATETIME NULL AFTER `expires_at`')
  )
);
PREPARE stmt FROM @add_approved_at; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @add_approved_by := IF(
  @reservations_table IS NULL,
  'SELECT "skip reservations migration: table not found"',
  IF(
    EXISTS(
      SELECT 1
        FROM INFORMATION_SCHEMA.COLUMNS
       WHERE TABLE_SCHEMA = @schema_name
         AND TABLE_NAME = @reservations_table
         AND COLUMN_NAME = 'approved_by'
    ),
    'SELECT "skip reservations.approved_by"',
    CONCAT('ALTER TABLE ', @reservations_table_quoted, ' ADD COLUMN `approved_by` INT NULL AFTER `approved_at`')
  )
);
PREPARE stmt FROM @add_approved_by; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @add_rejected_at := IF(
  @reservations_table IS NULL,
  'SELECT "skip reservations migration: table not found"',
  IF(
    EXISTS(
      SELECT 1
        FROM INFORMATION_SCHEMA.COLUMNS
       WHERE TABLE_SCHEMA = @schema_name
         AND TABLE_NAME = @reservations_table
         AND COLUMN_NAME = 'rejected_at'
    ),
    'SELECT "skip reservations.rejected_at"',
    CONCAT('ALTER TABLE ', @reservations_table_quoted, ' ADD COLUMN `rejected_at` DATETIME NULL AFTER `approved_by`')
  )
);
PREPARE stmt FROM @add_rejected_at; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @add_rejected_by := IF(
  @reservations_table IS NULL,
  'SELECT "skip reservations migration: table not found"',
  IF(
    EXISTS(
      SELECT 1
        FROM INFORMATION_SCHEMA.COLUMNS
       WHERE TABLE_SCHEMA = @schema_name
         AND TABLE_NAME = @reservations_table
         AND COLUMN_NAME = 'rejected_by'
    ),
    'SELECT "skip reservations.rejected_by"',
    CONCAT('ALTER TABLE ', @reservations_table_quoted, ' ADD COLUMN `rejected_by` INT NULL AFTER `rejected_at`')
  )
);
PREPARE stmt FROM @add_rejected_by; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @add_rejection_reason := IF(
  @reservations_table IS NULL,
  'SELECT "skip reservations migration: table not found"',
  IF(
    EXISTS(
      SELECT 1
        FROM INFORMATION_SCHEMA.COLUMNS
       WHERE TABLE_SCHEMA = @schema_name
         AND TABLE_NAME = @reservations_table
         AND COLUMN_NAME = 'rejection_reason'
    ),
    'SELECT "skip reservations.rejection_reason"',
    CONCAT('ALTER TABLE ', @reservations_table_quoted, ' ADD COLUMN `rejection_reason` VARCHAR(255) NULL AFTER `rejected_by`')
  )
);
PREPARE stmt FROM @add_rejection_reason; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @add_idx_book_status_reserved := IF(
  @reservations_table IS NULL,
  'SELECT "skip reservations migration: table not found"',
  IF(
    EXISTS(
      SELECT 1
        FROM INFORMATION_SCHEMA.STATISTICS
       WHERE TABLE_SCHEMA = @schema_name
         AND TABLE_NAME = @reservations_table
         AND INDEX_NAME = 'idx_reservations_book_status_reserved_at'
    ),
    'SELECT "skip idx_reservations_book_status_reserved_at"',
    CONCAT('ALTER TABLE ', @reservations_table_quoted, ' ADD INDEX `idx_reservations_book_status_reserved_at` (`book_id`, `status`, `reserved_at`)')
  )
);
PREPARE stmt FROM @add_idx_book_status_reserved; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @add_idx_user_status := IF(
  @reservations_table IS NULL,
  'SELECT "skip reservations migration: table not found"',
  IF(
    EXISTS(
      SELECT 1
        FROM INFORMATION_SCHEMA.STATISTICS
       WHERE TABLE_SCHEMA = @schema_name
         AND TABLE_NAME = @reservations_table
         AND INDEX_NAME = 'idx_reservations_user_status'
    ),
    'SELECT "skip idx_reservations_user_status"',
    CONCAT('ALTER TABLE ', @reservations_table_quoted, ' ADD INDEX `idx_reservations_user_status` (`user_id`, `status`)')
  )
);
PREPARE stmt FROM @add_idx_user_status; EXECUTE stmt; DEALLOCATE PREPARE stmt;
