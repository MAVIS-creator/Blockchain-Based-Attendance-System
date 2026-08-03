<?php
/**
 * High-Q Solid Academy Biometric Attendance System Bootstrap
 */

require_once __DIR__ . '/env-loader.php';

// Set default timezone
$timezone = getenv('APP_TIMEZONE') ?: 'Africa/Lagos';
date_default_timezone_set($timezone);

// Error reporting based on environment
if (getenv('APP_ENV') === 'production') {
    error_reporting(0);
    ini_set('display_errors', '0');
} else {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
}

// Session configuration
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.cookie_httponly', '1');
    ini_set('session.use_strict_mode', '1');
}
