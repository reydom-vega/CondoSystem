<?php
/** Real multipart uploads, database transactions and protected HTTP routes; disposable data only. */
if (PHP_SAPI !== 'cli') exit;
require_once dirname(__DIR__) . '/includes/environment.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$server = new mysqli(appSetting('CONDO_DB_HOST','localhost'), appSetting('CONDO_DB_USER','root'), appSetting('CONDO_DB_PASS'));
$database = 'condo_identity_test_' . bin2hex(random_bytes(6));
$server->query("CREATE DATABASE `$database` CHARACTER SET utf8mb4");
$storage = sys_get_temp_dir() . '/condo-identity-' . bin2hex(random_bytes(12));
$socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
$port = (int)substr(strrchr(stream_socket_get_name($socket,false), ':'),1); fclose($socket);
$base = 'http://127.0.0.1:' . $port . '/CondoSystem3/';
foreach (['CONDO_DB_NAME'=>$database,'CONDO_APP_ENV'=>'test','CONDO_AUTO_MIGRATE'=>'1','CONDO_UI_FIXTURE'=>'1','CONDO_APP_URL'=>rtrim($base,'/'),'CONDO_VISITOR_ID_STORAGE'=>$storage,'CONDO_VISITOR_ID_RETENTION_DAYS'=>'30','CONDO_PASS_SIGNING_KEY'=>str_repeat('a',64)] as $key=>$value) putenv($key.'='.$value);
$_SERVER['PHP_SELF'] = $_SERVER['SCRIPT_NAME'] = '/CondoSystem3/scripts/test_visitor_identity.php';
$_SERVER['REQUEST_METHOD'] = 'GET'; $_SERVER['HTTP_HOST'] = 'localhost';
require_once dirname(__DIR__) . '/config.php';
$http = null; $curl = null; $temps = []; $checks = 0;
function identityCheck(bool $condition, string $label): void { global $checks; if (!$condition) throw new RuntimeException('FAILED: '.$label); $checks++; }
function identityActor(int $id, string $role): void { $_SESSION = ['user_id'=>$id,'role'=>$role,'username'=>'Identity Fixture','session_version'=>0,'last_activity'=>time()]; }
function identityRow(mysqli $db, int $id): array { $stmt=$db->prepare('SELECT * FROM resident_service_requests WHERE id=?'); $stmt->bind_param('i',$id); $stmt->execute(); return $stmt->get_result()->fetch_assoc(); }
function identityHttp(string $path, int $actor, ?array $post = null): array {
    global $curl,$base;
    curl_setopt_array($curl,[CURLOPT_URL=>$base.$path.(str_contains($path,'?')?'&':'?').'fixture_actor='.$actor,CURLOPT_POST=>$post!==null,CURLOPT_HTTPGET=>$post===null,CURLOPT_HEADER=>true]);
    if ($post !== null) curl_setopt($curl,CURLOPT_POSTFIELDS,$post);
    $raw=curl_exec($curl); if (!is_string($raw)) throw new RuntimeException('HTTP failed: '.curl_error($curl));
    clearstatcache();
    $headerSize=curl_getinfo($curl,CURLINFO_HEADER_SIZE);
    return [(int)curl_getinfo($curl,CURLINFO_HTTP_CODE),substr($raw,$headerSize),substr($raw,0,$headerSize)];
}
try {
    $server->select_db($database); $server->multi_query(file_get_contents(dirname(__DIR__).'/database.sql'));
    do { $r=$server->store_result(); if($r)$r->free(); } while($server->more_results() && $server->next_result());
    $db=connectDb(); ensureResidentServicesTables($db); visitorIdStorageDirectory(true);
    $db->query("INSERT INTO users(id,username,full_name,email,password_hash,unit_number,role,is_verified,status,account_type,unit_owner_id) VALUES
        (1,'owner','Owner One','owner@example.invalid','fixture','0101','resident',1,'approved','Resident Owner',NULL),
        (2,'tenant','Tenant Two','tenant@example.invalid','fixture','0101','resident',1,'approved','Tenant',1),
        (3,'other','Other Owner','other@example.invalid','fixture','0102','resident',1,'approved','Resident Owner',NULL),
        (4,'admin','Admin Staff','admin@example.invalid','fixture',NULL,'admin',1,'approved',NULL,NULL),
        (5,'guard','Security Staff','guard@example.invalid','fixture',NULL,'security',1,'approved',NULL,NULL),
        (6,'super','Superadmin Staff','super@example.invalid','fixture',NULL,'superadmin',1,'approved',NULL,NULL),
        (7,'treasurer','Treasurer Staff','treasurer@example.invalid','fixture',NULL,'treasurer',1,'approved',NULL,NULL),
        (8,'maintenance','Maintenance Staff','maintenance@example.invalid','fixture',NULL,'maintenance',1,'approved',NULL,NULL)");
    $db->query("INSERT INTO parking_slots(slot_code,slot_type,status) VALUES('ID-V1','visitor','available')");
    identityCheck(ensureVisitorIdentitySchema($db) && ensureVisitorIdentitySchema($db), 'identity migration is repeatable');
    $manifest = [['path'=>'resident/visitors.php','actor'=>1],['path'=>'resident/parking.php','actor'=>1],['path'=>'parking_pass.php','actor'=>1],['path'=>'superadmin/service_requests.php?kind=visitor','actor'=>4],['path'=>'resident_service_pass.php','actor'=>1],['path'=>'visitor_id_document.php','actor'=>4],['path'=>'security/scanner.php','actor'=>5],['path'=>'security/security_dashboard.php','actor'=>5]];
    putenv('CONDO_UI_MANIFEST='.json_encode($manifest));
    $log=tempnam(sys_get_temp_dir(),'condo-identity-http-'); $temps[]=$log;
    $http=proc_open([PHP_BINARY,'-S','127.0.0.1:'.$port,__DIR__.'/ui_fixture_router.php'],[0=>['pipe','r'],1=>['file',$log,'a'],2=>['file',$log,'a']],$pipes,dirname(__DIR__)); fclose($pipes[0]);
    $curl=curl_init(); curl_setopt_array($curl,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_COOKIEFILE=>'',CURLOPT_CONNECTTIMEOUT=>1,CURLOPT_TIMEOUT=>20]);
    for($i=0;$i<50;$i++){ try { [$status,$html]=identityHttp('resident/visitors.php',1); if($status===200) break; } catch(RuntimeException $e) {} usleep(100000); }
    if ($status !== 200 || !str_contains($html,'csrf_token')) fwrite(STDERR, "Fixture form status: $status\n" . substr($html,0,500) . "\n" . file_get_contents($log));
    identityCheck($status===200 && preg_match('/name="csrf_token" value="([a-f0-9]+)"/',$html,$match)===1, 'visitor form renders with CSRF'); $csrf=$match[1];
    identityCheck(str_contains($html,'multipart/form-data') && str_contains($html,'name="valid_id"') && str_contains($html,'Privacy notice:'), 'upload and privacy notice use existing form');
    foreach(VISITOR_ID_TYPES as $type) identityCheck(str_contains($html,htmlspecialchars($type,ENT_QUOTES,'UTF-8')), 'ID type available: '.$type);
    $png=tempnam(sys_get_temp_dir(),'condo-id-image-'); $temps[]=$png;
    file_put_contents($png,base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jwXcAAAAASUVORK5CYII='));
    $bad=tempnam(sys_get_temp_dir(),'condo-id-bad-'); $temps[]=$bad; file_put_contents($bad,'<?php echo "not an image";');
    $large=tempnam(sys_get_temp_dir(),'condo-id-large-'); $temps[]=$large; file_put_contents($large,str_repeat('x',VISITOR_ID_MAX_BYTES+1));
    $truncated=tempnam(sys_get_temp_dir(),'condo-id-truncated-'); $temps[]=$truncated; file_put_contents($truncated,substr(file_get_contents($png),0,40));
    $input=['csrf_token'=>$csrf,'visitor_name'=>'ID Protected Guest','visitor_count'=>'2','details'=>'Family visit','start_date'=>date('Y-m-d'),'id_type'=>'National ID','id_number'=>'PRIVATE-ID-9876543210'];
    $upload=fn()=>new CURLFile($png,'image/png','identity.png');
    $count=fn()=>(int)$db->query('SELECT COUNT(*) AS n FROM resident_service_requests')->fetch_assoc()['n'];
    foreach ([
        'missing image'=>$input,
        'unsupported ID type'=>array_replace($input,['id_type'=>'Fake ID','valid_id'=>$upload()]),
        'oversized image'=>array_replace($input,['valid_id'=>new CURLFile($large,'image/png','large.png')]),
        'spoofed MIME'=>array_replace($input,['valid_id'=>new CURLFile($bad,'image/png','script.png')]),
        'executable extension'=>array_replace($input,['valid_id'=>new CURLFile($png,'image/png','identity.php')]),
        'truncated image'=>array_replace($input,['valid_id'=>new CURLFile($truncated,'image/png','partial.png')]),
        'invalid ID number'=>array_replace($input,['id_number'=>str_repeat('A',81),'valid_id'=>$upload()]),
    ] as $label=>$post) {
        [$status,$body]=identityHttp('resident/visitors.php',1,$post);
        identityCheck($status===200 && str_contains($body,'alert error') && $count()===0, 'backend rejects '.$label.' without creating records');
        identityCheck(iterator_count(new FilesystemIterator($storage))===0,'no leaked file after '.$label);
    }
    [$status]=identityHttp('resident/visitors.php',1,array_replace($input,['csrf_token'=>'invalid','valid_id'=>$upload()]));
    identityCheck($status===403 && $count()===0, 'invalid CSRF cannot upload or register');
    $combined=array_replace($input,['valid_id'=>$upload(),'needs_parking'=>'1','vehicle_type'=>'Car','expected_arrival'=>date('Y-m-d').'T00:00','expected_departure'=>date('Y-m-d').'T23:59','parking_fee_ack'=>'1','vehicle_plate'=>'ID1234','vehicle_description'=>'Silver sedan']);
    [$status]=identityHttp('resident/visitors.php',2,$combined);
    identityCheck($status===302 && $count()===1,'tenant can submit real multipart registration with linked parking');
    $row=$db->query('SELECT * FROM resident_service_requests ORDER BY id DESC LIMIT 1')->fetch_assoc(); $id=(int)$row['id'];
    identityCheck($row['identity_status']==='pending' && $row['status']==='pending' && $row['id_number_last4']==='3210', 'identity and visit approvals start pending; only last four ID characters retained');
    identityCheck(preg_match('/^[a-f0-9]{48}\.png$/D',$row['id_document_name'])===1 && is_file($storage.'/'.$row['id_document_name']), 'random file stored outside the application');
    identityCheck(!visitorPathWithin(realpath($storage),realpath(dirname(__DIR__))), 'ID storage is outside the public application');
    $parking=$db->query('SELECT id FROM parking_requests WHERE visitor_registration_id='.$id)->fetch_assoc(); $parkingId=(int)$parking['id'];
    $passPath='resident_service_pass.php?id='.$id.'&token='.$row['access_token']; $passUrl=residentServicePassUrl($row);
    [$status,$body]=identityHttp($passPath,2);
    identityCheck($status===200 && !str_contains($body,'id="serviceQr"') && !str_contains($body,'PRIVATE-ID-9876543210') && !str_contains($body,$row['id_document_name']), 'pending pass has no QR or ID data');
    foreach ([1,2,3,7,8] as $actor) identityCheck(identityHttp('visitor_id_document.php?request_id='.$id,$actor)[0]===403,'unauthorized role cannot read document: '.$actor);
    identityCheck(identityHttp('visitor_id_document.php?request_id='.$id,0)[0]===302,'anonymous document access requires login');
    [, $html] = identityHttp('resident/visitors.php',2);
    preg_match('/name="csrf_token" value="([a-f0-9]+)"/',$html,$match); $csrf=$match[1]; $input['csrf_token']=$csrf;
    foreach ([4,5,6] as $actor) {
        [$status,$body,$headers]=identityHttp('visitor_id_document.php?request_id='.$id,$actor);
        identityCheck($status===200 && $body===file_get_contents($png) && str_contains(strtolower($headers),'no-store') && str_contains(strtolower($headers),'nosniff'), 'authorized reviewer receives protected ID: '.$actor);
    }
    $review=['csrf_token'=>$csrf,'action'=>'review_identity','request_id'=>$id,'identity_version'=>visitorIdentityVersion($row),'identity_decision'=>'verified','identity_review_note'=>'Matches the named visitor.'];
    identityCheck(identityHttp('superadmin/service_requests.php?kind=visitor',1,$review)[0]===403,'resident cannot forge staff identity review');
    identityCheck(identityHttp('superadmin/service_requests.php?kind=visitor',4,array_replace($review,['csrf_token'=>'bad']))[0]===403,'review requires valid CSRF');
    identityActor(5,'security');
    identityCheck(decideResidentServiceRequest($db,$id,'approved','Visit approved'),'visit approval remains separate');
    identityCheck(!checkInRegisteredVisitor($db,$id,$row['access_token'],true) && !verifyAccessScan($db,$passUrl)['valid'], 'visit approval without identity cannot admit visitor');
    // Reproduce legacy parking approval while the visitor ID is still pending.
    $parkingPath='parking_pass.php?request_id='.$parkingId.'&signature='.parkingPassSignature($parkingId);
    $db->query("UPDATE parking_requests SET status='approved',slot_id=1 WHERE id=$parkingId");
    identityCheck(getParkingPass($parkingId,parkingPassSignature($parkingId))===null && !verifyAccessScan($db,parkingPassUrl($parkingId))['valid'],'pending visitor identity never authorizes approved parking');
    [$status,$body]=identityHttp($parkingPath,2);
    identityCheck($status===200 && str_contains($body,'Visitor identity verification is pending') && !str_contains($body,'id="qrCode"') && !str_contains($body,'id="downloadPass"'),'authorized owner sees pending verification instead of a false access-denied page');
    identityCheck(identityHttp($parkingPath,3)[0]===404 && identityHttp($parkingPath,7)[0]===404 && identityHttp($parkingPath,4)[0]===200,'status explanation preserves owner and gate-personnel access restrictions');
    [$deniedStatus,$deniedBody]=identityHttp($parkingPath,3);
    identityCheck($deniedStatus===404 && str_contains($deniedBody,'Parking pass unavailable') && str_contains($deniedBody,'You are signed in as') && str_contains($deniedBody,'Return to dashboard') && !str_contains($deniedBody,'ID Protected Guest') && !str_contains($deniedBody,'ID1234'),'denied pass shows the current account and recovery navigation without exposing visitor details');
    identityCheck(identityHttp('parking_pass.php?request_id='.$parkingId.'&signature=forged',2)[0]===404,'status page still rejects forged signatures');
    $db->query("UPDATE resident_service_requests SET id_document_name=NULL WHERE id=$id");
    [$status,$body]=identityHttp($parkingPath,2);
    identityCheck($status===200 && str_contains($body,'Upload a valid ID in Visitor Registration') && !str_contains($body,'id="qrCode"'),'missing legacy ID explains how to complete verification without issuing a QR');
    [, $list]=identityHttp('resident/parking.php?tab=requests',2);
    identityCheck(str_contains($list,'View visitor registration') && !str_contains($list,'Download QR Pass'),'parking requests do not offer a download before ID verification');
    $restore=$db->prepare('UPDATE resident_service_requests SET id_document_name=? WHERE id=?');$restore->bind_param('si',$row['id_document_name'],$id);$restore->execute();
    $db->query("UPDATE parking_requests SET status='pending',slot_id=NULL WHERE id=$parkingId");
    identityCheck(identityHttp('superadmin/service_requests.php?kind=visitor',4,$review)[0]===302,'admin verifies the uploaded identity');
    $row=identityRow($db,$id);
    identityCheck($row['identity_status']==='verified' && (int)$row['identity_reviewed_by']===4 && $row['identity_reviewed_at']!==null,'review records actual staff actor and timestamp');
    identityCheck(!verifyAccessScan($db,$passUrl)['valid'] && !checkInRegisteredVisitor($db,$id,$row['access_token'],true), 'identity approval still waits for linked parking');
    identityCheck(decideParkingRequest($parkingId,'approved',1,''),'existing parking approval works');
    [$status,$body]=identityHttp($parkingPath,2);
    identityCheck($status===200 && str_contains($body,'id="qrCode"') && str_contains($body,'id="downloadPass"') && !str_contains($body,'cdnjs.cloudflare.com/ajax/libs/qrcodejs'),'verified and approved parking pass opens with the local QR renderer');
    $scan=verifyAccessScan($db,$passUrl);
    identityCheck($scan['valid'] && $scan['identity_status']==='verified' && $scan['vehicle_plate']==='ID1234' && $scan['visit_purpose']==='Family visit' && $scan['visitor_count']===2,'scanner shows visitor, visit, identity and vehicle information');
    $json=json_encode($scan);
    identityCheck(!str_contains($json,'id_number') && !str_contains($json,'PRIVATE-ID') && !str_contains($json,$row['id_document_name']) && !str_contains($json,'id_document'), 'scanner JSON excludes ID references, images and paths');
    [$status,$body]=identityHttp($passPath,2);
    identityCheck($status===200 && str_contains($body,'id="serviceQr"') && str_contains($body,'ID1234') && str_contains($body,'Identity verification') && !str_contains($body,'ID reference ending'), 'approved pass has QR and safe visitor details');
    identityCheck(identityHttp('superadmin/service_requests.php?kind=visitor',5,array_replace($review,['identity_decision'=>'rejected','identity_review_note'=>'']))[0]===200 && identityRow($db,$id)['identity_status']==='verified','rejection requires a reason');
    identityCheck(identityHttp('superadmin/service_requests.php?kind=visitor',5,array_replace($review,['identity_decision'=>'rejected','identity_review_note'=>'Please upload a readable image.']))[0]===302,'security rejects an identification document');
    $rejected=identityRow($db,$id);
    identityCheck($rejected['identity_status']==='rejected' && $rejected['access_token']!==$row['access_token'] && !verifyAccessScan($db,$passUrl)['valid'], 'rejection immediately revokes old QR token');
    $replacement=['csrf_token'=>$csrf,'action'=>'replace_identity','request_id'=>$id,'id_type'=>'Passport','valid_id'=>$upload()];
    identityHttp('resident/visitors.php',3,$replacement);
    identityCheck(identityRow($db,$id)['id_document_name']===$rejected['id_document_name'],'another resident cannot replace the visitor ID');
    identityCheck(identityHttp('resident/visitors.php',2,$replacement)[0]===302,'owner can resubmit rejected ID');
    $row=identityRow($db,$id);
    identityCheck($row['identity_status']==='pending' && $row['identity_reviewed_by']===null && $row['id_number_last4']===null && !is_file($storage.'/'.$rejected['id_document_name']), 'replacement resets review, deletes old file and does not retain a previous number');
    identityCheck(identityHttp('superadmin/service_requests.php?kind=visitor',4,$review)[0]===200 && identityRow($db,$id)['identity_status']==='pending','stale review cannot approve a replacement document that was not reviewed');
    identityCheck(ensureVisitorIdentitySchema($db) && identityRow($db,$id)['id_document_name']===$row['id_document_name'],'repeat migration retains uploaded documents');
    $db->query("CREATE TRIGGER fail_identity_audit BEFORE INSERT ON audit_logs FOR EACH ROW BEGIN IF NEW.entity_type='visitor_identity' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Simulated identity audit failure'; END IF; END");
    identityCheck(!reviewVisitorIdentity($db,$id,'verified','Matches') && identityRow($db,$id)['identity_status']==='pending','review and audit roll back together');
    $db->query('DROP TRIGGER fail_identity_audit');
    identityCheck(reviewVisitorIdentity($db,$id,'verified','Matches'),'security verifies replacement identity');
    $row=identityRow($db,$id); $passUrl=residentServicePassUrl($row);
    identityCheck(checkInRegisteredVisitor($db,$id,$row['access_token'],true),'verified visitor checks in through existing QR workflow');
    $row=identityRow($db,$id); $logId=(int)$row['visitor_log_id'];
    identityCheck($logId>0 && (int)$db->query('SELECT visitor_count FROM visitor_logs WHERE id='.$logId)->fetch_assoc()['visitor_count']===2,'existing visitor log retains group count');
    identityCheck(!checkInRegisteredVisitor($db,$id,$row['access_token'],true) && logVisitorOut($db,$logId,5),'duplicate entry refused and existing exit still works');
    identityCheck(verifyAccessScan($db,$passUrl)['verification_result']==='already_used','completed QR cannot be replayed');
    $db->query("UPDATE resident_service_requests SET start_date=CURDATE()-INTERVAL 31 DAY,end_date=CURDATE()-INTERVAL 31 DAY WHERE id=$id");
    identityCheck(identityHttp('visitor_id_document.php?request_id='.$id,4)[0]===404,'expired ID is unavailable before scheduled deletion');
    $dry=purgeExpiredVisitorIds($db);
    identityCheck($dry['eligible']===1 && $dry['deleted']===0 && is_file($storage.'/'.$row['id_document_name']),'dry run preserves ID and reports retention candidates');
    $orphan=bin2hex(random_bytes(24)).'.png'; file_put_contents($storage.'/'.$orphan,file_get_contents($png)); touch($storage.'/'.$orphan,time()-90000);
    $fresh=bin2hex(random_bytes(24)).'.png'; file_put_contents($storage.'/'.$fresh,file_get_contents($png));
    $purged=purgeExpiredVisitorIds($db,true); $retained=identityRow($db,$id);
    identityCheck($purged['deleted']===1 && !is_file($storage.'/'.$row['id_document_name']) && $retained['id_document_name']===null && $retained['id_number_last4']===null && $retained['id_deleted_at']!==null,'retention deletes expired image and number reference');
    identityCheck($retained['identity_status']==='verified' && $retained['status']==='checked_out' && (int)$retained['visitor_log_id']===$logId, 'retention preserves visitor and verification history');
    identityCheck($purged['orphans_deleted']===1 && !is_file($storage.'/'.$orphan) && is_file($storage.'/'.$fresh),'retention cleans old orphans while preserving fresh uploads');
    putenv('CONDO_VISITOR_ID_RETENTION_DAYS=0');
    try { visitorIdRetentionDays(); throw new LogicException('Invalid retention accepted.'); } catch (RuntimeException $e) { identityCheck(true,'invalid retention configuration fails closed'); }
    putenv('CONDO_VISITOR_ID_RETENTION_DAYS=30');
    putenv('CONDO_VISITOR_ID_STORAGE='.dirname(__DIR__).'/private_uploads/visitor_ids');
    try { visitorIdStorageDirectory(true); throw new LogicException('Public storage accepted.'); } catch (RuntimeException $e) { identityCheck(true,'web-accessible storage configuration rejected'); }
    putenv('CONDO_VISITOR_ID_STORAGE='.$storage);
    $jpeg = base64_decode('/9j/4AAQSkZJRgABAQEAYABgAAD//gA+Q1JFQVRPUjogZ2QtanBlZyB2MS4wICh1c2luZyBJSkcgSlBFRyB2ODApLCBkZWZhdWx0IHF1YWxpdHkK/9sAQwAIBgYHBgUIBwcHCQkICgwUDQwLCwwZEhMPFB0aHx4dGhwcICQuJyAiLCMcHCg3KSwwMTQ0NB8nOT04MjwuMzQy/9sAQwEJCQkMCwwYDQ0YMiEcITIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIy/8AAEQgAAgACAwEiAAIRAQMRAf/EAB8AAAEFAQEBAQEBAAAAAAAAAAABAgMEBQYHCAkKC//EALUQAAIBAwMCBAMFBQQEAAABfQECAwAEEQUSITFBBhNRYQcicRQygZGhCCNCscEVUtHwJDNicoIJChYXGBkaJSYnKCkqNDU2Nzg5OkNERUZHSElKU1RVVldYWVpjZGVmZ2hpanN0dXZ3eHl6g4SFhoeIiYqSk5SVlpeYmZqio6Slpqeoqaqys7S1tre4ubrCw8TFxsfIycrS09TV1tfY2drh4uPk5ebn6Onq8fLz9PX29/j5+v/EAB8BAAMBAQEBAQEBAQEAAAAAAAABAgMEBQYHCAkKC//EALURAAIBAgQEAwQHBQQEAAECdwABAgMRBAUhMQYSQVEHYXETIjKBCBRCkaGxwQkjM1LwFWJy0QoWJDThJfEXGBkaJicoKSo1Njc4OTpDREVGR0hJSlNUVVZXWFlaY2RlZmdoaWpzdHV2d3h5eoKDhIWGh4iJipKTlJWWl5iZmqKjpKWmp6ipqrKztLW2t7i5usLDxMXGx8jJytLT1NXW19jZ2uLj5OXm5+jp6vLz9PX29/j5+v/aAAwDAQACEQMRAD8A+f6KKKAP/9k=');
    $webp = base64_decode('UklGRiQAAABXRUJQVlA4IBgAAAAwAQCdASoCAAIAAUAmJaQAA3AA/v02aAA=');
    $pngBytes = file_get_contents($png);
    $text = "Note\0" . str_repeat('a',VISITOR_ID_MAX_BYTES-strlen($pngBytes)-12-5);
    $maxPng = substr($pngBytes,0,-12) . pack('N',strlen($text)) . 'tEXt' . $text . pack('N',crc32('tEXt'.$text)) . substr($pngBytes,-12);
    foreach ([['JPEG',$jpeg,'image/jpeg','image.JPG'],['WEBP',$webp,'image/webp','image.webp'],['5 MB boundary PNG',$maxPng,'image/png','max.png']] as [$label,$bytes,$mime,$name]) {
        $formatFile=tempnam(sys_get_temp_dir(),'condo-id-format-'); $temps[]=$formatFile; file_put_contents($formatFile,$bytes);
        [$status]=identityHttp('resident/visitors.php',1,array_replace($input,['visitor_name'=>$label.' Guest','valid_id'=>new CURLFile($formatFile,$mime,$name)]));
        identityCheck($status===302,'real upload accepts '.$label);
    }
    $filesBefore=iterator_count(new FilesystemIterator($storage)); $rowsBefore=$count();
    $db->query("CREATE TRIGGER fail_identity_parking BEFORE INSERT ON parking_requests FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Simulated linked parking failure'");
    [$status,$body]=identityHttp('resident/visitors.php',1,array_replace($input,['needs_parking'=>'1','vehicle_type'=>'Car','expected_arrival'=>date('Y-m-d').'T00:00','expected_departure'=>date('Y-m-d').'T23:59','parking_fee_ack'=>'1','vehicle_plate'=>'ROLLBACKID','valid_id'=>$upload()]));
    identityCheck($status===200 && str_contains($body,'alert error') && $count()===$rowsBefore && iterator_count(new FilesystemIterator($storage))===$filesBefore,'parking failure rolls back registration and deletes its new ID file');
    $db->query('DROP TRIGGER fail_identity_parking');
    $legacyToken=bin2hex(random_bytes(32));
    $db->query("INSERT INTO resident_service_requests(user_id,request_kind,visitor_name,details,start_date,end_date,status,access_token) VALUES(1,'visitor','Legacy Visitor','Existing visit',CURDATE(),CURDATE(),'approved','$legacyToken')"); $legacyId=(int)$db->insert_id;
    $legacy=identityRow($db,$legacyId);
    identityCheck($legacy['identity_status']==='pending' && !verifyAccessScan($db,residentServicePassUrl($legacy))['valid'],'legacy approval cannot bypass mandatory identity verification');
    identityCheck(identityHttp('resident/visitors.php',1,array_replace($replacement,['request_id'=>$legacyId,'valid_id'=>$upload()]))[0]===302,'resident can supply required ID for an existing approved registration');
    $legacy=identityRow($db,$legacyId);
    identityCheck($legacy['status']==='approved' && $legacy['identity_status']==='pending' && $legacy['access_token']!==$legacyToken,'legacy upgrade preserves visit approval and revokes its old token');
    identityActor(4,'admin'); identityCheck(reviewVisitorIdentity($db,$legacyId,'verified','Matches'),'legacy visitor can complete identity verification');
    $legacy=identityRow($db,$legacyId);
    $db->query("INSERT INTO parking_requests(user_id,request_type,visitor_registration_id,vehicle_plate,start_date,end_date,status) VALUES(1,'visitor',$legacyId,'OLD',CURDATE(),CURDATE(),'rejected'),(1,'visitor',$legacyId,'CANCELLED',CURDATE(),CURDATE(),'cancelled')");
    identityCheck(visitorAccessApprovalsComplete($db,$legacy) && visitorPassVehicle($db,$legacyId)===null,'cancelled latest parking permits walking in without reviving an old rejected request');
    // Keep one pending registration for optional browser review; no test ID data is real.
    identityHttp('resident/visitors.php',1,array_replace($input,['visitor_name'=>'Browser Identity Guest','id_number'=>'','valid_id'=>$upload()]));
    if (in_array('--preview',$argv,true)) {
        [$browserStatus]=identityHttp('resident/visitors.php',1,array_replace($input,['visitor_name'=>'Browser Parking Guest','id_number'=>'','valid_id'=>$upload(),'needs_parking'=>'1','vehicle_type'=>'Car','expected_arrival'=>date('Y-m-d').'T00:00','expected_departure'=>date('Y-m-d').'T23:59','parking_fee_ack'=>'1','vehicle_plate'=>'BROWSER-PARK','vehicle_description'=>'Browser fixture vehicle']));
        identityCheck($browserStatus===302,'browser parking fixture registers with a current CSRF token');
        $browserVisitor=$db->query("SELECT * FROM resident_service_requests WHERE visitor_name='Browser Parking Guest' ORDER BY id DESC LIMIT 1")->fetch_assoc();
        $browserId=(int)$browserVisitor['id'];$browserParking=(int)$db->query('SELECT id FROM parking_requests WHERE visitor_registration_id='.$browserId)->fetch_assoc()['id'];
        $db->query("INSERT INTO parking_slots(slot_code,slot_type,status) VALUES('BROWSER-V1','visitor','available')");$browserSlot=(int)$db->insert_id;
        identityActor(4,'admin');
        identityCheck(decideResidentServiceRequest($db,$browserId,'approved','Browser fixture') && reviewVisitorIdentity($db,$browserId,'verified','Browser fixture') && decideParkingRequest($browserParking,'approved',$browserSlot,''),'browser parking fixture completes existing visit, identity and parking approvals');
    }
    echo "Passed $checks visitor identity upload, approval, privacy, QR and retention checks in a disposable database.\n";
    if (in_array('--preview',$argv,true)) { echo 'Identity preview: '.$base."\nPress Enter to finish.\n"; fgets(STDIN); }
    $logContents=file_get_contents($log);
    identityCheck(!preg_match('/PHP (?:Warning|Fatal|Parse|Deprecated)/',$logContents), 'HTTP routes have no PHP warnings');
} finally {
    if ($curl) curl_close($curl);
    if (is_resource($http)) { proc_terminate($http); proc_close($http); }
    foreach ($temps as $file) if (is_file($file)) unlink($file);
    if (is_dir($storage) && str_starts_with($storage,sys_get_temp_dir().'/condo-identity-')) { foreach(new DirectoryIterator($storage) as $file) if($file->isFile()) unlink($file->getPathname()); rmdir($storage); }
    if (!preg_match('/^condo_identity_test_[a-f0-9]{12}$/D',$database)) throw new RuntimeException('Unsafe fixture cleanup.');
    $server->query("DROP DATABASE `$database`");
}
