<?php
// Styled attendance-closed page (general closed state)
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="stylesheet" href="admin/boxicons.min.css">
  <link href='https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css' rel='stylesheet'>
  <title>Attendance Closed</title>
  <style>
    :root{ --accent-red:#ef4444; --accent-yellow:#facc15; --accent-dark:#111827 }
    body{ margin:0; font-family:'Segoe UI',sans-serif; background:#f8fafc; color:var(--accent-dark); display:flex; align-items:center; justify-content:center; height:100vh }
    .card{ background:#fff; padding:28px; border-radius:12px; box-shadow:0 12px 40px rgba(16,24,40,0.08); max-width:720px; width:92%; text-align:center }
    .logo{ height:64px; width:64px; object-fit:cover; border-radius:10px; margin-bottom:12px }
    h1{ margin:0 0 8px; font-size:1.6rem }
    p{ color:#374151 }
    .actions{ margin-top:18px; display:flex; gap:12px; justify-content:center }
    .btn{ padding:10px 18px; border-radius:8px; font-weight:700; border:none; cursor:pointer }
    .btn-primary{ background: linear-gradient(90deg,var(--accent-red),#d97706); color:#fff }
    .btn-accent{ background: linear-gradient(90deg,var(--accent-yellow),#f59e0b); color:#111 }
  </style>
</head>
<body>
  <div class="card">
  <img class="logo" src="asset/lautech_logo.png" alt="logo" style="background:#fff;padding:8px;border-radius:8px;">
    <h1>Attendance Currently Closed</h1>
    <p>Attendance is not open at this time. Please return later or contact support if you believe this is an error.</p>
    <div class="actions">
      <a class="btn btn-primary" href="support.php"><i class='bx bx-message'></i> Contact Support</a>
    </div>
  </div>
</body>
</html>
