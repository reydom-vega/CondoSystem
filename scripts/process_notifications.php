<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$limit=25; $seconds=45;
foreach (array_slice($argv,1) as $argument) {
    if ($argument==='--help') { echo "Usage: php scripts/process_notifications.php [--limit=25] [--seconds=45]\nDelivers queued email/SMS using configured providers; schedule once per minute.\n"; exit; }
    if (preg_match('/^--limit=(\d+)$/D',$argument,$match)) $limit=(int)$match[1];
    elseif (preg_match('/^--seconds=(\d+)$/D',$argument,$match)) $seconds=(int)$match[1];
    else { fwrite(STDERR,"Unknown option. Use --help.\n"); exit(2); }
}
require_once dirname(__DIR__) . '/config.php';
// Scheduler stdout is normally discarded. Keep a private, non-PII heartbeat
// so administrators can distinguish queued notices from a stopped worker.
$statusFile=dirname(__DIR__).'/private_uploads/notification-worker.json';
$started=microtime(true);
$record=static function(string $state,array $extra=[])use($statusFile,$started):void {
    $data=['state'=>$state,'started_at'=>date(DATE_ATOM,(int)$started),'updated_at'=>date(DATE_ATOM),'elapsed_seconds'=>round(microtime(true)-$started,2)]+$extra;
    if (@file_put_contents($statusFile,json_encode($data,JSON_UNESCAPED_SLASHES),LOCK_EX)===false) error_log('Notification worker status could not be recorded.');
};
$record('running');
try {
    // A parking monitoring fault must not stop unrelated billing/email delivery.
    try { $parkingMonitor=monitorVisitorParking(connectDb()); }
    catch(Throwable $parkingError) { $parkingMonitor=['error'=>true]; error_log('Parking monitoring failed: '.get_class($parkingError)); }
    $result=processNotificationOutbox(connectDb(),$limit,null,$seconds);
    $record($result['busy']?'busy':'finished',['result'=>$result,'parking_monitor'=>$parkingMonitor]);
    echo json_encode($result,JSON_UNESCAPED_SLASHES) . PHP_EOL;
} catch(Throwable $error) {
    $record('error');
    error_log('Notification worker failed: '.get_class($error));
    fwrite(STDERR,"Notification worker failed. Check database availability and private PHP logs.\n");
    exit(1);
}
