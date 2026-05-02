-- Migration: Add renewal_count to Circulation table
-- This enforces the limit of 1 renewal per loan

ALTER TABLE `Circulation`
ADD COLUMN `renewal_count` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `status`;
