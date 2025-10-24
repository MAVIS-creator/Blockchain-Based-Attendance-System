<?php
session_start();
if (!isset($_SESSION['admin_logged_in'])) {
  header('Location: login.php');
  exit;
}

$page = $_GET['page'] ?? 'dashboard';

$routes = [
  'dashboard'          => 'dashboard.php',
  'status'             => 'status.php',
  'logs'               => 'logs/logs.php', 
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
];
$view = $routes[$page] ?? 'dashboard.php';
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Admin Panel</title>
  <link rel="stylesheet" href="style.css">
  <link href='https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css' rel='stylesheet'>
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
</body>
</html>
