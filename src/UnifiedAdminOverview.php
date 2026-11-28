<?php
declare(strict_types=1);

require_once __DIR__ . '/../admin/state_helpers.php';

function bbas_unified_overview(string $subject): array
{
    $allowed = static function (string $module) use ($subject): bool {
        $subjects = (string)app_env_value('BBAS_UNIFIED_' . $module . '_READ_SUBJECTS', '');
        return in_array($subject, array_map('trim', explode(',', $subjects)), true);
    };
    $overview = [];
    if ($allowed('REPORTS')) {
        $summary = admin_dashboard_log_summary(20, 0);
        $overview['reports'] = [
            'Attendance records' => array_sum($summary['dailyCounts']),
            'Unique students' => $summary['uniqueStudentCount'],
            'Failed attempts' => array_sum($summary['failedCounts']),
        ];
    }
    if ($allowed('GEOFENCE')) {
        $settings = admin_load_settings_cached();
        $overview['geofence'] = [
            'Enforcement' => !empty($settings['geo_fence_enabled']) ? 'Enabled' : 'Disabled',
            'Radius (metres)' => max(0, (int)($settings['geo_fence']['radius_m'] ?? 0)),
            'Saved landmarks' => is_array($settings['geo_landmarks'] ?? null) ? count($settings['geo_landmarks']) : 0,
        ];
    }
    if ($allowed('FINGERPRINTS')) {
        $overview['fingerprints'] = ['Registered fingerprints' => admin_fingerprint_count_cached()];
    }
    if ($allowed('REVOCATIONS')) {
        $path = admin_storage_migrate_file('revoked.json', app_storage_file('revoked.json'));
        $revoked = admin_cached_json_file('unified_revocations', $path, [], 15);
        $overview['revocations'] = [
            'Token revocation entries' => is_array($revoked['tokens'] ?? null) ? count($revoked['tokens']) : 0,
        ];
    }
    if ($allowed('TICKETS')) {
        $overview['tickets'] = ['Unresolved tickets' => admin_support_ticket_count()];
    }
    if ($allowed('ANNOUNCEMENTS')) {
        $announcement = admin_cached_json_file('unified_announcement', admin_storage_migrate_file('announcement.json'), [], 15);
        $overview['announcements'] = ['Student announcement' => !empty($announcement['enabled']) ? 'Enabled' : 'Disabled'];
    }
    return $overview;
}
