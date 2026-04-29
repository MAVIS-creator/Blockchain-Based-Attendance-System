<?php
// Minimal standalone index - no framework
header('Content-Type: text/html');
?>
<!DOCTYPE html>
<html>
<head>
    <title>Smart Attendance - Status</title>
    <style>
        body { font-family: Arial; margin: 40px; background: #f5f5f5; }
        .status { background: white; padding: 20px; border-radius: 5px; }
        .ok { color: green; }
        .error { color: red; }
    </style>
</head>
<body>
    <div class="status">
        <h1>Smart Attendance System</h1>
        <p class="ok">✓ PHP is working!</p>
        <p>Deployed at: <?php echo date('Y-m-d H:i:s'); ?></p>
        <p>Server: <?php echo ($_SERVER['SERVER_SOFTWARE'] ?? 'Unknown'); ?></p>
        
        <h3>Environment Check:</h3>
        <ul>
        <?php
            $env_vars = ['APP_ENV', 'APP_URL', 'SMTP_HOST'];
            foreach ($env_vars as $var) {
                $val = getenv($var);
                if ($val) {
                    echo "<li><strong>$var</strong>: <span class='ok'>✓ Set</span></li>";
                } else {
                    echo "<li><strong>$var</strong>: <span class='error'>✗ Missing</span></li>";
                }
            }
        ?>
        </ul>
        
        <p><a href="/admin/">Go to Admin</a></p>
    </div>
</body>
</html>
