<?php
session_start();
// Enable output buffering so included page views can send headers (redirects) after POST handling
if (function_exists('ob_start')) ob_start();
if (!isset($_SESSION['admin_logged_in'])) {
  header('Location: login.php');
  exit;
}

require_once __DIR__ . '/runtime_storage.php';

// Session Tracking & Validity Check
$sessionsFile = admin_storage_migrate_file('sessions.json');
$currentSessionId = session_id();
if (file_exists($sessionsFile)) {
  $activeSessions = json_decode(file_get_contents($sessionsFile), true);
  if (!is_array($activeSessions)) $activeSessions = [];

  // If tracking is active but our session isn't in it, we were terminated
  if (!isset($activeSessions[$currentSessionId])) {
    // Destroy PHP session and logout
    session_unset();
    session_destroy();
    header('Location: login.php?msg=session_terminated');
    exit;
  } else {
    // Update last activity
    $activeSessions[$currentSessionId]['last_activity'] = time();
    file_put_contents($sessionsFile, json_encode($activeSessions, JSON_PRETTY_PRINT));
  }
}

$page = $_GET['page'] ?? 'dashboard';

$routes = [
  'dashboard'          => 'dashboard.php',
  'status'             => 'status.php',
  'request_timings'    => 'request_timings.php',
  'logs'               => 'logs/logs.php',
  'clear_logs_ui'      => 'clear_logs_ui.php',
  'clear_tokens_ui'    => 'clear_tokens_ui.php',
  'failed_attempts'    => 'logs/failed_attempts.php',
  'accounts'           => 'accounts.php',
  'settings'           => 'settings.php',
  'chain'              => 'chain.php',
  'add_course'         => 'courses/add.php',
  'set_active'         => 'courses/set_active.php',
  'manual_attendance'  => 'manual_attendance.php',
  'geofence'           => 'geofence.php',
  'support_tickets'    => 'view_tickets.php',
  'unlink_fingerprint' => 'unlink_fingerprint.php',
  'announcement'       => 'announcement.php',
  'patcher'            => 'patcher.php',
  'send_logs_email'   => 'send_logs_email.php',
  'profile_settings'  => 'profile_settings.php'
];
$view = $routes[$page] ?? 'dashboard.php';
?>

<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Admin Panel | Attendance System</title>
  <!-- Fonts -->
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
  <!-- Material Symbols -->
  <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet">
  <!-- Boxicons (kept for backward compatibility) -->
  <link href='https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css' rel='stylesheet'>
  <!-- Main Stylesheet -->
  <link rel="stylesheet" href="style.css?v=2.1">
  <!-- Favicons -->
  <link rel="icon" type="image/x-icon" href="../asset/favicon.ico">
  <link rel="apple-touch-icon" sizes="180x180" href="../asset/apple-touch-icon.png">
  <link rel="icon" type="image/png" sizes="32x32" href="../asset/favicon-32x32.png">
  <link rel="icon" type="image/png" sizes="16x16" href="../asset/favicon-16x16.png">
  <link rel="manifest" href="../asset/site.webmanifest">
</head>

<body class="admin-page-<?= htmlspecialchars($page) ?>">

  <div class="layout">

    <?php include 'includes/navbar.php'; ?>
    <?php include 'includes/sidebar.php'; ?>

    <div class="main-content">
      <?php include 'includes/header.php'; ?>
      <div class="content-wrapper">
        <?php include $view; ?>
      </div>
      <?php include 'includes/footer.php'; ?>
    </div>

  </div>

  <script>
    function toggleSidebar() {
      const sidebar = document.querySelector('.sidebar');
      const body = document.body;
      if (sidebar.classList.contains('open')) {
        sidebar.classList.remove('open');
        body.classList.remove('sidebar-open');
        body.style.overflow = '';
      } else {
        sidebar.classList.add('open');
        body.classList.add('sidebar-open');
        body.style.overflow = 'hidden';
      }
    }
  </script>
  <?php if (function_exists('ob_end_flush')) {
    @ob_end_flush();
  } ?>
</body>

</html>
