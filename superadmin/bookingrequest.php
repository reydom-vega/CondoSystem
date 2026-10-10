<?php
require_once '../config.php';
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
        setFlash('success','Booking updated successfully.'); redirect('bookingrequest.php');
    } catch(InvalidArgumentException $error) { $errors[]=$error->getMessage(); }
    catch(Throwable $error) { error_log($error->getMessage()); $errors[]='Unable to update this booking. Please try again.'; }
}
$statusFilter=is_string($_GET['status'] ?? null)?$_GET['status']:'all';
$statuses=['pending','approved','confirmed','rejected','cancelled','completed'];
if(!in_array($statusFilter,array_merge(['all'],$statuses),true)) $statusFilter='all';
$sql='SELECT b.*,u.full_name,u.username,u.account_type,p.status AS payment_status,p.paymongo_checkout_id FROM bookings b JOIN users u ON u.id=b.user_id LEFT JOIN payments p ON p.id=b.payment_id';
if($statusFilter!=='all') $sql.=' WHERE b.status=?';
$sql.=' ORDER BY b.booking_date DESC,b.booking_time DESC,b.id DESC';
$query=$connection->prepare($sql); if($statusFilter!=='all') $query->bind_param('s',$statusFilter);
$query->execute(); $bookings=$query->get_result()->fetch_all(MYSQLI_ASSOC);
$flash=getFlash(); $escape=static fn($value)=>htmlspecialchars((string)$value,ENT_QUOTES,'UTF-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Celandine Residences</title>
    <link rel="stylesheet" href="../styles.css">
    <link rel="stylesheet" href="../services.css">
    <link rel="stylesheet" href="../amenities.css?v=<?= filemtime(__DIR__ . '/../amenities.css') ?>">
<?php renderPortalUiHead(); ?>
</head>
<body class="portal-ui dashboard-page admin-page amenity-page">
    <div class="dash-layout">
        <aside class="sidebar" id="sidebar">
            <a href="<?php echo htmlspecialchars(buildUrl(dashboardPathForRole()), ENT_QUOTES, 'UTF-8'); ?>" class="sidebar-brand"><?php include '../buildingicon.php'; ?><span class="brand-title">CELANDINE<br>RESIDENCES</span></a>
            <nav class="sidebar-nav"><?php renderStaffSidebarNavigation(); ?></nav>
        </aside>
        <div class="sidebar-overlay" id="sidebarOverlay"></div>

        <main class="dashboard-main" id="amenityApp" data-csrf="<?= htmlspecialchars(workflowCsrfToken(),ENT_QUOTES,'UTF-8') ?>">
            <header class="dash-header">
                <div class="dash-header-left"><button class="btn-icon-menu" id="menuToggle" type="button" aria-label="Menu"><?php echo systemIcon('menu', 'menu-icon'); ?></button><div><span class="dash-subtitle">CELANDINE RESIDENCES</span><h1 class="dash-title">Booking Requests</h1></div></div>
                <div class="dash-header-right">
                    <?php include '../notifications.php'; ?>
                    <div class="profile-menu" id="profileMenu"><button class="btn-profile-nav" id="profileToggle" type="button" aria-label="Profile menu" aria-haspopup="true" aria-expanded="false"><span class="profile-avatar"><?php echo htmlspecialchars($initials); ?></span><span class="profile-nav-name"><?php echo htmlspecialchars($username); ?></span><span class="profile-caret">&#9662;</span></button><div class="profile-dropdown" id="profileDropdown"><div class="profile-dropdown-header"><span class="profile-dropdown-avatar"><?php echo htmlspecialchars($initials); ?></span><div class="profile-dropdown-info"><span class="profile-dropdown-name"><?php echo htmlspecialchars($username); ?></span><span class="profile-dropdown-unit">Administrator</span></div></div><a href="../logout.php" class="profile-dropdown-item profile-dropdown-danger"><?php echo systemIcon('arrow-right', 'system-action-icon'); ?> Logout</a></div></div>
                </div>
            </header>

            <?php if($flash): ?><div class="alert success" role="status"><?= $escape($flash['message']) ?></div><?php endif; ?>
            <?php if($errors): ?><div class="alert error" role="alert"><?php foreach($errors as $error): ?><p><?= $escape($error) ?></p><?php endforeach; ?></div><?php endif; ?>
            <section class="amenity-page-intro"><div><p class="amenity-kicker">AMENITY MANAGEMENT</p><h2>Reservations and pool schedule</h2><p>Review requests, check schedule availability and follow billing status. Pool approvals reserve the hours and create one unit-account charge.</p></div></section>
            <section class="amenity-admin-schedule"><div><h2>Swimming Pool availability</h2><p>Exclusive reservations · ₱300/hour · 6 AM–9 PM · Asia/Manila</p></div>
                <form data-pool-schedule><div class="amenity-form-grid"><label class="field-label">Schedule date<input type="date" name="booking_date" value="<?= date('Y-m-d') ?>" required></label><label class="field-label">Duration<select name="duration_hours"><option value="1">1 Hour</option><option value="2">2 Hours</option><option value="3">3 Hours</option></select></label></div><button type="button" class="service-btn service-btn-secondary" data-refresh-slots>Refresh availability</button><p data-availability-message class="amenity-availability-message" role="status" aria-live="polite"></p><div data-time-slots class="amenity-time-slots" aria-label="Pool schedule"></div></form>
            </section>
            <section class="amenity-booking-history"><div class="amenity-section-heading"><h2>Booking requests</h2><p><?= count($bookings) ?> matching reservation(s)</p></div>
                <form class="amenity-admin-filter" method="get"><label class="field-label">Booking status<select name="status"><option value="all">All statuses</option><?php foreach($statuses as $value): ?><option value="<?= $escape($value) ?>" <?= $statusFilter===$value?'selected':'' ?>><?= ucfirst($value) ?></option><?php endforeach; ?></select></label><button class="service-btn" type="submit">Apply filter</button></form>
                <p class="amenity-payment-note">Scroll to see all columns. Pending pool requests do not hold hours. Paid or online-checkout cancellations require PMO and billing review.</p>
                <div class="amenity-admin-table-wrap" role="region" aria-label="Amenity booking requests" tabindex="0"><table class="amenity-admin-table"><thead><tr><th scope="col">Booking</th><th scope="col">Resident / Tenant</th><th scope="col">Unit</th><th scope="col">Date</th><th scope="col">Start – End</th><th scope="col">Duration</th><th scope="col">Total fee</th><th scope="col">Booking status</th><th scope="col">Payment</th><th scope="col">Details &amp; Actions</th></tr></thead><tbody>
                <?php if(!$bookings): ?><tr><td colspan="10">No reservations match this status.</td></tr><?php endif; ?>
                <?php foreach($bookings as $booking):
                    $future=$booking['booking_date'].' '.$booking['booking_time']>date('Y-m-d H:i:s');
                    $review=$booking['payment_id'] && ($booking['payment_status']==='paid' || $booking['paymongo_checkout_id']);
                    $active=in_array($booking['status'],['pending','approved','confirmed'],true);
                ?>
                    <tr><td><strong>#<?= (int)$booking['id'] ?></strong><span><?= $escape($booking['amenity']) ?></span></td><td><strong><?= $escape($booking['full_name']) ?></strong><span><?= $escape($booking['account_type'] ?: 'Resident Owner') ?></span></td><td><?= $escape($booking['unit_number']) ?></td><td><?= $escape(date('M j, Y',strtotime($booking['booking_date']))) ?></td><td><?= $escape(date('g:i A',strtotime($booking['booking_time']))) ?> – <?= $escape($booking['end_time']?date('g:i A',strtotime($booking['end_time'])):'—') ?></td><td><?= $booking['duration_hours']?(int)$booking['duration_hours'].' hour(s)':'Full day' ?></td><td>₱<?= number_format((float)$booking['total_fee'],2) ?></td><td><span class="amenity-status amenity-status-<?= $escape($booking['status']) ?>"><?= ucfirst($booking['status']) ?></span></td><td><?= $booking['payment_id']?$escape($booking['payment_status']==='pending'?'Unpaid':($booking['payment_status']==='rejected'?'Voided':ucfirst($booking['payment_status']))):'No auto bill' ?><?php if($booking['payment_id']): ?><span>Bill #<?= (int)$booking['payment_id'] ?></span><?php endif; ?></td><td>
                        <details class="amenity-decision-details"><summary>View details / actions</summary><p><?= (int)$booking['attendees'] ?> guest(s) · Unit <?= $escape($booking['unit_number']) ?></p><p>Rate snapshot: ₱<?= number_format((float)$booking['hourly_rate'],2) ?>/hour</p><p>Approved: <?= $escape($booking['approved_at'] ?: 'Not yet approved') ?><?= $booking['approved_by']?' · Staff #'.(int)$booking['approved_by']:'' ?></p>
                        <?php if($booking['rejection_reason'] || $booking['cancellation_note']): ?><p><?= $escape($booking['rejection_reason'] ?: $booking['cancellation_note']) ?></p><?php endif; ?>
                        <?php if($active): ?><form method="post" data-booking-decision="<?= (int)$booking['id'] ?>"><?= workflowCsrfField() ?><input type="hidden" name="booking_id" value="<?= (int)$booking['id'] ?>"><label class="field-label">Reason / PMO notes<textarea name="reason" maxlength="500" rows="3" placeholder="Required for rejection or PMO cancellation"></textarea></label>
                            <?php if($review && canManageBilling()): ?><label class="field-label amenity-pmo-checkbox"><input type="checkbox" name="pmo_review"> PMO reviewed cancellation; preserve this bill for financial/refund review.</label><?php endif; ?>
                            <div class="amenity-decision-buttons"><?php if($booking['status']==='pending' && $future): ?><button class="service-btn" type="submit" name="status" value="approved">Approve</button><button class="service-btn service-btn-danger" type="submit" name="status" value="rejected">Reject</button><?php endif; ?>
                            <?php if($future && (!$review || canManageBilling())): ?><button class="service-btn service-btn-secondary" type="submit" name="status" value="cancelled"><?= $review?'PMO cancellation':'Cancel' ?></button><?php endif; ?>
                            <?php if(in_array($booking['status'],['approved','confirmed'],true) && $booking['booking_date'].' '.$booking['end_time']<=date('Y-m-d H:i:s')): ?><button class="service-btn service-btn-secondary" type="submit" name="status" value="completed">Mark completed</button><?php endif; ?></div><p data-decision-feedback role="status" aria-live="polite"></p>
                        </form><?php endif; ?></details>
                    </td></tr>
                <?php endforeach; ?></tbody></table></div>
            </section>
        </main>
    </div>
    <script src="../js/services-menu.js"></script><script src="../js/profile-menu.js"></script><script src="../js/notification-menu.js"></script><script src="../js/amenities.js?v=<?= filemtime(__DIR__.'/../js/amenities.js') ?>"></script>
</body></html>
