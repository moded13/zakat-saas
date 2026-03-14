<?php
/**
 * beneficiary_history.php
 * Show beneficiary profile + full distribution history.
 */

declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
requireLogin();

$pdo = getPDO();
$id  = (int)($_GET['id'] ?? 0);

if ($id <= 0) {
    flashError('رقم المستفيد غير صحيح.');
    redirect(ADMIN_PATH . '/beneficiaries.php');
}

// Beneficiary
$stmt = $pdo->prepare(
    'SELECT b.*, bt.name_ar AS type_name
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

// History rows
$stmt = $pdo->prepare(
    'SELECT
        d.id AS distribution_id,
        d.title,
        d.distribution_date,
        bt.name_ar AS distribution_type,
        di.cash_amount,
        di.details_text,
        di.notes
     FROM distribution_items di
     JOIN distributions d ON d.id = di.distribution_id
     LEFT JOIN beneficiary_types bt ON bt.id = d.beneficiary_type_id
     WHERE di.beneficiary_id = ?
     ORDER BY d.distribution_date DESC, d.id DESC'
);
$stmt->execute([$id]);
$rows = $stmt->fetchAll();

$totalCash = array_sum(array_map(fn($r) => (float)($r['cash_amount'] ?? 0), $rows));

require_once __DIR__ . '/layout.php';
renderPage('سجل المستفيد', 'beneficiaries', function() use ($ben, $rows, $totalCash, $id) {
?>
<?= renderFlash() ?>

<div class="mb-3 d-flex gap-2 flex-wrap">
    <a href="<?= e(ADMIN_PATH) ?>/beneficiaries.php?type=<?= (int)$ben['beneficiary_type_id'] ?>" class="btn btn-outline-secondary btn-sm">
        <i class="bi bi-arrow-right me-1"></i> رجوع
    </a>

    <a href="<?= e(ADMIN_PATH) ?>/print_beneficiary_history.php?id=<?= (int)$id ?>" target="_blank"
       class="btn btn-outline-primary btn-sm">
        <i class="bi bi-printer me-1"></i> طباعة السجل
    </a>
</div>

<div class="card mb-3">
    <div class="card-header fw-bold">
        <i class="bi bi-person-vcard me-1"></i>بيانات المستفيد
    </div>
    <div class="card-body">
        <div class="row g-2">
            <div class="col-md-2"><strong>رقم الملف:</strong> <?= e((string)$ben['file_number']) ?></div>
            <div class="col-md-4"><strong>الاسم:</strong> <?= e((string)$ben['full_name']) ?></div>
            <div class="col-md-3"><strong>النوع:</strong> <?= e((string)$ben['type_name']) ?></div>
            <div class="col-md-3"><strong>الهوية:</strong> <?= e((string)($ben['id_number'] ?? '—')) ?></div>
            <div class="col-md-3"><strong>الهاتف:</strong> <?= e((string)($ben['phone'] ?? '—')) ?></div>
            <div class="col-md-3"><strong>راتب نقدي شهري:</strong> <?= $ben['monthly_cash'] !== null ? e(number_format((float)$ben['monthly_cash'],2)) : '—' ?></div>
            <div class="col-md-6"><strong>المادة/الوصف الافتراضي:</strong> <?= e((string)($ben['default_item'] ?? '—')) ?></div>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header d-flex align-items-center justify-content-between">
        <span class="fw-bold">
            <i class="bi bi-clock-history me-1"></i>سجل الاستلام
            <span class="badge bg-secondary ms-1"><?= count($rows) ?></span>
        </span>

        <span class="fw-bold">
            إجمالي النقدي: <?= formatAmount($totalCash) ?>
        </span>
    </div>

    <div class="card-body p-0">
        <?php if (!empty($rows)): ?>
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>التاريخ</th>
                        <th>التوزيعة</th>
                        <th>تصنيف التوزيعة</th>
                        <th>المبلغ النقدي</th>
                        <th>التفاصيل</th>
                        <th>ملاحظات</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $i => $r): ?>
                    <tr>
                        <td><?= $i+1 ?></td>
                        <td><?= e((string)$r['distribution_date']) ?></td>
                        <td class="fw-semibold"><?= e((string)$r['title']) ?></td>
                        <td><?= $r['distribution_type'] ? '<span class="badge bg-primary">'.e((string)$r['distribution_type']).'</span>' : '—' ?></td>
                        <td><?= ((float)$r['cash_amount'] > 0) ? formatAmount((float)$r['cash_amount']) : '—' ?></td>
                        <td class="small text-muted"><?= e((string)($r['details_text'] ?? '')) ?></td>
                        <td class="small text-muted"><?= e((string)($r['notes'] ?? '')) ?></td>
                        <td>
                            <a class="btn btn-sm btn-outline-secondary"
                               href="<?= e(ADMIN_PATH) ?>/print_distribution.php?id=<?= (int)$r['distribution_id'] ?>"
                               target="_blank" title="طباعة التوزيعة">
                                <i class="bi bi-printer"></i>
                            </a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr class="table-light fw-bold">
                        <td colspan="4">الإجمالي</td>
                        <td><?= formatAmount($totalCash) ?></td>
                        <td colspan="3"></td>
                    </tr>
                </tfoot>
            </table>
        </div>
        <?php else: ?>
            <div class="p-4 text-center text-muted">لا يوجد سجل توزيعات لهذا المستفيد بعد.</div>
        <?php endif; ?>
    </div>
</div>
<?php
});