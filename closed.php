<?php
session_start();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <title>Attendance Closed</title>
  <style>
    body {
      background-color: #0c1a2b;
      color: #ffffff;
      font-family: 'Segoe UI', sans-serif;
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      height: 100vh;
      margin: 0;
      text-align: center;
    }
    h1 {
      font-size: 2rem;
      margin-bottom: 20px;
    }
    p {
      font-size: 1rem;
      margin-bottom: 30px;
    }
    a {
      display: inline-block;
      padding: 12px 25px;
      background-color: #00eaff;
      color: #001a33;
      font-weight: bold;
      text-decoration: none;
      border-radius: 8px;
      transition: 0.3s;
    }
    a:hover {
      background-color: #00c5cc;
      transform: translateY(-2px);
    }
  </style>
</head>
<body>
  <h1>Attendance Closed</h1>
  <p>You were inactive or navigated away from this page. For fairness, your attendance is now closed for today.</p>
  <a href="support.php">💬 Contact Support</a>
</body>
</html>
