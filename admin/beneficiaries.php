<?php
/**
 * beneficiaries.php
 * Unified page: list / add / edit / delete beneficiaries.
 * Supports:
 * - Search + type filter (auto submit on type change)
 * - PRG on POST actions
 * - Single delete + Bulk delete (with safe transaction handling)
 * - Renumber file_number after delete (same type)
 * - Shows monthly_cash + default_item
 * - Adds "History" button per beneficiary (beneficiary_history.php)
 */

declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
requireLogin();

$pdo   = getPDO();
$types = getBeneficiaryTypes();

/* ── POST: Add / Edit / Delete / Bulk Delete ─────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';

    // ── Bulk delete ──
    if ($action === 'bulk_delete') {
        $ids = $_POST['ids'] ?? [];
        $ids = array_values(array_filter(array_map('intval', (array)$ids)));

        if (empty($ids)) {
            flashError('لم يتم تحديد أي مستفيد.');
            redirect(ADMIN_PATH . '/beneficiaries.php');
        }

        $in = implode(',', array_fill(0, count($ids), '?'));

        // Determine involved type(s) to renumber afterwards
        $stmt = $pdo->prepare("SELECT DISTINCT beneficiary_type_id FROM beneficiaries WHERE id IN ($in)");
        $stmt->execute($ids);
        $typeIds = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));

        // Prevent delete if referenced by distribution_items
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM distribution_items WHERE beneficiary_id IN ($in)");
        $stmt->execute($ids);
        if ((int)$stmt->fetchColumn() > 0) {
            flashError('لا يمكن حذف بعض/كل المستفيدين لوجود سجلات توزيع مرتبطة بهم.');
            redirect(ADMIN_PATH . '/beneficiaries.php');
        }

        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare("DELETE FROM beneficiaries WHERE id IN ($in)");
            $stmt->execute($ids);

            // Renumber each impacted type
            foreach ($typeIds as $tid) {
                if ($tid > 0) {
                    // IMPORTANT: renumberBeneficiariesForType must be safe with open transactions
                    renumberBeneficiariesForType($tid);
                }
            }

            $pdo->commit();
            flashSuccess('تم حذف المستفيدين المحددين وإعادة ترتيب أرقام الملفات.');
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            flashError('خطأ أثناء الحذف: ' . $e->getMessage());
        }

        redirect(ADMIN_PATH . '/beneficiaries.php');
    }

    // ── Delete single ──
    if ($action === 'delete') {
        $id = (int) ($_POST['id'] ?? 0);

        // fetch type before deleting (for renumber)
        $stmt = $pdo->prepare('SELECT beneficiary_type_id FROM beneficiaries WHERE id = ?');
        $stmt->execute([$id]);
        $typeIdForRenumber = (int)($stmt->fetchColumn() ?? 0);

        // Check no distribution items reference this beneficiary
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM distribution_items WHERE beneficiary_id = ?');
        $stmt->execute([$id]);

        if ((int) $stmt->fetchColumn() > 0) {
            flashError('لا يمكن حذف المستفيد لوجود سجلات توزيع مرتبطة به.');
        } else {
            $pdo->prepare('DELETE FROM beneficiaries WHERE id = ?')->execute([$id]);

            if ($typeIdForRenumber > 0) {
                renumberBeneficiariesForType($typeIdForRenumber);
            }

            flashSuccess('تم حذف المستفيد بنجاح وإعادة ترتيب أرقام الملفات.');
        }

        redirect(ADMIN_PATH . '/beneficiaries.php?' . http_build_query([
            'type' => $_POST['filter_type'] ?? '',
            'q'    => $_POST['filter_q']    ?? '',
        ]));
    }

    // ── Save (add / edit) ──
    if ($action === 'save') {
        $id     = (int) ($_POST['id'] ?? 0);
        $typeId = (int) ($_POST['beneficiary_type_id'] ?? 0);

        $name   = trim($_POST['full_name']  ?? '');
        $idNum  = trim($_POST['id_number']  ?? '');
        $phone  = trim($_POST['phone']      ?? '');
        $status = $_POST['status'] ?? 'active';
        $notes  = trim($_POST['notes']      ?? '');

        $monthlyCashRaw = trim((string)($_POST['monthly_cash'] ?? ''));
        $monthlyCash = ($monthlyCashRaw === '') ? null : (float)$monthlyCashRaw;

        $defaultItem = trim((string)($_POST['default_item'] ?? ''));

        // Validate
        $errors = [];
        if (!$typeId)   $errors[] = 'يُرجى تحديد نوع المستفيد.';
        if (!$name)     $errors[] = 'الاسم الكامل مطلوب.';
        if ($status !== 'active' && $status !== 'inactive') $status = 'active';

        // Check duplicate id_number within same type
        if (!$errors && $idNum !== '') {
            $chk = $pdo->prepare(
                'SELECT id FROM beneficiaries WHERE beneficiary_type_id = ? AND id_number = ? AND id != ?'
            );
            $chk->execute([$typeId, $idNum, $id]);
            if ($chk->fetch()) {
                $errors[] = 'رقم الهوية مستخدم مسبقاً لنفس نوع المستفيدين.';
            }
        }

        if ($errors) {
            flashError(implode(' | ', $errors));
        } else {
            if ($id > 0) {
                $pdo->prepare(
                    'UPDATE beneficiaries
                     SET beneficiary_type_id=?, full_name=?, id_number=?,
                         phone=?, monthly_cash=?, default_item=?,
                         status=?, notes=?
                     WHERE id=?'
                )->execute([
                    $typeId,
                    $name,
                    $idNum ?: null,
                    $phone ?: null,
                    $monthlyCash,
                    $defaultItem !== '' ? $defaultItem : null,
                    $status,
                    $notes ?: null,
                    $id
                ]);
                flashSuccess('تم تحديث بيانات المستفيد بنجاح.');
            } else {
                $fileNum = getNextFileNumber($typeId);
                $pdo->prepare(
                    'INSERT INTO beneficiaries
                     (beneficiary_type_id, file_number, full_name, id_number, phone, monthly_cash, default_item, status, notes)
                     VALUES (?,?,?,?,?,?,?,?,?)'
                )->execute([
                    $typeId,
                    $fileNum,
                    $name,
                    $idNum ?: null,
                    $phone ?: null,
                    $monthlyCash,
                    $defaultItem !== '' ? $defaultItem : null,
                    $status,
                    $notes ?: null
                ]);
                flashSuccess('تمت إضافة المستفيد بنجاح. رقم الملف: ' . $fileNum);
            }
        }

        redirect(ADMIN_PATH . '/beneficiaries.php?' . http_build_query([
            'type' => $typeId ?: '',
            'q'    => '',
        ]));
    }
}

/* ── GET: Read / Search ───────────────────────────────────────────────────── */
$filterType   = (int) ($_GET['type'] ?? 0);
$filterSearch = trim($_GET['q'] ?? '');
$editId       = (int) ($_GET['edit'] ?? 0);

// Build query
$where  = ['1=1'];
$params = [];
if ($filterType > 0) {
    $where[]  = 'b.beneficiary_type_id = ?';
    $params[] = $filterType;
}
if ($filterSearch !== '') {
    $where[]  = '(b.full_name LIKE ? OR b.id_number LIKE ? OR b.phone LIKE ?)';
    $like      = '%' . $filterSearch . '%';
    $params   = array_merge($params, [$like, $like, $like]);
}

$sql = 'SELECT b.*, bt.name_ar AS type_name
        FROM beneficiaries b
        JOIN beneficiary_types bt ON bt.id = b.beneficiary_type_id
        WHERE ' . implode(' AND ', $where) . '
        ORDER BY b.beneficiary_type_id, b.file_number';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$beneficiaries = $stmt->fetchAll();

// Edit mode
$editRow = null;
if ($editId > 0) {
    $stmt = $pdo->prepare('SELECT * FROM beneficiaries WHERE id = ?');
    $stmt->execute([$editId]);
    $editRow = $stmt->fetch();
}

require_once __DIR__ . '/layout.php';
renderPage('المستفيدون', 'beneficiaries', function() use (
    $types, $beneficiaries, $filterType, $filterSearch, $editRow, $editId
) {
?>
<?= renderFlash() ?>

<!-- Form card -->
<div class="card mb-4">
    <div class="card-header">
        <i class="bi bi-person-plus-fill me-1"></i>
        <?= $editRow ? 'تعديل بيانات المستفيد' : 'إضافة مستفيد جديد' ?>
    </div>
    <div class="card-body">
        <form method="post" action="<?= e(ADMIN_PATH) ?>/beneficiaries.php">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="id"     value="<?= $editRow ? (int)$editRow['id'] : 0 ?>">
            <input type="hidden" name="filter_type" value="<?= e((string)$filterType) ?>">

            <div class="row g-3">
                <div class="col-md-3">
                    <label class="form-label">نوع المستفيد <span class="text-danger">*</span></label>
                    <select name="beneficiary_type_id" class="form-select" required>
                        <option value="">— اختر النوع —</option>
                        <?php foreach ($types as $t): ?>
                        <option value="<?= (int)$t['id'] ?>"
                            <?= ($editRow && (int)$editRow['beneficiary_type_id'] === (int)$t['id'])
                                || (!$editRow && (int)$filterType === (int)$t['id']) ? 'selected' : '' ?>>
                            <?= e((string)$t['name']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-md-4">
                    <label class="form-label">الاسم الكامل <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" name="full_name"
                           value="<?= e((string)($editRow['full_name'] ?? '')) ?>" required>
                </div>

                <div class="col-md-2">
                    <label class="form-label">رقم الهوية</label>
                    <input type="text" class="form-control" name="id_number"
                           value="<?= e((string)($editRow['id_number'] ?? '')) ?>">
                </div>

                <div class="col-md-2">
                    <label class="form-label">رقم الهاتف</label>
                    <input type="text" class="form-control" name="phone"
                           value="<?= e((string)($editRow['phone'] ?? '')) ?>">
                </div>

                <div class="col-md-1">
                    <label class="form-label">الحالة</label>
                    <select name="status" class="form-select">
                        <option value="active"   <?= (!$editRow || $editRow['status'] === 'active')   ? 'selected' : '' ?>>نشط</option>
                        <option value="inactive" <?= ($editRow  && $editRow['status'] === 'inactive') ? 'selected' : '' ?>>موقوف</option>
                    </select>
                </div>

                <div class="col-md-2">
                    <label class="form-label">راتب نقدي (دينار)</label>
                    <input type="number" step="0.01" min="0" class="form-control" name="monthly_cash"
                           value="<?= e((string)($editRow['monthly_cash'] ?? '')) ?>">
                </div>

                <div class="col-md-4">
                    <label class="form-label">المادة/الوصف الافتراضي</label>
                    <input type="text" class="form-control" name="default_item"
                           value="<?= e((string)($editRow['default_item'] ?? '')) ?>">
                </div>

                <div class="col-md-6">
                    <label class="form-label">ملاحظات</label>
                    <input type="text" class="form-control" name="notes"
                           value="<?= e((string)($editRow['notes'] ?? '')) ?>">
                </div>

                <div class="col-12 d-flex gap-2 align-items-end">
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-save me-1"></i><?= $editRow ? 'حفظ التعديلات' : 'إضافة' ?>
                    </button>
                    <?php if ($editRow): ?>
                    <a href="<?= e(ADMIN_PATH) ?>/beneficiaries.php?type=<?= (int)$filterType ?>" class="btn btn-outline-secondary">
                        <i class="bi bi-x-circle me-1"></i>إلغاء
                    </a>
                    <?php endif; ?>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- Search/filter card -->
<div class="card mb-3">
    <div class="card-body py-2">
        <form method="get" action="" class="row g-2 align-items-center" id="filterForm">
            <div class="col-md-3">
                <select name="type" class="form-select form-select-sm" onchange="this.form.submit()">
                    <option value="">جميع الأنواع</option>
                    <?php foreach ($types as $t): ?>
                    <option value="<?= (int)$t['id'] ?>" <?= (int)$filterType === (int)$t['id'] ? 'selected' : '' ?>>
                        <?= e((string)$t['name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-5">
                <input type="text" name="q" class="form-control form-control-sm"
                       placeholder="ابحث بالاسم أو رقم الهوية أو الهاتف"
                       value="<?= e((string)$filterSearch) ?>">
            </div>
            <div class="col-auto">
                <button class="btn btn-sm btn-primary" type="submit">
                    <i class="bi bi-search me-1"></i>بحث
                </button>
                <a href="<?= e(ADMIN_PATH) ?>/beneficiaries.php" class="btn btn-sm btn-outline-secondary ms-1">مسح</a>
            </div>
        </form>
    </div>
</div>

<!-- Bulk actions + table -->
<div class="card">
    <div class="card-header d-flex align-items-center justify-content-between">
        <span><i class="bi bi-people-fill me-1"></i>قائمة المستفيدين
            <span class="badge bg-secondary ms-1"><?= count($beneficiaries) ?></span>
        </span>

        <form method="post" action="" id="bulkForm" onsubmit="return confirm('هل تريد حذف المستفيدين المحددين؟')">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="bulk_delete">
            <button type="submit" class="btn btn-sm btn-outline-danger" id="bulkDeleteBtn" disabled>
                <i class="bi bi-trash3-fill me-1"></i>حذف المحدد
            </button>
        </form>
    </div>

    <div class="card-body p-0">
        <?php if ($beneficiaries): ?>
        <div class="table-responsive">
            <table class="table table-hover mb-0" id="benListTable">
                <thead>
                    <tr>
                        <th style="width:44px" class="text-center">
                            <input type="checkbox" id="checkAll">
                        </th>
                        <th>رقم الملف</th>
                        <th>النوع</th>
                        <th>الاسم الكامل</th>
                        <th>رقم الهوية</th>
                        <th>الهاتف</th>
                        <th>راتب (دينار)</th>
                        <th>المادة</th>
                        <th>الحالة</th>
                        <th>ملاحظات</th>
                        <th>إجراءات</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($beneficiaries as $b): ?>
                    <tr>
                        <td class="text-center">
                            <input type="checkbox" class="row-check" form="bulkForm" name="ids[]" value="<?= (int)$b['id'] ?>">
                        </td>
                        <td class="fw-bold text-primary"><?= e((string)$b['file_number']) ?></td>
                        <td><span class="badge bg-primary bg-opacity-75"><?= e((string)$b['type_name']) ?></span></td>
                        <td><?= e((string)$b['full_name']) ?></td>
                        <td><?= e((string)($b['id_number'] ?? '—')) ?></td>
                        <td><?= e((string)($b['phone'] ?? '—')) ?></td>
                        <td><?= $b['monthly_cash'] !== null ? e(number_format((float)$b['monthly_cash'], 2)) : '—' ?></td>
                        <td><?= e((string)($b['default_item'] ?? '—')) ?></td>
                        <td>
                            <span class="badge bg-<?= statusBadge((string)$b['status']) ?>">
                                <?= statusLabel((string)$b['status']) ?>
                            </span>
                        </td>
                        <td class="text-muted small"><?= e((string)($b['notes'] ?? '')) ?></td>
                        <td>
                            <div class="d-flex gap-1 flex-nowrap">
                                <a href="?edit=<?= (int)$b['id'] ?>&type=<?= (int)$filterType ?>&q=<?= urlencode($filterSearch) ?>"
                                   class="btn btn-sm btn-outline-primary" title="تعديل">
                                    <i class="bi bi-pencil-fill"></i>
                                </a>

                                <a href="<?= e(ADMIN_PATH) ?>/beneficiary_history.php?id=<?= (int)$b['id'] ?>"
                                   class="btn btn-sm btn-outline-info" title="السجل">
                                    <i class="bi bi-clock-history"></i>
                                </a>

                                <form method="post" action="" class="d-inline"
                                      onsubmit="return confirm('هل تريد حذف هذا المستفيد؟')">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="action"      value="delete">
                                    <input type="hidden" name="id"          value="<?= (int)$b['id'] ?>">
                                    <input type="hidden" name="filter_type" value="<?= (int)$filterType ?>">
                                    <input type="hidden" name="filter_q"    value="<?= e((string)$filterSearch) ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-danger" title="حذف">
                                        <i class="bi bi-trash3-fill"></i>
                                    </button>
                                </form>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php else: ?>
        <div class="p-4 text-center text-muted">
            <i class="bi bi-inbox fs-2 d-block mb-2"></i>
            لا توجد سجلات مطابقة.
        </div>
        <?php endif; ?>
    </div>
</div>

<script>
const checkAll = document.getElementById('checkAll');
const rowChecks = () => Array.from(document.querySelectorAll('.row-check'));
const bulkBtn = document.getElementById('bulkDeleteBtn');

function updateBulkBtn(){
  const any = rowChecks().some(ch => ch.checked);
  bulkBtn.disabled = !any;
}

if (checkAll) {
  checkAll.addEventListener('change', () => {
    rowChecks().forEach(ch => ch.checked = checkAll.checked);
    updateBulkBtn();
  });
}

document.addEventListener('change', (e) => {
  if (e.target.classList && e.target.classList.contains('row-check')) {
    updateBulkBtn();
    const all = rowChecks().every(ch => ch.checked);
    const any = rowChecks().some(ch => ch.checked);
    if (checkAll) {
      checkAll.indeterminate = any && !all;
      checkAll.checked = all;
    }
  }
});

updateBulkBtn();
</script>

<?php
});