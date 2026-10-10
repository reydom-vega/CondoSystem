<?php
/** Isolated integration suite. Never modifies the working condominium database. */
if (PHP_SAPI!=='cli') exit;
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$server=new mysqli(getenv('CONDO_DB_HOST') ?: 'localhost',getenv('CONDO_DB_USER') ?: 'root',getenv('CONDO_DB_PASS') ?: '');
$testDb='condo_gate_test_'.bin2hex(random_bytes(6)); $server->query("CREATE DATABASE `$testDb` CHARACTER SET utf8mb4");
putenv('CONDO_DB_NAME='.$testDb); putenv('CONDO_APP_ENV=test'); putenv('CONDO_AUTO_MIGRATE=1'); putenv('CONDO_TENANT_PERMITS=1'); putenv('CONDO_PASS_SIGNING_KEY='.str_repeat('a',64));
$_SERVER['PHP_SELF']=$_SERVER['SCRIPT_NAME']='/CondoSystem3/scripts/test_property_gate_pass.php'; $_SERVER['HTTP_HOST']='localhost'; $_SERVER['REQUEST_METHOD']='GET';
require_once __DIR__.'/../config.php';
$checks=0;
function gateCheck(bool $value,string $label): void { global $checks; if(!$value) throw new RuntimeException('FAILED: '.$label); $checks++; }
function gateReject(callable $action,string $label): void { try { $action(); } catch(InvalidArgumentException $e) { gateCheck(true,$label); return; } throw new RuntimeException('FAILED: '.$label); }
function gateActor(int $id,string $role='resident'): void { $_SESSION=['user_id'=>$id,'role'=>$role,'username'=>'Gate Fixture','last_activity'=>time(),'session_version'=>0]; }
function gateRender(string $page,array $get=[],array $post=[]): string {
    global $db;
    $_GET=$get; $_POST=$post; $_SERVER['REQUEST_METHOD']=$post?'POST':'GET';
    $staffServices=$page==='staff'; $serviceKind='permit'; $permitDetail=isset($get['request_id'])?gateRequest($db,(int)$get['request_id']):null;
    $ready=true; $requests=getResidentServiceRequests($db,'permit',$staffServices?null:(int)$_SESSION['user_id']);
    ob_start(); include __DIR__.'/../includes/permit_requests_content.php'; return ob_get_clean();
}
function serviceEscape($value): string { return gateEscape($value); }
try {
    $server->select_db($testDb);
    $server->query("CREATE TABLE users (id INT PRIMARY KEY AUTO_INCREMENT,username VARCHAR(100),full_name VARCHAR(100),email VARCHAR(100),contact_number VARCHAR(30),unit_number VARCHAR(30),role VARCHAR(30),is_verified TINYINT DEFAULT 1,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB");
    $server->query("INSERT INTO users(id,username,full_name,email,unit_number,role) VALUES(1,'owner','Owner','owner@example.invalid','101','resident'),(2,'tenant','Tenant','tenant@example.invalid','101','resident'),(3,'other','Other Owner','other@example.invalid','102','resident'),(4,'manager','Manager','manager@example.invalid',NULL,'admin'),(5,'guard','Guard','guard@example.invalid',NULL,'security')");
    $db=connectDb(); ensureResidentAccountSchema($db);
    $db->query("CREATE TABLE resident_service_requests (id INT AUTO_INCREMENT PRIMARY KEY,user_id INT NOT NULL,request_kind ENUM('visitor','permit') NOT NULL,permit_type VARCHAR(30) DEFAULT NULL,visitor_name VARCHAR(120) DEFAULT NULL,visitor_contact VARCHAR(30) DEFAULT NULL,details VARCHAR(500) NOT NULL,start_date DATE NOT NULL,end_date DATE NOT NULL,status ENUM('pending','approved','rejected','cancelled','checked_in','checked_out') NOT NULL DEFAULT 'pending',access_token CHAR(64) NOT NULL UNIQUE,admin_notes VARCHAR(500) DEFAULT NULL,decided_by INT DEFAULT NULL,decided_at DATETIME DEFAULT NULL,visitor_log_id INT DEFAULT NULL,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE) ENGINE=InnoDB");
    $db->query("INSERT INTO resident_service_requests(user_id,request_kind,permit_type,details,start_date,end_date,status,access_token) VALUES(1,'permit','Move-in','Pre-upgrade record',CURDATE(),CURDATE(),'approved',REPEAT('c',64))");
    $original=$db->query('SELECT * FROM resident_service_requests WHERE id=1')->fetch_assoc();
    ensureResidentServicesTables($db);
    $upgraded=$db->query('SELECT * FROM resident_service_requests WHERE id=1')->fetch_assoc();
    gateCheck(array_intersect_key($upgraded,$original)===$original && $upgraded['gate_data']===null,'upgrade preserves every field of an existing approved permit');
    $db->query("UPDATE users SET account_type='Tenant',unit_owner_id=1 WHERE id=2");
    // Repeatable upgrades preserve the original enum values and pre-existing requests.
    gateActor(1); $legacy=createResidentServiceRequest($db,1,'permit',['permit_type'=>'Delivery','details'=>'Legacy request','start_date'=>date('Y-m-d')]);
    ensureResidentServicesTables($db);
    gateCheck(gateRequest($db,$legacy)['details']==='Legacy request','migration preserves legacy permits');
    $input=['permit_type'=>'Property Gate Pass','direction'=>'entry','purpose'=>'New Appliance / Furniture','details'=>'Kitchen equipment','start_date'=>date('Y-m-d'),'start_time'=>'00:00','end_time'=>'23:59','transporter'=>'Fixture Delivery','vehicle_plate'=>'ABC123','items'=>[['item_name'=>'Refrigerator','category'=>'Appliance','quantity'=>'1','description'=>'Silver'],['item_name'=>'Cabinet','category'=>'Furniture','quantity'=>'2','description'=>'Wood']]];
    gateActor(2); $id=savePropertyGateRequest($db,$input+['user_id'=>3,'unit_number'=>'102']); $row=gateRequest($db,$id);
    gateCheck((int)$row['user_id']===2 && gateData($row)['unit_number']==='0101','authenticated identity overrides forged account and unit');
    gateCheck($row['status']==='pending' && $row['qr_token_hash']===null,'submission is pending without issued QR token hash');
    gateCheck(count(gateChildren($db,$id,'permit_items'))===2 && (int)gateChildren($db,$id,'permit_items')[1]['quantity']===2,'multiple items and quantities stored under one request');
    gateCheck(str_starts_with(gateNumber($id),'PGP-') && gateNumber($id)!==gateNumber($legacy),'unique stable permit number');
    gateReject(fn()=>gateTransition($db,$id,'approved'),'tenant cannot approve');
    gateReject(fn()=>gateTransition($db,$id,'completed',['physical_check'=>'1']),'tenant cannot complete');
    gateReject(fn()=>gateTransition($db,$id,'changes_requested',['admin_notes'=>'Fake']),'tenant cannot request corrections');
    gateActor(3); gateCheck(!gateCanView($db,$row),'another resident cannot view permit');
    gateReject(fn()=>gateTransition($db,$id,'cancelled'),'another resident cannot cancel');
    gateActor(5,'security'); gateReject(fn()=>gateTransition($db,$id,'approved'),'guard cannot approve property movement');
    gateActor(4,'admin');
    gateReject(fn()=>gateTransition($db,$id,'rejected'),'rejection requires a reason');
    gateReject(fn()=>gateTransition($db,$id,'changes_requested'),'correction requires a reason');
    gateTransition($db,$id,'changes_requested',['admin_notes'=>'Specify model']);
    gateCheck(gateRequest($db,$id)['status']==='changes_requested','management returns for correction');
    gateActor(3); gateReject(fn()=>savePropertyGateRequest($db,$input,[],$id),'another resident cannot edit returned permit');
    gateActor(2); $input['items'][0]['description']='Model 2026'; savePropertyGateRequest($db,$input,[],$id);
    gateCheck(gateRequest($db,$id)['status']==='pending' && count(gateChildren($db,$id,'permit_items'))===2,'resubmission atomically replaces items and returns pending');
    gateActor(4,'admin');
    gateReject(fn()=>gateTransition($db,$id,'approved',['authorized_start'=>'17:00','authorized_end'=>'16:00']),'invalid authorized range rejected');
    gateTransition($db,$id,'approved'); $row=gateRequest($db,$id); $url=gatePassUrl($row); $token=gateToken($row);
    gateCheck($row['decided_by']==4 && $row['decided_at'] && hash_equals($row['qr_token_hash'],hash('sha256',$token)),'approval records approver timestamp and QR hash');
    gateCheck(!str_contains($url,'Owner') && !str_contains($url,'0101'),'QR URL contains no personal data');
    gateCheck(gateVerifyToken($db,$id,str_repeat('0',64))===null,'forged QR token rejected');
    gateCheck(gateValid($row),'approved in-schedule pass is valid');
    gateCheck(verifyAccessScan($db,$url)['valid'],'existing scanner recognizes property pass');
    gateCheck(gateRequest($db,$id)['status']==='approved','scanning does not complete a permit');
    $before=count(gateChildren($db,$id,'permit_history')); recordQrVerification($db,$url,'11111111-1111-4111-8111-111111111111');
    gateCheck(count(gateChildren($db,$id,'permit_history'))===$before+1,'verification outcome recorded with actor and timestamp');
    gateReject(fn()=>gateTransition($db,$id,'approved'),'approval cannot be replayed');
    gateReject(fn()=>savePropertyGateRequest($db,$input,[],$id),'approved request cannot be edited');
    gateActor(2); gateReject(fn()=>gateTransition($db,$id,'completed',['physical_check'=>'1']),'requester cannot complete approved permit');
    gateActor(5,'security'); gateReject(fn()=>gateTransition($db,$id,'completed'),'physical confirmation is mandatory');
    gateTransition($db,$id,'completed',['physical_check'=>'1','admin_notes'=>'All items checked']);
    $row=gateRequest($db,$id); gateCheck($row['status']==='completed' && gateData($row)['completed_by']===5,'guard completion records identity and time');
    gateCheck(!verifyAccessScan($db,$url)['valid'],'completed QR cannot be reused');
    gateReject(fn()=>gateTransition($db,$id,'completed',['physical_check'=>'1']),'duplicate completion denied');
    foreach(['rejected','cancelled'] as $outcome) {
        gateActor(2); $new=savePropertyGateRequest($db,$input); gateActor(4,'admin');
        if($outcome==='cancelled') { gateTransition($db,$new,'approved'); $revoked=gatePassUrl(gateRequest($db,$new)); }
        gateTransition($db,$new,$outcome,['admin_notes'=>'Not permitted']);
        gateCheck(gateRequest($db,$new)['status']===$outcome,'transition to '.$outcome);
        if($outcome==='cancelled') gateCheck(!verifyAccessScan($db,$revoked)['valid'],'cancelled QR is invalid');
    }
    gateActor(2); $timed=savePropertyGateRequest($db,$input); gateActor(4,'admin');
    gateTransition($db,$timed,'approved',['authorized_date'=>date('Y-m-d',strtotime('+1 day'))]);
    gateCheck(!gateValid(gateRequest($db,$timed)) && !verifyAccessScan($db,gatePassUrl(gateRequest($db,$timed)))['valid'],'future schedule invalid today');
    $expired=gateRequest($db,$timed); $data=gateData($expired); $data['authorized']['date']=date('Y-m-d',strtotime('-1 day')); $json=json_encode($data);
    $stmt=$db->prepare('UPDATE resident_service_requests SET gate_data=? WHERE id=?'); $stmt->bind_param('si',$json,$timed); $stmt->execute();
    gateCheck(gateEffectiveStatus(gateRequest($db,$timed))==='expired' && !verifyAccessScan($db,gatePassUrl(gateRequest($db,$timed)))['valid'],'expired schedule invalid');
    gateReject(fn()=>gateTransition($db,$timed,'completed',['physical_check'=>'1']),'expired permit cannot complete');
    gateActor(2);
    foreach([['items'=>[]],['start_time'=>'15:00','end_time'=>'14:00'],['start_time'=>'24:00'],['direction'=>'invalid'],['purpose'=>'Other','details'=>''],['start_date'=>'2026-02-30']] as $invalid) gateReject(fn()=>savePropertyGateRequest($db,array_replace($input,$invalid)),'invalid request rejected');
    foreach(['0','-1','1.2','65536','abc'] as $quantity) { $bad=$input; $bad['items'][0]['quantity']=$quantity; gateReject(fn()=>savePropertyGateRequest($db,$bad),'invalid quantity rejected'); }
    $before=(int)$db->query('SELECT COUNT(*) AS n FROM resident_service_requests')->fetch_assoc()['n'];
    $db->query("CREATE TRIGGER gate_item_failure BEFORE INSERT ON permit_items FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Injected item failure'");
    try { savePropertyGateRequest($db,$input); throw new RuntimeException('Expected storage failure'); } catch(mysqli_sql_exception $e) { gateCheck((int)$db->query('SELECT COUNT(*) AS n FROM resident_service_requests')->fetch_assoc()['n']===$before,'item failure rolls back parent and history'); }
    $db->query('DROP TRIGGER gate_item_failure');
    gateReject(fn()=>gateStoreUploads(['documents'=>['error'=>[UPLOAD_ERR_OK],'tmp_name'=>[__FILE__],'name'=>['malicious.php']]]),'non-uploaded/executable source rejected');
    $html=gateRender('resident'); gateCheck(str_contains($html,'id="permitRequestForm"') && str_contains($html,'name="items[0][quantity]"') && str_contains($html,'multipart/form-data'),'resident form renders dynamic item and upload controls');
    gateActor(4,'admin'); $html=gateRender('staff',['request_id'=>$timed]); gateCheck(str_contains($html,'Refrigerator') && str_contains($html,'Fixture Delivery') && str_contains($html,'Request and verification history'),'management details include items transport and history');
    gateCheck(count(gateChildren($db,$id,'permit_history'))>=6,'full approval correction completion history retained');
    // Exercise genuine multipart uploads, route authorization and CSRF through PHP's HTTP stack.
    $socket=stream_socket_server('tcp://127.0.0.1:0',$errno,$errstr); $address=stream_socket_get_name($socket,false); fclose($socket);
    $port=(int)substr(strrchr($address,':'),1); $base='http://127.0.0.1:'.$port.'/CondoSystem3/';
    $httpLog=tempnam(sys_get_temp_dir(),'gate-http-'); $cookie=tempnam(sys_get_temp_dir(),'gate-cookie-');
    $http=proc_open([PHP_BINARY,'-S','127.0.0.1:'.$port,__DIR__.'/gate_fixture_router.php'],[0=>['pipe','r'],1=>['file',$httpLog,'a'],2=>['file',$httpLog,'a']],$httpPipes,dirname(__DIR__));
    if(!is_resource($http)) throw new RuntimeException('Could not launch HTTP fixture');
    $uploadFiles=[]; $storedFiles=[];
    try {
        $request=function(string $route,int $actor=2,?array $post=null) use($base,$cookie): array {
            $ch=curl_init($base.$route.(str_contains($route,'?')?'&':'?').'fixture_actor='.$actor);
            curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_COOKIEJAR=>$cookie,CURLOPT_COOKIEFILE=>$cookie,CURLOPT_TIMEOUT=>10]);
            if($post!==null) curl_setopt($ch,CURLOPT_POSTFIELDS,$post);
            $body=curl_exec($ch); $status=curl_getinfo($ch,CURLINFO_HTTP_CODE); curl_close($ch); return [$status,$body];
        };
        for($attempt=0;$attempt<30;$attempt++) { $probe=@fsockopen('127.0.0.1',$port,$err,$message,.1); if($probe) { fclose($probe); break; } usleep(100000); }
        [$status,$html]=$request('resident/permits.php');
        gateCheck($status===200 && str_contains($html,'id="permitRequestForm"') && str_contains($html,'Property Gate Pass'),'actual tenant permit page renders');
        preg_match('/name="csrf_token" value="([a-f0-9]+)"/',$html,$match); $csrf=$match[1] ?? '';
        $post=['action'=>'gate_save','permit_type'=>'Property Gate Pass','direction'=>'exit','purpose'=>'Repair / Return','details'=>'HTTP uploaded property request','start_date'=>date('Y-m-d'),'start_time'=>'00:00','end_time'=>'23:59','items[0][item_name]'=>'Washing Machine','items[0][category]'=>'Appliance','items[0][quantity]'=>'1','items[1][item_name]'=>'Cabinet','items[1][category]'=>'Furniture','items[1][quantity]'=>'2','csrf_token'=>$csrf];
        [$status]=$request('resident/permits.php',2,array_replace($post,['csrf_token'=>'invalid']));
        gateCheck($status===403,'HTTP route rejects forged CSRF');
        $pdf=tempnam(sys_get_temp_dir(),'gate-pdf-'); file_put_contents($pdf,"%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF\n"); $uploadFiles[]=$pdf;
        $png=tempnam(sys_get_temp_dir(),'gate-png-'); file_put_contents($png,base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jCfkAAAAASUVORK5CYII=')); $uploadFiles[]=$png;
        [$status,$body]=$request('resident/permits.php',2,$post+['documents[0]'=>new CURLFile($pdf,'application/pdf','receipt.pdf'),'documents[1]'=>new CURLFile($png,'image/png','item.png')]);
        gateCheck($status===302,'real multipart submission redirects successfully');
        $httpRow=$db->query("SELECT * FROM resident_service_requests WHERE details='HTTP uploaded property request'")->fetch_assoc();
        gateCheck($httpRow && count(gateChildren($db,(int)$httpRow['id'],'permit_items'))===2,'HTTP submission stores all items');
        $storedFiles=gateChildren($db,(int)$httpRow['id'],'permit_documents');
        gateCheck(count($storedFiles)===2 && preg_match('/^[a-f0-9]{48}\.pdf$/D',$storedFiles[0]['stored_name'])===1,'real PDF and PNG uploads have randomized names');
        $documentId=(int)$storedFiles[0]['id'];
        [$status,$docBody]=$request('permit_document.php?id='.$documentId); gateCheck($status===200 && str_starts_with($docBody,'%PDF'),'requester can retrieve protected attachment');
        [$status]=$request('permit_document.php?id='.$documentId,3); gateCheck($status===404,'other resident cannot retrieve attachment by URL');
        [$status]=$request('resident/permits.php?request_id='.(int)$httpRow['id'],3); gateCheck($status===404,'other resident cannot retrieve permit details by URL');
        $bad=tempnam(sys_get_temp_dir(),'gate-bad-'); file_put_contents($bad,'<?php echo "executable";'); $uploadFiles[]=$bad;
        [$status,$body]=$request('resident/permits.php',2,$post+['documents[0]'=>new CURLFile($bad,'image/png','spoofed.png')]);
        gateCheck($status===200 && str_contains($body,'Only valid JPG, PNG and PDF'),'MIME spoofed executable upload rejected');
        $db->query("CREATE TRIGGER gate_http_item_failure BEFORE INSERT ON permit_items FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Injected upload transaction failure'");
        $directory=dirname(__DIR__).'/private_uploads/permit_documents'; $beforeFiles=glob($directory.'/*');
        [$status,$body]=$request('resident/permits.php',2,$post+['documents[0]'=>new CURLFile($pdf,'application/pdf','rollback.pdf')]);
        gateCheck($status===200 && str_contains($body,'Unable to save your request') && glob($directory.'/*')===$beforeFiles,'HTTP transaction failure removes newly uploaded files');
        $db->query('DROP TRIGGER gate_http_item_failure');
        [$status,$body]=$request('superadmin/service_requests.php?kind=permit&request_id='.(int)$httpRow['id'],4);
        gateCheck($status===200 && str_contains($body,'gate_approved') && str_contains($body,'receipt.pdf'),'admin HTTP preview exposes review and attachments');
        $review=['action'=>'gate_approved','request_id'=>$httpRow['id'],'csrf_token'=>$csrf];
        [$status]=$request('superadmin/service_requests.php?kind=permit',4,$review); gateCheck($status===302,'admin HTTP approval succeeds');
        $approved=gateRequest($db,(int)$httpRow['id']); $passRoute='resident_service_pass.php?id='.$approved['id'].'&token='.gateToken($approved);
        [$status,$body]=$request($passRoute,5); gateCheck($status===200 && str_contains($body,'id="serviceQr"') && str_contains($body,'Washing Machine'),'guard sees issued QR and authorized items');
        [$status]=$request($passRoute,3); gateCheck($status===404,'other resident cannot open token-bearing pass URL');
        [$status]=$request($passRoute,5,['action'=>'gate_completed','physical_check'=>'1','csrf_token'=>$csrf]); gateCheck($status===302,'guard explicitly completes through HTTP');
        [$status,$body]=$request($passRoute,5); gateCheck($status===200 && !str_contains($body,'id="serviceQr"') && str_contains($body,'Invalid for movement now'),'completed printable pass contains no reusable QR');
        if (in_array('--preview',$argv,true)) {
            echo "Disposable UI preview: ".$base."resident/permits.php\nPress Enter to finish and remove the fixture.\n";
            fgets(STDIN);
        }
    } finally {
        proc_terminate($http); fclose($httpPipes[0]); proc_close($http);
        gateRemoveUploads($storedFiles);
        foreach(array_merge($uploadFiles,[$cookie,$httpLog]) as $temp) if(is_file($temp)) unlink($temp);
    }
    echo "Passed $checks property gate pass integration checks in a disposable database.\n";
} finally {
    $server->query("DROP DATABASE IF EXISTS `$testDb`");
}
