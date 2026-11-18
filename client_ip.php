<?php
/** Resolve client IPs only through explicitly trusted reverse proxies. */
require_once __DIR__ . '/env_helpers.php';

if (!function_exists('attendance_ip_in_cidr')) {
  function attendance_ip_in_cidr($ip, $cidr) {
    $ip = trim((string)$ip); $cidr = trim((string)$cidr);
    if (!filter_var($ip, FILTER_VALIDATE_IP) || $cidr === '') return false;
    $parts = explode('/', $cidr, 2);
    $net = @inet_pton(trim($parts[0])); $addr = @inet_pton($ip);
    if ($net === false || $addr === false || strlen($net) !== strlen($addr)) return false;
    $bits = strlen($net) * 8;
    $prefix = count($parts) === 2 ? filter_var($parts[1], FILTER_VALIDATE_INT) : $bits;
    if ($prefix === false || $prefix < 0 || $prefix > $bits) return false;
    $whole = intdiv($prefix, 8); $rest = $prefix % 8;
    if ($whole && substr($net, 0, $whole) !== substr($addr, 0, $whole)) return false;
    if (!$rest) return true;
    $mask = (0xff << (8 - $rest)) & 0xff;
    return (ord($net[$whole]) & $mask) === (ord($addr[$whole]) & $mask);
  }
}

if (!function_exists('attendance_client_ip_from')) {
  function attendance_client_ip_from(array $server, array $trustedCidrs = []) {
    $peer = trim((string)($server['REMOTE_ADDR'] ?? ''));
    if (!filter_var($peer, FILTER_VALIDATE_IP)) return 'unknown';
    $peerTrusted = false;
    foreach ($trustedCidrs as $cidr) {
      if (attendance_ip_in_cidr($peer, $cidr)) { $peerTrusted = true; break; }
    }
    if (!$peerTrusted) return $peer;
    $xff = trim((string)($server['HTTP_X_FORWARDED_FOR'] ?? ''));
    if ($xff === '') return $peer;
    $hops = array_map('trim', explode(',', $xff)); $hops[] = $peer;
    // Walk from the nearest proxy toward the client, ignoring spoofable leftmost entries.
    for ($i = count($hops)-1; $i >= 0; $i--) {
      $ip = $hops[$i];
      if (!filter_var($ip, FILTER_VALIDATE_IP)) return $peer;
      $trusted = false;
      foreach ($trustedCidrs as $cidr) {
        if (attendance_ip_in_cidr($ip, $cidr)) { $trusted = true; break; }
      }
      if (!$trusted) return $ip;
    }
    return $peer;
  }
}

if (!function_exists('attendance_client_ip')) {
  function attendance_client_ip() {
    $raw = trim((string)app_env_value('TRUSTED_PROXY_CIDRS', ''));
    $trusted = $raw !== '' ? array_values(array_filter(array_map('trim', explode(',', $raw)))) : [];
    return attendance_client_ip_from($_SERVER, $trusted);
  }
}
