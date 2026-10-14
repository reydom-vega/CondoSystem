<?php
/** Real MySQL and HTTP with disposable data; never modifies the configured application DB. */
if (PHP_SAPI !== 'cli') exit;
require_once dirname(__DIR__).'/includes/environment.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$server = new mysqli(appSetting('CONDO_DB_HOST','localhost'),appSetting('CONDO_DB_USER','root'),appSetting('CONDO_DB_PASS'));
$database = 'condo_ui_test_'.bin2hex(random_bytes(6));
$server->query("CREATE DATABASE `$database` CHARACTER SET utf8mb4");
$socket = stream_socket_server('tcp://127.0.0.1:0',$errno,$error); $address = stream_socket_get_name($socket,false); fclose($socket);
$port = (int)substr(strrchr($address,':'),1); $base = 'http://127.0.0.1:'.$port.'/CondoSystem3/';
foreach (['CONDO_DB_NAME'=>$database,'CONDO_APP_ENV'=>'test','CONDO_AUTO_MIGRATE'=>'1','CONDO_UI_FIXTURE'=>'1','CONDO_UI_MANIFEST'=>'[]','CONDO_APP_URL'=>rtrim($base,'/')] as $key=>$value) putenv($key.'='.$value);
$_SERVER['REQUEST_METHOD'] = 'GET'; $_SERVER['SCRIPT_NAME'] = '/CondoSystem3/scripts/test_profile_photos.php';
require_once dirname(__DIR__).'/config.php';
$checks=0; $http=null; $log=null;
function photoCheck(bool $condition,string $label): void { global $checks; if (!$condition) throw new RuntimeException('FAILED: '.$label); $checks++; }
function photoActor(mysqli $db,int $id): void {
    $row=$db->query('SELECT role,username,session_version FROM users WHERE id='.$id)->fetch_assoc();
    $_SESSION=['user_id'=>$id,'role'=>$row['role'],'username'=>$row['username'],'session_version'=>(int)$row['session_version'],'last_activity'=>time()];
}
function idToken(array $issued): string { parse_str(parse_url($issued['qr_url'],PHP_URL_FRAGMENT),$parts); return $parts['credential']; }
try {
    $server->select_db($database); $server->multi_query(file_get_contents(dirname(__DIR__).'/database.sql'));
    do { $result=$server->store_result(); if($result)$result->free(); } while($server->more_results() && $server->next_result());
    $db=connectDb();
    $db->query("INSERT INTO users(id,username,full_name,email,contact_number,password_hash,unit_number,role,is_verified,status,account_type,unit_owner_id) VALUES
        (1,'owner','Owner One','owner@example.invalid','','fixture','0101','resident',1,'approved','Resident Owner',NULL),
        (2,'tenant','Tenant Two','tenant@example.invalid','','fixture','0101','resident',1,'approved','Tenant',1),
        (3,'pending','Pending Person','pending@example.invalid','','fixture',NULL,'resident',1,'pending','Tenant',NULL),
        (4,'admin','Admin Staff','admin@example.invalid','','fixture',NULL,'admin',1,'approved',NULL,NULL),
        (5,'security','Security Staff','security@example.invalid','','fixture',NULL,'security',1,'approved',NULL,NULL),
        (6,'superadmin','Superadmin Staff','superadmin@example.invalid','','fixture',NULL,'superadmin',1,'approved',NULL,NULL),
        (7,'treasurer','Treasurer Staff','treasurer@example.invalid','','fixture',NULL,'treasurer',1,'approved',NULL,NULL),
        (8,'maintenance','Maintenance Staff','maintenance@example.invalid','','fixture',NULL,'maintenance',1,'approved',NULL,NULL)");
    $db->query("UPDATE users SET staff_id=CONCAT('STAFF-',LPAD(id,5,'0')) WHERE role<>'resident'");

    $db->query("UPDATE users SET password_hash='unchanged-fixture-hash'");
    $before=$db->query('SELECT id,password_hash,role,unit_number,session_version FROM users ORDER BY id')->fetch_all(MYSQLI_ASSOC);
    ensureProfilePhotoSchema($db);ensureProfilePhotoSchema($db);
    photoCheck($before===$db->query('SELECT id,password_hash,role,unit_number,session_version FROM users ORDER BY id')->fetch_all(MYSQLI_ASSOC),'idempotent additive migration preserves accounts');
    photoCheck(profileInitial("  ?lan Name")==='?' && profileInitial('? ??')==='?' && profileInitial('Juan Dela Cruz')==='J','one meaningful UTF-8 initial');
    $log=tempnam(sys_get_temp_dir(),'condo-photo-http-');
    $http=proc_open([PHP_BINARY,'-S','127.0.0.1:'.$port,__DIR__.'/ui_fixture_router.php'],[0=>['pipe','r'],1=>['file',$log,'a'],2=>['file',$log,'a']],$pipes,dirname(__DIR__));fclose($pipes[0]);
    for($attempt=0;$attempt<50;$attempt++){if(@file_get_contents($base.'__manifest')!==false)break;usleep(100000);}
    $client=curl_init();curl_setopt_array($client,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_COOKIEFILE=>'',CURLOPT_TIMEOUT=>30]);
    $request=static function(string $path,?array $body=null)use($client,$base):array{
        $headers=[];curl_setopt_array($client,[CURLOPT_URL=>str_starts_with($path,$base)?$path:$base.$path,CURLOPT_POST=>$body!==null,CURLOPT_HTTPHEADER=>[],CURLOPT_HEADERFUNCTION=>static function($c,$line)use(&$headers){$headers[]=trim($line);return strlen($line);}]);
        if($body!==null)curl_setopt($client,CURLOPT_POSTFIELDS,$body);else curl_setopt($client,CURLOPT_HTTPGET,true);
        $data=curl_exec($client);if(!is_string($data))throw new RuntimeException('Fixture HTTP failed.');
        return ['status'=>curl_getinfo($client,CURLINFO_HTTP_CODE),'body'=>$data,'json'=>json_decode($data,true),'headers'=>implode("\n",$headers)];
    };
    $files=[];$photos=[];
    $sample=imagecreatetruecolor(700,500);imagefill($sample,0,0,imagecolorallocate($sample,255,120,10));imagefilledrectangle($sample,50,50,300,450,imagecolorallocate($sample,20,80,200));
    foreach(['jpg','png','webp'] as $format){$file=tempnam(sys_get_temp_dir(),'photo-test-');$files[]=$file;match($format){'jpg'=>imagejpeg($sample,$file,90),'png'=>imagepng($sample,$file),'webp'=>imagewebp($sample,$file,90)};$samples[$format]=$file;}
    imagedestroy($sample);
    foreach([1=>'Unit Owner',2=>'Tenant',4=>'Admin',5=>'Security Personnel',6=>'Super Admin',7=>'Treasurer',8=>'Maintenance']as$actor=>$role){
        $page=$request(($actor<=2?'resident/':'').'edit_profile.php?fixture_actor='.$actor);
        photoCheck($page['status']===200&&!str_contains($page['body'],'Fatal error')&&str_contains($page['body'],'Change profile picture'),'existing shared profile works for '.$role);
        photoCheck(($actor<=2)===str_contains($page['body'],'id="unit_number_display"'),'role appropriate unit display '.$role);
        preg_match('/data-csrf="([^"]+)"/',$page['body'],$m);$csrf=$m[1]??'';photoCheck($csrf!=='','CSRF supplied '.$role);
        $format=['jpg','png','webp'][$actor%3];$mime=['jpg'=>'image/jpeg','png'=>'image/png','webp'=>'image/webp'][$format];
        $uploaded=$request('api/profile_photo.php',['action'=>'upload','csrf_token'=>$csrf,'photo'=>new CURLFile($samples[$format],$mime,'portrait.'.$format)]);
        photoCheck($uploaded['status']===200&&($uploaded['json']['profile']['role']??'')===$role,'multipart upload '.$role.' '.$format);
        $photo=$db->query('SELECT profile_photo FROM users WHERE id='.$actor)->fetch_assoc()['profile_photo'];$photos[]=$photo;
        photoCheck(profilePhotoFilename($photo)&&$photo!=='portrait.'.$format,'randomized safe name '.$role);
        $media=$request($uploaded['json']['profile']['photo_url']);$info=getimagesizefromstring($media['body']);
        photoCheck($media['status']===200&&$info[0]===512&&$info[1]===512&&$info['mime']==='image/jpeg','512 square server reencoded JPEG '.$role);
        photoCheck(str_contains($media['headers'],'no-store')&&str_contains($media['headers'],'nosniff'),'private media headers '.$role);
        photoActor($db,$actor);$issued=digitalIdIssue($db);photoCheck($issued['profile']['photo_url']===$uploaded['json']['profile']['photo_url'],'Digital ID fresh photo '.$role);
        $_SESSION=[];photoCheck(!isset(digitalIdVerify($db,idToken($issued),true)['profile']),'public identity omits photo '.$role);
        photoActor($db,5);photoCheck(verifyAccessScan($db,$issued['qr_url'])['profile']['photo_url']===$uploaded['json']['profile']['photo_url'],'scanner returns authorized current photo '.$role);photoCheck(digitalIdVerify($db,idToken($issued),true)['profile']['photo_url']===$uploaded['json']['profile']['photo_url'],'authorized verification includes photo '.$role);
    }
    photoCheck($before===$db->query('SELECT id,password_hash,role,unit_number,session_version FROM users ORDER BY id')->fetch_all(MYSQLI_ASSOC),'photo updates preserve credentials roles units and sessions');
    $page=$request('resident/edit_profile.php?fixture_actor=1');preg_match('/data-csrf="([^"]+)"/',$page['body'],$m);$csrf=$m[1];
    $owner=$request('api/profile_photo.php')['json']['profile'];$old=$db->query('SELECT profile_photo FROM users WHERE id=1')->fetch_assoc()['profile_photo'];
    photoCheck($request('api/profile_photo.php',['action'=>'remove','csrf_token'=>'invalid'])['status']===403,'CSRF rejected');
    photoCheck($request('api/profile_photo.php',['action'=>'remove','csrf_token'=>$csrf,'user_id'=>2])['status']===403,'other target rejected');
    $bad=tempnam(sys_get_temp_dir(),'photo-bad-');$files[]=$bad;file_put_contents($bad,'<?php echo "unsafe"; ?><svg></svg>');
    foreach([['x.jpg',$bad,'image/jpeg'],['x.php',$samples['jpg'],'image/jpeg'],['x.svg',$bad,'image/svg+xml']]as[$name,$file,$mime])photoCheck($request('api/profile_photo.php',['action'=>'upload','csrf_token'=>$csrf,'photo'=>new CURLFile($file,$mime,$name)])['status']===422,'actual content and suffix validation '.$name);
    $bomb=tempnam(sys_get_temp_dir(),'photo-bomb-');$files[]=$bomb;$huge=imagecreatetruecolor(4097,1);imagepng($huge,$bomb);imagedestroy($huge);
    photoCheck($request('api/profile_photo.php',['action'=>'upload','csrf_token'=>$csrf,'photo'=>new CURLFile($bomb,'image/png','large.png')])['status']===422,'dimensions validated before decoding');
    file_put_contents($bad,str_repeat('x',5*1024*1024+1));photoCheck($request('api/profile_photo.php',['action'=>'upload','csrf_token'=>$csrf,'photo'=>new CURLFile($bad,'image/jpeg','big.jpg')])['status']>=400,'oversized upload rejected');
    photoCheck($db->query('SELECT profile_photo FROM users WHERE id=1')->fetch_assoc()['profile_photo']===$old&&is_file(profilePhotoDirectory().'/'.$old),'failed uploads keep saved image');
    $foreign=$db->query('SELECT * FROM users WHERE id=2')->fetch_assoc();$foreignUrl=profilePhotoUrl($foreign);
    photoCheck($request($foreignUrl)['status']===404,'resident cannot read another photo');
    photoCheck($request('profile_photo.php?user_id=1&v=../../.env')['status']===404,'unsafe media version rejected');

    $count=count(glob(profilePhotoDirectory().'/*.jpg'));
    $db->query("CREATE TRIGGER reject_fixture_photo BEFORE UPDATE ON users FOR EACH ROW BEGIN IF NEW.id=1 AND NOT(NEW.profile_photo <=> OLD.profile_photo) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Fixture write failure'; END IF; END");
    $failed=$request('api/profile_photo.php',['action'=>'upload','csrf_token'=>$csrf,'photo'=>new CURLFile($samples['jpg'],'image/jpeg','retry.jpg')]);
    photoCheck($failed['status']===503&&$db->query('SELECT profile_photo FROM users WHERE id=1')->fetch_assoc()['profile_photo']===$old&&count(glob(profilePhotoDirectory().'/*.jpg'))===$count,'failed DB write rolls back and removes newly encoded file');
    $db->query('DROP TRIGGER reject_fixture_photo');
    $marker='PROFILE_METADATA_MUST_BE_STRIPPED';file_put_contents($samples['jpg'],$marker,FILE_APPEND);
    $replacement=$request('api/profile_photo.php',['action'=>'upload','csrf_token'=>$csrf,'photo'=>new CURLFile($samples['jpg'],'image/jpeg','replace.jpg')]);$new=$db->query('SELECT profile_photo FROM users WHERE id=1')->fetch_assoc()['profile_photo'];$photos[]=$new;
    photoCheck($replacement['status']===200&&$new!==$old&&!is_file(profilePhotoDirectory().'/'.$old),'replacement commits then removes unused old file');
    photoCheck(!str_contains($request($replacement['json']['profile']['photo_url'])['body'],$marker),'reencoding strips trailing original payload');
    photoCheck($request($owner['photo_url'])['status']===404,'old version cannot return replacement');
    $removed=$request('api/profile_photo.php',['action'=>'remove','csrf_token'=>$csrf]);
    photoCheck($removed['status']===200&&$removed['json']['profile']['photo_url']===null&&!is_file(profilePhotoDirectory().'/'.$new),'remove restores initial and cleans file');
    $request('edit_profile.php?fixture_actor=5');photoCheck($request($foreignUrl)['status']===200,'authorized security can read identity photo');
    $request('edit_profile.php?fixture_actor=7');photoCheck($request($foreignUrl)['status']===404,'staff without identity review cannot read others');
    $request('api/profile_photo.php?fixture_actor=0');photoCheck($request($foreignUrl)['status']===401&&$request('api/profile_photo.php',['action'=>'remove','csrf_token'=>$csrf])['status']===401,'anonymous read and mutation rejected');
    $request('resident/edit_profile.php?fixture_actor=2');$db->query('UPDATE users SET is_active=0 WHERE id=2');photoCheck($request('api/profile_photo.php')['status']===401,'disabled live session rejected');
    echo 'Passed '.$checks." profile role, upload, privacy and HTTP checks.\n";
}finally{
    if(is_resource($http)){proc_terminate($http);proc_close($http);}
    if($log&&is_file($log))unlink($log);
    foreach($files??[]as$file)if(is_file($file))unlink($file);
    foreach($photos??[]as$photo)if(profilePhotoFilename($photo)&&is_file(profilePhotoDirectory().'/'.$photo))unlink(profilePhotoDirectory().'/'.$photo);
    if(!preg_match('/^condo_ui_test_[a-f0-9]{12}$/D',$database))throw new RuntimeException('Unsafe fixture cleanup.');
    $server->query("DROP DATABASE `$database`");
}
