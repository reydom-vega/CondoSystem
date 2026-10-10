<?php
/** Authentication regression checks never touch the configured application database. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__) . '/includes/environment.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$server = new mysqli(appSetting('CONDO_DB_HOST', 'localhost'), appSetting('CONDO_DB_USER', 'root'), appSetting('CONDO_DB_PASS'));
$database = 'condo_auth_test_' . bin2hex(random_bytes(6));
$server->query("CREATE DATABASE `{$database}` CHARACTER SET utf8mb4");
putenv('CONDO_DB_NAME=' . $database);
putenv('CONDO_APP_ENV=local'); putenv('CONDO_AUTO_MIGRATE=0');
putenv('CONDO_APP_URL=http://localhost/CondoSystem3');
$checks = 0;
function authCheck(bool $condition, string $description): void {
    global $checks;
    if (!$condition) throw new RuntimeException('FAILED: ' . $description);
    $checks++;
}
function authProcess(array $args): array {
    $process = proc_open(array_merge([PHP_BINARY], $args), [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']], $pipes, dirname(__DIR__));
    fclose($pipes[0]); $out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]); return [proc_close($process), $out, $err];
}
function authActor(int $id, int $version = 0): void {
    $_SESSION = ['user_id'=>$id,'role'=>'superadmin','session_version'=>$version,'last_activity'=>time()];
}
try {
    $server->select_db($database);
    $server->multi_query(file_get_contents(dirname(__DIR__) . '/database.sql'));
    do { $result = $server->store_result(); if ($result) $result->free(); } while ($server->more_results() && $server->next_result());
    [$status, $out, $err] = authProcess(['scripts/migrate.php','--apply']);
    authCheck($status === 0, 'authentication schema migrated: ' . $err);
    $_SERVER['HTTP_HOST'] = 'malicious.example';
    $_SERVER['REMOTE_ADDR'] = '127.0.0.42';
    $_SERVER['PHP_SELF'] = '/CondoSystem3/scripts/test_authentication.php';
    $_SERVER['SCRIPT_NAME'] = $_SERVER['PHP_SELF']; $_SERVER['SCRIPT_FILENAME'] = __FILE__;
    require_once dirname(__DIR__) . '/config.php';
    $db = connectDb();
    $password = 'Fixture@12345'; $hash = password_hash($password, PASSWORD_DEFAULT);
    $stmt = $db->prepare("INSERT INTO users (id,full_name,username,email,contact_number,unit_number,password_hash,is_verified,status,role) VALUES (1,'Fixture Resident','fixture','fixture@example.invalid','09171234567','0101',?,1,'approved','resident'),(2,'Fixture Security','guard','guard@example.invalid','09171234568',NULL,?,1,'approved','security')");
    $stmt->bind_param('ss', $hash, $hash); $stmt->execute();
    authCheck(!schemaMutationAllowed(), 'managed runtime has no automatic schema mutations');
    authCheck(buildUrl('reset_password.php') === 'http://localhost/CondoSystem3/reset_password.php', 'canonical URL ignores untrusted Host');
    authCheck(authenticateCredentials($db,'fixture',$password)['id'] === 1, 'valid credentials authenticate');
    authCheck(authenticateCredentials($db,'fixture@example.invalid',$password)['id'] === 1, 'email login authenticates');
    authCheck(authenticateCredentials($db,'unknown',$password) === null, 'unknown account returns no session');
    for ($i = 0; $i < 5; $i++) authCheck(authenticateCredentials($db,'fixture','wrong') === null, 'failed login counted ' . ($i+1));
    authCheck(authenticateCredentials($db,'fixture',$password) === null, 'locked account rejects correct password');
    $db->query("UPDATE users SET locked_until=DATE_SUB(NOW(), INTERVAL 1 MINUTE) WHERE id=1");
    authCheck(authenticateCredentials($db,'fixture',$password)['id'] === 1, 'expired lock can recover with correct password');
    $db->query('UPDATE users SET is_active=0 WHERE id=1');
    authCheck(authenticateCredentials($db,'fixture',$password) === null, 'inactive account rejects login');
    $db->query('UPDATE users SET is_active=1 WHERE id=1');
    authActor(1);
    authCheck(isLoggedIn() && $_SESSION['role'] === 'resident' && $_SESSION['unit_number'] === '0101', 'DB role replaces stale session privileges');
    authCheck(!canAccess('billing.manage'), 'stale superadmin session cannot access finance');
    $db->query("UPDATE users SET role='security' WHERE id=1");
    authCheck(isLoggedIn() && canAccess('security.gate') && !canAccess('units.manage'), 'role edits apply during existing session');
    $db->query("UPDATE users SET role='resident' WHERE id=1");
    $token = issuePasswordResetToken($db, 1);
    $stored = $db->query('SELECT reset_token FROM users WHERE id=1')->fetch_assoc()['reset_token'];
    authCheck($stored !== $token && $stored === passwordResetDigest($token), 'only reset digest stored');
    authCheck(findPasswordResetUser($db, $token)['id'] === 1, 'valid reset recognized');
    authCheck(findPasswordResetUser($db, str_repeat('f',64)) === null, 'forged reset rejected');
    $db->query('UPDATE users SET reset_expires=DATE_SUB(NOW(), INTERVAL 1 SECOND) WHERE id=1');
    authCheck(!completePasswordReset($db,$token,'NewPassword@123'), 'expired reset refused');
    $token = issuePasswordResetToken($db, 1);
    $db->query("INSERT INTO remember_tokens (user_id,selector,validator_hash,expires_at,session_version) VALUES (1,'fixture','fixture',DATE_ADD(NOW(),INTERVAL 1 DAY),0)");
    authCheck(completePasswordReset($db, $token, 'NewPassword@123'), 'password reset succeeds atomically');
    authCheck(!completePasswordReset($db, $token, 'OtherPassword@123'), 'reset link is single use');
    authCheck((int)$db->query('SELECT COUNT(*) AS n FROM remember_tokens WHERE user_id=1')->fetch_assoc()['n'] === 0, 'reset revokes persistent tokens');
    authCheck(!isLoggedIn(), 'reset revokes old active session version');
    authCheck(authenticateCredentials($db,'fixture','NewPassword@123')['id'] === 1, 'new password works');
    $db->query("UPDATE users SET phone_otp='" . hash('sha256','123456') . "',phone_otp_expires=DATE_ADD(NOW(),INTERVAL 10 MINUTE),phone_otp_attempts=0 WHERE id=1");
    authCheck(!verifyPhoneOtp(1, '000000'), 'incorrect OTP refused');
    authCheck(verifyPhoneOtp(1, '123456') && !verifyPhoneOtp(1, '123456'), 'phone OTP atomically consumed');
    $db->query("UPDATE users SET phone_otp='" . hash('sha256','123456') . "',phone_otp_expires=DATE_ADD(NOW(),INTERVAL 10 MINUTE),phone_otp_attempts=5 WHERE id=1");
    authCheck(!verifyPhoneOtp(1,'123456'), 'OTP guess limit blocks even matching code');
    $selector = bin2hex(random_bytes(12)); $validator = bin2hex(random_bytes(32)); $validatorHash = hash('sha256',$validator);
    $insert = $db->prepare('INSERT INTO remember_tokens (user_id,selector,validator_hash,expires_at,session_version) VALUES (1,?,?,DATE_ADD(NOW(),INTERVAL 1 DAY),0)');
    $insert->bind_param('ss',$selector,$validatorHash); $insert->execute();
    $_SESSION=[]; $_COOKIE[REMEMBER_COOKIE_NAME]=$selector . ':' . $validator;
    attemptAutoLogin();
    authCheck(empty($_SESSION['user_id']), 'persistent token cannot bypass session revocation');
    $selector = bin2hex(random_bytes(12)); $validator = bin2hex(random_bytes(32)); $validatorHash = hash('sha256',$validator);
    $insert = $db->prepare('INSERT INTO remember_tokens (user_id,selector,validator_hash,expires_at,session_version) VALUES (1,?,?,DATE_ADD(NOW(),INTERVAL 1 DAY),1)');
    $insert->bind_param('ss',$selector,$validatorHash); $insert->execute();
    $_COOKIE[REMEMBER_COOKIE_NAME]=$selector . ':' . $validator;
    attemptAutoLogin();
    authCheck(($_SESSION['user_id'] ?? 0) === 1 && ($_SESSION['role'] ?? '') === 'resident', 'valid remember token restores current role');
    $_SESSION=[];
    attemptAutoLogin();
    authCheck(empty($_SESSION['user_id']), 'consumed remember token cannot be replayed');
    $_SERVER['REMOTE_ADDR']='127.0.0.43';
    authCheck(allowAuthenticationRequest($db,'fixture_limit',1) && !allowAuthenticationRequest($db,'fixture_limit',1), 'anonymous rate bucket blocks excess attempts');
    authCheck(session_get_cookie_params()['httponly'] && session_get_cookie_params()['samesite'] === 'Lax', 'session cookie protections configured');
    authCheck(ini_get('session.use_strict_mode') === '1', 'strict session IDs configured');
    // An HTTP server exercises the real login form, CSRF guard, redirect and session cookie.
    $root=dirname(__DIR__); $router=tempnam(sys_get_temp_dir(),'condo_auth_router_');
    $cookie=tempnam(sys_get_temp_dir(),'condo_auth_cookie_'); $log=tempnam(sys_get_temp_dir(),'condo_auth_http_');
    $socket=stream_socket_server('tcp://127.0.0.1:0',$errno,$error);
    $port=(int)substr(strrchr(stream_socket_get_name($socket,false),':'),1); fclose($socket);
    file_put_contents($router,'<?php if (!preg_match("/^condo_auth_test_[a-f0-9]{12}$/D",getenv("CONDO_DB_NAME")?:"")) { http_response_code(404); exit; } $path=parse_url($_SERVER["REQUEST_URI"],PHP_URL_PATH); if(!in_array($path,["/login.php","/logout.php","/resident/edit_profile.php"],true)){http_response_code(404);exit;} $_SERVER["SCRIPT_FILENAME"]=' . var_export($root,true) . '.$path; $_SERVER["SCRIPT_NAME"]=$path; $_SERVER["PHP_SELF"]=$path; chdir(dirname($_SERVER["SCRIPT_FILENAME"])); require $_SERVER["SCRIPT_FILENAME"];');
    $http=proc_open([PHP_BINARY,'-S','127.0.0.1:'.$port,'-t',$root,$router],[0=>['pipe','r'],1=>['file',$log,'a'],2=>['file',$log,'a']],$pipes);
    $curl=curl_init();
    try {
        curl_setopt_array($curl,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_COOKIEFILE=>$cookie,CURLOPT_COOKIEJAR=>$cookie,CURLOPT_TIMEOUT=>10]);
        $base='http://127.0.0.1:'.$port;
        for($i=0;$i<50;$i++) { curl_setopt($curl,CURLOPT_URL,$base.'/login.php'); $html=curl_exec($curl); if($html!==false && curl_getinfo($curl,CURLINFO_HTTP_CODE)===200) break; usleep(20000); }
        authCheck(is_string($html) && preg_match('/name="csrf_token" value="([a-f0-9]+)"/',$html,$match)===1, 'real login provides CSRF token');
        curl_setopt_array($curl,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>http_build_query(['identifier'=>'fixture','password'=>'NewPassword@123'])]);
        curl_exec($curl); authCheck(curl_getinfo($curl,CURLINFO_HTTP_CODE)===403, 'real login rejects missing CSRF');
        curl_setopt($curl,CURLOPT_POSTFIELDS,http_build_query(['csrf_token'=>$match[1],'identifier'=>'fixture','password'=>'NewPassword@123']));
        curl_exec($curl); authCheck(curl_getinfo($curl,CURLINFO_HTTP_CODE)===302, 'real login redirects on correct credentials');
        curl_setopt_array($curl,[CURLOPT_HTTPGET=>true,CURLOPT_URL=>$base.'/resident/edit_profile.php']); $profileHtml=curl_exec($curl);
        authCheck(curl_getinfo($curl,CURLINFO_HTTP_CODE)===200 && preg_match('/name="csrf_token" value="([a-f0-9]+)"/',$profileHtml,$profileMatch)===1,'real profile form supplies CSRF');
        $profile=['action'=>'update_profile','full_name'=>'Updated Fixture','email'=>'updated@example.invalid','contact_number'=>'09171234567','current_password'=>'wrong'];
        curl_setopt_array($curl,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>http_build_query($profile)]); curl_exec($curl);
        authCheck(curl_getinfo($curl,CURLINFO_HTTP_CODE)===403,'profile update rejects missing CSRF');
        $profile['csrf_token']=$profileMatch[1]; curl_setopt($curl,CURLOPT_POSTFIELDS,http_build_query($profile)); $profileHtml=curl_exec($curl);
        authCheck(str_contains($profileHtml,'Enter your current password') && $db->query('SELECT email FROM users WHERE id=1')->fetch_assoc()['email']==='fixture@example.invalid','email change requires current password');
        $profile['current_password']='NewPassword@123'; curl_setopt($curl,CURLOPT_POSTFIELDS,http_build_query($profile)); curl_exec($curl);
        $after=$db->query('SELECT email,full_name,session_version FROM users WHERE id=1')->fetch_assoc();
        authCheck($after['email']==='updated@example.invalid' && $after['full_name']==='Updated Fixture' && (int)$after['session_version']===2,'profile email update commits and revokes other sessions');
        $db->query("CREATE TRIGGER reject_fixture_password BEFORE UPDATE ON users FOR EACH ROW BEGIN IF NEW.password_hash<>OLD.password_hash THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Injected profile update failure'; END IF; END");
        $profile['full_name']='Rolled Back Name'; $profile['new_password']='FailurePassword@123'; $profile['confirm_password']=$profile['new_password'];
        curl_setopt($curl,CURLOPT_POSTFIELDS,http_build_query($profile)); curl_exec($curl);
        authCheck(curl_getinfo($curl,CURLINFO_HTTP_CODE)===503 && $db->query('SELECT full_name FROM users WHERE id=1')->fetch_assoc()['full_name']==='Updated Fixture','failed password write rolls back entire profile update');
        $db->query('DROP TRIGGER reject_fixture_password');
        curl_setopt_array($curl,[CURLOPT_HTTPGET=>true,CURLOPT_URL=>$base.'/logout.php']); $html=curl_exec($curl);
        authCheck(curl_getinfo($curl,CURLINFO_HTTP_CODE)===200 && preg_match('/name="csrf_token" value="([a-f0-9]+)"/',$html,$match)===1, 'logout GET offers protected confirmation');
        authCheck(str_contains($html,'href="resident/dashboard.php"') && str_contains($html,'Return to dashboard'), 'approved resident can return to their dashboard from sign out');
        foreach (['pending','rejected'] as $applicationStatus) {
            $db->query("UPDATE users SET status='$applicationStatus' WHERE id=1");
            $html=curl_exec($curl);
            authCheck(curl_getinfo($curl,CURLINFO_HTTP_CODE)===200 && str_contains($html,'href="signuppending.php"') && str_contains($html,'Return to application status') && !str_contains($html,'Return to dashboard'), $applicationStatus.' resident returns to application status from sign out');
        }
        $db->query("UPDATE users SET role='security',status='approved' WHERE id=1");
        $html=curl_exec($curl);
        authCheck(str_contains($html,'href="security/security_dashboard.php"'), 'staff sign-out return keeps its role dashboard');
        $db->query("UPDATE users SET role='resident' WHERE id=1");
        curl_setopt($curl,CURLOPT_URL,$base.'/login.php'); curl_exec($curl);
        authCheck(curl_getinfo($curl,CURLINFO_HTTP_CODE)===302, 'GET logout does not mutate session');
        curl_setopt_array($curl,[CURLOPT_URL=>$base.'/logout.php',CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>http_build_query(['csrf_token'=>$match[1]])]); curl_exec($curl);
        authCheck(curl_getinfo($curl,CURLINFO_HTTP_CODE)===302, 'logout POST clears session');
        curl_setopt_array($curl,[CURLOPT_HTTPGET=>true,CURLOPT_URL=>$base.'/login.php']); curl_exec($curl);
        authCheck(curl_getinfo($curl,CURLINFO_HTTP_CODE)===200, 'logged out user returns to sign in');
        // Browser-managed password saving uses the same protected login handler.
        $html=curl_exec($curl); preg_match('/name="csrf_token" value="([a-f0-9]+)"/',$html,$match);
        curl_setopt_array($curl,[CURLOPT_HTTPHEADER=>['Accept: application/json'],CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>http_build_query(['identifier'=>'fixture','password'=>'NewPassword@123','remember_me'=>'1'])]);
        curl_exec($curl);
        authCheck(curl_getinfo($curl,CURLINFO_HTTP_CODE)===403, 'password-manager login cannot bypass CSRF');
        $login=['csrf_token'=>$match[1],'identifier'=>'fixture','password'=>'WrongFixture!123','remember_me'=>'1'];
        curl_setopt($curl,CURLOPT_POSTFIELDS,http_build_query($login)); $body=curl_exec($curl);$result=json_decode($body,true);
        authCheck(curl_getinfo($curl,CURLINFO_HTTP_CODE)===422 && !empty($result['errors']) && empty($result['save_credentials']), 'incorrect password never requests browser credential storage');
        authCheck(!str_contains($body,$login['password']), 'authentication response never echoes a password');
        $login['password']='NewPassword@123'; curl_setopt($curl,CURLOPT_POSTFIELDS,http_build_query($login));$body=curl_exec($curl);$result=json_decode($body,true);
        authCheck(curl_getinfo($curl,CURLINFO_HTTP_CODE)===200 && $result['save_credentials']===true && $result['redirect']==='resident/dashboard.php', 'verified remembered login can ask the browser to save credentials');
        authCheck(!str_contains($body,$login['password']) && str_contains(curl_getinfo($curl,CURLINFO_CONTENT_TYPE),'application/json'), 'successful credential response contains no password');
        authCheck((int)$db->query('SELECT COUNT(*) AS n FROM remember_tokens WHERE user_id=1')->fetch_assoc()['n']===1, 'remembered browser login still issues a persistent token');
        curl_setopt($curl,CURLOPT_POSTFIELDS,http_build_query($login));$result=json_decode(curl_exec($curl),true);
        authCheck($result['save_credentials']===false, 'existing session does not claim an unverified password was authenticated');
        curl_setopt_array($curl,[CURLOPT_HTTPHEADER=>[],CURLOPT_HTTPGET=>true,CURLOPT_URL=>$base.'/logout.php']);$html=curl_exec($curl);preg_match('/name="csrf_token" value="([a-f0-9]+)"/',$html,$match);
        curl_setopt_array($curl,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>http_build_query(['csrf_token'=>$match[1]])]);curl_exec($curl);
        authCheck(curl_getinfo($curl,CURLINFO_HTTP_CODE)===302 && (int)$db->query('SELECT COUNT(*) AS n FROM remember_tokens WHERE user_id=1')->fetch_assoc()['n']===0, 'logout still revokes the server token independently of browser saved passwords');
        curl_setopt_array($curl,[CURLOPT_HTTPGET=>true,CURLOPT_URL=>$base.'/login.php']);$html=curl_exec($curl);preg_match('/name="csrf_token" value="([a-f0-9]+)"/',$html,$match);
        $login['csrf_token']=$match[1];unset($login['remember_me']);
        curl_setopt_array($curl,[CURLOPT_HTTPHEADER=>['Accept: application/json'],CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>http_build_query($login)]);$result=json_decode(curl_exec($curl),true);
        authCheck(curl_getinfo($curl,CURLINFO_HTTP_CODE)===200 && $result['save_credentials']===false, 'unchecked Remember Me does not request browser credential storage');
    } finally {
        curl_close($curl); proc_terminate($http); fclose($pipes[0]); proc_close($http);
        foreach ([$router,$cookie,$log] as $file) if (is_file($file)) unlink($file);
    }
    echo "Passed {$checks} authentication checks in a disposable database.\n";
} finally {
    if (!preg_match('/\Acondo_auth_test_[a-f0-9]{12}\z/', $database)) throw new RuntimeException('Unsafe cleanup target.');
    $server->query("DROP DATABASE `{$database}`");
}
