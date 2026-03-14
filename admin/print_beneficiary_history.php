<?php
/**
 * print_beneficiary_history.php
 * A4 print-friendly beneficiary history.
 */

declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
requireLogin();

$pdo = getPDO();
$id  = (int)($_GET['id'] ?? 0);

if ($id <= 0) exit('رقم المستفيد غير صحيح.');

$stmt = $pdo->prepare(
    'SELECT b.*, bt.name_ar AS type_name
     FROM beneficiaries b
     JOIN beneficiary_types bt ON bt.id = b.beneficiary_type_id
     WHERE b.id = ?'
);
$stmt->execute([$id]);
$ben = $stmt->fetch();
if (!$ben) exit('المستفيد غير موجود.');

$stmt = $pdo->prepare(
    'SELECT d.id AS distribution_id, d.title, d.distribution_date,
            bt.name_ar AS distribution_type,
            di.cash_amount, di.details_text, di.notes
     FROM distribution_items di
     JOIN distributions d ON d.id = di.distribution_id
     LEFT JOIN beneficiary_types bt ON bt.id = d.beneficiary_type_id
     WHERE di.beneficiary_id = ?
     ORDER BY d.distribution_date DESC, d.id DESC'
);
$stmt->execute([$id]);
$rows = $stmt->fetchAll();

$totalCash = array_sum(array_map(fn($r) => (float)($r['cash_amount'] ?? 0), $rows));
$orgName   = getSetting('org_name', APP_NAME);
$printDate = date('Y-m-d H:i');
?>
<!doctype html>
<html lang="ar" dir="rtl">
<head>
<meta charset="utf-8">
<title>سجل مستفيد — <?= e((string)$ben['full_name']) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700;800&display=swap" rel="stylesheet">
<style>
@page { size:A4; margin:10mm; }
body{font-family:'Cairo',sans-serif;font-size:12px;margin:0;color:#111}
.header{border:2px solid #0f2c5e;border-radius:12px;padding:10px 12px;margin-bottom:10px}
.title{font-size:18px;font-weight:900;color:#0f2c5e}
.meta{margin-top:8px;font-size:11.5px;color:#333;display:flex;flex-wrap:wrap;gap:6px 14px}
table{width:100%;border-collapse:collapse}
th,td{border:1px solid #d5deee;padding:6px;vertical-align:top}
th{background:#eef3fb;font-weight:900}
.small{font-size:11px;color:#555}
.no-print{margin-bottom:10px}
@media print{ .no-print{display:none} tr{page-break-inside:avoid} }
</style>
</head>
<body>

<div class="no-print">
  <button onclick="window.print()">طباعة</button>
  <a href="javascript:history.back()">رجوع</a>
</div>

<div class="header">
  <div class="title">لجنة الزكاة — سجل مستفيد</div>
  <div class="small"><?= e($orgName) ?></div>
  <div class="meta">
    <div><b>رقم الملف:</b> <?= e((string)$ben['file_number']) ?></div>
    <div><b>الاسم:</b> <?= e((string)$ben['full_name']) ?></div>
    <div><b>النوع:</b> <?= e((string)$ben['type_name']) ?></div>
    <div><b>الهوية:</b> <?= e((string)($ben['id_number'] ?? '—')) ?></div>
    <div><b>الهاتف:</b> <?= e((string)($ben['phone'] ?? '—')) ?></div>
    <div><b>إجمالي النقدي:</b> <?= e(formatAmount($totalCash)) ?></div>
  </div>
</div>

<table>
  <thead>
    <tr>
      <th style="width:40px">#</th>
      <th style="width:90px">التاريخ</th>
      <th>التوزيعة</th>
      <th style="width:120px">التصنيف</th>
      <th style="width:110px">المبلغ</th>
      <th>التفاصيل</th>
    </tr>
  </thead>
  <tbody>
  <?php foreach ($rows as $i => $r): ?>
    <tr>
      <td><?= $i+1 ?></td>
      <td><?= e((string)$r['distribution_date']) ?></td>
      <td><?= e((string)$r['title']) ?></td>
      <td><?= e((string)($r['distribution_type'] ?? '—')) ?></td>
      <td><?= ((float)$r['cash_amount'] > 0) ? e(formatAmount((float)$r['cash_amount'])) : '—' ?></td>
      <td class="small">
        <?= e((string)($r['details_text'] ?? '')) ?>
        <?php if (!empty($r['notes'])): ?>
          <?= ' | ' . e((string)$r['notes']) ?>
        <?php endif; ?>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>

<div class="small" style="margin-top:10px;color:#666">
  طُبع في: <?= e($printDate) ?>
</div>

</body>
</html>