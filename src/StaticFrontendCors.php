<?php
declare(strict_types=1);

require_once __DIR__ . '/../env_helpers.php';

/**
 * CORS is a browser read permission, not authorization. Server-side attendance
 * validation, fingerprint controls, course checks and anti-abuse rules still apply.
 */
function bbas_public_frontend_cors(array $methods = ['GET']): void
{
    $allowed = rtrim(trim((string)app_env_value('BBAS_STATIC_FRONTEND_ORIGIN', '')), '/');
    $origin = rtrim(trim((string)($_SERVER['HTTP_ORIGIN'] ?? '')), '/');
    if ($allowed !== '' && $origin !== '' && hash_equals($allowed, $origin)) {
        header('Access-Control-Allow-Origin: ' . $allowed);
        header('Vary: Origin', false);
        header('Access-Control-Allow-Methods: ' . implode(', ', $methods));
        header('Access-Control-Allow-Headers: Content-Type');
        header('Access-Control-Max-Age: 300');
    }
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
        if ($allowed === '' || !hash_equals($allowed, $origin)) {
            http_response_code(403);
        } else {
            http_response_code(204);
        }
        exit;
    }
}
