<?php
require_once __DIR__.'/../config.php';
requireCapability('notifications.manage');
$db=connectDb(); ensureNotificationOutboxTable($db);
if ($_SERVER['REQUEST_METHOD']==='POST') {
    requireWorkflowCsrf();
    $id=filter_var($_POST['notification_id'] ?? null,FILTER_VALIDATE_INT);
    $retried=$id && retryNotificationDelivery($db,$id);
    setFlash($retried ? 'success' : 'error', $retried ? 'Delivery retry queued.' : 'This delivery cannot be retried. Refresh the list.');
    redirect('notification_delivery.php');
}
$filter=$_GET['status'] ?? 'failed';
if (!is_string($filter) || !in_array($filter,['all','pending','processing','sent','failed','cancelled'],true)) $filter='failed';
$channel=$_GET['channel'] ?? 'all';
if (!is_string($channel) || !in_array($channel,['all','email','sms'],true)) $channel='all';
$reportVisibility="(event_kind<>'community_report' OR EXISTS(SELECT 1 FROM community_reports r WHERE r.id=notification_outbox.entity_id AND (".reportReviewerPredicate($db,(int)$_SESSION['user_id']).")))";
$counts=array_fill_keys(['pending','processing','sent','failed','cancelled'],0);
$summary=$db->prepare("SELECT status,COUNT(*) AS total FROM notification_outbox WHERE $reportVisibility AND (?='all' OR channel=?) GROUP BY status");
$summary->bind_param('ss',$channel,$channel);$summary->execute();
foreach ($summary->get_result() as $row) $counts[$row['status']]=(int)$row['total'];
$find=$db->prepare("SELECT id,channel,recipient,event_kind,status,attempts,last_error,created_at,sent_at FROM notification_outbox WHERE $reportVisibility AND (?='all' OR status=?) AND (?='all' OR channel=?) ORDER BY id DESC LIMIT 50");
$find->bind_param('ssss',$filter,$filter,$channel,$channel); $find->execute(); $jobs=$find->get_result()->fetch_all(MYSQLI_ASSOC);
$workerFile=__DIR__.'/../private_uploads/notification-worker.json';
$worker=is_file($workerFile) ? json_decode((string)file_get_contents($workerFile,false,null,0,8192),true) : null;
$workerUpdated=is_array($worker) ? strtotime($worker['updated_at'] ?? '') : false;
$workerHealthy=$workerUpdated && time()-$workerUpdated<180 && in_array($worker['state'] ?? '',['finished','running','busy'],true);
$deliveryLink=static fn(string $status,string $kind):string=>htmlspecialchars('?'.http_build_query(['status'=>$status,'channel'=>$kind]),ENT_QUOTES,'UTF-8');
$flash=getFlash();
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Notification Delivery | The Celandine</title><link rel="stylesheet" href="<?= htmlspecialchars(portalUiAssetUrl('styles.css'), ENT_QUOTES, 'UTF-8') ?>"><link rel="stylesheet" href="<?= htmlspecialchars(portalUiAssetUrl('services.css'), ENT_QUOTES, 'UTF-8') ?>"><?php renderPortalUiHead(); ?>
</head>
<body class="portal-ui dashboard-page admin-page"><div class="dash-layout">
<aside class="sidebar" id="sidebar"><a class="sidebar-brand" href="<?php echo htmlspecialchars(buildUrl(dashboardPathForRole()),ENT_QUOTES,'UTF-8'); ?>"><?php include __DIR__.'/../buildingicon.php'; ?></a><nav class="sidebar-nav"><?php renderStaffSidebarNavigation(); ?></nav></aside><div class="sidebar-overlay" id="sidebarOverlay"></div>
<main class="dashboard-main"><?php renderPortalWorkspaceHeader('Notification Delivery'); ?>
<section class="service-panel"><h2>Delivery status</h2><p>Notices are delivered automatically. Retry a failed notice after resolving its delivery issue.</p>
<div class="alert <?= $workerHealthy ? 'success' : 'error' ?>" role="status"><?= $workerHealthy ? 'Notification worker is checking the queue.' : 'The notification worker has not reported a successful recent run. Check its scheduled task or hosting cron job.' ?><?php if($workerUpdated): ?> Last activity: <?= htmlspecialchars(date('M j, Y g:i:s A',$workerUpdated),ENT_QUOTES,'UTF-8') ?>.<?php endif; ?></div>
<p>Email marked Sent means the mail server accepted it; inbox delivery is controlled by the receiving provider. SMS uses a separate provider.</p>
<?php if($flash): ?><div class="alert <?php echo $flash['type']==='error'?'error':'success'; ?>"><?php echo htmlspecialchars($flash['message'],ENT_QUOTES,'UTF-8'); ?></div><?php endif; ?>
<nav class="service-button-row notification-status-filters" aria-label="Filter notification channel"><?php foreach(['all'=>'All channels','email'=>'Email','sms'=>'SMS'] as $kind=>$label): ?><a class="service-btn service-btn-secondary" href="<?= $deliveryLink($filter,$kind) ?>"<?= $channel===$kind ? ' aria-current="page"' : '' ?>><?= $label ?></a><?php endforeach; ?></nav>
<nav class="service-button-row notification-status-filters" aria-label="Filter delivery status"><?php foreach($counts as $status=>$count): ?><a class="service-btn service-btn-secondary" href="<?= $deliveryLink($status,$channel) ?>"<?= $filter===$status ? ' aria-current="page"' : '' ?>><?php echo ucfirst($status).' ('.$count.')'; ?></a><?php endforeach; ?><a class="service-btn service-btn-secondary" href="<?= $deliveryLink('all',$channel) ?>"<?= $filter==='all' ? ' aria-current="page"' : '' ?>>All notices</a></nav>
<?php if(!$jobs): ?><p>No notices match this status.</p><?php endif; ?>
<?php foreach($jobs as $job): ?><article class="service-request"><div class="service-request-heading"><h3><?php echo htmlspecialchars(ucwords(str_replace('_',' ',$job['event_kind'])),ENT_QUOTES,'UTF-8').' #'.(int)$job['id']; ?></h3><span><?php echo htmlspecialchars(ucfirst($job['status']),ENT_QUOTES,'UTF-8'); ?></span></div><p><?php echo htmlspecialchars(strtoupper($job['channel']).' to '.$job['recipient'],ENT_QUOTES,'UTF-8'); ?> · Attempts: <?php echo (int)$job['attempts']; ?></p>
<?php if($job['last_error']): ?><p><?php echo htmlspecialchars($job['last_error'],ENT_QUOTES,'UTF-8'); ?></p><?php endif; ?>
<?php if($job['status']==='failed'): ?><form method="post" class="service-actions"><?php echo workflowCsrfField(); ?><input type="hidden" name="notification_id" value="<?php echo (int)$job['id']; ?>"><button type="submit" class="service-btn">Retry delivery</button></form><?php endif; ?></article><?php endforeach; ?>
</section></main></div><script src="../js/services-menu.js" defer></script></body></html>
