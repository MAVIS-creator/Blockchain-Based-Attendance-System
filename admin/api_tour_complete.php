<?php
require_once __DIR__ . '/session_bootstrap.php';
require_once __DIR__ . '/sql_accounts.php';
require_once __DIR__ . '/state_helpers.php';

header('Content-Type: application/json');

if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
    exit;
}

require_once __DIR__ . '/runtime_storage.php';

$username = $_SESSION['admin_user'] ?? '';
if (!$username) {
    echo json_encode(['ok' => false, 'error' => 'Unknown user']);
    exit;
}

if (admin_should_use_sql_accounts()) {
    $sqlError = null;
    if (!admin_sql_update_needs_tour($username, false, $sqlError)) {
        echo json_encode(['ok' => false, 'error' => $sqlError ?: 'Failed to update SQL tour state']);
        exit;
    }
} else {
    $accountsFile = admin_accounts_file();
    if (!file_exists($accountsFile)) {
        echo json_encode(['ok' => false, 'error' => 'File not found']);
        exit;
    }

    $accounts = admin_load_accounts_cached(0);
    if (!is_array($accounts)) {
        $accounts = [];
    }

    $accountKey = null;
    foreach ($accounts as $key => $_account) {
        if (strcasecmp((string)$key, (string)$username) === 0) {
            $accountKey = (string)$key;
            break;
        }
    }

    if ($accountKey !== null && isset($accounts[$accountKey]['needs_tour'])) {
        unset($accounts[$accountKey]['needs_tour']);
        if (!admin_write_json_atomic($accountsFile, $accounts)) {
            echo json_encode(['ok' => false, 'error' => 'Failed to update JSON tour state']);
            exit;
        }
    }
}

// Clear the session flag so it doesn't trigger on reload
$_SESSION['needs_tour'] = false;

echo json_encode(['ok' => true]);
