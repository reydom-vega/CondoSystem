<?php
require_once '../config.php';
if (!isLoggedIn()) redirect('../login.php');
if (isAdmin()) redirect(buildUrl(dashboardPathForRole()));
requireApproval();
requireResidentPermission('resident.amenities.book');
$username=$_SESSION['username'] ?? 'Resident';
$unitNumber='Unit '.htmlspecialchars($_SESSION['unit_number'] ?? '');
$nameParts=preg_split('/\s+/',trim($username));
$initials=strtoupper(substr($nameParts[0],0,1).(count($nameParts)>1?substr(end($nameParts),0,1):''));
$connection=connectDb(); ensureAmenityBookingSchema($connection); ensurePaymentsTable($connection); ensurePaymongoColumns($connection);
$errors=[]; $conflict=false;
if($_SERVER['REQUEST_METHOD']==='POST') {
    requireWorkflowCsrf();
    try {
        if(($_POST['action'] ?? '')==='cancel') decideAmenityBooking($connection,(int)($_POST['booking_id'] ?? 0),'cancelled');
        else {
            $guests=filter_var($_POST['attendees'] ?? null,FILTER_VALIDATE_INT);
            $duration=filter_var($_POST['duration_hours'] ?? 1,FILTER_VALIDATE_INT);
            if($guests===false || $duration===false) throw new InvalidArgumentException('Enter valid guests and duration.');
            createAmenityBooking($connection,(int)$_SESSION['user_id'],trim((string)($_POST['amenity'] ?? '')),trim((string)($_POST['booking_date'] ?? '')),trim((string)($_POST['booking_time'] ?? '')),$guests,$duration);
            trackEvent('booking_created',(string)$_POST['amenity'].' on '.(string)$_POST['booking_date'],(int)$_SESSION['user_id']);
        }
        setFlash('success','Reservation saved successfully.'); redirect('book_amenity.php#yourBookings');
    } catch(AmenityScheduleConflict $error) { $conflict=true; $errors[]=$error->getMessage(); }
    catch(InvalidArgumentException $error) { $errors[]=$error->getMessage(); }
    catch(Throwable $error) { error_log($error->getMessage()); $errors[]='Unable to save your reservation. Please try again.'; }
}
$bookingFlash=getFlash();
$query=$connection->prepare('SELECT b.*,p.status AS payment_status,p.paymongo_checkout_id FROM bookings b LEFT JOIN payments p ON p.id=b.payment_id WHERE b.user_id=? ORDER BY b.booking_date DESC,b.booking_time DESC,b.id DESC');
$query->bind_param('i',$_SESSION['user_id']); $query->execute(); $bookings=$query->get_result()->fetch_all(MYSQLI_ASSOC);
$catalog=amenityCatalog();
$escape=static fn($value)=>htmlspecialchars((string)$value,ENT_QUOTES,'UTF-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Celandine Residences - Book Amenities</title>
    <link rel="stylesheet" href="../resident.css">
    <link rel="stylesheet" href="../services.css?v=<?php echo filemtime(__DIR__ . '/../services.css'); ?>">
    <link rel="stylesheet" href="../amenities.css?v=<?= filemtime(__DIR__ . '/../amenities.css') ?>">
<?php renderPortalUiHead(); ?>
</head>
<body class="portal-ui dashboard-page amenity-page amenity-resident-page">

    <div class="dash-layout">

        <aside class="sidebar" id="sidebar">
            <a href="dashboard.php" class="sidebar-brand">
                <?php include '../buildingicon.php'; ?>
                <span class="brand-title">CELANDINE<br>RESIDENCES</span>
            </a>

            <nav class="sidebar-nav"><?php renderResidentSidebarNavigation(); ?></nav>
        </aside>

        <div class="sidebar-overlay" id="sidebarOverlay"></div>

        <main class="dashboard-main" id="amenityApp" data-csrf="<?= htmlspecialchars(workflowCsrfToken(),ENT_QUOTES,'UTF-8') ?>">
            <header class="dash-header">
                <div class="dash-header-left">
                    <button class="btn-icon-menu" id="menuToggle" aria-label="Menu">
                        <?php echo systemIcon('menu', 'menu-icon'); ?>
                    </button>
                    <div>
                        <span class="dash-subtitle">CELANDINE RESIDENCES</span>
                        <h1 class="dash-title">Book Amenity</h1>
                    </div>
                </div>
                <div class="dash-header-right">
                    <?php include '../notifications.php'; ?>
                    <div class="profile-menu" id="profileMenu">
                        <button class="btn-profile-nav" id="profileToggle" aria-label="Profile menu" aria-haspopup="true" aria-expanded="false">
                            <span class="profile-avatar"><?php echo htmlspecialchars($initials); ?></span>
                            <span class="profile-nav-name"><?php echo htmlspecialchars($username); ?></span>
                            <span class="profile-caret">&#9662;</span>
                        </button>
                        <div class="profile-dropdown" id="profileDropdown">
                            <div class="profile-dropdown-header">
                                <span class="profile-dropdown-avatar"><?php echo htmlspecialchars($initials); ?></span>
                                <div class="profile-dropdown-info">
                                    <span class="profile-dropdown-name"><?php echo htmlspecialchars($username); ?></span>
                                    <span class="profile-dropdown-unit"><?php echo $unitNumber; ?></span>
                                </div>
                            </div>
                            <a href="edit_profile.php" class="profile-dropdown-item">
                                <?php echo systemIcon('edit', 'system-action-icon'); ?> Edit Profile
                            </a>
                            <a href="../logout.php" class="profile-dropdown-item profile-dropdown-danger">
                                <?php echo systemIcon('arrow-right', 'system-action-icon'); ?> Logout
                            </a>
                        </div>
                    </div>
                </div>
            </header>

            <?php if($bookingFlash): ?><div class="alert success" role="status"><?= $escape($bookingFlash['message']) ?></div><?php endif; ?>
            <?php if($errors): ?><div class="alert error" role="alert"><?php if($conflict): ?><strong>Time Slot Already Booked</strong><?php endif; ?><?php foreach($errors as $error): ?><p><?= $escape($error) ?></p><?php endforeach; ?></div><?php endif; ?>
            <section class="amenity-page-intro">
                <div><p class="amenity-kicker">YOUR COMMUNITY, YOUR SPACE</p><h2>Make time for what matters</h2><p>Reserve the Swimming Pool or Function Hall, or explore the other amenities in your community.</p></div>
                <span class="amenity-timezone">All times in Asia/Manila</span>
            </section>
            <nav class="amenity-view-nav" aria-label="Amenity views" hidden>
                <button type="button" id="amenityExploreTab" data-amenity-view="exploreAmenities" aria-controls="exploreAmenities">Explore amenities</button>
                <button type="button" id="amenityBookingsTab" data-amenity-view="yourBookings" aria-controls="yourBookings">My reservations <span class="amenity-count"><?= count($bookings) ?></span></button>
            </nav>
            <section id="exploreAmenities" aria-labelledby="amenityExploreHeading">
            <div class="amenity-section-heading amenity-reservable-heading"><div><h2 class="amenity-section-title" id="amenityExploreHeading">Find your next reservation</h2><p>Choose a space below. Every request is subject to admin approval.</p></div><span class="amenity-section-count">2 reservable amenities</span></div>

            <div class="amenity-featured-grid">
            <?php foreach($catalog as $name=>$entry): if(!$entry['booking']) continue; ?>
                <article class="amenity-card" id="amenity-<?= $escape($entry['slug']) ?>">
                    <?php renderAmenityGallery($name,false); ?>
                    <div class="amenity-body"><div class="amenity-title-row"><h3><?= $escape($name) ?></h3><span class="amenity-price"><?= $escape($entry['price']) ?></span></div>
                        <p><?= $escape($entry['description']) ?></p><dl class="amenity-facts"><div><dt>Operating hours</dt><dd><?= $escape($entry['hours']) ?></dd></div><div><dt>Location</dt><dd><?= $escape($entry['location']) ?></dd></div></dl>
                        <details class="amenity-information"><summary>Rules and reservation details</summary><p><?= $escape($entry['rules']) ?></p><?php if($name==='Swimming Pool'): ?><p>Pricing is per reservation, independent of guest count. Approval creates one bill for your unit owner. Paid reservations and online checkouts require PMO review before cancellation.</p><?php endif; ?></details>
                        <button type="button" class="service-btn" data-open-booking="<?= $escape($entry['slug']) ?>">Reserve <?= $escape($name) ?></button>
                    </div>
                </article>
            <?php endforeach; ?>
            </div>
            <div class="amenity-section-heading"><h2 class="amenity-section-title">More places to enjoy</h2><p>Information only &middot; Online reservations are not offered for these amenities.</p></div>
            <div class="amenity-information-grid">
            <?php foreach($catalog as $name=>$entry): if($entry['booking']) continue; ?>
                <article class="amenity-card" id="amenity-<?= $escape($entry['slug']) ?>">
                    <?php renderAmenityGallery($name); ?>
                    <div class="amenity-body"><div class="amenity-title-row"><h3><?= $escape($name) ?></h3><span class="amenity-view-only">View only</span></div><p><?= $escape($entry['description']) ?></p>
                        <details class="amenity-information"><summary>View amenity details</summary><dl class="amenity-facts"><div><dt>Operating hours</dt><dd><?= $escape($entry['hours']) ?></dd></div><div><dt>Location</dt><dd><?= $escape($entry['location']) ?></dd></div></dl><p>Ask PMO about access and the current house rules.</p></details>
                    </div>
                </article>
            <?php endforeach; ?>
            </div>
            </section>
            <section class="amenity-booking-history" id="yourBookings" aria-labelledby="amenityHistoryHeading"><h2 class="amenity-section-title" id="amenityHistoryHeading">My reservations</h2><p>Pending requests do not hold pool hours. Approved and paid reservations keep their schedule reserved.</p>
            <?php if(!$bookings): ?><div class="amenity-empty"><h3>No reservations yet</h3><p>Choose the Swimming Pool or Function Hall to plan your next visit.</p><a class="service-btn service-btn-secondary" href="#exploreAmenities" data-amenity-view-link>Explore amenities</a></div><?php endif; ?>
            <?php foreach($bookings as $booking):
                $future=$booking['booking_date'].' '.$booking['booking_time']>date('Y-m-d H:i:s');
                $review=$booking['payment_id'] && ($booking['payment_status']==='paid' || $booking['paymongo_checkout_id']);
            ?>
                <article class="amenity-history-card"><div><span class="amenity-kicker">BOOKING #<?= (int)$booking['id'] ?></span><h3><?= $escape($booking['amenity']) ?></h3><p><?= $escape(date('F j, Y',strtotime($booking['booking_date']))) ?> &middot; <?= $escape(date('g:i A',strtotime($booking['booking_time']))) ?><?= $booking['end_time']?' - '.$escape(date('g:i A',strtotime($booking['end_time']))):'' ?></p><p>Unit <?= $escape($booking['unit_number']) ?> &middot; <?= (int)$booking['attendees'] ?> <?= (int)$booking['attendees']===1?'guest':'guests' ?><?= $booking['duration_hours']?' · '.(int)$booking['duration_hours'].' '.((int)$booking['duration_hours']===1?'hour':'hours'):'' ?></p></div>
                <div class="amenity-history-status"><span class="amenity-status amenity-status-<?= $escape($booking['status']) ?>"><?= $escape(ucfirst($booking['status'])) ?></span><strong>&#8369;<?= number_format((float)$booking['total_fee'],2) ?></strong><span><?= $booking['payment_id']?'Payment: '.$escape($booking['payment_status']==='rejected'?'Voided':($booking['payment_status']==='pending'?'Unpaid':ucfirst($booking['payment_status']))):'No automatic bill issued' ?></span></div>
                <div class="amenity-history-actions">
                <?php if($booking['rejection_reason'] || $booking['cancellation_note']): ?><p class="amenity-history-note"><?= $escape($booking['rejection_reason'] ?: $booking['cancellation_note']) ?></p><?php endif; ?>
                <?php if($future && in_array($booking['status'],['pending','approved','confirmed'],true)): ?>
                    <?php if($review): ?><p class="amenity-history-note">Contact PMO to review cancellation and any payment or refund.</p><?php else: ?><form method="post"><?= workflowCsrfField() ?><input type="hidden" name="action" value="cancel"><input type="hidden" name="booking_id" value="<?= (int)$booking['id'] ?>"><button class="service-btn service-btn-danger" type="submit">Cancel reservation</button></form><?php endif; ?>
                <?php endif; ?>
                <?php if($booking['payment_id'] && in_array($booking['payment_status'],['pending','overdue'],true)): ?><a href="payments.php" class="service-btn service-btn-secondary">View unit bill</a><?php endif; ?>
                </div>
                </article>
            <?php endforeach; ?>
            </section>
            <dialog id="amenityBookingDialog" class="amenity-dialog" aria-labelledby="amenityBookingTitle">
                <div class="amenity-dialog-heading"><div><p class="amenity-kicker">REQUEST A RESERVATION</p><h2 id="amenityBookingTitle">Swimming Pool reservation</h2></div><button class="service-btn service-btn-secondary" type="button" data-close-amenity aria-label="Close reservation dialog">Close</button></div>
                <div data-booking-pane="swimming-pool">
                    <div class="amenity-booking-overview"><?php renderAmenityGallery('Swimming Pool'); ?><div><span class="amenity-overview-tag">Exclusive use</span><h3>A pool day, just for your group</h3><p>6 AM–9 PM &middot; Up to 20 guests</p><p>Choose 1–3 hours. &#8369;300 per hour, regardless of guest count.</p></div></div>
                    <form method="post" id="poolBookingForm" data-pool-reservation class="amenity-booking-layout">
                        <?= workflowCsrfField() ?><input type="hidden" name="amenity" value="Swimming Pool"><input type="hidden" name="booking_time" value="">
                        <div class="amenity-booking-fields">
                            <h3 class="amenity-step-heading"><span>1</span> Plan your visit</h3>
                            <div class="amenity-form-grid"><label class="field-label">Reservation date<input type="date" name="booking_date" min="<?= date('Y-m-d') ?>" required></label><label class="field-label">Duration<select name="duration_hours"><option value="1">1 hour</option><option value="2">2 hours</option><option value="3">3 hours</option></select></label><label class="field-label">Guests <span class="amenity-field-hint">Including yourself</span><input type="number" name="attendees" value="1" min="1" max="20" required></label></div>
                            <div class="amenity-slot-heading"><h3 class="amenity-step-heading"><span>2</span> Choose a time</h3><button type="button" class="service-btn service-btn-secondary" data-refresh-slots>Refresh</button></div>
                            <div class="amenity-slot-legend"><span>Available</span><span>Booked</span><span>Selected</span></div>
                            <p class="amenity-availability-message" data-availability-message role="status" aria-live="polite">Select a date to see available starting times.</p><div class="amenity-time-slots" data-time-slots role="group" aria-label="Available pool starting times"></div>
                            <noscript><p>Enable JavaScript to load schedule availability and choose a pool starting time.</p></noscript>
                        </div>
                        <aside class="amenity-booking-review" aria-label="Review pool reservation">
                            <div class="amenity-reservation-summary" aria-live="polite"><p class="amenity-kicker">REVIEW YOUR VISIT</p><h3>Booking summary</h3><dl><div><dt>Amenity</dt><dd>Swimming Pool</dd></div><div><dt>Date</dt><dd data-summary-date>Choose a date</dd></div><div><dt>Time</dt><dd data-summary-time>Choose a time</dd></div><div><dt>Duration</dt><dd data-summary-duration>1 hour</dd></div><div><dt>Hourly rate</dt><dd>&#8369;300/hour</dd></div><div class="amenity-summary-total"><dt>Total fee</dt><dd data-summary-fee>&#8369;300.00</dd></div></dl>
                            <p class="amenity-payment-note">Admin approval creates one charge on your unit owner's billing account. Pending requests do not hold a time slot.</p>
                            <div class="amenity-form-feedback" data-booking-feedback role="status" aria-live="polite"></div><button type="submit" class="service-btn" data-reservation-submit disabled>Submit reservation</button>
                            <p class="amenity-submit-hint">Select a date and available time to continue.</p>
                            </div>
                            <p class="amenity-review-help">Paid reservations and online checkouts require PMO review before cancellation.</p>
                        </aside>
                    </form>
                </div>
                <div data-booking-pane="function-hall" hidden>
                    <div class="amenity-booking-overview"><?php renderAmenityGallery('Function Hall'); ?><div><span class="amenity-overview-tag">Full-day reservation</span><h3>A space to bring everyone together</h3><p>Arrival: 6 AM–8 PM &middot; Up to 100 guests</p><p>&#8369;3,000 per day. Admin approval is required.</p></div></div>
                    <form method="post" class="amenity-hall-form amenity-booking-layout"><?= workflowCsrfField() ?><input type="hidden" name="amenity" value="Function Hall">
                        <div class="amenity-booking-fields"><h3 class="amenity-step-heading"><span>1</span> Plan your event</h3><div class="amenity-form-grid"><label class="field-label">Reservation date<input type="date" name="booking_date" min="<?= date('Y-m-d') ?>" required></label><label class="field-label">Arrival time<input type="time" name="booking_time" min="06:00" max="20:00" step="3600" required></label><label class="field-label">Number of guests<input type="number" name="attendees" min="1" max="100" value="1" required></label></div><div class="amenity-hall-guidance"><h3>Before you submit</h3><p><?= $escape($catalog['Function Hall']['rules']) ?></p><p>PMO will confirm your request and coordinate payment arrangements.</p></div></div>
                        <aside class="amenity-booking-review" aria-label="Function Hall reservation rate"><div class="amenity-reservation-summary"><p class="amenity-kicker">YOUR RESERVATION</p><h3>Function Hall</h3><dl><div><dt>Reservation type</dt><dd>Full day</dd></div><div><dt>Maximum guests</dt><dd>100</dd></div><div class="amenity-summary-total"><dt>Daily fee</dt><dd>&#8369;3,000.00</dd></div></dl><p class="amenity-payment-note">Payment arrangements are handled by PMO after approval.</p><button class="service-btn" type="submit">Submit reservation</button></div></aside>
                    </form>
                </div>
            </dialog>
            <?php include __DIR__.'/../includes/amenity_lightbox.php'; ?>
        </main>
    </div>
    <script src="../js/services-menu.js"></script><script src="../js/profile-menu.js"></script><script src="../js/notification-menu.js"></script>
    <script src="../js/amenities.js?v=<?= filemtime(__DIR__.'/../js/amenities.js') ?>"></script>
</body></html>
