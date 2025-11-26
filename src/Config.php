<?php

namespace MavisCreator\AttendanceSystem;

/**
 * Attendance System Configuration
 * 
 * Central configuration class for the attendance system
 */
class Config
{
    private static $config = [];
    
    /**
     * Load configuration from .env file
     * 
     * @param string $envPath Path to .env file
     * @return void
     */
    public static function load(string $envPath = null)
    {
        if ($envPath === null) {
            $envPath = dirname(__DIR__) . '/.env';
        }
        
        if (!file_exists($envPath)) {
            return;
        }
        
        $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        
        foreach ($lines as $line) {
            $line = trim($line);
            
            // Skip comments and empty lines
            if (empty($line) || strpos($line, '#') === 0) {
                continue;
            }
            
            // Parse key=value
            if (strpos($line, '=') !== false) {
                list($key, $value) = explode('=', $line, 2);
                $key = trim($key);
                $value = trim($value, " \t\n\r\0\x0B\"'");
                
                self::$config[$key] = $value;
                
                // Set as environment variable if not already set
                if (!getenv($key)) {
                    putenv("$key=$value");
                }
            }
        }
    }
    
    /**
     * Get configuration value
     * 
     * @param string $key Configuration key
     * @param mixed $default Default value if key not found
     * @return mixed
     */
    public static function get(string $key, $default = null)
    {
        if (isset(self::$config[$key])) {
            return self::$config[$key];
        }
        
        $env = getenv($key);
        if ($env !== false) {
            return $env;
        }
        
        return $default;
    }
    
    /**
     * Set configuration value
     * 
     * @param string $key Configuration key
     * @param mixed $value Configuration value
     * @return void
     */
    public static function set(string $key, $value)
    {
        self::$config[$key] = $value;
        putenv("$key=$value");
    }
    
    /**
     * Get all configuration
     * 
     * @return array
     */
    public static function all(): array
    {
        return self::$config;
    }
    
    /**
     * Check if configuration key exists
     * 
     * @param string $key Configuration key
     * @return bool
     */
    public static function has(string $key): bool
    {
        return isset(self::$config[$key]) || getenv($key) !== false;
    }
}
