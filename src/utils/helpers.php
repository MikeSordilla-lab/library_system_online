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
