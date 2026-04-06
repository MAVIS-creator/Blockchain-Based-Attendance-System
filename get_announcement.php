<?php
require_once __DIR__ . '/admin/runtime_storage.php';
$announcementFile = admin_storage_migrate_file('announcement.json');

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

header('Content-Type: application/json');
echo json_encode($announcement);
