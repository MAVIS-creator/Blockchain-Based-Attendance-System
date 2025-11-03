<?php
// Public endpoint clients can poll to get revoked tokens list
// Lightweight and cacheable
header('Content-Type: application/json');
$file = __DIR__ . '/revoked.json';
$data = file_exists($file) ? json_decode(file_get_contents($file), true) : ['tokens'=>[], 'ips'=>[], 'macs'=>[]];
if (!is_array($data)) $data = ['tokens'=>[], 'ips'=>[], 'macs'=>[]];

// Send minimal response
echo json_encode(['ok'=>true,'revoked'=>$data]);
