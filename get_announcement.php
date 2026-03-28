<?php
$announcementFile = __DIR__ . '/admin/announcement.json';

$announcement = [
    'enabled' => false,
    'message' => ''
];

if (file_exists($announcementFile)) {
    $json = json_decode(file_get_contents($announcementFile), true);
    if (is_array($json)) {
        $announcement['enabled'] = $json['enabled'] ?? false;
        $announcement['message'] = $json['message'] ?? '';
    }
}

header('Content-Type: application/json');
echo json_encode($announcement);
?>