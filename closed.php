<?php
session_start();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <title>Attendance Closed</title>
  <style>
    :root{ --accent-red:#ef4444; --accent-yellow:#facc15; --accent-dark:#111827; }
    body { background: #f8fafc; color: var(--accent-dark); font-family: 'Segoe UI', sans-serif; display:flex; align-items:center; justify-content:center; height:100vh; margin:0; }
    .card{ background:#fff; padding:28px; border-radius:12px; box-shadow:0 10px 30px rgba(16,24,40,0.08); text-align:center; max-width:720px; }
    .card h1{ margin:0 0 8px; font-size:1.6rem }
    .card p{ color:#374151; margin-bottom:18px }
    .card .actions { display:flex; gap:12px; justify-content:center }
    .btn { padding:10px 18px; border-radius:8px; font-weight:700; border:none; cursor:pointer }
    .btn-primary{ background: linear-gradient(90deg,var(--accent-red),#d97706); color:#fff }
    .btn-accent{ background: linear-gradient(90deg,var(--accent-yellow),#f59e0b); color:#111 }
    .logo{ height:56px; width:56px; margin-bottom:14px; border-radius:10px }
  </style>
</head>
<body>
  <div class="card">
  <img class="logo" src="asset/lautech_logo.png" alt="logo" style="background:#fff;padding:8px;border-radius:8px;">
    <h1>Attendance Closed</h1>
    <p>Your session was interrupted (inactivity or navigated away). Attendance for today is now closed to ensure fairness.</p>
    <div class="actions">
      <a class="btn btn-accent" href="index.php"><i class='bx bx-home'></i> Return to Home</a>
      <a class="btn btn-primary" href="support.php"><i class='bx bx-message'></i> Contact Support</a>
    </div>
  </div>
</body>
  <style>
    :root{ --accent-red:#ef4444; --accent-yellow:#facc15; --accent-dark:#111827 }
    body { background: #f8fafc; color: var(--accent-dark); font-family: 'Segoe UI', sans-serif; display:flex; align-items:center; justify-content:center; height:100vh; margin:0 }
    .card{ background:#fff; padding:28px; border-radius:12px; box-shadow:0 10px 30px rgba(16,24,40,0.08); text-align:center; max-width:720px; width:92%; }
    .logo{ height:56px; width:auto; max-width:220px; margin-bottom:14px; border-radius:10px; background:transparent; display:block; margin-left:auto; margin-right:auto }
    h1{ margin:0 0 8px; font-size:1.6rem }
    p{ color:#374151; margin-bottom:18px }
    .actions { display:flex; gap:12px; justify-content:center }
    .btn{ padding:10px 18px; border-radius:8px; font-weight:700; border:none; cursor:pointer }
    .btn-primary{ background: linear-gradient(90deg,var(--accent-red),#d97706); color:#fff }
    .btn-accent{ background: linear-gradient(90deg,var(--accent-yellow),#f59e0b); color:#111 }
    a { text-decoration:none; color:inherit }
  </style>
