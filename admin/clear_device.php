<?php
session_start();
if (empty($_SESSION['admin_logged_in'])) {
  header('HTTP/1.1 403 Forbidden');
  echo json_encode(['ok'=>false,'message'=>'Not authorized']);
  exit;
}

// Accept fingerprint or matric to clear device-blocking state for today
$fingerprint = trim($_POST['fingerprint'] ?? '');
$matric = trim($_POST['matric'] ?? '');
$today = date('Y-m-d');
$adminLogs = __DIR__ . '/logs';
$responses = ['cleared'=>[], 'skipped'=>[], 'errors'=>[]];

if ($fingerprint === '' && $matric === '') {
  header('Content-Type: application/json');
  echo json_encode(['ok'=>false,'message'=>'fingerprint or matric required']);
  exit;
}

// Helper to read/write JSON stores (no encryption here)
function read_json($file){ if (!file_exists($file)) return []; $c = @file_get_contents($file); $d = json_decode($c, true); return is_array($d) ? $d : []; }
function write_json($file, $data){ @file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX); }

// Target files for clearing
$targets = [
  $adminLogs . "/fp_useragent_{$today}.json",
  $adminLogs . "/device_cooldowns_{$today}.json",
  $adminLogs . "/fp_devices_{$today}.json",
  $adminLogs . "/fp_devices_{$today}.json",
];

foreach ($targets as $file) {
  if (!file_exists($file)) continue;
  $data = read_json($file);
  $changed = false;
  if ($fingerprint !== '') {
    if (isset($data[$fingerprint])) { unset($data[$fingerprint]); $changed = true; }
    // some files may use concatenated keys
    foreach ($data as $k => $v) {
      if (strpos($k, $fingerprint) !== false) { unset($data[$k]); $changed = true; }
    }
  }
  if ($matric !== '') {
    // fingerprints.json maps matric -> hashedFingerprint
    $fpFile = __DIR__ . '/fingerprints.json';
    if (file_exists($fpFile)) {
      $fps = read_json($fpFile);
      if (isset($fps[$matric])) { unset($fps[$matric]); write_json($fpFile,$fps); $responses['cleared'][] = "fingerprints.json: $matric"; }
    }
    // also remove any entries where matric appears in keys
    foreach ($data as $k => $v) {
      if (strpos($k, $matric) !== false) { unset($data[$k]); $changed = true; }
    }
  }
  if ($changed) { write_json($file, $data); $responses['cleared'][] = $file; }
}

// Also remove cooldown entries that use composite keys
$cdFile = $adminLogs . "/device_cooldowns_{$today}.json";
if (file_exists($cdFile)) {
  $cd = read_json($cdFile);
  $changed = false;
  foreach ($cd as $k => $v) {
    if ($fingerprint !== '' && strpos($k, $fingerprint) !== false) { unset($cd[$k]); $changed = true; }
    if ($matric !== '' && strpos($k, $matric) !== false) { unset($cd[$k]); $changed = true; }
  }
  if ($changed) { write_json($cdFile, $cd); $responses['cleared'][] = $cdFile; }
}

header('Content-Type: application/json');
echo json_encode(['ok'=>true,'result'=>$responses]);
