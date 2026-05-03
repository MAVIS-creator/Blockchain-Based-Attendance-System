<?php
require_once __DIR__ . '/request_guard.php';
require_once __DIR__ . '/admin/runtime_storage.php';
require_once __DIR__ . '/admin/state_helpers.php';
require_once __DIR__ . '/admin/cache_helpers.php';
require_once __DIR__ . '/src/AiAnnouncementService.php';
app_request_guard('get_announcement.php', 'public');

if (!function_exists('sanitize_public_announcement_message')) {
    function sanitize_public_announcement_message($message, $classification = '')
    {
        if (class_exists('AiAnnouncementService') && method_exists('AiAnnouncementService', 'normalizeStudentTargetedMessage')) {
            return AiAnnouncementService::normalizeStudentTargetedMessage($message, $classification, []);
        }

        return trim((string)$message);
    }
}

if (!function_exists('public_targeted_announcement_index')) {
    function public_targeted_announcement_index($path, $ttl = 3)
    {
        $mtime = @filemtime($path) ?: 0;
        $size = @filesize($path) ?: 0;
        $cacheKey = 'public_targeted_announcement_index:' . md5($path . '|' . $mtime . '|' . $size);

        return admin_cache_remember($cacheKey, $ttl, function () use ($path, $ttl) {
            $rows = admin_cached_json_file('public_targeted_announcements', $path, [], $ttl);
            $index = [];

            if (!is_array($rows)) {
                return $index;
            }

            foreach ($rows as $row) {
                if (!is_array($row) || empty($row['enabled'])) {
                    continue;
                }

                $fingerprint = trim((string)($row['target_fingerprint'] ?? ''));
                if ($fingerprint === '') {
                    continue;
                }

                $index[$fingerprint] = $row;
            }

            return $index;
        });
    }
}

$announcementFile = admin_storage_migrate_file('announcement.json');
$targetedFile = function_exists('ai_targeted_announcements_file')
    ? ai_targeted_announcements_file()
    : admin_storage_migrate_file('announcement_targets.json');

$fingerprint = trim((string)($_GET['fingerprint'] ?? ''));

$announcement = [
    'enabled' => false,
    'message' => '',
    'severity' => 'info',
    'updated_at' => null
];

if (file_exists($announcementFile)) {
    $json = admin_cached_json_file('public_announcement', $announcementFile, [], 3);
    if (is_array($json)) {
        $announcement['enabled'] = $json['enabled'] ?? false;
        $announcement['message'] = $json['message'] ?? '';
        $announcement['severity'] = in_array(($json['severity'] ?? 'info'), ['info', 'warning', 'urgent'], true) ? $json['severity'] : 'info';
        $announcement['updated_at'] = $json['updated_at'] ?? null;
    }
}

if ($fingerprint !== '' && file_exists($targetedFile)) {
    $targetedIndex = public_targeted_announcement_index($targetedFile, 3);
    $row = is_array($targetedIndex) ? ($targetedIndex[$fingerprint] ?? null) : null;
    if (is_array($row)) {
        $announcement = [
            'enabled' => true,
            'message' => class_exists('AiAnnouncementService') && method_exists('AiAnnouncementService', 'normalizeStudentTargetedMessage')
                ? AiAnnouncementService::normalizeStudentTargetedMessage(
                    (string)($row['message'] ?? ''),
                    (string)($row['classification'] ?? ''),
                    is_array($row) ? $row : []
                )
                : sanitize_public_announcement_message((string)($row['message'] ?? ''), (string)($row['classification'] ?? '')),
            'severity' => in_array(($row['severity'] ?? 'info'), ['info', 'warning', 'urgent'], true) ? (string)$row['severity'] : 'info',
            'updated_at' => $row['updated_at'] ?? null,
            'target_fingerprint' => (string)($row['target_fingerprint'] ?? ''),
            'auto_generated_by' => (string)($row['auto_generated_by'] ?? ''),
            'id' => (string)($row['id'] ?? ''),
        ];
    }
}

header('Content-Type: application/json');
echo json_encode($announcement);
