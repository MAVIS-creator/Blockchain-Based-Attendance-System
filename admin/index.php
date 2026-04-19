<?php
require_once __DIR__ . '/session_bootstrap.php';
require_once __DIR__ . '/state_helpers.php';
// Enable output buffering so included page views can send headers (redirects) after POST handling
if (function_exists('ob_start')) ob_start();
$isAdminLoggedIn = isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true;
if (!$isAdminLoggedIn) {
  $restoreReason = null;
  $restoreSid = trim((string)session_id());
  if ($restoreSid === '') {
    $restoreSid = trim((string)($_COOKIE[ADMIN_SESSION_TRACKER_COOKIE] ?? ''));
  }
  if ($restoreSid !== '' && admin_restore_session_from_tracker($restoreSid, $restoreReason) && !empty($_SESSION['admin_logged_in'])) {
    admin_auth_debug_log('index_session_restored_from_tracker', [
      'reason' => (string)$restoreReason,
      'session_id' => (string)session_id(),
      'session_keys' => array_values(array_keys($_SESSION ?? [])),
    ]);
    $isAdminLoggedIn = true;
  }
}

if (!$isAdminLoggedIn) {
  admin_auth_debug_log('index_guard_redirect', [
    'reason' => 'admin_logged_in_missing_or_false',
    'restore_attempt' => isset($restoreReason) ? (string)$restoreReason : 'not_attempted',
    'session_keys' => array_values(array_keys($_SESSION ?? [])),
    'cookie_present' => isset($_COOKIE[session_name()]),
  ]);
  $query = http_build_query([
    'auth_issue' => 'session_lost_before_index',
    'auth_debug' => '1',
  ]);
  header('Location: login.php?' . $query);
  exit;
}

require_once __DIR__ . '/runtime_storage.php';

// Session Tracking & Validity Check
$currentSessionId = session_id();
$activeSessions = admin_sessions_read_fresh();
if (!isset($activeSessions[$currentSessionId]) || !is_array($activeSessions[$currentSessionId])) {
  $registerOk = admin_register_session((string)($_SESSION['admin_user'] ?? 'admin'));
  admin_auth_debug_log('index_session_tracker_repair', [
    'session_id' => $currentSessionId,
    'register_ok' => (bool)$registerOk,
  ]);
  $activeSessions = admin_sessions_read_fresh();
}

if (isset($activeSessions[$currentSessionId]) && !admin_touch_session_activity($currentSessionId)) {
  // Avoid logging the user out just because the tracker file could not be refreshed.
  $activeSessions[$currentSessionId]['last_activity'] = time();
  admin_auth_debug_log('index_session_touch_failed', [
    'session_id' => $currentSessionId,
  ]);
}

$page = $_GET['page'] ?? 'dashboard';

// Enforce Role Permissions
$currentRole = $_SESSION['admin_role'] ?? 'admin';
if ($currentRole !== 'superadmin' && $page !== 'unauthorized') {
  $permissions = admin_load_permissions_cached();
  $allowedPages = $permissions[$currentRole] ?? [];
  if (!in_array($page, $allowedPages, true)) {
    header('Location: index.php?page=unauthorized');
    exit;
  }
}

$routes = [];
foreach (admin_route_catalog() as $pageId => $meta) {
  $routes[$pageId] = (string)($meta['file'] ?? '');
}
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
  <link rel="icon" type="image/png" href="../asset/image.png">
  <link rel="apple-touch-icon" sizes="180x180" href="../asset/image.png">
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
