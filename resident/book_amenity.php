<?php
require_once '../config.php';

if (!isLoggedIn()) {
    redirect('../login.php');
}
if (isAdmin()) {
    redirect(isSecurity() ? '../security/security_dashboard.php' : (isMaintenance() ? '../maintenance/maintenance_dashboard.php' : '../admin/admin_dashboard.php'));
}
requireApproval();

$username = $_SESSION['username'] ?? 'User';
$unitNumber = isset($_SESSION['unit_number']) ? 'Unit ' . htmlspecialchars($_SESSION['unit_number']) : 'Unit Not Set';
$nameParts = preg_split('/\s+/', trim($username));
$initials = strtoupper(substr($nameParts[0], 0, 1) . (count($nameParts) > 1 ? substr(end($nameParts), 0, 1) : ''));
$errors = [];
$success = false;

$connection = connectDb();
$tableReady = ensureBookingsTable($connection);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $amenity = trim($_POST['amenity'] ?? '');
    $bookingDate = trim($_POST['booking_date'] ?? '');
    $bookingTime = trim($_POST['booking_time'] ?? '');
    $validAmenities = ['Swimming Pool', 'Function Hall'];

    if (!$tableReady) {
        $errors[] = 'Booking storage is unavailable. Please try again later.';
    } elseif (!in_array($amenity, $validAmenities, true) || $bookingDate === '' || $bookingTime === '') {
        $errors[] = 'Please choose an amenity, date, and time.';
    } elseif ($bookingDate < date('Y-m-d')) {
        $errors[] = 'Please choose a future booking date.';
    } else {
        $insert = $connection->prepare('INSERT INTO bookings (user_id, amenity, booking_date, booking_time) VALUES (?, ?, ?, ?)');
        $insert->bind_param('isss', $_SESSION['user_id'], $amenity, $bookingDate, $bookingTime);
        if ($insert->execute()) {
            $success = true;
            trackEvent('booking_created', $amenity . ' on ' . $bookingDate, (int)$_SESSION['user_id']);
        } else {
            $errors[] = 'Unable to save your booking. Please try again.';
        }
    }
}

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
    $bookingQuery = $connection->prepare('SELECT amenity, booking_date, booking_time, status FROM bookings WHERE user_id = ? ORDER BY booking_date ASC, booking_time ASC');
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
</head>
<body class="dashboard-page">

    <div class="dash-layout">

        <aside class="sidebar" id="sidebar">
            <a href="dashboard.php" class="sidebar-brand">
                <?php include '../buildingicon.php'; ?>
                <span class="brand-title">CELANDINE<br>RESIDENCES</span>
            </a>

            <nav class="sidebar-nav">
                <a href="dashboard.php" class="sidebar-link">
                    <?php echo systemSidebarIcon('dashboard'); ?> Dashboard
                </a>
                <a href="payments.php" class="sidebar-link">
                    <?php echo systemSidebarIcon('billing'); ?> Billing &amp; Payments
                </a>
                <a href="book_amenity.php" class="sidebar-link active">
                    <?php echo systemSidebarIcon('calendar'); ?> Book Amenity
                </a>
                <a href="parking.php" class="sidebar-link">
                    <?php echo systemSidebarIcon('parking'); ?> Parking
                </a>
                <a href="maintenance.php" class="sidebar-link">
                    <?php echo systemSidebarIcon('maintenance'); ?> Maintenance
                </a>
                <a href="messages.php" class="sidebar-link">
                    <?php echo systemSidebarIcon('messages'); ?> Messages
                </a>
                    <a href="announcements.php" class="sidebar-link">
                        <?php echo systemSidebarIcon('announcements'); ?> Announcements
                    </a>
            </nav>
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

            <?php if ($success): ?><div class="alert success"><strong>Booking submitted!</strong> Your reservation is pending confirmation.</div><?php endif; ?>
            <?php if (!empty($errors)): ?><div class="alert error"><ul><?php foreach ($errors as $error): ?><li><?php echo htmlspecialchars($error); ?></li><?php endforeach; ?></ul></div><?php endif; ?>

            <p class="bookings-empty" class="bookings-empty">Looking for parking? Visit the <a href="parking.php">Parking</a> page to check your assigned slot or request visitor parking.</p>

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
                            <form method="POST" action="book_amenity.php">
                                <input type="hidden" name="amenity" value="<?php echo htmlspecialchars($amenity['name']); ?>">
                                <label class="field-label" for="booking_date_<?php echo htmlspecialchars($amenity['name']); ?>">Date</label>
                                <input class="form-date" type="date" id="booking_date_<?php echo htmlspecialchars($amenity['name']); ?>" name="booking_date" min="<?php echo date('Y-m-d'); ?>" required>
                                <label class="field-label" for="booking_time_<?php echo htmlspecialchars($amenity['name']); ?>">Time</label>
                                <input class="form-date" type="time" id="booking_time_<?php echo htmlspecialchars($amenity['name']); ?>" name="booking_time" required>
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