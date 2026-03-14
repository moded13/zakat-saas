<?php
/**
 * import.php (FINAL - FIXED FORMAT + NO 500)
 *
 * Expected columns per line (TAB-separated):
 *  0 full_name
 *  1 id_number (national id)
 *  2 phone
 *  3 monthly_cash (Dinار)
 *  4 file_number
 *
 * - MySQL "id" is the system serial (auto increment)
 * - file_number imported from file
 * - monthly_cash imported from file
 * - default_item optional text for all rows
 */

declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
requireLogin();

$pdo   = getPDO();
$types = getBeneficiaryTypes();

function normalizeDigits(string $s): string
{
    return preg_replace('/\D+/', '', $s) ?? '';
}

function parseRows(string $raw): array
{
    $rows  = [];
    $lines = preg_split('/\r\n|\r|\n/', trim($raw));

    $sep = "\t";
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '') continue;
        if (str_contains($line, "\t")) { $sep = "\t"; break; }
        if (str_contains($line, ','))  { $sep = ',';  break; }
        if (str_contains($line, ';'))  { $sep = ';';  break; }
        break;
    }

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '') continue;

        $cols = array_map('trim', explode($sep, $line));
        while (count($cols) > 0 && $cols[array_key_last($cols)] === '') {
            array_pop($cols);
        }
        $rows[] = $cols;
    }

    return $rows;
}

function isHeaderLikeRow(array $row): bool
{
    $joined = mb_strtolower(trim(implode(' ', $row)));
    $keys = ['الاسم', 'الرقم', 'الهوية', 'الهاتف', 'ملف', 'دينار', 'اثبات', 'شخصية'];
    foreach ($keys as $k) {
        if (str_contains($joined, mb_strtolower($k))) return true;
    }
    return false;
}

function mapRowFixed(array $row, string $defaultItem): ?array
{
    if (count($row) < 5) return null;

    $name = trim((string)($row[0] ?? ''));
    $idn  = normalizeDigits((string)($row[1] ?? ''));
    $ph   = normalizeDigits((string)($row[2] ?? ''));

    $cashRaw = trim((string)($row[3] ?? ''));
    $cashRaw = str_replace(',', '.', $cashRaw);
    $monthlyCash = ($cashRaw === '' ? null : (float)$cashRaw);

    $file = normalizeDigits((string)($row[4] ?? ''));

    if ($name === '') return null;
    if ($file === '' || (int)$file <= 0) return null;

    return [
        'full_name'     => $name,
        'id_number'     => ($idn !== '' ? $idn : null),
        'phone'         => ($ph !== '' ? $ph : null),
        'monthly_cash'  => $monthlyCash,
        'file_number'   => (int)$file,
        'default_item'  => ($defaultItem !== '' ? $defaultItem : null),
    ];
}

/* ───────────────────────── POST ───────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $step = $_POST['step'] ?? '';

    if ($step === 'parse') {
        $typeId = (int)($_POST['beneficiary_type_id'] ?? 0);
        $defaultItem = trim((string)($_POST['default_item'] ?? ''));

        if ($typeId <= 0) {
            flashError('يُرجى تحديد نوع المستفيدين.');
            redirect(ADMIN_PATH . '/import.php');
        }

        $rawText = '';
        if (!empty($_FILES['csv_file']['tmp_name'])) {
            $rawText = file_get_contents($_FILES['csv_file']['tmp_name']) ?: '';
            if (str_starts_with($rawText, "\xFF\xFE") || str_starts_with($rawText, "\xFE\xFF")) {
                $rawText = mb_convert_encoding($rawText, 'UTF-8', 'UTF-16');
            }
            $rawText = ltrim($rawText, "\xEF\xBB\xBF");
        } elseif (!empty($_POST['paste_text'])) {
            $rawText = (string)$_POST['paste_text'];
        }

        $rawText = trim($rawText);
        if ($rawText === '') {
            flashError('لم يتم تقديم بيانات للاستيراد.');
            redirect(ADMIN_PATH . '/import.php');
        }

        $rows = parseRows($rawText);
        if (empty($rows)) {
            flashError('لا توجد بيانات صالحة.');
            redirect(ADMIN_PATH . '/import.php');
        }

        $dataRows = [];
        foreach ($rows as $r) {
            if (isHeaderLikeRow($r)) continue;
            $dataRows[] = $r;
        }

        $preview = [];
        foreach ($dataRows as $r) {
            $m = mapRowFixed($r, $defaultItem);
            if ($m) $preview[] = $m;
        }

        if (empty($preview)) {
            flashError('لم يتم العثور على صفوف صالحة. تأكد أن التنسيق: الاسم | الهوية | الهاتف | دينار | رقم الملف.');
            redirect(ADMIN_PATH . '/import.php');
        }

        $_SESSION['import_preview'] = $preview;
        $_SESSION['import_type_id'] = $typeId;

        redirect(ADMIN_PATH . '/import.php?step=preview');
    }

    if ($step === 'confirm') {
        $preview = $_SESSION['import_preview'] ?? [];
        $typeId  = (int)($_SESSION['import_type_id'] ?? 0);

        unset($_SESSION['import_preview'], $_SESSION['import_type_id']);

        if ($typeId <= 0 || empty($preview)) {
            flashError('انتهت جلسة المعاينة.');
            redirect(ADMIN_PATH . '/import.php');
        }

        $inserted = 0;
        $updated  = 0;

        $pdo->beginTransaction();
        try {
            foreach ($preview as $r) {
                $name  = (string)$r['full_name'];
                $idNum = $r['id_number'] ?: null;
                $phone = $r['phone'] ?: null;
                $cash  = $r['monthly_cash'];
                $file  = (int)$r['file_number'];
                $item  = $r['default_item'] ?? null;

                // Upsert by (type + file_number)
                $chk = $pdo->prepare('SELECT id FROM beneficiaries WHERE beneficiary_type_id = ? AND file_number = ?');
                $chk->execute([$typeId, $file]);
                $existing = (int)($chk->fetchColumn() ?? 0);

                if ($existing > 0) {
                    $pdo->prepare(
                        'UPDATE beneficiaries
                         SET full_name=?, id_number=?, phone=?, monthly_cash=?, default_item=?
                         WHERE id=?'
                    )->execute([$name, $idNum, $phone, $cash, $item, $existing]);
                    $updated++;
                } else {
                    $pdo->prepare(
                        'INSERT INTO beneficiaries (beneficiary_type_id, file_number, full_name, id_number, phone, monthly_cash, default_item, status)
                         VALUES (?,?,?,?,?,?,?, "active")'
                    )->execute([$typeId, $file, $name, $idNum, $phone, $cash, $item]);
                    $inserted++;
                }
            }

            $pdo->commit();
            flashSuccess("تم الاستيراد بنجاح: إضافة {$inserted}، تحديث {$updated}.");
        } catch (Throwable $e) {
            $pdo->rollBack();
            flashError('خطأ أثناء الاستيراد: ' . $e->getMessage());
        }

        redirect(ADMIN_PATH . '/import.php');
    }
}

/* ───────────────────────── GET render ───────────────────────── */
$step = $_GET['step'] ?? '';
$preview = $_SESSION['import_preview'] ?? [];

require_once __DIR__ . '/layout.php';
renderPage('استيراد البيانات', 'import', function () use ($types, $step, $preview) {
?>
<?= renderFlash() ?>

<?php if ($step === 'preview' && !empty($preview)): ?>
<div class="card mb-4">
  <div class="card-header fw-bold text-warning bg-dark">
    <i class="bi bi-eye-fill me-1"></i>معاينة البيانات قبل الاستيراد
    <span class="badge bg-secondary ms-1"><?= count($preview) ?> سجل</span>
  </div>
  <div class="card-body">
    <div class="alert alert-info py-2 small">
      <b>التنسيق الثابت:</b> <code>الاسم | الهوية | الهاتف | دينار | رقم الملف</code>
      <br>الرقم التسلسلي (ID) يُولد تلقائيًا من النظام.
    </div>

    <div class="table-responsive" style="max-height:420px;overflow-y:auto">
      <table class="table table-sm table-bordered mb-0">
        <thead class="table-light sticky-top">
          <tr>
            <th>#</th>
            <th>رقم الملف</th>
            <th>الاسم</th>
            <th>رقم الهوية</th>
            <th>رقم الهاتف</th>
            <th>دينار (راتب/مبلغ)</th>
            <th>المادة الموزعة</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($preview as $i => $r): ?>
          <tr>
            <td><?= $i+1 ?></td>
            <td class="fw-bold text-primary"><?= e((string)$r['file_number']) ?></td>
            <td><?= e((string)$r['full_name']) ?></td>
            <td><?= e((string)($r['id_number'] ?? '')) ?></td>
            <td><?= e((string)($r['phone'] ?? '')) ?></td>
            <td><?= $r['monthly_cash'] !== null ? e(number_format((float)$r['monthly_cash'],2)) : '—' ?></td>
            <td><?= e((string)($r['default_item'] ?? '')) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <form method="post" action="<?= e(ADMIN_PATH) ?>/import.php" class="mt-3 d-flex gap-2">
      <?= csrfField() ?>
      <input type="hidden" name="step" value="confirm">
      <button type="submit" class="btn btn-success">
        <i class="bi bi-check2-circle me-1"></i>تأكيد الاستيراد
      </button>
      <a href="<?= e(ADMIN_PATH) ?>/import.php" class="btn btn-outline-secondary">
        <i class="bi bi-x-circle me-1"></i>إلغاء
      </a>
    </form>
  </div>
</div>
<?php else: ?>
<div class="card mb-4">
  <div class="card-header fw-bold"><i class="bi bi-upload me-1"></i>استيراد بيانات المستفيدين</div>
  <div class="card-body">
    <div class="alert alert-info py-2 small">
      <b>تنسيق النص المطلوب (كل سطر):</b> <code>الاسم | الهوية | الهاتف | دينار | رقم الملف</code>
    </div>

    <form method="post" action="<?= e(ADMIN_PATH) ?>/import.php" enctype="multipart/form-data">
      <?= csrfField() ?>
      <input type="hidden" name="step" value="parse">

      <div class="row g-3">
        <div class="col-md-4">
          <label class="form-label">نوع المستفيدين <span class="text-danger">*</span></label>
          <select name="beneficiary_type_id" class="form-select" required>
            <option value="">— اختر النوع —</option>
            <?php foreach ($types as $t): ?>
              <option value="<?= (int)$t['id'] ?>"><?= e((string)$t['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="col-md-8">
          <label class="form-label">المادة الموزعة (اختياري)</label>
          <input type="text" name="default_item" class="form-control" placeholder="مثال: رواتب الأسر / كفالة 1">
        </div>
      </div>

      <ul class="nav nav-tabs mt-4">
        <li class="nav-item">
          <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tab-paste" type="button">لصق من Excel</button>
        </li>
        <li class="nav-item">
          <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-upload" type="button">رفع CSV</button>
        </li>
      </ul>

      <div class="tab-content border border-top-0 rounded-bottom p-3 mb-3">
        <div class="tab-pane fade show active" id="tab-paste">
          <textarea name="paste_text" class="form-control font-monospace" rows="10" dir="rtl"></textarea>
        </div>
        <div class="tab-pane fade" id="tab-upload">
          <input type="file" name="csv_file" accept=".csv,.txt" class="form-control">
        </div>
      </div>

      <button type="submit" class="btn btn-primary">
        <i class="bi bi-eye me-1"></i>معاينة البيانات
      </button>
    </form>
  </div>
</div>
<?php endif; ?>
<?php
});