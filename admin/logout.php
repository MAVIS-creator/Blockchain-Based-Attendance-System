<?php
session_start();
$sessionsFile = __DIR__ . '/sessions.json';
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
