<?php
/**
 * print_distribution.php (A4 Pro)
 * - A4 print layout
 * - EXACTLY 20 rows per page (pads with empty rows; never splits 20 across pages)
 * - Clean professional header/footer
 * - Flexible columns: name grows, extra space goes to signature
 * - Long text handling (wrap + clamp) for details/notes
 */

declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
requireLogin();

$pdo = getPDO();
$id  = (int)($_GET['id'] ?? 0);

if ($id <= 0) {
    http_response_code(400);
    exit('رقم التوزيعة مطلوب.');
}

// Detect created_by existence (optional)
$hasCreatedBy = (int)$pdo->query(
    "SELECT COUNT(*)
     FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'distributions'
       AND COLUMN_NAME = 'created_by'"
)->fetchColumn() > 0;

// Distribution header
if ($hasCreatedBy) {
    $stmt = $pdo->prepare(
        'SELECT d.*,
                bt.name_ar AS type_name,
                a.display_name AS created_by_name
         FROM distributions d
         LEFT JOIN beneficiary_types bt ON bt.id = d.beneficiary_type_id
         LEFT JOIN admins a ON a.id = d.created_by
         WHERE d.id = ?'
    );
} else {
    $stmt = $pdo->prepare(
        'SELECT d.*,
                bt.name_ar AS type_name,
                NULL AS created_by_name
         FROM distributions d
         LEFT JOIN beneficiary_types bt ON bt.id = d.beneficiary_type_id
         WHERE d.id = ?'
    );
}
$stmt->execute([$id]);
$dist = $stmt->fetch();

if (!$dist) {
    http_response_code(404);
    exit('التوزيعة غير موجودة.');
}

// Items
$stmt = $pdo->prepare(
    'SELECT di.*,
            b.full_name, b.file_number, b.id_number,
            bt.name_ar AS beneficiary_type_name
     FROM distribution_items di
     JOIN beneficiaries b ON b.id = di.beneficiary_id
     JOIN beneficiary_types bt ON bt.id = b.beneficiary_type_id
     WHERE di.distribution_id = ?
     ORDER BY b.beneficiary_type_id, b.file_number'
);
$stmt->execute([$id]);
$items = $stmt->fetchAll();

$orgName  = getSetting('org_name', APP_NAME);
$orgPhone = getSetting('org_phone', '');

$perPage = 20; // fixed
$totalItems = count($items);
$totalPages = (int)max(1, ceil($totalItems / $perPage));
$pages = array_chunk($items, $perPage);

$totalCash = array_sum(array_map(fn($r) => (float)($r['cash_amount'] ?? 0), $items));
$printDate = date('Y-m-d H:i');

// Helper to build a short printable details string
function buildDetails(array $it): string
{
    $details = trim((string)($it['details_text'] ?? ''));
    $notes   = trim((string)($it['notes'] ?? ''));
    if ($details !== '' && $notes !== '') return $details . ' | ' . $notes;
    return $details !== '' ? $details : $notes;
}
?>
<!doctype html>
<html lang="ar" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>كشف توزيع #<?= (int)$id ?> — <?= e($orgName) ?></title>

<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700;800&display=swap" rel="stylesheet">

<style>
:root{
  --ink:#0f2c5e;
  --ink2:#0a1e40;
  --line:#d5deee;
  --muted:#5e6b80;
  --headbg:#eef3fb;
}
*{ box-sizing:border-box; }
body{
  font-family:'Cairo',sans-serif;
  font-size:12px;
  margin:0;
  color:#111;
  background:#fff;
}
.no-print{
  padding:10px 12px;
  display:flex;
  gap:8px;
  border-bottom:1px solid var(--line);
}
.btn{
  border:1px solid var(--line);
  background:#fff;
  padding:6px 10px;
  border-radius:10px;
  font-family:inherit;
  cursor:pointer;
}
.btn-primary{ background:var(--ink); color:#fff; border-color:var(--ink); }

.sheet{ padding:0; }
.page{
  width: 210mm;
  min-height: 297mm;
  padding: 10mm;
  margin: 0 auto;
  page-break-after: always;
}
.page:last-child{ page-break-after: auto; }

/* Header */
.header{
  border:2px solid var(--ink);
  border-radius:14px;
  padding:10px 12px;
  margin-bottom:8px;
}
.header-top{
  display:flex;
  align-items:flex-start;
  justify-content:space-between;
  gap:10px;
}
.brand{
  display:flex;
  gap:10px;
  align-items:flex-start;
}
.logo{
  width:42px;height:42px;border-radius:12px;
  background: linear-gradient(135deg,var(--ink),#1a4a8a);
}
.brand h1{
  margin:0;
  font-size:18px;
  font-weight:900;
  color:var(--ink);
  line-height:1.1;
}
.brand .sub{
  margin-top:2px;
  color:var(--muted);
  font-size:11px;
}
.docbox{
  text-align:left;
}
.badge{
  display:inline-block;
  padding:4px 10px;
  background:var(--ink2);
  color:#fff;
  border-radius:999px;
  font-weight:800;
  font-size:11px;
}
.docbox .sub{
  margin-top:6px;
  color:var(--muted);
  font-size:11px;
}

/* meta line */
.meta{
  display:flex;
  flex-wrap:wrap;
  gap:6px 14px;
  margin-top:10px;
  padding-top:8px;
  border-top:1px dashed var(--line);
  font-size:11.5px;
}
.meta b{ color:#000; }

/* Table */
.table-wrap{
  border:1px solid var(--line);
  border-radius:14px;
  overflow:hidden;
}
table{
  width:100%;
  border-collapse:collapse;
  table-layout:fixed; /* important for controlled widths */
}
thead th{
  background:var(--headbg);
  border-bottom:1px solid var(--line);
  font-weight:900;
  font-size:11px;
  padding:7px 6px;
}
tbody td{
  border-top:1px solid var(--line);
  padding:6px 6px;
  vertical-align:middle;
}

/* Column widths: keep signature taking extra space */
.col-n{ width: 6%;  text-align:center; }
.col-file{ width: 10%; text-align:center; }
.col-name{ width: 34%; }             /* wide name */
.col-btype{ width: 13%; text-align:center; }
.col-id{ width: 15%; text-align:center; }
.col-amt{ width: 10%; text-align:center; }
.col-notes{ width: 22%; }            /* details */
.col-sign{ width: auto; }            /* takes remaining */

.text-center{ text-align:center; }
.muted{ color:var(--muted); }
.small{ font-size:11px; }

/* Long text: clean wrapping without breaking layout */
.wrap{
  overflow:hidden;
  text-overflow:ellipsis;
  white-space:normal;
  word-break:break-word;
  line-height:1.25;
}
.clamp-2{
  display:-webkit-box;
  -webkit-line-clamp:2;
  -webkit-box-orient:vertical;
  overflow:hidden;
}
.name{
  font-weight:800;
  font-size:12px;
}

/* Footer */
.footer{
  margin-top:8px;
  border:2px solid var(--ink);
  border-radius:14px;
  padding:10px 12px;
  display:grid;
  grid-template-columns: 1fr 1.2fr 1fr;
  gap:10px;
  align-items:end;
}
.sig{
  text-align:center;
}
.sig .line{
  margin-top:26px;
  border-top:1px solid #333;
  width:90%;
  margin-inline:auto;
}
.sig .label{
  font-weight:800;
  font-size:11.5px;
  margin-top:6px;
}
.witness{
  text-align:center;
  font-weight:900;
  color:#111;
}
.witness .box{
  margin-top:6px;
  border:1px dashed var(--line);
  border-radius:12px;
  padding:10px 8px;
  font-weight:800;
  font-size:11.5px;
}

.page-note{
  margin-top:6px;
  display:flex;
  justify-content:space-between;
  font-size:10.5px;
  color:var(--muted);
}

/* Print */
@media print{
  .no-print{ display:none !important; }
  body{ background:#fff; }
  @page{ size:A4; margin:0; } /* page itself has padding */
  .page{ margin:0; }
  thead{ display: table-header-group; } /* header repeats if browser decides to split (we avoid splitting anyway) */
  tfoot{ display: table-footer-group; }
  tr{ page-break-inside: avoid; }
  table{ page-break-inside: avoid; }
}
</style>
</head>
<body>

<div class="no-print">
  <button class="btn btn-primary" onclick="window.print()">طباعة A4</button>
  <a class="btn" href="javascript:history.back()">رجوع</a>
</div>

<div class="sheet">
<?php foreach ($pages as $pi => $pageItems): ?>
  <div class="page">
    <div class="header">
      <div class="header-top">
        <div class="brand">
          <div class="logo"></div>
          <div>
            <h1>لجنة الزكاة</h1>
            <div class="sub"><?= e($orgName) ?><?= $orgPhone ? (' — هاتف: ' . e($orgPhone)) : '' ?></div>
          </div>
        </div>

        <div class="docbox">
          <div class="badge">كشف توزيع رقم #<?= (int)$id ?></div>
          <div class="sub">صفحة <?= (int)($pi+1) ?> / <?= (int)$totalPages ?></div>
        </div>
      </div>

      <div class="meta">
        <div><b>عنوان التوزيعة:</b> <?= e((string)$dist['title']) ?></div>
        <div><b>نوع التوزيعة:</b> <?= e((string)($dist['type_name'] ?? '—')) ?></div>
        <div><b>تاريخ التوزيع:</b> <?= e((string)$dist['distribution_date']) ?></div>
        <div><b>عدد الأسماء:</b> <?= (int)$totalItems ?></div>
        <div><b>إجمالي الصرف:</b> <?= formatAmount($totalCash) ?></div>
      </div>
    </div>

    <div class="table-wrap">
      <table>
        <thead>
          <tr>
            <th class="col-n">م</th>
            <th class="col-file">رقم الملف</th>
            <th class="col-name">الاسم الكامل</th>
            <th class="col-btype">النوع</th>
            <th class="col-id">رقم الهوية</th>
            <th class="col-amt">المبلغ</th>
            <th class="col-notes">التفاصيل</th>
            <th class="col-sign">التوقيع</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($pageItems as $i => $it): ?>
            <?php
              $n = ($pi * $perPage) + $i + 1;
              $cash = (float)($it['cash_amount'] ?? 0);
              $details = buildDetails($it);
            ?>
            <tr>
              <td class="col-n"><?= (int)$n ?></td>
              <td class="col-file"><b><?= e((string)$it['file_number']) ?></b></td>
              <td class="col-name">
                <div class="name wrap"><?= e((string)$it['full_name']) ?></div>
              </td>
              <td class="col-btype small"><?= e((string)$it['beneficiary_type_name']) ?></td>
              <td class="col-id muted small"><?= e((string)($it['id_number'] ?? '')) ?></td>
              <td class="col-amt"><?= $cash > 0 ? e(formatAmount($cash)) : '—' ?></td>
              <td class="col-notes">
                <div class="wrap clamp-2 muted small"><?= e($details) ?></div>
              </td>
              <td class="col-sign"></td>
            </tr>
          <?php endforeach; ?>

          <?php
            // pad to EXACTLY 20 rows
            $missing = $perPage - count($pageItems);
            for ($k=0; $k<$missing; $k++):
          ?>
            <tr>
              <td class="col-n">&nbsp;</td>
              <td class="col-file">&nbsp;</td>
              <td class="col-name">&nbsp;</td>
              <td class="col-btype">&nbsp;</td>
              <td class="col-id">&nbsp;</td>
              <td class="col-amt">&nbsp;</td>
              <td class="col-notes">&nbsp;</td>
              <td class="col-sign">&nbsp;</td>
            </tr>
          <?php endfor; ?>
        </tbody>
      </table>
    </div>

    <div class="footer">
      <div class="sig">
        <div class="line"></div>
        <div class="label">توقيع مسؤول اللجنة</div>
      </div>

      <div class="witness">
        <div>وزعت بحضوري</div>
        <div class="box">الاسم: ____________ &nbsp;&nbsp; التوقيع: ____________</div>
      </div>

      <div class="sig">
        <div class="line"></div>
        <div class="label">توقيع المستلم</div>
      </div>
    </div>

    <div class="page-note">
      <div>طُبع في: <?= e($printDate) ?></div>
      <div>
        <?php if (!empty($dist['created_by_name'])): ?>
          أُنشئ بواسطة: <?= e((string)$dist['created_by_name']) ?>
        <?php endif; ?>
      </div>
    </div>
  </div>
<?php endforeach; ?>
</div>

</body>
</html>