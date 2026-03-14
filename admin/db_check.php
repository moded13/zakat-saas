<?php
declare(strict_types=1);

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

$host = 'localhost';
$db   = 'zaka';
$user = 'zaka';
$pass = '123@123';
$charset = 'utf8mb4';

echo '<pre>';

try {
    $dsn = "mysql:host=$host;dbname=$db;charset=$charset";
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);

    echo "SUCCESS: DB connected\n";

    $stmt = $pdo->query("SELECT DATABASE() AS db");
    $row = $stmt->fetch();
    print_r($row);

} catch (Throwable $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
echo '</pre>';