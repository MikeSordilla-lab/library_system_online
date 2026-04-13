<?php

/**
 * Quick migration runner for password reset functionality
 */

require_once __DIR__ . '/includes/db.php';

try {
  $pdo = get_db();

  // Read migration file
  $migration_sql = file_get_contents(__DIR__ . '/database/migration-password-reset.sql');

  // Split by semicolon and execute each statement
  $statements = array_filter(
    array_map('trim', explode(';', $migration_sql)),
    fn($stmt) => !empty($stmt) && !str_starts_with($stmt, '--')
  );

  foreach ($statements as $statement) {
    echo "Executing: " . substr($statement, 0, 50) . "...\n";
    $pdo->exec($statement);
  }

  echo "\n✓ Migration completed successfully!\n";
  echo "✓ Added password_reset_token column\n";
  echo "✓ Added password_reset_expires column\n";
  echo "✓ Created index on password_reset_token\n";
} catch (PDOException $e) {
  echo "✗ Migration failed: " . $e->getMessage() . "\n";
  exit(1);
}
