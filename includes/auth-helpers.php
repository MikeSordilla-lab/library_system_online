<?php

/**
 * includes/auth-helpers.php — Shared authentication helper utilities.
 */

if (!function_exists('resolve_role')) {
  /**
   * Restrict role redirects to known role directories.
   */
  function resolve_role(string $role): string
  {
    $allowedRoles = ['admin', 'librarian', 'borrower'];
    return in_array($role, $allowedRoles, true) ? $role : 'borrower';
  }
}

if (!function_exists('role_landing_path')) {
  /**
   * Resolve the default landing path for a role.
   * Admins should land on About/Profile first per instructor requirement.
   */
  function role_landing_path(string $role): string
  {
    $resolvedRole = resolve_role($role);
    if ($resolvedRole === 'admin') {
      return 'admin/index.php#about-me';
    }
    return $resolvedRole . '/index.php';
  }
}

if (!function_exists('redirect_authenticated_user')) {
  /**
   * Redirect authenticated users to their role dashboard.
   */
  function redirect_authenticated_user(): void
  {
    if (empty($_SESSION['user_id'])) {
      return;
    }

    $role = (string) ($_SESSION['role'] ?? 'borrower');
    header('Location: ' . BASE_URL . role_landing_path($role));
    exit;
  }
}
