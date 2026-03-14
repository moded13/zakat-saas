<?php
/**
 * beneficiaries.php
 * Unified page: list / add / edit / delete beneficiaries.
 * Supports search, type filter, PRG on all POST actions.
 */

declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
requireLogin();

$pdo   = getPDO();
$types = getBeneficiaryTypes();

/* ── POST: Add / Edit / Delete ────────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';

    // ── Delete ──
    if ($action === 'delete') {
        $id = (int) ($_POST['id'] ?? 0);
        // Check no distribution items reference this beneficiary
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM distribution_items WHERE beneficiary_id = ?');
        $stmt->execute([$id]);
        if ((int) $stmt->fetchColumn() > 0) {
            flashError('لا يمكن حذف المستفيد لوجود سجلات توزيع مرتبطة به.');
        } else {
            $pdo->prepare('DELETE FROM beneficiaries WHERE id = ?')->execute([$id]);
            flashSuccess('تم حذف المستفيد بنجاح.');
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
                // Edit
                $pdo->prepare(
                    'UPDATE beneficiaries SET beneficiary_type_id=?, full_name=?, id_number=?,
                     phone=?, status=?, notes=? WHERE id=?'
                )->execute([$typeId, $name, $idNum ?: null, $phone ?: null, $status, $notes ?: null, $id]);
                flashSuccess('تم تحديث بيانات المستفيد بنجاح.');
            } else {
                // Add
                $fileNum = getNextFileNumber($typeId);
                $pdo->prepare(
                    'INSERT INTO beneficiaries (beneficiary_type_id, file_number, full_name, id_number, phone, status, notes)
                     VALUES (?,?,?,?,?,?,?)'
                )->execute([$typeId, $fileNum, $name, $idNum ?: null, $phone ?: null, $status, $notes ?: null]);
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

$sql = 'SELECT b.*, bt.name AS type_name
        FROM beneficiaries b
        JOIN beneficiary_types bt ON bt.id = b.beneficiary_type_id
        WHERE ' . implode(' AND ', $where) . '
        ORDER BY b.beneficiary_type_id, b.file_number';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$beneficiaries = $stmt->fetchAll();

// Edit mode: fetch existing record
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
            <input type="hidden" name="id"     value="<?= $editRow ? $editRow['id'] : 0 ?>">
            <input type="hidden" name="filter_type" value="<?= e((string)$filterType) ?>">

            <div class="row g-3">
                <div class="col-md-3">
                    <label class="form-label">نوع المستفيد <span class="text-danger">*</span></label>
                    <select name="beneficiary_type_id" class="form-select" required id="typeSelect">
                        <option value="">— اختر النوع —</option>
                        <?php foreach ($types as $t): ?>
                        <option value="<?= $t['id'] ?>"
                            <?= ($editRow && $editRow['beneficiary_type_id'] == $t['id'])
                                || (!$editRow && $filterType == $t['id']) ? 'selected' : '' ?>>
                            <?= e($t['name']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label">الاسم الكامل <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" name="full_name"
                           value="<?= e($editRow['full_name'] ?? '') ?>" required>
                </div>
                <div class="col-md-2">
                    <label class="form-label">رقم الهوية</label>
                    <input type="text" class="form-control" name="id_number"
                           value="<?= e($editRow['id_number'] ?? '') ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label">رقم الهاتف</label>
                    <input type="text" class="form-control" name="phone"
                           value="<?= e($editRow['phone'] ?? '') ?>">
                </div>
                <div class="col-md-1">
                    <label class="form-label">الحالة</label>
                    <select name="status" class="form-select">
                        <option value="active"   <?= (!$editRow || $editRow['status'] === 'active')   ? 'selected' : '' ?>>نشط</option>
                        <option value="inactive" <?= ($editRow  && $editRow['status'] === 'inactive') ? 'selected' : '' ?>>موقوف</option>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label">ملاحظات</label>
                    <input type="text" class="form-control" name="notes"
                           value="<?= e($editRow['notes'] ?? '') ?>">
                </div>
                <div class="col-12 d-flex gap-2 align-items-end">
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-save me-1"></i><?= $editRow ? 'حفظ التعديلات' : 'إضافة' ?>
                    </button>
                    <?php if ($editRow): ?>
                    <a href="<?= e(ADMIN_PATH) ?>/beneficiaries.php?type=<?= $filterType ?>" class="btn btn-outline-secondary">
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
        <form method="get" action="" class="row g-2 align-items-center">
            <div class="col-md-3">
                <select name="type" class="form-select form-select-sm">
                    <option value="">جميع الأنواع</option>
                    <?php foreach ($types as $t): ?>
                    <option value="<?= $t['id'] ?>" <?= $filterType == $t['id'] ? 'selected' : '' ?>>
                        <?= e($t['name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-5">
                <input type="text" name="q" class="form-control form-control-sm"
                       placeholder="ابحث بالاسم أو رقم الهوية أو الهاتف"
                       value="<?= e($filterSearch) ?>">
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

<!-- Beneficiaries table -->
<div class="card">
    <div class="card-header d-flex align-items-center justify-content-between">
        <span><i class="bi bi-people-fill me-1"></i>قائمة المستفيدين
            <span class="badge bg-secondary ms-1"><?= count($beneficiaries) ?></span>
        </span>
    </div>
    <div class="card-body p-0">
        <?php if ($beneficiaries): ?>
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead>
                    <tr>
                        <th>رقم الملف</th>
                        <th>النوع</th>
                        <th>الاسم الكامل</th>
                        <th>رقم الهوية</th>
                        <th>الهاتف</th>
                        <th>الحالة</th>
                        <th>ملاحظات</th>
                        <th>إجراءات</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($beneficiaries as $b): ?>
                    <tr>
                        <td class="fw-bold text-primary"><?= e((string)$b['file_number']) ?></td>
                        <td><span class="badge bg-primary bg-opacity-75"><?= e($b['type_name']) ?></span></td>
                        <td><?= e($b['full_name']) ?></td>
                        <td><?= e($b['id_number'] ?? '—') ?></td>
                        <td><?= e($b['phone'] ?? '—') ?></td>
                        <td>
                            <span class="badge bg-<?= statusBadge($b['status']) ?>">
                                <?= statusLabel($b['status']) ?>
                            </span>
                        </td>
                        <td class="text-muted small"><?= e($b['notes'] ?? '') ?></td>
                        <td>
                            <div class="d-flex gap-1 flex-nowrap">
                                <a href="?edit=<?= $b['id'] ?>&type=<?= $filterType ?>&q=<?= urlencode($filterSearch) ?>"
                                   class="btn btn-sm btn-outline-primary" title="تعديل">
                                    <i class="bi bi-pencil-fill"></i>
                                </a>
                                <a href="<?= e(ADMIN_PATH) ?>/beneficiary_history.php?id=<?= $b['id'] ?>"
                                   class="btn btn-sm btn-outline-info" title="السجل">
                                    <i class="bi bi-clock-history"></i>
                                </a>
                                <form method="post" action="" class="d-inline"
                                      onsubmit="return confirm('هل تريد حذف هذا المستفيد؟')">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="action"      value="delete">
                                    <input type="hidden" name="id"          value="<?= $b['id'] ?>">
                                    <input type="hidden" name="filter_type" value="<?= $filterType ?>">
                                    <input type="hidden" name="filter_q"    value="<?= e($filterSearch) ?>">
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
<?php
});
