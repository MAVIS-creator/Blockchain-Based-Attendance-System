<?php
session_start();
if (empty($_SESSION['admin_logged_in'])) { header('HTTP/1.1 403 Forbidden'); echo json_encode(['ok'=>false,'message'=>'Not authorized']); exit; }

$file = __DIR__ . '/revoked.json';
$data = file_exists($file) ? json_decode(file_get_contents($file), true) : ['tokens'=>[], 'ips'=>[], 'macs'=>[]];
if (!is_array($data)) $data = ['tokens'=>[], 'ips'=>[], 'macs'=>[]];

$token = trim($_POST['token'] ?? '');
$ip = trim($_POST['ip'] ?? '');
$mac = trim($_POST['mac'] ?? '');
$added = [];
if ($token !== '') { if (!in_array($token, $data['tokens'])) { $data['tokens'][] = $token; $added[] = 'token'; } }
if ($ip !== '') { if (!in_array($ip, $data['ips'])) { $data['ips'][] = $ip; $added[] = 'ip'; } }
if ($mac !== '') { if (!in_array($mac, $data['macs'])) { $data['macs'][] = $mac; $added[] = 'mac'; } }

file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
header('Content-Type: application/json');
echo json_encode(['ok'=>true,'added'=>$added,'data_count'=>['tokens'=>count($data['tokens']),'ips'=>count($data['ips']),'macs'=>count($data['macs'])]]);
