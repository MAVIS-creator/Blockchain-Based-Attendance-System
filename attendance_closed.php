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
  <link rel="icon" type="image/svg+xml" href="asset/attendance-favicon.svg">
  <link rel="icon" type="image/x-icon" href="asset/favicon.ico">
  <link rel="apple-touch-icon" sizes="180x180" href="asset/apple-touch-icon.png">
  <link rel="icon" type="image/png" sizes="32x32" href="asset/favicon-32x32.png">
  <link rel="icon" type="image/png" sizes="16x16" href="asset/favicon-16x16.png">
  <link rel="manifest" href="asset/site.webmanifest">
  <title>Attendance Closed</title>
  <style>
    :root {
      --bg-top: #f4f7fb;
      --bg-bottom: #edf2f7;
      --panel: #ffffff;
      --text: #10233a;
      --muted: #5f6d7d;
      --line: #d8e1eb;
      --primary: #1f5d99;
      --primary-2: #3b7db6;
      --shadow: 0 18px 40px rgba(24, 39, 75, 0.08);
    }

    * {
      box-sizing: border-box;
    }

    body {
      margin: 0;
      font-family: "Trebuchet MS", "Segoe UI", sans-serif;
      min-height: 100vh;
      display: grid;
      place-items: center;
      color: var(--text);
      background:
        radial-gradient(circle at 12% 14%, rgba(59, 125, 182, 0.22), transparent 26%),
        radial-gradient(circle at 88% 82%, rgba(30, 142, 106, 0.14), transparent 24%),
        linear-gradient(180deg, var(--bg-top), var(--bg-bottom));
      padding: 20px;
    }

    .card {
      background: var(--panel);
      border: 1px solid var(--line);
      border-radius: 18px;
      box-shadow: var(--shadow);
      max-width: 680px;
      width: 100%;
      text-align: center;
      padding: 28px;
      animation: rise-in 0.45s ease;
    }

    @keyframes rise-in {
      from {
        opacity: 0;
        transform: translateY(10px);
      }

      to {
        opacity: 1;
        transform: translateY(0);
      }
    }

    .logo {
      height: 70px;
      width: 70px;
      object-fit: contain;
      border-radius: 14px;
      margin-bottom: 12px;
      background: #f7fbff;
      border: 1px solid var(--line);
      padding: 8px;
    }

    h1 {
      margin: 0 0 8px;
      font-size: 1.62rem;
    }

    p {
      color: var(--muted);
      line-height: 1.55;
      margin: 0 auto;
      max-width: 520px;
    }

    .actions {
      margin-top: 18px;
      display: flex;
      gap: 12px;
      justify-content: center;
      flex-wrap: wrap;
    }

    .btn {
      padding: 11px 18px;
      border-radius: 10px;
      font-weight: 700;
      border: none;
      cursor: pointer;
      width: auto;
      min-width: 148px;
      text-decoration: none;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      gap: 6px;
      transition: transform 0.18s ease, box-shadow 0.18s ease;
    }

    .btn-primary {
      background: linear-gradient(90deg, var(--primary), var(--primary-2));
      color: #fff;
      box-shadow: 0 8px 20px rgba(31, 93, 153, 0.25);
    }

    .btn-primary:hover {
      transform: translateY(-1px);
    }

    @media (max-width: 480px) {
      .card {
        padding: 20px;
        border-radius: 14px;
      }

      h1 {
        font-size: 1.28rem;
      }

      p {
        font-size: 0.95rem;
      }

      .logo {
        height: 56px;
        width: 56px;
      }

      .btn {
        min-width: 128px;
        padding: 9px 16px;
        font-size: 0.9rem;
      }
    }

    @media (max-width: 360px) {
      .card {
        padding: 15px;
      }

      .actions {
        flex-direction: column;
        gap: 8px;
      }

      .btn {
        width: 100%;
      }
    }
  </style>
</head>

<body>
  <div class="card">
    <img class="logo" src="asset/attendance-mark.svg" alt="Attendance Mark">
    <h1>Attendance Currently Closed</h1>
    <p>Attendance is not open at this time. Please return later or contact support if you believe this is an error.</p>
    <div class="actions">
      <a class="btn btn-primary" href="support.php"><i class='bx bx-message'></i> Contact Support</a>
    </div>
  </div>

  <script>
    async function autoReturnWhenAttendanceOpens() {
      try {
        const res = await fetch('status_api.php', {
          cache: 'no-store'
        });
        if (!res.ok) return;
        const data = await res.json();
        if (data && (data.checkin || data.checkout)) {
          window.location.href = 'index.php';
        }
      } catch (e) {
        // keep page stable if status endpoint is unavailable
      }
    }

    autoReturnWhenAttendanceOpens();
    setInterval(autoReturnWhenAttendanceOpens, 5000);
  </script>
</body>

</html>
