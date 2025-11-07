<?php
// Show the timeout page inside the admin layout so it looks consistent.
if (session_status() === PHP_SESSION_NONE) session_start();
$from = $_GET['from'] ?? '';
$back = '#';
try { if ($from) $back = htmlspecialchars(urldecode($from)); } catch(Exception $e) { $back = '#'; }
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Page Load Timeout</title>
  <link rel="stylesheet" href="style.css">
  <link href='https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css' rel='stylesheet'>
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
</head>
<body>
  <div class="layout">
    <?php include __DIR__ . '/includes/sidebar.php'; ?>
    <div class="main-content">
      <?php include __DIR__ . '/includes/header.php'; ?>
      <div class="content-wrapper" style="display:flex;align-items:center;justify-content:center;min-height:320px;">
        <div class="timeout-card">
          <div class="timeout-hero"><i class='bx bx-time-five'></i></div>
          <h2>Page taking too long to load</h2>
          <p>We couldn't finish loading the page within 1 minute. You can try reloading, go back to the previous page, or open the dashboard.</p>
          <div class="timeout-actions">
            <a class="btn btn-primary" href="<?= $back ?>">Retry</a>
            <a class="btn" href="index.php">Go to Dashboard</a>
          </div>
          <div style="margin-top:12px;color:#7b8794;font-size:0.9rem;">If this keeps happening, check your network connection or try again later.</div>
        </div>
      </div>
      <?php include __DIR__ . '/includes/footer.php'; ?>
    </div>
  </div>
</body>
</html>