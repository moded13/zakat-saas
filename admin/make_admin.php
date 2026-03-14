<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

echo '<pre>';

try {
    $pdo = getPDO();

    $username = 'admin';
    $password = '123@123';
    $displayName = 'مدير النظام';
    $hash = password_hash($password, PASSWORD_DEFAULT);

    $stmt = $pdo->prepare("SELECT id FROM admins WHERE username = ? LIMIT 1");
    $stmt->execute([$username]);
    $existing = $stmt->fetch();

    if ($existing) {
        $stmt = $pdo->prepare("
            UPDATE admins
            SET password_hash = ?, display_name = ?, is_active = 1, updated_at = NOW()
            WHERE username = ?
        ");
        $stmt->execute([$hash, $displayName, $username]);
        echo "Admin updated successfully";
    } else {
        $stmt = $pdo->prepare("
            INSERT INTO admins (username, password_hash, display_name, is_active, created_at, updated_at)
            VALUES (?, ?, ?, 1, NOW(), NOW())
        ");
        $stmt->execute([$username, $hash, $displayName]);
        echo "Admin inserted successfully";
    }

} catch (Throwable $e) {
    echo "ERROR: " . $e->getMessage();
}

echo '</pre>';