<?php

/**
 * includes/constants.php — Application-wide Constants
 *
 * Centralizes magic numbers and configuration constants used throughout the application.
 * Prevents code duplication and makes maintenance easier.
 */

// Time-related constants
define('SECONDS_PER_DAY', 86400);
define('SECONDS_PER_HOUR', 3600);
define('SECONDS_PER_MINUTE', 60);

// OTP / Verification Code
define('OTP_MIN', 100000);
define('OTP_MAX', 999999);
define('OTP_LENGTH', 6);

// CSRF Token
define('CSRF_TOKEN_BYTES', 32);

// Loan defaults (seconds from config, but also useful as fallbacks)
define('DEFAULT_LOAN_DAYS', 14);
define('DEFAULT_RESERVATION_EXPIRY_DAYS', 7);

// File upload constraints
define('MAX_FILE_SIZE_MB', 5);
define('MAX_FILE_SIZE_BYTES', 5 * 1024 * 1024);

// Avatar constraints (already in avatar.php, but referenced here for completeness)
define('AVATAR_MIN_DIMENSION', 100);
define('AVATAR_MAX_DIMENSION', 2000);

// Search constraints
define('SEARCH_MIN_LENGTH', 2);
define('SEARCH_MAX_LENGTH', 100);

// Pagination
define('DEFAULT_PAGE_SIZE', 20);
define('MAX_PAGE_SIZE', 100);

// Email OTP expiry (in minutes)
define('OTP_EXPIRY_MINUTES', 15);

// Session timeout (in seconds)
define('SESSION_TIMEOUT_SECONDS', 3600);  // 1 hour

// Database constraints
define('MAX_VARCHAR_LENGTH', 255);
define('MAX_TEXT_LENGTH', 65535);

// Role constants
define('ROLE_ADMIN', 'admin');
define('ROLE_LIBRARIAN', 'librarian');
define('ROLE_BORROWER', 'borrower');

// Loan status constants
define('CIRCULATION_STATUS_ACTIVE', 'active');
define('CIRCULATION_STATUS_OVERDUE', 'overdue');
define('CIRCULATION_STATUS_RETURNED', 'returned');

// Reservation status constants
define('RESERVATION_STATUS_PENDING', 'pending');
define('RESERVATION_STATUS_FULFILLED', 'fulfilled');
define('RESERVATION_STATUS_CANCELLED', 'cancelled');

// Log outcome constants
define('LOG_OUTCOME_SUCCESS', 'success');
define('LOG_OUTCOME_FAILURE', 'failure');
define('LOG_OUTCOME_INFO', 'info');
