<?php
session_start();
require_once __DIR__ . '/runtime_storage.php';
require_once __DIR__ . '/state_helpers.php';
admin_unregister_session(session_id());
session_destroy();
header("Location: login.php");
exit;
