<?php
/**
 * login.php
 * Admin login page. Implements PRG.
 */

declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';

// Already logged in → go to dashboard
if (isLoggedIn()) {
    redirect(ADMIN_PATH . '/dashboard.php');
}

$error = '';

// POST: handle login
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($username === '' || $password === '') {
        $error = 'يُرجى إدخال اسم المستخدم وكلمة المرور.';
    } else {
        $stmt = getPDO()->prepare(
            'SELECT id, username, password_hash, full_name FROM admins WHERE username = ? LIMIT 1'
        );
        $stmt->execute([$username]);
        $admin = $stmt->fetch();

        if ($admin && password_verify($password, $admin['password_hash'])) {
            // Regenerate session ID on login
            session_regenerate_id(true);
            $_SESSION['admin_id']   = $admin['id'];
            $_SESSION['admin_user'] = $admin['username'];
            $_SESSION['admin_name'] = $admin['full_name'] ?? $admin['username'];
            redirect(ADMIN_PATH . '/dashboard.php');
        } else {
            $error = 'اسم المستخدم أو كلمة المرور غير صحيحة.';
        }
    }
}

$orgName = 'نظام إدارة الزكاة';
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>تسجيل الدخول — <?= e($orgName) ?></title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.rtl.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700;800&display=swap" rel="stylesheet">
<style>
body {
    font-family: 'Cairo', sans-serif;
    background: linear-gradient(135deg, #0f2c5e 0%, #1a4a8a 55%, #2563c9 100%);
    min-height: 100vh;
    display: flex;
    align-items: center;
    justify-content: center;
}
.login-card {
    width: 100%;
    max-width: 420px;
    border: none;
    border-radius: 18px;
    box-shadow: 0 8px 40px rgba(0,0,0,.25);
    padding: 40px 36px 32px;
    background: #fff;
}
.login-logo {
    width: 70px;
    height: 70px;
    background: linear-gradient(135deg, #0f2c5e, #1a4a8a);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    margin: 0 auto 18px;
    font-size: 2rem;
    color: #fff;
}
.login-title { font-size: 1.4rem; font-weight: 800; color: #0f2c5e; text-align: center; }
.login-sub   { font-size: .85rem; color: #6b7a8e; text-align: center; margin-bottom: 28px; }
.btn-login {
    background: linear-gradient(135deg, #0f2c5e, #1a4a8a);
    border: none;
    font-weight: 700;
    font-size: 1rem;
    padding: 10px;
    letter-spacing: .02em;
}
.btn-login:hover { background: linear-gradient(135deg, #0a1e40, #163d73); }
</style>
</head>
<body>
<div class="login-card">
    <div class="login-logo"><i class="bi bi-shield-lock-fill"></i></div>
    <div class="login-title"><?= e($orgName) ?></div>
    <div class="login-sub">يُرجى تسجيل الدخول للمتابعة</div>

    <?php if ($error): ?>
    <div class="alert alert-danger py-2"><?= e($error) ?></div>
    <?php endif; ?>

    <form method="post" action="">
        <?= csrfField() ?>
        <div class="mb-3">
            <label class="form-label" for="username">اسم المستخدم</label>
            <div class="input-group">
                <span class="input-group-text"><i class="bi bi-person-fill"></i></span>
                <input type="text" class="form-control" id="username" name="username"
                       value="<?= e($_POST['username'] ?? '') ?>"
                       autofocus autocomplete="username" required>
            </div>
        </div>
        <div class="mb-4">
            <label class="form-label" for="password">كلمة المرور</label>
            <div class="input-group">
                <span class="input-group-text"><i class="bi bi-lock-fill"></i></span>
                <input type="password" class="form-control" id="password" name="password"
                       autocomplete="current-password" required>
            </div>
        </div>
        <button type="submit" class="btn btn-primary btn-login w-100 text-white">
            <i class="bi bi-box-arrow-in-right me-1"></i> دخول
        </button>
    </form>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
