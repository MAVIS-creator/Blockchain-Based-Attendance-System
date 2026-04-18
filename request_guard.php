<?php

require_once __DIR__ . '/storage_helpers.php';

if (!function_exists('request_guard_env_int')) {
  function request_guard_env_int($key, $default, $min, $max)
  {
    $raw = getenv($key);
    if ($raw === false || $raw === '') {
      return $default;
    }
    $value = (int)$raw;
    if ($value < $min) $value = $min;
    if ($value > $max) $value = $max;
    return $value;
  }
}

if (!function_exists('request_guard_client_ip')) {
  function request_guard_client_ip()
  {
    $cfIp = trim((string)($_SERVER['HTTP_CF_CONNECTING_IP'] ?? ''));
    if ($cfIp !== '') return $cfIp;

    $xff = trim((string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ''));
    if ($xff !== '') {
      $parts = explode(',', $xff);
      $first = trim((string)($parts[0] ?? ''));
      if ($first !== '') return $first;
    }

    return trim((string)($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
  }
}

if (!function_exists('request_guard_is_json_request')) {
  function request_guard_is_json_request()
  {
    $accept = strtolower((string)($_SERVER['HTTP_ACCEPT'] ?? ''));
    $xrw = strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? ''));
    return strpos($accept, 'application/json') !== false || $xrw === 'xmlhttprequest';
  }
}

if (!function_exists('request_guard_reject')) {
  function request_guard_reject($retryAfter, array $payload)
  {
    if (!headers_sent()) {
      http_response_code(429);
      header('Retry-After: ' . max(1, (int)$retryAfter));
    }

    if (!headers_sent() && request_guard_is_json_request()) {
      header('Content-Type: application/json');
    }

    if (request_guard_is_json_request()) {
      echo json_encode($payload);
    } else {
      echo 'Too many requests. Please retry in ' . max(1, (int)$retryAfter) . ' seconds.';
    }
    exit;
  }
}

if (!function_exists('request_guard_log')) {
  function request_guard_log($line)
  {
    $file = app_storage_file('logs/request_guard.log');
    $dir = dirname($file);
    if (!is_dir($dir)) {
      @mkdir($dir, 0775, true);
    }
    @file_put_contents($file, $line . PHP_EOL, FILE_APPEND | LOCK_EX);
  }
}

if (!function_exists('app_request_guard')) {
  function app_request_guard($route, $scope = 'public')
  {
    if (PHP_SAPI === 'cli' || PHP_SAPI === 'phpdbg') {
      return;
    }

    app_storage_init();

    $scope = strtolower(trim((string)$scope));
    if (!in_array($scope, ['public', 'admin', 'all'], true)) {
      $scope = 'public';
    }

    $windowSec = request_guard_env_int('REQUEST_GUARD_WINDOW_SECONDS', 60, 10, 3600);
    $blockSec = request_guard_env_int('REQUEST_GUARD_BLOCK_SECONDS', 180, 5, 86400);

    $publicLimit = request_guard_env_int('REQUEST_GUARD_PUBLIC_PER_WINDOW', 100, 5, 5000);
    $adminLimit = request_guard_env_int('REQUEST_GUARD_ADMIN_PER_WINDOW', 300, 5, 10000);
    $loginLimit = request_guard_env_int('REQUEST_GUARD_LOGIN_PER_WINDOW', 25, 3, 500);

    $publicTimeout = request_guard_env_int('REQUEST_GUARD_PUBLIC_TIMEOUT_SECONDS', 25, 5, 300);
    $adminTimeout = request_guard_env_int('REQUEST_GUARD_ADMIN_TIMEOUT_SECONDS', 45, 5, 600);

    $route = strtolower(trim((string)$route));
    $isLoginRoute = strpos($route, 'admin/login.php') !== false;

    $limit = $scope === 'admin' ? $adminLimit : $publicLimit;
    if ($isLoginRoute) {
      $limit = min($limit, $loginLimit);
    }

    $timeout = $scope === 'admin' ? $adminTimeout : $publicTimeout;
    @ini_set('max_execution_time', (string)$timeout);
    if (function_exists('set_time_limit')) {
      @set_time_limit($timeout);
    }

    $ip = request_guard_client_ip();
    $ua = trim((string)($_SERVER['HTTP_USER_AGENT'] ?? ''));
    $now = time();

    $bucketKey = sha1($scope . '|' . $route . '|' . $ip);
    $bucketFile = app_storage_file('security/request_guard/' . $bucketKey . '.json');
    $bucketDir = dirname($bucketFile);
    if (!is_dir($bucketDir)) {
      @mkdir($bucketDir, 0775, true);
    }

    $state = [
      'window_started' => $now,
      'count' => 0,
      'blocked_until' => 0,
      'ip' => $ip,
      'route' => $route,
      'scope' => $scope,
    ];

    if (file_exists($bucketFile)) {
      $raw = @file_get_contents($bucketFile);
      $decoded = json_decode((string)$raw, true);
      if (is_array($decoded)) {
        $state = array_merge($state, $decoded);
      }
    }

    $blockedUntil = (int)($state['blocked_until'] ?? 0);
    if ($blockedUntil > $now) {
      $retryAfter = $blockedUntil - $now;
      request_guard_reject($retryAfter, [
        'ok' => false,
        'error' => 'rate_limited',
        'message' => 'Too many requests. Try again shortly.',
        'retry_after' => $retryAfter,
      ]);
    }

    $windowStarted = (int)($state['window_started'] ?? $now);
    if (($now - $windowStarted) >= $windowSec) {
      $state['window_started'] = $now;
      $state['count'] = 0;
      $state['blocked_until'] = 0;
    }

    $state['count'] = (int)($state['count'] ?? 0) + 1;

    if ($state['count'] > $limit) {
      $state['blocked_until'] = $now + $blockSec;
      @file_put_contents($bucketFile, json_encode($state, JSON_UNESCAPED_SLASHES), LOCK_EX);

      request_guard_log(json_encode([
        'time' => gmdate('c'),
        'event' => 'blocked',
        'ip' => $ip,
        'route' => $route,
        'scope' => $scope,
        'count' => $state['count'],
        'limit' => $limit,
        'block_seconds' => $blockSec,
        'user_agent' => $ua,
      ], JSON_UNESCAPED_SLASHES));

      request_guard_reject($blockSec, [
        'ok' => false,
        'error' => 'rate_limited',
        'message' => 'Too many requests. Please wait before retrying.',
        'retry_after' => $blockSec,
      ]);
    }

    @file_put_contents($bucketFile, json_encode($state, JSON_UNESCAPED_SLASHES), LOCK_EX);
  }
}
