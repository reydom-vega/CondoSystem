<?php
/** All values and destinations are escaped; private report narratives never enter this view. */
function renderManagementDashboardContent(array $data): void {
    $e = static fn($value): string => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    $url = static fn(string $path): string => htmlspecialchars(buildUrl($path), ENT_QUOTES, 'UTF-8');
    $date = static fn(string $value, bool $utc = false): string => (new DateTimeImmutable($value, new DateTimeZone($utc ? 'UTC' : 'Asia/Manila')))->setTimezone(new DateTimeZone('Asia/Manila'))->format('M j, g:i A');
    $activeQueues = array_values(array_filter($data['queues'], static fn($queue) => $queue['count'] > 0));
    $clearQueues = array_values(array_filter($data['queues'], static fn($queue) => $queue['count'] === 0));
    $queueRow = static function(array $queue) use ($e, $url, $date): void { ?>
        <a class="management-queue" data-management-queue="<?= $e($queue['key']) ?>" href="<?= $url($queue['path']) ?>">
            <span class="management-row-icon" aria-hidden="true"><?= systemSidebarIcon($queue['icon']) ?></span>
            <span class="management-row-text"><strong><?= $e($queue['title']) ?></strong><small><?= $queue['count'] ? 'Oldest: '.$e($date($queue['oldest'], in_array($queue['key'], ['staff_reports','extensions'], true))) : 'No pending items' ?></small></span>
            <span class="management-count<?= $queue['count'] ? ' management-count-pending' : '' ?>"><?= number_format($queue['count']) ?></span>
            <span class="management-row-arrow" aria-hidden="true">&rarr;</span>
        </a>
    <?php };
    $metrics = [
        ['reviews','Pending reviews',$data['pending_total'],'Review items across your authorized modules','clipboard-list','admin-icon-yellow','#managementReviewQueue'],
        ['maintenance','Active maintenance',$data['maintenance']['active'],$data['maintenance']['urgent'].' urgent request'.($data['maintenance']['urgent'] === 1 ? '' : 's'),'maintenance','admin-icon-pink','superadmin/maintenancerequests.php'],
        ['messages','Unread conversations',$data['messages']['conversations'],$data['messages']['messages'].' unread resident messages','messages','admin-icon-green','superadmin/admin_messages.php'],
        ['occupied','Units with residents',$data['units']['occupied'],'Of '.number_format($data['units']['total']).' inventory units','units','admin-icon-blue','superadmin/units.php'],
    ]; ?>
    <section class="admin-stats-grid management-metrics" aria-label="Management overview">
        <?php foreach ($metrics as [$key,$label,$value,$description,$icon,$tone,$path]): ?>
        <a class="admin-stat-card management-metric" data-management-metric="<?= $e($key) ?>" href="<?= str_starts_with($path,'#') ? $e($path) : $url($path) ?>">
            <span class="management-metric-heading"><span class="admin-stat-icon <?= $e($tone) ?>" aria-hidden="true"><?= systemSidebarIcon($icon) ?></span><span><?= $e($label) ?></span></span>
            <strong><?= number_format($value) ?></strong><small><?= $e($description) ?></small>
        </a>
        <?php endforeach; ?>
    </section>

    <?php if (isset($data['finance'])): $finance = $data['finance']; ?>
    <section class="management-panel management-finance" data-management-finance aria-labelledby="managementFinanceTitle">
        <div class="management-panel-heading"><div><h2 id="managementFinanceTitle">Finance overview</h2><p><?= $e($finance['month']) ?> collections and current unpaid bills</p></div><a href="<?= $url('superadmin/unitpayments.php') ?>">Open billing &rarr;</a></div>
        <dl class="management-finance-grid">
            <div><dt>Monthly collections</dt><dd data-management-finance-value="collected"><?= $e(managementDashboardMoney($finance['collected_cents'])) ?></dd><small>Confirmed payments by payment date</small></div>
            <div><dt>Outstanding balance</dt><dd data-management-finance-value="outstanding"><?= $e(managementDashboardMoney($finance['outstanding_cents'])) ?></dd><small>Pending and overdue bills</small></div>
            <div><dt>Past due</dt><dd data-management-finance-value="past_due"><?= $e(managementDashboardMoney($finance['past_due_cents'])) ?></dd><small><?= number_format($finance['past_due_count']) ?> unpaid <?= $finance['past_due_count'] === 1 ? 'bill past its' : 'bills past their' ?> due date</small></div>
        </dl>
        <?php if ($finance['undated_paid_count']): ?><p class="management-note"><?= number_format($finance['undated_paid_count']) ?> paid records have no payment date and are excluded from monthly collections.</p><?php endif; ?>
    </section>
    <?php endif; ?>
    <?php if (!empty($data['failed_notifications'])): ?>
    <p class="management-delivery-notice"><?= systemSidebarIcon('messages') ?><span class="management-delivery-text"><?= number_format($data['failed_notifications']) ?> <?= $data['failed_notifications'] === 1 ? 'notification needs' : 'notifications need' ?> a delivery check.</span><a href="<?= $url('superadmin/notification_delivery.php') ?>">Review delivery &rarr;</a></p>
    <?php endif; ?>

    <div class="management-columns">
        <div class="management-stack">
        <section class="management-panel" id="managementReviewQueue" aria-labelledby="managementQueueTitle">
            <div class="management-panel-heading"><div><h2 id="managementQueueTitle">Needs your review</h2><p>Open a module to verify details and take action.</p></div><span class="management-count management-count-pending"><?= number_format($data['pending_total']) ?></span></div>
            <p class="management-note">Counts are review tasks. A visitor registration and its parking request may appear separately.</p>
            <?php if (!$activeQueues): ?><p class="management-empty">No requests are currently waiting for your review.</p><?php else: ?><div class="management-queue-list"><?php foreach ($activeQueues as $queue) $queueRow($queue); ?></div><?php endif; ?>
            <?php if ($clearQueues): ?><details class="management-clear-queues"><summary>Other review queues (<?= count($clearQueues) ?> clear)</summary><div class="management-queue-list"><?php foreach ($clearQueues as $queue) $queueRow($queue); ?></div></details><?php endif; ?>
        </section>
        <section class="management-panel" aria-labelledby="managementMaintenanceTitle">
            <div class="management-panel-heading"><div><h2 id="managementMaintenanceTitle">Maintenance follow-up</h2><p>Urgent requests first, followed by the oldest active work</p></div><a href="<?= $url('superadmin/maintenancerequests.php') ?>">View all &rarr;</a></div>
            <?php if (!$data['maintenance']['rows']): ?><p class="management-empty">No active maintenance requests.</p><?php else: ?><ul class="management-list">
            <?php foreach ($data['maintenance']['rows'] as $request): ?><li><div class="management-list-heading"><a href="<?= $url('superadmin/maintenancerequests.php?search='.rawurlencode('MR-'.str_pad((string)$request['id'],5,'0',STR_PAD_LEFT))) ?>"><?= $e($request['issue_type']) ?></a><span class="management-tag<?= $request['urgency'] === 'urgent' ? ' management-tag-urgent' : '' ?>"><?= $e(ucfirst(str_replace('_',' ',$request['status']))) ?></span></div><p><?= $e($request['full_name']) ?> &middot; Unit <?= $e($request['unit_number'] ?: 'Unassigned') ?></p><small><?= $request['urgency'] === 'urgent' ? 'Urgent &middot; ' : '' ?>MR-<?= str_pad((string)$request['id'],5,'0',STR_PAD_LEFT) ?> &middot; <?= $e($date($request['created_at'])) ?></small></li><?php endforeach; ?>
            </ul><?php endif; ?>
        </section>
        <section class="management-panel" aria-labelledby="managementMessagesTitle">
            <div class="management-panel-heading"><div><h2 id="managementMessagesTitle">Resident conversations</h2><p>Unread conversations, oldest message first</p></div><a href="<?= $url('superadmin/admin_messages.php') ?>">Open messages &rarr;</a></div>
            <?php if (!$data['messages']['rows']): ?><p class="management-empty">You are caught up. No unread resident messages.</p><?php else: ?><ul class="management-list">
            <?php foreach ($data['messages']['rows'] as $conversation): ?><li><div class="management-list-heading"><a href="<?= $url('superadmin/admin_messages.php?user_id='.$conversation['id']) ?>"><?= $e($conversation['full_name']) ?></a><span class="management-count"><?= (int)$conversation['unread'] ?> unread</span></div><small>Unit <?= $e($conversation['unit_number'] ?: 'Unassigned') ?> &middot; <?= $e($date($conversation['oldest'])) ?></small></li><?php endforeach; ?>
            </ul><?php endif; ?>
        </section>
        </div>
        <div class="management-stack">
        <section class="management-panel" aria-labelledby="managementPropertyTitle">
            <div class="management-panel-heading"><div><h2 id="managementPropertyTitle">Property at a glance</h2><p>Current resident registrations and gate activity</p></div></div>
            <div class="management-occupancy-heading"><strong><?= $data['units']['percent'] !== null ? $e(number_format($data['units']['percent'],1)).'%' : 'Unavailable' ?></strong><span>of inventory units have approved residents</span></div>
            <?php if ($data['units']['percent'] !== null): ?><div class="management-occupancy-track" role="progressbar" aria-label="Inventory units linked to approved residents" aria-valuenow="<?= $e($data['units']['percent']) ?>" aria-valuemin="0" aria-valuemax="100"><span style="width:<?= $e($data['units']['percent']) ?>%"></span></div><?php endif; ?>
            <dl class="management-property-grid">
                <div><dt>Total units</dt><dd><?= number_format($data['units']['total']) ?></dd></div>
                <div><dt>Without approved residents</dt><dd><?= number_format($data['units']['unassigned']) ?></dd></div>
                <div><dt>Approved resident accounts</dt><dd><?= number_format($data['residents']['approved']) ?></dd></div>
                <div><dt>Visitors currently inside</dt><dd><?= number_format($data['visitors']['inside']) ?></dd></div>
            </dl>
            <p class="management-note">Units are counted once using active, verified, approved resident accounts. Registration does not confirm physical occupancy.</p>
            <div class="management-property-links"><a href="<?= $url('superadmin/visitorlog.php') ?>">Visitor log &rarr;</a><a href="<?= $url('superadmin/announcements.php') ?>"><?= $data['announcements'] ?> active <?= $data['announcements'] === 1 ? 'announcement' : 'announcements' ?> &rarr;</a></div>
        </section>
        <section class="management-panel" aria-labelledby="managementScheduleTitle">
            <div class="management-panel-heading"><div><h2 id="managementScheduleTitle">Upcoming amenity bookings</h2><p>Approved / confirmed reservations: today and the next 6 days</p></div><a href="<?= $url('superadmin/bookingrequest.php#bookingRequests') ?>">View all &rarr;</a></div>
            <?php if (!$data['bookings']): ?><p class="management-empty">No approved bookings in the next 7 days.</p><?php else: ?><ul class="management-list">
            <?php foreach ($data['bookings'] as $booking): ?><li><div class="management-list-heading"><a href="<?= $url('superadmin/bookingrequest.php?status='.$booking['status'].'#bookingRequests') ?>"><?= $e($booking['amenity']) ?></a><span class="management-tag"><?= $booking['booking_date'] === $data['today'] ? 'Today' : $e((new DateTimeImmutable($booking['booking_date']))->format('M j')) ?></span></div><p><?= $e($booking['full_name']) ?> &middot; Unit <?= $e($booking['unit_number'] ?: 'Unassigned') ?></p><small><?= $e($date($booking['booking_date'].' '.$booking['booking_time'])) ?><?= $booking['end_time'] ? ' - '.$e(substr($booking['end_time'],0,5)) : '' ?> &middot; <?= $e(ucfirst($booking['status'])) ?></small></li><?php endforeach; ?>
            </ul><?php endif; ?>
        </section>
        <section class="management-panel" aria-labelledby="managementResidentsTitle">
            <div class="management-panel-heading"><div><h2 id="managementResidentsTitle">Latest resident accounts</h2><p>New registrations and their current account status</p></div><a href="<?= $url('superadmin/residents.php') ?>">View residents &rarr;</a></div>
            <?php if (!$data['residents']['rows']): ?><p class="management-empty">No resident accounts have been registered.</p><?php else: ?><ul class="management-list management-resident-list">
            <?php foreach ($data['residents']['rows'] as $resident): $state = !$resident['is_active'] ? 'Inactive' : (!$resident['is_verified'] ? 'Unverified' : ucfirst($resident['status'])); ?><li><div class="management-list-heading"><a href="<?= $url('superadmin/residents.php?search='.rawurlencode($resident['username'])) ?>"><?= $e($resident['full_name']) ?></a><span class="management-tag"><?= $e($state) ?></span></div><small>Unit <?= $e($resident['unit_number'] ?: 'Unassigned') ?> &middot; <?= $e($resident['username']) ?></small></li><?php endforeach; ?>
            </ul><?php endif; ?>
        </section>
        </div>
    </div>
    <p class="management-updated">Updated <?= $e($data['updated_at']) ?>. Refreshes every minute when visible and not in use.</p>
<?php }
