<?php
/**
 * bootstrap.php
 * Shared bootstrap: config, session, PDO, auth, CSRF, flash, helpers.
 * Include at the top of every admin page.
 */

declare(strict_types=1);

// ── Configuration ────────────────────────────────────────────────────────────
define('DB_HOST',    'localhost');
define('DB_NAME',    'zakat_db');
define('DB_USER',    'root');
define('DB_PASS',    '');          // ⚠️ Change before production!
define('DB_CHARSET', 'utf8mb4');

define('APP_NAME',   'نظام إدارة الزكاة');
define('ADMIN_PATH', '/zakat/admin');  // No trailing slash

// ── Session ──────────────────────────────────────────────────────────────────
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.use_strict_mode', '1');
    ini_set('session.cookie_httponly', '1');
    session_start();
}

// ── PDO (lazy singleton) ─────────────────────────────────────────────────────
function getPDO(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $dsn = sprintf(
            'mysql:host=%s;dbname=%s;charset=%s',
            DB_HOST, DB_NAME, DB_CHARSET
        );
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    }
    return $pdo;
}

// ── Auth ─────────────────────────────────────────────────────────────────────
function requireLogin(): void
{
    if (empty($_SESSION['admin_id'])) {
        header('Location: ' . ADMIN_PATH . '/login.php');
        exit;
    }
}

function isLoggedIn(): bool
{
    return !empty($_SESSION['admin_id']);
}

function currentAdmin(): array
{
    return [
        'id'        => $_SESSION['admin_id']   ?? 0,
        'username'  => $_SESSION['admin_user'] ?? '',
        'full_name' => $_SESSION['admin_name'] ?? '',
    ];
}

// ── CSRF ─────────────────────────────────────────────────────────────────────
function csrfToken(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrfField(): string
{
    return '<input type="hidden" name="csrf_token" value="' . e(csrfToken()) . '">';
}

function verifyCsrf(): void
{
    $token   = $_POST['csrf_token'] ?? '';
    $session = $_SESSION['csrf_token'] ?? '';
    if (!$session || !hash_equals($session, $token)) {
        flashError('رمز الأمان غير صالح. يُرجى المحاولة مجدداً.');
        $back = $_SERVER['HTTP_REFERER'] ?? (ADMIN_PATH . '/dashboard.php');
        header('Location: ' . $back);
        exit;
    }
}

// ── Flash messages ───────────────────────────────────────────────────────────
function flashSuccess(string $msg): void
{
    $_SESSION['flash']['success'] = $msg;
}

function flashError(string $msg): void
{
    $_SESSION['flash']['error'] = $msg;
}

function flashInfo(string $msg): void
{
    $_SESSION['flash']['info'] = $msg;
}

/**
 * Returns flash array and clears it.
 */
function getFlash(): array
{
    $flash = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $flash;
}

/**
 * Render Bootstrap 5 flash alerts.
 */
function renderFlash(): string
{
    $flash = getFlash();
    if (empty($flash)) {
        return '';
    }
    $map = [
        'success' => 'success',
        'error'   => 'danger',
        'info'    => 'info',
    ];
    $html = '';
    foreach ($flash as $type => $msg) {
        $cls = $map[$type] ?? 'secondary';
        $html .= '<div class="alert alert-' . $cls . ' alert-dismissible fade show" role="alert">'
               . e($msg)
               . '<button type="button" class="btn-close" data-bs-dismiss="alert"></button>'
               . '</div>';
    }
    return $html;
}

// ── Helpers ───────────────────────────────────────────────────────────────────

/** HTML-escape a string. */
function e(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Redirect and exit. */
function redirect(string $url): void
{
    header('Location: ' . $url);
    exit;
}

/** Return next sequential file_number for a given type. */
function getNextFileNumber(int $typeId): int
{
    $stmt = getPDO()->prepare(
        'SELECT COALESCE(MAX(file_number), 0) + 1 FROM beneficiaries WHERE beneficiary_type_id = ?'
    );
    $stmt->execute([$typeId]);
    return (int) $stmt->fetchColumn();
}

/** Return all beneficiary types (cached per request). */
function getBeneficiaryTypes(): array
{
    static $types = null;
    if ($types === null) {
        $types = getPDO()->query('SELECT * FROM beneficiary_types ORDER BY id')->fetchAll();
    }
    return $types;
}

/** Return a single setting value. */
function getSetting(string $key, string $default = ''): string
{
    static $cache = [];
    if (!array_key_exists($key, $cache)) {
        $stmt = getPDO()->prepare('SELECT setting_value FROM settings WHERE setting_key = ?');
        $stmt->execute([$key]);
        $row = $stmt->fetchColumn();
        $cache[$key] = ($row !== false) ? $row : $default;
    }
    return $cache[$key];
}

/** Format decimal currency. Symbol is configurable via settings. */
function formatAmount(string|float $amount): string
{
    $symbol = getSetting('currency_symbol', 'ريال');
    return number_format((float) $amount, 2) . ' ' . $symbol;
}

/** Return Arabic label for a status. */
function statusLabel(string $status): string
{
    return $status === 'active' ? 'نشط' : 'موقوف';
}

/** Return Bootstrap badge class for a status. */
function statusBadge(string $status): string
{
    return $status === 'active' ? 'success' : 'secondary';
}
