<?php

/**
 * apply-migration.php — Apply database migrations
 *
 * Usage: php apply-migration.php
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';

$pdo = get_db();

// Read migration file
$migration_file = __DIR__ . '/database/migration-add-performance-indexes.sql';

if (!file_exists($migration_file)) {
    die("Migration file not found: $migration_file\n");
}

$sql = file_get_contents($migration_file);

// Split SQL statements (handle comments)
$statements = [];
$current = '';

foreach (explode("\n", $sql) as $line) {
    $line = trim($line);
    
    // Skip empty lines and comments
    if (empty($line) || strpos($line, '--') === 0 || strpos($line, '/*') === 0) {
        continue;
    }
    
    $current .= ' ' . $line;
    
    // Check if statement ends with semicolon
    if (substr(rtrim($current), -1) === ';') {
        $statements[] = rtrim($current, ';');
        $current = '';
    }
}

// Execute each statement
$success_count = 0;
$skip_count = 0;
$error_count = 0;

echo "Applying migration: $migration_file\n";
echo str_repeat("-", 80) . "\n";

foreach ($statements as $statement) {
    if (empty(trim($statement))) {
        continue;
    }
    
    try {
        $pdo->exec($statement);
        echo "[✓] " . substr($statement, 0, 80) . (strlen($statement) > 80 ? "..." : "") . "\n";
        $success_count++;
    } catch (PDOException $e) {
        // Check if it's a "already exists" error (safe to skip)
        if (strpos($e->getMessage(), 'Duplicate') !== false || 
            strpos($e->getMessage(), 'already exists') !== false) {
            echo "[~] " . substr($statement, 0, 80) . "... (already exists - skipped)\n";
            $skip_count++;
        } else {
            echo "[✗] " . substr($statement, 0, 80) . (strlen($statement) > 80 ? "..." : "") . "\n";
            echo "    Error: " . $e->getMessage() . "\n";
            $error_count++;
        }
    }
}

echo str_repeat("-", 80) . "\n";
echo "Migration Complete:\n";
echo "  Applied:  $success_count\n";
echo "  Skipped:  $skip_count\n";
echo "  Errors:   $error_count\n";

exit($error_count > 0 ? 1 : 0);
