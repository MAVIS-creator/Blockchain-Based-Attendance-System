<?php

require_once __DIR__ . '/cache_helpers.php';

if (!function_exists('admin_accounts_file')) {
  function admin_accounts_file()
  {
    return admin_storage_migrate_file('accounts.json');
  }
}

if (!function_exists('admin_sessions_file')) {
  function admin_sessions_file()
  {
    return admin_storage_migrate_file('sessions.json');
  }
}

if (!function_exists('admin_settings_file')) {
  function admin_settings_file()
  {
    return admin_storage_migrate_file('settings.json');
  }
}

if (!function_exists('admin_settings_key_file')) {
  function admin_settings_key_file()
  {
    return admin_storage_migrate_file('.settings_key');
  }
}

if (!function_exists('admin_status_file')) {
  function admin_status_file()
  {
    return app_storage_migrate_file('status.json', __DIR__ . '/../status.json');
  }
}

if (!function_exists('admin_templates_file')) {
  function admin_templates_file()
  {
    return admin_storage_migrate_file('settings_templates.json');
  }
}

if (!function_exists('admin_chat_file')) {
  function admin_chat_file()
  {
    return admin_storage_migrate_file('chat.json');
  }
}

if (!function_exists('admin_settings_audit_file')) {
  function admin_settings_audit_file()
  {
    return admin_storage_migrate_file('settings_audit.log');
  }
}

if (!function_exists('admin_load_accounts_cached')) {
  function admin_load_accounts_cached($ttl = 15)
  {
    $file = admin_accounts_file();
    $accounts = admin_cached_json_file('accounts', $file, [], $ttl);
    return is_array($accounts) ? $accounts : [];
  }
}

if (!function_exists('admin_load_sessions_cached')) {
  function admin_load_sessions_cached($ttl = 10)
  {
    $file = admin_sessions_file();
    $sessions = admin_cached_json_file('sessions', $file, [], $ttl);
    return is_array($sessions) ? $sessions : [];
  }
}

if (!function_exists('admin_load_status_cached')) {
  function admin_load_status_cached($ttl = 10)
  {
    $file = admin_status_file();
    $status = admin_cached_json_file('status', $file, ['checkin' => false, 'checkout' => false, 'end_time' => null], $ttl);
    if (!isset($status['end_time'])) {
      $status['end_time'] = null;
    }
    return $status;
  }
}

if (!function_exists('admin_load_templates_cached')) {
  function admin_load_templates_cached($ttl = 20)
  {
    $file = admin_templates_file();
    $templates = admin_cached_json_file('settings_templates', $file, [], $ttl);
    return is_array($templates) ? $templates : [];
  }
}

if (!function_exists('admin_recent_text_lines_cached')) {
  function admin_recent_text_lines_cached($cachePrefix, $path, $limit = 20, $ttl = 10)
  {
    $mtime = @filemtime($path) ?: 0;
    $size = @filesize($path) ?: 0;
    $key = $cachePrefix . ':' . md5($path . '|' . $mtime . '|' . $size . '|' . (int)$limit);
    return admin_cache_remember($key, $ttl, function () use ($path, $limit) {
      if (!file_exists($path)) return [];
      $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
      return array_slice(array_map('trim', $lines), -max(1, (int)$limit));
    });
  }
}

if (!function_exists('admin_backup_files_cached')) {
  function admin_backup_files_cached($pattern = 'settings_*.json', $ttl = 20)
  {
    $backupDir = app_storage_file('backups');
    $manifest = [];
    if (is_dir($backupDir)) {
      foreach (glob($backupDir . DIRECTORY_SEPARATOR . $pattern) ?: [] as $file) {
        $manifest[] = basename($file) . '|' . (@filemtime($file) ?: 0) . '|' . (@filesize($file) ?: 0);
      }
    }
    sort($manifest);
    $key = 'backup_files:' . md5($pattern . '|' . implode(';', $manifest));
    return admin_cache_remember($key, $ttl, function () use ($backupDir, $pattern) {
      $files = glob($backupDir . DIRECTORY_SEPARATOR . $pattern) ?: [];
      usort($files, function ($a, $b) {
        return (@filemtime($b) ?: 0) <=> (@filemtime($a) ?: 0);
      });
      return $files;
    });
  }
}

if (!function_exists('admin_decrypt_settings_payload')) {
  function admin_decrypt_settings_payload($raw, $keyFile)
  {
    if (strpos((string)$raw, 'ENC:') !== 0 || !file_exists($keyFile)) {
      return null;
    }

    $key = trim((string)file_get_contents($keyFile));
    if ($key === '') {
      return null;
    }

    $blob = base64_decode(substr((string)$raw, 4));
    $iv = substr((string)$blob, 0, 16);
    $ct = substr((string)$blob, 16);
    $plain = openssl_decrypt($ct, 'AES-256-CBC', base64_decode($key), OPENSSL_RAW_DATA, $iv);
    $decoded = json_decode((string)$plain, true);
    return is_array($decoded) ? $decoded : null;
  }
}

if (!function_exists('admin_load_settings_cached')) {
  function admin_load_settings_cached($ttl = 15)
  {
    $settingsFile = admin_settings_file();
    $keyFile = admin_settings_key_file();
    $mtime = @filemtime($settingsFile) ?: 0;
    $size = @filesize($settingsFile) ?: 0;
    $keyMtime = @filemtime($keyFile) ?: 0;
    $cacheKey = 'settings_decoded:' . md5($settingsFile . '|' . $mtime . '|' . $size . '|' . $keyMtime);

    return admin_cache_remember($cacheKey, $ttl, function () use ($settingsFile, $keyFile) {
      if (!file_exists($settingsFile)) {
        return [];
      }
      $raw = (string)file_get_contents($settingsFile);
      $decoded = json_decode($raw, true);
      if (is_array($decoded)) {
        return $decoded;
      }
      $decrypted = admin_decrypt_settings_payload($raw, $keyFile);
      return is_array($decrypted) ? $decrypted : [];
    });
  }
}
