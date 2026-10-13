<?php
/** Short-lived opaque identity credentials. Never used as an entry/parking permit. */
const DIGITAL_ID_TTL = 60;

function ensureDigitalIdSchema(mysqli $db): bool {
    if (!schemaMutationAllowed()) return true;
    return $db->query("CREATE TABLE IF NOT EXISTS digital_id_credentials (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL, token_hash CHAR(64) NOT NULL,
        account_fingerprint CHAR(64) NOT NULL,
        issued_at DATETIME NOT NULL, expires_at DATETIME NOT NULL,
        revoked_at DATETIME DEFAULT NULL,
        UNIQUE KEY uq_digital_id_token (token_hash),
        INDEX ix_digital_id_user (user_id,revoked_at), INDEX ix_digital_id_expiry (expires_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4") === true;
}

function digitalIdNoStore(): void {
    header('Cache-Control: no-store, private, max-age=0');
    header('Pragma: no-cache');
    header('Expires: 0');
    header('Referrer-Policy: no-referrer');
    header('X-Frame-Options: DENY');
}

function digitalIdRoleLabel(array $user): ?string {
    if ($user['role'] === 'resident') {
        return match (residentAccountKind($user['account_type'] ?? null)) {
            'owner' => 'Unit Owner', 'tenant' => 'Tenant', 'occupant' => 'Authorized Occupant', default => null,
        };
    }
    return ['admin'=>'Admin','superadmin'=>'Super Admin','security'=>'Security Personnel',
        'treasurer'=>'Treasurer','maintenance'=>'Maintenance'][$user['role']] ?? null;
}

function digitalIdAccount(mysqli $db, int $id, bool $lock = false): ?array {
    $stmt = $db->prepare('SELECT id,full_name,role,account_type,unit_number,unit_owner_id,staff_id,resident_id,created_at,is_active,is_verified,status,session_version FROM users WHERE id=?' . ($lock ? ' FOR UPDATE' : ''));
    $stmt->bind_param('i', $id); $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    if ($user && $user['role'] === 'resident') {
        $user['_context'] = residentContext($db, $id);
        $ownerId = (int)($user['unit_owner_id'] ?? 0);
        if ($ownerId && $ownerId !== $id) {
            $stmt = $db->prepare('SELECT session_version,is_active,is_verified,status,unit_number,role,account_type FROM users WHERE id=?');
            $stmt->bind_param('i', $ownerId); $stmt->execute();
            $user['_owner_state'] = $stmt->get_result()->fetch_assoc();
        }
    }
    return $user;
}

function digitalIdAccountFailure(?array $user): ?string {
    if (!$user) return 'not_found';
    if ((int)$user['is_active'] !== 1) return 'suspended';
    if ((int)$user['is_verified'] !== 1 || $user['status'] !== 'approved' || digitalIdRoleLabel($user) === null) return 'unauthorized';
    if ($user['role'] === 'resident' && !($user['_context']['approved'] ?? false)) return 'unauthorized';
    return null;
}

function digitalIdFingerprint(array $user): string {
    $state = array_intersect_key($user, array_flip(['id','full_name','role','account_type','unit_number','unit_owner_id','staff_id','resident_id','session_version','is_active','is_verified','status','_owner_state']));
    // Changes to the existing tenant policy also invalidate previously issued IDs.
    foreach (['AMENITIES','MAINTENANCE','MESSAGES','VISITORS','PARKING','VEHICLES','PERMITS'] as $flag) $state[$flag] = appSetting('CONDO_TENANT_'.$flag, $flag === 'PERMITS' ? '0' : '1');
    return hash('sha256', json_encode($state, JSON_THROW_ON_ERROR));
}

function digitalIdProfile(array $user): array {
    $profile = ['name'=>$user['full_name'], 'role'=>digitalIdRoleLabel($user), 'status'=>'Verified',
        'member_since'=>date('M Y', strtotime($user['created_at']))];
    if ($user['role'] === 'resident' && trim((string)$user['unit_number']) !== '') $profile['unit_number'] = $user['unit_number'];
    if ($user['role'] !== 'resident' && trim((string)$user['staff_id']) !== '') $profile['staff_id'] = $user['staff_id'];
    // The current users schema has no profile photograph, tower or lease dates.
    // Do not manufacture these fields or reuse uploaded visitor identification.
    return $profile;
}

function digitalIdIssue(mysqli $db): array {
    if (!isLoggedIn()) throw new InvalidArgumentException('Sign in again to open your Digital Condo ID.');
    if (appIsProduction() && parse_url(buildUrl(''), PHP_URL_SCHEME) !== 'https') throw new InvalidArgumentException('Digital Condo ID requires the secure HTTPS site. Contact management.');
    ensureDigitalIdSchema($db);
    $id = (int)$_SESSION['user_id'];
    $db->begin_transaction();
    try {
        $user = digitalIdAccount($db, $id, true);
        if (digitalIdAccountFailure($user) !== null || (int)$user['session_version'] !== (int)$_SESSION['session_version']) throw new InvalidArgumentException('Your account is not currently eligible for a Digital Condo ID. Contact management.');
        $token = bin2hex(random_bytes(32));
        $hash = hash('sha256', $token); $fingerprint = digitalIdFingerprint($user);
        $issued = time(); $expires = $issued + DIGITAL_ID_TTL;
        $issuedAt = gmdate('Y-m-d H:i:s', $issued); $expiresAt = gmdate('Y-m-d H:i:s', $expires);
        $stmt = $db->prepare('UPDATE digital_id_credentials SET revoked_at=UTC_TIMESTAMP() WHERE user_id=? AND revoked_at IS NULL');
        $stmt->bind_param('i', $id); $stmt->execute();
        $stmt = $db->prepare('INSERT INTO digital_id_credentials(user_id,token_hash,account_fingerprint,issued_at,expires_at) VALUES(?,?,?,?,?)');
        $stmt->bind_param('issss', $id, $hash, $fingerprint, $issuedAt, $expiresAt); $stmt->execute();
        $db->commit();
    } catch (Throwable $error) { $db->rollback(); throw $error; }
    // Keep expired/revoked diagnostics for one day; audit snapshots use existing scan history.
    $db->query('DELETE FROM digital_id_credentials WHERE expires_at < UTC_TIMESTAMP()-INTERVAL 1 DAY LIMIT 500');
    return ['success'=>true, 'profile'=>digitalIdProfile($user),
        'qr_url'=>buildUrl('digital_id_verify.php').'#credential='.$token,
        'expires_at'=>$expires, 'server_time'=>microtime(true), 'ttl'=>DIGITAL_ID_TTL];
}

function digitalIdRevoke(mysqli $db): void {
    if (!isLoggedIn()) throw new InvalidArgumentException('Sign in again to revoke your QR code.');
    ensureDigitalIdSchema($db);
    $id = (int)$_SESSION['user_id'];
    // Same user-row lock as issuance serializes revocation and concurrent renewals.
    $db->begin_transaction();
    try {
        digitalIdAccount($db, $id, true);
        $stmt = $db->prepare('UPDATE digital_id_credentials SET revoked_at=UTC_TIMESTAMP() WHERE user_id=? AND revoked_at IS NULL');
        $stmt->bind_param('i', $id); $stmt->execute();
        $db->commit();
    } catch (Throwable $error) { $db->rollback(); throw $error; }
}

function digitalIdCanReview(): bool {
    return canAccess('security.gate') && digitalIdAccountFailure(digitalIdAccount(connectDb(), (int)$_SESSION['user_id'])) === null;
}

function digitalIdPrivileges(mysqli $db, array $user): array {
    $rights = [$user['role'] === 'resident' ? 'Approved residential account' : 'Active '.digitalIdRoleLabel($user).' account'];
    if ($user['role'] === 'resident') {
        foreach (['resident.amenities.book'=>'Amenity requests, subject to booking approval and payment',
            'resident.parking.request'=>'Visitor parking requests, subject to approval and slot assignment',
            'resident.vehicles.register'=>'Vehicle registration requests, subject to review'] as $permission=>$label) {
            if (residentUserHasPermission($db, (int)$user['id'], $permission)) $rights[] = $label;
        }
    } else $rights[] = 'Staff identification for the assigned role; restricted access requires separate authorization';
    return $rights;
}

/** Always re-read current account eligibility. Only authorized staff may request details. */
function digitalIdVerify(mysqli $db, string $token, bool $withIdentity = false): array {
    $failure = 'invalid'; $credential = null; $user = null;
    if (preg_match('/^[a-f0-9]{64}$/D', $token)) {
        ensureDigitalIdSchema($db);
        $hash = hash('sha256', $token);
        $stmt = $db->prepare('SELECT * FROM digital_id_credentials WHERE token_hash=?');
        $stmt->bind_param('s', $hash); $stmt->execute(); $credential = $stmt->get_result()->fetch_assoc();
        if ($credential) {
            if (strtotime($credential['expires_at'].' UTC') <= time()) $failure = 'expired';
            elseif ($credential['revoked_at'] !== null) $failure = 'revoked';
            else {
                $user = digitalIdAccount($db, (int)$credential['user_id']);
                $failure = digitalIdAccountFailure($user);
                if ($failure === null && !hash_equals($credential['account_fingerprint'], digitalIdFingerprint($user))) $failure = 'revoked';
            }
        }
    }
    if ($failure === null && strtotime($credential['expires_at'].' UTC') <= time()) $failure = 'expired';
    $status = $failure ?? 'valid';
    $message = match ($status) {
        'valid'=>'Verified Condominium Member', 'expired'=>'QR Code Expired', 'revoked'=>'Credential Revoked',
        'suspended'=>'Account Suspended', 'not_found'=>'Account Not Found', 'unauthorized'=>'Access Not Authorized', default=>'Invalid QR Code',
    };
    $result = ['recognized'=>true,'valid'=>$status === 'valid','verification_result'=>$status,'qr_type'=>'digital_id',
        'message'=>$message,'remarks'=>$message.'. Identity verification does not authorize physical entry.', 'server_time'=>microtime(true)];
    if ($credential) $result['expires_at'] = strtotime($credential['expires_at'].' UTC');
    if ($status === 'valid' && $withIdentity && digitalIdCanReview()) {
        $result += ['profile'=>digitalIdProfile($user),'privileges'=>digitalIdPrivileges($db,$user),
            'reference_id'=>(int)$user['id'], 'subject_name'=>$user['full_name'],
            'unit_number'=>$user['role'] === 'resident' ? $user['unit_number'] : '',
            'account_role'=>digitalIdRoleLabel($user), 'account_status'=>'Active and approved',
            'pass_number'=>$user['role'] === 'resident' ? ($user['resident_id'] ?? '') : ($user['staff_id'] ?? '')];
    }
    return $result;
}

function renderDigitalIdHead(): void {
    if (empty($_SESSION['user_id']) || !array_key_exists($_SESSION['role'] ?? '', getUserRoles())) return;
    $escape = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    echo '<meta name="digital-id-endpoint" content="'.$escape(buildUrl('api/digital_id.php')).'">';
    echo '<meta name="digital-id-csrf" content="'.$escape(workflowCsrfToken()).'">';
    echo '<link rel="stylesheet" href="'.$escape(portalUiAssetUrl('assets/css/digital-id.css')).'">';
    echo '<script defer src="'.$escape(portalUiAssetUrl('assets/vendor/qrcode/qrcode.min.js')).'"></script>';
    echo '<script defer src="'.$escape(portalUiAssetUrl('assets/js/digital-id.js')).'"></script>';
}
