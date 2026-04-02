<?php

require_once __DIR__ . '/env_helpers.php';

if (!function_exists('app_storage_env_value')) {
  function app_storage_env_value($key, $default = null)
  {
    return app_env_value($key, $default, __DIR__ . '/.env');
  }
}

if (!function_exists('app_storage_path')) {
  function app_storage_path()
  {
    $configured = trim((string)app_storage_env_value('STORAGE_PATH', ''));
    if ($configured === '') {
      $configured = __DIR__ . '/storage';
    }

    $normalized = rtrim($configured, '/\\');
    if ($normalized === '') {
      $normalized = __DIR__ . '/storage';
    }

    if (!defined('STORAGE_PATH')) {
      define('STORAGE_PATH', $normalized . DIRECTORY_SEPARATOR);
    }

    return $normalized;
  }
}

if (!function_exists('app_storage_file')) {
  function app_storage_file($relative)
  {
    $relative = ltrim(str_replace('\\', '/', (string)$relative), '/');
    return app_storage_path() . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
  }
}

if (!function_exists('app_storage_migrate_file')) {
  function app_storage_migrate_file($relative, $legacyPath)
  {
    $target = app_storage_file($relative);
    if (!file_exists($target) && is_string($legacyPath) && $legacyPath !== '' && file_exists($legacyPath)) {
      $dir = dirname($target);
      if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
      }
      @copy($legacyPath, $target);
    }
    return $target;
  }
}

if (!function_exists('app_storage_init')) {
  function app_storage_init()
  {
    $base = app_storage_path();
    $dirs = ['logs', 'secure_logs', 'backups'];

    if (!is_dir($base)) {
      @mkdir($base, 0775, true);
    }

    foreach ($dirs as $dir) {
      $path = app_storage_file($dir);
      if (!is_dir($path)) {
        @mkdir($path, 0775, true);
      }
    }

    @chmod($base, 0775);
    return $base;
  }
}
