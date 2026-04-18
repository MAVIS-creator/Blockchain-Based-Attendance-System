<?php
require_once __DIR__ . '/session_bootstrap.php';
if (empty($_SESSION['admin_logged_in'])) {
  http_response_code(401);
  header('Content-Type: application/json');
  echo json_encode(['ok' => false, 'error' => 'unauthorized']);
  exit;
}

require_once __DIR__ . '/../storage_helpers.php';
require_once __DIR__ . '/runtime_storage.php';
require_once __DIR__ . '/cache_helpers.php';
app_storage_init();

$hours = isset($_GET['hours']) ? (int)$_GET['hours'] : 24;
if ($hours < 1) $hours = 1;
if ($hours > 168) $hours = 168;
$sinceTs = time() - ($hours * 3600);

$monitorFile = app_storage_file('logs/request_guard_monitor.log');
$blockedFile = app_storage_file('logs/request_guard.log');
$bucketPattern = app_storage_file('security/request_guard/*.json');

$readRecentJsonLines = static function ($cacheKey, $filePath, $sinceEpoch, $maxLines = 20000) {
  if (!file_exists($filePath)) {
    return [];
  }
  $lines = admin_cached_file_lines($cacheKey, $filePath, 3);
  if (!is_array($lines)) {
    return [];
  }

  if (count($lines) > $maxLines) {
    $lines = array_slice($lines, -$maxLines);
  }

  $rows = [];
  foreach ($lines as $line) {
    $decoded = json_decode((string)$line, true);
    if (!is_array($decoded)) {
      continue;
    }
    $eventTs = isset($decoded['time']) ? strtotime((string)$decoded['time']) : 0;
    if ($eventTs <= 0 || $eventTs < $sinceEpoch) {
      continue;
    }
    $decoded['_event_ts'] = $eventTs;
    $rows[] = $decoded;
  }
  return $rows;
};

$monitorRows = $readRecentJsonLines('request_guard_monitor_lines', $monitorFile, $sinceTs, 30000);
$blockedRows = $readRecentJsonLines('request_guard_blocked_lines', $blockedFile, $sinceTs, 30000);

$ipStats = [];
$routeStats = [];
$ipRouteStats = [];

$accumulate = static function (&$ipStats, &$routeStats, &$ipRouteStats, $row, $type) {
  $ip = trim((string)($row['ip'] ?? 'unknown'));
  $route = trim((string)($row['route'] ?? 'unknown'));
  $eventTs = (int)($row['_event_ts'] ?? time());
  $count = isset($row['count']) ? (int)$row['count'] : 0;
  $limit = isset($row['limit']) ? (int)$row['limit'] : 0;

  if (!isset($ipStats[$ip])) {
    $ipStats[$ip] = [
      'ip' => $ip,
      'events' => 0,
      'request_samples' => 0,
      'burst_events' => 0,
      'blocked_events' => 0,
      'max_count_seen' => 0,
      'max_limit_seen' => 0,
      'routes' => [],
      'last_seen' => 0,
      'score' => 0,
    ];
  }

  $ipStats[$ip]['events']++;
  $ipStats[$ip]['last_seen'] = max((int)$ipStats[$ip]['last_seen'], $eventTs);
  $ipStats[$ip]['max_count_seen'] = max((int)$ipStats[$ip]['max_count_seen'], $count);
  $ipStats[$ip]['max_limit_seen'] = max((int)$ipStats[$ip]['max_limit_seen'], $limit);
  $ipStats[$ip]['routes'][$route] = true;

  if ($type === 'sample') {
    $ipStats[$ip]['request_samples']++;
    $ipStats[$ip]['score'] += 1;
  } elseif ($type === 'burst') {
    $ipStats[$ip]['burst_events']++;
    $ipStats[$ip]['score'] += 3;
  } elseif ($type === 'blocked') {
    $ipStats[$ip]['blocked_events']++;
    $ipStats[$ip]['score'] += 5;
  }

  if (!isset($routeStats[$route])) {
    $routeStats[$route] = [
      'route' => $route,
      'events' => 0,
      'blocked_events' => 0,
      'burst_events' => 0,
      'last_seen' => 0,
    ];
  }
  $routeStats[$route]['events']++;
  $routeStats[$route]['last_seen'] = max((int)$routeStats[$route]['last_seen'], $eventTs);
  if ($type === 'blocked') {
    $routeStats[$route]['blocked_events']++;
  }
  if ($type === 'burst') {
    $routeStats[$route]['burst_events']++;
  }

  $pairKey = $ip . '|' . $route;
  if (!isset($ipRouteStats[$pairKey])) {
    $ipRouteStats[$pairKey] = [
      'ip' => $ip,
      'route' => $route,
      'events' => 0,
      'blocked_events' => 0,
      'burst_events' => 0,
      'last_seen' => 0,
    ];
  }
  $ipRouteStats[$pairKey]['events']++;
  $ipRouteStats[$pairKey]['last_seen'] = max((int)$ipRouteStats[$pairKey]['last_seen'], $eventTs);
  if ($type === 'blocked') {
    $ipRouteStats[$pairKey]['blocked_events']++;
  }
  if ($type === 'burst') {
    $ipRouteStats[$pairKey]['burst_events']++;
  }
};

foreach ($monitorRows as $row) {
  $event = (string)($row['event'] ?? 'request_sample');
  $type = ($event === 'burst_detected') ? 'burst' : 'sample';
  $accumulate($ipStats, $routeStats, $ipRouteStats, $row, $type);
}

foreach ($blockedRows as $row) {
  $event = (string)($row['event'] ?? 'blocked');
  $type = ($event === 'burst_blocked') ? 'burst' : 'blocked';
  $accumulate($ipStats, $routeStats, $ipRouteStats, $row, $type);
}

$ipRows = array_values($ipStats);
foreach ($ipRows as &$ipRow) {
  $ipRow['route_count'] = count($ipRow['routes']);
  $ipRow['routes'] = array_values(array_keys($ipRow['routes']));
}
unset($ipRow);

usort($ipRows, static function ($a, $b) {
  $scoreCmp = ((int)$b['score']) <=> ((int)$a['score']);
  if ($scoreCmp !== 0) return $scoreCmp;
  $blockedCmp = ((int)$b['blocked_events']) <=> ((int)$a['blocked_events']);
  if ($blockedCmp !== 0) return $blockedCmp;
  return ((int)$b['events']) <=> ((int)$a['events']);
});

$routeRows = array_values($routeStats);
usort($routeRows, static function ($a, $b) {
  $blockedCmp = ((int)$b['blocked_events']) <=> ((int)$a['blocked_events']);
  if ($blockedCmp !== 0) return $blockedCmp;
  return ((int)$b['events']) <=> ((int)$a['events']);
});

$pairRows = array_values($ipRouteStats);
usort($pairRows, static function ($a, $b) {
  $blockedCmp = ((int)$b['blocked_events']) <=> ((int)$a['blocked_events']);
  if ($blockedCmp !== 0) return $blockedCmp;
  return ((int)$b['events']) <=> ((int)$a['events']);
});

$currentBlocks = [];
foreach (glob($bucketPattern) ?: [] as $bucketFile) {
  $decoded = json_decode((string)@file_get_contents($bucketFile), true);
  if (!is_array($decoded)) {
    continue;
  }
  $blockedUntil = isset($decoded['blocked_until']) ? (int)$decoded['blocked_until'] : 0;
  if ($blockedUntil <= time()) {
    continue;
  }
  $currentBlocks[] = [
    'ip' => (string)($decoded['ip'] ?? 'unknown'),
    'route' => (string)($decoded['route'] ?? 'unknown'),
    'scope' => (string)($decoded['scope'] ?? 'public'),
    'count' => (int)($decoded['count'] ?? 0),
    'blocked_until' => $blockedUntil,
    'retry_after' => max(0, $blockedUntil - time()),
  ];
}

usort($currentBlocks, static function ($a, $b) {
  return ((int)$b['retry_after']) <=> ((int)$a['retry_after']);
});

header('Content-Type: application/json');
echo json_encode([
  'ok' => true,
  'window_hours' => $hours,
  'since_epoch' => $sinceTs,
  'monitor_events' => count($monitorRows),
  'blocked_events' => count($blockedRows),
  'top_ips' => array_slice($ipRows, 0, 30),
  'top_routes' => array_slice($routeRows, 0, 30),
  'top_ip_routes' => array_slice($pairRows, 0, 40),
  'active_blocks' => $currentBlocks,
], JSON_UNESCAPED_SLASHES);
