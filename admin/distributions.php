<?php
/**
 * distributions.php (SERVER-SIDE selection - FIXED SAVE + FILTER DROPDOWN)
 *
 * Fixes:
 * - Save distribution now ALWAYS receives title/date/type via POST + session.
 * - After save redirects to ?view=<id>
 * - List filter dropdown auto-submits on change.
 */

declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
requireLogin();

$pdo   = getPDO();
$types = getBeneficiaryTypes();

function builderKey(int $distId): string { return $distId > 0 ? 'edit_' . $distId : 'new'; }

function &getBuilder(string $key): array {
    if (!isset($_SESSION['dist_builder'])) $_SESSION['dist_builder'] = [];
    if (!isset($_SESSION['dist_builder'][$key])) {
        $_SESSION['dist_builder'][$key] = [
            'type_id' => 0,
            'title' => '',
            'date' => date('Y-m-d'),
            'notes' => '',
            'mode' => 'cash',
            'fallback_cash' => 20.0,
            'items' => [],
        ];
    }
    return $_SESSION['dist_builder'][$key];
}

function clearBuilder(string $key): void {
    if (isset($_SESSION['dist_builder'][$key])) unset($_SESSION['dist_builder'][$key]);
}

function fetchBeneficiaryById(PDO $pdo, int $id): ?array {
    $st = $pdo->prepare(
        'SELECT b.*, bt.name_ar AS type_name
         FROM beneficiaries b
         JOIN beneficiary_types bt ON bt.id = b.beneficiary_type_id
         WHERE b.id = ?'
    );
    $st->execute([$id]);
    $r = $st->fetch();
    return $r ?: null;
}

function normalizeHeaderToBuilder(array &$builder, array $post): void {
    // take from POST first, otherwise keep existing builder values
    $title = isset($post['title']) ? trim((string)$post['title']) : $builder['title'];
    $date  = isset($post['distribution_date']) ? trim((string)$post['distribution_date']) : $builder['date'];
    $notes = isset($post['dist_notes']) ? trim((string)$post['dist_notes']) : $builder['notes'];
    $type  = isset($post['beneficiary_type_id']) ? (int)$post['beneficiary_type_id'] : (int)$builder['type_id'];

    $mode = isset($post['dist_mode']) ? (string)$post['dist_mode'] : (string)$builder['mode'];
    if ($mode !== 'cash' && $mode !== 'in_kind') $mode = 'cash';

    $fallback = isset($post['fallback_cash']) ? (float)$post['fallback_cash'] : (float)$builder['fallback_cash'];

    $builder['title'] = $title;
    $builder['date'] = $date;
    $builder['notes'] = $notes;
    if ($type > 0) $builder['type_id'] = $type;
    $builder['mode'] = $mode;
    $builder['fallback_cash'] = $fallback;
}

/* ───────────────────────────────── POST ─────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'delete') {
        $id = (int)($_POST['dist_id'] ?? 0);
        if ($id > 0) {
            $pdo->beginTransaction();
            try {
                $pdo->prepare('DELETE FROM distribution_items WHERE distribution_id = ?')->execute([$id]);
                $pdo->prepare('DELETE FROM distributions WHERE id = ?')->execute([$id]);
                $pdo->commit();
                flashSuccess('تم حذف التوزيعة وبنودها بنجاح.');
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                flashError('خطأ أثناء الحذف: ' . $e->getMessage());
            }
        }
        redirect(ADMIN_PATH . '/distributions.php');
    }

    if (in_array($action, ['set_type','add_selected','remove_selected','update_item','save_dist'], true)) {
        $distId = (int)($_POST['dist_id'] ?? 0);
        $key = builderKey($distId);
        $builder = &getBuilder($key);

        // ALWAYS sync header to builder
        normalizeHeaderToBuilder($builder, $_POST);

        if ($action === 'set_type') {
            redirect(ADMIN_PATH . '/distributions.php?action=' . ($distId > 0 ? 'edit&id='.$distId : 'new') . '&type=' . (int)$builder['type_id']);
        }

        if ($action === 'add_selected') {
            $selected = (array)($_POST['select_beneficiaries'] ?? []);
            $countAdded = 0;

            foreach ($selected as $sid) {
                $bid = (int)$sid;
                if ($bid <= 0) continue;

                $ben = fetchBeneficiaryById($pdo, $bid);
                if (!$ben) continue;

                if ((int)$builder['type_id'] > 0 && (int)$ben['beneficiary_type_id'] !== (int)$builder['type_id']) {
                    continue;
                }

                if (!isset($builder['items'][$bid])) {
                    $mc = $ben['monthly_cash'];
                    if ($mc !== null && (float)$mc > 0) {
                        $cash = (float)$mc;
                    } else {
                        $cash = ($builder['mode'] === 'cash') ? (float)$builder['fallback_cash'] : 0.0;
                    }

                    $builder['items'][$bid] = ['cash'=>$cash,'details'=>'','notes'=>''];
                    $countAdded++;
                }
            }

            flashSuccess('تمت إضافة: ' . $countAdded . ' مستفيد.');
            redirect(ADMIN_PATH . '/distributions.php?action=' . ($distId > 0 ? 'edit&id='.$distId : 'new') . '&type=' . (int)$builder['type_id']);
        }

        if ($action === 'remove_selected') {
            $selected = (array)($_POST['remove_beneficiaries'] ?? []);
            $countRemoved = 0;

            foreach ($selected as $sid) {
                $bid = (int)$sid;
                if ($bid <= 0) continue;
                if (isset($builder['items'][$bid])) {
                    unset($builder['items'][$bid]);
                    $countRemoved++;
                }
            }

            flashSuccess('تم حذف المحدد: ' . $countRemoved);
            redirect(ADMIN_PATH . '/distributions.php?action=' . ($distId > 0 ? 'edit&id='.$distId : 'new') . '&type=' . (int)$builder['type_id']);
        }

        if ($action === 'update_item') {
            $bid = (int)($_POST['bid'] ?? 0);
            if ($bid > 0 && isset($builder['items'][$bid])) {
                $builder['items'][$bid]['cash'] = (float)($_POST['cash'] ?? $builder['items'][$bid]['cash']);
                $builder['items'][$bid]['details'] = trim((string)($_POST['details'] ?? $builder['items'][$bid]['details']));
                $builder['items'][$bid]['notes'] = trim((string)($_POST['notes'] ?? $builder['items'][$bid]['notes']));
                flashSuccess('تم تحديث بيانات المستفيد داخل التوزيعة.');
            }
            redirect(ADMIN_PATH . '/distributions.php?action=' . ($distId > 0 ? 'edit&id='.$distId : 'new') . '&type=' . (int)$builder['type_id']);
        }

        if ($action === 'save_dist') {
            // FINAL validation (builder is now synced with POST)
            $errors = [];
            if (trim((string)$builder['title']) === '') $errors[] = 'عنوان التوزيعة مطلوب.';
            if (trim((string)$builder['date']) === '')  $errors[] = 'تاريخ التوزيعة مطلوب.';
            if ((int)$builder['type_id'] <= 0) $errors[] = 'تصنيف التوزيعة مطلوب.';
            if (empty($builder['items'])) $errors[] = 'لم يتم إضافة أي مستفيد.';

            if ($errors) {
                flashError(implode(' | ', $errors));
                redirect(ADMIN_PATH . '/distributions.php?action=' . ($distId > 0 ? 'edit&id='.$distId : 'new') . '&type=' . (int)$builder['type_id']);
            }

            $pdo->beginTransaction();
            try {
                if ($distId > 0) {
                    $pdo->prepare(
                        'UPDATE distributions
                         SET title = ?, distribution_date = ?, beneficiary_type_id = ?, notes = ?
                         WHERE id = ?'
                    )->execute([
                        $builder['title'],
                        $builder['date'],
                        (int)$builder['type_id'],
                        ($builder['notes'] !== '' ? $builder['notes'] : null),
                        $distId
                    ]);

                    $pdo->prepare('DELETE FROM distribution_items WHERE distribution_id = ?')->execute([$distId]);
                    $saveId = $distId;
                } else {
                    $pdo->prepare(
                        'INSERT INTO distributions (title, distribution_date, beneficiary_type_id, notes)
                         VALUES (?,?,?,?)'
                    )->execute([
                        $builder['title'],
                        $builder['date'],
                        (int)$builder['type_id'],
                        ($builder['notes'] !== '' ? $builder['notes'] : null),
                    ]);
                    $saveId = (int)$pdo->lastInsertId();
                }

                $ins = $pdo->prepare(
                    'INSERT INTO distribution_items (distribution_id, beneficiary_id, cash_amount, details_text, notes)
                     VALUES (?,?,?,?,?)'
                );

                foreach ($builder['items'] as $bid => $it) {
                    $ins->execute([
                        $saveId,
                        (int)$bid,
                        (float)($it['cash'] ?? 0),
                        (($it['details'] ?? '') !== '' ? $it['details'] : null),
                        (($it['notes'] ?? '') !== '' ? $it['notes'] : null),
                    ]);
                }

                $pdo->commit();
                clearBuilder($key);
                flashSuccess('تم حفظ التوزيعة بنجاح.');
                redirect(ADMIN_PATH . '/distributions.php?view=' . $saveId);
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                flashError('خطأ أثناء الحفظ: ' . $e->getMessage());
                redirect(ADMIN_PATH . '/distributions.php?action=' . ($distId > 0 ? 'edit&id='.$distId : 'new') . '&type=' . (int)$builder['type_id']);
            }
        }
    }
}

/* ───────────────────────────────── GET routing ───────────────────────────── */
$action     = (string)($_GET['action'] ?? '');
$viewId     = (int)($_GET['view'] ?? 0);
$editId     = (int)($_GET['id'] ?? 0);
$typeFilter = (int)($_GET['type'] ?? 0);

/* VIEW */

// DEBUG (temporary): show count from same DB connection
$dbg = $pdo->query("SELECT COUNT(*) FROM distributions")->fetchColumn();
flashSuccess("DEBUG distributions count (same connection): " . $dbg);

if ($viewId > 0) {
    $distStmt = $pdo->prepare(
        'SELECT d.*, bt.name_ar AS type_name
         FROM distributions d
         LEFT JOIN beneficiary_types bt ON bt.id = d.beneficiary_type_id
         WHERE d.id = ?'
    );
    $distStmt->execute([$viewId]);
    $dist = $distStmt->fetch();
    if (!$dist) { flashError('التوزيعة غير موجودة.'); redirect(ADMIN_PATH . '/distributions.php'); }

    $itemsStmt = $pdo->prepare(
        'SELECT di.*, b.full_name, b.file_number, b.id_number, b.phone, bt.name_ar AS type_name
         FROM distribution_items di
         JOIN beneficiaries b ON b.id = di.beneficiary_id
         JOIN beneficiary_types bt ON bt.id = b.beneficiary_type_id
         WHERE di.distribution_id = ?
         ORDER BY b.beneficiary_type_id, b.file_number'
    );
    $itemsStmt->execute([$viewId]);
    $items = $itemsStmt->fetchAll();

    require_once __DIR__ . '/layout.php';
    renderPage('تفاصيل التوزيعة', 'distributions', function () use ($dist, $items, $viewId) {
        $totalCash = array_sum(array_map(fn($r) => (float)($r['cash_amount'] ?? 0), $items));
        ?>
        <?= renderFlash() ?>
        <div class="mb-3 d-flex gap-2 flex-wrap">
            <a href="<?= e(ADMIN_PATH) ?>/distributions.php" class="btn btn-outline-secondary btn-sm">رجوع</a>
            <a href="<?= e(ADMIN_PATH) ?>/distributions.php?action=edit&id=<?= (int)$viewId ?>" class="btn btn-outline-primary btn-sm">تعديل</a>
            <a href="<?= e(ADMIN_PATH) ?>/print_distribution.php?id=<?= (int)$viewId ?>" target="_blank" class="btn btn-outline-secondary btn-sm">طباعة</a>
        </div>

        <div class="card mb-3">
            <div class="card-header fw-bold">بيانات التوزيعة</div>
            <div class="card-body">
                <div class="row g-2">
                    <div class="col-md-4"><strong>العنوان:</strong> <?= e((string)$dist['title']) ?></div>
                    <div class="col-md-3"><strong>التاريخ:</strong> <?= e((string)$dist['distribution_date']) ?></div>
                    <div class="col-md-3"><strong>التصنيف:</strong> <?= e((string)($dist['type_name'] ?? '—')) ?></div>
                    <div class="col-md-2"><strong>الإجمالي:</strong> <?= formatAmount($totalCash) ?></div>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-header fw-bold">بنود التوزيعة <span class="badge bg-secondary ms-1"><?= count($items) ?></span></div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead>
                        <tr><th>رقم الملف</th><th>الاسم</th><th>الهاتف</th><th>النوع</th><th>المبلغ</th><th>التفاصيل</th></tr>
                        </thead>
                        <tbody>
                        <?php foreach ($items as $it): ?>
                            <tr>
                                <td class="fw-bold"><?= e((string)$it['file_number']) ?></td>
                                <td><?= e((string)$it['full_name']) ?></td>
                                <td><?= e((string)($it['phone'] ?? '—')) ?></td>
                                <td><span class="badge bg-primary bg-opacity-75"><?= e((string)$it['type_name']) ?></span></td>
                                <td><?= ((float)$it['cash_amount'] > 0) ? formatAmount((float)$it['cash_amount']) : '—' ?></td>
                                <td class="small text-muted"><?= e((string)($it['details_text'] ?? '')) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                        <tfoot><tr class="table-light fw-bold"><td colspan="4">الإجمالي</td><td><?= formatAmount($totalCash) ?></td><td></td></tr></tfoot>
                    </table>
                </div>
            </div>
        </div>
        <?php
    });
    exit;
}

/* NEW/EDIT */
if ($action === 'new' || $action === 'edit') {
    $isEdit = ($action === 'edit');
    $distId = $isEdit ? (int)$editId : 0;
    if ($isEdit && $distId <= 0) { flashError('رقم التوزيعة غير صحيح.'); redirect(ADMIN_PATH . '/distributions.php'); }

    $key = builderKey($distId);
    $builder = &getBuilder($key);

    // For new page: accept type from query
    if (!$isEdit && $typeFilter > 0 && (int)$builder['type_id'] === 0) $builder['type_id'] = $typeFilter;

    // For edit: initialize header from DB once
    if ($isEdit && (int)$builder['type_id'] === 0) {
        $st = $pdo->prepare('SELECT * FROM distributions WHERE id=?');
        $st->execute([$distId]);
        $d = $st->fetch();
        if (!$d) { flashError('التوزيعة غير موجودة.'); redirect(ADMIN_PATH . '/distributions.php'); }
        $builder['type_id'] = (int)$d['beneficiary_type_id'];
        $builder['title'] = (string)$d['title'];
        $builder['date'] = (string)$d['distribution_date'];
        $builder['notes'] = (string)($d['notes'] ?? '');
    }

    $selTypeId = (int)$builder['type_id'];
    $q = trim((string)($_GET['q'] ?? ''));

    $where = ['b.status="active"'];
    $params = [];
    if ($selTypeId > 0) { $where[] = 'b.beneficiary_type_id = ?'; $params[] = $selTypeId; }
    if ($q !== '') {
        $where[] = '(b.full_name LIKE ? OR b.id_number LIKE ? OR b.phone LIKE ?)';
        $like = '%'.$q.'%';
        array_push($params, $like, $like, $like);
    }

    $st = $pdo->prepare(
        'SELECT b.*, bt.name_ar AS type_name
         FROM beneficiaries b
         JOIN beneficiary_types bt ON bt.id=b.beneficiary_type_id
         WHERE ' . implode(' AND ', $where) . '
         ORDER BY b.file_number'
    );
    $st->execute($params);
    $bens = $st->fetchAll();

    // Added items view
    $added = [];
    if (!empty($builder['items'])) {
        $ids = array_keys($builder['items']);
        $in = implode(',', array_fill(0, count($ids), '?'));
        $st = $pdo->prepare(
            "SELECT b.*, bt.name_ar AS type_name
             FROM beneficiaries b
             JOIN beneficiary_types bt ON bt.id=b.beneficiary_type_id
             WHERE b.id IN ($in)"
        );
        $st->execute(array_map('intval', $ids));
        $rows = $st->fetchAll();
        $idx = [];
        foreach ($rows as $r) $idx[(int)$r['id']] = $r;

        foreach ($builder['items'] as $bid => $it) {
            if (!isset($idx[(int)$bid])) continue;
            $r = $idx[(int)$bid];
            $added[] = [
                'id' => (int)$bid,
                'file_number' => (int)$r['file_number'],
                'full_name' => (string)$r['full_name'],
                'phone' => (string)($r['phone'] ?? ''),
                'monthly_cash' => $r['monthly_cash'],
                'cash' => (float)($it['cash'] ?? 0),
                'details' => (string)($it['details'] ?? ''),
                'notes' => (string)($it['notes'] ?? ''),
            ];
        }
        usort($added, fn($a,$b) => $a['file_number'] <=> $b['file_number']);
    }

    require_once __DIR__ . '/layout.php';
    renderPage($isEdit ? 'تعديل التوزيعة' : 'توزيعة جديدة', 'distributions', function () use (
        $types, $isEdit, $distId, $selTypeId, $q, $builder, $bens, $added
    ) {
        ?>
        <?= renderFlash() ?>

        <div class="mb-3 d-flex gap-2 flex-wrap">
            <a href="<?= e(ADMIN_PATH) ?>/distributions.php" class="btn btn-outline-secondary btn-sm">رجوع</a>
        </div>

        <div class="card mb-4">
            <div class="card-header fw-bold"><?= $isEdit ? 'تعديل التوزيعة' : 'إنشاء توزيعة جديدة' ?></div>
            <div class="card-body">

                <!-- Header (type dropdown submits) -->
                <form method="post" action="<?= e(ADMIN_PATH) ?>/distributions.php" class="mb-3">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="set_type">
                    <input type="hidden" name="dist_id" value="<?= (int)$distId ?>">

                    <div class="row g-3">
                        <div class="col-md-5">
                            <label class="form-label">عنوان التوزيعة <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="title" required value="<?= e((string)$builder['title']) ?>">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">التاريخ <span class="text-danger">*</span></label>
                            <input type="date" class="form-control" name="distribution_date" required value="<?= e((string)$builder['date']) ?>">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">تصنيف التوزيعة <span class="text-danger">*</span></label>
                            <select name="beneficiary_type_id" class="form-select" required onchange="this.form.submit()">
                                <option value="">— اختر النوع —</option>
                                <?php foreach ($types as $t): ?>
                                    <option value="<?= (int)$t['id'] ?>" <?= (int)$selTypeId === (int)$t['id'] ? 'selected' : '' ?>>
                                        <?= e((string)$t['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">ملاحظات</label>
                            <input type="text" class="form-control" name="dist_notes" value="<?= e((string)$builder['notes']) ?>">
                        </div>
                    </div>
                </form>

                <!-- Beneficiaries list -->
                <form method="get" action="" class="d-flex gap-2 align-items-center mb-2">
                    <input type="hidden" name="action" value="<?= e($isEdit ? 'edit' : 'new') ?>">
                    <?php if ($isEdit): ?><input type="hidden" name="id" value="<?= (int)$distId ?>"><?php endif; ?>
                    <input type="hidden" name="type" value="<?= (int)$selTypeId ?>">
                    <input type="text" name="q" class="form-control" placeholder="ابحث..." value="<?= e($q) ?>">
                    <button class="btn btn-outline-secondary">بحث</button>
                </form>

                <form method="post" action="<?= e(ADMIN_PATH) ?>/distributions.php" class="mb-3">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="add_selected">
                    <input type="hidden" name="dist_id" value="<?= (int)$distId ?>">
                    <input type="hidden" name="title" value="<?= e((string)$builder['title']) ?>">
                    <input type="hidden" name="distribution_date" value="<?= e((string)$builder['date']) ?>">
                    <input type="hidden" name="beneficiary_type_id" value="<?= (int)$selTypeId ?>">
                    <input type="hidden" name="dist_notes" value="<?= e((string)$builder['notes']) ?>">
                    <input type="hidden" name="dist_mode" value="<?= e((string)$builder['mode']) ?>">
                    <input type="hidden" name="fallback_cash" value="<?= e((string)$builder['fallback_cash']) ?>">

                    <div class="table-responsive" style="max-height:280px;overflow:auto">
                        <table class="table table-sm table-hover mb-0">
                            <thead class="sticky-top">
                                <tr>
                                    <th style="width:34px" class="text-center">
                                        <input type="checkbox" onclick="document.querySelectorAll('.sel-ben').forEach(cb=>cb.checked=this.checked)">
                                    </th>
                                    <th style="width:70px">ملف</th>
                                    <th>الاسم</th>
                                    <th style="width:120px">الهاتف</th>
                                    <th style="width:90px">راتب</th>
                                    <th style="width:130px">الهوية</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($bens as $b): ?>
                                <tr>
                                    <td class="text-center"><input type="checkbox" class="sel-ben" name="select_beneficiaries[]" value="<?= (int)$b['id'] ?>"></td>
                                    <td class="fw-bold"><?= e((string)$b['file_number']) ?></td>
                                    <td><?= e((string)$b['full_name']) ?></td>
                                    <td><?= e((string)($b['phone'] ?? '—')) ?></td>
                                    <td class="fw-bold"><?= $b['monthly_cash'] !== null ? e(number_format((float)$b['monthly_cash'],2)) : '—' ?></td>
                                    <td class="text-muted"><?= e((string)($b['id_number'] ?? '')) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <button type="submit" class="btn btn-outline-primary btn-sm mt-2">إضافة المحدد</button>
                </form>

                <!-- Added -->
                <h6 class="fw-bold mb-2">المستفيدون المضافون <span class="badge bg-secondary ms-1"><?= count($added) ?></span></h6>

                <?php if (empty($added)): ?>
                    <div class="text-muted small">لم يُضف أي مستفيد بعد.</div>
                <?php else: ?>
                    <form method="post" action="<?= e(ADMIN_PATH) ?>/distributions.php" class="mb-2">
                        <?= csrfField() ?>
                        <input type="hidden" name="action" value="remove_selected">
                        <input type="hidden" name="dist_id" value="<?= (int)$distId ?>">
                        <button type="submit" class="btn btn-outline-danger btn-sm">حذف المحدد</button>
                        <label class="small ms-2">
                            <input type="checkbox" onclick="document.querySelectorAll('.rm-ben').forEach(cb=>cb.checked=this.checked)">
                            تحديد الكل
                        </label>

                        <div class="table-responsive mt-2">
                            <table class="table table-sm table-bordered mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th style="width:34px"></th>
                                        <th style="width:70px">ملف</th>
                                        <th>الاسم</th>
                                        <th style="width:120px">الهاتف</th>
                                        <th style="width:100px">المبلغ</th>
                                        <th>تفاصيل</th>
                                        <th>ملاحظات</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($added as $it): ?>
                                    <tr>
                                        <td class="text-center"><input type="checkbox" class="rm-ben" name="remove_beneficiaries[]" value="<?= (int)$it['id'] ?>"></td>
                                        <td class="fw-bold"><?= e((string)$it['file_number']) ?></td>
                                        <td><?= e((string)$it['full_name']) ?></td>
                                        <td><?= e((string)($it['phone'] ?? '—')) ?></td>

                                        <td colspan="3">
                                            <form method="post" action="<?= e(ADMIN_PATH) ?>/distributions.php" class="d-flex gap-2">
                                                <?= csrfField() ?>
                                                <input type="hidden" name="action" value="update_item">
                                                <input type="hidden" name="dist_id" value="<?= (int)$distId ?>">
                                                <input type="hidden" name="bid" value="<?= (int)$it['id'] ?>">

                                                <input type="hidden" name="title" value="<?= e((string)$builder['title']) ?>">
                                                <input type="hidden" name="distribution_date" value="<?= e((string)$builder['date']) ?>">
                                                <input type="hidden" name="beneficiary_type_id" value="<?= (int)$selTypeId ?>">
                                                <input type="hidden" name="dist_notes" value="<?= e((string)$builder['notes']) ?>">

                                                <input type="number" step="0.01" min="0" class="form-control form-control-sm" style="max-width:110px"
                                                       name="cash" value="<?= e((string)$it['cash']) ?>">
                                                <input type="text" class="form-control form-control-sm" name="details" placeholder="تفاصيل" value="<?= e((string)$it['details']) ?>">
                                                <input type="text" class="form-control form-control-sm" name="notes" placeholder="ملاحظات" value="<?= e((string)$it['notes']) ?>">
                                                <button class="btn btn-sm btn-outline-secondary" type="submit">تحديث</button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </form>

                    <form method="post" action="<?= e(ADMIN_PATH) ?>/distributions.php">
                        <?= csrfField() ?>
                        <input type="hidden" name="action" value="save_dist">
                        <input type="hidden" name="dist_id" value="<?= (int)$distId ?>">
                        <input type="hidden" name="title" value="<?= e((string)$builder['title']) ?>">
                        <input type="hidden" name="distribution_date" value="<?= e((string)$builder['date']) ?>">
                        <input type="hidden" name="beneficiary_type_id" value="<?= (int)$selTypeId ?>">
                        <input type="hidden" name="dist_notes" value="<?= e((string)$builder['notes']) ?>">
                        <button type="submit" class="btn btn-primary">حفظ التوزيعة</button>
                    </form>
                <?php endif; ?>

            </div>
        </div>
        <?php
    });
    exit;
}

/* ───────────────────────────────── LIST ─────────────────────────────────── */
$distWhere  = '';
$distParams = [];
if ($typeFilter > 0) {
    $distWhere  = 'WHERE d.beneficiary_type_id = ?';
    $distParams = [$typeFilter];
}

$distStmt = $pdo->prepare(
    "SELECT d.*, bt.name_ar AS type_name,
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
renderPage('التوزيعات', 'distributions', function () use ($distributions, $types, $typeFilter) {
?>
<?= renderFlash() ?>

<div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
    <form method="get" class="d-flex gap-2 align-items-center">
        <select name="type" class="form-select form-select-sm" style="width:220px" onchange="this.form.submit()">
            <option value="">جميع الأنواع</option>
            <?php foreach ($types as $t): ?>
                <option value="<?= (int)$t['id'] ?>" <?= (int)$typeFilter === (int)$t['id'] ? 'selected' : '' ?>>
                    <?= e((string)$t['name']) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </form>

    <a href="<?= e(ADMIN_PATH) ?>/distributions.php?action=new" class="btn btn-primary">توزيعة جديدة</a>
</div>

<div class="card">
    <div class="card-header">قائمة التوزيعات <span class="badge bg-secondary ms-1"><?= count($distributions) ?></span></div>
    <div class="card-body p-0">
        <?php if ($distributions): ?>
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead>
                <tr>
                    <th>#</th><th>العنوان</th><th>التصنيف</th><th>التاريخ</th>
                    <th>المستفيدون</th><th>الإجمالي</th><th>إجراءات</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($distributions as $d): ?>
                    <tr>
                        <td><?= e((string)$d['id']) ?></td>
                        <td class="fw-semibold"><?= e((string)$d['title']) ?></td>
                        <td><?= !empty($d['type_name']) ? '<span class="badge bg-primary">'.e((string)$d['type_name']).'</span>' : '—' ?></td>
                        <td><?= e((string)$d['distribution_date']) ?></td>
                        <td><?= e((string)$d['item_count']) ?></td>
                        <td><?= formatAmount((float)$d['total_cash']) ?></td>
                        <td>
                            <div class="d-flex gap-1">
                                <a href="?view=<?= (int)$d['id'] ?>" class="btn btn-sm btn-outline-primary">عرض</a>
                                <a href="<?= e(ADMIN_PATH) ?>/distributions.php?action=edit&id=<?= (int)$d['id'] ?>" class="btn btn-sm btn-outline-info">تعديل</a>
                                <a href="<?= e(ADMIN_PATH) ?>/print_distribution.php?id=<?= (int)$d['id'] ?>" target="_blank" class="btn btn-sm btn-outline-secondary">طباعة</a>
                                <form method="post" action="" class="d-inline" onsubmit="return confirm('حذف التوزيعة؟')">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="dist_id" value="<?= (int)$d['id'] ?>">
                                    <button class="btn btn-sm btn-outline-danger" type="submit">حذف</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php else: ?>
            <div class="p-4 text-center text-muted">لا توجد توزيعات بعد.</div>
        <?php endif; ?>
    </div>
</div>
<?php
});