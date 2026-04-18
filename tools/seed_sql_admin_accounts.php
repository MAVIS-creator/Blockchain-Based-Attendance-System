<?php

require_once __DIR__ . '/../admin/sql_accounts.php';
require_once __DIR__ . '/../admin/state_helpers.php';

$opts = getopt('', [
  'from-json::',
  'maximus::',
  'maximus-email::',
  'maximus-password::',
]);

$fromJson = isset($opts['from-json']) ? (string)$opts['from-json'] : admin_accounts_file();
$includeMaximus = !isset($opts['maximus']) || strtolower((string)$opts['maximus']) !== 'false';
$maximusEmail = isset($opts['maximus-email']) ? (string)$opts['maximus-email'] : 'isholasamuel062@gmail.com';
$maximusPassword = isset($opts['maximus-password']) ? (string)$opts['maximus-password'] : 'Callmelater';

$pdo = admin_sql_accounts_connect($error);
if (!$pdo) {
  fwrite(STDERR, "[ERROR] {$error}\n");
  exit(1);
}

if (!admin_sql_accounts_ensure_schema($pdo, $error)) {
  fwrite(STDERR, "[ERROR] {$error}\n");
  exit(1);
}

if (!admin_sql_accounts_seed_defaults($pdo, $error)) {
  fwrite(STDERR, "[ERROR] {$error}\n");
  exit(1);
}

$seeded = 0;
$skipped = 0;

if (is_string($fromJson) && $fromJson !== '' && file_exists($fromJson)) {
  $accounts = json_decode((string)@file_get_contents($fromJson), true);
  if (is_array($accounts)) {
    foreach ($accounts as $username => $row) {
      if (!is_array($row)) {
        continue;
      }

      $username = trim((string)$username);
      if ($username === '') {
        continue;
      }

      $email = trim((string)($row['email'] ?? ''));
      $displayName = trim((string)($row['name'] ?? $username));
      $role = trim((string)($row['role'] ?? 'admin'));
      $needsTour = !empty($row['needs_tour']) ? 1 : 0;
      $passwordHash = trim((string)($row['password'] ?? ''));

      if ($passwordHash === '') {
        $skipped++;
        continue;
      }

      try {
        $table = admin_sql_accounts_table();
        $stmt = $pdo->prepare("SELECT TOP 1 id FROM [dbo].[{$table}] WHERE LOWER(username)=LOWER(?)");
        $stmt->execute([$username]);
        if ($stmt->fetch()) {
          $skipped++;
          continue;
        }

        $ins = $pdo->prepare("INSERT INTO [dbo].[{$table}] (username, email, password_hash, display_name, avatar, role, needs_tour, is_active, updated_at) VALUES (?, ?, ?, ?, NULL, ?, ?, 1, SYSUTCDATETIME())");
        $ins->execute([
          $username,
          ($email !== '' ? $email : null),
          $passwordHash,
          ($displayName !== '' ? $displayName : $username),
          ($role !== '' ? $role : 'admin'),
          $needsTour,
        ]);
        $seeded++;
      } catch (Throwable $e) {
        fwrite(STDERR, "[WARN] Failed to migrate {$username}: " . $e->getMessage() . "\n");
      }
    }
  }
}

if ($includeMaximus) {
  $ok = admin_sql_accounts_seed_user(
    $pdo,
    'Maximus',
    $maximusEmail,
    $maximusPassword,
    'admin',
    'Maximus',
    0,
    $error
  );
  if ($ok) {
    $seeded++;
  } else {
    fwrite(STDERR, "[WARN] Failed to seed Maximus: {$error}\n");
  }
}

echo "[OK] SQL account seeding complete. Seeded={$seeded}, Skipped={$skipped}\n";
