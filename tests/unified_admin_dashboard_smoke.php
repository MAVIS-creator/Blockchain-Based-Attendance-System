<?php
declare(strict_types=1);
require_once __DIR__ . '/../src/UnifiedAdminDashboardAuth.php';

$key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
if ($key === false) {
    throw new RuntimeException('Could not generate the ephemeral test signing key');
}
$public = openssl_pkey_get_details($key)['key'];
$now = 1795778500;
$claims = ['iss' => 'attendancebot-admin', 'aud' => 'bbas-admin', 'sub' => '24',
    'scope' => 'dashboard:read', 'iat' => $now, 'exp' => $now + 25];

$encode = static function (string $value): string {
    return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
};
$sign = static function (array $payload) use ($encode, $key): string {
    $input = $encode(json_encode(['alg' => 'RS256', 'typ' => 'bbas-admin+jwt'])) . '.' .
        $encode(json_encode($payload));
    openssl_sign($input, $signature, $key, OPENSSL_ALGO_SHA256);
    return 'Bearer ' . $input . '.' . $encode($signature);
};
$token = $sign($claims);
if (!bbas_unified_dashboard_authorized($token, $public, '17, 24', $now)) {
    throw new RuntimeException('An approved administrator was denied');
}
$denied = [
    [$token, '17', $now],
    [$token, '24', $now + 25],
    [$sign(['aud' => 'attendancebot'] + $claims), '24', $now],
    [$sign(['scope' => 'attendance:write'] + $claims), '24', $now],
    [$sign(['exp' => $now + 300] + $claims), '24', $now],
    [$sign(['iat' => $now + 10] + $claims), '24', $now],
    ['Bearer nope', '24', $now],
];
foreach ($denied as [$authorization, $subjects, $time]) {
    if (bbas_unified_dashboard_authorized($authorization, $public, $subjects, $time)) {
        throw new RuntimeException('An unauthorized request was accepted');
    }
}
echo "Unified admin dashboard credential checks passed\n";
