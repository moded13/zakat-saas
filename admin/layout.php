<?php
/**
 * layout.php
 * Shared layout: fixed RTL sidebar + header + footer.
 * Usage:  renderPage(string $title, string $activeMenu, callable $callback)
 */

declare(strict_types=1);

if (!function_exists('renderPage')) {

function renderPage(string $title, string $activeMenu, callable $callback): void
{
    $orgName   = getSetting('org_name', APP_NAME);
    $adminName = currentAdmin()['full_name'] ?: currentAdmin()['username'];

    $navItems = [
        'dashboard'           => ['icon' => 'bi-speedometer2',     'label' => 'لوحة التحكم',    'href' => ADMIN_PATH . '/dashboard.php'],
        'beneficiaries'       => ['icon' => 'bi-people-fill',      'label' => 'المستفيدون',      'href' => ADMIN_PATH . '/beneficiaries.php'],
        'distributions'       => ['icon' => 'bi-box-seam-fill',    'label' => 'التوزيعات',       'href' => ADMIN_PATH . '/distributions.php'],
        'import'              => ['icon' => 'bi-upload',            'label' => 'استيراد البيانات','href' => ADMIN_PATH . '/import.php'],
        'settings'            => ['icon' => 'bi-gear-fill',        'label' => 'الإعدادات',       'href' => ADMIN_PATH . '/settings.php'],
    ];
    ?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title) ?> — <?= e($orgName) ?></title>
<!-- Bootstrap 5 RTL -->
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.rtl.min.css">
<!-- Bootstrap Icons -->
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<!-- Cairo font -->
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;500;600;700;800&display=swap" rel="stylesheet">

<style>
/* ── Root variables ── */
:root {
    --sidebar-w: 260px;
    --sidebar-bg: linear-gradient(180deg, #0f2c5e 0%, #1a4a8a 55%, #1e5fa5 100%);
    --sidebar-text: rgba(255,255,255,.85);
    --sidebar-active-bg: rgba(255,255,255,.15);
    --sidebar-hover-bg: rgba(255,255,255,.08);
    --topbar-h: 60px;
    --body-bg: #f0f4f8;
    --card-radius: 14px;
    --card-shadow: 0 2px 16px rgba(30,80,160,.10);
    --primary: #1a4a8a;
}

/* ── Base ── */
*, *::before, *::after { box-sizing: border-box; }
body {
    font-family: 'Cairo', sans-serif;
    background: var(--body-bg);
    margin: 0;
    min-height: 100vh;
    color: #1e2a38;
}

/* ── Sidebar ── */
#sidebar {
    position: fixed;
    top: 0;
    right: 0;
    width: var(--sidebar-w);
    height: 100vh;
    background: var(--sidebar-bg);
    z-index: 1040;
    display: flex;
    flex-direction: column;
    overflow-y: auto;
    transition: transform .25s ease;
}

.sidebar-brand {
    padding: 22px 20px 18px;
    border-bottom: 1px solid rgba(255,255,255,.12);
}
.sidebar-brand .brand-name {
    color: #fff;
    font-weight: 800;
    font-size: 1.15rem;
    line-height: 1.25;
    display: block;
}
.sidebar-brand .brand-sub {
    color: rgba(255,255,255,.6);
    font-size: .78rem;
}

.sidebar-nav {
    padding: 14px 0;
    flex: 1;
    list-style: none;
    margin: 0;
}
.sidebar-nav .nav-section {
    padding: 8px 20px 4px;
    font-size: .7rem;
    font-weight: 700;
    letter-spacing: .08em;
    text-transform: uppercase;
    color: rgba(255,255,255,.35);
}
.sidebar-nav .nav-item a {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 10px 20px;
    color: var(--sidebar-text);
    text-decoration: none;
    font-size: .92rem;
    font-weight: 500;
    border-radius: 0;
    transition: background .15s;
}
.sidebar-nav .nav-item a:hover {
    background: var(--sidebar-hover-bg);
    color: #fff;
}
.sidebar-nav .nav-item a.active {
    background: var(--sidebar-active-bg);
    color: #fff;
    font-weight: 700;
    border-right: 3px solid rgba(255,255,255,.8);
}
.sidebar-nav .nav-item a i {
    font-size: 1.1rem;
    width: 22px;
    text-align: center;
    flex-shrink: 0;
}

.sidebar-footer {
    padding: 14px 20px;
    border-top: 1px solid rgba(255,255,255,.12);
    font-size: .82rem;
    color: rgba(255,255,255,.55);
}

/* ── Top bar ── */
#topbar {
    position: fixed;
    top: 0;
    right: var(--sidebar-w);
    left: 0;
    height: var(--topbar-h);
    background: #fff;
    z-index: 1030;
    display: flex;
    align-items: center;
    padding: 0 24px;
    box-shadow: 0 1px 6px rgba(0,0,0,.07);
    gap: 10px;
}
#topbar .topbar-title {
    font-size: 1rem;
    font-weight: 700;
    color: var(--primary);
    flex: 1;
}
#topbar .topbar-user {
    font-size: .85rem;
    color: #6b7a8e;
    display: flex;
    align-items: center;
    gap: 8px;
}
#topbar .topbar-user .avatar {
    width: 34px;
    height: 34px;
    background: var(--primary);
    border-radius: 50%;
    color: #fff;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 700;
    font-size: .85rem;
    flex-shrink: 0;
}

/* ── Main content ── */
#main {
    margin-right: var(--sidebar-w);
    margin-top: var(--topbar-h);
    padding: 24px;
    min-height: calc(100vh - var(--topbar-h));
}

/* ── Cards ── */
.card {
    border: none;
    border-radius: var(--card-radius);
    box-shadow: var(--card-shadow);
}
.card-header {
    background: transparent;
    border-bottom: 1px solid #e9eef5;
    padding: 14px 20px;
    font-weight: 700;
    font-size: .95rem;
}

/* ── Stat cards ── */
.stat-card {
    border-radius: var(--card-radius);
    padding: 20px 22px;
    box-shadow: var(--card-shadow);
    color: #fff;
    position: relative;
    overflow: hidden;
}
.stat-card .stat-icon {
    position: absolute;
    left: 14px;
    top: 50%;
    transform: translateY(-50%);
    font-size: 3rem;
    opacity: .18;
}
.stat-card .stat-value {
    font-size: 2rem;
    font-weight: 800;
    line-height: 1;
}
.stat-card .stat-label {
    font-size: .82rem;
    opacity: .88;
    margin-top: 4px;
}

/* ── Hero header ── */
.hero-header {
    background: linear-gradient(135deg, #0f2c5e, #1a4a8a 50%, #2563c9);
    border-radius: var(--card-radius);
    padding: 28px 30px;
    color: #fff;
    margin-bottom: 24px;
    box-shadow: 0 4px 24px rgba(15,44,94,.25);
    position: relative;
    overflow: hidden;
}
.hero-header::after {
    content: '';
    position: absolute;
    left: -40px;
    bottom: -40px;
    width: 200px;
    height: 200px;
    background: rgba(255,255,255,.05);
    border-radius: 50%;
}
.hero-header h2 {
    font-size: 1.5rem;
    font-weight: 800;
    margin: 0 0 6px;
}
.hero-header p {
    font-size: .9rem;
    opacity: .8;
    margin: 0;
}

/* ── Tables ── */
.table th {
    background: #f4f7fb;
    font-weight: 700;
    font-size: .88rem;
    color: #4a5568;
    white-space: nowrap;
}
.table td { vertical-align: middle; font-size: .9rem; }
.table-hover tbody tr:hover { background: #eef3fa; }

/* ── Buttons ── */
.btn { font-family: 'Cairo', sans-serif; font-weight: 600; }
.btn-primary { background: var(--primary); border-color: var(--primary); }
.btn-primary:hover { background: #163d73; border-color: #163d73; }

/* ── Badges ── */
.badge { font-family: 'Cairo', sans-serif; }

/* ── Forms ── */
.form-label { font-weight: 600; font-size: .9rem; }
.form-control:focus, .form-select:focus { border-color: var(--primary); box-shadow: 0 0 0 .2rem rgba(26,74,138,.2); }

/* ── Responsive sidebar toggle ── */
@media (max-width: 991.98px) {
    #sidebar { transform: translateX(100%); }
    #sidebar.open { transform: translateX(0); }
    #main { margin-right: 0; }
    #topbar { right: 0; }
    #sidebar-overlay {
        display: none;
        position: fixed; inset: 0; background: rgba(0,0,0,.5);
        z-index: 1039;
    }
    #sidebar-overlay.show { display: block; }
}

/* ── Print: hide UI chrome ── */
@media print {
    #sidebar, #topbar { display: none !important; }
    #main { margin: 0 !important; padding: 0 !important; }
    body { background: #fff !important; }
}
</style>
</head>
<body>

<!-- ── Sidebar ── -->
<nav id="sidebar">
    <div class="sidebar-brand">
        <span class="brand-name"><?= e($orgName) ?></span>
        <span class="brand-sub">لوحة الإدارة</span>
    </div>
    <ul class="sidebar-nav">
        <li class="nav-section">القائمة الرئيسية</li>
        <?php foreach ($navItems as $key => $item): ?>
        <li class="nav-item">
            <a href="<?= e($item['href']) ?>" class="<?= $activeMenu === $key ? 'active' : '' ?>">
                <i class="bi <?= e($item['icon']) ?>"></i>
                <?= e($item['label']) ?>
            </a>
        </li>
        <?php endforeach; ?>
        <li class="nav-section" style="margin-top:8px">الحساب</li>
        <li class="nav-item">
            <a href="<?= e(ADMIN_PATH) ?>/logout.php">
                <i class="bi bi-box-arrow-left"></i>
                تسجيل الخروج
            </a>
        </li>
    </ul>
    <div class="sidebar-footer">
        <i class="bi bi-shield-check me-1"></i>نظام آمن ومحمي
    </div>
</nav>

<div id="sidebar-overlay"></div>

<!-- ── Top bar ── -->
<header id="topbar">
    <button class="btn btn-sm btn-outline-secondary d-lg-none" id="sidebar-toggle">
        <i class="bi bi-list"></i>
    </button>
    <div class="topbar-title"><?= e($title) ?></div>
    <div class="topbar-user">
        <span class="d-none d-sm-inline"><?= e($adminName) ?></span>
        <div class="avatar"><?= e(mb_substr($adminName, 0, 1)) ?></div>
    </div>
</header>

<!-- ── Main ── -->
<main id="main">
    <?php $callback(); ?>
</main>

<!-- Bootstrap JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Mobile sidebar toggle
const sidebarToggle  = document.getElementById('sidebar-toggle');
const sidebar        = document.getElementById('sidebar');
const sidebarOverlay = document.getElementById('sidebar-overlay');
if (sidebarToggle) {
    sidebarToggle.addEventListener('click', () => {
        sidebar.classList.toggle('open');
        sidebarOverlay.classList.toggle('show');
    });
    sidebarOverlay.addEventListener('click', () => {
        sidebar.classList.remove('open');
        sidebarOverlay.classList.remove('show');
    });
}
</script>
</body>
</html>
    <?php
}

} // end if !function_exists
