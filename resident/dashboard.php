<?php
require_once '../config.php';

if (!isLoggedIn()) {
    redirect('../login.php');
}
if (isAdmin()) {
    redirect(isSecurity() ? '../security/security_dashboard.php' : (isMaintenance() ? '../maintenance/maintenance_dashboard.php' : '../admin/admin_dashboard.php'));
}

$username = $_SESSION['username'] ?? 'User';
$unitNumber = isset($_SESSION['unit_number']) ? 'Unit ' . htmlspecialchars($_SESSION['unit_number']) : 'Unit Not Set';
$nameParts = preg_split('/\s+/', trim($username));
$initials = strtoupper(substr($nameParts[0], 0, 1) . (count($nameParts) > 1 ? substr(end($nameParts), 0, 1) : ''));
$accountApproved = isApproved();
$connection = connectDb();
$accountRoleLabel = residentAccountLabel();
$userId = (int)$_SESSION['user_id'];
$welcomeAccountQuery = $connection->prepare('SELECT full_name, created_at FROM users WHERE id = ? LIMIT 1');
$welcomeAccountQuery->bind_param('i', $userId);
$welcomeAccountQuery->execute();
$welcomeAccount = $welcomeAccountQuery->get_result()->fetch_assoc();
$welcomeAccountQuery->close();
$displayName = trim((string)($welcomeAccount['full_name'] ?? '')) ?: $username;
$joinedAt = !empty($welcomeAccount['created_at']) ? strtotime($welcomeAccount['created_at']) : false;
$memberSince = $joinedAt !== false ? date('M Y', $joinedAt) : null;
$monthlyDue = 0.00;
$activeBookings = 0;
$activeRequests = 0;
$unreadMessages = 0;
if (ensurePaymentsTable($connection)) {
    $monthlyDue = getResidentBillingSummary($connection,(int)$_SESSION['user_id'])['amount'];
}
if (residentHasPermission('resident.amenities.book') && ensureBookingsTable($connection)) {
    $bookingResult = $connection->query("SELECT COUNT(*) AS total FROM bookings WHERE user_id = " . (int)$_SESSION['user_id'] . " AND status IN ('pending', 'approved', 'confirmed')");
    if ($bookingResult) $activeBookings = (int)$bookingResult->fetch_assoc()['total'];
}
if (residentHasPermission('resident.maintenance.request') && ensureMaintenanceTable($connection)) {
    $requestResult = $connection->query("SELECT COUNT(*) AS total FROM maintenance_requests WHERE user_id = " . (int)$_SESSION['user_id'] . " AND status IN ('pending', 'approved', 'in_progress', 'reopened')");
    if ($requestResult) $activeRequests = (int)$requestResult->fetch_assoc()['total'];
}
if (residentHasPermission('resident.messages.use') && ensureMessagesTable($connection)) {
    $messageResult = $connection->query("SELECT COUNT(*) AS total FROM messages WHERE user_id = " . (int)$_SESSION['user_id'] . " AND sender_role = 'admin' AND is_read = 0");
    if ($messageResult) $unreadMessages = (int)$messageResult->fetch_assoc()['total'];
}

// Get announcements — only for approved residents; a pending account
// shouldn't see admin announcements until its unit is assigned.
$announcements = residentHasPermission('resident.announcements.view') ? getAnnouncements(5, true) : [];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>The Celandine - My Dashboard</title>
    <link rel="stylesheet" href="<?= htmlspecialchars(portalUiAssetUrl('resident.css'), ENT_QUOTES, 'UTF-8') ?>">
<?php renderPortalUiHead(); ?>
</head>
<body class="portal-ui dashboard-page">
    <?php if (!$accountApproved): ?>
        <div id="approvalModal" class="approval-modal-overlay" role="dialog" aria-modal="true" aria-labelledby="approval-modal-title">
            <div class="approval-modal">
                <?php echo systemIconFromGlyph('⏳', 'approval-modal-icon'); ?>
                <h2 id="approval-modal-title">Your account is awaiting admin approval.</h2>
                <p>Visit the admin office with a valid ID and proof of unit ownership or authorized tenancy. Management must confirm your unit and account type before enabling resident services.</p>
                <button type="button" id="approvalModalClose">I Understand</button>
            </div>
        </div>
    <?php endif; ?>

    <div class="dash-layout">

        <aside class="sidebar" id="sidebar">
            <a href="dashboard.php" class="sidebar-brand">
                <?php include '../buildingicon.php'; ?>
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
                        <span class="dash-subtitle">THE CELANDINE</span>
                        <h1 class="dash-title">My Dashboard</h1>
                    </div>
                </div>
                <div class="dash-header-right">
                    <?php include '../notifications.php'; ?>
                    <div class="profile-menu" id="profileMenu">
                        <button class="btn-profile-nav" id="profileToggle" aria-label="Profile menu" aria-haspopup="true" aria-expanded="false">
                            <?= profileAvatar('profile-avatar') ?>
                            <span class="profile-nav-name"><?php echo htmlspecialchars($username); ?></span>
                            <span class="profile-caret">▾</span>
                        </button>
                        <div class="profile-dropdown" id="profileDropdown">
                            <div class="profile-dropdown-header">
                                <?= profileAvatar('profile-dropdown-avatar') ?>
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

            <section class="welcome-banner">
                <div class="welcome-banner-heading">
                    <div class="welcome-text">
                        <span class="welcome-eyebrow">RESIDENT DASHBOARD</span>
                        <h2>Welcome back, <?php echo htmlspecialchars($displayName, ENT_QUOTES, 'UTF-8'); ?>!</h2>
                        <p>Here's your condominium overview.</p>
                    </div>
                    <span class="badge badge-success welcome-role-badge"><?php echo systemIcon('dashboard'); ?><?php echo htmlspecialchars($accountRoleLabel, ENT_QUOTES, 'UTF-8'); ?></span>
                </div>
                <p class="welcome-details"><span><?php echo $unitNumber; ?></span> <span aria-hidden="true">&bull;</span> <span><?php echo htmlspecialchars($memberSince !== null ? 'Member since ' . $memberSince : 'Membership date unavailable', ENT_QUOTES, 'UTF-8'); ?></span></p>
            </section>

            <?php if (!$accountApproved): ?>
                <section class="notice-banner" style="background: var(--theme-hover,#1f293d); border: 1px solid var(--theme-line,#4b5563); border-left: 4px solid #f59e0b; border-radius: 10px; padding: 16px 20px; margin-bottom: 20px; display: flex; gap: 12px; align-items: flex-start;">
                    <?php echo systemIcon('hourglass', 'dashboard-reminder-icon'); ?>
                    <div>
                        <strong style="color: var(--theme-brand,#fbbf24);">Your account is awaiting admin approval.</strong>
                        <p style="color: var(--theme-copy,#9ca3af); margin: 4px 0 0; font-size: 0.9em;">Bring a valid ID and proof of unit ownership or authorized tenancy to management so they can confirm your unit and account type.</p>
                    </div>
                </section>
            <?php endif; ?>

            <section class="stats-grid">
                <?php if (residentHasPermission('resident.billing.view')): ?>
                <a href="payments.php" class="stat-card">
                    <div class="stat-head">
                        <?php echo systemIconFromGlyph('💳', 'stat-icon icon-yellow'); ?>
                        <span class="badge badge-danger">Due</span>
                    </div>
                    <div class="stat-value">₱<?php echo number_format($monthlyDue, 2); ?></div>
                    <div class="stat-label">Outstanding Unit Bills</div>
                </a>

                <?php endif; ?>
                <?php if (residentHasPermission('resident.amenities.book')): ?>
                <a href="book_amenity.php" class="stat-card">
                    <div class="stat-head">
                        <?php echo systemIconFromGlyph('📅', 'stat-icon icon-blue'); ?>
                        <span class="badge badge-success"><?php echo $activeBookings; ?> Booking<?php echo $activeBookings === 1 ? '' : 's'; ?></span>
                    </div>
                    <div class="stat-value"><?php echo $activeBookings; ?></div>
                    <div class="stat-label">Active Bookings</div>
                </a>

                <?php endif; ?>
                <?php if (residentHasPermission('resident.maintenance.request')): ?>
                <a href="maintenance.php" class="stat-card">
                    <div class="stat-head">
                        <?php echo systemIconFromGlyph('🔧', 'stat-icon icon-pink'); ?>
                    </div>
                    <div class="stat-value"><?php echo $activeRequests; ?></div>
                    <div class="stat-label">Maintenance Requests</div>
                </a>

                <?php endif; ?>
                <?php if (residentHasPermission('resident.messages.use')): ?>
                <a href="messages.php" class="stat-card">
                    <div class="stat-head">
                        <?php echo systemIconFromGlyph('✉️', 'stat-icon icon-purple'); ?>
                        <span class="badge badge-info"><?php echo $unreadMessages; ?> New</span>
                    </div>
                    <div class="stat-value"><?php echo $unreadMessages; ?></div>
                    <div class="stat-label">Messages</div>
                </a>
                <?php endif; ?>
            </section>

            <?php if (residentHasPermission('resident.billing.view') && !residentHasPermission('resident.billing.pay')): ?>
                <section class="notice-banner" style="background: var(--theme-hover,#1f293d); border: 1px solid var(--theme-line,#4b5563); border-radius: 10px; padding: 16px 20px; margin-bottom: 20px;">
                    <strong>Unit billing is read-only for tenants.</strong>
                    <p style="color: var(--theme-copy,#9ca3af); margin: 4px 0 0;">You can review bills for your approved unit. The unit owner handles shared unit payments. Tenants request and pay for their own parking through Parking &amp; Stickers.</p>
                </section>
            <?php endif; ?>

            <section class="stats-grid" aria-label="Resident services">
                <?php if (residentHasPermission('resident.visitors.register')): ?>
                <a href="visitors.php" class="stat-card"><div class="stat-head"><?php echo systemSidebarIcon('visitors'); ?></div><div class="stat-label">Visitor Registration</div><p>Register guests and view their access passes.</p></a>
                <?php endif; ?>
                <?php if (residentHasPermission('resident.permits.request')): ?>
                <a href="permits.php" class="stat-card"><div class="stat-head"><?php echo systemSidebarIcon('calendar'); ?></div><div class="stat-label">Permit Requests</div><p>Request move-in, move-out, renovation, or delivery approval.</p></a>
                <?php endif; ?>
                <?php if (residentHasPermission('resident.parking.request') || residentHasPermission('resident.stickers.order')): ?>
                <a href="parking.php" class="stat-card"><div class="stat-head"><?php echo systemSidebarIcon('parking'); ?></div><div class="stat-label"><?php echo 'Parking &amp; Vehicles'; ?></div><p><?php echo residentHasPermission('resident.stickers.order') ? 'Order stickers and request visitor parking.' : 'Request temporary parking for your registered visitors.'; ?></p></a>
                <?php endif; ?>
            </section>

            <?php if (!$accountApproved || residentHasPermission('resident.announcements.view')): ?>
            <section class="announcements-section resident-dashboard-announcements">
                <h3 class="section-title">Recent Announcements</h3>

                <?php if (!$accountApproved): ?>
                    <div style="text-align: center; color: var(--theme-copy,#9ca3af); padding: 20px;">
                        <p>Announcements will show here once your account is approved.</p>
                    </div>
                <?php elseif (empty($announcements)): ?>
                    <div style="text-align: center; color: var(--theme-copy,#9ca3af); padding: 20px;">
                        <p>No announcements at this time.</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($announcements as $announcement):
                        $priorityClass = 'notice-info';
                        if ($announcement['priority'] === 'high') $priorityClass = 'notice-danger';
                        elseif ($announcement['priority'] === 'medium') $priorityClass = 'notice-warning';

                        $priorityIcon = 'info';
                        if ($announcement['priority'] === 'high') $priorityIcon = 'violations';
                        elseif ($announcement['priority'] === 'medium') $priorityIcon = 'clock';
                    ?>
                        <div class="announcement-item <?php echo $priorityClass; ?>">
                            <div class="notice-icon"><?php echo systemIcon($priorityIcon, 'notice-icon-svg'); ?></div>
                            <div class="notice-content">
                                <h4><?php echo htmlspecialchars($announcement['title']); ?></h4>
                                <?php if (!empty($announcement['category'])): ?>
                                    <small style="color: var(--theme-copy,#9ca3af);"><?php echo htmlspecialchars($announcement['category']); ?> • </small>
                                <?php endif; ?>
                                <small style="color: var(--theme-copy,#9ca3af);"><?php echo date('M d, Y', strtotime($announcement['created_at'])); ?></small>
                                <p class="dashboard-announcement-content"><?php echo htmlspecialchars($announcement['content']); ?></p>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </section>
            <?php endif; ?>
        </main>

    </div>

    <style>
        .approval-modal-overlay {
            position: fixed;
            inset: 0;
            z-index: 9999;
            display: flex;
            align-items: center;
            justify-content: center;
            background: rgba(10, 15, 24, 0.82);
            backdrop-filter: blur(4px);
            padding: 20px;
        }

        .approval-modal {
            width: min(560px, 100%);
            background: var(--theme-surface,#121b2d);
            border: 2px solid #f59e0b;
            border-radius: 18px;
            box-shadow: 0 30px 80px rgba(0, 0, 0, 0.5);
            padding: 28px 26px 22px;
            text-align: center;
            color: var(--theme-ink,#f3f6fb);
        }

        .approval-modal-icon {
            font-size: 3rem;
            margin-bottom: 10px;
        }

        .approval-modal h2 {
            margin: 0 0 12px;
            font-size: clamp(1.5rem, 2vw, 2rem);
            color: var(--theme-brand,#fbbf24);
        }

        .approval-modal p {
            margin: 0 0 22px;
            color: var(--theme-ink,#dfe7f5);
            line-height: 1.6;
            font-size: 1rem;
        }

        .approval-modal button {
            border: none;
            background: linear-gradient(135deg, #f59e0b, #fbbf24);
            color: #111827;
            font-weight: 700;
            padding: 12px 24px;
            border-radius: 10px;
            cursor: pointer;
            font-size: 1rem;
        }
    </style>

    <script>
        const menuToggle = document.getElementById('menuToggle');
        const sidebar = document.getElementById('sidebar');
        const overlay = document.getElementById('sidebarOverlay');

        if (menuToggle && sidebar && overlay) {
            /* Navigation is handled by the shared UI module. */

            /* Navigation is handled by the shared UI module. */
        }

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

        const approvalModal = document.getElementById('approvalModal');
        const approvalModalClose = document.getElementById('approvalModalClose');

        if (approvalModal) {
            document.body.style.overflow = 'hidden';
            document.addEventListener('DOMContentLoaded', () => {
                approvalModal.style.display = 'flex';
            });

            if (approvalModalClose) {
                approvalModalClose.addEventListener('click', () => {
                    approvalModal.style.display = 'none';
                    document.body.style.overflow = '';
                });
            }
        }
    </script>
    <script src="../js/live-updates.js"></script>
</body>
</html>
