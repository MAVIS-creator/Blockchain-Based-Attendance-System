<?php

/**
 * Blockchain-Based Attendance System Bootstrap
 * 
 * This file initializes the application and loads configuration
 */

// Autoload Composer dependencies
require_once __DIR__ . '/vendor/autoload.php';

// Load configuration from .env
use MavisCreator\AttendanceSystem\Config;

Config::load(__DIR__ . '/.env');

// Set timezone
$timezone = Config::get('APP_TIMEZONE', 'UTC');
date_default_timezone_set($timezone);

// Error reporting based on environment
if (Config::get('APP_ENV') === 'production') {
    error_reporting(0);
    ini_set('display_errors', '0');
} else {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
}

// Session configuration
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_samesite', 'Strict');
ini_set('session.use_strict_mode', '1');

$sessionLifetime = Config::get('SESSION_LIFETIME', 120) * 60;
ini_set('session.gc_maxlifetime', $sessionLifetime);

// Helper function to get config values easily
if (!function_exists('config')) {
    /**
     * Get configuration value
     * 
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    function config(string $key, $default = null)
    {
        return Config::get($key, $default);
    }
}

// Helper function for environment check
if (!function_exists('is_production')) {
    /**
     * Check if running in production
     * 
     * @return bool
     */
    function is_production(): bool
    {
        return Config::get('APP_ENV') === 'production';
    }
}

// Helper function for debug mode check
if (!function_exists('is_debug')) {
    /**
     * Check if debug mode is enabled
     * 
     * @return bool
     */
    function is_debug(): bool
    {
        return Config::get('APP_DEBUG', 'false') === 'true';
    }
}
