<?php

require_once __DIR__ . '/../admin/runtime_storage.php';

class AiServiceIdentity
{
  public $id;
  public $name;
  public $created_at;
  public $can_login = false;
  public $capabilities = [];

  private static $cache = [];

  public function __construct($id, $name, array $capabilities = [], $createdAt = null)
  {
    $this->id = (string)$id;
    $this->name = (string)$name;
    $this->capabilities = array_values(array_unique(array_map('strval', $capabilities)));
    $this->created_at = $createdAt ?: date('c');
    $this->can_login = false;
  }

  public function canLogin()
  {
    return false;
  }

  public static function filePath()
  {
    return admin_storage_migrate_file('ai_accounts.json');
  }

  public static function ensureSeed()
  {
    $file = self::filePath();
    if (file_exists($file)) {
      return;
    }

    $seed = [
      'system_ai_operator' => [
        'id' => 'system_ai_operator',
        'name' => 'System AI Operator',
        'created_at' => date('c'),
        'can_login' => false,
        'capabilities' => [
          'ticket.read',
          'ticket.diagnose',
          'ticket.resolve',
          'ticket.add_attendance',
          'announcement.write_targeted',
          'logs.export',
          'chat.admin_assist'
        ]
      ]
    ];

    file_put_contents($file, json_encode($seed, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
  }

  public static function load($id)
  {
    $id = (string)$id;
    if ($id === '') {
      return null;
    }
    if (isset(self::$cache[$id])) {
      return self::$cache[$id];
    }

    self::ensureSeed();
    $raw = @file_get_contents(self::filePath());
    $data = json_decode((string)$raw, true);
    if (!is_array($data) || !isset($data[$id]) || !is_array($data[$id])) {
      return null;
    }

    $row = $data[$id];
    $identity = new self(
      $row['id'] ?? $id,
      $row['name'] ?? $id,
      isset($row['capabilities']) && is_array($row['capabilities']) ? $row['capabilities'] : [],
      $row['created_at'] ?? null
    );

    self::$cache[$id] = $identity;
    return $identity;
  }
}
