<?php

if (!function_exists('app_storage_env_value')) {
  function app_storage_env_value($key, $default = null)
  {
    $val = getenv($key);
    if ($val !== false && $val !== '') return $val;

    static $cache = null;
    if ($cache === null) {
      $cache = [];
      $envPath = __DIR__ . '/.env';
      if (file_exists($envPath)) {
        $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
          $line = trim((string)$line);
          if ($line === '' || strpos($line, '#') === 0 || strpos($line, '=') === false) continue;
          list($k, $v) = explode('=', $line, 2);
          $cache[trim($k)] = trim(trim($v), "\"'");
        }
      }
    }

    return array_key_exists($key, $cache) ? $cache[$key] : $default;
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
