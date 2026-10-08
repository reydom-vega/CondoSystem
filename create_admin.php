<?php
/** CLI-only provisioning. Passwords are read from stdin, never command args. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$usage = "Usage: php create_admin.php <username> <email> --password-stdin [--full-name=Name] [--role=superadmin] [--update]\n"
    . "Password is read from the first stdin line. Use a protected input source.\n"
    . "Default role is superadmin. Updating an existing staff account requires --update.\n"
    . "Existing resident accounts cannot be converted by this tool.\n";
if ($argc === 1 || ($argv[1] ?? '') === '--help') { echo $usage; exit; }
if ($argc < 4) { fwrite(STDERR, $usage); exit(2); }
$username = trim($argv[1]); $email = trim($argv[2]);
$role = 'superadmin'; $fullName = 'System Administrator'; $updateExisting = false; $stdinPassword = false;
foreach (array_slice($argv,3) as $option) {
    if ($option === '--password-stdin') $stdinPassword = true;
    elseif ($option === '--update') $updateExisting = true;
    elseif (str_starts_with($option,'--role=')) $role = strtolower(trim(substr($option,7)));
    elseif (str_starts_with($option,'--full-name=')) $fullName = trim(substr($option,12));
    else { fwrite(STDERR, "Unknown option. Password command arguments are no longer supported.\n" . $usage); exit(2); }
}
if (!$stdinPassword) { fwrite(STDERR, "Use --password-stdin.\n" . $usage); exit(2); }
$line = fgets(STDIN,1026);
$password = $line === false ? '' : rtrim($line,"\r\n");
$db = null;
try {
    require_once __DIR__ . '/config.php';
    $roles = getUserRoles();
    if (!preg_match('/^[a-zA-Z0-9_]{3,20}$/D',$username)) throw new InvalidArgumentException('Username must be 3-20 letters, numbers or underscores.');
    if (!filter_var($email,FILTER_VALIDATE_EMAIL) || strlen($email)>100) throw new InvalidArgumentException('Provide a valid email of up to 100 characters.');
    if ($fullName === '' || strlen($fullName)>100) throw new InvalidArgumentException('Provide a full name of up to 100 characters.');
    if (!isset($roles[$role]) || $role === 'resident') throw new InvalidArgumentException('Role must be admin, superadmin, treasurer, maintenance or security.');
    if (strlen($password)>128 || !isPasswordStrong($password)) throw new InvalidArgumentException('Password must be 8-128 characters with uppercase, lowercase, a number and a special character.');
    $hash = password_hash($password,PASSWORD_DEFAULT);
    unset($password,$line);
    $db = connectDb();
    if (!ensureRememberTokensTable($db) || !ensureAuditLogTable($db)) throw new RuntimeException('Account storage is unavailable. Run migrations first.');
    $db->begin_transaction();
    $find = $db->prepare('SELECT id,username,email,role FROM users WHERE username=? OR email=? FOR UPDATE');
    $find->bind_param('ss',$username,$email); $find->execute();
    $matches = $find->get_result()->fetch_all(MYSQLI_ASSOC);
    if (count($matches)>1) throw new InvalidArgumentException('The username and email identify different accounts. No changes made.');
    $existing = $matches[0] ?? null;
    if ($existing) {
        if (!$updateExisting) throw new InvalidArgumentException('An account already exists. Use --update to explicitly update an existing staff account.');
        if ($existing['role'] === 'resident') throw new InvalidArgumentException('Resident accounts cannot be converted to staff by this tool.');
        if (strcasecmp($existing['username'],$username)!==0 || strcasecmp($existing['email'],$email)!==0) throw new InvalidArgumentException('Both username and email must match the existing staff account.');
        $id = (int)$existing['id'];
        $change = $db->prepare("UPDATE users SET full_name=?,password_hash=?,role=?,is_verified=1,is_active=1,status='approved',
            staff_id=COALESCE(NULLIF(staff_id,''),CONCAT('STAFF-',LPAD(id,5,'0'))),
            failed_login_attempts=0,locked_until=NULL,verification_token=NULL,reset_token=NULL,reset_expires=NULL,
            session_version=session_version+1 WHERE id=?");
        $change->bind_param('sssi',$fullName,$hash,$role,$id); $change->execute();
        $revoke = $db->prepare('DELETE FROM remember_tokens WHERE user_id=?');
        $revoke->bind_param('i',$id); $revoke->execute();
        $action = 'update';
    } else {
        if ($updateExisting) throw new InvalidArgumentException('No existing staff account matches. Omit --update to create a new account.');
        $contact = 'STAFF';
        $insert = $db->prepare("INSERT INTO users (full_name,username,email,contact_number,unit_number,password_hash,is_verified,is_active,status,role) VALUES (?,?,?,?,NULL,?,1,1,'approved',?)");
        $insert->bind_param('ssssss',$fullName,$username,$email,$contact,$hash,$role); $insert->execute();
        $id = (int)$db->insert_id;
        $staffId = 'STAFF-' . str_pad((string)$id,5,'0',STR_PAD_LEFT);
        $assign = $db->prepare('UPDATE users SET staff_id=? WHERE id=?');
        $assign->bind_param('si',$staffId,$id); $assign->execute();
        $action = 'create';
    }
    $details = 'CLI provisioning: ' . $roles[$role] . ' account; existing sessions revoked on update.';
    $audit = $db->prepare("INSERT INTO audit_logs (admin_id,admin_name,admin_role,action,entity_type,entity_id,details,ip_address) VALUES (NULL,'CLI provisioning','system',?,'staff',?,?,'')");
    $audit->bind_param('sis',$action,$id,$details); $audit->execute();
    $db->commit();
    echo $roles[$role] . ' account ' . ($action === 'create' ? 'created' : 'updated') . '. Username: ' . $username . PHP_EOL;
} catch (Throwable $e) {
    if ($db instanceof mysqli) $db->rollback();
    fwrite(STDERR,$e instanceof InvalidArgumentException ? $e->getMessage() . PHP_EOL : "Provisioning failed. Check private server logs and migration readiness.\n");
    if (!$e instanceof InvalidArgumentException) error_log('Staff provisioning: ' . $e->getMessage());
    exit(1);
}
