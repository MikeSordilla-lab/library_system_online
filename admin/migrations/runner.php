<?php

/**
 * admin/migrations/runner.php — Unified Database Migration Runner
 *
 * This script handles all database migrations for the Library Management System.
 * It supports running specific migrations or all available migrations.
 *
 * Usage:
 *   php runner.php                    - Show available migrations
 *   php runner.php all               - Run all migrations
 *   php runner.php receipts-phase1   - Run specific migration
 *   php runner.php reservations      - Run specific migration (alias)
 *
 * Exit codes:
 *   0 = Success
 *   1 = Errors during migration
 *   2 = Invalid migration name
 */

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/db.php';

// Define available migrations
$migrations = [
    'receipts-phase1' => [
        'file' => 'database/migration-receipts-phase1.sql',
        'description' => 'Add phase 1 receipt tickets and receipt logs',
        'aliases' => ['receipts', 'ticket', 'tickets']
    ],
    'receipts-phase1-safe' => [
        'file' => 'database/migration-receipts-phase1-safe.sql',
        'description' => 'Idempotent receipt phase 1 column-extension script',
        'aliases' => ['receipts-safe', 'ticket-safe']
    ],
    'reservations-approval-phase1' => [
        'file' => 'database/migration-reservations-approval-phase1.sql',
        'description' => 'Add reservation approval/rejection metadata and indexes',
        'aliases' => ['reservations-approval', 'reservations', 'reservation-approval']
    ]
];

// Get command line argument
$command = $argv[1] ?? 'help';

// Show help
if ($command === 'help' || $command === '--help' || $command === '-h') {
    show_help();
    exit(0);
}

// Show available migrations
if ($command === 'list' || $command === 'ls') {
    show_list();
    exit(0);
}

// Run all migrations
if ($command === 'all') {
    echo "Running all migrations...\n\n";
    $total_applied = 0;
    $total_skipped = 0;
    $total_errors = 0;

    foreach ($migrations as $name => $config) {
        $result = run_migration($name, $config);
        $total_applied += $result['applied'];
        $total_skipped += $result['skipped'];
        $total_errors += $result['errors'];
        echo "\n";
    }

    echo str_repeat("=", 80) . "\n";
    echo "ALL MIGRATIONS COMPLETE\n";
    echo str_repeat("=", 80) . "\n";
    echo "Total Applied: $total_applied\n";
    echo "Total Skipped: $total_skipped\n";
    echo "Total Errors:  $total_errors\n";
    exit($total_errors > 0 ? 1 : 0);
}

// Find matching migration
$migration_name = null;
$migration_config = null;

foreach ($migrations as $name => $config) {
    if ($command === $name) {
        $migration_name = $name;
        $migration_config = $config;
        break;
    }
    
    // Check aliases
    if (in_array($command, $config['aliases'] ?? [])) {
        $migration_name = $name;
        $migration_config = $config;
        break;
    }
}

// Migration not found
if (!$migration_name) {
    echo "Error: Unknown migration '$command'\n\n";
    show_list();
    exit(2);
}

// Run the migration
$result = run_migration($migration_name, $migration_config);
exit($result['errors'] > 0 ? 1 : 0);

/**
 * Execute a specific migration
 */
function run_migration($name, $config) {
    global $migrations;
    
    $pdo = get_db();
    $migration_file = __DIR__ . '/../../' . $config['file'];

    if (!file_exists($migration_file)) {
        echo "✗ Migration file not found: {$config['file']}\n";
        return ['applied' => 0, 'skipped' => 0, 'errors' => 1];
    }

    $sql = file_get_contents($migration_file);

    // Parse SQL statements
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

    // Execute statements
    $success_count = 0;
    $skip_count = 0;
    $error_count = 0;

    echo "Applying migration: $name\n";
    echo "Description: {$config['description']}\n";
    echo str_repeat("-", 80) . "\n";

    foreach ($statements as $statement) {
        if (empty(trim($statement))) {
            continue;
        }
        
        try {
            $pdo->exec($statement);
            echo "[✓] " . substr($statement, 0, 75) . (strlen($statement) > 75 ? "..." : "") . "\n";
            $success_count++;
        } catch (PDOException $e) {
            // Check if it's a "already exists" error (safe to skip)
            if (strpos($e->getMessage(), 'Duplicate') !== false || 
                strpos($e->getMessage(), 'already exists') !== false) {
                echo "[~] " . substr($statement, 0, 75) . "... (exists - skipped)\n";
                $skip_count++;
            } else {
                echo "[✗] " . substr($statement, 0, 75) . (strlen($statement) > 75 ? "..." : "") . "\n";
                echo "    Error: " . $e->getMessage() . "\n";
                $error_count++;
            }
        }
    }

    echo str_repeat("-", 80) . "\n";
    echo "Migration Complete: $name\n";
    echo "  Applied:  $success_count\n";
    echo "  Skipped:  $skip_count\n";
    echo "  Errors:   $error_count\n";

    return ['applied' => $success_count, 'skipped' => $skip_count, 'errors' => $error_count];
}

/**
 * Show help message
 */
function show_help() {
    echo <<<'EOF'
╔════════════════════════════════════════════════════════════════════════════════╗
║            Library Management System - Database Migration Runner               ║
╚════════════════════════════════════════════════════════════════════════════════╝

USAGE:
  php runner.php [COMMAND]

COMMANDS:
  help              Show this help message
  list              List all available migrations
  all               Run all migrations in sequence
  <migration>       Run specific migration by name or alias

AVAILABLE MIGRATIONS:
  receipts-phase1        - Add receipt/ticket tables (aliases: receipts, ticket, tickets)
  receipts-phase1-safe   - Safe re-run receipt extension (aliases: receipts-safe, ticket-safe)
  reservations-approval-phase1 - Add reservation approval columns/indexes (aliases: reservations-approval, reservations, reservation-approval)

EXAMPLES:
  php runner.php                    # Show this help
  php runner.php list               # List all migrations
  php runner.php all                # Run all migrations
  php runner.php receipts-phase1    # Run receipts migration
  php runner.php reservations       # Run reservations approval migration (alias)

EXIT CODES:
  0 = Success
  1 = Migration errors occurred
  2 = Invalid migration name

EOF;
}

/**
 * Show available migrations
 */
function show_list() {
    global $migrations;
    
    echo "Available Migrations:\n\n";
    echo str_repeat("-", 80) . "\n";
    printf("%-25s | %-35s | %-20s\n", "Name", "Description", "Aliases");
    echo str_repeat("-", 80) . "\n";
    
    foreach ($migrations as $name => $config) {
        $aliases = implode(', ', $config['aliases'] ?? []);
        printf("%-25s | %-35s | %-20s\n", $name, substr($config['description'], 0, 35), $aliases);
    }
    
    echo str_repeat("-", 80) . "\n";
    echo "\nRun 'php runner.php all' to execute all migrations\n";
}
