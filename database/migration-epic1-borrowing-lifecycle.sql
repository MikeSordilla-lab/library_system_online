-- ============================================================
-- EPIC 1: Borrowing Lifecycle — DB Migration
-- ============================================================
-- Extends Reservations.status enum to include: approved, rejected, expired
-- Adds updated_at tracking column
-- Safe to re-run (uses INFORMATION_SCHEMA guards).
-- ============================================================

SET @schema_name := DATABASE();

-- ── 1. Extend Reservations.status enum ──────────────────────────────────────
-- Original: enum('pending','fulfilled','cancelled')
-- Extended: + 'approved','rejected','expired'
-- MySQL/MariaDB requires a full enum replacement to add values.

SET @res_table := (
  SELECT TABLE_NAME
    FROM INFORMATION_SCHEMA.TABLES
   WHERE TABLE_SCHEMA = @schema_name
     AND LOWER(TABLE_NAME) = 'reservations'
   LIMIT 1
);

SET @has_approved := IF(
  @res_table IS NULL,
  0,
  (
    SELECT COLUMN_TYPE LIKE '%approved%'
      FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = @schema_name
       AND TABLE_NAME   = @res_table
       AND COLUMN_NAME  = 'status'
     LIMIT 1
  )
);

SET @extend_enum := IF(
  @has_approved,
  'SELECT "skip: status enum already extended"',
  IF(
    @res_table IS NULL,
    'SELECT "skip: Reservations table not found"',
    CONCAT(
      'ALTER TABLE `', @res_table, '`
       MODIFY COLUMN `status`
         ENUM(''pending'',''approved'',''rejected'',''fulfilled'',''cancelled'',''expired'')
         NOT NULL DEFAULT ''pending'''
    )
  )
);
PREPARE stmt FROM @extend_enum; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ── 2. Add updated_at to Reservations ───────────────────────────────────────

SET @add_updated_at := IF(
  @res_table IS NULL,
  'SELECT "skip: table not found"',
  IF(
    EXISTS(
      SELECT 1
        FROM INFORMATION_SCHEMA.COLUMNS
       WHERE TABLE_SCHEMA = @schema_name
         AND TABLE_NAME   = @res_table
         AND COLUMN_NAME  = 'updated_at'
    ),
    'SELECT "skip: updated_at already exists"',
    CONCAT(
      'ALTER TABLE `', @res_table, '`
       ADD COLUMN `updated_at` DATETIME NULL DEFAULT NULL AFTER `expires_at`'
    )
  )
);
PREPARE stmt FROM @add_updated_at; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ── 3. Ensure approved_at / approved_by / rejected_at / rejected_by / rejection_reason ──
-- (These were added by migration-reservations-approval-phase1.sql;
--  repeat guards here so this migration is self-contained.)

SET @add_approved_at := IF(
  @res_table IS NULL,
  'SELECT "skip"',
  IF(
    EXISTS(SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA=@schema_name AND TABLE_NAME=@res_table AND COLUMN_NAME='approved_at'),
    'SELECT "skip: approved_at exists"',
    CONCAT('ALTER TABLE `', @res_table, '` ADD COLUMN `approved_at` DATETIME NULL AFTER `updated_at`')
  )
);
PREPARE stmt FROM @add_approved_at; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @add_approved_by := IF(
  @res_table IS NULL,
  'SELECT "skip"',
  IF(
    EXISTS(SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA=@schema_name AND TABLE_NAME=@res_table AND COLUMN_NAME='approved_by'),
    'SELECT "skip: approved_by exists"',
    CONCAT('ALTER TABLE `', @res_table, '` ADD COLUMN `approved_by` INT NULL AFTER `approved_at`')
  )
);
PREPARE stmt FROM @add_approved_by; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @add_rejected_at := IF(
  @res_table IS NULL,
  'SELECT "skip"',
  IF(
    EXISTS(SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA=@schema_name AND TABLE_NAME=@res_table AND COLUMN_NAME='rejected_at'),
    'SELECT "skip: rejected_at exists"',
    CONCAT('ALTER TABLE `', @res_table, '` ADD COLUMN `rejected_at` DATETIME NULL AFTER `approved_by`')
  )
);
PREPARE stmt FROM @add_rejected_at; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @add_rejected_by := IF(
  @res_table IS NULL,
  'SELECT "skip"',
  IF(
    EXISTS(SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA=@schema_name AND TABLE_NAME=@res_table AND COLUMN_NAME='rejected_by'),
    'SELECT "skip: rejected_by exists"',
    CONCAT('ALTER TABLE `', @res_table, '` ADD COLUMN `rejected_by` INT NULL AFTER `rejected_at`')
  )
);
PREPARE stmt FROM @add_rejected_by; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @add_rejection_reason := IF(
  @res_table IS NULL,
  'SELECT "skip"',
  IF(
    EXISTS(SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA=@schema_name AND TABLE_NAME=@res_table AND COLUMN_NAME='rejection_reason'),
    'SELECT "skip: rejection_reason exists"',
    CONCAT('ALTER TABLE `', @res_table, '` ADD COLUMN `rejection_reason` VARCHAR(255) NULL AFTER `rejected_by`')
  )
);
PREPARE stmt FROM @add_rejection_reason; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ── 4. Indexes for borrower my_books queries ─────────────────────────────────

SET @add_idx_user_status := IF(
  @res_table IS NULL,
  'SELECT "skip"',
  IF(
    EXISTS(SELECT 1 FROM INFORMATION_SCHEMA.STATISTICS
            WHERE TABLE_SCHEMA=@schema_name AND TABLE_NAME=@res_table
              AND INDEX_NAME='idx_res_user_status'),
    'SELECT "skip: index exists"',
    CONCAT('ALTER TABLE `', @res_table, '` ADD INDEX `idx_res_user_status` (`user_id`, `status`)')
  )
);
PREPARE stmt FROM @add_idx_user_status; EXECUTE stmt; DEALLOCATE PREPARE stmt;
