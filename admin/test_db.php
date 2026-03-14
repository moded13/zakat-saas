<?php
declare(strict_types=1);

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

require_once __DIR__ . '/bootstrap.php';

echo '<pre>';

try {
    $pdo = getPDO();
    echo "DB connected successfully\n";

    $stmt = $pdo->query("SELECT DATABASE() AS db");
    $row = $stmt->fetch();
    echo "Current DB: " . ($row['db'] ?? 'unknown') . "\n";

    $stmt = $pdo->query("SELECT COUNT(*) AS total FROM admins");
    $row = $stmt->fetch();
    echo "Admins count: " . ($row['total'] ?? 0) . "\n";

} catch (Throwable $e) {
    echo 'ERROR: ' . $e->getMessage() . "\n";
    echo 'FILE: ' . $e->getFile() . "\n";
    echo 'LINE: ' . $e->getLine() . "\n";
}
echo '</pre>';