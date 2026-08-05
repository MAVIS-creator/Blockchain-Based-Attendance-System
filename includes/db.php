<?php
/**
 * Database Connection & Initialization Helper
 * High-Q Solid Academy Biometric Attendance System
 */

// Load environment variables via Composer Dotenv or native fallback
function load_environment(): void {
    static $loaded = false;
    if ($loaded) return;

    $autoload = __DIR__ . '/../vendor/autoload.php';
    if (file_exists($autoload)) {
        require_once $autoload;
        if (class_exists('Dotenv\Dotenv')) {
            $dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
            $dotenv->safeLoad();
        }
    }

    // Fallback native parser if $_ENV or getenv is empty
    $envFile = __DIR__ . '/../.env';
    if (file_exists($envFile)) {
        $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line) || strpos($line, '#') === 0) continue;
            if (strpos($line, '=') !== false) {
                list($key, $val) = explode('=', $line, 2);
                $key = trim($key);
                $val = trim(trim($val), '"\'');
                if (!getenv($key)) {
                    putenv("$key=$val");
                    $_ENV[$key] = $val;
                    $_SERVER[$key] = $val;
                }
            }
        }
    }
    $loaded = true;
}

load_environment();

function get_db_connection(): PDO {
    static $pdo = null;

    if ($pdo !== null) {
        return $pdo;
    }

    $host = getenv('DB_HOST') ?: ($_ENV['DB_HOST'] ?? '127.0.0.1');
    $port = getenv('DB_PORT') ?: ($_ENV['DB_PORT'] ?? '3306');
    $db   = getenv('DB_NAME') ?: ($_ENV['DB_NAME'] ?? 'highqsol_highq');
    $user = getenv('DB_USER') ?: ($_ENV['DB_USER'] ?? 'root');
    $pass = getenv('DB_PASS') !== false ? getenv('DB_PASS') : ($_ENV['DB_PASS'] ?? '');

    try {
        // Try connecting directly to the database
        $dsn = "mysql:host=$host;port=$port;dbname=$db;charset=utf8mb4";
        $pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    } catch (PDOException $e) {
        // If database does not exist, connect without dbname and create it
        if ($e->getCode() == 1049 || strpos($e->getMessage(), 'Unknown database') !== false) {
            try {
                $rawDsn = "mysql:host=$host;port=$port;charset=utf8mb4";
                $rawPdo = new PDO($rawDsn, $user, $pass);
                $rawPdo->exec("CREATE DATABASE IF NOT EXISTS `$db` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
                
                // Reconnect to newly created database
                $dsn = "mysql:host=$host;port=$port;dbname=$db;charset=utf8mb4";
                $pdo = new PDO($dsn, $user, $pass, [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                ]);
            } catch (PDOException $e2) {
                die("Database Initialization Error: " . $e2->getMessage());
            }
        } else {
            die("Database Connection Error: " . $e->getMessage());
        }
    }

    // Auto-run schema migrations if tables do not exist
    init_db_tables($pdo);

    return $pdo;
}

function init_db_tables(PDO $pdo): void {
    static $initialized = false;
    if ($initialized) return;

    $schemaFile = __DIR__ . '/../database/schema.sql';
    if (file_exists($schemaFile)) {
        $sql = file_get_contents($schemaFile);
        try {
            $pdo->exec($sql);
        } catch (PDOException $e) {
            // Ignore error if schema partially exists or multiple statement issues
        }
    }
    $initialized = true;
}
