<?php
session_start();
// Enable output buffering so included page views can send headers (redirects) after POST handling
if (function_exists('ob_start')) ob_start();
if (!isset($_SESSION['admin_logged_in'])) {
  header('Location: login.php');
  exit;
}

$page = $_GET['page'] ?? 'dashboard';

$routes = [
  'dashboard'          => 'dashboard.php',
  'status'             => 'status.php',
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
  'support_tickets'    => 'view_tickets.php',
  'unlink_fingerprint' => 'unlink_fingerprint.php',
  'announcement'       => 'announcement.php'
  , 'send_logs_email'   => 'send_logs_email.php'
];
$view = $routes[$page] ?? 'dashboard.php';
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Admin Panel</title>
  <link rel="stylesheet" href="style.css">
  <link rel="stylesheet" href="boxicons.min.css">
  <link href='https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css' rel='stylesheet'>
          <link rel="icon" type="image/x-icon" href="../asset/favicon.ico">
  <link rel="apple-touch-icon" sizes="180x180" href="../asset/apple-touch-icon.png">
  <link rel="icon" type="image/png" sizes="32x32" href="../asset/favicon-32x32.png">
  <link rel="icon" type="image/png" sizes="16x16" href="../asset/favicon-16x16.png">
  <link rel="manifest" href="../asset/site.webmanifest">
</head>
<body>

<div class="layout"> 

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
  const content = document.querySelector('.main-content');
  sidebar.classList.toggle('collapsed');
  if (content) content.classList.toggle('expanded');
  document.cookie = "sidebar_collapsed=" + (sidebar.classList.contains('collapsed') ? 'true' : 'false');
}
</script>
<?php if (function_exists('ob_end_flush')) { @ob_end_flush(); } ?>
</body>
</html>
