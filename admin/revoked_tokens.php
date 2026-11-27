<?php
declare(strict_types=1);

// Public clients may ask whether THEIR random browser token has been revoked.
// Never publish the global list of tokens, IP addresses, administrator names,
// or MAC addresses. Enrollment/recovery are handled by separate verified flows.
require_once __DIR__ . '/../src/StaticFrontendCors.php';
bbas_public_frontend_cors(['POST', 'OPTIONS']);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');
header('X-Content-Type-Options: nosniff');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST, OPTIONS');
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'method_not_allowed']);
    exit;
}

$token = $_POST['token'] ?? '';
if (!is_string($token) || !preg_match('/^[a-zA-Z0-9_-]{16,128}$/D', $token)) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'invalid_token']);
    exit;
}
require_once __DIR__ . '/../storage_helpers.php';
require_once __DIR__ . '/runtime_storage.php';
require_once __DIR__ . '/cache_helpers.php';
app_storage_init();
$file = admin_storage_migrate_file('revoked.json', app_storage_file('revoked.json'));
$data = admin_cached_json_file('revoked_self_check', $file, ['tokens' => []], 2);
$meta = is_array($data['tokens'] ?? null) ? ($data['tokens'][$token] ?? null) : null;
$expiry = is_array($meta) ? (int)($meta['expiry'] ?? 0) : 0;
$active = $meta !== null && ($expiry === 0 || $expiry >= time());
echo json_encode(['ok' => true, 'revoked' => $active]);
