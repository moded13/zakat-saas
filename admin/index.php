<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

// If logged in go dashboard, otherwise go login
if (isLoggedIn()) {
    redirect(ADMIN_PATH . '/dashboard.php');
}

redirect(ADMIN_PATH . '/login.php');