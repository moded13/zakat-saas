<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

if (isLoggedIn()) {
    redirect(ADMIN_PATH . '/dashboard.php');
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        verifyCsrf();

        $username = trim($_POST['username'] ?? '');
        $password = (string)($_POST['password'] ?? '');

        if ($username === '' || $password === '') {
            $error = 'يرجى إدخال اسم المستخدم وكلمة المرور.';
        } else {
            $stmt = getPDO()->prepare("
                SELECT id, username, password_hash, display_name, is_active
                FROM admins
                WHERE username = ?
                LIMIT 1
            ");
            $stmt->execute([$username]);
            $admin = $stmt->fetch();

            if (
                $admin &&
                (int)$admin['is_active'] === 1 &&
                password_verify($password, (string)$admin['password_hash'])
            ) {
                session_regenerate_id(true);
                $_SESSION['admin_id']   = (int)$admin['id'];
                $_SESSION['admin_user'] = (string)$admin['username'];
                $_SESSION['admin_name'] = (string)($admin['display_name'] ?: $admin['username']);

                redirect(ADMIN_PATH . '/dashboard.php');
            } else {
                $error = 'اسم المستخدم أو كلمة المرور غير صحيحة.';
            }
        }
    } catch (Throwable $e) {
        $error = 'ERROR: ' . $e->getMessage();
    }
}
?>
<!doctype html>
<html lang="ar" dir="rtl">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>تسجيل الدخول</title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.rtl.min.css">
</head>
<body class="bg-light">
  <div class="container py-5" style="max-width:480px">
    <div class="card shadow-sm">
      <div class="card-body p-4">
        <h4 class="mb-3 text-center">تسجيل الدخول</h4>

        <?php if ($error): ?>
          <div class="alert alert-danger"><?= e($error) ?></div>
        <?php endif; ?>

        <form method="post">
          <?= csrfField() ?>

          <div class="mb-3">
            <label class="form-label">اسم المستخدم</label>
            <input class="form-control" name="username" value="<?= e($_POST['username'] ?? 'admin') ?>" required>
          </div>

          <div class="mb-3">
            <label class="form-label">كلمة المرور</label>
            <input type="password" class="form-control" name="password" required>
          </div>

          <button class="btn btn-primary w-100">دخول</button>
        </form>
      </div>
    </div>
  </div>
</body>
</html>