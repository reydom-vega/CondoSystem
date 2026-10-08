<?php
// CLI-only fixture renderer for the disposable-database integration suite.
if (PHP_SAPI !== 'cli' || !preg_match('/^condo_workflow_test_[a-f0-9]{12}$/D', getenv('CONDO_DB_NAME') ?: '')) {
    http_response_code(404); exit;
}
$page = $argv[1] ?? '';
$allowed = ['resident/visitors.php','resident/permits.php','resident/parking.php','resident/vehicles.php','resident/book_amenity.php','security/scanner.php','superadmin/service_requests.php','superadmin/registeredvehicles.php','superadmin/parking_configuration.php','superadmin/parking.php','superadmin/bookingrequest.php','resident_service_pass.php'];
if (!in_array($page, $allowed, true)) exit(1);
$_SERVER['PHP_SELF'] = '/CondoSystem3/' . $page;
$_SERVER['SCRIPT_NAME'] = $_SERVER['PHP_SELF'];
$_SERVER['SCRIPT_FILENAME'] = dirname(__DIR__) . '/' . $page;
$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['REQUEST_METHOD'] = 'GET';
require_once __DIR__ . '/../config.php';
$staff = str_starts_with($page,'superadmin/');
$security = str_starts_with($page,'security/');
$_SESSION = ['user_id'=>$staff ? 4 : ($security ? 3 : 1),'role'=>$staff ? 'superadmin' : ($security ? 'security' : 'resident'),'username'=>'Fixture User','unit_number'=>'101','last_activity'=>time(),'session_version'=>0];
if (isset($argv[2])) {
    $posted = json_decode($argv[2], true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($posted)) exit(1);
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_POST = $posted;
    $_POST['csrf_token'] = ($argv[3] ?? '') === 'invalid' ? 'invalid' : workflowCsrfToken();
}
if ($page === 'resident_service_pass.php') {
    $db = connectDb();
    $pass = $db->query("SELECT id, access_token FROM resident_service_requests WHERE request_kind = 'permit' LIMIT 1")->fetch_assoc();
    $_GET = ['id'=>$pass['id'],'token'=>$pass['access_token']];
}
chdir(dirname(__DIR__) . '/' . dirname($page));
require dirname(__DIR__) . '/' . $page;
