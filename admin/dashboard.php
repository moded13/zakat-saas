<?php
/**
 * dashboard.php
 * Hero header + stat cards + quick actions + latest distributions table.
 */

declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
requireLogin();

$pdo   = getPDO();
$types = getBeneficiaryTypes(); // returns name_ar now

// Counts per type
$typeCounts = [];
foreach ($types as $t) {
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM beneficiaries WHERE beneficiary_type_id = ? AND status = "active"');
    $stmt->execute([(int)$t['id']]);
    $typeCounts[(int)$t['id']] = (int)$stmt->fetchColumn();
}

$totalBeneficiaries = array_sum($typeCounts);
$totalDistributions = (int)$pdo->query('SELECT COUNT(*) FROM distributions')->fetchColumn();

// Latest 10 distributions
$latestDist = $pdo->query(
    'SELECT d.*,
            bt.name_ar AS type_name,
            (SELECT COUNT(*) FROM distribution_items di WHERE di.distribution_id = d.id) AS item_count,
            (SELECT COALESCE(SUM(cash_amount),0) FROM distribution_items di WHERE di.distribution_id = d.id) AS total_cash
     FROM distributions d
     LEFT JOIN beneficiary_types bt ON bt.id = d.beneficiary_type_id
     ORDER BY d.created_at DESC
     LIMIT 10'
)->fetchAll();

// Card colors
$cardColors = ['#1a4a8a', '#0d6efd', '#198754', '#dc3545', '#6f42c1', '#0dcaf0'];

require_once __DIR__ . '/layout.php';
renderPage('لوحة التحكم', 'dashboard', function () use (
    $types,
    $typeCounts,
    $totalBeneficiaries,
    $totalDistributions,
    $latestDist,
    $cardColors
) {
    $orgName = getSetting('org_name', APP_NAME);
?>
<!-- Hero header -->
<div class="hero-header mb-4">
    <div class="row align-items-center g-0">
        <div class="col">
            <h2><i class="bi bi-stars me-2"></i>مرحباً بك في <?= e($orgName) ?></h2>
            <p>
                اليوم: <?= e(date('Y-m-d')) ?>
                &nbsp;|&nbsp;
                إجمالي المستفيدين النشطين: <strong><?= (int)$totalBeneficiaries ?></strong>
            </p>
        </div>
        <div class="col-auto d-none d-md-block">
            <a href="<?= e(ADMIN_PATH) ?>/distributions.php?action=new" class="btn btn-light fw-bold me-2">
                <i class="bi bi-plus-circle-fill me-1"></i> توزيعة جديدة
            </a>
            <a href="<?= e(ADMIN_PATH) ?>/import.php" class="btn btn-outline-light fw-bold">
                <i class="bi bi-upload me-1"></i> استيراد
            </a>
        </div>
    </div>
</div>

<?= function_exists('renderFlash') ? renderFlash() : '' ?>

<!-- Stat cards -->
<div class="row g-3 mb-4">
    <?php foreach ($types as $i => $t): ?>
    <?php
        $typeId = (int)$t['id'];
        $label  = $t['name_ar'] ?? ($t['name'] ?? '');
    ?>
    <div class="col-6 col-md-4 col-xl-2">
        <div class="stat-card" style="background: <?= e($cardColors[$i % count($cardColors)]) ?>;">
            <div class="stat-value"><?= (int)($typeCounts[$typeId] ?? 0) ?></div>
            <div class="stat-label"><?= e((string)$label) ?></div>
            <i class="bi bi-people-fill stat-icon"></i>
        </div>
    </div>
    <?php endforeach; ?>

    <div class="col-6 col-md-4 col-xl-2">
        <div class="stat-card" style="background: #495057;">
            <div class="stat-value"><?= (int)$totalDistributions ?></div>
            <div class="stat-label">التوزيعات</div>
            <i class="bi bi-box-seam-fill stat-icon"></i>
        </div>
    </div>
</div>

<!-- Quick actions (mobile) -->
<div class="d-md-none mb-3 d-flex gap-2">
    <a href="<?= e(ADMIN_PATH) ?>/distributions.php?action=new" class="btn btn-primary flex-fill">
        <i class="bi bi-plus-circle-fill me-1"></i> توزيعة جديدة
    </a>
    <a href="<?= e(ADMIN_PATH) ?>/import.php" class="btn btn-outline-primary flex-fill">
        <i class="bi bi-upload me-1"></i> استيراد
    </a>
</div>

<!-- Latest distributions -->
<div class="card">
    <div class="card-header d-flex align-items-center justify-content-between">
        <span><i class="bi bi-clock-history me-1"></i>آخر التوزيعات</span>
        <a href="<?= e(ADMIN_PATH) ?>/distributions.php" class="btn btn-sm btn-outline-primary">عرض الكل</a>
    </div>

    <div class="card-body p-0">
        <?php if (!empty($latestDist)): ?>
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead>
                <tr>
                    <th>#</th>
                    <th>العنوان</th>
                    <th>التصنيف</th>
                    <th>التاريخ</th>
                    <th>المستفيدون</th>
                    <th>إجمالي الصرف</th>
                    <th></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($latestDist as $d): ?>
                    <tr>
                        <td><?= e((string)$d['id']) ?></td>
                        <td class="fw-semibold"><?= e((string)$d['title']) ?></td>
                        <td>
                            <?php if (!empty($d['type_name'])): ?>
                                <span class="badge bg-primary"><?= e((string)$d['type_name']) ?></span>
                            <?php else: ?>
                                <span class="text-muted">—</span>
                            <?php endif; ?>
                        </td>
                        <td><?= e((string)$d['distribution_date']) ?></td>
                        <td><?= e((string)$d['item_count']) ?></td>
                        <td><?= function_exists('formatAmount') ? formatAmount((float)$d['total_cash']) : e((string)$d['total_cash']) ?></td>
                        <td>
                            <a href="<?= e(ADMIN_PATH) ?>/print_distribution.php?id=<?= e((string)$d['id']) ?>"
                               target="_blank" class="btn btn-sm btn-outline-secondary">
                                <i class="bi bi-printer"></i>
                            </a>
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