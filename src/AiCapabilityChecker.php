<?php

require_once __DIR__ . '/../admin/runtime_storage.php';

class AiCapabilityChecker
{
  private static $cache = [];

  public static function filePath()
  {
    return admin_storage_migrate_file('ai_permissions.json');
  }

  public static function ensureSeed()
  {
    $file = self::filePath();
    if (file_exists($file)) {
      return;
    }

    $seed = [
      'system_ai_operator' => [
        'ticket.read' => true,
        'ticket.diagnose' => true,
        'ticket.resolve' => true,
        'ticket.add_attendance' => true,
        'announcement.write_targeted' => true,
        'logs.export' => true,
        'chat.admin_assist' => true
      ]
    ];

    file_put_contents($file, json_encode($seed, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
  }

  public static function can($serviceId, $capability)
  {
    $serviceId = (string)$serviceId;
    $capability = (string)$capability;
    if ($serviceId === '' || $capability === '') {
      return false;
    }

    if (!isset(self::$cache[$serviceId])) {
      self::ensureSeed();
      $raw = @file_get_contents(self::filePath());
      $all = json_decode((string)$raw, true);
      self::$cache[$serviceId] = (is_array($all) && isset($all[$serviceId]) && is_array($all[$serviceId]))
        ? $all[$serviceId]
        : [];
    }

    return !empty(self::$cache[$serviceId][$capability]);
  }
}

if (!function_exists('ai_can')) {
  function ai_can($serviceId, $capability)
  {
    return AiCapabilityChecker::can($serviceId, $capability);
  }
}
