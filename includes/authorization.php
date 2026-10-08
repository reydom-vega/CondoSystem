<?php
/**
 * Explicit application permissions. isAdmin() identifies staff for legacy code;
 * it must not be used as permission to operate every management module.
 */
function roleHasCapability(string $role, string $capability): bool {
    static $permissions = [
        'resident.portal' => ['resident'],
        'resident.billing.view' => ['resident'], 'resident.billing.pay' => ['resident'],
        'resident.announcements.view' => ['resident'], 'resident.violations.view' => ['resident'],
        'resident.amenities.book' => ['resident'], 'resident.maintenance.request' => ['resident'],
        'resident.messages.use' => ['resident'], 'resident.visitors.register' => ['resident'],
        'resident.parking.request' => ['resident'], 'resident.vehicles.register' => ['resident'],
        'resident.stickers.order' => ['resident'], 'resident.permits.request' => ['resident'], 'resident.profile.edit' => ['resident'],
        'management.dashboard' => ['admin', 'superadmin'],
        'residents.read' => ['admin', 'superadmin'],
        'units.manage' => ['admin', 'superadmin'],
        'accounts.review' => ['superadmin'],
        'staff.manage' => ['superadmin'],
        'announcements.manage' => ['admin', 'superadmin'],
        'messages.manage' => ['admin', 'superadmin'],
        'bookings.review' => ['admin', 'superadmin'],
        'permits.review' => ['admin', 'superadmin'],
        'visitors.review' => ['admin', 'superadmin', 'security'],
        'visitors.logs' => ['admin', 'superadmin', 'security'],
        'parking.review' => ['admin', 'superadmin', 'security'],
        'parking.configure' => ['superadmin'],
        'stickers.issue' => ['admin', 'superadmin'],
        'vehicles.read' => ['admin', 'superadmin', 'security'],
        'vehicles.review' => ['admin', 'superadmin'],
        'billing.manage' => ['superadmin', 'treasurer'],
        'maintenance.review' => ['admin', 'superadmin'],
        'maintenance.work' => ['admin', 'superadmin', 'maintenance'],
        'violations.manage' => ['admin', 'superadmin', 'security'],
        'violations.issue' => ['admin', 'superadmin', 'security'],
        'violations.review' => ['admin', 'superadmin'],
        'security.gate' => ['security'],
        'audit.read' => ['superadmin'],
        'analytics.read' => ['superadmin'],
        'notifications.manage' => ['superadmin'],
    ];
    return isset($permissions[$capability]) && in_array($role, $permissions[$capability], true);
}

require_once __DIR__ . '/staff_navigation.php';

function canAccess(string $capability): bool {
    return isLoggedIn() && roleHasCapability((string)($_SESSION['role'] ?? ''), $capability)
        && (!str_starts_with($capability,'resident.') || residentHasPermission($capability));
}

/** Guard an entry point before reading data or accepting any mutations. */
function requireCapability(string $capability, bool $api = false): void {
    $loggedIn = isLoggedIn();
    if ($loggedIn && roleHasCapability((string)($_SESSION['role'] ?? ''), $capability) && (!str_starts_with($capability,'resident.') || residentHasPermission($capability))) {
        return;
    }
    if (!$loggedIn && !$api) {
        redirect(buildUrl('login.php'));
    }
    http_response_code($loggedIn ? 403 : 401);
    if ($api) {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode(['error' => $loggedIn ? 'Access denied' : 'Unauthorized']);
        exit;
    }
    exit('Access denied.');
}

/** Return an application-relative canonical dashboard route for a role. */
function dashboardPathForRole(?string $role = null): string {
    $role ??= (string)($_SESSION['role'] ?? '');
    return [
        'resident' => 'resident/dashboard.php',
        'admin' => 'admin/admin_dashboard.php',
        'superadmin' => 'superadmin/admin_dashboard.php',
        'treasurer' => 'treasurer/treasurer_dashboard.php',
        'maintenance' => 'maintenance/maintenance_dashboard.php',
        'security' => 'security/security_dashboard.php',
    ][$role] ?? 'login.php';
}
