<?php

require_once __DIR__ . '/storage_helpers.php';

if (!function_exists('request_timing_hybrid_ready')) {
  function request_timing_hybrid_ready()
  {
    $hybridFile = __DIR__ . '/hybrid_dual_write.php';
    if (!file_exists($hybridFile)) {
      return false;
    }
    require_once $hybridFile;
    return function_exists('hybrid_enabled') && function_exists('hybrid_dual_write');
  }
}

if (!function_exists('request_timing_mirror_supabase')) {
  function request_timing_mirror_supabase(array $ctx)
  {
    if (!request_timing_hybrid_ready() || !hybrid_enabled()) {
      return;
    }

    $payload = [
      'route' => (string)($ctx['route'] ?? 'unknown'),
      'started_at_epoch' => (float)($ctx['started_at'] ?? microtime(true)),
      'finished_at' => (string)($ctx['finished_at'] ?? date('c')),
      'duration_ms' => (float)($ctx['duration_ms'] ?? 0),
      'method' => (string)($ctx['method'] ?? ''),
      'uri' => (string)($ctx['uri'] ?? ''),
      'status_code' => isset($ctx['status_code']) ? (int)$ctx['status_code'] : null,
      'memory_peak_mb' => isset($ctx['memory_peak_mb']) ? (float)$ctx['memory_peak_mb'] : null,
      'meta' => isset($ctx['meta']) && is_array($ctx['meta']) ? $ctx['meta'] : [],
      'spans' => isset($ctx['spans']) && is_array($ctx['spans']) ? $ctx['spans'] : [],
    ];

    hybrid_dual_write('request_timing', 'request_timings', $payload);
  }
}

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

if (!function_exists('request_timing_sample_rate')) {
  function request_timing_sample_rate()
  {
    $raw = getenv('APP_TIMING_SAMPLE_RATE');
    if ($raw === false || $raw === '') {
      return 0.05; // Default: keep only 5% of normal requests.
    }
    $rate = (float)$raw;
    if (!is_finite($rate)) {
      return 0.05;
    }
    if ($rate < 0) $rate = 0;
    if ($rate > 1) $rate = 1;
    return $rate;
  }
}

if (!function_exists('request_timing_slow_ms_threshold')) {
  function request_timing_slow_ms_threshold()
  {
    $raw = getenv('APP_TIMING_SLOW_MS');
    if ($raw === false || $raw === '') {
      return 1200.0; // Always keep slow requests (>= 1.2s) by default.
    }
    $ms = (float)$raw;
    if (!is_finite($ms) || $ms < 0) {
      return 1200.0;
    }
    return $ms;
  }
}

if (!function_exists('request_timing_max_spans')) {
  function request_timing_max_spans()
  {
    $raw = getenv('APP_TIMING_MAX_SPANS');
    if ($raw === false || $raw === '') {
      return 10;
    }
    $value = (int)$raw;
    if ($value < 1) {
      return 1;
    }
    if ($value > 200) {
      return 200;
    }
    return $value;
  }
}

if (!function_exists('request_timing_should_keep_errors')) {
  function request_timing_should_keep_errors()
  {
    $raw = getenv('APP_TIMING_KEEP_ERRORS');
    if ($raw === false || $raw === '') {
      return true;
    }
    $raw = strtolower(trim((string)$raw));
    return in_array($raw, ['1', 'true', 'yes', 'on'], true);
  }
}

if (!function_exists('request_timing_should_sample')) {
  function request_timing_should_sample($rate)
  {
    if ($rate >= 1) return true;
    if ($rate <= 0) return false;
    try {
      $bucket = random_int(1, 10000);
    } catch (Exception $e) {
      $bucket = mt_rand(1, 10000);
    }
    return $bucket <= (int)round($rate * 10000);
  }
}

if (!function_exists('request_timing_start')) {
  function request_timing_start($route, array $meta = [])
  {
    static $initialized = false;
    if (!request_timing_enabled()) {
      return null;
    }

    if (isset($GLOBALS['__request_timing_ctx']) && is_array($GLOBALS['__request_timing_ctx'])) {
      if ((string)$route !== '') {
        $GLOBALS['__request_timing_ctx']['route'] = (string)$route;
      }
      if (!empty($meta)) {
        $existingMeta = $GLOBALS['__request_timing_ctx']['meta'] ?? [];
        if (!is_array($existingMeta)) {
          $existingMeta = [];
        }
        $GLOBALS['__request_timing_ctx']['meta'] = array_merge($existingMeta, $meta);
      }
      return $GLOBALS['__request_timing_ctx']['started_at'] ?? microtime(true);
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

if (!function_exists('request_timing_auto_start')) {
  function request_timing_auto_start(array $meta = [])
  {
    if (!request_timing_enabled()) {
      return null;
    }

    if (PHP_SAPI === 'cli' || PHP_SAPI === 'phpdbg') {
      return null;
    }

    $script = (string)($_SERVER['SCRIPT_NAME'] ?? $_SERVER['PHP_SELF'] ?? 'unknown.php');
    $route = ltrim(str_replace('\\', '/', $script), '/');
    if ($route === '') {
      $route = 'unknown.php';
    }

    $defaultMeta = [
      'auto_timing' => true,
      'script' => $route,
    ];

    return request_timing_start($route, array_merge($defaultMeta, $meta));
  }
}

if (!function_exists('request_timing_span')) {
  function request_timing_span($name, $startedAt, array $meta = [])
  {
    if (!request_timing_enabled() || !isset($GLOBALS['__request_timing_ctx']) || $startedAt === null) {
      return;
    }

    $maxSpans = request_timing_max_spans();
    $existingSpans = $GLOBALS['__request_timing_ctx']['spans'] ?? [];
    if (count($existingSpans) >= $maxSpans) {
      $dropped = (int)($GLOBALS['__request_timing_ctx']['spans_dropped'] ?? 0);
      $GLOBALS['__request_timing_ctx']['spans_dropped'] = $dropped + 1;
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

    $statusCode = (int)$ctx['status_code'];
    $durationMs = (float)$ctx['duration_ms'];
    $slowMsThreshold = request_timing_slow_ms_threshold();
    $keepErrors = request_timing_should_keep_errors();
    $sampleRate = request_timing_sample_rate();

    $isError = $statusCode >= 400;
    $isSlow = $durationMs >= $slowMsThreshold;
    $isSampled = request_timing_should_sample($sampleRate);

    if (!$isError && !$isSlow && !$isSampled) {
      return;
    }

    if ($isError && !$keepErrors) {
      // Respect explicit config to skip error logging as well.
      return;
    }

    if (!isset($ctx['meta']) || !is_array($ctx['meta'])) {
      $ctx['meta'] = [];
    }
    $ctx['meta']['timing_policy'] = [
      'sample_rate' => $sampleRate,
      'slow_ms' => $slowMsThreshold,
      'kept_because' => $isError ? 'error' : ($isSlow ? 'slow' : 'sampled')
    ];

    if (!empty($ctx['spans_dropped'])) {
      $ctx['meta']['spans_dropped'] = (int)$ctx['spans_dropped'];
      unset($ctx['spans_dropped']);
    }

    $logFile = app_storage_file('logs/request_timing.jsonl');
    @file_put_contents($logFile, json_encode($ctx, JSON_UNESCAPED_SLASHES) . PHP_EOL, FILE_APPEND | LOCK_EX);
    request_timing_mirror_supabase($ctx);
  }
}
