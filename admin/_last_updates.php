<?php
if (session_status() === PHP_SESSION_NONE) session_start();
header('Content-Type: application/json');
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    echo json_encode(['error' => 'unauthenticated']);
    exit;
}
$base = __DIR__ . '/';
$accounts = $base . 'accounts.json';
$settings = $base . 'settings.json';
$chain = __DIR__ . '/../secure_logs/attendance_chain.json';
// additional watched files/pages/data
$ticketsFile = $base . 'support_tickets.json';
$fingerprints = $base . 'fingerprints.json';
$courses = $base . 'courses/course.json';
$activeCourse = $base . 'courses/active_course.json';
$statusFile = $base . 'status.json';
$viewTicketsPage = $base . 'view_tickets.php';
$unlinkPage = $base . 'unlink_fingerprint.php';
$addCoursePage = $base . 'courses/add.php';
$chatFile = $base . 'chat.json';

$out = [
    'accounts' => @filemtime($accounts) ?: 0,
    'settings' => @filemtime($settings) ?: 0,
    'chain' => @filemtime($chain) ?: 0,
    'tickets' => @filemtime($ticketsFile) ?: 0,
    'fingerprints' => @filemtime($fingerprints) ?: 0,
    'courses' => @filemtime($courses) ?: 0,
    'active_course' => @filemtime($activeCourse) ?: 0,
    'status' => @filemtime($statusFile) ?: 0,
    'view_tickets_page' => @filemtime($viewTicketsPage) ?: 0,
    'unlink_page' => @filemtime($unlinkPage) ?: 0,
    'add_course_page' => @filemtime($addCoursePage) ?: 0,
    'chat' => @filemtime($chatFile) ?: 0
];
echo json_encode($out);
