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

    $sessionDir = app_storage_file('sessions');
    if (!is_dir($sessionDir)) {
      @mkdir($sessionDir, 0775, true);
    }

    @ini_set('session.save_path', $sessionDir);
    @ini_set('session.gc_maxlifetime', (string)$lifetimeSeconds);
    @ini_set('session.use_strict_mode', '1');
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

    session_start();
  }
}

admin_configure_session();

