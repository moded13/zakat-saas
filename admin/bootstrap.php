<?php
declare(strict_types=1);

// DEBUG (تجريب)
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

// ── DB CONFIG ────────────────────────────────────────────────────────────────
define('DB_HOST',    'localhost');
define('DB_PORT',    '3306');
define('DB_NAME',    'zaka');
define('DB_USER',    'zaka');
define('DB_PASS',    'Tvvcrtv1610@');
define('DB_CHARSET', 'utf8mb4');

define('APP_NAME',   'نظام إدارة الزكاة');
define('ADMIN_PATH', '/zaka/admin'); // بدون / بالنهاية

// ── Session ──────────────────────────────────────────────────────────────────
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.use_strict_mode', '1');
    ini_set('session.cookie_httponly', '1');
    session_start();
}

// ── PDO ──────────────────────────────────────────────────────────────────────
function getPDO(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=%s',
            DB_HOST,
            DB_PORT,
            DB_NAME,
            DB_CHARSET
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
function isLoggedIn(): bool
{
    return !empty($_SESSION['admin_id']);
}

function requireLogin(): void
{
    if (!isLoggedIn()) {
        header('Location: ' . ADMIN_PATH . '/login.php');
        exit;
    }
}

function currentAdmin(): array
{
    return [
        'id'        => (int)($_SESSION['admin_id'] ?? 0),
        'username'  => (string)($_SESSION['admin_user'] ?? ''),
        'full_name' => (string)($_SESSION['admin_name'] ?? ''),
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

// ── Flash ────────────────────────────────────────────────────────────────────
function flashSuccess(string $msg): void { $_SESSION['flash']['success'] = $msg; }
function flashError(string $msg): void   { $_SESSION['flash']['error']   = $msg; }
function flashInfo(string $msg): void    { $_SESSION['flash']['info']    = $msg; }

function getFlash(): array
{
    $flash = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $flash;
}

function renderFlash(): string
{
    $flash = getFlash();
    if (!$flash) return '';

    $map = ['success' => 'success', 'error' => 'danger', 'info' => 'info'];
    $html = '';
    foreach ($flash as $type => $msg) {
        $cls = $map[$type] ?? 'secondary';
        $html .= '<div class="alert alert-' . $cls . ' alert-dismissible fade show" role="alert">'
              . e((string)$msg)
              . '<button type="button" class="btn-close" data-bs-dismiss="alert"></button>'
              . '</div>';
    }
    return $html;
}

// ── Helpers ──────────────────────────────────────────────────────────────────
function e(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function redirect(string $url): void
{
    header('Location: ' . $url);
    exit;
}

function getSetting(string $key, string $default = ''): string
{
    static $cache = [];
    if (!array_key_exists($key, $cache)) {
        $stmt = getPDO()->prepare('SELECT setting_value FROM settings WHERE setting_key = ?');
        $stmt->execute([$key]);
        $row = $stmt->fetchColumn();
        $cache[$key] = ($row !== false) ? (string)$row : $default;
    }
    return $cache[$key];
}

function formatAmount(string|float $amount): string
{
    $symbol = getSetting('currency_symbol', 'ريال');
    return number_format((float)$amount, 2) . ' ' . $symbol;
}

function statusLabel(string $status): string
{
    return $status === 'active' ? 'نشط' : 'موقوف';
}

function statusBadge(string $status): string
{
    return $status === 'active' ? 'success' : 'secondary';
}

// ── Data helpers ─────────────────────────────────────────────────────────────
function getNextFileNumber(int $typeId): int
{
    $stmt = getPDO()->prepare(
        'SELECT COALESCE(MAX(file_number), 0) + 1
         FROM beneficiaries
         WHERE beneficiary_type_id = ?'
    );
    $stmt->execute([$typeId]);
    return (int)$stmt->fetchColumn();
}

/**
 * ✅ يرجع name_ar + alias name لتوافق كل الصفحات القديمة (المنسدلات وغيرها)
 */
function getBeneficiaryTypes(): array
{
    static $types = null;
    if ($types === null) {
        $types = getPDO()->query(
            "SELECT
                id,
                name_ar,
                name_ar AS name,
                slug,
                is_active,
                sort_order
             FROM beneficiary_types
             ORDER BY sort_order, id"
        )->fetchAll();
    }
    return $types;
}

function renumberBeneficiariesForType(int $typeId): void
{
    $pdo = getPDO();

    $stmt = $pdo->prepare(
        'SELECT id
         FROM beneficiaries
         WHERE beneficiary_type_id = ?
         ORDER BY file_number ASC, id ASC'
    );
    $stmt->execute([$typeId]);
    $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);

    $ownsTx = false;
    if (!$pdo->inTransaction()) {
        $pdo->beginTransaction();
        $ownsTx = true;
    }

    try {
        $upd = $pdo->prepare('UPDATE beneficiaries SET file_number = ? WHERE id = ?');
        $n = 1;
        foreach ($ids as $bid) {
            $upd->execute([$n, (int)$bid]);
            $n++;
        }

        if ($ownsTx) {
            $pdo->commit();
        }
    } catch (Throwable $e) {
        if ($ownsTx && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}