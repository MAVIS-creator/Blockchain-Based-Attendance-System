<?php
http_response_code(404);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>404 - Page Not Found</title>
  <style>
    :root {
      --primary: #4361ee;
      --bg: #f8fafc;
      --surface: #ffffff;
      --text: #0f172a;
      --muted: #64748b;
    }
    * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Inter', system-ui, sans-serif; }
    body {
      background: var(--bg); color: var(--text); display: flex; flex-direction: column; align-items: center; justify-content: center; min-height: 100vh; text-align: center; padding: 2rem;
    }
    .wrapper {
      background: var(--surface); padding: 3rem; border-radius: 16px; box-shadow: 0 4px 6px -1px rgb(0 0 0 / 0.1), 0 2px 4px -2px rgb(0 0 0 / 0.1); max-width: 500px; width: 100%;
    }
    h1 { font-size: 4rem; color: var(--primary); margin-bottom: 1rem; }
    h2 { font-size: 1.5rem; margin-bottom: 1rem; font-weight: 600; }
    p { color: var(--muted); margin-bottom: 2rem; line-height: 1.5; }
    a { display: inline-block; background: var(--primary); color: white; text-decoration: none; padding: 0.75rem 1.5rem; border-radius: 8px; font-weight: 500; transition: opacity 0.2s; }
    a:hover { opacity: 0.9; }
  </style>
</head>
<body>
  <div class="wrapper">
    <h1>404</h1>
    <h2>Page Not Found</h2>
    <p>Oops! The page you're looking for doesn't exist or has been moved.</p>
    <a href="index.php">Return to Homepage</a>
  </div>
</body>
</html>
