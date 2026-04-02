<?php
session_start();
require_once __DIR__ . '/runtime_storage.php';
require_once __DIR__ . '/state_helpers.php';
$sessionsFile = admin_sessions_file();
if (file_exists($sessionsFile)) {
  $activeSessions = admin_load_sessions_cached(10);
  if (isset($activeSessions[session_id()])) {
    unset($activeSessions[session_id()]);
    file_put_contents($sessionsFile, json_encode($activeSessions, JSON_PRETTY_PRINT));
  }
}
session_destroy();
header("Location: login.php");
exit;
