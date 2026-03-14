<?php
/**
 * import.php
 * CSV upload or Excel paste → preview → confirm import.
 * Supports smart column detection for RTL-swapped order.
 * Upsert logic: update existing record if id_number matches in same type.
 */

declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
requireLogin();

$pdo   = getPDO();
$types = getBeneficiaryTypes();

/* ─────────────────────────────────────────────────────────────────────────────
   Helpers
───────────────────────────────────────────────────────────────────────────── */

/**
 * Parse raw text (CSV lines or tab-separated Excel paste) into rows.
 * Returns array of ['full_name'=>, 'id_number'=>, 'phone'=>, 'file_number'=>]
 */
function parseRows(string $raw): array
{
    $rows  = [];
    $lines = preg_split('/\r\n|\r|\n/', trim($raw));

    // Detect separator once from the first non-empty line
    $sep = "\t"; // default
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '') continue;
        if (str_contains($line, "\t"))     { $sep = "\t"; break; }
        if (str_contains($line, ','))      { $sep = ',';  break; }
        if (str_contains($line, ';'))      { $sep = ';';  break; }
        break;
    }

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '') continue;

        $cols = array_map('trim', explode($sep, $line));
        // Remove empty trailing columns
        while (count($cols) > 0 && $cols[array_key_last($cols)] === '') {
            array_pop($cols);
        }

        $rows[] = $cols;
    }
    return $rows;
}

/**
 * Detect whether first row is a header.
 */
function isHeaderRow(array $row): bool
{
    $headerKeywords = ['الاسم','name','اسم','رقم','هوية','هاتف','phone','ترقيم','رقم الملف','file'];
    foreach ($row as $cell) {
        $cell = mb_strtolower(trim($cell));
        foreach ($headerKeywords as $kw) {
            if (str_contains($cell, $kw)) return true;
        }
    }
    return false;
}

/**
 * Auto-detect column mapping from a header row (or by position heuristic).
 * Returns ['name'=>idx, 'id_number'=>idx|null, 'phone'=>idx|null, 'file_number'=>idx|null]
 */
function detectColumns(array $header): array
{
    $map = ['name' => null, 'id_number' => null, 'phone' => null, 'file_number' => null];

    $patterns = [
        'name'        => ['الاسم','اسم','name'],
        'id_number'   => ['هوية','رقم الهوية','id_number','id','هويه'],
        'phone'       => ['هاتف','جوال','phone','موبايل'],
        'file_number' => ['ترقيم','رقم الملف','رقم','file','#','م'],
    ];

    foreach ($header as $i => $cell) {
        $cell = mb_strtolower(trim($cell));
        foreach ($patterns as $field => $keywords) {
            if ($map[$field] !== null) continue;
            foreach ($keywords as $kw) {
                if (str_contains($cell, $kw)) {
                    $map[$field] = $i;
                    break 2;
                }
            }
        }
    }

    // Fallback positional if no headers matched: expect ترقيم|الاسم|هوية|هاتف or الاسم|هوية|هاتف
    if ($map['name'] === null) {
        $count = count($header);
        if ($count >= 4) {
            // 4-col: file | name | id | phone
            $map = ['file_number' => 0, 'name' => 1, 'id_number' => 2, 'phone' => 3];
        } elseif ($count === 3) {
            // name | id | phone
            $map = ['file_number' => null, 'name' => 0, 'id_number' => 1, 'phone' => 2];
        } elseif ($count === 2) {
            $map = ['file_number' => null, 'name' => 0, 'id_number' => 1, 'phone' => null];
        } elseif ($count >= 1) {
            $map = ['file_number' => null, 'name' => 0, 'id_number' => null, 'phone' => null];
        }
    }

    return $map;
}

/**
 * Map a single row to a beneficiary array using a column map.
 */
function mapRow(array $row, array $colMap): array
{
    $get = fn($field) => isset($colMap[$field]) && $colMap[$field] !== null
        ? trim($row[$colMap[$field]] ?? '')
        : '';

    return [
        'full_name'   => $get('name'),
        'id_number'   => $get('id_number')   ?: null,
        'phone'       => $get('phone')        ?: null,
        'file_number' => $get('file_number')  ?: null,
    ];
}

/* ─────────────────────────────────────────────────────────────────────────────
   POST handlers
───────────────────────────────────────────────────────────────────────────── */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $step = $_POST['step'] ?? '';

    // ── Step 1: Parse uploaded CSV or pasted text ──
    if ($step === 'parse') {
        $typeId = (int) ($_POST['beneficiary_type_id'] ?? 0);
        if (!$typeId) {
            flashError('يُرجى تحديد نوع المستفيدين أولاً.');
            redirect(ADMIN_PATH . '/import.php');
        }

        $rawText = '';

        if (!empty($_FILES['csv_file']['tmp_name'])) {
            $rawText = file_get_contents($_FILES['csv_file']['tmp_name']);
            // Handle UTF-16 BOM (some Windows CSV exports)
            if (str_starts_with($rawText, "\xFF\xFE") || str_starts_with($rawText, "\xFE\xFF")) {
                $rawText = mb_convert_encoding($rawText, 'UTF-8', 'UTF-16');
            }
            // Strip UTF-8 BOM
            $rawText = ltrim($rawText, "\xEF\xBB\xBF");
        } elseif (!empty($_POST['paste_text'])) {
            $rawText = $_POST['paste_text'];
        }

        $rawText = trim($rawText);
        if (!$rawText) {
            flashError('لم يتم تقديم بيانات للاستيراد.');
            redirect(ADMIN_PATH . '/import.php');
        }

        $allRows = parseRows($rawText);
        if (empty($allRows)) {
            flashError('لا توجد بيانات صالحة للمعالجة.');
            redirect(ADMIN_PATH . '/import.php');
        }

        // Detect header
        $hasHeader = isHeaderRow($allRows[0]);
        $headerRow = $hasHeader ? $allRows[0] : null;
        $dataRows  = $hasHeader ? array_slice($allRows, 1) : $allRows;

        $colMap    = detectColumns($headerRow ?? $allRows[0]);

        // Build preview rows
        $preview = [];
        foreach ($dataRows as $row) {
            $mapped = mapRow($row, $colMap);
            if ($mapped['full_name'] === '') continue;
            $preview[] = $mapped;
        }

        if (empty($preview)) {
            flashError('لم يتم العثور على صفوف صالحة. تأكد من تنسيق البيانات.');
            redirect(ADMIN_PATH . '/import.php');
        }

        // Store in session for confirm step
        $_SESSION['import_preview']  = $preview;
        $_SESSION['import_type_id']  = $typeId;
        redirect(ADMIN_PATH . '/import.php?step=preview');
    }

    // ── Step 2: Confirm and execute import ──
    if ($step === 'confirm') {
        $preview = $_SESSION['import_preview'] ?? [];
        $typeId  = (int) ($_SESSION['import_type_id'] ?? 0);

        unset($_SESSION['import_preview'], $_SESSION['import_type_id']);

        if (empty($preview) || !$typeId) {
            flashError('انتهت جلسة المعاينة. يُرجى إعادة رفع الملف.');
            redirect(ADMIN_PATH . '/import.php');
        }

        $inserted = 0;
        $updated  = 0;
        $skipped  = 0;

        $pdo->beginTransaction();
        try {
            foreach ($preview as $row) {
                $name    = $row['full_name'];
                $idNum   = $row['id_number']   ?: null;
                $phone   = $row['phone']        ?: null;
                $fileNum = $row['file_number']  ? (int) $row['file_number'] : null;

                // Upsert: if id_number exists in same type, update
                if ($idNum !== null) {
                    $chk = $pdo->prepare(
                        'SELECT id FROM beneficiaries WHERE beneficiary_type_id = ? AND id_number = ?'
                    );
                    $chk->execute([$typeId, $idNum]);
                    $existing = $chk->fetchColumn();

                    if ($existing) {
                        $pdo->prepare(
                            'UPDATE beneficiaries SET full_name=?, phone=? WHERE id=?'
                        )->execute([$name, $phone, $existing]);
                        $updated++;
                        continue;
                    }
                }

                // Insert new
                if ($fileNum === null) {
                    $fileNum = getNextFileNumber($typeId);
                } else {
                    // Check if file number already taken for this type
                    $chk = $pdo->prepare(
                        'SELECT id FROM beneficiaries WHERE beneficiary_type_id = ? AND file_number = ?'
                    );
                    $chk->execute([$typeId, $fileNum]);
                    if ($chk->fetchColumn()) {
                        $fileNum = getNextFileNumber($typeId);
                    }
                }

                $pdo->prepare(
                    'INSERT INTO beneficiaries (beneficiary_type_id, file_number, full_name, id_number, phone)
                     VALUES (?,?,?,?,?)'
                )->execute([$typeId, $fileNum, $name, $idNum, $phone]);
                $inserted++;
            }
            $pdo->commit();
            flashSuccess("تم الاستيراد بنجاح: إضافة {$inserted}، تحديث {$updated}.");
        } catch (\Throwable $e) {
            $pdo->rollBack();
            flashError('خطأ أثناء الاستيراد: ' . $e->getMessage());
        }

        redirect(ADMIN_PATH . '/import.php');
    }
}

/* ─────────────────────────────────────────────────────────────────────────────
   GET: Render
───────────────────────────────────────────────────────────────────────────── */

$step    = $_GET['step']    ?? '';
$preview = $_SESSION['import_preview'] ?? [];
$previewTypeId = (int) ($_SESSION['import_type_id'] ?? 0);

// Find type name for preview header
$previewTypeName = '';
foreach ($types as $t) {
    if ($t['id'] == $previewTypeId) {
        $previewTypeName = $t['name'];
        break;
    }
}

require_once __DIR__ . '/layout.php';
renderPage('استيراد البيانات', 'import', function() use (
    $types, $step, $preview, $previewTypeName, $previewTypeId
) {
?>
<?= renderFlash() ?>

<?php if ($step === 'preview' && !empty($preview)): ?>
<!-- ── Preview Step ── -->
<div class="card mb-4">
    <div class="card-header fw-bold text-warning bg-dark">
        <i class="bi bi-eye-fill me-1"></i>معاينة البيانات قبل الاستيراد
        — النوع: <span class="badge bg-primary"><?= e($previewTypeName) ?></span>
        <span class="badge bg-secondary ms-1"><?= count($preview) ?> سجل</span>
    </div>
    <div class="card-body">
        <div class="alert alert-warning py-2">
            <i class="bi bi-exclamation-triangle-fill me-1"></i>
            تحقق من البيانات أدناه. عند التأكيد سيتم <strong>إضافة أو تحديث</strong> السجلات تلقائياً
            (إذا كان رقم الهوية موجوداً في نفس النوع سيتم التحديث، وإلا سيُضاف سجل جديد).
        </div>
        <div class="table-responsive" style="max-height:400px;overflow-y:auto">
            <table class="table table-sm table-bordered mb-0">
                <thead class="table-light sticky-top">
                    <tr><th>#</th><th>الاسم الكامل</th><th>رقم الهوية</th><th>الهاتف</th><th>رقم الملف (من الملف)</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($preview as $i => $row): ?>
                    <tr class="<?= $row['full_name'] === '' ? 'table-warning' : '' ?>">
                        <td><?= $i + 1 ?></td>
                        <td><?= e($row['full_name']) ?></td>
                        <td><?= e($row['id_number'] ?? '') ?></td>
                        <td><?= e($row['phone']     ?? '') ?></td>
                        <td class="text-muted"><?= e($row['file_number'] ?? 'تلقائي') ?></td>
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
<!-- ── Upload/Paste Step ── -->
<div class="card mb-4">
    <div class="card-header fw-bold">
        <i class="bi bi-upload me-1"></i>استيراد بيانات المستفيدين
    </div>
    <div class="card-body">

        <div class="alert alert-info py-2 small">
            <i class="bi bi-info-circle-fill me-1"></i>
            <strong>الصيغ المدعومة:</strong><br>
            • <code>ترقيم | الاسم | رقم الهوية | الهاتف</code><br>
            • <code>الاسم | رقم الهوية | الهاتف</code><br>
            يمكن رفع ملف CSV أو لصق البيانات مباشرة من Excel (انسخ الخلايا والصقها في المربع أدناه).
            النظام يكتشف رؤوس الأعمدة تلقائياً ويتعامل مع ترتيب RTL المعكوس.
        </div>

        <form method="post" action="<?= e(ADMIN_PATH) ?>/import.php"
              enctype="multipart/form-data" id="importForm">
            <?= csrfField() ?>
            <input type="hidden" name="step" value="parse">

            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label">نوع المستفيدين <span class="text-danger">*</span></label>
                    <select name="beneficiary_type_id" class="form-select" required>
                        <option value="">— اختر النوع —</option>
                        <?php foreach ($types as $t): ?>
                        <option value="<?= $t['id'] ?>"><?= e($t['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <!-- Tab: upload or paste -->
            <ul class="nav nav-tabs mt-4" id="importTab">
                <li class="nav-item">
                    <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tab-paste" type="button">
                        <i class="bi bi-clipboard-fill me-1"></i> لصق من Excel
                    </button>
                </li>
                <li class="nav-item">
                    <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-upload" type="button">
                        <i class="bi bi-file-earmark-csv me-1"></i> رفع ملف CSV
                    </button>
                </li>
            </ul>

            <div class="tab-content border border-top-0 rounded-bottom p-3 mb-3">
                <div class="tab-pane fade show active" id="tab-paste">
                    <label class="form-label fw-semibold">الصق البيانات هنا</label>
                    <textarea name="paste_text" id="pasteArea" class="form-control font-monospace"
                              rows="10" dir="rtl"
                              placeholder="الصق الصفوف مباشرة من Excel هنا..."></textarea>
                    <div class="form-text">كل سطر = مستفيد واحد. يُقبل الفاصل: Tab أو فاصلة أو فاصلة منقوطة.</div>
                </div>
                <div class="tab-pane fade" id="tab-upload">
                    <label class="form-label fw-semibold">اختر ملف CSV</label>
                    <input type="file" name="csv_file" accept=".csv,.txt" class="form-control">
                    <div class="form-text">الترميز المدعوم: UTF-8، UTF-16 (Excel).</div>
                </div>
            </div>

            <button type="submit" class="btn btn-primary">
                <i class="bi bi-eye me-1"></i>معاينة البيانات
            </button>
        </form>
    </div>
</div>

<!-- Sample format card -->
<div class="card">
    <div class="card-header">
        <i class="bi bi-question-circle me-1"></i>أمثلة على تنسيق البيانات
    </div>
    <div class="card-body">
        <p class="small text-muted">نسخ الخلايا من Excel (عمود واحد أو أكثر)، الترتيب يُكتشف تلقائياً:</p>
        <div class="row g-3">
            <div class="col-md-6">
                <p class="fw-semibold small mb-1">4 أعمدة (مع الترقيم)</p>
                <pre class="bg-light p-2 rounded small" dir="rtl">ترقيم	الاسم	رقم الهوية	الهاتف
1	محمد أحمد	1012345678	0501234567
2	سارة علي	1098765432	0559876543</pre>
            </div>
            <div class="col-md-6">
                <p class="fw-semibold small mb-1">3 أعمدة (بدون ترقيم)</p>
                <pre class="bg-light p-2 rounded small" dir="rtl">الاسم	رقم الهوية	الهاتف
محمد أحمد	1012345678	0501234567
سارة علي	1098765432	0559876543</pre>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>
<?php
});
