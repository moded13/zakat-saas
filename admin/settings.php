<?php
/**
 * settings.php
 * Minimal org settings (name, address, phone, email).
 */

declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
requireLogin();

$pdo = getPDO();

/* ── POST ─────────────────────────────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $postAction = $_POST['action'] ?? 'save_settings';

    // ── Change password ──
    if ($postAction === 'change_password') {
        $currentPw  = $_POST['current_password']  ?? '';
        $newPw      = $_POST['new_password']       ?? '';
        $confirmPw  = $_POST['confirm_password']   ?? '';
        $adminId    = currentAdmin()['id'];

        $stmt2 = $pdo->prepare('SELECT password_hash FROM admins WHERE id = ?');
        $stmt2->execute([$adminId]);
        $row = $stmt2->fetch();

        if (!$row || !password_verify($currentPw, $row['password_hash'])) {
            flashError('كلمة المرور الحالية غير صحيحة.');
        } elseif (strlen($newPw) < 6) {
            flashError('كلمة المرور الجديدة يجب أن تكون 6 أحرف على الأقل.');
        } elseif ($newPw !== $confirmPw) {
            flashError('كلمة المرور الجديدة وتأكيدها غير متطابقتين.');
        } else {
            $hash = password_hash($newPw, PASSWORD_BCRYPT);
            $pdo->prepare('UPDATE admins SET password_hash = ? WHERE id = ?')->execute([$hash, $adminId]);
            flashSuccess('تم تحديث كلمة المرور بنجاح.');
        }
        redirect(ADMIN_PATH . '/settings.php');
    }

    // ── Save org settings ──
    $fields = ['org_name', 'org_address', 'org_phone', 'org_email', 'currency_symbol'];
    $upd    = $pdo->prepare(
        'INSERT INTO settings (setting_key, setting_value) VALUES (?,?)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
    );

    foreach ($fields as $key) {
        $val = trim($_POST[$key] ?? '');
        $upd->execute([$key, $val]);
    }

    flashSuccess('تم حفظ الإعدادات بنجاح.');
    redirect(ADMIN_PATH . '/settings.php');
}

/* ── GET ──────────────────────────────────────────────────────────────────── */
$settings = [];
$rows = $pdo->query('SELECT setting_key, setting_value FROM settings')->fetchAll();
foreach ($rows as $r) {
    $settings[$r['setting_key']] = $r['setting_value'];
}

$val = fn(string $k) => $settings[$k] ?? '';

require_once __DIR__ . '/layout.php';
renderPage('الإعدادات', 'settings', function() use ($val) {
?>
<?= renderFlash() ?>

<div class="card" style="max-width:680px">
    <div class="card-header fw-bold">
        <i class="bi bi-gear-fill me-1"></i>إعدادات المنظمة
    </div>
    <div class="card-body">
        <form method="post" action="<?= e(ADMIN_PATH) ?>/settings.php">
            <?= csrfField() ?>

            <div class="mb-3">
                <label class="form-label">اسم الجمعية / اللجنة <span class="text-danger">*</span></label>
                <input type="text" name="org_name" class="form-control"
                       value="<?= e($val('org_name')) ?>" required>
            </div>
            <div class="mb-3">
                <label class="form-label">العنوان</label>
                <input type="text" name="org_address" class="form-control"
                       value="<?= e($val('org_address')) ?>">
            </div>
            <div class="row g-3 mb-3">
                <div class="col-md-6">
                    <label class="form-label">رقم الهاتف</label>
                    <input type="text" name="org_phone" class="form-control"
                           value="<?= e($val('org_phone')) ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label">البريد الإلكتروني</label>
                    <input type="email" name="org_email" class="form-control"
                           value="<?= e($val('org_email')) ?>">
                </div>
            </div>
            <div class="mb-3">
                <label class="form-label">رمز العملة</label>
                <input type="text" name="currency_symbol" class="form-control" style="max-width:160px"
                       value="<?= e($val('currency_symbol') ?: 'ريال') ?>"
                       placeholder="ريال">
                <div class="form-text">يُستخدم في عرض المبالغ وكشوف الطباعة.</div>
            </div>

            <button type="submit" class="btn btn-primary">
                <i class="bi bi-save me-1"></i>حفظ الإعدادات
            </button>
        </form>
    </div>
</div>

<!-- Change password section -->
<div class="card mt-4" style="max-width:480px">
    <div class="card-header fw-bold">
        <i class="bi bi-lock-fill me-1"></i>تغيير كلمة المرور
    </div>
    <div class="card-body">
        <form method="post" action="<?= e(ADMIN_PATH) ?>/settings.php" id="pwForm">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="change_password">
            <div class="mb-3">
                <label class="form-label">كلمة المرور الحالية</label>
                <input type="password" name="current_password" class="form-control" autocomplete="current-password">
            </div>
            <div class="mb-3">
                <label class="form-label">كلمة المرور الجديدة</label>
                <input type="password" name="new_password" class="form-control" autocomplete="new-password" minlength="6">
            </div>
            <div class="mb-3">
                <label class="form-label">تأكيد كلمة المرور الجديدة</label>
                <input type="password" name="confirm_password" class="form-control" autocomplete="new-password">
            </div>
            <button type="submit" class="btn btn-outline-danger">
                <i class="bi bi-shield-lock me-1"></i>تحديث كلمة المرور
            </button>
        </form>
    </div>
</div>
<?php
});
