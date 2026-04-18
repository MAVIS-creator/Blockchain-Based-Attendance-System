<?php

require_once __DIR__ . '/../env_helpers.php';

if (!function_exists('admin_should_use_sql_accounts')) {
  function admin_should_use_sql_accounts()
  {
    if (app_is_local_environment()) {
      return false;
    }

    $backend = strtolower(trim((string)app_env_value('ADMIN_ACCOUNTS_BACKEND', 'json')));
    return $backend === 'sql';
  }
}

if (!function_exists('admin_sql_accounts_dsn')) {
  function admin_sql_accounts_dsn()
  {
    $dsn = trim((string)app_env_value('ADMIN_SQL_DSN', ''));
    if ($dsn !== '') {
      return $dsn;
    }

    $server = trim((string)app_env_value('ADMIN_SQL_SERVER', ''));
    $database = trim((string)app_env_value('ADMIN_SQL_DATABASE', ''));
    if ($server === '' || $database === '') {
      return '';
    }

    return 'sqlsrv:Server=' . $server . ';Database=' . $database . ';Encrypt=yes;TrustServerCertificate=no;LoginTimeout=30';
  }
}

if (!function_exists('admin_sql_accounts_table')) {
  function admin_sql_accounts_table()
  {
    $table = trim((string)app_env_value('ADMIN_SQL_ACCOUNTS_TABLE', 'admin_accounts'));
    if ($table === '') {
      $table = 'admin_accounts';
    }
    return $table;
  }
}

if (!function_exists('admin_sql_accounts_connect')) {
  function admin_sql_accounts_connect(&$error = null)
  {
    $error = null;

    if (!extension_loaded('pdo_sqlsrv')) {
      $error = 'pdo_sqlsrv extension is required for SQL account backend.';
      return null;
    }

    $dsn = admin_sql_accounts_dsn();
    $username = trim((string)app_env_value('ADMIN_SQL_USERNAME', ''));
    $password = (string)app_env_value('ADMIN_SQL_PASSWORD', '');

    if ($dsn === '' || $username === '' || $password === '') {
      $error = 'ADMIN_SQL_DSN/ADMIN_SQL_SERVER+ADMIN_SQL_DATABASE and ADMIN_SQL_USERNAME/ADMIN_SQL_PASSWORD must be configured.';
      return null;
    }

    try {
      $pdo = new PDO($dsn, $username, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
      ]);
      return $pdo;
    } catch (Throwable $e) {
      $error = 'SQL connection failed: ' . $e->getMessage();
      return null;
    }
  }
}

if (!function_exists('admin_sql_accounts_ensure_schema')) {
  function admin_sql_accounts_ensure_schema(PDO $pdo, &$error = null)
  {
    $error = null;
    $table = admin_sql_accounts_table();

    try {
      $sql = "
IF NOT EXISTS (SELECT * FROM sys.objects WHERE object_id = OBJECT_ID(N'[dbo].[{$table}]') AND type in (N'U'))
BEGIN
  CREATE TABLE [dbo].[{$table}] (
    [id] INT IDENTITY(1,1) NOT NULL PRIMARY KEY,
    [username] NVARCHAR(64) NOT NULL UNIQUE,
    [email] NVARCHAR(255) NULL,
    [password_hash] NVARCHAR(255) NOT NULL,
    [display_name] NVARCHAR(255) NULL,
    [avatar] NVARCHAR(255) NULL,
    [role] NVARCHAR(64) NOT NULL DEFAULT('admin'),
    [needs_tour] BIT NOT NULL DEFAULT(0),
    [is_active] BIT NOT NULL DEFAULT(1),
    [created_at] DATETIME2 NOT NULL DEFAULT(SYSUTCDATETIME()),
    [updated_at] DATETIME2 NOT NULL DEFAULT(SYSUTCDATETIME())
  );
END
";
      $pdo->exec($sql);
      return true;
    } catch (Throwable $e) {
      $error = 'Failed to ensure SQL account schema: ' . $e->getMessage();
      return false;
    }
  }
}

if (!function_exists('admin_sql_accounts_seed_user')) {
  function admin_sql_accounts_seed_user(PDO $pdo, $username, $email, $plainPassword, $role = 'admin', $displayName = '', $needsTour = 0, &$error = null)
  {
    $error = null;
    $username = trim((string)$username);
    $email = trim((string)$email);
    $plainPassword = (string)$plainPassword;
    $role = trim((string)$role);
    $displayName = trim((string)$displayName);
    $needsTour = (int)$needsTour;

    if ($username === '' || $plainPassword === '') {
      $error = 'Username and password are required for SQL seed user.';
      return false;
    }

    $table = admin_sql_accounts_table();

    try {
      $checkStmt = $pdo->prepare("SELECT TOP 1 id FROM [dbo].[{$table}] WHERE LOWER(username) = LOWER(?)");
      $checkStmt->execute([$username]);
      $existing = $checkStmt->fetch();

      if ($existing) {
        return true;
      }

      $hash = password_hash($plainPassword, PASSWORD_DEFAULT);
      $insertStmt = $pdo->prepare("INSERT INTO [dbo].[{$table}] (username, email, password_hash, display_name, avatar, role, needs_tour, is_active, updated_at) VALUES (?, ?, ?, ?, NULL, ?, ?, 1, SYSUTCDATETIME())");
      $insertStmt->execute([
        $username,
        ($email !== '' ? $email : null),
        $hash,
        ($displayName !== '' ? $displayName : $username),
        ($role !== '' ? $role : 'admin'),
        ($needsTour ? 1 : 0),
      ]);

      return true;
    } catch (Throwable $e) {
      $error = 'Failed to seed SQL user: ' . $e->getMessage();
      return false;
    }
  }
}

if (!function_exists('admin_sql_accounts_seed_defaults')) {
  function admin_sql_accounts_seed_defaults(PDO $pdo, &$error = null)
  {
    $error = null;

    $bootstrapUser = trim((string)app_env_value('ADMIN_BOOTSTRAP_USERNAME', ''));
    $bootstrapEmail = trim((string)app_env_value('ADMIN_BOOTSTRAP_EMAIL', ''));
    $bootstrapPassword = (string)app_env_value('ADMIN_BOOTSTRAP_PASSWORD', '');
    $bootstrapRole = trim((string)app_env_value('ADMIN_BOOTSTRAP_ROLE', 'superadmin'));

    if ($bootstrapUser !== '' && $bootstrapPassword !== '') {
      $ok = admin_sql_accounts_seed_user($pdo, $bootstrapUser, $bootstrapEmail, $bootstrapPassword, $bootstrapRole, $bootstrapUser, 0, $seedError);
      if (!$ok) {
        $error = $seedError;
        return false;
      }
    }

    return true;
  }
}

if (!function_exists('admin_sql_authenticate_user')) {
  function admin_sql_authenticate_user($username, $password, &$account = null, &$reason = null)
  {
    $account = null;
    $reason = null;

    $username = trim((string)$username);
    $password = (string)$password;
    if ($username === '' || $password === '') {
      $reason = 'missing_credentials';
      return false;
    }

    $pdo = admin_sql_accounts_connect($connectError);
    if (!$pdo) {
      $reason = $connectError ?: 'sql_connection_failed';
      return false;
    }

    if (!admin_sql_accounts_ensure_schema($pdo, $schemaError)) {
      $reason = $schemaError ?: 'sql_schema_error';
      return false;
    }

    if (!admin_sql_accounts_seed_defaults($pdo, $seedError)) {
      $reason = $seedError ?: 'sql_seed_error';
      return false;
    }

    $table = admin_sql_accounts_table();

    try {
      $stmt = $pdo->prepare("SELECT TOP 1 username, email, password_hash, display_name, avatar, role, needs_tour, is_active FROM [dbo].[{$table}] WHERE LOWER(username) = LOWER(?)");
      $stmt->execute([$username]);
      $row = $stmt->fetch();

      if (!$row || !is_array($row)) {
        $reason = 'user_not_found';
        return false;
      }

      $isActive = !array_key_exists('is_active', $row) || (int)$row['is_active'] === 1;
      if (!$isActive) {
        $reason = 'user_inactive';
        return false;
      }

      $storedHash = (string)($row['password_hash'] ?? '');
      if ($storedHash === '' || !password_verify($password, $storedHash)) {
        $reason = 'password_mismatch';
        return false;
      }

      $account = [
        'username' => (string)($row['username'] ?? $username),
        'email' => (string)($row['email'] ?? ''),
        'name' => (string)($row['display_name'] ?? $username),
        'avatar' => (string)($row['avatar'] ?? ''),
        'role' => (string)($row['role'] ?? 'admin'),
        'needs_tour' => !empty($row['needs_tour']),
      ];
      return true;
    } catch (Throwable $e) {
      $reason = 'sql_query_error: ' . $e->getMessage();
      return false;
    }
  }
}

if (!function_exists('admin_sql_list_accounts')) {
  function admin_sql_list_accounts(&$error = null)
  {
    $error = null;
    $pdo = admin_sql_accounts_connect($connectError);
    if (!$pdo) {
      $error = $connectError;
      return [];
    }

    if (!admin_sql_accounts_ensure_schema($pdo, $schemaError)) {
      $error = $schemaError;
      return [];
    }

    if (!admin_sql_accounts_seed_defaults($pdo, $seedError)) {
      $error = $seedError;
      return [];
    }

    $table = admin_sql_accounts_table();
    try {
      $stmt = $pdo->query("SELECT username, email, password_hash, display_name, avatar, role, needs_tour, is_active FROM [dbo].[{$table}] WHERE is_active = 1 ORDER BY username ASC");
      $rows = $stmt->fetchAll();
      if (!is_array($rows)) {
        return [];
      }

      $accounts = [];
      foreach ($rows as $row) {
        $u = trim((string)($row['username'] ?? ''));
        if ($u === '') {
          continue;
        }
        $accounts[$u] = [
          'password' => (string)($row['password_hash'] ?? ''),
          'name' => (string)($row['display_name'] ?? $u),
          'email' => (string)($row['email'] ?? ''),
          'avatar' => (string)($row['avatar'] ?? ''),
          'role' => (string)($row['role'] ?? 'admin'),
          'needs_tour' => !empty($row['needs_tour']),
        ];
      }
      return $accounts;
    } catch (Throwable $e) {
      $error = 'Failed to load SQL accounts: ' . $e->getMessage();
      return [];
    }
  }
}

if (!function_exists('admin_sql_create_account')) {
  function admin_sql_create_account($username, $fullname, $email, $password, $role = 'admin', $needsTour = 1, &$error = null)
  {
    $error = null;
    $pdo = admin_sql_accounts_connect($connectError);
    if (!$pdo) {
      $error = $connectError;
      return false;
    }
    if (!admin_sql_accounts_ensure_schema($pdo, $schemaError)) {
      $error = $schemaError;
      return false;
    }

    $username = trim((string)$username);
    if ($username === '') {
      $error = 'Username is required.';
      return false;
    }

    $table = admin_sql_accounts_table();
    try {
      $check = $pdo->prepare("SELECT TOP 1 id FROM [dbo].[{$table}] WHERE LOWER(username) = LOWER(?)");
      $check->execute([$username]);
      if ($check->fetch()) {
        $error = 'Username already exists.';
        return false;
      }

      $hash = password_hash((string)$password, PASSWORD_DEFAULT);
      $stmt = $pdo->prepare("INSERT INTO [dbo].[{$table}] (username, email, password_hash, display_name, avatar, role, needs_tour, is_active, updated_at) VALUES (?, ?, ?, ?, NULL, ?, ?, 1, SYSUTCDATETIME())");
      $stmt->execute([
        $username,
        trim((string)$email) !== '' ? trim((string)$email) : null,
        $hash,
        trim((string)$fullname) !== '' ? trim((string)$fullname) : $username,
        trim((string)$role) !== '' ? trim((string)$role) : 'admin',
        $needsTour ? 1 : 0,
      ]);
      return true;
    } catch (Throwable $e) {
      $error = 'Failed to create SQL account: ' . $e->getMessage();
      return false;
    }
  }
}

if (!function_exists('admin_sql_delete_account')) {
  function admin_sql_delete_account($username, &$error = null)
  {
    $error = null;
    $pdo = admin_sql_accounts_connect($connectError);
    if (!$pdo) {
      $error = $connectError;
      return false;
    }
    if (!admin_sql_accounts_ensure_schema($pdo, $schemaError)) {
      $error = $schemaError;
      return false;
    }

    $table = admin_sql_accounts_table();
    try {
      $stmt = $pdo->prepare("UPDATE [dbo].[{$table}] SET is_active = 0, updated_at = SYSUTCDATETIME() WHERE LOWER(username) = LOWER(?)");
      $stmt->execute([trim((string)$username)]);
      return true;
    } catch (Throwable $e) {
      $error = 'Failed to delete SQL account: ' . $e->getMessage();
      return false;
    }
  }
}

if (!function_exists('admin_sql_update_password')) {
  function admin_sql_update_password($username, $newPassword, &$error = null)
  {
    $error = null;
    $pdo = admin_sql_accounts_connect($connectError);
    if (!$pdo) {
      $error = $connectError;
      return false;
    }
    if (!admin_sql_accounts_ensure_schema($pdo, $schemaError)) {
      $error = $schemaError;
      return false;
    }

    $table = admin_sql_accounts_table();
    try {
      $hash = password_hash((string)$newPassword, PASSWORD_DEFAULT);
      $stmt = $pdo->prepare("UPDATE [dbo].[{$table}] SET password_hash = ?, updated_at = SYSUTCDATETIME() WHERE LOWER(username) = LOWER(?) AND is_active = 1");
      $stmt->execute([$hash, trim((string)$username)]);
      return true;
    } catch (Throwable $e) {
      $error = 'Failed to update SQL password: ' . $e->getMessage();
      return false;
    }
  }
}

if (!function_exists('admin_sql_update_role')) {
  function admin_sql_update_role($username, $newRole, &$error = null)
  {
    $error = null;
    $pdo = admin_sql_accounts_connect($connectError);
    if (!$pdo) {
      $error = $connectError;
      return false;
    }
    if (!admin_sql_accounts_ensure_schema($pdo, $schemaError)) {
      $error = $schemaError;
      return false;
    }

    $table = admin_sql_accounts_table();
    try {
      $stmt = $pdo->prepare("UPDATE [dbo].[{$table}] SET role = ?, updated_at = SYSUTCDATETIME() WHERE LOWER(username) = LOWER(?) AND is_active = 1");
      $stmt->execute([trim((string)$newRole), trim((string)$username)]);
      return true;
    } catch (Throwable $e) {
      $error = 'Failed to update SQL role: ' . $e->getMessage();
      return false;
    }
  }
}
