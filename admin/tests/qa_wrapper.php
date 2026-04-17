<?php
// Mock PHP script to run the patcher without starting an HTTP server
require_once __DIR__ . '/../admin/session_bootstrap.php';
admin_configure_session();
$_SESSION['admin_logged_in'] = true;
$_SESSION['admin_role'] = 'superadmin';

$_POST = json_decode(file_get_contents($argv[1]), true) ?? [];
$_GET['api'] = $_POST['action'] ?? '';
$_REQUEST['api'] = $_POST['action'] ?? '';
$_SERVER['REQUEST_METHOD'] = 'POST';

require_once __DIR__ . '/../includes/csrf.php';
$_POST['csrf_token'] = csrf_token();

// Mock json_decode in patcher
$_SERVER['HTTP_X_REQUESTED_WITH'] = 'XMLHttpRequest';
ob_start();
require __DIR__ . '/../patcher.php';
$output = ob_get_clean();

// Patcher outputs JSON and calls exit, we just print the ob buffer.
echo $output;
