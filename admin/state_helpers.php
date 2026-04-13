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
    return admin_storage_migrate_file('status.json', app_storage_file('status.json'));
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

if (!function_exists('admin_chat_typing_file')) {
  function admin_chat_typing_file()
  {
    return admin_storage_migrate_file('chat_typing.json');
  }
}

if (!function_exists('admin_chat_ai_queue_file')) {
  function admin_chat_ai_queue_file()
  {
    return admin_storage_migrate_file('chat_ai_queue.json');
  }
}

if (!function_exists('admin_settings_audit_file')) {
  function admin_settings_audit_file()
  {
    return admin_storage_migrate_file('settings_audit.log');
  }
}

if (!function_exists('admin_permissions_file')) {
  function admin_permissions_file()
  {
    return admin_storage_migrate_file('permissions.json');
  }
}

if (!function_exists('admin_audit_file')) {
  function admin_audit_file()
  {
    return admin_storage_migrate_file('admin_audit.json');
  }
}

if (!function_exists('ai_accounts_file')) {
  function ai_accounts_file()
  {
    return admin_storage_migrate_file('ai_accounts.json');
  }
}

if (!function_exists('ai_permissions_file')) {
  function ai_permissions_file()
  {
    return admin_storage_migrate_file('ai_permissions.json');
  }
}

if (!function_exists('ai_targeted_announcements_file')) {
  function ai_targeted_announcements_file()
  {
    return admin_storage_migrate_file('announcement_targets.json');
  }
}

if (!function_exists('ai_ticket_diagnostics_file')) {
  function ai_ticket_diagnostics_file()
  {
    return admin_storage_migrate_file('ai_ticket_diagnostics.json');
  }
}

if (!function_exists('ai_auto_send_tracker_file')) {
  function ai_auto_send_tracker_file()
  {
    return admin_storage_migrate_file('ai_auto_send_tracker.json');
  }
}

if (!function_exists('ai_rulebook_file')) {
  function ai_rulebook_file()
  {
    return admin_storage_migrate_file('ai_rulebook.json');
  }
}

if (!function_exists('admin_route_catalog')) {
  function admin_route_catalog()
  {
    return [
      'dashboard' => ['file' => 'dashboard.php', 'label' => 'Dashboard', 'superadmin_only' => false],
      'unauthorized' => ['file' => 'unauthorized.php', 'label' => 'Unauthorized', 'superadmin_only' => true, 'assignable' => false],
      'roles' => ['file' => 'roles.php', 'label' => 'Role Privileges', 'superadmin_only' => true],
      'audit' => ['file' => 'audit.php', 'label' => 'Action Audit Log', 'superadmin_only' => true],
      'status' => ['file' => 'status.php', 'label' => 'Status Monitor', 'superadmin_only' => false],
      'status_debug' => ['file' => 'status_debug.php', 'label' => 'Status Diagnostics', 'superadmin_only' => true],
      'request_timings' => ['file' => 'request_timings.php', 'label' => 'Request Timings', 'superadmin_only' => false],
      'logs' => ['file' => 'logs/logs.php', 'label' => 'General Logs', 'superadmin_only' => false],
      'clear_logs_ui' => ['file' => 'clear_logs_ui.php', 'label' => 'Clear / Backup Logs', 'superadmin_only' => false],
      'clear_tokens_ui' => ['file' => 'clear_tokens_ui.php', 'label' => 'Access Tokens Management', 'superadmin_only' => false],
      'failed_attempts' => ['file' => 'logs/failed_attempts.php', 'label' => 'Failed Verification Logs', 'superadmin_only' => false],
      'accounts' => ['file' => 'accounts.php', 'label' => 'Manage Accounts', 'superadmin_only' => true],
      'settings' => ['file' => 'settings.php', 'label' => 'System Settings', 'superadmin_only' => true],
      'chain' => ['file' => 'chain.php', 'label' => 'Blockchain Ledger', 'superadmin_only' => false],
      'add_course' => ['file' => 'courses/add.php', 'label' => 'Add Course', 'superadmin_only' => false],
      'set_active' => ['file' => 'courses/set_active.php', 'label' => 'Set Active Course', 'superadmin_only' => false],
      'manual_attendance' => ['file' => 'manual_attendance.php', 'label' => 'Manual Attendance Tool', 'superadmin_only' => false],
      'geofence' => ['file' => 'geofence.php', 'label' => 'Geo-fence', 'superadmin_only' => true],
      'support_tickets' => ['file' => 'view_tickets.php', 'label' => 'Support Tickets', 'superadmin_only' => false],
      'ai_suggestions' => ['file' => 'ai_suggestions.php', 'label' => 'AI Suggestions', 'superadmin_only' => false],
      'ai_context_preview' => ['file' => 'ai_context_preview.php', 'label' => 'AI Context Preview', 'superadmin_only' => false],
      'ai_rulebook' => ['file' => 'ai_rulebook.php', 'label' => 'AI Rulebook Trainer', 'superadmin_only' => false],
      'unlink_fingerprint' => ['file' => 'unlink_fingerprint.php', 'label' => 'Unlink Fingerprints', 'superadmin_only' => false],
      'announcement' => ['file' => 'announcement.php', 'label' => 'Broadcast Announcements', 'superadmin_only' => false],
      'patcher' => ['file' => 'patcher.php', 'label' => 'Patcher Studio', 'superadmin_only' => false],
      'send_logs_email' => ['file' => 'send_logs_email.php', 'label' => 'Email Reports', 'superadmin_only' => false],
      'profile_settings' => ['file' => 'profile_settings.php', 'label' => 'Profile Settings', 'superadmin_only' => false],
    ];
  }
}

if (!function_exists('admin_assignable_pages')) {
  function admin_assignable_pages()
  {
    $catalog = admin_route_catalog();
    $assignable = [];
    foreach ($catalog as $pageId => $meta) {
      $isSuperOnly = !empty($meta['superadmin_only']);
      $isExplicitlyAssignable = array_key_exists('assignable', $meta) ? !empty($meta['assignable']) : true;
      if (!$isSuperOnly && $isExplicitlyAssignable) {
        $assignable[$pageId] = [
          'label' => (string)($meta['label'] ?? ucwords(str_replace('_', ' ', $pageId))),
          'file' => (string)($meta['file'] ?? ''),
        ];
      }
    }
    return $assignable;
  }
}

if (!function_exists('admin_log_action')) {
  function admin_log_action($category, $action, $details)
  {
    $entry = [
      'timestamp' => date('Y-m-d H:i:s'),
      'admin' => $_SESSION['admin_user'] ?? 'system',
      'role' => $_SESSION['admin_role'] ?? 'unknown',
      'ip' => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
      'category' => $category,
      'action' => $action,
      'details' => $details
    ];

    // Dual-write to Supabase if hybrid mode is enabled
    $hybridFile = dirname(__DIR__) . '/hybrid_dual_write.php';
    $isHybrid = false;
    if (file_exists($hybridFile)) {
      require_once $hybridFile;
      if (function_exists('hybrid_enabled') && hybrid_enabled()) {
        $isHybrid = true;
        hybrid_dual_write('admin_audit', 'admin_audit_logs', [
          'timestamp' => $entry['timestamp'],
          'admin_user' => $entry['admin'],
          'admin_role' => $entry['role'],
          'ip_address' => $entry['ip'],
          'category' => $entry['category'],
          'action' => $entry['action'],
          'details' => $entry['details']
        ]);
      }
    }

    // Write to local JSON:
    // - Always write locally on localhost/dev (safe offline fallback)
    // - On production with dual_write, SKIP local write (Supabase is primary; outbox handles failures)
    $isLocal = function_exists('app_is_local_environment') && app_is_local_environment();

    if (!$isHybrid || $isLocal) {
      $auditFile = admin_audit_file();
      $logs = file_exists($auditFile) ? json_decode(file_get_contents($auditFile), true) : [];
      if (!is_array($logs)) $logs = [];

      array_unshift($logs, $entry);
      // Retain only the last 500 actions locally (Supabase holds the full history)
      if (count($logs) > 500) {
        $logs = array_slice($logs, 0, 500);
      }

      @file_put_contents($auditFile, json_encode($logs, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
    }
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

if (!function_exists('admin_load_permissions_cached')) {
  function admin_load_permissions_cached($ttl = 30)
  {
    $file = admin_permissions_file();
    // Default allowed pages for the "admin" role
    $defaultAllowed = [
      'dashboard',
      'status',
      'request_timings',
      'logs',
      'support_tickets',
      'ai_suggestions',
      'ai_context_preview',
      'ai_rulebook',
      'announcement',
      'profile_settings',
      'manual_attendance'
    ];
    $permissions = admin_cached_json_file('permissions', $file, ['admin' => $defaultAllowed], $ttl);
    if (!is_array($permissions)) {
      return ['admin' => $defaultAllowed];
    }

    $assignableKeys = array_fill_keys(array_keys(admin_assignable_pages()), true);
    $normalized = [];
    foreach ($permissions as $role => $pages) {
      if (!is_string($role) || $role === '' || !is_array($pages)) {
        continue;
      }
      $sanitizedPages = [];
      foreach ($pages as $pageId) {
        if (is_string($pageId) && isset($assignableKeys[$pageId])) {
          $sanitizedPages[$pageId] = true;
        }
      }
      $normalized[$role] = array_keys($sanitizedPages);
    }

    if (!isset($normalized['admin'])) {
      $normalized['admin'] = $defaultAllowed;
    } else {
      $normalized['admin'] = array_values(array_unique(array_merge($defaultAllowed, $normalized['admin'])));
    }

    return $normalized;
  }
}

if (!function_exists('admin_load_status_cached')) {
  function admin_load_status_cached($ttl = 10)
  {
    $file = admin_status_file();
    $status = admin_cached_json_file('status', $file, ['checkin' => false, 'checkout' => false, 'end_time' => null], $ttl);
    $sourceWasArray = is_array($status);
    if (!is_array($status)) {
      $status = ['checkin' => false, 'checkout' => false, 'end_time' => null];
    }

    $normalized = [
      'checkin' => !empty($status['checkin']),
      'checkout' => !empty($status['checkout']),
      'end_time' => isset($status['end_time']) && is_numeric($status['end_time']) ? (int)$status['end_time'] : null,
    ];

    $isActiveMode = $normalized['checkin'] || $normalized['checkout'];
    $isTimerValid = $normalized['end_time'] !== null && $normalized['end_time'] > time();

    // Rigid rule: active modes must always have a valid future end_time.
    if ($isActiveMode && !$isTimerValid) {
      $normalized = ['checkin' => false, 'checkout' => false, 'end_time' => null];
    }

    if (!$normalized['checkin'] && !$normalized['checkout']) {
      $normalized['end_time'] = null;
    }

    if (
      $sourceWasArray &&
      (
        ($status['checkin'] ?? null) !== $normalized['checkin'] ||
        ($status['checkout'] ?? null) !== $normalized['checkout'] ||
        (($status['end_time'] ?? null) !== $normalized['end_time'])
      )
    ) {
      @file_put_contents($file, json_encode($normalized, JSON_PRETTY_PRINT), LOCK_EX);
    }

    return $normalized;
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
