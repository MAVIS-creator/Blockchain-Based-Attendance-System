<?php
declare(strict_types=1);

function bbas_unified_admin_claims(
    string $authorization,
    string $publicKey,
    string $subjects,
    int $now,
    string $scope = 'dashboard:read'
): ?array
{
    if ($publicKey === '' || strlen($authorization) > 4096 ||
        !preg_match('/\ABearer ([A-Za-z0-9_-]+)\.([A-Za-z0-9_-]+)\.([A-Za-z0-9_-]+)\z/', $authorization, $parts)) {
        return null;
    }

    $decode = static function (string $segment) {
        return base64_decode(strtr($segment, '-_', '+/'), true);
    };
    $header = json_decode((string)$decode($parts[1]), true);
    $claims = json_decode((string)$decode($parts[2]), true);
    $signature = $decode($parts[3]);
    if ($header !== ['alg' => 'RS256', 'typ' => 'bbas-admin+jwt'] ||
        !is_array($claims) || $signature === false ||
        ($claims['iss'] ?? null) !== 'attendancebot-admin' ||
        ($claims['aud'] ?? null) !== 'bbas-admin' ||
        ($claims['scope'] ?? null) !== $scope ||
        !is_string($claims['sub'] ?? null) || !preg_match('/\A[1-9][0-9]{0,15}\z/', $claims['sub']) ||
        !is_int($claims['iat'] ?? null) || !is_int($claims['exp'] ?? null) ||
        $claims['iat'] > $now + 5 || $claims['exp'] <= $now ||
        $claims['exp'] <= $claims['iat'] || $claims['exp'] - $claims['iat'] > 30 ||
        !in_array($claims['sub'], array_map('trim', explode(',', $subjects)), true)) {
        return null;
    }

    $key = openssl_pkey_get_public($publicKey);
    if ($key === false) {
        return null;
    }
    $details = openssl_pkey_get_details($key);
    return $details && $details['type'] === OPENSSL_KEYTYPE_RSA && $details['bits'] >= 2048 &&
        openssl_verify($parts[1] . '.' . $parts[2], $signature, $key, OPENSSL_ALGO_SHA256) === 1
        ? $claims : null;
}

function bbas_unified_dashboard_authorized(string $authorization, string $publicKey, string $subjects, int $now): bool
{
    return bbas_unified_admin_claims($authorization, $publicKey, $subjects, $now) !== null;
}
