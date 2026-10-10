<?php
require_once '../config.php';

if (!isLoggedIn()) {
    redirect('../login.php');
}
if (!isSuperAdmin()) {
    redirect('../resident/dashboard.php');
}

$username = $_SESSION['username'] ?? 'Administrator';
$nameParts = preg_split('/\s+/', trim($username));
$initials = strtoupper(substr($nameParts[0], 0, 1) . (count($nameParts) > 1 ? substr(end($nameParts), 0, 1) : ''));

$analyticsData = getAnalytics();

// Export report as a printable report: /analytics.php?export=pdf
// No extra library needed — opens a print-ready page; use the browser's
// Print dialog and choose "Save as PDF" as the destination.
if (($_GET['export'] ?? '') === 'pdf') {
    $generatedAt = date('F j, Y g:i A');

    $section = function (string $title, array $rows, array $headers) {
        $html = "<h2>{$title}</h2><table><tr>";
        foreach ($headers as $h) $html .= "<th>" . htmlspecialchars($h) . "</th>";
        $html .= "</tr>";
        foreach ($rows as $row) {
            $html .= "<tr>";
            foreach ($row as $cell) $html .= "<td>" . htmlspecialchars((string)$cell) . "</td>";
            $html .= "</tr>";
        }
        $html .= "</table>";
        return $html;
    };

    $summaryRows = [
        ['Total Events', $analyticsData['total_events']],
        ['Active Residents (last 7 days)', $analyticsData['active_residents']],
        ['Total Logins', $analyticsData['total_logins']],
        ['Payment Events', $analyticsData['payment_events']],
        ['Collection Rate (last 30 days)', $analyticsData['collection_rate'] . '%'],
        ['Total Billed (last 30 days)', '₱' . number_format($analyticsData['total_billed'], 2)],
        ['Total Collected (last 30 days)', '₱' . number_format($analyticsData['total_collected'], 2)],
        ['Overdue Payments', $analyticsData['overdue_count'] . ' (₱' . number_format($analyticsData['overdue_amount'], 2) . ')'],
        ['Parking Occupancy', $analyticsData['parking_occupancy_rate'] . '% (' . $analyticsData['parking_occupied'] . '/' . $analyticsData['parking_total_slots'] . ' slots)'],
        ['Parking Pending Requests', $analyticsData['parking_pending_requests']],
        ['Total Violations', $analyticsData['violations_total']],
        ['Outstanding Fines (unpaid)', '₱' . number_format($analyticsData['outstanding_fines'], 2)],
    ];

    $eventBreakdownRows = [];
    foreach ($analyticsData['event_breakdown'] as $type => $count) $eventBreakdownRows[] = [ucwords(str_replace('_', ' ', $type)), $count];

    $paymentTrendRows = [];
    foreach ($analyticsData['payment_trend'] as $row) $paymentTrendRows[] = [$row['date'], $row['count'], '₱' . number_format($row['collected'], 2)];

    $maintenanceTrendRows = [];
    foreach ($analyticsData['maintenance_trend'] as $row) $maintenanceTrendRows[] = [$row['date'], $row['count'], $row['completed']];

    $topIssuesRows = [];
    foreach ($analyticsData['top_issues'] as $row) $topIssuesRows[] = [$row['issue'], $row['count']];

    $topAmenitiesRows = [];
    foreach ($analyticsData['top_amenities'] as $row) $topAmenitiesRows[] = [$row['amenity'], $row['count']];

    $bookingStatusRows = [];
    foreach ($analyticsData['booking_status'] as $status => $count) $bookingStatusRows[] = [ucfirst($status), $count];

    $violationsBreakdownRows = [];
    foreach ($analyticsData['violations_breakdown'] as $status => $count) $violationsBreakdownRows[] = [ucwords(str_replace('_', ' ', $status)), $count];

    $topViolationRows = [];
    foreach ($analyticsData['top_violation_types'] as $row) $topViolationRows[] = [$row['type'], $row['count']];

    $parkingRows = [
        ['Occupied', $analyticsData['parking_occupied']],
        ['Available', $analyticsData['parking_available']],
        ['Maintenance', $analyticsData['parking_maintenance']],
        ['Pending Requests', $analyticsData['parking_pending_requests']],
    ];

    $html = '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Celandine Residences - Analytics Report</title><style>
        body { font-family: Arial, Helvetica, sans-serif; color: #1f2937; font-size: 13px; max-width: 900px; margin: 30px auto; padding: 0 20px; }
        h1 { color: #d97706; margin-bottom: 0; font-size: 24px; }
        .subtitle { color: #6b7280; margin-top: 2px; margin-bottom: 10px; font-size: 13px; }
        h2 { font-size: 15px; color: #111827; border-bottom: 2px solid #d97706; padding-bottom: 4px; margin-top: 26px; margin-bottom: 10px; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 4px; }
        th { background: #1f2937; color: #fff; text-align: left; padding: 8px 10px; font-size: 12px; }
        td { padding: 6px 10px; border-bottom: 1px solid #e5e7eb; font-size: 12px; }
        tr:nth-child(even) td { background: #f9fafb; }
    </style></head><body>
        <h1>Celandine Residences</h1>
        <div class="subtitle">System Analytics Report &mdash; Generated ' . htmlspecialchars($generatedAt) . '</div>'
        . $section('Summary Metrics', $summaryRows, ['Metric', 'Value'])
        . $section('Event Breakdown', $eventBreakdownRows, ['Event Type', 'Count'])
        . $section('Payment Trend (Last 30 Days)', $paymentTrendRows, ['Date', 'Payments', 'Amount Collected'])
        . $section('Maintenance Trend (Last 30 Days)', $maintenanceTrendRows, ['Date', 'Requests', 'Completed'])
        . $section('Top Maintenance Issues', $topIssuesRows, ['Issue Type', 'Count'])
        . $section('Top Booked Amenities', $topAmenitiesRows, ['Amenity', 'Count'])
        . $section('Booking Status Breakdown', $bookingStatusRows, ['Status', 'Count'])
        . $section('Violations by Status', $violationsBreakdownRows, ['Status', 'Count'])
        . $section('Top Violation Types', $topViolationRows, ['Violation Type', 'Count'])
        . $section('Parking Slot Status', $parkingRows, ['Status', 'Count'])
        . '</body></html>';

    echo $html;
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Celandine Residences</title>
    <link rel="stylesheet" href="../styles.css">
    <style>
        .admin-page .admin-panel h3,
        .admin-page .admin-stat-card strong,
        .admin-page .admin-stat-card > span:not(.admin-stat-icon) {
            color: #ffffff;
        }
    </style>
<?php renderPortalUiHead(); ?>
</head>
<body class="portal-ui dashboard-page admin-page">
    <div class="dash-layout">
        <aside class="sidebar" id="sidebar">
            <a href="<?php echo htmlspecialchars(buildUrl(dashboardPathForRole()), ENT_QUOTES, 'UTF-8'); ?>" class="sidebar-brand">
                <?php include '../buildingicon.php'; ?>
                <span class="brand-title">CELANDINE<br>RESIDENCES</span>
            </a>
            <nav class="sidebar-nav"><?php renderStaffSidebarNavigation(); ?></nav>
        </aside>
        <div class="sidebar-overlay" id="sidebarOverlay"></div>

        <main class="dashboard-main">
            <header class="dash-header">
                <div class="dash-header-left">
                    <button class="btn-icon-menu" id="menuToggle" aria-label="Menu"><?php echo systemIcon('menu', 'menu-icon'); ?></button>
                    <div>
                        <span class="dash-subtitle">CELANDINE RESIDENCES</span>
                        <h1 class="dash-title">System Analytics</h1>
                    </div>
                </div>
                <div class="dash-header-right">

                    <?php include '../notifications.php'; ?>
                    <div class="profile-menu" id="profileMenu">
                        <button class="btn-profile-nav" id="profileToggle" type="button" aria-label="Profile menu" aria-haspopup="true" aria-expanded="false">
                            <span class="profile-avatar"><?php echo htmlspecialchars($initials); ?></span>
                            <span class="profile-nav-name"><?php echo htmlspecialchars($username); ?></span>
                            <span class="profile-caret">▾</span>
                        </button>
                        <div class="profile-dropdown" id="profileDropdown">
                            <div class="profile-dropdown-header">
                                <span class="profile-dropdown-avatar"><?php echo htmlspecialchars($initials); ?></span>
                                <div class="profile-dropdown-info"><span class="profile-dropdown-name"><?php echo htmlspecialchars($username); ?></span><span class="profile-dropdown-unit">Administrator</span></div>
                            </div>
                            <a href="../logout.php" class="profile-dropdown-item profile-dropdown-danger"><?php echo systemIcon('arrow-right', 'system-action-icon'); ?> Logout</a>
                        </div>
                    </div>
                </div>
            </header>
            <div class="portal-report-toolbar"><p>Review recorded activity and financial trends.</p><a href="analytics.php?export=pdf" class="service-btn service-btn-secondary"><?php echo systemIcon('file-text', 'system-action-icon'); ?> View full report</a></div>

            <section class="admin-stats-grid">
                <div class="admin-stat-card"><?php echo systemIconFromGlyph('📊', 'admin-stat-icon admin-icon-blue'); ?><strong><?php echo $analyticsData['total_events']; ?></strong><span>Total Events</span></div>
                <div class="admin-stat-card"><?php echo systemIconFromGlyph('👥', 'admin-stat-icon admin-icon-green'); ?><strong><?php echo $analyticsData['active_residents']; ?></strong><span>Active Residents</span><em>Last 7 days</em></div>
                <div class="admin-stat-card"><?php echo systemIconFromGlyph('🔐', 'admin-stat-icon admin-icon-yellow'); ?><strong><?php echo $analyticsData['total_logins']; ?></strong><span>Total Logins</span></div>
                <div class="admin-stat-card"><?php echo systemIconFromGlyph('💳', 'admin-stat-icon admin-icon-pink'); ?><strong><?php echo $analyticsData['payment_events']; ?></strong><span>Payment Events</span></div>
                <div class="admin-stat-card"><?php echo systemIconFromGlyph('📈', 'admin-stat-icon admin-icon-green'); ?><strong><?php echo number_format($analyticsData['collection_rate'], 1); ?>%</strong><span>Collection Rate</span><em>Last 30 days</em></div>
                <div class="admin-stat-card"><?php echo systemIconFromGlyph('⏰', 'admin-stat-icon admin-icon-yellow'); ?><strong><?php echo $analyticsData['overdue_count']; ?></strong><span>Overdue Payments</span><em>₱<?php echo number_format($analyticsData['overdue_amount'], 2); ?></em></div>
                <div class="admin-stat-card"><?php echo systemIconFromGlyph('🚗', 'admin-stat-icon admin-icon-blue'); ?><strong><?php echo number_format($analyticsData['parking_occupancy_rate'], 1); ?>%</strong><span>Parking Occupancy</span><em><?php echo $analyticsData['parking_occupied']; ?>/<?php echo $analyticsData['parking_total_slots']; ?> slots</em></div>
                <div class="admin-stat-card"><?php echo systemIconFromGlyph('⚠️', 'admin-stat-icon admin-icon-pink'); ?><strong><?php echo $analyticsData['violations_total']; ?></strong><span>Total Violations</span><em>₱<?php echo number_format($analyticsData['outstanding_fines'], 2); ?> unpaid</em></div>
            </section>

            <div class="portal-analytics-grid">
                <!-- Daily Activity -->
                <div class="admin-panel" style="padding: 20px;">
                    <h3 style="margin-bottom: 15px; font-size: 16px; font-weight: 600;">Daily Activity (Last 7 Days)</h3>
                    <div style="display: flex; align-items: flex-end; justify-content: space-around; height: 150px; gap: 8px;">
                        <?php
                        $maxCount = max(array_column($analyticsData['daily_activity'], 'count') ?: [1]);
                        foreach ($analyticsData['daily_activity'] as $activity):
                            $height = ($activity['count'] / $maxCount) * 100;
                        ?>
                            <div style="flex: 1; display: flex; flex-direction: column; align-items: center;">
                                <div style="width: 100%; background: #d97706; border-radius: 4px; height: <?php echo $height; ?>%; min-height: 10px; transition: all 0.3s ease;" title="<?php echo $activity['count']; ?> events"></div>
                                <small style="margin-top: 8px; font-size: 11px; color: #9ca3af;"><?php echo date('M d', strtotime($activity['date'])); ?></small>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Event Breakdown -->
                <div class="admin-panel" style="padding: 20px;">
                    <h3 style="margin-bottom: 15px; font-size: 16px; font-weight: 600;">Event Breakdown</h3>
                    <div style="display: flex; flex-direction: column; gap: 10px;">
                        <?php foreach ($analyticsData['event_breakdown'] as $eventType => $count): ?>
                            <div style="display: flex; justify-content: space-between; align-items: center;">
                                <span style="font-size: 13px; color: #cbd5e1; text-transform: capitalize;"><?php echo str_replace('_', ' ', $eventType); ?></span>
                                <span style="background: #374151; color: #f59e0b; padding: 4px 12px; border-radius: 20px; font-size: 12px; font-weight: 600;"><?php echo $count; ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Top Issues -->
                <?php if (!empty($analyticsData['top_issues'])): ?>
                <div class="admin-panel" style="padding: 20px;">
                    <h3 style="margin-bottom: 15px; font-size: 16px; font-weight: 600;">Top Maintenance Issues</h3>
                    <div style="display: flex; flex-direction: column; gap: 10px;">
                        <?php foreach ($analyticsData['top_issues'] as $issue): ?>
                            <div style="display: flex; justify-content: space-between; align-items: center;">
                                <span style="font-size: 13px; color: #cbd5e1;"><?php echo htmlspecialchars(substr($issue['issue'], 0, 20)); ?></span>
                                <span style="background: #ef4444; color: #fff; padding: 4px 12px; border-radius: 20px; font-size: 12px; font-weight: 600;"><?php echo $issue['count']; ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Top Amenities -->
                <?php if (!empty($analyticsData['top_amenities'])): ?>
                <div class="admin-panel" style="padding: 20px;">
                    <h3 style="margin-bottom: 15px; font-size: 16px; font-weight: 600;">Top Booked Amenities</h3>
                    <div style="display: flex; flex-direction: column; gap: 10px;">
                        <?php foreach ($analyticsData['top_amenities'] as $amenity): ?>
                            <div style="display: flex; justify-content: space-between; align-items: center;">
                                <span style="font-size: 13px; color: #cbd5e1;"><?php echo htmlspecialchars($amenity['amenity']); ?></span>
                                <span style="background: #10b981; color: #fff; padding: 4px 12px; border-radius: 20px; font-size: 12px; font-weight: 600;"><?php echo $amenity['count']; ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Booking Status Breakdown -->
                <div class="admin-panel" style="padding: 20px;">
                    <h3 style="margin-bottom: 15px; font-size: 16px; font-weight: 600;">Booking Status Breakdown</h3>
                    <div style="display: flex; flex-direction: column; gap: 10px;">
                        <?php foreach ($analyticsData['booking_status'] as $status => $count): ?>
                            <div style="display: flex; justify-content: space-between; align-items: center;">
                                <span style="font-size: 13px; color: #cbd5e1; text-transform: capitalize;"><?php echo htmlspecialchars($status); ?></span>
                                <span style="background: #374151; color: #f59e0b; padding: 4px 12px; border-radius: 20px; font-size: 12px; font-weight: 600;"><?php echo $count; ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <!-- Violations Breakdown -->
                <?php if (!empty($analyticsData['violations_breakdown'])): ?>
                <div class="admin-panel" style="padding: 20px;">
                    <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 15px;">
                        <h3 style="font-size: 16px; font-weight: 600;">Violations by Status</h3>
                        <a href="violations.php" style="font-size: 12px; font-weight: 600;">View violations →</a>
                    </div>
                    <div style="display: flex; flex-direction: column; gap: 10px;">
                        <?php foreach ($analyticsData['violations_breakdown'] as $status => $count): ?>
                            <div style="display: flex; justify-content: space-between; align-items: center;">
                                <span style="font-size: 13px; color: #cbd5e1; text-transform: capitalize;"><?php echo htmlspecialchars(str_replace('_', ' ', $status)); ?></span>
                                <span style="background: #374151; color: #f59e0b; padding: 4px 12px; border-radius: 20px; font-size: 12px; font-weight: 600;"><?php echo $count; ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Top Violation Types -->
                <?php if (!empty($analyticsData['top_violation_types'])): ?>
                <div class="admin-panel" style="padding: 20px;">
                    <h3 style="margin-bottom: 15px; font-size: 16px; font-weight: 600;">Top Violation Types</h3>
                    <div style="display: flex; flex-direction: column; gap: 10px;">
                        <?php foreach ($analyticsData['top_violation_types'] as $vt): ?>
                            <div style="display: flex; justify-content: space-between; align-items: center;">
                                <span style="font-size: 13px; color: #cbd5e1;"><?php echo htmlspecialchars($vt['type']); ?></span>
                                <span style="background: #ef4444; color: #fff; padding: 4px 12px; border-radius: 20px; font-size: 12px; font-weight: 600;"><?php echo $vt['count']; ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Parking Slot Status -->
                <?php if ($analyticsData['parking_total_slots'] > 0): ?>
                <div class="admin-panel" style="padding: 20px;">
                    <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 15px;">
                        <h3 style="font-size: 16px; font-weight: 600;">Parking Slot Status</h3>
                        <a href="parking.php" style="font-size: 12px; font-weight: 600;">View parking →</a>
                    </div>
                    <div style="display: flex; flex-direction: column; gap: 10px;">
                        <div style="display: flex; justify-content: space-between; align-items: center;">
                            <span style="font-size: 13px; color: #cbd5e1;">Occupied</span>
                            <span style="background: #ef4444; color: #fff; padding: 4px 12px; border-radius: 20px; font-size: 12px; font-weight: 600;"><?php echo $analyticsData['parking_occupied']; ?></span>
                        </div>
                        <div style="display: flex; justify-content: space-between; align-items: center;">
                            <span style="font-size: 13px; color: #cbd5e1;">Available</span>
                            <span style="background: #10b981; color: #fff; padding: 4px 12px; border-radius: 20px; font-size: 12px; font-weight: 600;"><?php echo $analyticsData['parking_available']; ?></span>
                        </div>
                        <div style="display: flex; justify-content: space-between; align-items: center;">
                            <span style="font-size: 13px; color: #cbd5e1;">Maintenance</span>
                            <span style="background: #374151; color: #f59e0b; padding: 4px 12px; border-radius: 20px; font-size: 12px; font-weight: 600;"><?php echo $analyticsData['parking_maintenance']; ?></span>
                        </div>
                        <div style="display: flex; justify-content: space-between; align-items: center;">
                            <span style="font-size: 13px; color: #cbd5e1;">Pending Requests</span>
                            <span style="background: #374151; color: #f59e0b; padding: 4px 12px; border-radius: 20px; font-size: 12px; font-weight: 600;"><?php echo $analyticsData['parking_pending_requests']; ?></span>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
            </div>

            <!-- Payment & Maintenance Trends -->
            <div class="portal-analytics-trends">
                <?php if (!empty($analyticsData['payment_trend'])): ?>
                <div class="admin-panel" style="padding: 20px;">
                    <h3 style="margin-bottom: 15px; font-size: 16px; font-weight: 600;">Payment Trend (Last 30 Days)</h3>
                    <div style="display: flex; align-items: flex-end; height: 120px; gap: 4px;">
                        <?php
                        $maxPayments = max(array_column($analyticsData['payment_trend'], 'count') ?: [1]);
                        foreach ($analyticsData['payment_trend'] as $payment):
                            $height = ($payment['count'] / $maxPayments) * 100;
                        ?>
                            <div style="flex: 1; display: flex; flex-direction: column; align-items: center;">
                                <div style="width: 100%; background: #10b981; border-radius: 3px; height: <?php echo $height; ?>%; min-height: 5px;" title="₱<?php echo number_format($payment['collected'], 2); ?>"></div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <small style="color: #9ca3af; margin-top: 10px; display: block;">Total Collected: ₱<?php echo number_format(array_sum(array_column($analyticsData['payment_trend'], 'collected')), 2); ?></small>
                </div>
                <?php endif; ?>

                <?php if (!empty($analyticsData['maintenance_trend'])): ?>
                <div class="admin-panel" style="padding: 20px;">
                    <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 15px;">
                        <h3 style="font-size: 16px; font-weight: 600;">Maintenance Trend (Last 30 Days)</h3>
                        <a href="maintenancerequests.php" style="font-size: 12px; font-weight: 600;">View requests →</a>
                    </div>
                    <div style="display: flex; align-items: flex-end; height: 120px; gap: 4px;">
                        <?php
                        $maxMaint = max(array_column($analyticsData['maintenance_trend'], 'count') ?: [1]);
                        foreach ($analyticsData['maintenance_trend'] as $maint):
                            $height = ($maint['count'] / $maxMaint) * 100;
                        ?>
                            <div style="flex: 1; display: flex; flex-direction: column; align-items: center;">
                                <div style="width: 100%; background: #f59e0b; border-radius: 3px; height: <?php echo $height; ?>%; min-height: 5px;" title="<?php echo $maint['count']; ?> requests, <?php echo $maint['completed']; ?> completed"></div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <small style="color: #9ca3af; margin-top: 10px; display: block;">Total Requests: <?php echo array_sum(array_column($analyticsData['maintenance_trend'], 'count')); ?> | Completed: <?php echo array_sum(array_column($analyticsData['maintenance_trend'], 'completed')); ?></small>
                </div>
                <?php endif; ?>
            </div>
        </main>
    </div>

    <script>
        const menuToggle = document.getElementById('menuToggle');
        const sidebar = document.getElementById('sidebar');
        const overlay = document.getElementById('sidebarOverlay');
        /* Navigation is handled by the shared UI module. */
        /* Navigation is handled by the shared UI module. */

        const profileMenu = document.getElementById('profileMenu');
        const profileToggle = document.getElementById('profileToggle');
        profileToggle.addEventListener('click', (event) => { event.stopPropagation(); const isOpen = profileMenu.classList.toggle('open'); profileToggle.setAttribute('aria-expanded', isOpen); });
        document.addEventListener('click', (event) => { if (!profileMenu.contains(event.target)) { profileMenu.classList.remove('open'); profileToggle.setAttribute('aria-expanded', 'false'); } });
    </script>
</body>
</html>
