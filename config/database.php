<?php

/**
 * config/database.php
 *
 * Centralized database configuration.
 * Values are sourced from environment variables when available.
 */

if (!function_exists('cfg_env')) {
    function cfg_env(string $key, string $default = ''): string
    {
        $value = getenv($key);
        return $value !== false ? trim((string) $value) : $default;
    }
}

if (!defined('DB_HOST')) {
    define('DB_HOST', cfg_env('DB_HOST', ''));
}

if (!defined('DB_PORT')) {
    $port = cfg_env('DB_PORT', '3306');
    define('DB_PORT', ctype_digit($port) ? (int) $port : 3306);
}

if (!defined('DB_NAME')) {
    define('DB_NAME', cfg_env('DB_NAME', ''));
}

if (!defined('DB_USER')) {
    define('DB_USER', cfg_env('DB_USER', ''));
}

if (!defined('DB_PASS')) {
    define('DB_PASS', cfg_env('DB_PASS', ''));
}
