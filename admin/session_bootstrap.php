<?php

require_once __DIR__ . '/../env_helpers.php';
require_once __DIR__ . '/../storage_helpers.php';

if (!defined('ADMIN_SESSION_NAME')) {
  define('ADMIN_SESSION_NAME', 'ATTENDANCE_ADMIN_SESSION');
}

if (!function_exists('admin_auth_debug_enabled')) {
  function admin_auth_debug_enabled()
  {
    $queryFlag = strtolower(trim((string)($_GET['auth_debug'] ?? $_POST['auth_debug'] ?? '')));
    if (in_array($queryFlag, ['1', 'true', 'yes', 'on'], true)) {
      return true;
    }

    $envFlag = strtolower(trim((string)app_env_value('AUTH_DEBUG_PANEL', '0')));
    return in_array($envFlag, ['1', 'true', 'yes', 'on'], true);
  }
}

if (!function_exists('admin_auth_debug_file')) {
  function admin_auth_debug_file()
  {
    app_storage_init();
    $file = app_storage_file('logs/admin_auth_debug.jsonl');
    $dir = dirname($file);
    if (!is_dir($dir)) {
      @mkdir($dir, 0775, true);
    }
    return $file;
  }
}

if (!function_exists('admin_auth_debug_log')) {
  function admin_auth_debug_log($event, array $context = [])
  {
    $payload = [
      'time' => date('c'),
      'event' => (string)$event,
      'sid' => (string)session_id(),
      'session_name' => (string)session_name(),
      'request_method' => (string)($_SERVER['REQUEST_METHOD'] ?? ''),
      'request_uri' => (string)($_SERVER['REQUEST_URI'] ?? ''),
      'remote_addr' => (string)($_SERVER['REMOTE_ADDR'] ?? ''),
      'save_path' => (string)ini_get('session.save_path'),
      'cookie_present' => isset($_COOKIE[session_name()]),
      'context' => $context,
    ];

    $line = json_encode($payload, JSON_UNESCAPED_SLASHES);
    if (!is_string($line) || $line === '') {
      return false;
    }

    return @file_put_contents(admin_auth_debug_file(), $line . PHP_EOL, FILE_APPEND | LOCK_EX) !== false;
  }
}

if (!function_exists('admin_auth_debug_recent')) {
  function admin_auth_debug_recent($limit = 20)
  {
    $limit = max(1, (int)$limit);
    $file = admin_auth_debug_file();
    if (!is_file($file)) {
      return [];
    }

    $lines = @file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!is_array($lines) || empty($lines)) {
      return [];
    }

    $lines = array_slice($lines, -$limit);
    $rows = [];
    foreach ($lines as $line) {
      $decoded = json_decode((string)$line, true);
      if (is_array($decoded)) {
        $rows[] = $decoded;
      }
    }

    return $rows;
  }
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

    // Instance-local temp storage remains a fallback only and should be avoided
    // on Azure where requests may land on different workers causing session loss.
    if (!$isAzureAppService && $tmpSessionDir !== '') {
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

    $selectedSavePath = (string)ini_get('session.save_path');
    $normalizedSavePath = str_replace('\\', '/', strtolower($selectedSavePath));
    $selectedIsEphemeral = (strpos($normalizedSavePath, '/tmp/') === 0);

    admin_auth_debug_log('session_bootstrap', [
      'started' => ($started || session_status() === PHP_SESSION_ACTIVE),
      'selected_session_dir' => $sessionDir,
      'candidate_dirs' => $sessionDirs,
      'is_secure_cookie' => $isSecure,
      'is_azure_app_service' => $isAzureAppService,
      'is_linux_runtime' => $isLinuxRuntime,
      'selected_is_ephemeral' => $selectedIsEphemeral,
    ]);
  }
}

admin_configure_session();
