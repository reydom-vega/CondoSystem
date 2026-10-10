<?php
/** Loopback-only disposable-database HTTP fixture, not a production endpoint. */
if (PHP_SAPI!=='cli-server' || !preg_match('/^condo_gate_test_[a-f0-9]{12}$/D',getenv('CONDO_DB_NAME') ?: '') || !in_array($_SERVER['REMOTE_ADDR'] ?? '',['127.0.0.1','::1'],true)) { http_response_code(404); exit; }
$path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);
$root=dirname(__DIR__);
$assets=['/CondoSystem3/amenities.css','/CondoSystem3/js/amenities.js','/CondoSystem3/services.css','/CondoSystem3/resident.css','/CondoSystem3/styles.css','/CondoSystem3/security.css','/CondoSystem3/scanner.css','/CondoSystem3/js/qr-scanner.js','/CondoSystem3/js/permit-requests.js','/CondoSystem3/js/services-menu.js','/CondoSystem3/js/profile-menu.js','/CondoSystem3/js/notification-menu.js'];
if (in_array($path,$assets,true)) {
    $file=$root.substr($path,strlen('/CondoSystem3'));
    header('Content-Type: '.(str_ends_with($file,'.css')?'text/css':'application/javascript')); readfile($file); exit;
}
if(str_starts_with($path,'/CondoSystem3/assets/amenities/')) {
    $file=realpath($root.'/assets/amenities/'.basename($path));
    $size=$file && is_file($file) && dirname($file)===realpath($root.'/assets/amenities')?@getimagesize($file):false;
    if(!$size) { http_response_code(404); exit; }
    header('Content-Type: '.$size['mime']); readfile($file); exit;
}
$routes=['/CondoSystem3/resident/book_amenity.php'=>'resident/book_amenity.php','/CondoSystem3/superadmin/bookingrequest.php'=>'superadmin/bookingrequest.php','/CondoSystem3/api/amenity_bookings.php'=>'api/amenity_bookings.php','/CondoSystem3/resident/permits.php'=>'resident/permits.php','/CondoSystem3/superadmin/service_requests.php'=>'superadmin/service_requests.php','/CondoSystem3/resident_service_pass.php'=>'resident_service_pass.php','/CondoSystem3/permit_document.php'=>'permit_document.php','/CondoSystem3/security/scanner.php'=>'security/scanner.php','/CondoSystem3/superadmin/scanner.php'=>'superadmin/scanner.php','/CondoSystem3/api/scan_history.php'=>'api/scan_history.php','/CondoSystem3/api/scan_history_export.php'=>'api/scan_history_export.php'];
if (!isset($routes[$path])) { http_response_code(404); exit; }
require_once $root.'/config.php';
$id=filter_var($_GET['fixture_actor'] ?? $_SESSION['user_id'] ?? 2,FILTER_VALIDATE_INT);
if (!in_array($id,[1,2,3,4,5,6],true)) { http_response_code(404); exit; }
$db=connectDb(); $stmt=$db->prepare('SELECT role,session_version FROM users WHERE id=?'); $stmt->bind_param('i',$id); $stmt->execute(); $actor=$stmt->get_result()->fetch_assoc();
$_SESSION=array_merge($_SESSION,['user_id'=>$id,'role'=>$actor['role'],'username'=>'Gate Fixture','session_version'=>(int)$actor['session_version'],'last_activity'=>time()]);
chdir(dirname($root.'/'.$routes[$path])); require $root.'/'.$routes[$path];
