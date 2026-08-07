<?php
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/env.php';

load_env();

// Railway / Render / generic production environment support
$databaseUrl = env('DATABASE_URL') ?: env('MYSQL_URL') ?: env('RAILWAY_DATABASE_URL') ?: env('RENDER_DATABASE_URL');
if ($databaseUrl) {
    $dbopts = parse_url($databaseUrl);
    $host     = $dbopts['host'] ?? 'localhost';
    $dbname   = rawurldecode(ltrim($dbopts['path'] ?? '', '/'));
    $username = rawurldecode($dbopts['user'] ?? 'root');
    $password = rawurldecode($dbopts['pass'] ?? '');
    $port     = $dbopts['port'] ?? 5432;
    $scheme   = $dbopts['scheme'] ?? 'mysql';
    if ($scheme === 'postgres' || $scheme === 'postgresql') {
        $dsn = "pgsql:host=$host;port=$port;dbname=$dbname";
    } else {
        $dsn = "mysql:host=$host;port=$port;dbname=$dbname;charset=utf8mb4";
    }
} else {
    // Local XAMPP / manual environment variable fallbacks
    $host     = env('DB_HOST') ?: env('MYSQL_HOST') ?: env('MYSQLHOST') ?: 'localhost';
    $dbname   = env('DB_NAME') ?: env('DB_DATABASE') ?: env('MYSQL_DATABASE') ?: env('MYSQLDATABASE') ?: 'pos_system';
    $username = env('DB_USER') ?: env('DB_USERNAME') ?: env('MYSQL_USER') ?: env('MYSQLUSER') ?: 'root';
    $password = env('DB_PASS') ?: env('DB_PASSWORD') ?: env('MYSQL_PASSWORD') ?: env('MYSQLPASSWORD') ?: '';
    $port     = env('DB_PORT') ?: env('MYSQL_PORT') ?: env('MYSQLPORT') ?: 3306;
}

try {
    $pdo = new PDO($dsn ?? "mysql:host=$host;port=$port;dbname=$dbname;charset=utf8mb4", $username, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_TIMEOUT => 5,
    ]);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    app_log('Database connection failed: ' . $e->getMessage());
    $pdo = null;

    // Render a friendly maintenance page instead of white-screening on
    // every page that touches the database.
    if (PHP_SAPI !== 'cli') {
        http_response_code(503);
        if (!headers_sent()) {
            header('Retry-After: 120');
        }
        include __DIR__ . '/../maintenance.php';
        exit;
    }
}
