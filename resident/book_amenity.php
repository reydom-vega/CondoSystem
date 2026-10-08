<?php
require_once '../config.php';

if (!isLoggedIn()) {
    redirect('../login.php');
}
if (isAdmin()) {
    redirect(isSecurity() ? '../security/security_dashboard.php' : (isMaintenance() ? '../maintenance/maintenance_dashboard.php' : '../admin/admin_dashboard.php'));
}
requireApproval();
requireResidentPermission('resident.amenities.book');

$username = $_SESSION['username'] ?? 'User';
$unitNumber = isset($_SESSION['unit_number']) ? 'Unit ' . htmlspecialchars($_SESSION['unit_number']) : 'Unit Not Set';
$nameParts = preg_split('/\s+/', trim($username));
$initials = strtoupper(substr($nameParts[0], 0, 1) . (count($nameParts) > 1 ? substr(end($nameParts), 0, 1) : ''));
$errors = [];
$success = false;

$connection = connectDb();
$tableReady = ensureAmenityBookingSchema($connection);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireWorkflowCsrf();
    $amenity = trim($_POST['amenity'] ?? '');
    $bookingDate = trim($_POST['booking_date'] ?? '');
    $bookingTime = trim($_POST['booking_time'] ?? '');
    try {
        if (!$tableReady) throw new RuntimeException('Booking storage is unavailable.');
        if (($_POST['action'] ?? '') === 'cancel') {
            $bookingId = (int)($_POST['booking_id'] ?? 0);
            $cancel = $connection->prepare("UPDATE bookings SET status = 'cancelled' WHERE id = ? AND user_id = ? AND status IN ('pending','confirmed') AND TIMESTAMP(booking_date,booking_time) > NOW()");
            $cancel->bind_param('ii', $bookingId, $_SESSION['user_id']); $cancel->execute();
            if ($cancel->affected_rows !== 1) throw new InvalidArgumentException('This booking can no longer be cancelled.');
            logAudit('cancel','booking',$bookingId);
        } else {
            $attendees = filter_var($_POST['attendees'] ?? null, FILTER_VALIDATE_INT);
            if ($attendees === false || !createAmenityBooking($connection, (int)$_SESSION['user_id'], $amenity, $bookingDate, $bookingTime, $attendees)) throw new InvalidArgumentException('Unable to save booking. Enter a valid guest count.');
            trackEvent('booking_created', $amenity . ' on ' . $bookingDate, (int)$_SESSION['user_id']);
        }
        setFlash('success', 'Booking saved successfully.');
        redirect('book_amenity.php');
    } catch (InvalidArgumentException $e) { $errors[] = $e->getMessage(); }
    catch (Throwable $e) { error_log($e->getMessage()); $errors[] = 'Unable to save your booking. Please try again.'; }
}
$bookingFlash = getFlash();
$success = $bookingFlash !== null;

$amenities = [
    [
        'name'        => 'Swimming Pool',
        'description' => 'Olympic-size pool on the 3rd floor',
        'image'       => '../IMAGES/ThePool.webp',
        'photo_class' => 'amenity-photo-pool',
        'meta'        => [
            ['icon' => 'clock', 'label' => 'Hours: 6AM - 9PM'],
            ['icon' => 'users', 'label' => 'Max 20 persons'],
        ],
        'button_class' => 'amenity-btn-blue',
    ],
    [
        'name'        => 'Function Hall',
        'description' => 'Perfect for events and gatherings',
        'image'       => '../IMAGES/ThePatio.webp',
        'photo_class' => 'amenity-photo-hall',
        'meta'        => [
            ['icon' => 'clock', 'label' => 'Capacity: 100 persons'],
            ['icon' => 'users', 'label' => 'Rates: ₱3,000/day'],
        ],
        'button_class' => 'amenity-btn-purple',
    ],
];

$bookings = [];
if ($tableReady) {
    $bookingQuery = $connection->prepare('SELECT id, amenity, booking_date, booking_time, attendees, status FROM bookings WHERE user_id = ? ORDER BY booking_date ASC, booking_time ASC');
    $bookingQuery->bind_param('i', $_SESSION['user_id']);
    $bookingQuery->execute();
    $bookings = $bookingQuery->get_result()->fetch_all(MYSQLI_ASSOC);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Celandine Residences - Book Amenities</title>
    <link rel="stylesheet" href="../resident.css">
    <link rel="stylesheet" href="../services.css?v=<?php echo filemtime(__DIR__ . '/../services.css'); ?>">
</head>
<body class="dashboard-page">

    <div class="dash-layout">

        <aside class="sidebar" id="sidebar">
            <a href="dashboard.php" class="sidebar-brand">
                <?php include '../buildingicon.php'; ?>
                <span class="brand-title">CELANDINE<br>RESIDENCES</span>
            </a>

            <nav class="sidebar-nav"><?php renderResidentSidebarNavigation(); ?></nav>
        </aside>

        <div class="sidebar-overlay" id="sidebarOverlay"></div>

        <main class="dashboard-main">
            <header class="dash-header">
                <div class="dash-header-left">
                    <button class="btn-icon-menu" id="menuToggle" aria-label="Menu">
                        <?php echo systemIcon('menu', 'menu-icon'); ?>
                    </button>
                    <div>
                        <span class="dash-subtitle">CELANDINE RESIDENCES</span>
                        <h1 class="dash-title">Book Amenities</h1>
                    </div>
                </div>
                <div class="dash-header-right">
                    <?php include '../notifications.php'; ?>
                    <div class="profile-menu" id="profileMenu">
                        <button class="btn-profile-nav" id="profileToggle" aria-label="Profile menu" aria-haspopup="true" aria-expanded="false">
                            <span class="profile-avatar"><?php echo htmlspecialchars($initials); ?></span>
                            <span class="profile-nav-name"><?php echo htmlspecialchars($username); ?></span>
                            <span class="profile-caret">▾</span>
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

            <?php if ($success): ?><div class="alert success" role="status"><?php echo htmlspecialchars($bookingFlash['message']); ?></div><?php endif; ?>
            <?php if (!empty($errors)): ?><div class="alert error"><ul><?php foreach ($errors as $error): ?><li><?php echo htmlspecialchars($error); ?></li><?php endforeach; ?></ul></div><?php endif; ?>

            <p class="bookings-empty">Looking for parking? Visit the <a href="parking.php">Parking</a> page to check your assigned slot or request visitor parking.</p>

            <section class="amenities-grid">
                <?php foreach ($amenities as $amenity): ?>
                    <div class="amenity-card">
                        <div class="amenity-photo <?php echo htmlspecialchars($amenity['photo_class']); ?>">
                            <img src="<?php echo htmlspecialchars($amenity['image']); ?>" alt="<?php echo htmlspecialchars($amenity['name']); ?>" class="amenity-photo-img">
                        </div>
                        <div class="amenity-body">
                            <h3 class="amenity-title"><?php echo htmlspecialchars($amenity['name']); ?></h3>
                            <p class="amenity-desc"><?php echo htmlspecialchars($amenity['description']); ?></p>
                            <ul class="amenity-meta">
                                <?php foreach ($amenity['meta'] as $meta): ?>
                                    <li><?php echo systemIcon($meta['icon'], 'amenity-meta-icon'); ?> <?php echo htmlspecialchars($meta['label']); ?></li>
                                <?php endforeach; ?>
                            </ul>
                            <form method="POST" action="book_amenity.php"><?php echo workflowCsrfField(); ?>
                                <input type="hidden" name="amenity" value="<?php echo htmlspecialchars($amenity['name']); ?>">
                                <label class="field-label" for="booking_date_<?php echo htmlspecialchars($amenity['name']); ?>">Date</label>
                                <input class="form-date" type="date" id="booking_date_<?php echo htmlspecialchars($amenity['name']); ?>" name="booking_date" min="<?php echo date('Y-m-d'); ?>" required>
                                <label class="field-label" for="booking_time_<?php echo htmlspecialchars($amenity['name']); ?>">Time</label>
                                <input class="form-date" type="time" id="booking_time_<?php echo htmlspecialchars($amenity['name']); ?>" name="booking_time" min="06:00" max="20:00" step="3600" required>
                                <label class="field-label">Number of guests<input class="form-date" type="number" name="attendees" min="1" max="<?php echo $amenity['name'] === 'Swimming Pool' ? 20 : 100; ?>" value="1" required></label>
                                <p class="amenity-desc"><?php echo $amenity['name'] === 'Swimming Pool' ? 'One-hour sessions, up to 20 guests across all bookings. Pending requests reserve capacity.' : 'Exclusive full-day reservation, up to 100 guests. Select your arrival time.'; ?></p>
                                <button type="submit" class="amenity-book-btn <?php echo htmlspecialchars($amenity['button_class']); ?>">Book Now</button>
                            </form>
                        </div>
                    </div>
                <?php endforeach; ?>
            </section>

            <section class="bookings-section">
                <h3 class="section-title">Your Bookings</h3>

                <?php if (empty($bookings)): ?>
                    <p class="bookings-empty">You have no bookings yet.</p>
                <?php else: ?>
                    <?php foreach ($bookings as $booking): ?>
                        <div class="booking-item">
                            <?php echo systemIconFromGlyph('📋', 'booking-icon'); ?>
                            <div class="booking-content">
                                <h4><?php echo htmlspecialchars($booking['amenity']); ?></h4>
                                <p><?php echo htmlspecialchars(date('M j, Y', strtotime($booking['booking_date'])) . ' - ' . date('g:i A', strtotime($booking['booking_time']))); ?></p>
                            </div>
                            <span class="badge badge-success"><?php echo htmlspecialchars($booking['status']); ?></span>
                            <?php if (in_array($booking['status'], ['pending','confirmed'], true) && $booking['booking_date'] . ' ' . $booking['booking_time'] > date('Y-m-d H:i:s')): ?>
                            <form method="post"><?php echo workflowCsrfField(); ?><input type="hidden" name="action" value="cancel"><input type="hidden" name="booking_id" value="<?php echo (int)$booking['id']; ?>"><button type="submit" class="service-btn service-btn-danger">Cancel</button></form>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </section>
        </main>

    </div>
    <script>
        // Toggle Mobile Sidebar
        const menuToggle = document.getElementById('menuToggle');
        const sidebar = document.getElementById('sidebar');
        const overlay = document.getElementById('sidebarOverlay');

        if (menuToggle) {
            menuToggle.addEventListener('click', () => {
                sidebar.classList.toggle('open');
                overlay.classList.toggle('open');
            });
        }

        if (overlay) {
            overlay.addEventListener('click', () => {
                sidebar.classList.remove('open');
                overlay.classList.remove('open');
            });
        }

        // Profile Dropdown Menu Toggle
        const profileMenu = document.getElementById('profileMenu');
        const profileToggle = document.getElementById('profileToggle');

        if (profileToggle && profileMenu) {
            profileToggle.addEventListener('click', (e) => {
                e.stopPropagation();
                const isOpen = profileMenu.classList.toggle('open');
                profileToggle.setAttribute('aria-expanded', isOpen);
            });

            document.addEventListener('click', (e) => {
                if (!profileMenu.contains(e.target)) {
                    profileMenu.classList.remove('open');
                    profileToggle.setAttribute('aria-expanded', 'false');
                }
            });

            document.addEventListener('keydown', (e) => {
                if (e.key === 'Escape') {
                    profileMenu.classList.remove('open');
                    profileToggle.setAttribute('aria-expanded', 'false');
                }
            });
        }
    </script>
</body>
</html>
