-- Adds persistent profile image storage for borrower/librarian/admin accounts.
-- Safe to run more than once on MySQL/MariaDB versions that support IF NOT EXISTS.

ALTER TABLE `Users`
  ADD COLUMN IF NOT EXISTS `avatar_url` VARCHAR(500) DEFAULT NULL;
