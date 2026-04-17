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

    // Normalize configured path:
    // - Absolute paths remain unchanged.
    // - Relative paths are resolved predictably from project root.
    // - On Azure Linux App Service, allow "home/..." shorthand => "/home/...".
    $hasDrivePrefix = strlen($configured) > 2
      && ctype_alpha($configured[0])
      && $configured[1] === ':'
      && ($configured[2] === '\\' || $configured[2] === '/');
    $isUnixAbsolute = isset($configured[0]) && $configured[0] === '/';
    $isUncPath = strncmp($configured, '\\\\', 2) === 0 || strncmp($configured, '//', 2) === 0;
    $isAbsolute = $hasDrivePrefix || $isUnixAbsolute || $isUncPath;
    if (!$isAbsolute) {
      $looksLikeAzureHome = preg_match('#^home[\\/]#i', $configured) === 1;
      $isAzureAppService = getenv('WEBSITE_SITE_NAME') !== false || getenv('WEBSITE_INSTANCE_ID') !== false;
      $isLinuxRuntime = DIRECTORY_SEPARATOR === '/';

      if ($looksLikeAzureHome && $isAzureAppService && $isLinuxRuntime) {
        $configured = DIRECTORY_SEPARATOR . ltrim($configured, '/\\');
      } else {
        $configured = __DIR__ . DIRECTORY_SEPARATOR . $configured;
      }
    }

    $normalized = rtrim($configured, '/\\');
    if ($normalized === '') {
      $normalized = __DIR__ . '/storage';
    }

    // ---- Writable-path safety check ----
    // If the resolved path does not exist or cannot be written to, fall back to
    // the project-local storage/ directory. This prevents silent session failures
    // on hosted environments where the configured path (e.g. /home/data on Azure)
    // may not have been provisioned or may not be writable.
    if (!is_dir($normalized)) {
      @mkdir($normalized, 0775, true);
    }
    if (!is_dir($normalized) || !is_writable($normalized)) {
      // Fallback to project-local storage directory first.
      $fallback = __DIR__ . '/storage';
      if (!is_dir($fallback)) {
        @mkdir($fallback, 0775, true);
      }
      $normalized = $fallback;
    }

    // On Azure App Service Linux, prefer a writable shared /home location
    // when the project path is not writable (e.g. run-from-package deployments).
    if (!is_dir($normalized) || !is_writable($normalized)) {
      $isAzureAppService = getenv('WEBSITE_SITE_NAME') !== false || getenv('WEBSITE_INSTANCE_ID') !== false;
      $isLinuxRuntime = DIRECTORY_SEPARATOR === '/';
      if ($isAzureAppService && $isLinuxRuntime) {
        $azureFallback = '/home/data/attendance_storage';
        if (!is_dir($azureFallback)) {
          @mkdir($azureFallback, 0775, true);
        }
        if (is_dir($azureFallback) && is_writable($azureFallback)) {
          $normalized = $azureFallback;
        }
      }
    }

    // Final safety fallback for constrained environments.
    if (!is_dir($normalized) || !is_writable($normalized)) {
      $tmpFallback = rtrim((string)sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'attendance_storage';
      if (!is_dir($tmpFallback)) {
        @mkdir($tmpFallback, 0775, true);
      }
      if (is_dir($tmpFallback) && is_writable($tmpFallback)) {
        $normalized = $tmpFallback;
      }
    }
    // ---- End writable-path safety check ----

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
    $dir = dirname($target);
    if (!is_dir($dir)) {
      @mkdir($dir, 0775, true);
    }

    if (!file_exists($target) && is_string($legacyPath) && $legacyPath !== '' && file_exists($legacyPath)) {
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

    $htaccess = $base . DIRECTORY_SEPARATOR . '.htaccess';
    if (!file_exists($htaccess)) {
      $rules = "Options -Indexes\r\n<IfModule mod_authz_core.c>\r\nRequire all denied\r\n</IfModule>\r\n<IfModule !mod_authz_core.c>\r\nDeny from all\r\n</IfModule>\r\n";
      @file_put_contents($htaccess, $rules, LOCK_EX);
    }

    $indexFile = $base . DIRECTORY_SEPARATOR . 'index.html';
    if (!file_exists($indexFile)) {
      @file_put_contents($indexFile, '', LOCK_EX);
    }

    @chmod($base, 0775);
    return $base;
  }
}
