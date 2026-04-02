<?php

require_once __DIR__ . '/storage_helpers.php';

if (!function_exists('request_timing_enabled')) {
  function request_timing_enabled()
  {
    $flag = getenv('APP_TIMING_ENABLED');
    if ($flag === false || $flag === '') {
      return true;
    }
    $flag = strtolower(trim((string)$flag));
    return in_array($flag, ['1', 'true', 'yes', 'on'], true);
  }
}

if (!function_exists('request_timing_start')) {
  function request_timing_start($route, array $meta = [])
  {
    static $initialized = false;
    if (!request_timing_enabled()) {
      return null;
    }

    app_storage_init();

    $ctx = [
      'route' => (string)$route,
      'started_at' => microtime(true),
      'method' => $_SERVER['REQUEST_METHOD'] ?? 'CLI',
      'uri' => $_SERVER['REQUEST_URI'] ?? (string)$route,
      'meta' => $meta,
      'spans' => [],
    ];

    $GLOBALS['__request_timing_ctx'] = $ctx;

    if (!$initialized) {
      $initialized = true;
      register_shutdown_function('request_timing_flush');
    }

    return $ctx['started_at'];
  }
}

if (!function_exists('request_timing_span')) {
  function request_timing_span($name, $startedAt, array $meta = [])
  {
    if (!request_timing_enabled() || !isset($GLOBALS['__request_timing_ctx']) || $startedAt === null) {
      return;
    }

    $durationMs = round((microtime(true) - (float)$startedAt) * 1000, 2);
    $GLOBALS['__request_timing_ctx']['spans'][] = [
      'name' => (string)$name,
      'duration_ms' => $durationMs,
      'meta' => $meta,
    ];
  }
}

if (!function_exists('request_timing_note')) {
  function request_timing_note($key, $value)
  {
    if (!request_timing_enabled() || !isset($GLOBALS['__request_timing_ctx'])) {
      return;
    }
    $GLOBALS['__request_timing_ctx']['meta'][(string)$key] = $value;
  }
}

if (!function_exists('request_timing_flush')) {
  function request_timing_flush()
  {
    if (!request_timing_enabled() || !isset($GLOBALS['__request_timing_ctx'])) {
      return;
    }

    $ctx = $GLOBALS['__request_timing_ctx'];
    unset($GLOBALS['__request_timing_ctx']);

    $ctx['finished_at'] = date('c');
    $ctx['duration_ms'] = round((microtime(true) - (float)$ctx['started_at']) * 1000, 2);
    $ctx['memory_peak_mb'] = round(memory_get_peak_usage(true) / 1048576, 2);
    $ctx['status_code'] = http_response_code();

    $logFile = app_storage_file('logs/request_timing.jsonl');
    @file_put_contents($logFile, json_encode($ctx, JSON_UNESCAPED_SLASHES) . PHP_EOL, FILE_APPEND | LOCK_EX);
  }
}
