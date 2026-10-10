<?php
/** Loopback-only UI fixture. Never runs against an application database. */
if (PHP_SAPI !== 'cli-server' || getenv('CONDO_UI_FIXTURE') !== '1' || !preg_match('/^condo_ui_test_[a-f0-9]{12}$/D',getenv('CONDO_DB_NAME') ?: '') || !in_array($_SERVER['REMOTE_ADDR'] ?? '',['127.0.0.1','::1'],true)) { http_response_code(404); exit; }
$root = dirname(__DIR__);
$path = rawurldecode((string)parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH));
$relative = substr($path,strlen('/CondoSystem3/'));
if (!str_starts_with($path,'/CondoSystem3/')) { http_response_code(404); exit; }
if ($relative === '__manifest') { header('Content-Type: application/json'); echo getenv('CONDO_UI_MANIFEST'); exit; }
$asset = preg_match('~^(?:assets/(?:css|js|vendor/gsap)/[a-zA-Z0-9./_-]+|js/[a-zA-Z0-9._-]+\.js|[a-zA-Z0-9_-]+\.css|(?:assets/(?:amenities|homepage)|IMAGES)/[a-zA-Z0-9 ._-]+)$~D',$relative);
if ($asset) {
    $file = realpath($root.'/'.$relative);
    if (!$file || !is_file($file) || !str_starts_with(str_replace('\\','/',$file),str_replace('\\','/',$root).'/')) { http_response_code(404); exit; }
    $extension = strtolower(pathinfo($file,PATHINFO_EXTENSION));
    $mime = ['css'=>'text/css','js'=>'application/javascript','jpg'=>'image/jpeg','jpeg'=>'image/jpeg','png'=>'image/png','webp'=>'image/webp','svg'=>'image/svg+xml','ico'=>'image/x-icon'][$extension] ?? (@getimagesize($file)['mime'] ?? null);
    if (!$mime) { http_response_code(404); exit; }
    header('Content-Type: '.$mime); readfile($file); exit;
}
$manifest = json_decode(getenv('CONDO_UI_MANIFEST') ?: '[]',true);
$allowed = array_unique(array_merge(array_map(static fn(array $entry):string => explode('?',$entry['path'],2)[0],$manifest),['api/dashboard.php','api/admin_dashboard.php','api/notifications.php','api/messages.php','api/admin_messages.php','api/amenity_bookings.php','api/scan_history.php','api/scan_history_export.php','login.php','logout.php','resident/dashboard.php']));
if (!in_array($relative,$allowed,true)) { http_response_code(404); exit; }
require_once $root.'/config.php';
$actor = filter_var($_GET['fixture_actor'] ?? $_SESSION['user_id'] ?? 0,FILTER_VALIDATE_INT);
if (!in_array($actor,[0,1,2,3,4,5,6,7,8],true)) { http_response_code(404); exit; }
// Explicit fixture navigation selects a guest. Preserve that guest session's
// CSRF token on subsequent form posts, just as the application does.
if ($actor === 0 && isset($_GET['fixture_actor'])) $_SESSION = [];
elseif ($actor !== 0) {
    $db = connectDb();
    $statement = $db->prepare('SELECT role,session_version,username FROM users WHERE id=?');
    $statement->bind_param('i',$actor); $statement->execute(); $user = $statement->get_result()->fetch_assoc();
    if (!$user) { http_response_code(404); exit; }
    $_SESSION = array_merge($_SESSION,['user_id'=>$actor,'role'=>$user['role'],'username'=>$user['username'],'session_version'=>(int)$user['session_version'],'last_activity'=>time()]);
}
$_SERVER['SCRIPT_NAME'] = $_SERVER['PHP_SELF'] = '/CondoSystem3/'.$relative;
$_SERVER['SCRIPT_FILENAME'] = $root.'/'.$relative;
chdir(dirname($root.'/'.$relative));
require $root.'/'.$relative;
