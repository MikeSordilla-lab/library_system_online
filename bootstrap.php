<?php

/**
 * bootstrap.php — Application Bootstrap & Initialization
 * 
 * Central entry point for all application includes and initialization.
 * This file loads all necessary configuration, middleware, and utilities.
 * 
 * Usage: In entry point files (public/index.php, public/login.php, etc.)
 * require_once __DIR__ . '/bootstrap.php';
 */

// ============================================================================
// 1. ROOT PATH DEFINITION
// ============================================================================
define('ROOT_PATH', __DIR__);
define('SRC_PATH', ROOT_PATH . '/src');
define('PUBLIC_PATH', ROOT_PATH . '/public');
define('DATABASE_PATH', ROOT_PATH . '/database');

// ============================================================================
// 2. LOAD CONFIGURATION
// ============================================================================
// Load main configuration (handles .env file parsing)
require_once SRC_PATH . '/config/config.php';

// Load application constants
require_once SRC_PATH . '/config/constants.php';

// Load database connection helper
require_once SRC_PATH . '/config/database.php';

// ============================================================================
// 3. LOAD UTILITIES & HELPERS
// ============================================================================
require_once SRC_PATH . '/utils/helpers.php';
require_once SRC_PATH . '/utils/avatar.php';
require_once SRC_PATH . '/utils/circulation.php';
require_once SRC_PATH . '/utils/settings.php';

// ============================================================================
// 4. LOAD MIDDLEWARE
// ============================================================================
require_once SRC_PATH . '/middleware/auth_guard.php';
require_once SRC_PATH . '/middleware/csrf.php';

// ============================================================================
// 5. SESSION INITIALIZATION
// ============================================================================
if (session_status() === PHP_SESSION_NONE) {
    // Session hasn't started yet — start it now
    session_start();
}

// ============================================================================
// 6. CSRF TOKEN INITIALIZATION
// ============================================================================
// Ensure CSRF token exists for forms
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// ============================================================================
// 7. USER CONTEXT INITIALIZATION
// ============================================================================
// Determine current user and role (from session)
$current_user = $_SESSION['user'] ?? null;
$current_role = $_SESSION['role'] ?? 'guest';
$is_authenticated = isset($_SESSION['user']);

// ============================================================================
// Application is now ready to use!
// ============================================================================
