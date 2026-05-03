<?php
/**
 * src/utils/helpers.php — Application Helper Functions
 * 
 * Collection of utility functions used throughout the application.
 */

/**
 * Safe redirect function
 */
function redirect($url, $status_code = 302) {
    header("Location: $url", true, $status_code);
    exit;
}

/**
 * HTML escape function
 */
function html($str) {
    return htmlspecialchars($str, ENT_QUOTES, 'UTF-8');
}

/**
 * Get URL with base path
 */
function url($path = '') {
    return BASE_URL . ltrim($path, '/');
}

/**
 * Check if user is authenticated
 */
function is_authenticated() {
    return isset($_SESSION['user']);
}

/**
 * Get current user role
 */
function get_user_role() {
    return $_SESSION['role'] ?? 'guest';
}

/**
 * Get current user ID
 */
function get_user_id() {
    return $_SESSION['user']['id'] ?? null;
}

/**
 * Set flash message in session
 */
function flash($key, $value = null) {
    if ($value === null) {
        $msg = $_SESSION['flash'][$key] ?? null;
        unset($_SESSION['flash'][$key]);
        return $msg;
    } else {
        $_SESSION['flash'][$key] = $value;
    }
}

/**
 * Log message to file
 */
function log_message($message, $level = 'info') {
    $timestamp = date('Y-m-d H:i:s');
    $log_file = ROOT_PATH . '/logs/' . date('Y-m-d') . '.log';
    
    // Ensure logs directory exists
    @mkdir(dirname($log_file), 0755, true);
    
    $log_entry = "[$timestamp] [$level] $message" . PHP_EOL;
    @error_log($log_entry, 3, $log_file);
}

/**
 * Sanitize input
 */
function sanitize_input($data) {
    if (is_array($data)) {
        return array_map('sanitize_input', $data);
    }
    return htmlspecialchars(trim($data), ENT_QUOTES, 'UTF-8');
}

/**
 * Validate email
 */
function is_valid_email($email) {
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

/**
 * Format currency
 */
function format_currency($amount) {
    return '$' . number_format($amount, 2);
}

/**
 * Format date
 */
function format_date($date, $format = 'M d, Y') {
    $timestamp = is_numeric($date) ? $date : strtotime($date);
    return $timestamp ? date($format, $timestamp) : $date;
}

/**
 * Escape LIKE query wildcards (%, _, \) in user input to prevent predicate injection.
 *
 * @param string $q The raw search string
 * @return string The escaped string safe for use in LIKE patterns
 */
function escape_like_wildcards(string $q): string {
    return addcslashes($q, '%_\\');
}

/**
 * Validate password complexity requirements.
 * Requires: 1 uppercase, 1 lowercase, 1 digit, 1 special char, min 8 chars.
 *
 * @param string $password The password to validate
 * @return array{valid: bool, errors: string[]} List of validation errors if invalid
 */
function validate_password_complexity(string $password): array {
    $errors = [];

    if (strlen($password) < 8) {
        $errors[] = 'Password must be at least 8 characters.';
    }
    if (!preg_match('/[A-Z]/', $password)) {
        $errors[] = 'Password must contain at least 1 uppercase letter.';
    }
    if (!preg_match('/[a-z]/', $password)) {
        $errors[] = 'Password must contain at least 1 lowercase letter.';
    }
    if (!preg_match('/\d/', $password)) {
        $errors[] = 'Password must contain at least 1 digit.';
    }
    if (!preg_match('/[!@#$%^&*()_+\-=\[\]{};:\'",.<>\/?\\\\`|~]/', $password)) {
        $errors[] = 'Password must contain at least 1 special character.';
    }

    return [
        'valid' => empty($errors),
        'errors' => $errors,
    ];
}
