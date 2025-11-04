<?php
// Public endpoint clients can poll to get revoked tokens list
// Lightweight and cacheable
header('Content-Type: application/json');
$file = __DIR__ . '/revoked.json';
$data = file_exists($file) ? json_decode(file_get_contents($file), true) : ['tokens'=>[], 'ips'=>[], 'macs'=>[]];
if (!is_array($data)) $data = ['tokens'=>[], 'ips'=>[], 'macs'=>[]];

// Remove expired entries and build response
$now = time();
$cleaned = false;
$resp = ['tokens'=>[], 'ips'=>[], 'macs'=>[]];
foreach (['tokens','ips','macs'] as $k) {
	if (!isset($data[$k]) || !is_array($data[$k])) { $data[$k] = []; continue; }
	foreach ($data[$k] as $key => $meta) {
		$expiry = intval($meta['expiry'] ?? 0);
		if ($expiry !== 0 && $expiry < $now) {
			// expired
			unset($data[$k][$key]);
			$cleaned = true;
			continue;
		}
		// include metadata so clients or admin UIs can show expiry/issued-by
		$resp[$k][$key] = $meta;
	}
}

if ($cleaned) {
	// persist cleanup
	file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
}

echo json_encode(['ok'=>true,'revoked'=>$resp]);
