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
      return 0.0; // Default minimal mode: do not sample normal requests.
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

if (!function_exists('request_timing_critical_ms_threshold')) {
  function request_timing_critical_ms_threshold()
  {
    $raw = getenv('APP_TIMING_CRITICAL_MS');
    if ($raw === false || $raw === '') {
      return 3000.0; // Keep very slow outlier routes even when not allowlisted.
    }
    $ms = (float)$raw;
    if (!is_finite($ms) || $ms < 0) {
      return 3000.0;
    }
    return $ms;
  }
}

if (!function_exists('request_timing_route_allowlist')) {
  function request_timing_route_allowlist()
  {
    $raw = getenv('APP_TIMING_ROUTES');
    if ($raw === false || trim((string)$raw) === '') {
      // High-value/lag-prone routes by default.
      return [
        'submit.php',
        'support.php',
        'status_api.php',
        'ticket_status_api.php',
        'admin/request_timings.php',
        'admin/logs/logs.php',
        'admin/view_tickets.php',
        'admin/clear_logs.php',
        'admin/restore_logs.php',
      ];
    }

    $parts = array_filter(array_map('trim', explode(',', (string)$raw)), function ($v) {
      return $v !== '';
    });

    return array_values($parts);
  }
}

if (!function_exists('request_timing_route_matches_allowlist')) {
  function request_timing_route_matches_allowlist($route, array $allowlist)
  {
    $route = strtolower(str_replace('\\', '/', (string)$route));
    if ($route === '') return false;

    foreach ($allowlist as $ruleRaw) {
      $rule = strtolower(str_replace('\\', '/', trim((string)$ruleRaw)));
      if ($rule === '') continue;
      if (substr($rule, -1) === '*') {
        $prefix = substr($rule, 0, -1);
        if ($prefix !== '' && strpos($route, $prefix) === 0) {
          return true;
        }
        continue;
      }

      if ($route === $rule || substr($route, -strlen($rule)) === $rule) {
        return true;
      }
    }

    return false;
  }
}

if (!function_exists('request_timing_span_totals')) {
  function request_timing_span_totals(array $spans)
  {
    $totals = [
      'db_ms' => 0.0,
      'app_ms' => 0.0,
      'other_ms' => 0.0,
      'top_span_name' => '',
      'top_span_ms' => 0.0,
    ];

    foreach ($spans as $span) {
      if (!is_array($span)) continue;
      $name = strtolower((string)($span['name'] ?? ''));
      $ms = (float)($span['duration_ms'] ?? 0);
      if ($ms <= 0) continue;

      if ($ms > $totals['top_span_ms']) {
        $totals['top_span_ms'] = $ms;
        $totals['top_span_name'] = (string)($span['name'] ?? '');
      }

      if (preg_match('/(hybrid|supabase|db|sql|query|select|insert|update|delete|fetch)/i', $name)) {
        $totals['db_ms'] += $ms;
      } elseif (preg_match('/(append|write|save|chain|render|template|auth|session|php|app|logic|validation|geofence|ticket|support|attendance)/i', $name)) {
        $totals['app_ms'] += $ms;
      } else {
        $totals['other_ms'] += $ms;
      }
    }

    return $totals;
  }
}

if (!function_exists('request_timing_build_diagnostics')) {
  function request_timing_build_diagnostics(array $ctx)
  {
    $durationMs = (float)($ctx['duration_ms'] ?? 0);
    $statusCode = (int)($ctx['status_code'] ?? 0);
    $spans = isset($ctx['spans']) && is_array($ctx['spans']) ? $ctx['spans'] : [];
    $totals = request_timing_span_totals($spans);

    $dbMs = (float)$totals['db_ms'];
    $appMs = (float)$totals['app_ms'];
    $otherMs = (float)$totals['other_ms'];
    $knownSpanMs = $dbMs + $appMs + $otherMs;
    $unattributedMs = max(0.0, $durationMs - $knownSpanMs);
    $dbShare = $durationMs > 0 ? round($dbMs / $durationMs, 3) : 0.0;
    $appShare = $durationMs > 0 ? round($appMs / $durationMs, 3) : 0.0;

    $likelyLayer = 'app_server_azure';
    $confidence = 0.55;
    $reason = 'Processing appears dominated by application runtime operations.';

    if ($dbMs >= 120.0 && $dbShare >= 0.40) {
      $likelyLayer = 'database_supabase';
      $confidence = min(0.95, 0.55 + $dbShare);
      $reason = 'Database-related spans dominate measured request time.';
    } elseif ($statusCode >= 500) {
      $likelyLayer = 'app_server_azure';
      $confidence = 0.9;
      $reason = 'HTTP 5xx indicates server-side processing failure/latency.';
    } elseif ($durationMs >= 2500 && $unattributedMs >= max(300.0, $durationMs * 0.50)) {
      $likelyLayer = 'network_or_edge_uncertain';
      $confidence = 0.45;
      $reason = 'Large unattributed delay; check Cloudflare/DNS/edge and upstream network path.';
    }

    return [
      'likely_layer' => $likelyLayer,
      'confidence' => round($confidence, 2),
      'reason' => $reason,
      'breakdown_ms' => [
        'db_supabase' => round($dbMs, 2),
        'app_server' => round($appMs, 2),
        'other_spans' => round($otherMs, 2),
        'unattributed' => round($unattributedMs, 2),
      ],
      'breakdown_share' => [
        'db_supabase' => $dbShare,
        'app_server' => $appShare,
      ],
      'top_span' => [
        'name' => (string)$totals['top_span_name'],
        'duration_ms' => round((float)$totals['top_span_ms'], 2),
      ],
      'notes' => [
        'dns_domain_visibility' => 'php_runtime_cannot_directly_measure_dns_lookup_or_browser_connect_time',
        'edge_hint' => isset($_SERVER['HTTP_CF_RAY']) ? 'cloudflare_edge_present' : 'no_cloudflare_header_seen',
      ],
      'checks' => [
        'supabase' => ['slow queries', 'row locks', 'RLS/policy overhead', 'network RTT to Supabase region'],
        'azure_app_service' => ['CPU/memory pressure', 'cold start/restart', 'PHP-FPM saturation', 'filesystem I/O'],
        'domain_dns_cloudflare' => ['DNS resolution latency', 'Cloudflare edge health', 'origin reachability', 'TLS handshake latency'],
      ],
    ];
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
      'meta' => array_merge([
        'host' => (string)($_SERVER['HTTP_HOST'] ?? ''),
        'edge_cf_ray' => (string)($_SERVER['HTTP_CF_RAY'] ?? ''),
        'edge_cf_connecting_ip' => (string)($_SERVER['HTTP_CF_CONNECTING_IP'] ?? ''),
        'forwarded_for' => (string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ''),
      ], $meta),
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
    $criticalMsThreshold = request_timing_critical_ms_threshold();
    $keepErrors = request_timing_should_keep_errors();
    $sampleRate = request_timing_sample_rate();
    $allowlist = request_timing_route_allowlist();
    $routeTracked = request_timing_route_matches_allowlist((string)($ctx['route'] ?? ''), $allowlist);

    $isError = $statusCode >= 400;
    $isSlow = $durationMs >= $slowMsThreshold;
    $isCritical = $durationMs >= $criticalMsThreshold;
    $isSampled = request_timing_should_sample($sampleRate);

    $keepBecause = [];
    if ($isError) $keepBecause[] = 'error';
    if ($isSlow) $keepBecause[] = 'slow';
    if ($isSampled) $keepBecause[] = 'sampled';
    if ($isCritical && !$routeTracked) $keepBecause[] = 'critical_non_allowlisted';

    // Minimal policy:
    // - allowlisted routes: keep only error/slow/sampled
    // - non-allowlisted routes: keep only error OR critical slowness
    $shouldKeep = false;
    if ($routeTracked) {
      $shouldKeep = $isError || $isSlow || $isSampled;
    } else {
      $shouldKeep = $isError || $isCritical;
    }

    if (!$shouldKeep) {
      return;
    }

    if ($isError && !$keepErrors) {
      // Respect explicit config to skip error logging as well.
      return;
    }

    if (!isset($ctx['meta']) || !is_array($ctx['meta'])) {
      $ctx['meta'] = [];
    }

    $ctx['meta']['route_tracked'] = $routeTracked;
    $ctx['meta']['allowlist_count'] = count($allowlist);
    $ctx['meta']['critical_ms'] = $criticalMsThreshold;
    $ctx['meta']['timing_policy'] = [
      'sample_rate' => $sampleRate,
      'slow_ms' => $slowMsThreshold,
      'kept_because' => !empty($keepBecause) ? implode(',', $keepBecause) : 'unknown',
    ];
    $ctx['meta']['diagnostics'] = request_timing_build_diagnostics($ctx);

    if (!empty($ctx['spans_dropped'])) {
      $ctx['meta']['spans_dropped'] = (int)$ctx['spans_dropped'];
      unset($ctx['spans_dropped']);
    }

    $logFile = app_storage_file('logs/request_timing.jsonl');
    @file_put_contents($logFile, json_encode($ctx, JSON_UNESCAPED_SLASHES) . PHP_EOL, FILE_APPEND | LOCK_EX);
    request_timing_mirror_supabase($ctx);
  }
}
