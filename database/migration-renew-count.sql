-- Migration: Add renewal_count to Circulation table
-- Tracks renewals per loan; any limit of 1 renewal is enforced in application logic

ALTER TABLE `Circulation`
ADD COLUMN `renewal_count` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `status`;
