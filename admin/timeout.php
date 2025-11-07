<?php
// Public timeout page shown when a page takes too long to load
// This page intentionally does not require login so it can be displayed in all cases
$from = $_GET['from'] ?? '';
$back = '#';
try { if ($from) $back = htmlspecialchars(urldecode($from)); } catch(Exception $e) { $back = '#'; }
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Page Load Timeout</title>
  <link rel="stylesheet" href="style.css">
  <link href='https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css' rel='stylesheet'>
</head>
<body>
  <div style="min-height:100vh;display:flex;align-items:center;justify-content:center;padding:24px;background:linear-gradient(135deg,#f4f6f8,#eaf1f7);">
    <div class="timeout-card">
      <div class="timeout-hero">
        <i class='bx bx-time-five'></i>
      </div>
      <h2>Page taking too long to load</h2>
      <p>We couldn't finish loading the page within 1 minute. You can try reloading, go back to the previous page, or open the dashboard.</p>
      <div class="timeout-actions">
        <a class="btn btn-primary" href="<?= $back ?>">Retry</a>
        <a class="btn" href="index.php">Go to Dashboard</a>
      </div>
      <div style="margin-top:12px;color:#7b8794;font-size:0.9rem;">If this keeps happening, check your network connection or try again later.</div>
    </div>
  </div>
</body>
</html>