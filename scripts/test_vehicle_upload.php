<?php
/** Exercise real multipart uploads and protected downloads on a loopback test server. */
function testVehicleUploadWorkflow(mysqli $db): void {
    if (!preg_match('/^condo_workflow_test_[a-f0-9]{12}$/D',getenv('CONDO_DB_NAME')?:'')) throw new RuntimeException('Upload tests require the disposable database.');
    $root=dirname(__DIR__);
    $router=tempnam(sys_get_temp_dir(),'condo_router_');
    $pdf=tempnam(sys_get_temp_dir(),'condo_pdf_');
    $photo=tempnam(sys_get_temp_dir(),'condo_photo_');
    $cookie=tempnam(sys_get_temp_dir(),'condo_cookie_');
    $log=tempnam(sys_get_temp_dir(),'condo_http_');
    $socket=stream_socket_server('tcp://127.0.0.1:0',$errno,$error);
    if (!$socket) throw new RuntimeException('Could not allocate loopback test port.');
    $port=(int)substr(strrchr(stream_socket_get_name($socket,false),':'),1); fclose($socket);
    $code='<?php if (!preg_match("/^condo_workflow_test_[a-f0-9]{12}$/D", getenv("CONDO_DB_NAME") ?: "")) { http_response_code(404); exit; } ';
    $code.='$path=parse_url($_SERVER["REQUEST_URI"],PHP_URL_PATH); if(!in_array($path,["/resident/vehicles.php","/vehicle_document.php"],true)){http_response_code(404);exit;} ';
    $code.='require_once '.var_export($root.'/config.php',true).'; $actor=($_GET["fixture_actor"]??1)==2?2:1; $_SESSION["user_id"]=$actor; $_SESSION["role"]="resident"; $_SESSION["username"]="Fixture Resident"; $_SESSION["session_version"]=0; $_SESSION["last_activity"]=time(); $_SESSION["unit_number"]="101"; ';
    $code.='$_SERVER["SCRIPT_FILENAME"]='.var_export($root,true).'.$path; $_SERVER["SCRIPT_NAME"]=$path; $_SERVER["PHP_SELF"]=$path; chdir(dirname($_SERVER["SCRIPT_FILENAME"])); require $_SERVER["SCRIPT_FILENAME"];';
    file_put_contents($router,$code);
    file_put_contents($pdf,"%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF\n");
    file_put_contents($photo,base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jwXcAAAAASUVORK5CYII='));
    $server=proc_open([PHP_BINARY,'-S','127.0.0.1:'.$port,'-t',$root,$router],[0=>['pipe','r'],1=>['file',$log,'a'],2=>['file',$log,'a']],$pipes);
    $uploadedPath=null; $uploadedPhoto=null; $curl=curl_init();
    try {
        curl_setopt_array($curl,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_COOKIEFILE=>$cookie,CURLOPT_COOKIEJAR=>$cookie,CURLOPT_CONNECTTIMEOUT=>1,CURLOPT_TIMEOUT=>10]);
        $base='http://127.0.0.1:'.$port;
        for($attempt=0;$attempt<50;$attempt++) {
            curl_setopt($curl,CURLOPT_URL,$base.'/resident/vehicles.php'); $html=curl_exec($curl);
            if ($html!==false && curl_getinfo($curl,CURLINFO_HTTP_CODE)===200) break;
            usleep(20000);
        }
        checkWorkflow(is_string($html) && preg_match('/name="csrf_token" value="([a-f0-9]+)"/',$html,$match)===1,'upload form provides session CSRF');
        curl_setopt_array($curl,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>['csrf_token'=>$match[1],'action'=>'register_vehicle','make'=>'Honda','model'=>'Civic','color'=>'Blue','year'=>'2025','plate_number'=>'HTTP900','or_cr'=>new CURLFile($pdf,'application/pdf','or-cr.pdf'),'vehicle_photo'=>new CURLFile($photo,'image/png','vehicle.png')]]);
        curl_exec($curl);
        checkWorkflow(curl_getinfo($curl,CURLINFO_HTTP_CODE)===302,'real multipart vehicle submission redirects after success');
        $vehicle=$db->query("SELECT * FROM vehicles WHERE normalized_plate='HTTP900'")->fetch_assoc();
        checkWorkflow($vehicle && $vehicle['status']==='pending','real upload creates pending vehicle');
        $uploadedPath=$vehicle['or_cr_path'];
        $uploadedPhoto=$vehicle['vehicle_photo_path'];
        checkWorkflow(is_file($root.'/'.$uploadedPath) && $vehicle['or_cr_mime']==='application/pdf','uploaded OR/CR persisted privately with MIME');
        checkWorkflow($uploadedPhoto && is_file($root.'/'.$uploadedPhoto) && $vehicle['photo_mime']==='image/png','optional vehicle photo saved from a successful upload');
        curl_setopt_array($curl,[CURLOPT_HTTPGET=>true,CURLOPT_URL=>$base.'/vehicle_document.php?vehicle_id='.$vehicle['id'].'&document=or_cr']);
        $document=curl_exec($curl);
        checkWorkflow(curl_getinfo($curl,CURLINFO_HTTP_CODE)===200 && str_starts_with($document,'%PDF-1.4'),'owner can view protected OR/CR');
        curl_setopt($curl,CURLOPT_URL,$base.'/vehicle_document.php?vehicle_id='.$vehicle['id'].'&document=photo'); $image=curl_exec($curl);
        checkWorkflow(curl_getinfo($curl,CURLINFO_HTTP_CODE)===200 && str_starts_with($image,"\x89PNG"),'owner can view protected vehicle photo');
        curl_setopt($curl,CURLOPT_URL,$base.'/vehicle_document.php?vehicle_id='.$vehicle['id'].'&document=or_cr&fixture_actor=2'); curl_exec($curl);
        checkWorkflow(curl_getinfo($curl,CURLINFO_HTTP_CODE)===404,'another resident cannot download ownership document');
        checkWorkflow(storeVehicleDocument(['error'=>UPLOAD_ERR_NO_FILE],'or_cr')['error']!=='','missing upload rejected');
    } finally {
        curl_close($curl);
        if(is_resource($server)){proc_terminate($server);fclose($pipes[0]);proc_close($server);}
        removeUnusedVehicleUpload($uploadedPath);
        removeUnusedVehicleUpload($uploadedPhoto);
        foreach([$router,$pdf,$photo,$cookie,$log] as $file) if(is_file($file)) unlink($file);
    }
}
