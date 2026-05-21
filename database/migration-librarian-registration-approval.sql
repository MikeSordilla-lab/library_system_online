-- Adds admin approval state for public librarian registration requests.

ALTER TABLE `Users`
  ADD COLUMN `staff_approval_status` VARCHAR(20) NOT NULL DEFAULT 'none' AFTER `is_suspended`,
  ADD COLUMN `staff_approved_by` INT UNSIGNED NULL AFTER `staff_approval_status`,
  ADD COLUMN `staff_approved_at` DATETIME NULL AFTER `staff_approved_by`,
  ADD COLUMN `staff_rejected_by` INT UNSIGNED NULL AFTER `staff_approved_at`,
  ADD COLUMN `staff_rejected_at` DATETIME NULL AFTER `staff_rejected_by`,
  ADD COLUMN `staff_rejection_reason` VARCHAR(255) NULL AFTER `staff_rejected_at`;

CREATE INDEX `idx_users_staff_approval` ON `Users` (`staff_approval_status`, `role`);
