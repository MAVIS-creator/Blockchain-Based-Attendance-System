<?php

require_once __DIR__ . '/storage_helpers.php';
require_once __DIR__ . '/client_ip.php';
require_once __DIR__ . '/src/AtomicWindowLimiter.php';

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
  function request_guard_client_ip() { return attendance_client_ip(); }
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

if (!function_exists('request_guard_monitor_log_event')) {
  function request_guard_monitor_log_event(array $event)
  {
    $file = app_storage_file('logs/request_guard_monitor.log');
    $dir = dirname($file);
    if (!is_dir($dir)) {
      @mkdir($dir, 0775, true);
    }
    $event['time'] = isset($event['time']) ? $event['time'] : gmdate('c');
    @file_put_contents($file, json_encode($event, JSON_UNESCAPED_SLASHES) . PHP_EOL, FILE_APPEND | LOCK_EX);
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

    $publicLimit = request_guard_env_int('REQUEST_GUARD_PUBLIC_PER_WINDOW', 2400, 5, 20000);
    $adminLimit = request_guard_env_int('REQUEST_GUARD_ADMIN_PER_WINDOW', 300, 5, 10000);
    $loginLimit = request_guard_env_int('REQUEST_GUARD_LOGIN_PER_WINDOW', 25, 3, 500);

    $publicTimeout = request_guard_env_int('REQUEST_GUARD_PUBLIC_TIMEOUT_SECONDS', 25, 5, 300);
    $adminTimeout = request_guard_env_int('REQUEST_GUARD_ADMIN_TIMEOUT_SECONDS', 45, 5, 600);
    $burstWindowSec = request_guard_env_int('REQUEST_GUARD_BURST_WINDOW_SECONDS', 10, 2, 300);
    $burstLimit = request_guard_env_int('REQUEST_GUARD_BURST_PER_WINDOW', 900, 5, 20000);
    $burstBlockSec = request_guard_env_int('REQUEST_GUARD_BURST_BLOCK_SECONDS', 600, 10, 86400);
    $monitorSampleEvery = request_guard_env_int('REQUEST_GUARD_MONITOR_SAMPLE_EVERY', 10, 1, 1000);

    $route = strtolower(trim((string)$route));
    $isLoginRoute = strpos($route, 'admin/login.php') !== false;

    // Campus Wi-Fi can put hundreds of students behind one public IP.
    // High-volume read endpoints are protected separately; admin/login
    // retains strict limits. The origin must also be protected upstream.
    $isSharedRead = $scope === 'public' && in_array($route, [
      'index.php', 'status_api.php', 'get_announcement.php',
      'attendance_closed.php', 'closed.php'
    ], true);
    $limit = $scope === 'admin' ? $adminLimit : $publicLimit;
    if ($isSharedRead) {
      $limit = request_guard_env_int('REQUEST_GUARD_SHARED_READ_PER_WINDOW', 12000, 50, 100000);
      $burstLimit = request_guard_env_int('REQUEST_GUARD_SHARED_READ_BURST_PER_WINDOW', 3000, 20, 50000);
    }
    if ($isLoginRoute) {
      $limit = min($limit, $loginLimit);
      $burstLimit = min($burstLimit, request_guard_env_int('REQUEST_GUARD_LOGIN_BURST_PER_WINDOW', 12, 2, 100));
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

    try {
      $state = request_guard_tick($bucketFile, [
        'window_started' => $now, 'count' => 0, 'blocked_until' => 0,
        'ip' => $ip, 'route' => $route, 'scope' => $scope,
      ], $now, $windowSec);
      $burstFile = app_storage_file('security/request_guard_burst/' . sha1($scope . '|' . $route . '|' . $ip) . '.json');
      $burstState = request_guard_tick($burstFile, [
        'window_started' => $now, 'count' => 0, 'last_route' => $route, 'ip' => $ip,
      ], $now, $burstWindowSec);
    } catch (Throwable $e) {
      error_log('Request guard storage failure: ' . $e->getMessage());
      http_response_code(503);
      exit('Service temporarily unavailable.');
    }
    $blockedUntil = (int)($state['blocked_until'] ?? 0);
    if ($blockedUntil > $now) {
      request_guard_reject($blockedUntil - $now, [
        'ok' => false, 'error' => 'rate_limited',
        'message' => 'Too many requests. Try again shortly.',
        'retry_after' => $blockedUntil - $now,
      ]);
    }
    $burstCount = (int)$burstState['count'];
    $burstTriggered = $burstCount > $burstLimit;
    if ($burstTriggered) {
      request_guard_block($bucketFile, $now + $burstBlockSec);
      $state['blocked_until'] = $now + $burstBlockSec;
    }

    $nearLimit = $state['count'] >= (int)ceil($limit * 0.8);
    $shouldSample = ($state['count'] === 1) || (($state['count'] % max(1, $monitorSampleEvery)) === 0) || $nearLimit || $burstTriggered;
    if ($shouldSample) {
      request_guard_monitor_log_event([
        'event' => $burstTriggered ? 'burst_detected' : 'request_sample',
        'ip' => $ip,
        'route' => $route,
        'scope' => $scope,
        'count' => (int)$state['count'],
        'limit' => (int)$limit,
        'burst_count' => (int)$burstCount,
        'burst_limit' => (int)$burstLimit,
        'burst_window_seconds' => (int)$burstWindowSec,
        'blocked_until' => (int)($state['blocked_until'] ?? 0),
        'user_agent' => $ua,
      ]);
    }

    if ($burstTriggered) {
      
      request_guard_log(json_encode([
        'time' => gmdate('c'),
        'event' => 'burst_blocked',
        'ip' => $ip,
        'route' => $route,
        'scope' => $scope,
        'count' => (int)$state['count'],
        'limit' => (int)$limit,
        'burst_count' => (int)$burstCount,
        'burst_limit' => (int)$burstLimit,
        'burst_window_seconds' => (int)$burstWindowSec,
        'block_seconds' => (int)$burstBlockSec,
        'user_agent' => $ua,
      ], JSON_UNESCAPED_SLASHES));

      request_guard_reject($burstBlockSec, [
        'ok' => false,
        'error' => 'burst_limited',
        'message' => 'Request burst detected. Please slow down and try again later.',
        'retry_after' => (int)$burstBlockSec,
      ]);
    }

    if ($state['count'] > $limit) {
      $state['blocked_until'] = $now + $blockSec;
      request_guard_block($bucketFile, $state['blocked_until']);

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

    
  }
}
