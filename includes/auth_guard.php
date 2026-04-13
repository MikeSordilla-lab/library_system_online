<?php

/**
 * includes/auth_guard.php — RBAC Middleware (FR-029–FR-035)
 *
 * Usage (set $allowed_roles BEFORE the require statement):
 *
 *   $allowed_roles = ['admin'];                          // restrict to admins only
 *   $allowed_roles = ['admin', 'librarian'];             // multiple roles
 *   $allowed_roles = [];                                 // any authenticated user
 *   require_once __DIR__ . '/../includes/auth_guard.php';
 *
 * Behaviour:
 *   1. Starts the session if not already active.
 *   2. Redirects unauthenticated visitors to login.php (FR-030).
 *   3. Re-validates the session against the DB on every request:
 *      SELECT id, role FROM Users WHERE id = ? AND is_verified = 1 AND is_suspended = 0
 *      If no row is returned the session is destroyed and the user is
 *      redirected to login.php — prevents stale sessions after an Admin
 *      suspends or removes an account (FR-035).
 *   4. If $allowed_roles is non-empty and the user's role is not in the list,
 *      sends HTTP 403 and renders 403.php (FR-031).
 *   5. If $allowed_roles is empty, any authenticated user is allowed (FR-033).
 *
 * This file must be included BEFORE any HTML output or business logic (FR-034).
 * It does NOT rely on JavaScript or client-side checks.
 */

// Load shared PDO helper (also loads config.php → BASE_URL available)
require_once __DIR__ . '/db.php';

// 1. Start session if not already active
if (session_status() === PHP_SESSION_NONE) {
  session_start();
}

// 2. Redirect unauthenticated visitors (FR-030)
if (empty($_SESSION['user_id'])) {
  header('Location: ' . BASE_URL . 'login.php');
  exit;
}

// 3. DB re-validation — prevents stale sessions (FR-035)
try {
  $pdo  = get_db();
  $stmt = $pdo->prepare(
    'SELECT id, role FROM Users WHERE id = ? AND is_verified = 1 AND is_suspended = 0'
  );
  $stmt->execute([(int) $_SESSION['user_id']]);
  $db_user = $stmt->fetch();
} catch (PDOException $e) {
  // DB error: fail safe — deny access
  error_log('[auth_guard] DB re-validation error: ' . $e->getMessage());
  session_unset();
  session_destroy();
  header('Location: ' . BASE_URL . 'login.php');
  exit;
}

if ($db_user === false) {
  // Account deleted or suspended since login — destroy stale session
  session_unset();
  session_destroy();
  header('Location: ' . BASE_URL . 'login.php');
  exit;
}

// Sync role from DB in case it was changed since login
$_SESSION['role'] = $db_user['role'];

// 4. Role authorisation check (FR-031)
// $allowed_roles must be set by the including page before require-ing this file
// Default to empty array (any authenticated user) if not provided (FR-033)
if (!isset($allowed_roles) || !is_array($allowed_roles)) {
  $allowed_roles = [];
}

if (!empty($allowed_roles) && !in_array($_SESSION['role'], $allowed_roles, true)) {
  http_response_code(403);
  require_once __DIR__ . '/../403.php';
  exit;
}

// Guard passed — page execution continues normally.
// $_SESSION['user_id'], $_SESSION['role'], $_SESSION['full_name'] are available.
