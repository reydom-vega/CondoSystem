<?php
require_once '../config.php';require_once __DIR__.'/../includes/security_dashboard.php';
if(!isLoggedIn())redirect('../login.php');
requireCapability('security.dashboard');
$username=$_SESSION['username']??'Security';
try{$securityData=securityDashboardData(connectDb(),(int)$_SESSION['user_id']);}
catch(DomainException $error){http_response_code(403);renderPortalError('Access denied.');exit;}
catch(Throwable $error){http_response_code(503);error_log('Security dashboard: '.get_class($error));renderPortalError('The security dashboard is temporarily unavailable. Please try again.','Dashboard unavailable');exit;}
$e=static fn($value)=>htmlspecialchars((string)$value,ENT_QUOTES,'UTF-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>The Celandine - Security Dashboard</title>
    <link rel="stylesheet" href="<?= htmlspecialchars(portalUiAssetUrl('security.css'), ENT_QUOTES, 'UTF-8') ?>">
<?php renderPortalUiHead(); ?>
    <link rel="stylesheet" href="<?= htmlspecialchars(portalUiAssetUrl('assets/css/security-dashboard.css'),ENT_QUOTES,'UTF-8') ?>">
    <script defer src="<?= htmlspecialchars(portalUiAssetUrl('assets/js/security-dashboard.js'),ENT_QUOTES,'UTF-8') ?>"></script>
</head>
<body class="portal-ui dashboard-page admin-page">
    <div class="dash-layout">
        <aside class="sidebar" id="sidebar">
            <a href="<?php echo htmlspecialchars(buildUrl(dashboardPathForRole()), ENT_QUOTES, 'UTF-8'); ?>" class="sidebar-brand">
                <?php include '../buildingicon.php'; ?>
            </a>
            <nav class="sidebar-nav"><?php renderStaffSidebarNavigation(); ?></nav>
        </aside>
        <div class="sidebar-overlay" id="sidebarOverlay"></div>
        <main class="dashboard-main">
            <header class="dash-header">
                <div class="dash-header-left">
                    <button class="btn-icon-menu" id="menuToggle" aria-label="Menu"><?php echo systemIcon('menu', 'menu-icon'); ?></button>
                    <div><span class="dash-subtitle">THE CELANDINE</span><h1 class="dash-title">Security Dashboard</h1></div>
                </div>
                <div class="dash-header-right">
                    <?php include '../notifications.php'; ?>
                    <div class="profile-menu" id="profileMenu">
                        <button class="btn-profile-nav" id="profileToggle" type="button" aria-label="Profile menu" aria-haspopup="true" aria-expanded="false"><?= profileAvatar('profile-avatar') ?><span class="profile-nav-name"><?php echo htmlspecialchars($username); ?></span><span class="profile-caret">▾</span></button>
                        <div class="profile-dropdown" id="profileDropdown"><div class="profile-dropdown-header"><?= profileAvatar('profile-dropdown-avatar') ?><div class="profile-dropdown-info"><span class="profile-dropdown-name"><?php echo htmlspecialchars($username); ?></span><span class="profile-dropdown-unit">Security</span></div></div><a href="../logout.php" class="profile-dropdown-item profile-dropdown-danger"><?php echo systemIcon('arrow-right', 'system-action-icon'); ?> Logout</a></div>
                    </div>
                </div>
            </header>
            <section class="security-home" data-security-home aria-label="Security overview">
                <script type="application/json" data-security-config><?= json_encode(['endpoint'=>buildUrl('api/security_dashboard.php')],JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_QUOT) ?></script>
                <nav class="security-actions" aria-label="Security actions">
                    <a href="<?= $e(buildUrl('security/scanner.php')) ?>"><?= systemIcon('scanner','system-action-icon') ?> Open QR Scanner</a>
                    <a href="<?= $e(buildUrl('superadmin/violations.php')) ?>"><?= systemIcon('shield','system-action-icon') ?> Report an Incident</a>
                    <a href="<?= $e(buildUrl('job_report.php')) ?>"><?= systemIcon('clipboard-list','system-action-icon') ?> My Job Reports</a>
                </nav>
                <section class="admin-stats-grid" aria-label="Gate and reporting statistics">
                    <div class="admin-stat-card"><?= systemIcon('visitors','admin-stat-icon admin-icon-green') ?><strong data-security-stat="visitors_inside"><?= $securityData['visitors_inside'] ?></strong><span>Visitors Inside</span><small data-security-visits><?= $securityData['visits_inside'] ?> active visit group(s)</small></div>
                    <div class="admin-stat-card"><?= systemIcon('shield','admin-stat-icon admin-icon-yellow') ?><strong data-security-stat="pending_reports"><?= $securityData['pending_reports'] ?></strong><span>My Reports Awaiting Review</span><small>Incident reports you submitted to management</small></div>
                    <div class="admin-stat-card"><?= systemIcon('scanner','admin-stat-icon admin-icon-blue') ?><strong data-security-stat="qr_checks_today"><?= $securityData['qr_checks_today'] ?></strong><span>My QR Checks Today</span><small>Recorded verification checks &middot; Philippine time</small></div>
                </section>
                <section class="recent-grid" aria-label="Recent security activity">
                    <div class="admin-panel recent-panel">
                        <div class="recent-panel-head"><h2>Recent Visitor Movements</h2><a href="<?= $e(buildUrl('security/scanner.php')) ?>">Open scanner &rarr;</a></div>
                        <ul class="recent-list" data-security-visitors>
                            <?php if(!$securityData['recent_visitors']): ?><li class="recent-empty">No visitor movements recorded yet.</li><?php endif; ?>
                            <?php foreach($securityData['recent_visitors']as$visitor): ?>
                            <li class="recent-item"><div class="recent-item-main"><span class="recent-item-title"><?= $e($visitor['title']) ?></span><span class="recent-item-sub">Unit <?= $e($visitor['unit']) ?> &middot; <?= $visitor['people'] ?> person(s) &middot; <?= $e($visitor['time']) ?></span></div><span class="recent-badge <?= $visitor['status']==='Inside'?'status-good':'status-neutral' ?>"><?= $e($visitor['status']) ?></span></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                    <div class="admin-panel recent-panel">
                        <div class="recent-panel-head"><h2>My Recent Incident Reports</h2><a href="<?= $e(buildUrl('superadmin/violations.php')) ?>">View reports &rarr;</a></div>
                        <ul class="recent-list" data-security-reports>
                            <?php if(!$securityData['recent_reports']): ?><li class="recent-empty">You have not filed any incident reports yet.</li><?php endif; ?>
                            <?php foreach($securityData['recent_reports']as$report): ?>
                            <li class="recent-item"><div class="recent-item-main"><span class="recent-item-title"><?= $e($report['title']) ?></span><span class="recent-item-sub"><?= $e($report['reference']) ?> &middot; <?= $e($report['time']) ?></span></div><span class="recent-badge <?= $report['status']==='pending'?'status-warn':'status-neutral' ?>"><?= $e($report['status']) ?></span></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                </section>
                <div class="security-updated"><span data-security-updated>Updated <?= $e($securityData['updated_at']) ?></span><button type="button" data-security-refresh><?= systemIcon('clock','system-action-icon') ?> Refresh</button></div>
                <p data-security-feedback role="status" aria-live="polite" hidden></p>
            </section>
        </main>
    </div>
</body>
</html>
