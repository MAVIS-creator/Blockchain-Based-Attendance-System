<?php
/**
 * Load Azure App Settings into PHP environment
 * App Settings in Azure are available via $_SERVER and $_ENV
 * but the app expects getenv(), so we'll copy them over
 */

// Load all $_SERVER variables that look like app settings (uppercase with no PHP_ prefix)
foreach ($_SERVER as $key => $value) {
    if (strpos($key, 'APPSETTING_') === 0) {
        // Remove APPSETTING_ prefix
        $envKey = substr($key, 11);
        putenv("$envKey=$value");
    }
}

// Also try to load from $_ENV if it has values
foreach ($_ENV as $key => $value) {
    if (!empty($value) && strpos($key, 'APPSETTING_') === 0) {
        $envKey = substr($key, 11);
        putenv("$envKey=$value");
    }
}

// If no env settings found, try looking for .env file
if (!getenv('APP_ENV')) {
    if (file_exists(__DIR__ . '/.env')) {
        $lines = file(__DIR__ . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            if (strpos($line, '=') !== false && strpos($line, '#') !== 0) {
                list($key, $value) = explode('=', $line, 2);
                $key = trim($key);
                $value = trim($value, '\'"');
                if (!empty($key)) {
                    putenv("$key=$value");
                }
            }
        }
    }
}

// Log what we loaded for debugging
if (getenv('APP_DEBUG') === 'true' || getenv('APP_DEBUG') === '1') {
    error_log("APP_ENV: " . getenv('APP_ENV'));
    error_log("SMTP_HOST: " . getenv('SMTP_HOST'));
}

?>
