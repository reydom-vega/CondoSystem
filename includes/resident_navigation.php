<?php
/** Resident menus use the same permissions as pages and workflow actions. */
function residentSidebarLinks(): array {
    $links = [['dashboard.php', 'dashboard', 'Dashboard']];
    $candidates = [
        ['payments.php', 'billing', (residentHasPermission('resident.billing.pay') || residentHasPermission('resident.stickers.order')) ? 'Billing & Payments' : 'Unit Bills', 'resident.billing.view'],
        ['residentviolation.php', 'violations', 'Your Violations', 'resident.violations.view'],
        ['book_amenity.php', 'calendar', 'Book Amenity', 'resident.amenities.book'],
        ['parking.php', 'parking', residentHasPermission('resident.stickers.order') ? 'Parking & Stickers' : 'Visitor Parking', 'resident.parking.request'],
        ['vehicles.php', 'parking', 'Registered Vehicles', 'resident.vehicles.register'],
        ['maintenance.php', 'maintenance', 'Maintenance', 'resident.maintenance.request'],
        ['messages.php', 'messages', 'Messages', 'resident.messages.use'],
        ['announcements.php', 'announcements', 'Announcements', 'resident.announcements.view'],
        ['visitors.php', 'visitors', 'Visitor Registration', 'resident.visitors.register'],
        ['permits.php', 'calendar', 'Permit Requests', 'resident.permits.request'],
    ];
    foreach ($candidates as [$path, $icon, $label, $permission]) {
        // A resident may use stickers even when visitor parking is disabled.
        $allowed = residentHasPermission($permission);
        if ($path === 'parking.php') $allowed = $allowed || residentHasPermission('resident.stickers.order');
        if ($allowed) $links[] = [$path, $icon, $label];
    }
    return $links;
}

function renderResidentSidebarNavigation(?string $activePage = null): void {
    $activePage ??= basename((string)($_SERVER['SCRIPT_FILENAME'] ?? ''));
    foreach (residentSidebarLinks() as [$path, $icon, $label]) {
        $active = $path === $activePage;
        echo '<a href="' . htmlspecialchars($path, ENT_QUOTES, 'UTF-8') . '" class="sidebar-link' . ($active ? ' active' : '') . '"' . ($active ? ' aria-current="page"' : '') . '>' . systemSidebarIcon($icon) . ' ' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</a>';
    }
    renderPortalSidebarFooter();
}
