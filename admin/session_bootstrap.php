<?php

require_once __DIR__ . '/../env_helpers.php';
require_once __DIR__ . '/../storage_helpers.php';

if (!defined('ADMIN_SESSION_NAME')) {
  define('ADMIN_SESSION_NAME', 'ATTENDANCE_ADMIN_SESSION');
}

if (!function_exists('admin_configure_session')) {
  function admin_configure_session()
  {
    $targetName = ADMIN_SESSION_NAME;

    // If a session is already active, check it is using the right name.
    // If it's not (e.g. a bare session_start() was called earlier), close it
    // and re-open with the correct name so $_SESSION holds the right data.
    if (session_status() === PHP_SESSION_ACTIVE) {
      if (session_name() === $targetName) {
        // Already the right session — nothing to do.
        return;
      }
      // Wrong session name was opened; write & close, then fall through to
      // re-start with the correct name.
      session_write_close();
    }

    app_storage_init();

    $lifetimeMinutes = (int)app_env_value('SESSION_LIFETIME', 120);
    if ($lifetimeMinutes <= 0) {
      $lifetimeMinutes = 120;
    }
    $lifetimeSeconds = $lifetimeMinutes * 60;

    $httpsForwarded = strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
    $httpsNative = !empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off';
    $isSecure = $httpsForwarded || $httpsNative;

    $isAzureAppService = getenv('WEBSITE_SITE_NAME') !== false || getenv('WEBSITE_INSTANCE_ID') !== false;
    $isLinuxRuntime = DIRECTORY_SEPARATOR === '/';
    $tmpSessionDir = rtrim((string)sys_get_temp_dir(), '/\\');

    $sessionDirs = [];

    // Explicit environment override has highest priority.
    $envSessionPath = trim((string)app_env_value('SESSION_SAVE_PATH', ''));
    if ($envSessionPath !== '') {
      $sessionDirs[] = $envSessionPath;
    }

    // On Azure App Service, prefer shared home-based session storage so
    // sessions survive restarts and requests routed across workers/instances.
    if ($isAzureAppService) {
      $azureHome = trim((string)getenv('HOME'));
      if ($azureHome !== '') {
        $sessionDirs[] = rtrim($azureHome, '/\\') . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'attendance_sessions';
      }

      // Keep the Linux-style shared path as an additional fallback for hosts
      // that expose the shared home volume at /home.
      if ($isLinuxRuntime) {
        $sessionDirs[] = '/home/data/attendance_sessions';
      }
    }

    // App storage sessions path next.
    $sessionDirs[] = app_storage_file('sessions');

    // Instance-local temp storage remains a fallback only.
    if ($tmpSessionDir !== '') {
      $sessionDirs[] = $tmpSessionDir . DIRECTORY_SEPARATOR . 'attendance_sessions';
    }

    $sessionDir = '';
    foreach (array_values(array_unique($sessionDirs)) as $candidateDir) {
      $candidateDir = trim((string)$candidateDir);
      if ($candidateDir === '') {
        continue;
      }
      if (!is_dir($candidateDir)) {
        @mkdir($candidateDir, 0775, true);
      }
      if (is_dir($candidateDir) && is_writable($candidateDir)) {
        $sessionDir = $candidateDir;
        break;
      }
    }
    if ($sessionDir === '') {
      $sessionDir = app_storage_file('sessions');
    }

    @ini_set('session.save_path', $sessionDir);
    @ini_set('session.gc_maxlifetime', (string)$lifetimeSeconds);
    @ini_set('session.use_strict_mode', '1');
    @ini_set('session.use_cookies', '1');
    @ini_set('session.use_only_cookies', '1');
    @ini_set('session.cookie_httponly', '1');
    @ini_set('session.cookie_samesite', 'Lax');
    @ini_set('session.cookie_secure', $isSecure ? '1' : '0');

    session_name($targetName);

    if (PHP_VERSION_ID >= 70300) {
      session_set_cookie_params([
        'lifetime' => $lifetimeSeconds,
        'path' => '/',
        'domain' => '',
        'secure' => $isSecure,
        'httponly' => true,
        'samesite' => 'Lax',
      ]);
    } else {
      session_set_cookie_params($lifetimeSeconds, '/; samesite=Lax', '', $isSecure, true);
    }

    $started = @session_start();
    if (!$started && session_status() !== PHP_SESSION_ACTIVE) {
      foreach (array_values(array_unique($sessionDirs)) as $fallbackDir) {
        $fallbackDir = trim((string)$fallbackDir);
        if ($fallbackDir === '' || $fallbackDir === $sessionDir) {
          continue;
        }
        if (!is_dir($fallbackDir)) {
          @mkdir($fallbackDir, 0775, true);
        }
        if (!is_dir($fallbackDir) || !is_writable($fallbackDir)) {
          continue;
        }
        @ini_set('session.save_path', $fallbackDir);
        $started = @session_start();
        if ($started || session_status() === PHP_SESSION_ACTIVE) {
          break;
        }
      }
    }

    if (!$started && session_status() !== PHP_SESSION_ACTIVE) {
      @error_log('admin_configure_session: session_start failed; verify save_path permissions and platform storage settings.');
    }
  }
}

admin_configure_session();
