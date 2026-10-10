<?php
/** Presentation helpers only. No queries, session writes or output at bootstrap. */
function portalUiRoleLabel(): string {
    $role = (string)($_SESSION['role'] ?? '');
    if ($role === 'resident') {
        return residentAccountKind($_SESSION['account_type'] ?? null) === 'tenant' ? 'Tenant' : 'Resident';
    }
    return ['superadmin'=>'Superadmin','admin'=>'Admin','security'=>'Security','maintenance'=>'Maintenance','treasurer'=>'Treasurer'][$role] ?? 'Resident portal';
}

/** Explicit opt-in keeps the scanner and recently redesigned screens unchanged. */
function portalWorkspaceModule(): ?string {
    $script = str_replace('\\', '/', (string)($_SERVER['SCRIPT_FILENAME'] ?? ''));
    $root = str_replace('\\', '/', dirname(__DIR__)) . '/';
    if (!str_starts_with($script, $root)) return null;
    $route = substr($script, strlen($root));
    $pages = [
        'resident/dashboard.php'=>'dashboard', 'admin/admin_dashboard.php'=>'dashboard',
        'superadmin/admin_dashboard.php'=>'dashboard', 'security/security_dashboard.php'=>'dashboard',
        'maintenance/maintenance_dashboard.php'=>'dashboard', 'treasurer/treasurer_dashboard.php'=>'dashboard',
        'resident/payments.php'=>'billing', 'superadmin/unitpayments.php'=>'billing',
        'superadmin/generate_bills.php'=>'billing',
        'resident/residentviolation.php'=>'violations', 'superadmin/violations.php'=>'violations',
        'resident/parking.php'=>'parking', 'superadmin/parking.php'=>'parking',
        'superadmin/parkinginventory.php'=>'parking', 'superadmin/parking_configuration.php'=>'parking',
        'resident/vehicles.php'=>'vehicles', 'superadmin/registeredvehicles.php'=>'vehicles',
        'resident/maintenance.php'=>'maintenance', 'superadmin/maintenancerequests.php'=>'maintenance',
        'resident/messages.php'=>'messages', 'superadmin/admin_messages.php'=>'messages',
        'resident/announcements.php'=>'announcements', 'superadmin/announcements.php'=>'announcements',
        'resident/visitors.php'=>'visitors', 'superadmin/visitorlog.php'=>'visitors',
        'security/visitor_log.php'=>'visitors',
        'superadmin/units.php'=>'accounts', 'superadmin/residents.php'=>'accounts',
        'superadmin/pending_accounts.php'=>'accounts', 'superadmin/staff.php'=>'accounts',
        'superadmin/analytics.php'=>'reports', 'superadmin/auditlog.php'=>'reports',
        'superadmin/notification_delivery.php'=>'notifications',
    ];
    if ($route === 'superadmin/service_requests.php') {
        return isSecurity() || ($_GET['kind'] ?? 'permit') === 'visitor' ? 'visitors' : null;
    }
    return $pages[$route] ?? null;
}

function renderPortalUiHead(): void {
    static $rendered = false;
    if ($rendered) return;
    $rendered = true;
    $escape = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    echo '<meta name="portal-role" content="'.$escape(portalUiRoleLabel()).'">';
    foreach (['assets/css/design-system.css','assets/css/components.css','assets/css/responsive.css'] as $path) {
        echo '<link rel="stylesheet" href="'.$escape(buildUrl($path)).'?v='.filemtime(dirname(__DIR__).'/'.$path).'">';
    }
    $module = portalWorkspaceModule();
    if ($module !== null) {
        echo '<meta name="portal-workspace" content="'.$escape($module).'">';
        $path = 'assets/css/workspace.css';
        echo '<link rel="stylesheet" href="'.$escape(buildUrl($path)).'?v='.filemtime(dirname(__DIR__).'/'.$path).'">';
    }
    foreach (['assets/vendor/gsap/gsap.min.js','js/confirmation-ui.js','assets/js/ui-components.js','assets/js/animations.js'] as $path) {
        echo '<script defer src="'.$escape(buildUrl($path)).'?v='.filemtime(dirname(__DIR__).'/'.$path).'"></script>';
    }
    if ($module !== null) {
        $path = 'assets/js/workspace.js';
        echo '<script defer src="'.$escape(buildUrl($path)).'?v='.filemtime(dirname(__DIR__).'/'.$path).'"></script>';
    }
}

function renderPortalSidebarFooter(): void {
    $escape = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    $name = (string)($_SESSION['username'] ?? portalUiRoleLabel());
    echo '<div class="portal-sidebar-footer"><div class="portal-sidebar-account"><span class="portal-account-avatar" aria-hidden="true">'.$escape(strtoupper(substr($name,0,1))).'</span><div class="portal-sidebar-account-copy"><strong>'.$escape($name).'</strong><span>'.$escape(portalUiRoleLabel()).'</span></div></div><a class="sidebar-link portal-signout" href="'.$escape(buildUrl('logout.php')).'" title="Sign out">'.systemSidebarIcon('arrow-right').'<span class="sidebar-link-label">Sign out</span></a></div>';
}

/** Standard header for management pages without their own profile markup. */
function renderPortalWorkspaceHeader(string $title): void {
    $escape = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    $name = (string)($_SESSION['username'] ?? portalUiRoleLabel());
    echo '<header class="dash-header"><div class="dash-header-left"><button class="btn-icon-menu" id="menuToggle" type="button" aria-label="Menu">'.systemIcon('menu','menu-icon').'</button><div><span class="dash-subtitle">CELANDINE RESIDENCES</span><h1 class="dash-title">'.$escape($title).'</h1></div></div><div class="dash-header-right">';
    include dirname(__DIR__).'/notifications.php';
    echo '<div class="profile-menu" id="profileMenu"><button class="btn-profile-nav" id="profileToggle" type="button" aria-label="Profile menu" aria-haspopup="true" aria-expanded="false"><span class="profile-avatar">'.$escape(strtoupper(substr($name,0,1))).'</span><span class="profile-nav-name">'.$escape($name).'</span><span class="profile-caret">&#9662;</span></button><div class="profile-dropdown" id="profileDropdown"><div class="profile-dropdown-header"><span class="profile-dropdown-avatar">'.$escape(strtoupper(substr($name,0,1))).'</span><div class="profile-dropdown-info"><span class="profile-dropdown-name">'.$escape($name).'</span><span class="profile-dropdown-unit">'.$escape(portalUiRoleLabel()).'</span></div></div><a class="profile-dropdown-item profile-dropdown-danger" href="'.$escape(buildUrl('logout.php')).'">'.systemIcon('arrow-right','system-action-icon').' Sign out</a></div></div></div></header>';
}

/** Keep existing HTTP status and denial semantics while offering a useful recovery link. */
function renderPortalError(string $message, string $title = 'Access denied'): void {
    $escape = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>'.$escape($title).' · Celandine Residences</title>';
    renderPortalUiHead();
    echo '</head><body class="portal-ui portal-account-page"><main class="portal-error-card"><p class="portal-eyebrow">CELANDINE RESIDENCES</p><h1>'.$escape($title).'</h1><p>'.$escape($message).'</p><a class="service-btn" href="'.$escape(buildUrl(isset($_SESSION['user_id']) ? dashboardPathForRole() : 'login.php')).'">'.(isset($_SESSION['user_id']) ? 'Return to dashboard' : 'Sign in').'</a></main></body></html>';
}
