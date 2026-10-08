<?php
/** Build menus from the same capability rules used by the route guards. */
function staffSidebarLinks(): array {
    $role = (string)($_SESSION['role'] ?? '');
    $links = [[dashboardPathForRole($role), 'dashboard', 'Dashboard', null]];
    $candidates = [
        ['superadmin/units.php', 'units', 'Units', 'units.manage'],
        ['superadmin/residents.php', 'residents', 'Residents', 'residents.read'],
        ['superadmin/pending_accounts.php', 'pending', 'Pending Accounts', 'accounts.review'],
        ['superadmin/staff.php', 'staff', 'Staff Management', 'staff.manage'],
        ['superadmin/unitpayments.php', 'billing', 'Billing & Payments', 'billing.manage'],
        ['superadmin/generate_bills.php', 'bills', 'Generate Bills', 'billing.manage'],
        ['superadmin/violations.php', 'violations', 'Violations', 'violations.manage'],
        ['superadmin/bookingrequest.php', 'calendar', 'Booking Requests', 'bookings.review'],
        ['superadmin/maintenancerequests.php', 'maintenance', 'Maintenance Requests', 'maintenance.work'],
        ['superadmin/admin_messages.php', 'messages', 'Messages', 'messages.manage'],
        ['superadmin/announcements.php', 'announcements', 'Announcements', 'announcements.manage'],
        ['superadmin/registeredvehicles.php', 'parking', 'Registered Vehicles', 'vehicles.read'],
        ['superadmin/service_requests.php?kind=visitor', 'visitors', 'Visitor Registrations', 'visitors.review'],
        ['superadmin/service_requests.php?kind=permit', 'calendar', 'Permit Requests', 'permits.review'],
        ['superadmin/parking.php', 'parking', 'Parking & Stickers', 'parking.review'],
        ['superadmin/parkinginventory.php', 'parking', 'Parking Inventory', 'parking.configure'],
        ['superadmin/parking_configuration.php', 'parking', 'Parking Configuration', 'parking.configure'],
        ['security/scanner.php', 'scanner', 'QR Scanner', 'security.gate'],
        [$role === 'security' ? 'security/visitor_log.php' : 'superadmin/visitorlog.php', 'visitors', 'Visitor Log', 'visitors.logs'],
        ['superadmin/analytics.php', 'analytics', 'Analytics', 'analytics.read'],
        ['superadmin/auditlog.php', 'audit', 'Audit Log', 'audit.read'],
        ['superadmin/notification_delivery.php', 'messages', 'Notification Delivery', 'notifications.manage'],
    ];
    foreach ($candidates as $link) {
        if (roleHasCapability($role, $link[3])) $links[] = $link;
    }
    return $links;
}

function renderStaffSidebarNavigation(): void {
    $current = str_replace('\\', '/', (string)($_SERVER['SCRIPT_FILENAME'] ?? ''));
    foreach (staffSidebarLinks() as [$path, $icon, $label]) {
        $route = explode('?', $path, 2)[0];
        $active = str_ends_with($current, '/' . $route);
        if ($route === 'superadmin/service_requests.php') {
            $kind = isSecurity() ? 'visitor' : (($_GET['kind'] ?? 'permit') === 'visitor' ? 'visitor' : 'permit');
            $active = $active && str_ends_with($path, '?kind=' . $kind);
        }
        echo '<a href="' . htmlspecialchars(buildUrl($path), ENT_QUOTES, 'UTF-8') . '" class="sidebar-link' . ($active ? ' active' : '') . '"' . ($active ? ' aria-current="page"' : '') . '>' . systemSidebarIcon($icon) . ' ' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</a>';
    }
}
