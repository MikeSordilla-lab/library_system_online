-- Migration: Add account lockout columns to Users table
-- After 5 consecutive failed login attempts, account locks for 15 minutes

ALTER TABLE Users
ADD COLUMN failed_login_attempts INT NOT NULL DEFAULT 0 AFTER is_suspended,
ADD COLUMN locked_until TIMESTAMP NULL DEFAULT NULL AFTER failed_login_attempts;

-- Add index for faster lookup during login
CREATE INDEX idx_users_locked_until ON Users (locked_until);