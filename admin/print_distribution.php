<?php
/**
 * print_distribution.php
 * Print-friendly distribution sheet (no sidebar, clean layout).
 */

declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
requireLogin();

$pdo = getPDO();
$id  = (int) ($_GET['id'] ?? 0);

if (!$id) {
    exit('رقم التوزيعة مطلوب.');
}

$dist = $pdo->prepare(
    'SELECT d.*, bt.name AS type_name, a.full_name AS created_by_name
     FROM distributions d
     LEFT JOIN beneficiary_types bt ON bt.id = d.beneficiary_type_id
     LEFT JOIN admins a ON a.id = d.created_by
     WHERE d.id = ?'
);
$dist->execute([$id]);
$dist = $dist->fetch();

if (!$dist) {
    exit('التوزيعة غير موجودة.');
}

$items = $pdo->prepare(
    'SELECT di.*, b.full_name, b.file_number, b.id_number, b.phone, bt.name AS type_name
     FROM distribution_items di
     JOIN beneficiaries b ON b.id = di.beneficiary_id
     JOIN beneficiary_types bt ON bt.id = b.beneficiary_type_id
     WHERE di.distribution_id = ?
     ORDER BY b.beneficiary_type_id, b.file_number'
);
$items->execute([$id]);
$items = $items->fetchAll();

$totalCash = array_sum(array_column($items, 'cash_amount'));
$orgName   = getSetting('org_name', APP_NAME);
$orgPhone  = getSetting('org_phone', '');
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<title>كشف توزيع #<?= $id ?> — <?= e($orgName) ?></title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.rtl.min.css">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700;800&display=swap" rel="stylesheet">
<style>
body { font-family: 'Cairo', sans-serif; font-size: 13px; background: #fff; }
.print-header { border-bottom: 3px double #0f2c5e; margin-bottom: 18px; padding-bottom: 12px; }
.org-name { font-size: 1.4rem; font-weight: 800; color: #0f2c5e; }
.dist-title { font-size: 1.1rem; font-weight: 700; }
.table th { background: #e8eef7; font-weight: 700; font-size: .85rem; }
.table td { font-size: .85rem; vertical-align: middle; }
.sig-area { margin-top: 40px; border-top: 1px solid #ccc; padding-top: 10px; }
.no-print { display: flex; gap: 8px; margin-bottom: 16px; }
@media print {
    .no-print { display: none !important; }
    body { margin: 0; padding: 10px; }
    table { page-break-inside: auto; }
    tr { page-break-inside: avoid; }
}
</style>
</head>
<body class="p-3">

<div class="no-print">
    <button onclick="window.print()" class="btn btn-primary btn-sm">
        <i class="bi bi-printer me-1"></i> طباعة
    </button>
    <a href="javascript:history.back()" class="btn btn-outline-secondary btn-sm">رجوع</a>
</div>

<!-- Header -->
<div class="print-header text-center">
    <div class="org-name"><?= e($orgName) ?></div>
    <?php if ($orgPhone): ?>
    <div class="text-muted small">هاتف: <?= e($orgPhone) ?></div>
    <?php endif; ?>
    <div class="dist-title mt-1">كشف توزيع — <?= e($dist['title']) ?></div>
</div>

<!-- Distribution info -->
<div class="row g-2 mb-3 small">
    <div class="col-auto"><strong>رقم الكشف:</strong> <?= e((string)$id) ?></div>
    <div class="col-auto">|</div>
    <div class="col-auto"><strong>التاريخ:</strong> <?= e($dist['distribution_date']) ?></div>
    <div class="col-auto">|</div>
    <div class="col-auto"><strong>التصنيف:</strong> <?= e($dist['type_name'] ?? 'عام') ?></div>
    <div class="col-auto">|</div>
    <div class="col-auto"><strong>عدد المستفيدين:</strong> <?= count($items) ?></div>
    <div class="col-auto">|</div>
    <div class="col-auto"><strong>إجمالي الصرف:</strong> <?= formatAmount($totalCash) ?></div>
</div>

<?php if ($dist['notes']): ?>
<div class="mb-2 small text-muted"><strong>ملاحظات:</strong> <?= e($dist['notes']) ?></div>
<?php endif; ?>

<!-- Items table -->
<table class="table table-bordered table-sm">
    <thead>
        <tr>
            <th style="width:45px">م</th>
            <th style="width:70px">رقم الملف</th>
            <th>الاسم الكامل</th>
            <th style="width:80px">نوع المستفيد</th>
            <th style="width:100px">رقم الهوية</th>
            <th style="width:100px">المبلغ النقدي</th>
            <th>التفاصيل</th>
            <th style="width:100px">التوقيع / الاستلام</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($items as $i => $it): ?>
        <tr>
            <td class="text-center"><?= $i + 1 ?></td>
            <td class="fw-bold text-center"><?= e((string)$it['file_number']) ?></td>
            <td><?= e($it['full_name']) ?></td>
            <td class="small"><?= e($it['type_name']) ?></td>
            <td class="text-muted small"><?= e($it['id_number'] ?? '') ?></td>
            <td class="text-center"><?= $it['cash_amount'] > 0 ? formatAmount($it['cash_amount']) : '—' ?></td>
            <td class="small text-muted"><?= e($it['details_text'] ?? '') ?><?= $it['notes'] ? (' | ' . e($it['notes'])) : '' ?></td>
            <td></td>
        </tr>
        <?php endforeach; ?>
    </tbody>
    <tfoot>
        <tr class="fw-bold table-light">
            <td colspan="5" class="text-center">الإجمالي</td>
            <td class="text-center"><?= formatAmount($totalCash) ?></td>
            <td colspan="2"></td>
        </tr>
    </tfoot>
</table>

<!-- Signatures -->
<div class="row sig-area small">
    <div class="col-4 text-center">
        <div style="height:40px"></div>
        <div>توقيع المسؤول عن التوزيع</div>
        <div class="border-top mt-1" style="width:80%;margin:auto"></div>
    </div>
    <div class="col-4 text-center">
        <div style="height:40px"></div>
        <div>توقيع المراجع</div>
        <div class="border-top mt-1" style="width:80%;margin:auto"></div>
    </div>
    <div class="col-4 text-center">
        <div style="height:40px"></div>
        <div>توقيع رئيس اللجنة</div>
        <div class="border-top mt-1" style="width:80%;margin:auto"></div>
    </div>
</div>

<div class="text-muted small mt-3 text-center">
    طُبع في: <?= date('Y-m-d H:i') ?>
    <?php if ($dist['created_by_name']): ?>
    &nbsp;|&nbsp; أُنشئ بواسطة: <?= e($dist['created_by_name']) ?>
    <?php endif; ?>
</div>

<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
</body>
</html>
