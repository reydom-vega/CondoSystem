<?php
require_once '../config.php';
require_once '../includes/amenity_workspace.php';
if(!isLoggedIn()) redirect('../login.php');
requireCapability('bookings.review');
$username=$_SESSION['username'] ?? 'Administrator';
$nameParts=preg_split('/\s+/',trim($username));
$initials=strtoupper(substr($nameParts[0],0,1).(count($nameParts)>1?substr(end($nameParts),0,1):''));
$connection=connectDb(); ensureAmenityBookingSchema($connection); ensurePaymentsTable($connection); ensurePaymongoColumns($connection);
$errors=[];
if($_SERVER['REQUEST_METHOD']==='POST') {
    requireWorkflowCsrf();
    try {
        decideAmenityBooking($connection,(int)($_POST['booking_id'] ?? 0),(string)($_POST['status'] ?? ''),trim((string)($_POST['reason'] ?? '')),isset($_POST['pmo_review']));
        setFlash('success','Booking updated successfully.'); redirect('bookingrequest.php#' . (in_array($_POST['status'] ?? '', ['rejected', 'cancelled', 'completed'], true) ? 'bookingHistory' : 'bookingRequests'));
    } catch(InvalidArgumentException $error) { $errors[]=$error->getMessage(); }
    catch(Throwable $error) { error_log($error->getMessage()); $errors[]='Unable to update this booking. Please try again.'; }
}
$statusFilter=is_string($_GET['status'] ?? null)?$_GET['status']:'all';
$statuses=['pending','approved','confirmed','rejected','cancelled','completed'];
if(!in_array($statusFilter,array_merge(['all'],$statuses),true)) $statusFilter='all';
$sql='SELECT b.*,u.full_name,u.username,u.account_type,p.status AS payment_status,p.paymongo_checkout_id FROM bookings b JOIN users u ON u.id=b.user_id LEFT JOIN payments p ON p.id=b.payment_id';
$sql.=' ORDER BY b.booking_date DESC,b.booking_time DESC,b.id DESC';
$query=$connection->prepare($sql);
$query->execute(); $bookings=$query->get_result()->fetch_all(MYSQLI_ASSOC);
$bookingGroups = ['bookingRequests' => [], 'bookingHistory' => []];
foreach ($bookings as $booking) $bookingGroups[amenityBookingIsHistory($booking) ? 'bookingHistory' : 'bookingRequests'][] = $booking;
$defaultView = in_array($statusFilter, ['rejected', 'cancelled', 'completed'], true) ? 'bookingHistory' : 'bookingRequests';
$flash=getFlash(); $escape=static fn($value)=>htmlspecialchars((string)$value,ENT_QUOTES,'UTF-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>The Celandine</title>
    <link rel="stylesheet" href="<?= htmlspecialchars(portalUiAssetUrl('styles.css'), ENT_QUOTES, 'UTF-8') ?>">
    <link rel="stylesheet" href="<?= htmlspecialchars(portalUiAssetUrl('services.css'), ENT_QUOTES, 'UTF-8') ?>">
    <link rel="stylesheet" href="../amenities.css?v=<?= filemtime(__DIR__ . '/../amenities.css') ?>">
<?php renderPortalUiHead(); ?>
    <script src="../assets/js/history-search.js" defer></script>
</head>
<body class="portal-ui dashboard-page admin-page amenity-page">
    <div class="dash-layout">
        <aside class="sidebar" id="sidebar">
            <a href="<?php echo htmlspecialchars(buildUrl(dashboardPathForRole()), ENT_QUOTES, 'UTF-8'); ?>" class="sidebar-brand"><?php include '../buildingicon.php'; ?></a>
            <nav class="sidebar-nav"><?php renderStaffSidebarNavigation(); ?></nav>
        </aside>
        <div class="sidebar-overlay" id="sidebarOverlay"></div>

        <main class="dashboard-main" id="amenityApp" data-csrf="<?= htmlspecialchars(workflowCsrfToken(),ENT_QUOTES,'UTF-8') ?>">
            <header class="dash-header">
                <div class="dash-header-left"><button class="btn-icon-menu" id="menuToggle" type="button" aria-label="Menu"><?php echo systemIcon('menu', 'menu-icon'); ?></button><div><span class="dash-subtitle">THE CELANDINE</span><h1 class="dash-title">Booking Requests</h1></div></div>
                <div class="dash-header-right">
                    <?php include '../notifications.php'; ?>
                    <div class="profile-menu" id="profileMenu"><button class="btn-profile-nav" id="profileToggle" type="button" aria-label="Profile menu" aria-haspopup="true" aria-expanded="false"><?= profileAvatar('profile-avatar') ?><span class="profile-nav-name"><?php echo htmlspecialchars($username); ?></span><span class="profile-caret">&#9662;</span></button><div class="profile-dropdown" id="profileDropdown"><div class="profile-dropdown-header"><?= profileAvatar('profile-dropdown-avatar') ?><div class="profile-dropdown-info"><span class="profile-dropdown-name"><?php echo htmlspecialchars($username); ?></span><span class="profile-dropdown-unit">Administrator</span></div></div><a href="../logout.php" class="profile-dropdown-item profile-dropdown-danger"><?php echo systemIcon('arrow-right', 'system-action-icon'); ?> Logout</a></div></div>
                </div>
            </header>

            <?php if($flash): ?><div class="alert success" role="status"><?= $escape($flash['message']) ?></div><?php endif; ?>
            <?php if($errors): ?><div class="alert error" role="alert"><?php foreach($errors as $error): ?><p><?= $escape($error) ?></p><?php endforeach; ?></div><?php endif; ?>
            <section class="amenity-page-intro"><div><p class="amenity-kicker">AMENITY MANAGEMENT</p><h2>Reservations and amenity schedules</h2><p>Review requests, check schedule availability and follow billing status. Pool approvals reserve the hours and create one unit-account charge.</p></div></section>
            <nav class="amenity-view-nav" aria-label="Booking management views" data-default-view="<?= $defaultView ?>" hidden>
                <button type="button" id="amenityRequestsTab" data-amenity-view="bookingRequests" aria-controls="bookingRequests">Booking Requests <span class="amenity-count"><?= count($bookingGroups['bookingRequests']) ?></span></button>
                <button type="button" id="amenityAvailabilityTab" data-amenity-view="amenityAvailability" aria-controls="amenityAvailability">Availability</button>
                <button type="button" id="amenityPastBookingsTab" data-amenity-view="bookingHistory" aria-controls="bookingHistory">History <span class="amenity-count"><?= count($bookingGroups['bookingHistory']) ?></span></button>
            </nav>
            <?php foreach ($bookingGroups as $viewKey => $tabBookings): ?>
                <?php
                $panelStatuses = $viewKey === 'bookingHistory' ? $statuses : ['pending', 'approved', 'confirmed'];
                $panelStatus = in_array($statusFilter, $panelStatuses, true) ? $statusFilter : 'all';
                $bookings = array_values(array_filter($tabBookings, static fn(array $booking): bool => $panelStatus === 'all' || $booking['status'] === $panelStatus));
                require '../includes/staff_amenity_records.php';
                ?>
            <?php endforeach; ?>
            <section id="amenityAvailability" class="amenity-availability-panels">
            <section class="amenity-admin-schedule"><div><h2>Swimming Pool availability</h2><p>Exclusive reservations · ₱<?= number_format(AMENITY_POOL_HOURLY_RATE) ?>/hour · 6 AM–9 PM · Asia/Manila</p></div>
                <form data-pool-schedule data-amenity-schedule="Swimming Pool"><div class="amenity-form-grid"><label class="field-label">Schedule date<input type="date" name="booking_date" value="<?= date('Y-m-d') ?>" required></label><label class="field-label">Duration<select name="duration_hours"><option value="1">1 Hour</option><option value="2">2 Hours</option><option value="3">3 Hours</option></select></label></div><button type="button" class="service-btn service-btn-secondary" data-refresh-slots>Refresh availability</button><p data-availability-message class="amenity-availability-message" role="status" aria-live="polite"></p><div data-time-slots class="amenity-time-slots" aria-label="Pool schedule"></div></form>
            </section>
            <section class="amenity-admin-schedule"><div><h2>Function Hall availability</h2><p>Exclusive full-day reservations &middot; &#8369;<?= number_format(AMENITY_HALL_DAILY_RATE) ?>/day &middot; Asia/Manila</p><p>Each available day can accommodate one reservation. Pending and approved requests hold the entire day.</p></div>
                <form data-hall-calendar>
                    <div class="amenity-calendar-controls"><button type="button" class="service-btn service-btn-secondary" data-calendar-step="-1" aria-label="Previous month">&lsaquo;</button><label class="field-label">Month<input type="month" name="month" min="1000-01" max="9998-12" value="<?= date('Y-m') ?>" required></label><button type="button" class="service-btn service-btn-secondary" data-calendar-step="1" aria-label="Next month">&rsaquo;</button><button type="button" class="service-btn service-btn-secondary" data-refresh-calendar>Refresh availability</button></div>
                    <p data-calendar-message class="amenity-availability-message" role="status" aria-live="polite"></p>
                    <div class="amenity-calendar" role="group" aria-label="Function Hall daily availability"><div class="amenity-calendar-weekdays" aria-hidden="true"><?php foreach (['Sun','Mon','Tue','Wed','Thu','Fri','Sat'] as $weekday): ?><span><?= $weekday ?></span><?php endforeach; ?></div><div data-calendar-days class="amenity-calendar-days" role="list" aria-label="Reservation days"></div></div>
                    <noscript><p>Enable JavaScript to view available reservation days.</p></noscript>
                </form>
            </section>
            </section>
        </main>
    </div>
    <script src="../js/services-menu.js"></script><script src="../js/profile-menu.js"></script><script src="../js/notification-menu.js"></script><script src="../js/amenities.js?v=<?= filemtime(__DIR__.'/../js/amenities.js') ?>"></script>
</body></html>
