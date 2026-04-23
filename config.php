<?php

// Optional .env loading (works locally and on shared hosting).
if (!isset($GLOBALS['_APP_ENV']) || !is_array($GLOBALS['_APP_ENV'])) {
    $GLOBALS['_APP_ENV'] = [];
}

$env_file = '';
$envMode = '';
$envModeFile = __DIR__ . '/.env.mode';
if (file_exists($envModeFile)) {
    $mode = trim((string) file_get_contents($envModeFile));
    $mode = strtolower($mode);
    if (in_array($mode, ['local', 'production'], true)) {
        $envMode = $mode;
    }
}

if ($envMode === '') {
    $httpHost = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
    $httpHost = explode(':', $httpHost, 2)[0];
    $isLocalHost = in_array($httpHost, ['localhost', '127.0.0.1', '::1'], true);
    $envMode = $isLocalHost ? 'local' : 'production';
}

$env_candidates = [];
if ($envMode === 'local') {
    $env_candidates[] = __DIR__ . '/.env.local';
} else {
    $env_candidates[] = __DIR__ . '/.env.production';
}
$env_candidates[] = __DIR__ . '/.env';

foreach ($env_candidates as $candidate) {
    if (file_exists($candidate)) {
        $env_file = $candidate;
        break;
    }
}

if ($env_file !== '') {
    $lines = file($env_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $trimmed = trim($line);
        if ($trimmed === '' || strpos($trimmed, '#') === 0) {
            continue;
        }

        if (strpos($line, '=') !== false) {
            list($key, $value) = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);

            if (
                strlen($value) >= 2
                && (($value[0] === '"' && substr($value, -1) === '"') || ($value[0] === '\'' && substr($value, -1) === '\''))
            ) {
                $value = substr($value, 1, -1);
            }

            if ($key === '') {
                continue;
            }

            // Keep a direct parsed map so shared hosts that restrict getenv/putenv still work.
            $GLOBALS['_APP_ENV'][$key] = $value;
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;

            if (function_exists('putenv')) {
                @putenv($key . '=' . $value);
            }
        }
    }
}

if (!function_exists('cfg_env')) {
    function cfg_env(string $key, string $default = ''): string
    {
        if (isset($GLOBALS['_APP_ENV']) && is_array($GLOBALS['_APP_ENV']) && array_key_exists($key, $GLOBALS['_APP_ENV'])) {
            return trim((string) $GLOBALS['_APP_ENV'][$key]);
        }

        if (array_key_exists($key, $_ENV)) {
            return trim((string) $_ENV[$key]);
        }

        if (array_key_exists($key, $_SERVER)) {
            return trim((string) $_SERVER[$key]);
        }

        if (function_exists('getenv')) {
            $value = getenv($key);
            if ($value !== false) {
                return trim((string) $value);
            }
        }

        return $default;
    }
}

if (!function_exists('cfg_env_bool')) {
    function cfg_env_bool(string $key, bool $default = false): bool
    {
        if (isset($GLOBALS['_APP_ENV']) && is_array($GLOBALS['_APP_ENV']) && array_key_exists($key, $GLOBALS['_APP_ENV'])) {
            $value = (string) $GLOBALS['_APP_ENV'][$key];
        } elseif (array_key_exists($key, $_ENV)) {
            $value = (string) $_ENV[$key];
        } elseif (array_key_exists($key, $_SERVER)) {
            $value = (string) $_SERVER[$key];
        } elseif (function_exists('getenv')) {
            $envValue = getenv($key);
            if ($envValue === false) {
                return $default;
            }
            $value = (string) $envValue;
        } else {
            return $default;
        }

        $normalized = strtolower(trim((string) $value));
        return in_array($normalized, ['1', 'true', 'yes', 'on'], true);
    }
}

if (!function_exists('cfg_detect_base_url')) {
    function cfg_detect_base_url(): string
    {
        $isHttps = (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
            || ((string) ($_SERVER['SERVER_PORT'] ?? '') === '443');
        $scheme = $isHttps ? 'https' : 'http';
        $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');

        $projectRoot = realpath(__DIR__);
        $documentRoot = isset($_SERVER['DOCUMENT_ROOT']) ? realpath((string) $_SERVER['DOCUMENT_ROOT']) : false;

        if ($projectRoot !== false && $documentRoot !== false) {
            $projectRootNorm = str_replace('\\', '/', $projectRoot);
            $documentRootNorm = rtrim(str_replace('\\', '/', $documentRoot), '/');

            if ($documentRootNorm !== '' && strpos($projectRootNorm, $documentRootNorm) === 0) {
                $relativePath = trim(substr($projectRootNorm, strlen($documentRootNorm)), '/');
                $basePath = $relativePath === '' ? '/' : '/' . $relativePath . '/';
                return $scheme . '://' . $host . $basePath;
            }
        }

        $scriptName = (string) ($_SERVER['SCRIPT_NAME'] ?? '/');
        $dir = str_replace('\\', '/', dirname($scriptName));
        $dir = $dir === '.' ? '/' : rtrim($dir, '/') . '/';
        return $scheme . '://' . $host . $dir;
    }
}

require_once __DIR__ . '/config/database.php';

// Application Configuration
$baseUrl = cfg_env('BASE_URL', cfg_detect_base_url());
$baseUrl = rtrim($baseUrl, '/') . '/';
define('BASE_URL', $baseUrl);
define('SUPERADMIN_EMAIL', cfg_env('SUPERADMIN_EMAIL', 'admin@library.local'));

// Developer / About Me metadata (shown in admin About Me page)
define('DEVELOPER_NAME', cfg_env('DEVELOPER_NAME', 'Mike L. Sordilla'));
define('DEVELOPER_TITLE', cfg_env('DEVELOPER_TITLE', 'Student 3rd Year college'));
define('DEVELOPER_STATUS', cfg_env('DEVELOPER_STATUS', 'Student 3rd Year college'));
define('DEVELOPER_EMAIL', cfg_env('DEVELOPER_EMAIL', 'mike.sordilla@nmsc.edu.ph'));
define('DEVELOPER_WEBSITE', cfg_env('DEVELOPER_WEBSITE', ''));
define('DEVELOPER_GITHUB', cfg_env('DEVELOPER_GITHUB', ''));
define('DEVELOPER_LINKEDIN', cfg_env('DEVELOPER_LINKEDIN', ''));
define('DEVELOPER_LOCATION', cfg_env('DEVELOPER_LOCATION', ''));
define('DEVELOPER_TIMEZONE', cfg_env('DEVELOPER_TIMEZONE', ''));
define('DEVELOPER_YEARS_EXPERIENCE', cfg_env('DEVELOPER_YEARS_EXPERIENCE', ''));
define('DEVELOPER_BIO', cfg_env('DEVELOPER_BIO', 'I design and build reliable digital systems that keep library operations fast, secure, and easy to use.'));
define('DEVELOPER_SPECIALTIES', cfg_env('DEVELOPER_SPECIALTIES', 'Backend Architecture, Data Modeling, UI Engineering, Product Design, Performance Optimization'));
define('DEVELOPER_STACK', cfg_env('DEVELOPER_STACK', 'PHP, MySQL, JavaScript, CSS, HTML'));
define('DEVELOPER_FOCUS_AREAS', cfg_env('DEVELOPER_FOCUS_AREAS', 'Secure authentication and role-based access, Clean admin workflows and UX consistency, Data integrity across circulation and reservations'));
define('DEVELOPER_HIGHLIGHTS', cfg_env('DEVELOPER_HIGHLIGHTS', 'Architected role-protected admin operations with audit logging||Designed a cohesive admin UX with responsive account flows||Built secure account controls for profile and password management'));
define('DEVELOPER_PROJECT_1_NAME', cfg_env('DEVELOPER_PROJECT_1_NAME', 'Library System Core Platform'));
define('DEVELOPER_PROJECT_1_DESC', cfg_env('DEVELOPER_PROJECT_1_DESC', 'End-to-end lending, reservations, and account management platform tailored for modern library operations.'));
define('DEVELOPER_PROJECT_1_URL', cfg_env('DEVELOPER_PROJECT_1_URL', ''));
define('DEVELOPER_PROJECT_2_NAME', cfg_env('DEVELOPER_PROJECT_2_NAME', 'Admin Security & Compliance Layer'));
define('DEVELOPER_PROJECT_2_DESC', cfg_env('DEVELOPER_PROJECT_2_DESC', 'Role protections, action logging, and safe account controls for administrative workflows.'));
define('DEVELOPER_PROJECT_2_URL', cfg_env('DEVELOPER_PROJECT_2_URL', ''));
define('DEVELOPER_PROJECT_3_NAME', cfg_env('DEVELOPER_PROJECT_3_NAME', 'Account Experience Redesign'));
define('DEVELOPER_PROJECT_3_DESC', cfg_env('DEVELOPER_PROJECT_3_DESC', 'Profile and password interfaces rebuilt with clearer structure, better feedback, and mobile-first usability.'));
define('DEVELOPER_PROJECT_3_URL', cfg_env('DEVELOPER_PROJECT_3_URL', ''));

// SMTP / Email Configuration (PHPMailer)
// 'tls' = STARTTLS (port 587), 'ssl' = SSL (port 465), '' = none
define('SMTP_HOST', cfg_env('SMTP_HOST', 'smtp.gmail.com'));
define('SMTP_PORT', (int) cfg_env('SMTP_PORT', '587'));
define('SMTP_SECURE', cfg_env('SMTP_SECURE', 'tls'));
define('SMTP_USER', cfg_env('SMTP_USER', ''));
define('SMTP_PASS', cfg_env('SMTP_PASS', ''));
define('SMTP_FROM_EMAIL', cfg_env('SMTP_FROM_EMAIL', ''));
define('SMTP_FROM_NAME', cfg_env('SMTP_FROM_NAME', 'Library System'));

// Debug Mode (should be false in production)
define('DEBUG_MODE', cfg_env_bool('DEBUG_MODE', false));

if (!DEBUG_MODE) {
    error_reporting(0);
    ini_set('display_errors', '0');
} else {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
}
