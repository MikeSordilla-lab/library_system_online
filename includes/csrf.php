<?php

/**
 * includes/csrf.php — CSRF token helpers (FR-011)
 *
 * Provides two functions:
 *
 *   csrf_token() — Generate-or-get the per-session token.
 *                  Call from GET handlers to embed in forms.
 *
 *   csrf_verify() — Validate the submitted token against the session copy
 *                   using hash_equals() (timing-safe). Regenerates the token
 *                   on success. Sends HTTP 403 + exits on failure.
 *                   Call at the top of every POST handler.
 *
 * Usage:
 *   require_once __DIR__ . '/csrf.php';
 *
 *   // In a form:
 *   <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
 *
 *   // In a POST handler:
 *   csrf_verify();
 */

if (session_status() === PHP_SESSION_NONE) {
  session_start();
}

/**
 * Return the current CSRF token, generating one if it does not yet exist.
 */
function csrf_token(): string
{
  if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
  }
  return $_SESSION['csrf_token'];
}

/**
 * Verify the submitted CSRF token. Regenerates the token on success.
 * Terminates with HTTP 403 if the token is missing or invalid.
 */
function csrf_verify(): void
{
  $submitted = $_POST['csrf_token'] ?? '';
  $stored    = $_SESSION['csrf_token'] ?? '';

  if ($stored === '' || !hash_equals($stored, $submitted)) {
    http_response_code(403);
    exit('403 Forbidden – Invalid or missing CSRF token.');
  }

  // Rotate token after each successful POST (prevents replay within session)
  $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
