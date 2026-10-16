<?php
/** Build menus from the same capability rules used by the route guards. */
function staffSidebarLinks(): array {
    $role = (string)($_SESSION['role'] ?? '');
    $useParkingManagement = in_array($role, ['admin', 'superadmin'], true);
    $links = [[dashboardPathForRole($role), 'dashboard', 'Dashboard', null]];
    $candidates = [
        ['superadmin/units.php', 'units', 'Units', 'units.manage'],
        ['superadmin/residents.php', 'residents', 'Residents', 'residents.read'],
        ['superadmin/pending_accounts.php', 'pending', 'Pending Accounts', 'accounts.review'],
        ['superadmin/staff.php', 'staff', 'Staff Management', 'staff.manage'],
        ['superadmin/archive.php', 'archive', 'Archive', 'archive.manage'],
        ['superadmin/unitpayments.php', 'billing', 'Billing & Payments', 'billing.manage'],
        ['superadmin/generate_bills.php', 'bills', 'Generate Bills', 'billing.manage'],
        ['superadmin/violations.php', 'violations', 'Violations', 'violations.manage'],
        ['superadmin/bookingrequest.php', 'calendar', 'Booking Requests', 'bookings.review'],
        ['superadmin/maintenancerequests.php', 'maintenance', 'Maintenance Requests', 'maintenance.work'],
        ['superadmin/admin_messages.php', 'messages', 'Messages', 'messages.manage'],
        ['superadmin/reports.php', 'report', 'Community Reports', 'reports.manage'],
        ['superadmin/announcements.php', 'announcements', 'Announcements', 'announcements.manage'],
        ['superadmin/registeredvehicles.php', 'parking', 'Registered Vehicles', 'vehicles.read'],
        ['superadmin/service_requests.php?kind=visitor', 'visitors', 'Visitor Registrations', 'visitors.review'],
        ['superadmin/service_requests.php?kind=permit', 'calendar', 'Permit Requests', 'permits.review'],
        ['superadmin/parking.php', 'parking', $useParkingManagement ? 'Parking Management' : 'Parking & Stickers', 'parking.review'],
        ['superadmin/parkinginventory.php', 'parking', 'Parking Inventory', 'parking.configure'],
        ['superadmin/parking_configuration.php', 'parking', 'Parking Configuration', 'parking.configure'],
        [$role === 'superadmin' ? 'superadmin/scanner.php' : 'security/scanner.php', 'scanner', 'QR Scanner', 'security.gate'],
        ['superadmin/visitorlog.php', 'visitors', 'Visitor Log', 'visitors.logs'],
        ['superadmin/analytics.php', 'analytics', 'Analytics', 'analytics.read'],
        ['job_report.php', 'clipboard-list', 'Job Report', 'jobs.personal'],
        ['superadmin/auditlog.php', 'audit', 'Audit Log', 'audit.read'],
        ['superadmin/notification_delivery.php', 'messages', 'Notification Delivery', 'notifications.manage'],
    ];
    foreach ($candidates as $link) {
        if ($useParkingManagement && in_array($link[0], [
            'superadmin/registeredvehicles.php',
            'superadmin/parkinginventory.php',
            'superadmin/parking_configuration.php',
        ], true)) continue;
        if (roleHasCapability($role, $link[3])) $links[] = $link;
    }
    return $links;
}

function renderStaffSidebarNavigation(): void {
    $current = str_replace('\\', '/', (string)($_SERVER['SCRIPT_FILENAME'] ?? ''));
    foreach (staffSidebarLinks() as [$path, $icon, $label]) {
        $route = explode('?', $path, 2)[0];
        $active = str_ends_with($current, '/' . $route);
        if ($route === 'superadmin/parking.php' && in_array($_SESSION['role'] ?? '', ['admin','superadmin'], true)) {
            foreach (['registeredvehicles.php','parkinginventory.php','parking_configuration.php'] as $parkingPage) {
                $active = $active || str_ends_with($current, '/superadmin/'.$parkingPage);
            }
        }
        if ($route === 'superadmin/service_requests.php') {
            $kind = isSecurity() ? 'visitor' : (($_GET['kind'] ?? 'permit') === 'visitor' ? 'visitor' : 'permit');
            $active = $active && str_ends_with($path, '?kind=' . $kind);
        }
        echo '<a href="' . htmlspecialchars(buildUrl($path), ENT_QUOTES, 'UTF-8') . '" class="sidebar-link' . ($active ? ' active' : '') . '"' . ($active ? ' aria-current="page"' : '') . '>' . systemSidebarIcon($icon) . ' ' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</a>';
    }
    renderPortalSidebarFooter();
}
