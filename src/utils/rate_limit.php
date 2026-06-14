<?php
/**
 * src/utils/rate_limit.php — In-memory sliding-window rate limiter
 *
 * Provides brute-force protection for authentication and sensitive endpoints.
 * Uses a sliding window algorithm to track attempts per action/identifier.
 */

define('RATE_LIMIT_STORE_KEY', 'rate_limit_store');

function rate_limit_store(): array {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    return $_SESSION[RATE_LIMIT_STORE_KEY] ?? [];
}

function rate_limit_store_set(array $store): void {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $_SESSION[RATE_LIMIT_STORE_KEY] = $store;
}

function rate_limit_store_clear(string $action, ?string $identifier = null): void {
    $store = rate_limit_store();
    $identifier = $identifier ?? ($_SERVER['REMOTE_ADDR'] ?? 'unknown');

    if ($identifier === null) {
        unset($store[$action]);
    } else {
        unset($store[$action][$identifier]);
    }
    rate_limit_store_set($store);
}

function rate_limit_get_record(string $action, ?string $identifier = null): ?array {
    $store = rate_limit_store();
    $identifier = $identifier ?? ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    return $store[$action][$identifier] ?? null;
}

function rate_limit_set_record(string $action, array $record, ?string $identifier = null): void {
    $store = rate_limit_store();
    $identifier = $identifier ?? ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    $store[$action][$identifier] = $record;
    rate_limit_store_set($store);
}

/**
 * Read the current rate-limit state without consuming an attempt.
 *
 * Use this when rendering pages that should respect an active lockout but should
 * not count as a new action, such as GET /login.php.
 *
 * @param string $action Action identifier (e.g., 'login', 'register', 'otp_verify')
 * @param int $max_attempts Maximum attempts allowed in the window
 * @param int $window_seconds Window size in seconds
 * @param string|null $identifier Optional custom identifier (defaults to IP address)
 * @return array{allowed: bool, remaining: int, reset: int, retry_after: int|null}
 */
function rate_limit_status(string $action, int $max_attempts, int $window_seconds, ?string $identifier = null): array {
    $identifier = $identifier ?? ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    $now = microtime(true);

    $record = rate_limit_get_record($action, $identifier);

    if ($record === null) {
        return [
            'allowed' => true,
            'remaining' => $max_attempts,
            'reset' => (int) ceil($now + $window_seconds),
            'retry_after' => null,
        ];
    }

    $window_elapsed = $now - $record['window_start'];

    if ($window_elapsed >= $window_seconds) {
        return [
            'allowed' => true,
            'remaining' => $max_attempts,
            'reset' => (int) ceil($now + $window_seconds),
            'retry_after' => null,
        ];
    }

    if ($record['count'] >= $max_attempts) {
        $retry_after = (int) ceil($window_seconds - $window_elapsed);
        return [
            'allowed' => false,
            'remaining' => 0,
            'reset' => (int) ceil($record['window_start'] + $window_seconds),
            'retry_after' => max(1, $retry_after),
        ];
    }

    return [
        'allowed' => true,
        'remaining' => max(0, $max_attempts - (int) $record['count']),
        'reset' => (int) ceil($record['window_start'] + $window_seconds),
        'retry_after' => null,
    ];
}

/**
 * Check if an action is rate limited using sliding window algorithm.
 *
 * @param string $action Action identifier (e.g., 'login', 'register', 'otp_verify')
 * @param int $max_attempts Maximum attempts allowed in the window
 * @param int $window_seconds Window size in seconds
 * @param string|null $identifier Optional custom identifier (defaults to IP address)
 * @return array{allowed: bool, remaining: int, reset: int, retry_after: int|null}
 */
function rate_limit_check(string $action, int $max_attempts, int $window_seconds, ?string $identifier = null): array {
    $identifier = $identifier ?? ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    $now = microtime(true);

    $record = rate_limit_get_record($action, $identifier);

    if ($record === null) {
        $record = [
            'count' => 0,
            'window_start' => $now,
        ];
    }

    $window_elapsed = $now - $record['window_start'];

    if ($window_elapsed >= $window_seconds) {
        $record = [
            'count' => 0,
            'window_start' => $now,
        ];
    }

    if ($record['count'] >= $max_attempts) {
        $retry_after = (int) ceil($window_seconds - $window_elapsed);
        return [
            'allowed' => false,
            'remaining' => 0,
            'reset' => (int) ceil($record['window_start'] + $window_seconds),
            'retry_after' => max(1, $retry_after),
        ];
    }

    $record['count']++;
    $record['window_start'] = $now;
    rate_limit_set_record($action, $record, $identifier);

    return [
        'allowed' => true,
        'remaining' => $max_attempts - $record['count'],
        'reset' => (int) ceil($record['window_start'] + $window_seconds),
        'retry_after' => null,
    ];
}

/**
 * Set rate limit headers on current response.
 *
 * @param array{allowed: bool, remaining: int, reset: int, retry_after: int|null} $result
 * @param int $limit The max attempts limit
 */
function rate_limit_headers(array $result, int $limit): void {
    header("X-RateLimit-Limit: {$limit}");
    header("X-RateLimit-Remaining: {$result['remaining']}");
    header("X-RateLimit-Reset: {$result['reset']}");

    if (!$result['allowed'] && $result['retry_after'] !== null) {
        header("Retry-After: {$result['retry_after']}");
    }
}

/**
 * Perform a rate limit check and send headers. Returns false if rate limited.
 *
 * @param string $action Action identifier
 * @param int $max_attempts Maximum attempts
 * @param int $window_seconds Window in seconds
 * @param string|null $identifier Optional identifier
 * @return bool True if allowed, false if rate limited
 */
function rate_limit(string $action, int $max_attempts, int $window_seconds, ?string $identifier = null): bool {
    $result = rate_limit_check($action, $max_attempts, $window_seconds, $identifier);
    rate_limit_headers($result, $max_attempts);
    return $result['allowed'];
}