<?php
/** Disposable-database renderer; never serves a web request. */
if (PHP_SAPI!=='cli' || !preg_match('/^condo_payment_test_[a-f0-9]{12}$/D',getenv('CONDO_DB_NAME') ?: '')) {
    http_response_code(404); exit;
}
$page=$argv[1] ?? '';
if (!in_array($page,['resident/payments.php','superadmin/unitpayments.php','superadmin/generate_bills.php'],true)) exit(1);
$_SERVER['SCRIPT_NAME']='/CondoSystem3/'.$page; $_SERVER['PHP_SELF']=$_SERVER['SCRIPT_NAME'];
$_SERVER['SCRIPT_FILENAME']=dirname(__DIR__).'/'.$page; $_SERVER['HTTP_HOST']='localhost'; $_SERVER['REQUEST_METHOD']='GET';
require_once __DIR__.'/../config.php';
$staff=str_starts_with($page,'superadmin/');
$_SESSION=['user_id'=>$staff ? 5 : 1,'role'=>$staff ? 'superadmin' : 'resident',
    'username'=>'Fixture','session_version'=>0,'last_activity'=>time()];
if (isset($argv[2])) {
    $_POST=json_decode($argv[2],true,512,JSON_THROW_ON_ERROR);
    $_POST['csrf_token']=($argv[3] ?? '')==='invalid' ? 'invalid' : workflowCsrfToken();
    $_SERVER['REQUEST_METHOD']='POST';
}
chdir(dirname(__DIR__).'/'.dirname($page));
require dirname(__DIR__).'/'.$page;
