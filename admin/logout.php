<?php
session_start();
require_once __DIR__ . '/runtime_storage.php';
$sessionsFile = admin_storage_migrate_file('sessions.json');
if (file_exists($sessionsFile)) {
  $activeSessions = json_decode(file_get_contents($sessionsFile), true);
  if (isset($activeSessions[session_id()])) {
    unset($activeSessions[session_id()]);
    file_put_contents($sessionsFile, json_encode($activeSessions, JSON_PRETTY_PRINT));
  }
}
session_destroy();
header("Location: login.php");
exit;
