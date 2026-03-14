<?php
/**
 * distributions.php
 * Create / list distributions + items. PRG on all POSTs.
 */

declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
requireLogin();

$pdo   = getPDO();
$types = getBeneficiaryTypes();

/* ── POST: Create distribution ────────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';

    // ── Delete distribution ──
    if ($action === 'delete') {
        $id = (int) ($_POST['dist_id'] ?? 0);
        if ($id > 0) {
            $pdo->prepare('DELETE FROM distributions WHERE id = ?')->execute([$id]);
            flashSuccess('تم حذف التوزيعة بنجاح.');
        }
        redirect(ADMIN_PATH . '/distributions.php');
    }

    // ── Save new distribution ──
    if ($action === 'create') {
        $title  = trim($_POST['title'] ?? '');
        $date   = trim($_POST['distribution_date'] ?? '');
        $typeId = (int) ($_POST['beneficiary_type_id'] ?? 0) ?: null;
        $notes  = trim($_POST['dist_notes'] ?? '');
        $bids   = $_POST['beneficiary_ids'] ?? [];
        $cash   = $_POST['cash_amounts']    ?? [];
        $det    = $_POST['details_texts']   ?? [];
        $itnot  = $_POST['item_notes']      ?? [];

        $errors = [];
        if (!$title) $errors[] = 'عنوان التوزيعة مطلوب.';
        if (!$date)  $errors[] = 'تاريخ التوزيعة مطلوب.';
        if (empty($bids)) $errors[] = 'يُرجى اختيار مستفيد واحد على الأقل.';

        if ($errors) {
            flashError(implode(' | ', $errors));
            redirect(ADMIN_PATH . '/distributions.php?action=new&type=' . ($typeId ?? ''));
        } else {
            $pdo->beginTransaction();
            try {
                $pdo->prepare(
                    'INSERT INTO distributions (title, distribution_date, beneficiary_type_id, notes, created_by)
                     VALUES (?,?,?,?,?)'
                )->execute([$title, $date, $typeId, $notes ?: null, currentAdmin()['id']]);
                $distId = (int) $pdo->lastInsertId();

                $ins = $pdo->prepare(
                    'INSERT INTO distribution_items (distribution_id, beneficiary_id, cash_amount, details_text, notes)
                     VALUES (?,?,?,?,?)'
                );
                foreach ($bids as $k => $bid) {
                    $bid = (int) $bid;
                    if (!$bid) continue;
                    $amount  = (float) ($cash[$k] ?? 0);
                    $details = trim($det[$k] ?? '');
                    $inote   = trim($itnot[$k] ?? '');
                    $ins->execute([$distId, $bid, $amount, $details ?: null, $inote ?: null]);
                }
                $pdo->commit();
                flashSuccess('تمت إضافة التوزيعة بنجاح.');
                redirect(ADMIN_PATH . '/distributions.php?view=' . $distId);
            } catch (\Throwable $e) {
                $pdo->rollBack();
                flashError('حدث خطأ أثناء الحفظ: ' . $e->getMessage());
                redirect(ADMIN_PATH . '/distributions.php?action=new');
            }
        }
    }
}

/* ── GET ──────────────────────────────────────────────────────────────────── */
$action  = $_GET['action'] ?? '';
$viewId  = (int) ($_GET['view'] ?? 0);
$typeFilter = (int) ($_GET['type'] ?? 0);

// ── View single distribution ──
if ($viewId > 0) {
    $dist = $pdo->prepare(
        'SELECT d.*, bt.name AS type_name
         FROM distributions d LEFT JOIN beneficiary_types bt ON bt.id = d.beneficiary_type_id
         WHERE d.id = ?'
    );
    $dist->execute([$viewId]);
    $dist = $dist->fetch();

    if (!$dist) {
        flashError('التوزيعة غير موجودة.');
        redirect(ADMIN_PATH . '/distributions.php');
    }

    $items = $pdo->prepare(
        'SELECT di.*, b.full_name, b.file_number, b.id_number, b.phone, bt.name AS type_name
         FROM distribution_items di
         JOIN beneficiaries b ON b.id = di.beneficiary_id
         JOIN beneficiary_types bt ON bt.id = b.beneficiary_type_id
         WHERE di.distribution_id = ?
         ORDER BY b.file_number'
    );
    $items->execute([$viewId]);
    $items = $items->fetchAll();

    require_once __DIR__ . '/layout.php';
    renderPage('تفاصيل التوزيعة', 'distributions', function() use ($dist, $items, $viewId) {
        $totalCash = array_sum(array_column($items, 'cash_amount'));
    ?>
    <?= renderFlash() ?>
    <div class="mb-3 d-flex gap-2">
        <a href="<?= e(ADMIN_PATH) ?>/distributions.php" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-arrow-right me-1"></i> رجوع
        </a>
        <a href="<?= e(ADMIN_PATH) ?>/print_distribution.php?id=<?= $viewId ?>" target="_blank"
           class="btn btn-outline-primary btn-sm">
            <i class="bi bi-printer me-1"></i> طباعة
        </a>
    </div>

    <div class="card mb-4">
        <div class="card-header fw-bold"><i class="bi bi-info-circle me-1"></i>بيانات التوزيعة</div>
        <div class="card-body">
            <div class="row g-2">
                <div class="col-md-4"><strong>العنوان:</strong> <?= e($dist['title']) ?></div>
                <div class="col-md-3"><strong>التاريخ:</strong> <?= e($dist['distribution_date']) ?></div>
                <div class="col-md-3"><strong>النوع:</strong> <?= e($dist['type_name'] ?? '—') ?></div>
                <div class="col-md-2"><strong>إجمالي الصرف:</strong> <?= formatAmount($totalCash) ?></div>
                <?php if ($dist['notes']): ?>
                <div class="col-12 text-muted small"><strong>ملاحظات:</strong> <?= e($dist['notes']) ?></div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-header">
            <i class="bi bi-list-check me-1"></i>بنود التوزيعة
            <span class="badge bg-secondary ms-1"><?= count($items) ?></span>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead>
                        <tr>
                            <th>رقم الملف</th><th>الاسم</th><th>النوع</th>
                            <th>المبلغ النقدي</th><th>التفاصيل</th><th>ملاحظات</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($items as $it): ?>
                        <tr>
                            <td class="fw-bold"><?= e((string)$it['file_number']) ?></td>
                            <td><?= e($it['full_name']) ?></td>
                            <td><span class="badge bg-primary bg-opacity-75"><?= e($it['type_name']) ?></span></td>
                            <td><?= $it['cash_amount'] > 0 ? formatAmount($it['cash_amount']) : '—' ?></td>
                            <td class="small text-muted"><?= e($it['details_text'] ?? '') ?></td>
                            <td class="small text-muted"><?= e($it['notes'] ?? '') ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr class="table-light fw-bold">
                            <td colspan="3">الإجمالي</td>
                            <td><?= formatAmount($totalCash) ?></td>
                            <td colspan="2"></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>
    <?php
    });
    exit;
}

// ── New distribution form ──
if ($action === 'new') {
    $selTypeId = $typeFilter;

    // Beneficiaries for selection (filtered by type if provided)
    $where  = ['b.status = "active"'];
    $params = [];
    if ($selTypeId > 0) {
        $where[]  = 'b.beneficiary_type_id = ?';
        $params[] = $selTypeId;
    }
    $bens = $pdo->prepare(
        'SELECT b.*, bt.name AS type_name
         FROM beneficiaries b
         JOIN beneficiary_types bt ON bt.id = b.beneficiary_type_id
         WHERE ' . implode(' AND ', $where) . '
         ORDER BY b.beneficiary_type_id, b.file_number'
    );
    $bens->execute($params);
    $bens = $bens->fetchAll();

    require_once __DIR__ . '/layout.php';
    renderPage('توزيعة جديدة', 'distributions', function() use ($types, $bens, $selTypeId) {
    ?>
    <?= renderFlash() ?>
    <div class="mb-3">
        <a href="<?= e(ADMIN_PATH) ?>/distributions.php" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-arrow-right me-1"></i> رجوع
        </a>
    </div>

    <div class="card mb-4">
        <div class="card-header fw-bold"><i class="bi bi-plus-circle-fill me-1"></i>إنشاء توزيعة جديدة</div>
        <div class="card-body">
            <form method="post" action="<?= e(ADMIN_PATH) ?>/distributions.php" id="distForm">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="create">

                <div class="row g-3 mb-4">
                    <div class="col-md-5">
                        <label class="form-label">عنوان التوزيعة <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="title" required
                               placeholder="مثال: توزيع زكاة الفطر 1446">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">التاريخ <span class="text-danger">*</span></label>
                        <input type="date" class="form-control" name="distribution_date"
                               value="<?= date('Y-m-d') ?>" required>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">تصنيف التوزيعة</label>
                        <select name="beneficiary_type_id" class="form-select" id="distTypeFilter">
                            <option value="">عام (كل الأنواع)</option>
                            <?php foreach ($types as $t): ?>
                            <option value="<?= $t['id'] ?>" <?= $selTypeId == $t['id'] ? 'selected' : '' ?>>
                                <?= e($t['name']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">ملاحظات</label>
                        <input type="text" class="form-control" name="dist_notes">
                    </div>
                </div>

                <!-- Beneficiary search -->
                <div class="mb-3 d-flex gap-2 align-items-center">
                    <input type="text" id="benSearch" class="form-control" style="max-width:320px"
                           placeholder="ابحث عن مستفيد لإضافته…">
                    <span class="text-muted small">أو اختر من القائمة بالنقر على +</span>
                </div>

                <!-- Beneficiaries selection table -->
                <div class="table-responsive mb-3" style="max-height:320px;overflow-y:auto">
                    <table class="table table-sm table-hover mb-0" id="benTable">
                        <thead class="sticky-top">
                            <tr>
                                <th style="width:50px"></th>
                                <th>ملف</th><th>الاسم</th><th>النوع</th><th>الهوية</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($bens as $b): ?>
                            <tr data-name="<?= e($b['full_name']) ?>" data-idnum="<?= e($b['id_number'] ?? '') ?>">
                                <td>
                                    <button type="button" class="btn btn-sm btn-outline-success add-ben-btn"
                                            data-id="<?= $b['id'] ?>"
                                            data-name="<?= e($b['full_name']) ?>"
                                            data-file="<?= e((string)$b['file_number']) ?>"
                                            data-type="<?= e($b['type_name']) ?>">
                                        <i class="bi bi-plus-lg"></i>
                                    </button>
                                </td>
                                <td><?= e((string)$b['file_number']) ?></td>
                                <td><?= e($b['full_name']) ?></td>
                                <td><span class="badge bg-primary bg-opacity-75 small"><?= e($b['type_name']) ?></span></td>
                                <td class="text-muted small"><?= e($b['id_number'] ?? '') ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Selected items -->
                <h6 class="fw-bold mb-2"><i class="bi bi-check2-circle me-1 text-success"></i>المستفيدون المضافون</h6>
                <div id="selectedItems" class="mb-3">
                    <div class="text-muted small" id="emptyMsg">لم يُضف أي مستفيد بعد.</div>
                </div>

                <button type="submit" class="btn btn-primary" id="saveDistBtn" disabled>
                    <i class="bi bi-save me-1"></i>حفظ التوزيعة
                </button>
            </form>
        </div>
    </div>

<script>
const addedIds = new Set();
const selectedDiv = document.getElementById('selectedItems');
const emptyMsg    = document.getElementById('emptyMsg');
const saveBtn     = document.getElementById('saveDistBtn');

document.querySelectorAll('.add-ben-btn').forEach(btn => {
    btn.addEventListener('click', () => {
        const id   = btn.dataset.id;
        const name = btn.dataset.name;
        const file = btn.dataset.file;
        const type = btn.dataset.type;
        if (addedIds.has(id)) { return; }
        addedIds.add(id);
        emptyMsg.style.display = 'none';
        btn.classList.replace('btn-outline-success','btn-success');
        btn.disabled = true;

        const idx  = addedIds.size - 1;
        const html = `
        <div class="border rounded p-2 mb-2 bg-white" id="item-${id}">
          <div class="row g-2 align-items-center">
            <div class="col-auto fw-bold text-primary">${file}</div>
            <div class="col-md-3 fw-semibold">${name}</div>
            <div class="col-auto"><span class="badge bg-primary bg-opacity-75">${type}</span></div>
            <div class="col-md-2">
              <input type="number" step="0.01" min="0" class="form-control form-control-sm"
                     name="cash_amounts[]" placeholder="المبلغ النقدي" value="0">
            </div>
            <div class="col-md-3">
              <input type="text" class="form-control form-control-sm"
                     name="details_texts[]" placeholder="تفاصيل (عيني/نوع المساعدة)">
            </div>
            <div class="col-md-2">
              <input type="text" class="form-control form-control-sm"
                     name="item_notes[]" placeholder="ملاحظة">
            </div>
            <div class="col-auto">
              <button type="button" class="btn btn-sm btn-outline-danger remove-item"
                      data-id="${id}"><i class="bi bi-x-lg"></i></button>
            </div>
          </div>
          <input type="hidden" name="beneficiary_ids[]" value="${id}">
        </div>`;
        selectedDiv.insertAdjacentHTML('beforeend', html);
        updateSaveBtn();
    });
});

document.addEventListener('click', e => {
    if (e.target.closest('.remove-item')) {
        const btn = e.target.closest('.remove-item');
        const id  = btn.dataset.id;
        addedIds.delete(id);
        document.getElementById('item-' + id).remove();
        // Re-enable add button
        const addBtn = document.querySelector(`.add-ben-btn[data-id="${id}"]`);
        if (addBtn) {
            addBtn.classList.replace('btn-success','btn-outline-success');
            addBtn.disabled = false;
        }
        if (addedIds.size === 0) emptyMsg.style.display = '';
        updateSaveBtn();
    }
});

function updateSaveBtn() {
    saveBtn.disabled = addedIds.size === 0;
}

// Live search filter
document.getElementById('benSearch').addEventListener('input', function() {
    const q = this.value.trim().toLowerCase();
    document.querySelectorAll('#benTable tbody tr').forEach(row => {
        const name = row.dataset.name.toLowerCase();
        const idn  = row.dataset.idnum.toLowerCase();
        row.style.display = (!q || name.includes(q) || idn.includes(q)) ? '' : 'none';
    });
});
</script>
    <?php
    });
    exit;
}

// ── List distributions ──
$distWhere  = '';
$distParams = [];
if ($typeFilter > 0) {
    $distWhere  = 'WHERE d.beneficiary_type_id = ?';
    $distParams = [$typeFilter];
}

$distStmt = $pdo->prepare(
    "SELECT d.*, bt.name AS type_name,
            (SELECT COUNT(*) FROM distribution_items di WHERE di.distribution_id = d.id) AS item_count,
            (SELECT COALESCE(SUM(cash_amount),0) FROM distribution_items di WHERE di.distribution_id = d.id) AS total_cash
     FROM distributions d
     LEFT JOIN beneficiary_types bt ON bt.id = d.beneficiary_type_id
     $distWhere
     ORDER BY d.distribution_date DESC, d.id DESC"
);
$distStmt->execute($distParams);
$distributions = $distStmt->fetchAll();

require_once __DIR__ . '/layout.php';
renderPage('التوزيعات', 'distributions', function() use ($distributions, $types, $typeFilter) {
?>
<?= renderFlash() ?>

<div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
    <form method="get" class="d-flex gap-2 align-items-center">
        <select name="type" class="form-select form-select-sm" style="width:200px">
            <option value="">جميع الأنواع</option>
            <?php foreach ($types as $t): ?>
            <option value="<?= $t['id'] ?>" <?= $typeFilter == $t['id'] ? 'selected' : '' ?>>
                <?= e($t['name']) ?>
            </option>
            <?php endforeach; ?>
        </select>
        <button class="btn btn-sm btn-outline-secondary" type="submit">
            <i class="bi bi-filter me-1"></i>تصفية
        </button>
    </form>
    <a href="<?= e(ADMIN_PATH) ?>/distributions.php?action=new" class="btn btn-primary">
        <i class="bi bi-plus-circle-fill me-1"></i>توزيعة جديدة
    </a>
</div>

<div class="card">
    <div class="card-header">
        <i class="bi bi-box-seam-fill me-1"></i>قائمة التوزيعات
        <span class="badge bg-secondary ms-1"><?= count($distributions) ?></span>
    </div>
    <div class="card-body p-0">
        <?php if ($distributions): ?>
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead>
                    <tr>
                        <th>#</th><th>العنوان</th><th>التصنيف</th><th>التاريخ</th>
                        <th>المستفيدون</th><th>إجمالي الصرف</th><th>إجراءات</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($distributions as $d): ?>
                    <tr>
                        <td><?= e((string)$d['id']) ?></td>
                        <td class="fw-semibold"><?= e($d['title']) ?></td>
                        <td><?= $d['type_name']
                              ? '<span class="badge bg-primary">' . e($d['type_name']) . '</span>'
                              : '<span class="text-muted">—</span>' ?></td>
                        <td><?= e($d['distribution_date']) ?></td>
                        <td><?= e((string)$d['item_count']) ?></td>
                        <td><?= formatAmount($d['total_cash']) ?></td>
                        <td>
                            <div class="d-flex gap-1">
                                <a href="?view=<?= $d['id'] ?>" class="btn btn-sm btn-outline-primary" title="عرض">
                                    <i class="bi bi-eye-fill"></i>
                                </a>
                                <a href="<?= e(ADMIN_PATH) ?>/print_distribution.php?id=<?= $d['id'] ?>"
                                   target="_blank" class="btn btn-sm btn-outline-secondary" title="طباعة">
                                    <i class="bi bi-printer-fill"></i>
                                </a>
                                <form method="post" action="" class="d-inline"
                                      onsubmit="return confirm('هل تريد حذف هذه التوزيعة وجميع بنودها؟')">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="action"  value="delete">
                                    <input type="hidden" name="dist_id" value="<?= $d['id'] ?>">
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
            لا توجد توزيعات بعد.
            <div class="mt-2">
                <a href="<?= e(ADMIN_PATH) ?>/distributions.php?action=new" class="btn btn-primary btn-sm">
                    <i class="bi bi-plus-circle-fill me-1"></i>إنشاء توزيعة الآن
                </a>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>
<?php
});
