<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

echo '<pre>';
echo 'bootstrap loaded' . "\n";
echo 'function getPDO exists: ' . (function_exists('getPDO') ? 'YES' : 'NO') . "\n";
echo 'function isLoggedIn exists: ' . (function_exists('isLoggedIn') ? 'YES' : 'NO') . "\n";
echo 'function redirect exists: ' . (function_exists('redirect') ? 'YES' : 'NO') . "\n";
echo '</pre>';