<?php
/** Real MySQL and loopback HTTP; disposable data only. */
if(PHP_SAPI!=='cli') exit;
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$server=new mysqli(getenv('CONDO_DB_HOST') ?: 'localhost',getenv('CONDO_DB_USER') ?: 'root',getenv('CONDO_DB_PASS') ?: '');
$testDb='condo_gate_test_'.bin2hex(random_bytes(6)); $server->query("CREATE DATABASE `$testDb` CHARACTER SET utf8mb4");
putenv('CONDO_DB_NAME='.$testDb); putenv('CONDO_APP_ENV=test'); putenv('CONDO_AUTO_MIGRATE=1'); putenv('CONDO_TENANT_PERMITS=1'); putenv('CONDO_PASS_SIGNING_KEY='.str_repeat('b',64)); putenv('CONDO_APP_URL=http://localhost/CondoSystem3');
$_SERVER['PHP_SELF']=$_SERVER['SCRIPT_NAME']='/CondoSystem3/scripts/test_qr_scan_history.php'; $_SERVER['HTTP_HOST']='localhost'; $_SERVER['REQUEST_METHOD']='GET';
require_once __DIR__.'/../config.php'; require_once __DIR__.'/../includes/qr_scan_report.php';
$checks=0;
function scanCheck(bool $value,string $label): void { global $checks; if(!$value) throw new RuntimeException('FAILED: '.$label); $checks++; }
function scanReject(callable $action,string $label): void { try { $action(); } catch(InvalidArgumentException $e) { scanCheck(true,$label); return; } throw new RuntimeException('FAILED: '.$label); }
function scanActor(int $id,string $role): void { $_SESSION=['user_id'=>$id,'role'=>$role,'username'=>'Browser supplied alias','last_activity'=>time(),'session_version'=>0]; }
function scanUuid(): string { $s=bin2hex(random_bytes(16)); return substr($s,0,8).'-'.substr($s,8,4).'-'.substr($s,12,4).'-'.substr($s,16,4).'-'.substr($s,20); }
try {
    $server->select_db($testDb); $server->multi_query(file_get_contents(__DIR__.'/../database.sql')); do{$r=$server->store_result();if($r)$r->free();}while($server->more_results() && $server->next_result());
    $db=connectDb();
    $db->query("INSERT INTO users(id,username,full_name,email,contact_number,password_hash,unit_number,role,is_verified,status,account_type,unit_owner_id) VALUES(1,'owner','Owner One','owner@example.invalid','','fixture','0101','resident',1,'approved','Resident Owner',NULL),(2,'tenant','Tenant Two','tenant@example.invalid','','fixture','0101','resident',1,'approved','Tenant',1),(3,'other','Other Owner','other@example.invalid','','fixture','0102','resident',1,'approved','Resident Owner',NULL),(4,'admin','Admin Full Name','admin@example.invalid','','fixture',NULL,'admin',1,'approved',NULL,NULL),(5,'guard','Guard Full Name','guard@example.invalid','','fixture',NULL,'security',1,'approved',NULL,NULL),(6,'super','Superadmin Full Name','super@example.invalid','','fixture',NULL,'superadmin',1,'approved',NULL,NULL)");
    $db->query('DROP TABLE qr_scan_logs');
    $db->query("CREATE TABLE qr_scan_logs (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,user_id INT NOT NULL,username VARCHAR(100) NOT NULL,scanned_content TEXT NOT NULL,content_type VARCHAR(20) NOT NULL DEFAULT 'text',scanned_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,INDEX(user_id),INDEX(scanned_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $db->query("INSERT INTO qr_scan_logs(user_id,username,scanned_content,content_type,scanned_at) VALUES(5,'Old Scanner','https://localhost/pass?token=secret-legacy-token','link','2026-10-01 00:00:00')");
    ensureQrScanHistorySchema($db); $legacy=$db->query('SELECT * FROM qr_scan_logs WHERE id=1')->fetch_assoc();
    scanCheck($legacy['event_time_utc']==='2026-09-30 16:00:00','legacy local timestamp converted to UTC');
    scanCheck(!str_contains($legacy['scanned_content'],'secret-legacy-token') && $legacy['verification_result']==='unknown','legacy IDs retained but tokens redacted without invented outcomes');
    scanActor(2,'resident'); $visitorId=createResidentServiceRequest($db,2,'visitor',['visitor_name'=>'Family Guest','visitor_count'=>'3','details'=>'Family visit','start_date'=>date('Y-m-d')]);
    scanActor(5,'security'); scanCheck(decideResidentServiceRequest($db,$visitorId,'approved','Welcome'),'security approves visitor fixture'); $visitor=gateRequest($db,$visitorId); $visitorUrl=residentServicePassUrl($visitor);
    $uuid=scanUuid(); $event=recordQrVerification($db,$visitorUrl,$uuid); $scanId=$event['id'];
    scanCheck($event['verification']['valid'] && $event['verification']['qr_type']==='visitor','visitor verification recorded');
    $snapshot=$db->query('SELECT * FROM qr_scan_logs WHERE id='.$scanId)->fetch_assoc();
    scanCheck((int)$snapshot['user_id']===5 && $snapshot['scanner_full_name']==='Guard Full Name' && $snapshot['scanner_role']==='security','scanner identity comes from stored authenticated account');
    scanCheck($snapshot['subject_name']==='Family Guest' && $snapshot['unit_number']==='0101','verified visitor reference and identity snapshot saved');
    scanCheck(!str_contains(json_encode($snapshot),$visitor['access_token']),'new event contains no raw QR secret');
    $duplicate=recordQrVerification($db,$visitorUrl,$uuid); scanCheck($duplicate['duplicate'] && $duplicate['id']===$scanId,'same request is idempotent');
    $duplicate=recordQrVerification($db,$visitorUrl,scanUuid()); scanCheck($duplicate['id']===$scanId,'rapid camera repetition suppressed server-side');
    scanReject(fn()=>recordQrVerification($db,'https://unrelated.invalid/',$uuid),'request ID cannot be reused for another QR');
    scanCheck(gateRequest($db,$visitorId)['status']==='approved','verification alone does not check visitor in');
    scanCheck(checkInRegisteredVisitor($db,$visitorId,$visitor['access_token']),'confirmed entry still works');
    scanCheck((int)$db->query("SELECT COUNT(*) AS n FROM qr_scan_logs WHERE reference_id=$visitorId AND scan_action='check_in'")->fetch_assoc()['n']===1,'check-in logged once in same transaction');
    scanCheck(!checkInRegisteredVisitor($db,$visitorId,$visitor['access_token']),'duplicate check-in denied');
    $log=(int)gateRequest($db,$visitorId)['visitor_log_id']; scanCheck(logVisitorOut($db,$log,5),'confirmed exit still works');
    scanCheck((int)$db->query("SELECT COUNT(*) AS n FROM qr_scan_logs WHERE reference_id=$visitorId AND scan_action='check_out'")->fetch_assoc()['n']===1,'check-out logged in same transaction');
    scanCheck(verifyAccessScan($db,$visitorUrl)['verification_result']==='already_used','used visitor pass cannot be reused');
    scanCheck($db->query('SELECT verification_result FROM qr_scan_logs WHERE id='.$scanId)->fetch_assoc()['verification_result']==='valid','old outcome remains valid snapshot after exit');
    $invalid=recordQrVerification($db,buildUrl('resident_service_pass.php?id='.$visitorId.'&token='.str_repeat('0',64)),scanUuid());
    $invalidRow=$db->query('SELECT * FROM qr_scan_logs WHERE id='.$invalid['id'])->fetch_assoc();
    scanCheck($invalidRow['reference_id']===null && $invalidRow['subject_name']==='' && $invalidRow['unit_number']==='','forged token has no unrelated identity association');
    $unknown=recordQrVerification($db,'https://unknown.invalid/token=private',scanUuid()); scanCheck(!$unknown['verification']['valid'],'unknown QR attempt stored safely');
    scanActor(2,'resident'); $expired=createResidentServiceRequest($db,2,'visitor',['visitor_name'=>'Expired Guest','details'=>'Old visit','start_date'=>date('Y-m-d')]);
    $db->query("UPDATE resident_service_requests SET status='approved',start_date=CURDATE()-INTERVAL 1 DAY,end_date=CURDATE()-INTERVAL 1 DAY WHERE id=$expired");
    scanActor(5,'security'); $expiredEvent=recordQrVerification($db,residentServicePassUrl(gateRequest($db,$expired)),scanUuid()); scanCheck($expiredEvent['verification']['verification_result']==='expired','expired visitor scan stored');
    scanActor(2,'resident'); $propertyId=savePropertyGateRequest($db,['direction'=>'exit','purpose'=>'Repair / Return','details'=>'Repair appliance','start_date'=>date('Y-m-d'),'start_time'=>'00:00','end_time'=>'23:59','items'=>[['item_name'=>'Refrigerator','category'=>'Appliance','quantity'=>'1']]]);
    scanActor(4,'admin'); gateTransition($db,$propertyId,'approved'); $property=gateRequest($db,$propertyId); $propertyUrl=gatePassUrl($property);
    $propertyEvent=recordQrVerification($db,$propertyUrl,scanUuid()); scanCheck($propertyEvent['verification']['valid'] && $propertyEvent['verification']['qr_type']==='property','admin can record property verification');
    scanCheck(gateRequest($db,$propertyId)['status']==='approved','property scan does not complete movement');
    gateTransition($db,$propertyId,'completed',['physical_check'=>'1']);
    scanCheck((int)$db->query("SELECT COUNT(*) AS n FROM qr_scan_logs WHERE reference_id=$propertyId AND scan_action='permit_completion'")->fetch_assoc()['n']===1,'completion action saved atomically');
    scanCheck(verifyAccessScan($db,$propertyUrl)['verification_result']==='already_used','completed property token reports already used');
    $replay=recordQrVerification($db,$propertyUrl,scanUuid()); scanCheck(!$replay['duplicate'] && $replay['verification']['verification_result']==='already_used','changed outcome creates a new event even inside camera debounce window');
    $db->query('DELETE FROM resident_service_requests WHERE id='.$propertyId);
    scanCheck($db->query('SELECT verification_result FROM qr_scan_logs WHERE id='.$propertyEvent['id'])->fetch_assoc()['verification_result']==='valid','historical snapshot survives removal of its source permit');
    scanActor(2,'resident'); $cancelledId=savePropertyGateRequest($db,['direction'=>'entry','purpose'=>'Personal Property Transfer','start_date'=>date('Y-m-d'),'start_time'=>'00:00','end_time'=>'23:59','items'=>[['item_name'=>'Chair','category'=>'Furniture','quantity'=>'1']]]);
    scanActor(4,'admin'); gateTransition($db,$cancelledId,'approved'); $cancelledUrl=gatePassUrl(gateRequest($db,$cancelledId)); gateTransition($db,$cancelledId,'cancelled');
    scanCheck(recordQrVerification($db,$cancelledUrl,scanUuid())['verification']['verification_result']==='cancelled','cancelled property scan recorded accurately');
    scanActor(2,'resident'); $rollbackVisitor=createResidentServiceRequest($db,2,'visitor',['visitor_name'=>'Rollback Guest','details'=>'Rollback test','start_date'=>date('Y-m-d')]);
    scanActor(5,'security'); decideResidentServiceRequest($db,$rollbackVisitor,'approved',''); $rollbackRow=gateRequest($db,$rollbackVisitor);
    $countBefore=(int)$db->query('SELECT COUNT(*) AS n FROM visitor_logs')->fetch_assoc()['n'];
    $db->query("CREATE TRIGGER qr_action_failure BEFORE INSERT ON qr_scan_logs FOR EACH ROW BEGIN IF NEW.scan_action='check_in' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Injected action history failure'; END IF; END");
    scanCheck(!checkInRegisteredVisitor($db,$rollbackVisitor,$rollbackRow['access_token']),'history write failure refuses visitor entry');
    scanCheck(gateRequest($db,$rollbackVisitor)['status']==='approved' && (int)$db->query('SELECT COUNT(*) AS n FROM visitor_logs')->fetch_assoc()['n']===$countBefore,'visitor entry and history rollback together');
    $db->query('DROP TRIGGER qr_action_failure');
    scanActor(2,'resident'); $rollbackProperty=savePropertyGateRequest($db,['direction'=>'entry','purpose'=>'Personal Property Transfer','start_date'=>date('Y-m-d'),'start_time'=>'00:00','end_time'=>'23:59','items'=>[['item_name'=>'Table','category'=>'Furniture','quantity'=>'1']]]);
    scanActor(4,'admin'); gateTransition($db,$rollbackProperty,'approved');
    $db->query("CREATE TRIGGER qr_completion_failure BEFORE INSERT ON qr_scan_logs FOR EACH ROW BEGIN IF NEW.scan_action='permit_completion' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Injected completion history failure'; END IF; END");
    try { gateTransition($db,$rollbackProperty,'completed',['physical_check'=>'1']); throw new RuntimeException('Expected completion history failure'); }
    catch(mysqli_sql_exception $e) { scanCheck(gateRequest($db,$rollbackProperty)['status']==='approved' && gateValid(gateRequest($db,$rollbackProperty)),'property completion rolls back if history cannot be saved'); }
    $db->query('DROP TRIGGER qr_completion_failure');
    scanActor(5,'admin'); scanReject(fn()=>recordQrVerification($db,$visitorUrl,scanUuid()),'session role spoofing cannot change scanner role');
    scanActor(2,'resident'); scanReject(fn()=>recordQrVerification($db,$visitorUrl,scanUuid()),'resident cannot record scans');
    scanActor(5,'security'); scanCheck(!qrExportAllowed(),'security export is explicitly denied'); scanActor(4,'admin'); scanCheck(qrExportAllowed(),'admin export allowed'); scanActor(6,'superadmin'); scanCheck(qrExportAllowed(),'superadmin export allowed');
    $scanner=qrScannerIdentity($db);
    for($i=0;$i<65;$i++) {
        $id=qrAppendEvent($db,$scanner,['qr_type'=>'visitor','reference_id'=>$visitorId,'subject_name'=>'Export Guest '.$i,'requester_name'=>'Tenant Two','unit_number'=>'0101','pass_number'=>'VIS-REPORT-'.$i,'verification_result'=>'valid','remarks'=>'Fixture report record']);
        $db->query("UPDATE qr_scan_logs SET event_time_utc='2026-10-05 04:00:00',created_at_utc='2026-10-05 04:00:00' WHERE id=$id");
    }
    $wild=qrAppendEvent($db,$scanner,['qr_type'=>'visitor','subject_name'=>'Literal A_100% Guest','verification_result'=>'valid']);
    foreach(['2026-09-30 15:59:59','2026-09-30 16:00:00','2026-10-09 15:59:59','2026-10-09 16:00:00'] as $time) {
        $id=qrAppendEvent($db,$scanner,['qr_type'=>'property','subject_name'=>'Boundary Guest','verification_result'=>'expired']);
        qrQuery($db,'UPDATE qr_scan_logs SET event_time_utc=? WHERE id=?','si',[$time,$id]);
    }
    $range=qrHistoryFilters(['period'=>'range','start_date'=>'2026-10-01','end_date'=>'2026-10-09','qr_type'=>'visitor','status'=>'valid','role'=>'superadmin','q'=>'Export Guest']);
    scanCheck($range['dates']['from']==='2026-09-30 16:00:00' && $range['dates']['until']==='2026-10-09 16:00:00','Manila range converted to inclusive-date UTC boundaries');
    $history=qrHistoryPage($db,$range); scanCheck($history['total']===65 && count($history['records'])===25 && $history['pages']===3,'combined filters and first pagination page work');
    $third=$range; $third['page']=3; scanCheck(count(qrHistoryPage($db,$third)['records'])===15,'last page contains remaining records');
    $oldest=$range; $oldest['sort']='oldest'; scanCheck(qrHistoryPage($db,$oldest)['records'][0]['subject_name']==='Export Guest 0','oldest order stable on tied timestamps');
    scanCheck(qrHistoryPage($db,qrHistoryFilters(['q'=>'Literal A_100%']))['total']===1,'LIKE wildcard characters are treated literally');
    scanCheck(qrHistoryPage($db,qrHistoryFilters(['q'=>"' OR 1=1 --"]))['total']===0,'SQL-like search text is handled as literal data');
    scanCheck(qrHistoryPage($db,qrHistoryFilters(['period'=>'range','start_date'=>'2026-10-01','end_date'=>'2026-10-09','q'=>'Boundary Guest']))['total']===2,'entire local end date included without following midnight');
    foreach([['period'=>'range','start_date'=>'2026-10-09','end_date'=>'2026-10-01'],['period'=>'single','date'=>'2026-02-30'],['role'=>'resident'],['status'=>'bogus'],['page'=>'0'],['period'=>['today']]] as $bad) scanReject(fn()=>qrHistoryFilters($bad),'invalid filter rejected');
    $report=qrReportData($db,$range); scanCheck(count($report['rows'])===65,'PDF includes all pages of matching records');
    $single=qrHistoryFilters(['period'=>'single','date'=>'2026-10-05','q'=>'Export Guest']); scanCheck(qrReportData($db,$single)['summary']['total']===65,'single-date report returns real matching records');
    scanReject(fn()=>qrReportData($db,qrHistoryFilters(['period'=>'single','date'=>'2000-01-01'])),'empty report returns a friendly error');
    scanReject(fn()=>qrReportData($db,qrHistoryFilters(['period'=>'range','start_date'=>'2000-01-01','end_date'=>'2026-10-09'])),'oversized report date range refused');
    $bulk=array_fill(0,2001,"(6,'Fixture','QR verification','event','visitor','CAP fixture','valid','superadmin','2026-10-05 04:00:00','2026-10-05 04:00:00')");
    $db->query('INSERT INTO qr_scan_logs(user_id,username,scanned_content,content_type,qr_type,subject_name,verification_result,scanner_role,event_time_utc,created_at_utc) VALUES '.implode(',',$bulk));
    scanReject(fn()=>qrReportData($db,qrHistoryFilters(['period'=>'single','date'=>'2026-10-05','q'=>'CAP fixture'])),'report limit rejects oversized result instead of silently truncating');
    $html=qrReportHtml($report,$range,$scanner,'QRR-TEST','2026-10-09 12:00:00'); scanCheck(str_contains($html,'Export Guest 0') && str_contains($html,'Export Guest 64'),'report HTML includes records beyond one page');
    $pdf=qrReportPdf($report,$range,$scanner); scanCheck(str_starts_with($pdf,'%PDF-') && strlen($pdf)>10000,'Dompdf produces real PDF bytes');
    foreach($argv as $arg) if(str_starts_with($arg,'--pdf=')) file_put_contents(substr($arg,6),$pdf);
    $socket=stream_socket_server('tcp://127.0.0.1:0',$err,$message); $address=stream_socket_get_name($socket,false); fclose($socket); $port=(int)substr(strrchr($address,':'),1);
    $log=tempnam(sys_get_temp_dir(),'qr-http-'); $cookie=tempnam(sys_get_temp_dir(),'qr-cookie-');
    $http=proc_open([PHP_BINARY,'-S','127.0.0.1:'.$port,__DIR__.'/gate_fixture_router.php'],[0=>['pipe','r'],1=>['file',$log,'a'],2=>['file',$log,'a']],$pipes,dirname(__DIR__));
    try {
        for($i=0;$i<30;$i++){ $probe=@fsockopen('127.0.0.1',$port,$err,$message,.1);if($probe){fclose($probe);break;}usleep(100000); }
        $httpRequest=function(string $route,int $actor=5,?array $post=null) use($port,$cookie):array {
            $ch=curl_init('http://127.0.0.1:'.$port.'/CondoSystem3/'.$route.(str_contains($route,'?')?'&':'?').'fixture_actor='.$actor);
            curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_COOKIEJAR=>$cookie,CURLOPT_COOKIEFILE=>$cookie,CURLOPT_TIMEOUT=>30]);
            if($post!==null){curl_setopt($ch,CURLOPT_HTTPHEADER,['Content-Type: application/json']);curl_setopt($ch,CURLOPT_POSTFIELDS,json_encode($post));}
            $body=curl_exec($ch);$status=curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);return[$status,$body];
        };
        foreach([4,5,6] as $actor) { [$status,$body]=$httpRequest('security/scanner.php',$actor);scanCheck($status===200 && str_contains($body,'id="historyTab"') && str_contains($body,'id="qrHistoryFilters"'),'authorized scanner page contains history tab and filters');scanCheck(str_contains($body,'id="openQrExport"')===($actor!==5),'PDF button follows explicit role permission'); }
        foreach([1,2,3] as $actor) foreach(['security/scanner.php','api/scan_history.php','api/scan_history_export.php?period=today'] as $route) { [$status]=$httpRequest($route,$actor);scanCheck($status===403,'resident/tenant denied scanner history and export endpoint'); }
        [$status]=$httpRequest('api/scan_history_export.php?period=today',5);scanCheck($status===403,'security cannot call PDF endpoint directly');
        [$status,$body]=$httpRequest('api/scan_history.php?'.http_build_query(['period'=>'range','start_date'=>'2026-10-01','end_date'=>'2026-10-09','q'=>'Export Guest','qr_type'=>'visitor','role'=>'superadmin','status'=>'valid','page'=>3]),5);
        $data=json_decode($body,true);scanCheck($status===200 && $data['total']===65 && count($data['records'])===15,'server-side HTTP search combined filters and pagination work');
        [$status,$body]=$httpRequest('api/scan_history.php?id='.$scanId,5);$data=json_decode($body,true);scanCheck($status===200 && $data['record']['scanner_role']==='security' && !str_contains($body,$visitor['access_token']),'detail endpoint is token-free');
        [$status,$body]=$httpRequest('security/scanner.php',5);preg_match('/data-csrf="([a-f0-9]+)"/',$body,$m);$csrf=$m[1] ?? '';
        $request=['content'=>'unknown safe scan','request_id'=>scanUuid(),'csrf_token'=>$csrf,'scanner_user_id'=>1,'scanner_role'=>'admin'];
        [$status]=$httpRequest('api/scan_history.php',5,array_replace($request,['csrf_token'=>'invalid']));scanCheck($status===403,'scan POST requires valid CSRF');
        [$status,$body]=$httpRequest('api/scan_history.php',5,$request);$event=json_decode($body,true);scanCheck($status===200 && $event['success'],'actual scan POST records failed verification');
        [$status,$body]=$httpRequest('api/scan_history.php?id='.$event['id'],5);$data=json_decode($body,true);scanCheck($data['record']['scanner_role']==='security','POST identity spoof fields ignored');
        [$status,$body]=$httpRequest('api/scan_history.php',5,$request);scanCheck($status===200 && json_decode($body,true)['id']===$event['id'],'HTTP retries are idempotent');
        $query=http_build_query(['period'=>'single','date'=>'2026-10-05','q'=>'Export Guest']);
        foreach([4,6] as $actor){[$status,$body]=$httpRequest('api/scan_history_export.php?'.$query,$actor);scanCheck($status===200 && str_starts_with($body,'%PDF-'),'admin/superadmin download actual filtered report');}
        [$status,$body]=$httpRequest('api/scan_history_export.php?period=single&date=2000-01-01',4);scanCheck($status===422 && str_contains($body,'No scans match'),'empty HTTP export returns friendly message');
    } finally { proc_terminate($http);fclose($pipes[0]);proc_close($http);unlink($cookie);unlink($log); }
    echo "Passed $checks QR scan history checks in a disposable database.\n";
} finally { $server->query("DROP DATABASE IF EXISTS `$testDb`"); }
