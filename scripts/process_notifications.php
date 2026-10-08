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
$result=processNotificationOutbox(connectDb(),$limit,null,$seconds);
echo json_encode($result,JSON_UNESCAPED_SLASHES) . PHP_EOL;
