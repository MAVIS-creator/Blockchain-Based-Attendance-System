<?php
require_once __DIR__ . '/../src/GeofenceEvidence.php';

$now = 1760000000000;
$settings = ['geo_fence_enabled' => true, 'geo_fence' => ['lat' => 0, 'lng' => 0, 'radius_m' => 100]];
$evidence = ['lat' => '0', 'lng' => '0', 'geo_client_ts' => (string)$now, 'geo_accuracy_m' => '10'];

$cases = [
    ['inside_geofence', []],
    ['invalid_coordinates', ['lng' => '1e9999']],
    ['invalid_coordinates', ['lat' => ['0']]],
    ['location_stale', ['geo_client_ts' => $now - 90001]],
    ['location_stale', ['geo_client_ts' => $now + 30001]],
    ['location_inaccurate', ['geo_accuracy_m' => '-1']],
    ['location_inaccurate', ['geo_accuracy_m' => '101']],
    ['location_uncertain', ['lng' => '0.00085']],
    ['outside_geofence', ['lng' => '0.001']],
];
foreach ($cases as [$expected, $fields]) {
    $result = bbas_geofence_evaluate($settings, $fields + $evidence, $now);
    if ($result['reason'] !== $expected || ($result['inside'] === true) !== ($expected === 'inside_geofence')) {
        throw new RuntimeException('Unexpected geofence result for ' . $expected);
    }
}
$settings['geo_fence']['lat'] = 'invalid';
if (bbas_geofence_evaluate($settings, $evidence, $now)['reason'] !== 'config_invalid') {
    throw new RuntimeException('Invalid enabled geofence did not fail closed');
}
echo "Geofence evidence regression passed\n";
