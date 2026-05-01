<?php

/**
 * includes/settings.php — Centralized settings management
 *
 * Provides unified interface for retrieving system configuration values
 * from the Settings table.
 */

/**
 * Get a system setting value from the Settings table
 *
 * @param PDO $pdo Database connection
 * @param string $key Setting key to retrieve
 * @param string $default Default value if setting not found (optional)
 * @return string Setting value or default
 */
function get_setting(PDO $pdo, string $key, string $default = ''): string
{
  $stmt = $pdo->prepare('SELECT `value` FROM `Settings` WHERE `key` = ? LIMIT 1');
  $stmt->execute([$key]);
  $row = $stmt->fetch();
  return ($row !== false) ? (string) $row['value'] : $default;
}

/**
 * Set a system setting value in the Settings table
 *
 * @param PDO $pdo Database connection
 * @param string $key Setting key
 * @param string $value Setting value
 * @return bool Success status
 */
function set_setting(PDO $pdo, string $key, string $value): bool
{
  try {
    // Use REPLACE to insert or update
    $stmt = $pdo->prepare('REPLACE INTO `Settings` (`key`, `value`) VALUES (?, ?)');
    $stmt->execute([$key, $value]);
    return true;
  } catch (PDOException $e) {
    error_log('[settings.php] Failed to set setting: ' . $e->getMessage());
    return false;
  }
}

/**
 * Get all system settings
 *
 * @param PDO $pdo Database connection
 * @return array Key-value mapping of all settings
 */
function get_all_settings(PDO $pdo): array
{
  $stmt = $pdo->prepare('SELECT `key`, `value` FROM `Settings`');
  $stmt->execute();
  $results = [];
  foreach ($stmt->fetchAll() as $row) {
    $results[$row['key']] = $row['value'];
  }
  return $results;
}
