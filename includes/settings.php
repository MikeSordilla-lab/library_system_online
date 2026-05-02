<?php

/**
 * includes/settings.php — Centralized settings management
 *
 * Provides unified interface for retrieving system configuration values
 * from the Settings table.
 */

/**
 * Returns a reference to the shared settings cache.
 * Both get_setting and _setting_cache_clear use this to access the same static array.
 */
function &_settings_cache_ref(): array
{
  static $cache = [];
  return $cache;
}

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
  $cache = &_settings_cache_ref();
  if (isset($cache[$key])) {
    return $cache[$key];
  }
  $stmt = $pdo->prepare('SELECT `value` FROM `Settings` WHERE `key` = ? LIMIT 1');
  $stmt->execute([$key]);
  $row = $stmt->fetch();
  $cache[$key] = ($row !== false) ? (string) $row['value'] : $default;
  return $cache[$key];
}

function _setting_cache_clear(string $key): void
{
  $cache = &_settings_cache_ref();
  unset($cache[$key]);
}

function set_setting(PDO $pdo, string $key, string $value): bool
{
  try {
    $stmt = $pdo->prepare('REPLACE INTO `Settings` (`key`, `value`) VALUES (?, ?)');
    $stmt->execute([$key, $value]);
    _setting_cache_clear($key);
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
