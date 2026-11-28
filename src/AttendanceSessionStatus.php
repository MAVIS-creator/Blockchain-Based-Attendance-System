<?php
declare(strict_types=1);

/** Shared by the native administrator and the unified API. */
function bbas_set_attendance_status(string $path, string $mode, int $duration, array $settings): array
{
    if (!in_array($mode, ['checkin', 'checkout', 'closed'], true) || $duration < 60 || $duration > 86400) {
        throw new DomainException('Choose a valid attendance mode and duration.');
    }
    if ($mode === 'checkin' && !empty($settings['checkin_time_start']) && !empty($settings['checkin_time_end'])) {
        $start = $settings['checkin_time_start'];
        $end = $settings['checkin_time_end'];
        $now = date('H:i');
        $inside = $start <= $end ? ($now >= $start && $now <= $end) : ($now >= $start || $now <= $end);
        if (!$inside) {
            throw new DomainException('Cannot enable Check-In outside the configured check-in window.');
        }
    }

    $status = [
        'checkin' => $mode === 'checkin',
        'checkout' => $mode === 'checkout',
        'end_time' => $mode === 'closed' ? null : time() + $duration,
    ];
    $temporary = tempnam(dirname($path), '.attendance-status-');
    if ($temporary === false) {
        throw new RuntimeException('Could not prepare attendance status');
    }
    try {
        $encoded = json_encode($status, JSON_THROW_ON_ERROR);
        if (file_put_contents($temporary, $encoded) !== strlen($encoded) || !rename($temporary, $path)) {
            throw new RuntimeException('Could not save attendance status');
        }
    } finally {
        if (is_file($temporary)) {
            unlink($temporary);
        }
    }
    return $status;
}
