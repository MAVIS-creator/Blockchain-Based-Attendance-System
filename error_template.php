<?php
$errorCode = isset($errorCode) ? (int)$errorCode : 500;
$errorTitle = isset($errorTitle) ? (string)$errorTitle : 'Server Error';
$errorMessage = isset($errorMessage) ? (string)$errorMessage : 'Something went wrong. Please try again later.';
$homeHref = isset($homeHref) ? (string)$homeHref : '/index.php';
$homeLabel = isset($homeLabel) ? (string)$homeLabel : 'Return to Homepage';

http_response_code($errorCode);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?php echo htmlspecialchars($errorCode . ' - ' . $errorTitle, ENT_QUOTES, 'UTF-8'); ?></title>
  <style>
    :root {
      --primary: #4361ee;
      --bg: #f8fafc;
      --surface: #ffffff;
      --text: #0f172a;
      --muted: #64748b;
      --border: #e2e8f0;
    }
    * { margin: 0; padding: 0; box-sizing: border-box; font-family: Inter, system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; }
    body {
      min-height: 100vh;
      display: flex;
      align-items: center;
      justify-content: center;
      background: linear-gradient(180deg, #f8fafc 0%, #eef2ff 100%);
      color: var(--text);
      padding: 2rem;
    }
    .wrapper {
      width: min(100%, 560px);
      background: var(--surface);
      border: 1px solid var(--border);
      border-radius: 20px;
      box-shadow: 0 20px 60px rgba(15, 23, 42, 0.08);
      padding: 3rem 2.5rem;
      text-align: center;
    }
    .code {
      font-size: clamp(3rem, 8vw, 5rem);
      line-height: 1;
      font-weight: 800;
      color: var(--primary);
      letter-spacing: -0.04em;
      margin-bottom: 1rem;
    }
    h1 {
      font-size: clamp(1.5rem, 4vw, 2rem);
      margin-bottom: 0.75rem;
    }
    p {
      color: var(--muted);
      line-height: 1.65;
      margin-bottom: 1.75rem;
    }
    a {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      gap: 0.5rem;
      padding: 0.85rem 1.4rem;
      border-radius: 999px;
      background: var(--primary);
      color: #fff;
      text-decoration: none;
      font-weight: 600;
      transition: transform 0.2s ease, opacity 0.2s ease;
    }
    a:hover { transform: translateY(-1px); opacity: 0.95; }
  </style>
</head>
<body>
  <main class="wrapper" role="main" aria-labelledby="error-title">
    <div class="code"><?php echo htmlspecialchars((string)$errorCode, ENT_QUOTES, 'UTF-8'); ?></div>
    <h1 id="error-title"><?php echo htmlspecialchars($errorTitle, ENT_QUOTES, 'UTF-8'); ?></h1>
    <p><?php echo htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8'); ?></p>
    <a href="<?php echo htmlspecialchars($homeHref, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($homeLabel, ENT_QUOTES, 'UTF-8'); ?></a>
  </main>
</body>
</html>
