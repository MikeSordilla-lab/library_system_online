<?php

/**
 * admin/migrations/run-migration.php
 *
 * Legacy compatibility wrapper that delegates to runner.php.
 *
 * Examples:
 *   php run-migration.php list
 *   php run-migration.php all
 *   php run-migration.php receipts-phase1
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('This script can only be run from CLI.');
}

$runner = __DIR__ . '/runner.php';
if (!file_exists($runner)) {
    fwrite(STDERR, "Migration runner not found: {$runner}\n");
    exit(1);
}

$args = $argv ?? [];
array_shift($args);
if ($args === []) {
    $args = ['help'];
}

fwrite(STDERR, "[deprecated] run-migration.php delegates to runner.php\n");

$command = escapeshellarg((string) PHP_BINARY) . ' ' . escapeshellarg($runner);
foreach ($args as $arg) {
    $command .= ' ' . escapeshellarg((string) $arg);
}

$exitCode = 0;
passthru($command, $exitCode);
exit($exitCode);
