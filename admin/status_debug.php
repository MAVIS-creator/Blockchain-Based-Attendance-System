<?php
if (session_status() === PHP_SESSION_NONE) session_start();
if (empty($_SESSION['admin_logged_in']) || (($_SESSION['admin_role'] ?? 'admin') !== 'superadmin')) {
  header('Location: login.php');
  exit;
}

require_once __DIR__ . '/runtime_storage.php';
require_once __DIR__ . '/state_helpers.php';

app_storage_init();

function status_debug_normalize_public_status($status)
{
  if (!is_array($status)) {
    return ['checkin' => false, 'checkout' => false, 'end_time' => null];
  }

  $normalized = [
    'checkin' => !empty($status['checkin']),
    'checkout' => !empty($status['checkout']),
    'end_time' => isset($status['end_time']) && is_numeric($status['end_time']) ? (int)$status['end_time'] : null,
  ];

  $active = $normalized['checkin'] || $normalized['checkout'];
  $timerValid = $normalized['end_time'] !== null && $normalized['end_time'] > time();
  if ($active && !$timerValid) {
    $normalized = ['checkin' => false, 'checkout' => false, 'end_time' => null];
  }
  if (!$normalized['checkin'] && !$normalized['checkout']) {
    $normalized['end_time'] = null;
  }

  return $normalized;
}

function status_debug_fetch_public_probe($url)
{
  $context = stream_context_create([
    'http' => [
      'method' => 'GET',
      'timeout' => 5,
      'ignore_errors' => true,
      'header' => "Cache-Control: no-cache\r\nPragma: no-cache\r\nUser-Agent: StatusDiagnostics/1.0\r\n",
    ],
    'ssl' => [
      'verify_peer' => false,
      'verify_peer_name' => false,
    ],
  ]);

  $body = @file_get_contents($url, false, $context);
  $headers = isset($http_response_header) && is_array($http_response_header) ? $http_response_header : [];
  $statusLine = $headers[0] ?? '';
  $statusCode = preg_match('/\s(\d{3})\s/', $statusLine, $matches) ? (int)$matches[1] : null;

  return [
    'ok' => $body !== false,
    'status_code' => $statusCode,
    'headers' => $headers,
    'body' => $body === false ? '' : (string)$body,
    'error' => $body === false ? (error_get_last()['message'] ?? 'Unable to fetch URL.') : '',
  ];
}

$statusFile = admin_status_file();
$rawExists = file_exists($statusFile);
$rawReadable = $rawExists && is_readable($statusFile);
$rawContent = $rawReadable ? (string)@file_get_contents($statusFile) : '';
$decoded = $rawContent !== '' ? json_decode($rawContent, true) : null;
$jsonError = $rawContent !== '' && !is_array($decoded) ? json_last_error_msg() : '';
$normalized = admin_load_status_cached(0);
$publicNormalized = status_debug_normalize_public_status($decoded);

$rawCheckin = is_array($decoded) ? ($decoded['checkin'] ?? null) : null;
$rawCheckout = is_array($decoded) ? ($decoded['checkout'] ?? null) : null;
$rawEndTime = is_array($decoded) ? ($decoded['end_time'] ?? null) : null;

$statusDir = dirname($statusFile);
$statusDirExists = is_dir($statusDir);
$statusDirWritable = $statusDirExists && is_writable($statusDir);
$statusFileWritable = $rawExists ? is_writable($statusFile) : $statusDirWritable;

$writeProbeOk = false;
$writeProbeError = '';
$writeProbeReadBackMatches = false;
$writeProbeFile = $statusDir . DIRECTORY_SEPARATOR . '.status_probe_' . substr(sha1((string)microtime(true)), 0, 10) . '.tmp';

if ($statusDirExists && $statusDirWritable) {
  $probePayload = json_encode([
    'probe' => 'status_debug',
    'time' => time(),
    'pid' => getmypid(),
  ]);

  $written = @file_put_contents($writeProbeFile, $probePayload, LOCK_EX);
  if ($written === false) {
    $writeProbeError = (string)(error_get_last()['message'] ?? 'Unable to write temp probe file.');
  } else {
    $writeProbeOk = true;
    $readBack = (string)@file_get_contents($writeProbeFile);
    $writeProbeReadBackMatches = ($readBack === (string)$probePayload);
    if (!$writeProbeReadBackMatches) {
      $writeProbeError = 'Probe write succeeded, but read-back content mismatch.';
    }
    if (!@unlink($writeProbeFile) && $writeProbeError === '') {
      $writeProbeError = (string)(error_get_last()['message'] ?? 'Probe file cleanup failed.');
    }
  }
} else {
  $writeProbeError = 'Storage directory is missing or not writable.';
}

$currentTime = time();
$activeModeConfigured = !empty($normalized['checkin']) || !empty($normalized['checkout']);
$timerValid = isset($normalized['end_time']) && is_numeric($normalized['end_time']) && (int)$normalized['end_time'] > $currentTime;
$redirectReason = (!$normalized['checkin'] && !$normalized['checkout'])
  ? 'No active mode after normalization. The public site will render Attendance Closed.'
  : (!$timerValid ? 'Mode is active but end_time is missing or expired, so normalization will disable it.' : 'Status is currently valid.');

$configuredAppUrl = function_exists('app_env_value') ? trim((string)app_env_value('APP_URL', '')) : '';
$forwardedProto = trim((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''));
$scheme = 'http';
if ($configuredAppUrl !== '' && preg_match('#^https?://#i', $configuredAppUrl)) {
  $parsedAppUrl = parse_url($configuredAppUrl);
  if (!empty($parsedAppUrl['scheme'])) {
    $scheme = strtolower((string)$parsedAppUrl['scheme']);
  }
} elseif ($forwardedProto !== '') {
  $scheme = strtolower(explode(',', $forwardedProto)[0]);
} elseif (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
  $scheme = 'https';
}

$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$basePath = rtrim(str_replace('\\', '/', dirname(dirname($_SERVER['SCRIPT_NAME'] ?? '/admin/status_debug.php'))), '/');
$baseUrl = $configuredAppUrl !== ''
  ? rtrim($configuredAppUrl, '/')
  : ($scheme . '://' . $host . ($basePath !== '' ? $basePath : ''));
$publicStatusApiUrl = $baseUrl . '/status_api.php?probe=' . time();
$publicIndexUrl = $baseUrl . '/index.php?probe=' . time();
$publicStatusProbe = status_debug_fetch_public_probe($publicStatusApiUrl);
$publicStatusBody = $publicStatusProbe['body'];
$publicStatusJson = $publicStatusBody !== '' ? json_decode($publicStatusBody, true) : null;
$publicStatusJsonError = $publicStatusBody !== '' && !is_array($publicStatusJson) ? json_last_error_msg() : '';
$publicIndexProbe = status_debug_fetch_public_probe($publicIndexUrl);
$publicIndexClosed = stripos($publicIndexProbe['body'], 'attendance closed') !== false;
$publicIndexRedirect = false;
foreach ($publicIndexProbe['headers'] as $headerLine) {
  if (stripos($headerLine, 'Location:') === 0 && stripos($headerLine, 'attendance_closed.php') !== false) {
    $publicIndexRedirect = true;
    break;
  }
}
$publicState = ($publicIndexRedirect || $publicIndexClosed) ? 'CLOSED' : 'OPEN';
?>

<div class="content flex-grow-1 p-4 p-md-5">
  <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:16px;flex-wrap:wrap;margin-bottom:24px;">
    <div>
      <h2 style="font-size:1.5rem;font-weight:800;color:var(--on-surface);margin:0;">Status Diagnostics</h2>
      <p style="color:var(--on-surface-variant);font-size:0.9rem;margin:4px 0 0;">Resolved status file path, raw file contents, normalized state, and timer validation.</p>
    </div>
    <a href="index.php?page=status" class="st-btn st-btn-secondary st-btn-sm">
      <span class="material-symbols-outlined" style="font-size:1rem;">arrow_back</span> Back to Status
    </a>
  </div>

  <div class="stats" style="grid-template-columns:repeat(auto-fit,minmax(220px,1fr));margin-bottom:24px;">
    <div class="stat" style="text-align:left;border-top:none;">
      <p class="st-stat-label">Resolved File</p>
      <p class="st-stat-value" style="font-size:0.95rem;line-height:1.4;word-break:break-all;"><?= htmlspecialchars($statusFile) ?></p>
    </div>
    <div class="stat" style="text-align:left;border-top:none;">
      <p class="st-stat-label">Server Time</p>
      <p class="st-stat-value" style="font-size:1rem;"><?= htmlspecialchars(date('Y-m-d H:i:s', $currentTime)) ?></p>
    </div>
    <div class="stat" style="text-align:left;border-top:none;">
      <p class="st-stat-label">Timer Valid</p>
      <p class="st-stat-value" style="font-size:1rem;color:<?= $timerValid ? '#059669' : '#dc2626' ?>;"><?= $timerValid ? 'YES' : 'NO' ?></p>
    </div>
    <div class="stat" style="text-align:left;border-top:none;">
      <p class="st-stat-label">Redirect State</p>
      <p class="st-stat-value" style="font-size:1rem;color:<?= (!$normalized['checkin'] && !$normalized['checkout']) ? '#dc2626' : '#059669' ?>;"><?= (!$normalized['checkin'] && !$normalized['checkout']) ? 'CLOSED' : 'OPEN' ?></p>
    </div>
  </div>

  <div class="st-card" style="margin-bottom:24px;">
    <p style="font-weight:700;color:var(--on-surface);margin:0 0 12px;">Public Endpoint Probe</p>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:14px;">
      <div><strong>`status_api.php`</strong>
        <div><?= htmlspecialchars($publicStatusApiUrl) ?></div>
      </div>
      <div><strong>`status_api.php` code</strong>
        <div><?= htmlspecialchars((string)($publicStatusProbe['status_code'] ?? 'n/a')) ?></div>
      </div>
      <div><strong>Public JSON</strong>
        <div><?= is_array($publicStatusJson) ? htmlspecialchars(json_encode($publicStatusJson)) : 'invalid / unavailable' ?></div>
      </div>
      <div><strong>Public JSON error</strong>
        <div><?= htmlspecialchars($publicStatusJsonError !== '' ? $publicStatusJsonError : 'none') ?></div>
      </div>
      <div><strong>`index.php`</strong>
        <div><?= htmlspecialchars($publicIndexUrl) ?></div>
      </div>
      <div><strong>Public site state</strong>
        <div style="color:<?= $publicState === 'OPEN' ? '#059669' : '#dc2626' ?>;font-weight:700;"><?= $publicState ?></div>
      </div>
    </div>
    <div style="margin-top:14px;padding:12px 14px;border-radius:12px;background:var(--surface-container-low);color:var(--on-surface);">
      <strong>Public-side result:</strong>
      <?php if (!$publicStatusProbe['ok'] || !$publicIndexProbe['ok']): ?>
        One or more public endpoints could not be fetched from this server. Check the URLs and server networking.
      <?php elseif ($publicIndexRedirect): ?>
        `index.php` is issuing a redirect to `attendance_closed.php`.
      <?php elseif ($publicIndexClosed): ?>
        `index.php` returned closed-page content.
      <?php else: ?>
        `index.php` is currently serving the live attendance page.
      <?php endif; ?>
    </div>
  </div>

  <div class="st-card" style="margin-bottom:24px;">
    <p style="font-weight:700;color:var(--on-surface);margin:0 0 12px;">Public Logic Normalized Status</p>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:14px;">
      <div><strong>checkin</strong>
        <div><?= !empty($publicNormalized['checkin']) ? 'true' : 'false' ?></div>
      </div>
      <div><strong>checkout</strong>
        <div><?= !empty($publicNormalized['checkout']) ? 'true' : 'false' ?></div>
      </div>
      <div><strong>end_time</strong>
        <div><?= isset($publicNormalized['end_time']) && $publicNormalized['end_time'] !== null ? htmlspecialchars((string)$publicNormalized['end_time']) : 'null' ?></div>
      </div>
      <div><strong>end_time human</strong>
        <div><?= isset($publicNormalized['end_time']) && $publicNormalized['end_time'] !== null ? htmlspecialchars(date('Y-m-d H:i:s', (int)$publicNormalized['end_time'])) : 'null' ?></div>
      </div>
      <div><strong>active_mode_configured</strong>
        <div><?= $activeModeConfigured ? 'true' : 'false' ?></div>
      </div>
      <div><strong>timer_valid</strong>
        <div><?= $timerValid ? 'true' : 'false' ?></div>
      </div>
    </div>
    <div style="margin-top:14px;padding:12px 14px;border-radius:12px;background:var(--surface-container-low);color:var(--on-surface);">
      <strong>Interpretation:</strong> <?= htmlspecialchars($redirectReason) ?>
    </div>
  </div>

  <div class="st-card" style="margin-bottom:24px;">
    <p style="font-weight:700;color:var(--on-surface);margin:0 0 12px;">Raw File State</p>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:14px;">
      <div><strong>exists</strong>
        <div><?= $rawExists ? 'true' : 'false' ?></div>
      </div>
      <div><strong>readable</strong>
        <div><?= $rawReadable ? 'true' : 'false' ?></div>
      </div>
      <div><strong>raw checkin</strong>
        <div><?= htmlspecialchars(var_export($rawCheckin, true)) ?></div>
      </div>
      <div><strong>raw checkout</strong>
        <div><?= htmlspecialchars(var_export($rawCheckout, true)) ?></div>
      </div>
      <div><strong>raw end_time</strong>
        <div><?= htmlspecialchars(var_export($rawEndTime, true)) ?></div>
      </div>
      <div><strong>json error</strong>
        <div><?= htmlspecialchars($jsonError !== '' ? $jsonError : 'none') ?></div>
      </div>
    </div>
  </div>

  <div class="st-card" style="margin-bottom:24px;">
    <p style="font-weight:700;color:var(--on-surface);margin:0 0 12px;">Storage Write Diagnostics</p>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:14px;">
      <div><strong>storage dir</strong>
        <div style="word-break:break-all;"><?= htmlspecialchars($statusDir) ?></div>
      </div>
      <div><strong>dir exists</strong>
        <div><?= $statusDirExists ? 'true' : 'false' ?></div>
      </div>
      <div><strong>dir writable</strong>
        <div style="color:<?= $statusDirWritable ? '#059669' : '#dc2626' ?>;"><?= $statusDirWritable ? 'true' : 'false' ?></div>
      </div>
      <div><strong>status writable</strong>
        <div style="color:<?= $statusFileWritable ? '#059669' : '#dc2626' ?>;"><?= $statusFileWritable ? 'true' : 'false' ?></div>
      </div>
      <div><strong>probe write</strong>
        <div style="color:<?= $writeProbeOk ? '#059669' : '#dc2626' ?>;"><?= $writeProbeOk ? 'ok' : 'failed' ?></div>
      </div>
      <div><strong>probe read-back</strong>
        <div style="color:<?= $writeProbeReadBackMatches ? '#059669' : '#dc2626' ?>;"><?= $writeProbeReadBackMatches ? 'match' : 'mismatch' ?></div>
      </div>
      <div><strong>probe error</strong>
        <div><?= htmlspecialchars($writeProbeError !== '' ? $writeProbeError : 'none') ?></div>
      </div>
    </div>
  </div>

  <div class="st-card">
    <p style="font-weight:700;color:var(--on-surface);margin:0 0 12px;">Raw `status.json`</p>
    <pre style="margin:0;white-space:pre-wrap;word-break:break-word;background:var(--surface-container-low);padding:16px;border-radius:12px;color:var(--on-surface);max-height:420px;overflow:auto;"><?= htmlspecialchars($rawContent !== '' ? $rawContent : '[empty or unreadable]') ?></pre>
  </div>
</div>
