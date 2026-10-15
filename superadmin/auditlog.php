<?php
require_once '../config.php';

if (!isLoggedIn()) {
    redirect('../login.php');
}
requireCapability('audit.read');

$username = $_SESSION['username'] ?? 'Administrator';
$nameParts = preg_split('/\s+/', trim($username));
$initials = strtoupper(substr($nameParts[0], 0, 1) . (count($nameParts) > 1 ? substr(end($nameParts), 0, 1) : ''));

require_once __DIR__.'/../includes/audit_report.php';
try { auditReportActor(connectDb()); } catch (DomainException $e) { http_response_code($e->getCode() ?: 403); renderPortalError('Access denied.'); exit; }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Audit Log | The Celandine</title>
    <link rel="stylesheet" href="<?= htmlspecialchars(portalUiAssetUrl('styles.css'), ENT_QUOTES, 'UTF-8') ?>">
    <link rel="stylesheet" href="<?= htmlspecialchars(portalUiAssetUrl('services.css'),ENT_QUOTES,'UTF-8') ?>">
    <link rel="stylesheet" href="<?= htmlspecialchars(portalUiAssetUrl('assets/css/audit-log.css'),ENT_QUOTES,'UTF-8') ?>">
<?php renderPortalUiHead(); ?>
</head>
<body class="portal-ui dashboard-page admin-page">
    <div class="dash-layout">
        <aside class="sidebar" id="sidebar">
            <a href="<?php echo htmlspecialchars(buildUrl(dashboardPathForRole()), ENT_QUOTES, 'UTF-8'); ?>" class="sidebar-brand"><?php include '../buildingicon.php'; ?></a>
            <nav class="sidebar-nav"><?php renderStaffSidebarNavigation(); ?></nav>
        </aside>
        <div class="sidebar-overlay" id="sidebarOverlay"></div>

        <main class="dashboard-main">
            <header class="dash-header">
                <div class="dash-header-left"><button class="btn-icon-menu" id="menuToggle" type="button" aria-label="Menu"><?php echo systemIcon('menu', 'menu-icon'); ?></button><div><span class="dash-subtitle">THE CELANDINE</span><h1 class="dash-title">Audit Log</h1></div></div>
                <div class="dash-header-right">
                    <?php include '../notifications.php'; ?>
                    <div class="profile-menu" id="profileMenu">
                        <button class="btn-profile-nav" id="profileToggle" type="button" aria-label="Profile menu" aria-haspopup="true" aria-expanded="false"><?= profileAvatar('profile-avatar') ?><span class="profile-nav-name"><?php echo htmlspecialchars($username); ?></span><span class="profile-caret">▾</span></button>
                        <div class="profile-dropdown" id="profileDropdown"><div class="profile-dropdown-header"><?= profileAvatar('profile-dropdown-avatar') ?><div class="profile-dropdown-info"><span class="profile-dropdown-name"><?php echo htmlspecialchars($username); ?></span><span class="profile-dropdown-unit">Administrator</span></div></div><a href="../logout.php" class="profile-dropdown-item profile-dropdown-danger"><?php echo systemIcon('arrow-right', 'system-action-icon'); ?> Logout</a></div>
                    </div>
                </div>
            </header>

            <?php include __DIR__.'/../includes/audit_workspace.php'; ?>
        </main>
    </div>
    <script src="<?= htmlspecialchars(portalUiAssetUrl('assets/js/audit-log.js'),ENT_QUOTES,'UTF-8') ?>" defer></script>
</body>
</html>
