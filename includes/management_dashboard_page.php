<?php
if (!isset($managementDashboardRole) || !in_array($managementDashboardRole, ['admin','superadmin'], true)) { http_response_code(404); exit; }
if (!isLoggedIn()) redirect(buildUrl('login.php'));
if (($_SESSION['role'] ?? '') !== $managementDashboardRole) redirect(buildUrl(dashboardPathForRole()));
requireCapability('management.dashboard');
require_once __DIR__.'/management_dashboard.php';
require_once __DIR__.'/management_dashboard_content.php';
$username = $_SESSION['username'] ?? 'Administrator';
$roleLabel = $managementDashboardRole === 'superadmin' ? 'Super Admin' : 'Admin';
$title = $roleLabel.' Dashboard'; $dashboard = null;
try { $dashboard = managementDashboardData(connectDb(), (int)$_SESSION['user_id']); }
catch (DomainException $error) { http_response_code(403); renderPortalError('Access denied. Please sign in again.'); exit; }
catch (Throwable $error) { error_log('Management dashboard: '.get_class($error)); }
$escape = static fn($value): string => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>The Celandine - <?= $escape($title) ?></title>
    <link rel="stylesheet" href="<?= $escape(portalUiAssetUrl('styles.css')) ?>">
    <?php renderPortalUiHead(); ?>
    <link rel="stylesheet" href="<?= $escape(portalUiAssetUrl('assets/css/management-dashboard.css')) ?>">
    <script defer src="<?= $escape(portalUiAssetUrl('assets/js/management-dashboard.js')) ?>"></script>
</head>
<body class="portal-ui dashboard-page admin-page">
    <div class="dash-layout">
        <aside class="sidebar" id="sidebar">
            <a href="<?= $escape(buildUrl(dashboardPathForRole())) ?>" class="sidebar-brand"><?php include __DIR__.'/../buildingicon.php'; ?></a>
            <nav class="sidebar-nav"><?php renderStaffSidebarNavigation(); ?></nav>
        </aside>
        <div class="sidebar-overlay" id="sidebarOverlay"></div>
        <main class="dashboard-main">
            <header class="dash-header">
                <div class="dash-header-left">
                    <button class="btn-icon-menu" id="menuToggle" aria-label="Menu"><?= systemIcon('menu','menu-icon') ?></button>
                    <div><span class="dash-subtitle">THE CELANDINE</span><h1 class="dash-title"><?= $escape($title) ?></h1></div>
                </div>
                <div class="dash-header-right">
                    <?php include __DIR__.'/../notifications.php'; ?>
                    <div class="profile-menu" id="profileMenu">
                        <button class="btn-profile-nav" id="profileToggle" type="button" aria-label="Profile menu" aria-haspopup="true" aria-expanded="false">
                            <?= profileAvatar('profile-avatar') ?><span class="profile-nav-name"><?= $escape($username) ?></span><span class="profile-caret">▾</span>
                        </button>
                        <div class="profile-dropdown" id="profileDropdown">
                            <div class="profile-dropdown-header"><?= profileAvatar('profile-dropdown-avatar') ?><div class="profile-dropdown-info"><span class="profile-dropdown-name"><?= $escape($username) ?></span><span class="profile-dropdown-unit"><?= $escape($roleLabel) ?></span></div></div>
                            <a href="<?= $escape(buildUrl('logout.php')) ?>" class="profile-dropdown-item profile-dropdown-danger"><?= systemIcon('arrow-right','system-action-icon') ?> Logout</a>
                        </div>
                    </div>
                </div>
            </header>
            <div class="management-home" data-management-dashboard data-endpoint="<?= $escape(buildUrl('api/admin_dashboard.php')) ?>">
                <div class="management-toolbar"><div><h2>Your management overview</h2><p><?= $managementDashboardRole === 'superadmin' ? 'Review operations, account approvals and financial follow-up.' : 'Review requests, follow up on maintenance and respond to residents.' ?></p></div><button class="management-refresh btn-primary" type="button" data-management-refresh><?= systemIcon('refresh-cw','system-action-icon') ?> Refresh dashboard</button></div>
                <nav class="management-shortcuts" aria-label="Management shortcuts">
                    <?php foreach ([['Residents','residents','superadmin/residents.php','residents.read'],['Community reports','report','superadmin/reports.php','reports.manage'],['QR scanner','scanner',$managementDashboardRole === 'superadmin' ? 'superadmin/scanner.php' : 'security/scanner.php','security.gate'],['Announcements','announcements','superadmin/announcements.php','announcements.manage'],['My job report','clipboard-list','job_report.php','jobs.personal'],['Analytics','analytics','superadmin/analytics.php','analytics.read']] as [$label,$icon,$path,$capability]): if (!canAccess($capability)) continue; ?>
                    <a href="<?= $escape(buildUrl($path)) ?>"><?= systemSidebarIcon($icon) ?> <?= $escape($label) ?></a>
                    <?php endforeach; ?>
                </nav>
                <p class="management-feedback" data-management-feedback role="status" aria-live="polite"<?= $dashboard ? '' : ' data-error="true"' ?>><?= $dashboard ? '' : 'Dashboard data is temporarily unavailable. Refresh to try again; your other modules remain available.' ?></p>
                <div class="management-content" data-management-content aria-busy="false"><?php if ($dashboard) renderManagementDashboardContent($dashboard); ?></div>
            </div>
        </main>
    </div>
</body>
</html>
