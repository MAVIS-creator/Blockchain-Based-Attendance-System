<?php

/**
 * Evaluate browser-reported evidence against the configured circular geofence.
 * Accuracy and timestamp are client claims, not proof of physical presence.
 */
function bbas_geofence_evaluate(array $settings, array $evidence, int $nowMs): array
{
    if (empty($settings['geo_fence_enabled'])) {
        return ['enabled' => false];
    }

    $result = ['enabled' => true, 'inside' => null];
    $config = $settings['geo_fence'] ?? [];
    $number = static function ($value, float $min, float $max): ?float {
        if (!is_numeric($value)) {
            return null;
        }
        $value = (float)$value;
        return is_finite($value) && $value >= $min && $value <= $max ? $value : null;
    };

    $centerLat = $number($config['lat'] ?? null, -90, 90);
    $centerLng = $number($config['lng'] ?? null, -180, 180);
    $radius = $number($config['radius_m'] ?? null, 1, PHP_FLOAT_MAX);
    if ($centerLat === null || $centerLng === null || $radius === null) {
        return $result + ['reason' => 'config_invalid'];
    }

    $result['radius_m'] = $radius;
    $rawLat = $evidence['lat'] ?? '';
    $rawLng = $evidence['lng'] ?? '';
    if ($rawLat === '' || $rawLng === '') {
        return $result + ['reason' => 'client_location_missing'];
    }
    $lat = $number($rawLat, -90, 90);
    $lng = $number($rawLng, -180, 180);
    if ($lat === null || $lng === null) {
        return $result + ['reason' => 'invalid_coordinates'];
    }

    $timestamp = $number($evidence['geo_client_ts'] ?? null, $nowMs - 90000, $nowMs + 30000);
    if ($timestamp === null) {
        return $result + ['reason' => 'location_stale'];
    }

    $accuracy = $number($evidence['geo_accuracy_m'] ?? null, 0, min(100, $radius));
    if ($accuracy === null) {
        return $result + ['reason' => 'location_inaccurate'];
    }

    $dLat = deg2rad($lat - $centerLat);
    $dLng = deg2rad($lng - $centerLng);
    $a = sin($dLat / 2) ** 2 + cos(deg2rad($centerLat)) * cos(deg2rad($lat)) * sin($dLng / 2) ** 2;
    $distance = 6371000 * 2 * asin(sqrt(max(0, min(1, $a))));
    $result['distance_m'] = round($distance, 2);
    $result['client_accuracy_m'] = $accuracy;
    $result['client_ts'] = (int)$timestamp;

    if ($distance > $radius) {
        $result['inside'] = false;
        return $result + ['reason' => 'outside_geofence'];
    }
    if ($distance + $accuracy > $radius) {
        return $result + ['reason' => 'location_uncertain'];
    }
    $result['inside'] = true;
    return $result + ['reason' => 'inside_geofence'];
}
