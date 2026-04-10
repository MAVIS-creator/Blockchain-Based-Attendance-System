<?php
require_once __DIR__ . '/admin/runtime_storage.php';
require_once __DIR__ . '/admin/state_helpers.php';
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
    $json = json_decode(file_get_contents($announcementFile), true);
    if (is_array($json)) {
        $announcement['enabled'] = $json['enabled'] ?? false;
        $announcement['message'] = $json['message'] ?? '';
        $announcement['severity'] = in_array(($json['severity'] ?? 'info'), ['info', 'warning', 'urgent'], true) ? $json['severity'] : 'info';
        $announcement['updated_at'] = $json['updated_at'] ?? null;
    }
}

if ($fingerprint !== '' && file_exists($targetedFile)) {
    $targetedRows = json_decode((string)@file_get_contents($targetedFile), true);
    if (is_array($targetedRows)) {
        foreach ($targetedRows as $row) {
            if (!is_array($row)) {
                continue;
            }
            if (empty($row['enabled'])) {
                continue;
            }
            if ((string)($row['target_fingerprint'] ?? '') !== $fingerprint) {
                continue;
            }

            $announcement = [
                'enabled' => true,
                'message' => (string)($row['message'] ?? ''),
                'severity' => in_array(($row['severity'] ?? 'info'), ['info', 'warning', 'urgent'], true) ? (string)$row['severity'] : 'info',
                'updated_at' => $row['updated_at'] ?? null,
                'target_fingerprint' => (string)($row['target_fingerprint'] ?? ''),
                'auto_generated_by' => (string)($row['auto_generated_by'] ?? ''),
                'id' => (string)($row['id'] ?? ''),
            ];
            break;
        }
    }
}

header('Content-Type: application/json');
echo json_encode($announcement);
