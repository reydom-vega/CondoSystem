<?php
require_once '../config.php';
require_once __DIR__ . '/../includes/treasurer_dashboard.php';

if (!isLoggedIn()) {
    redirect('../login.php');
}
if (!isTreasurer()) {
    redirect(isSecurity() ? '../security/security_dashboard.php' : (isMaintenance() ? '../maintenance/maintenance_dashboard.php' : (isAdmin() ? '../admin/admin_dashboard.php' : '../resident/dashboard.php')));
}

$username = $_SESSION['username'] ?? 'Treasurer';
$nameParts = preg_split('/\s+/', trim($username));
$initials = strtoupper(substr($nameParts[0], 0, 1) . (count($nameParts) > 1 ? substr(end($nameParts), 0, 1) : ''));
$connection = connectDb();
$monthError = '';
try {
    $dashboard = treasurerDashboardData($connection, (int)$_SESSION['user_id'], isset($_GET['month']) && is_string($_GET['month']) ? $_GET['month'] : null);
} catch (InvalidArgumentException $error) {
    $monthError = $error->getMessage();
    $dashboard = treasurerDashboardData($connection, (int)$_SESSION['user_id']);
}
header('Cache-Control: no-store, private');
$tdEscape = static fn($value): string => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
$totals = $dashboard['totals'];
$trendMax = max(1, ...array_column($dashboard['trend'], 'cents'));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>The Celandine - Treasurer Dashboard</title>
    <link rel="stylesheet" href="<?= htmlspecialchars(portalUiAssetUrl('styles.css'), ENT_QUOTES, 'UTF-8') ?>">
<?php renderPortalUiHead(); ?>
    <link rel="stylesheet" href="<?= $tdEscape(portalUiAssetUrl('assets/css/treasurer-dashboard.css')) ?>">
</head>
<body class="portal-ui dashboard-page admin-page">
    <div class="dash-layout">
        <aside class="sidebar" id="sidebar">
            <a href="<?php echo htmlspecialchars(buildUrl(dashboardPathForRole()), ENT_QUOTES, 'UTF-8'); ?>" class="sidebar-brand">
                <?php include '../buildingicon.php'; ?>
            </a>
            <nav class="sidebar-nav"><?php renderStaffSidebarNavigation(); ?></nav>
        </aside>
        <div class="sidebar-overlay" id="sidebarOverlay"></div>
        <main class="dashboard-main">
            <header class="dash-header">
                <div class="dash-header-left">
                    <button class="btn-icon-menu" id="menuToggle" aria-label="Menu"><?php echo systemIcon('menu', 'menu-icon'); ?></button>
                    <div>
                        <span class="dash-subtitle">THE CELANDINE</span>
                        <h1 class="dash-title">Treasurer Dashboard</h1>
                    </div>
                </div>
                <div class="dash-header-right">
                    <?php include '../notifications.php'; ?>
                    <div class="profile-menu" id="profileMenu">
                        <button class="btn-profile-nav" id="profileToggle" type="button" aria-label="Profile menu" aria-haspopup="true" aria-expanded="false">
                            <?= profileAvatar('profile-avatar') ?>
                            <span class="profile-nav-name"><?php echo htmlspecialchars($username); ?></span>
                            <span class="profile-caret">▾</span>
                        </button>
                        <div class="profile-dropdown" id="profileDropdown">
                            <div class="profile-dropdown-header">
                                <?= profileAvatar('profile-dropdown-avatar') ?>
                                <div class="profile-dropdown-info"><span class="profile-dropdown-name"><?php echo htmlspecialchars($username); ?></span><span class="profile-dropdown-unit">Treasurer</span></div>
                            </div>
                            <a href="../logout.php" class="profile-dropdown-item profile-dropdown-danger"><?php echo systemIcon('arrow-right', 'system-action-icon'); ?> Logout</a>
                        </div>
                    </div>
                </div>
            </header>

            <div class="treasurer-home" data-treasurer-dashboard>
                <section class="treasurer-toolbar" aria-labelledby="treasurerOverviewTitle">
                    <div><h2 id="treasurerOverviewTitle">Financial overview</h2><p>Collections for <?= $tdEscape($dashboard['month_label']) ?>. Open balances reflect today.</p></div>
                    <form method="get" class="treasurer-period"><label for="treasurerMonth">Collection month<input id="treasurerMonth" type="month" name="month" value="<?= $tdEscape($dashboard['month']) ?>" required></label><button type="submit" class="service-btn">Apply</button><a class="service-btn service-btn-secondary" href="?<?= $tdEscape(http_build_query(['month'=>$dashboard['month']])) ?>">Refresh</a></form>
                </section>
                <?php if ($monthError): ?><p class="alert error" role="alert"><?= $tdEscape($monthError) ?></p><?php endif; ?>
                <nav class="treasurer-actions" aria-label="Treasurer quick actions">
                    <a href="../superadmin/unitpayments.php?tab=billing"><?= systemIcon('billing') ?> Open billing</a>
                    <a href="../superadmin/generate_bills.php"><?= systemIcon('bills') ?> Generate bills</a>
                    <a href="../superadmin/unitpayments.php?tab=history"><?= systemIcon('clock') ?> Payment history</a>
                </nav>
                <section class="admin-stats-grid treasurer-stats" aria-label="Financial totals">
                    <?php foreach ([
                        ['check','green','Monthly collections','collected_cents','collected_count','completed payment','completed payments'],
                        ['billing','blue','Outstanding balance','outstanding_cents','outstanding_count','open invoice','open invoices'],
                        ['violations','pink','Past due','past_due_cents','past_due_count','invoice past its due date','invoices past their due date'],
                        ['calendar','yellow','Due within 7 days','due_soon_cents','due_soon_count','invoice including today','invoices including today'],
                    ] as [$icon,$color,$label,$amountKey,$countKey,$singular,$plural]): ?>
                    <article class="admin-stat-card"><div class="treasurer-stat-heading"><?= systemIcon($icon, 'admin-stat-icon admin-icon-'.$color) ?><h3><?= $tdEscape($label) ?></h3></div><strong data-treasurer-metric="<?= $tdEscape($amountKey) ?>"><?= $tdEscape(treasurerDashboardMoney($totals[$amountKey])) ?></strong><small><?= $totals[$countKey] ?> <?= $tdEscape($totals[$countKey]===1 ? $singular : $plural) ?></small></article>
                    <?php endforeach; ?>
                </section>
                <div class="treasurer-columns">
                    <section class="treasurer-panel" aria-labelledby="treasurerTrendTitle">
                        <div class="treasurer-panel-heading"><div><h2 id="treasurerTrendTitle">Collection trend</h2><p>Six months ending <?= $tdEscape($dashboard['month_label']) ?>, by recorded payment date.</p></div><?= systemIcon('analytics') ?></div>
                        <ol class="treasurer-trend" aria-label="Monthly completed collections">
                            <?php foreach ($dashboard['trend'] as $point): ?><li><span><?= $tdEscape($point['month']) ?></span><div class="treasurer-trend-track" aria-hidden="true"><i style="width:<?= round(max(0,$point['cents']) / $trendMax * 100, 2) ?>%"></i></div><strong><?= $tdEscape(treasurerDashboardMoney($point['cents'])) ?></strong></li><?php endforeach; ?>
                        </ol>
                        <?php if ($totals['undated_paid_count']): ?><p class="treasurer-note"><?= $totals['undated_paid_count'] ?> paid record(s) have no payment date and are excluded from dated collections.</p><?php endif; ?>
                    </section>
                    <section class="treasurer-panel" aria-labelledby="treasurerAgingTitle">
                        <div class="treasurer-panel-heading"><div><h2 id="treasurerAgingTitle">Outstanding balance aging</h2><p>Open invoices as of <?= $tdEscape(date('M j, Y', strtotime($dashboard['today']))) ?>.</p></div></div>
                        <ul class="treasurer-aging">
                            <?php foreach ($dashboard['aging'] as $index=>$bucket): ?><li><div><span class="treasurer-aging-dot treasurer-aging-<?= $index ?>" aria-hidden="true"></span><div><strong><?= $tdEscape($bucket['label']) ?></strong><small><?= $bucket['count'] ?> invoice(s)</small></div></div><span><?= $tdEscape(treasurerDashboardMoney($bucket['cents'])) ?></span></li><?php endforeach; ?>
                        </ul>
                        <a class="treasurer-text-link" href="../superadmin/unitpayments.php?tab=billing">Review outstanding bills <?= systemIcon('arrow-right') ?></a>
                    </section>
                    <section class="treasurer-panel" aria-labelledby="treasurerRecentTitle">
                        <div class="treasurer-panel-heading"><div><h2 id="treasurerRecentTitle">Recent payments</h2><p>Latest completed payments across all billing periods.</p></div><a href="../superadmin/unitpayments.php?tab=history">View history</a></div>
                        <?php if (!$dashboard['recent']): ?><p class="treasurer-empty">No completed payments yet. Payments will appear here once confirmed.</p><?php else: ?>
                        <div class="treasurer-table-scroll" role="region" aria-label="Recent payments; scroll horizontally if needed" tabindex="0"><table class="treasurer-table" data-workspace-ready="true"><thead><tr><th scope="col">Payer / unit</th><th scope="col">Amount</th><th scope="col">Payment details</th></tr></thead><tbody>
                            <?php foreach ($dashboard['recent'] as $payment): ?><tr><td><strong><?= $tdEscape($payment['full_name'] ?: $payment['username']) ?></strong><small><?= $tdEscape($payment['unit_number'] ?: 'No unit assigned') ?></small></td><td><strong><?= $tdEscape(treasurerDashboardMoney((int)$payment['cents'])) ?></strong><small><?= $tdEscape(paymentChannelLabel($payment['payment_channel'], $payment['payment_method'])) ?></small></td><td><a href="../superadmin/unitpayments.php?<?= $tdEscape(http_build_query(['tab'=>'history','search'=>'DUES-'.$payment['id']])) ?>">DUES-<?= (int)$payment['id'] ?></a><small><?= $payment['paid_at'] ? $tdEscape(date('M j, Y g:i A', strtotime($payment['paid_at']))) : 'Payment date not recorded' ?></small></td></tr><?php endforeach; ?>
                        </tbody></table></div><?php endif; ?>
                    </section>
                    <section class="treasurer-panel" aria-labelledby="treasurerFollowUpTitle">
                        <div class="treasurer-panel-heading"><div><h2 id="treasurerFollowUpTitle">Payment follow-ups</h2><p>Past-due and upcoming invoices, oldest due date first.</p></div></div>
                        <?php if (!$dashboard['follow_ups']): ?><p class="treasurer-empty">No past-due invoices or bills due within the next 7 days.</p><?php else: ?>
                        <ul class="treasurer-follow-ups">
                            <?php foreach ($dashboard['follow_ups'] as $bill): $days=(int)(new DateTimeImmutable($dashboard['today']))->diff(new DateTimeImmutable($bill['due_date']))->format('%r%a'); ?><li><div><strong><?= $tdEscape($bill['full_name'] ?: $bill['username']) ?></strong><small><?= $tdEscape($bill['unit_number'] ?: 'No unit assigned') ?> · Due <?= $tdEscape(date('M j, Y', strtotime($bill['due_date']))) ?></small><span class="treasurer-due-state<?= $days<0 ? ' past-due' : '' ?>"><?= $days<0 ? abs($days).' days past due' : ($days===0 ? 'Due today' : 'Due in '.$days.' days') ?></span></div><div><strong><?= $tdEscape(treasurerDashboardMoney((int)$bill['cents'])) ?></strong><a href="../superadmin/unitpayments.php?<?= $tdEscape(http_build_query(['tab'=>'billing','search'=>'DUES-'.$bill['id']])) ?>">Review DUES-<?= (int)$bill['id'] ?></a></div></li><?php endforeach; ?>
                        </ul><?php endif; ?>
                    </section>
                </div>
                <footer class="treasurer-summary"><span><?= $totals['record_count'] ?> payment records · <?= $tdEscape(treasurerDashboardMoney($totals['paid_cents'])) ?> collected overall</span><span>Updated <?= $tdEscape($dashboard['updated_at']) ?></span></footer>
            </div>

        </main>
    </div>
</body>
</html>
