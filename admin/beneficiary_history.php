<?php
/**
 * beneficiary_history.php
 * Read-only: shows all distribution items for a beneficiary, with filters.
 */

declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
requireLogin();

$pdo = getPDO();
$id  = (int) ($_GET['id'] ?? 0);

if (!$id) {
    flashError('يُرجى تحديد مستفيد.');
    redirect(ADMIN_PATH . '/beneficiaries.php');
}

// Fetch beneficiary
$stmt = $pdo->prepare(
    'SELECT b.*, bt.name AS type_name
     FROM beneficiaries b
     JOIN beneficiary_types bt ON bt.id = b.beneficiary_type_id
     WHERE b.id = ?'
);
$stmt->execute([$id]);
$ben = $stmt->fetch();

if (!$ben) {
    flashError('المستفيد غير موجود.');
    redirect(ADMIN_PATH . '/beneficiaries.php');
}

// Filters (GET – safe)
$filterFrom = trim($_GET['from'] ?? '');
$filterTo   = trim($_GET['to']   ?? '');

$where  = ['di.beneficiary_id = ?'];
$params = [$id];

if ($filterFrom) {
    $where[]  = 'd.distribution_date >= ?';
    $params[] = $filterFrom;
}
if ($filterTo) {
    $where[]  = 'd.distribution_date <= ?';
    $params[] = $filterTo;
}

$stmt = $pdo->prepare(
    'SELECT di.*, d.title AS dist_title, d.distribution_date, bt.name AS type_name
     FROM distribution_items di
     JOIN distributions d  ON d.id  = di.distribution_id
     LEFT JOIN beneficiary_types bt ON bt.id = d.beneficiary_type_id
     WHERE ' . implode(' AND ', $where) . '
     ORDER BY d.distribution_date DESC, d.id DESC'
);
$stmt->execute($params);
$items = $stmt->fetchAll();

$totalCash = array_sum(array_column($items, 'cash_amount'));

require_once __DIR__ . '/layout.php';
renderPage('سجل المستفيد', 'beneficiaries', function() use (
    $ben, $items, $totalCash, $filterFrom, $filterTo, $id
) {
?>
<?= renderFlash() ?>

<!-- Back button (GET link – safe) -->
<div class="mb-3">
    <a href="<?= e(ADMIN_PATH) ?>/beneficiaries.php?type=<?= $ben['beneficiary_type_id'] ?>"
       class="btn btn-outline-secondary btn-sm">
        <i class="bi bi-arrow-right me-1"></i> رجوع إلى قائمة المستفيدين
    </a>
</div>

<!-- Beneficiary info -->
<div class="card mb-4">
    <div class="card-header fw-bold"><i class="bi bi-person-badge-fill me-1"></i>بيانات المستفيد</div>
    <div class="card-body">
        <div class="row g-2">
            <div class="col-md-1"><strong>رقم الملف:</strong><br><?= e((string)$ben['file_number']) ?></div>
            <div class="col-md-3"><strong>الاسم:</strong><br><?= e($ben['full_name']) ?></div>
            <div class="col-md-2"><strong>النوع:</strong><br>
                <span class="badge bg-primary"><?= e($ben['type_name']) ?></span>
            </div>
            <div class="col-md-2"><strong>رقم الهوية:</strong><br><?= e($ben['id_number'] ?? '—') ?></div>
            <div class="col-md-2"><strong>الهاتف:</strong><br><?= e($ben['phone'] ?? '—') ?></div>
            <div class="col-md-2"><strong>الحالة:</strong><br>
                <span class="badge bg-<?= statusBadge($ben['status']) ?>">
                    <?= statusLabel($ben['status']) ?>
                </span>
            </div>
        </div>
    </div>
</div>

<!-- Filters -->
<div class="card mb-3">
    <div class="card-body py-2">
        <form method="get" action="" class="row g-2 align-items-center">
            <input type="hidden" name="id" value="<?= $id ?>">
            <div class="col-auto">
                <label class="form-label mb-0 small fw-bold">من</label>
                <input type="date" name="from" class="form-control form-control-sm"
                       value="<?= e($filterFrom) ?>">
            </div>
            <div class="col-auto">
                <label class="form-label mb-0 small fw-bold">إلى</label>
                <input type="date" name="to" class="form-control form-control-sm"
                       value="<?= e($filterTo) ?>">
            </div>
            <div class="col-auto mt-3">
                <button class="btn btn-sm btn-primary" type="submit">
                    <i class="bi bi-funnel me-1"></i>تصفية
                </button>
                <a href="?id=<?= $id ?>" class="btn btn-sm btn-outline-secondary ms-1">مسح</a>
            </div>
        </form>
    </div>
</div>

<!-- History table -->
<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-clock-history me-1"></i>سجل الاستلامات
            <span class="badge bg-secondary ms-1"><?= count($items) ?></span>
        </span>
        <?php if ($items): ?>
        <span class="text-muted small">إجمالي الصرف: <strong><?= formatAmount($totalCash) ?></strong></span>
        <?php endif; ?>
    </div>
    <div class="card-body p-0">
        <?php if ($items): ?>
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead>
                    <tr>
                        <th>التاريخ</th><th>عنوان التوزيعة</th><th>التصنيف</th>
                        <th>المبلغ</th><th>التفاصيل</th><th>ملاحظات</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($items as $it): ?>
                    <tr>
                        <td><?= e($it['distribution_date']) ?></td>
                        <td class="fw-semibold">
                            <a href="<?= e(ADMIN_PATH) ?>/distributions.php?view=<?= $it['distribution_id'] ?>"
                               class="text-decoration-none"><?= e($it['dist_title']) ?></a>
                        </td>
                        <td><?= $it['type_name']
                              ? '<span class="badge bg-primary bg-opacity-75">' . e($it['type_name']) . '</span>'
                              : '—' ?></td>
                        <td><?= $it['cash_amount'] > 0 ? formatAmount($it['cash_amount']) : '—' ?></td>
                        <td class="small text-muted"><?= e($it['details_text'] ?? '') ?></td>
                        <td class="small text-muted"><?= e($it['notes'] ?? '') ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php else: ?>
        <div class="p-4 text-center text-muted">
            <i class="bi bi-inbox fs-2 d-block mb-2"></i>
            لا توجد سجلات استلام في هذه الفترة.
        </div>
        <?php endif; ?>
    </div>
</div>
<?php
});
