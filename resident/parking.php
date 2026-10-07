<?php
require_once '../config.php';

if (!isLoggedIn()) {
    redirect('../login.php');
}
if (isAdmin()) {
    redirect(isSecurity() ? '../security/security_dashboard.php' : (isMaintenance() ? '../maintenance/maintenance_dashboard.php' : '../admin/admin_dashboard.php'));
}
requireApproval();

$userId = (int)$_SESSION['user_id'];
$username = $_SESSION['username'] ?? 'User';
$unitNumber = isset($_SESSION['unit_number']) ? 'Unit ' . htmlspecialchars($_SESSION['unit_number']) : 'Unit Not Set';
$nameParts = preg_split('/\s+/', trim($username));
$initials = strtoupper(substr($nameParts[0], 0, 1) . (count($nameParts) > 1 ? substr(end($nameParts), 0, 1) : ''));

$connection = connectDb();
ensureParkingTables($connection);

$errors = [];
$success = '';
$stickerError = '';
$stickerSuccess = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $formAction = $_POST['form_action'] ?? 'parking_request';

    if ($formAction === 'buy_sticker') {
        $stickerQuantity = filter_var($_POST['sticker_quantity'] ?? null, FILTER_VALIDATE_INT);
        if ($stickerQuantity === false || $stickerQuantity < 1 || $stickerQuantity > 10) {
            $stickerError = 'Choose a sticker quantity from 1 to 10.';
        }

        $existingStickerOrder = getLatestParkingStickerOrder($userId);
        if ($stickerError === '' && $existingStickerOrder && $existingStickerOrder['claim_status'] !== 'issued') {
            $stickerError = 'You already have a sticker order in progress. Check its status below.';
        } elseif ($stickerError === '') {
            $billId = createBill(
                $userId,
                [['category' => 'Parking Sticker', 'description' => $stickerQuantity . ' resident parking sticker(s)', 'amount' => PARKING_STICKER_PRICE * $stickerQuantity]],
                null,
                null,
                (new DateTime())->modify('+15 days')->format('Y-m-d'),
                'unbilled'
            );

            if (!$billId) {
                $stickerError = 'Unable to create the sticker bill. Please try again.';
            } else {
                $orderId = createParkingStickerOrderForBill($userId, $billId, $stickerQuantity);
                if ($orderId) {
                    logAudit('create', 'parking_sticker', $orderId, 'Created bill #' . $billId . ' for ' . $stickerQuantity . ' sticker(s)');
                    redirect('payments.php');
                }

                $stickerError = 'The sticker bill was created, but its claim record could not be created. Please contact management before paying.';
            }
        }
    } else {
        $requestType = $_POST['request_type'] ?? 'visitor';
        $vehiclePlate = strtoupper(trim($_POST['vehicle_plate'] ?? ''));
        $vehicleDescription = trim($_POST['vehicle_description'] ?? '');
        $startDate = trim($_POST['start_date'] ?? '');
        $endDate = trim($_POST['end_date'] ?? '');

        if ($requestType !== 'visitor') {
            $errors[] = 'Resident parking stickers are purchased separately below. Parking requests are for visitors only.';
        } elseif ($vehiclePlate === '' || $startDate === '') {
            $errors[] = 'Plate number and start date are required.';
        } elseif ($startDate < date('Y-m-d')) {
            $errors[] = 'Start date cannot be in the past.';
        } elseif ($endDate !== '' && $endDate < $startDate) {
            $errors[] = 'End date cannot be before the start date.';
        } elseif ($endDate !== '' && (strtotime($endDate) - strtotime($startDate)) > (7 * 86400)) {
            $errors[] = 'Visitor parking requests can cover at most 7 days.';
        } else {
            $finalEndDate = $endDate ?: $startDate;
            if (createParkingRequest($userId, 'visitor', $vehiclePlate, $vehicleDescription, $startDate, $finalEndDate)) {
                $success = 'Your visitor parking request has been submitted for admin review.';
            } else {
                $errors[] = 'Unable to submit your request. Please try again.';
            }
        }
    }
}

$latestStickerOrder = getLatestParkingStickerOrder($userId);

$myRequests = getParkingRequestsForUser($userId);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Celandine Residences - Parking</title>
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
                <a href="book_amenity.php" class="sidebar-link">
                    <?php echo systemSidebarIcon('calendar'); ?> Book Amenity
                </a>
                <a href="parking.php" class="sidebar-link active">
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
                        <h1 class="dash-title">Parking</h1>
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

            <?php if ($success): ?><div class="alert success"><strong>Submitted!</strong> <?php echo htmlspecialchars($success); ?></div><?php endif; ?>
            <?php if (!empty($errors)): ?><div class="alert error"><ul><?php foreach ($errors as $error): ?><li><?php echo htmlspecialchars($error); ?></li><?php endforeach; ?></ul></div><?php endif; ?>
            <?php if ($stickerError !== ''): ?><div class="alert error"><?php echo htmlspecialchars($stickerError); ?></div><?php endif; ?>
            <?php if ($stickerSuccess !== ''): ?><div class="alert success"><?php echo htmlspecialchars($stickerSuccess); ?></div><?php endif; ?>

            <section class="payment-methods-card parking-request-card">
                <h3 class="section-title">Resident Parking Sticker</h3>
                <p class="amenity-desc">Purchase a parking sticker for your resident vehicle. Price: <strong>₱<?php echo number_format(PARKING_STICKER_PRICE, 2); ?></strong>.</p>
                <p class="amenity-desc">Quantity: <?php echo (int)($latestStickerOrder['quantity'] ?? 0); ?> · Order total: ₱<?php echo number_format((float)($latestStickerOrder['amount'] ?? 0), 2); ?></p>
                <?php if ($latestStickerOrder && $latestStickerOrder['claim_status'] === 'issued'): ?>
                    <p class="amenity-desc">Your parking sticker has been issued<?php if (!empty($latestStickerOrder['issued_at'])): ?> on <?php echo htmlspecialchars(date('M j, Y', strtotime($latestStickerOrder['issued_at']))); ?><?php endif; ?>.</p>
                    <form method="POST" action="parking.php" id="stickerOrderForm">
                        <input type="hidden" name="form_action" value="buy_sticker">
                        <div class="parking-field">
                            <label class="field-label" for="sticker_quantity">Order additional stickers</label>
                            <input class="form-date" type="number" id="sticker_quantity" name="sticker_quantity" min="1" max="10" step="1" value="1" required>
                        </div>
                        <p class="amenity-desc">Total: <strong>₱<span id="stickerOrderTotal" data-unit-price="<?php echo number_format(PARKING_STICKER_PRICE, 2, '.', ''); ?>"><?php echo number_format(PARKING_STICKER_PRICE, 2); ?></span></strong></p>
                        <button type="submit" class="proceed-payment-btn">Add Stickers to Billing</button>
                    </form>
                <?php elseif ($latestStickerOrder && $latestStickerOrder['bill_status'] === 'paid'): ?>
                    <p class="amenity-desc">Payment verified. Please visit the Admin to claim your physical parking sticker. Provide a Reciept</p>
                    <?php if (!empty($latestStickerOrder['bill_paid_at'])): ?><p class="amenity-desc">Paid on <?php echo htmlspecialchars(date('M j, Y g:i A', strtotime($latestStickerOrder['bill_paid_at']))); ?>.</p><?php endif; ?>
                <?php elseif ($latestStickerOrder && $latestStickerOrder['bill_status'] !== 'paid'): ?>
                    <p class="amenity-desc">Your sticker request is waiting for payment confirmation. Complete payment from Billing &amp; Payments. Online payments are verified automatically by PayMongo.</p>
                    <a class="proceed-payment-btn" href="payments.php">Go to Billing &amp; Payments</a>
                <?php elseif ($latestStickerOrder): ?>
                    <p class="amenity-desc">This sticker order has already been processed. Contact management if you need help.</p>
                <?php else: ?>
                    <form method="POST" action="parking.php" id="stickerOrderForm">
                        <input type="hidden" name="form_action" value="buy_sticker">
                        <div class="parking-field">
                            <label class="field-label" for="sticker_quantity">Sticker quantity</label>
                            <input class="form-date" type="number" id="sticker_quantity" name="sticker_quantity" min="1" max="10" step="1" value="1" required>
                        </div>
                        <p class="amenity-desc">Total: <strong>₱<span id="stickerOrderTotal" data-unit-price="<?php echo number_format(PARKING_STICKER_PRICE, 2, '.', ''); ?>"><?php echo number_format(PARKING_STICKER_PRICE, 2); ?></span></strong></p>
                        <button type="submit" class="proceed-payment-btn">Add Stickers to Billing</button>
                    </form>
                    <a class="proceed-payment-btn" href="payments.php">Back to Billing &amp; Payments</a>
                <?php endif; ?>
            </section>

            <section class="payment-methods-card parking-request-card">
                <h3 class="section-title">Visitor Parking</h3>
                <p class="amenity-desc">Submit visitor vehicle details to request a temporary QR pass. Visitor parking is limited to 7 days.</p>
                <form method="POST" action="parking.php">
                    <input type="hidden" name="form_action" value="visitor_request">

                    <div class="parking-field"><label class="field-label" for="vehicle_plate">Plate Number</label>
                    <input class="form-date" type="text" id="vehicle_plate" name="vehicle_plate" placeholder="e.g. ABC 1234" maxlength="20" required>
                    </div>

                    <div class="parking-field"><label class="field-label" for="vehicle_description">Vehicle (optional)</label>
                    <input class="form-date" type="text" id="vehicle_description" name="vehicle_description" placeholder="e.g. Silver Toyota Vios" maxlength="120">
                    </div>

                    <div class="parking-field"><label class="field-label" for="start_date">Start Date</label>
                    <input class="form-date" type="date" id="start_date" name="start_date" min="<?php echo date('Y-m-d'); ?>" required>
                    </div>

                    <div class="parking-field"><label class="field-label" for="end_date">End Date (visitor only)</label>
                    <input class="form-date" type="date" id="end_date" name="end_date" min="<?php echo date('Y-m-d'); ?>">
                    </div>

                    <button type="submit" class="proceed-payment-btn">Submit Request</button>
                </form>
            </section>

            <section class="bookings-section">
                <h3 class="section-title">Your Parking Requests</h3>
                <?php if (empty($myRequests)): ?>
                    <p class="bookings-empty">You have no parking requests yet.</p>
                <?php else: ?>
                    <?php foreach ($myRequests as $request): ?>
                        <div class="booking-item">
                            <?php echo systemIconFromGlyph('🚗', 'booking-icon'); ?>
                            <div class="booking-content">
                                <h4><?php echo $request['request_type'] === 'resident_assignment' ? 'Resident Slot Request' : 'Visitor Parking'; ?> — <?php echo htmlspecialchars($request['vehicle_plate']); ?></h4>
                                <p>
                                    <?php echo htmlspecialchars(date('M j, Y', strtotime($request['start_date']))); ?>
                                    <?php if ($request['end_date']): ?> to <?php echo htmlspecialchars(date('M j, Y', strtotime($request['end_date']))); ?><?php endif; ?>
                                    <?php if ($request['status'] === 'approved' && !empty($request['slot_code'])): ?> · Slot <?php echo htmlspecialchars($request['slot_code']); ?><?php endif; ?>
                                </p>
                            </div>
                            <span class="badge badge-success unit-status <?php echo htmlspecialchars($request['status']); ?>"><?php echo htmlspecialchars(ucfirst($request['status'])); ?></span>
                            <?php if ($request['request_type'] === 'visitor' && $request['status'] === 'approved'): ?>
                                <a href="../parking_pass.php?request_id=<?php echo (int)$request['id']; ?>&amp;signature=<?php echo htmlspecialchars(parkingPassSignature((int)$request['id'])); ?>" class="admin-pill admin-pill-info">Download QR Pass</a>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </section>
        </main>

    </div>
    <script>
        const menuToggle = document.getElementById('menuToggle');
        const sidebar = document.getElementById('sidebar');
        const overlay = document.getElementById('sidebarOverlay');
        if (menuToggle) {
            menuToggle.addEventListener('click', () => { sidebar.classList.toggle('open'); overlay.classList.toggle('open'); });
        }
        if (overlay) {
            overlay.addEventListener('click', () => { sidebar.classList.remove('open'); overlay.classList.remove('open'); });
        }
        const profileMenu = document.getElementById('profileMenu');
        const profileToggle = document.getElementById('profileToggle');
        if (profileToggle && profileMenu) {
            profileToggle.addEventListener('click', (e) => { e.stopPropagation(); const isOpen = profileMenu.classList.toggle('open'); profileToggle.setAttribute('aria-expanded', isOpen); });
            document.addEventListener('click', (e) => { if (!profileMenu.contains(e.target)) { profileMenu.classList.remove('open'); profileToggle.setAttribute('aria-expanded', 'false'); } });
        }

        // Visitor parking is capped at 7 days; nudge the end date field accordingly.
        const requestType = document.getElementById('request_type');
        const endDateField = document.getElementById('end_date');
        const startDateField = document.getElementById('start_date');
        function syncEndDateMax() {
            if (!startDateField.value) return;
            const max = new Date(startDateField.value);
            max.setDate(max.getDate() + 7);
            endDateField.max = max.toISOString().slice(0, 10);
        }
        if (startDateField && endDateField) {
            startDateField.addEventListener('change', syncEndDateMax);
        }
        const stickerQuantity = document.getElementById('sticker_quantity');
        const stickerOrderTotal = document.getElementById('stickerOrderTotal');
        if (stickerQuantity && stickerOrderTotal) {
            const stickerUnitPrice = Number(stickerOrderTotal.dataset.unitPrice);
            stickerQuantity.addEventListener('input', () => {
                const quantity = Math.min(10, Math.max(1, Number.parseInt(stickerQuantity.value, 10) || 1));
                stickerOrderTotal.textContent = (stickerUnitPrice * quantity).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
            });
        }
    </script>
</body>
</html>