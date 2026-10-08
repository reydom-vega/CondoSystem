<?php
function ensureVehiclesTable(mysqli $db): bool {
    if (!schemaMutationAllowed()) return true;
    return $db->query("CREATE TABLE IF NOT EXISTS vehicles (
        id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL,
        make VARCHAR(80) NOT NULL, model VARCHAR(100) NOT NULL, color VARCHAR(50) NOT NULL,
        year SMALLINT NOT NULL, plate_number VARCHAR(20) NOT NULL, normalized_plate VARCHAR(20) NOT NULL,
        or_cr_path VARCHAR(255) NOT NULL, or_cr_mime VARCHAR(100) NOT NULL,
        vehicle_photo_path VARCHAR(255) DEFAULT NULL, photo_mime VARCHAR(100) DEFAULT NULL,
        parking_slot_id INT DEFAULT NULL,
        status ENUM('pending','approved','rejected','inactive') NOT NULL DEFAULT 'pending',
        rejection_reason VARCHAR(255) DEFAULT NULL, decided_by INT DEFAULT NULL, decided_at DATETIME DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX(user_id,status), INDEX(normalized_plate),
        FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4") === true;
}
function normalizePlateNumber(string $plate): string {
    return preg_replace('/[^A-Z0-9]/', '', strtoupper(trim($plate)));
}
function hasVehiclePlateConflict(mysqli $db, string $plate, int $exceptId = 0): bool {
    $normalized = normalizePlateNumber($plate);
    $stmt = $db->prepare("SELECT id FROM vehicles WHERE normalized_plate = ? AND id <> ? AND status IN ('pending','approved') LIMIT 1");
    $stmt->bind_param('si', $normalized, $exceptId); $stmt->execute();
    return $stmt->get_result()->num_rows > 0;
}
function storeVehicleDocument(array $upload, string $kind): array {
    $failure = ['path'=>null,'mime'=>null,'error'=>'Upload a valid ' . ($kind === 'or_cr' ? 'OR/CR document' : 'vehicle photo') . ' of up to 5 MB.'];
    if (($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($upload['tmp_name'] ?? '') || filesize($upload['tmp_name']) > 5 * 1024 * 1024 || filesize($upload['tmp_name']) < 1) return $failure;
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($upload['tmp_name']);
    $allowed = $kind === 'or_cr' ? ['application/pdf'=>'pdf','image/jpeg'=>'jpg','image/png'=>'png'] : ['image/jpeg'=>'jpg','image/png'=>'png'];
    if (!isset($allowed[$mime])) return $failure;
    $dir = dirname(__DIR__) . '/private_uploads/vehicle_documents';
    if (!is_dir($dir) && !mkdir($dir,0700,true)) return $failure;
    // Apache must never serve ownership documents directly.
    if (!file_exists($dir . '/.htaccess') && file_put_contents($dir . '/.htaccess', "Require all denied\n") === false) return $failure;
    $file = bin2hex(random_bytes(24)) . '.' . $allowed[$mime];
    if (!move_uploaded_file($upload['tmp_name'],$dir . '/' . $file)) return $failure;
    return ['path'=>'private_uploads/vehicle_documents/' . $file,'mime'=>$mime,'error'=>''];
}
function removeUnusedVehicleUpload(?string $path): void {
    if ($path === null || !preg_match('~^private_uploads/vehicle_documents/[a-f0-9]{48}\.(?:pdf|jpg|png)$~D',$path)) return;
    $full = dirname(__DIR__) . '/' . $path;
    if (is_file($full)) unlink($full);
}
function createVehicle(mysqli $db, int $userId, string $make, string $model, string $color, int $year, string $plate, string $orCr, string $orCrMime, ?string $photo = null, ?string $photoMime = null): int {
    if (!residentUserHasPermission($db,$userId,'resident.vehicles.register')) throw new InvalidArgumentException('Vehicle registration is disabled for this resident account.');
    $make = trim($make); $model = trim($model); $color = trim($color); $plate = strtoupper(trim($plate));
    $normalized = normalizePlateNumber($plate);
    if ($make === '' || strlen($make)>80 || $model === '' || strlen($model)>100 || $color === '' || strlen($color)>50 || $year<1900 || $year>(int)date('Y')+1 || $normalized === '' || strlen($plate)>20 || $orCr === '') throw new InvalidArgumentException('Enter valid make, model, color, year, plate number, and OR/CR.');
    $lockName = 'vehicle:' . sha1($normalized);
    $lock = $db->prepare('SELECT GET_LOCK(?,5) AS acquired'); $lock->bind_param('s',$lockName); $lock->execute();
    if ((int)$lock->get_result()->fetch_assoc()['acquired'] !== 1) throw new InvalidArgumentException('Vehicle registration is busy. Please try again.');
    try {
        $owner = $db->prepare("SELECT id FROM users WHERE id = ? AND role = 'resident' AND status = 'approved' AND is_active = 1");
        $owner->bind_param('i',$userId); $owner->execute();
        if (!$owner->get_result()->fetch_assoc()) throw new InvalidArgumentException('An approved resident account is required.');
        if (hasVehiclePlateConflict($db,$plate)) throw new InvalidArgumentException('This plate already has a pending or approved registration.');
        $stmt = $db->prepare('INSERT INTO vehicles (user_id,make,model,color,year,plate_number,normalized_plate,or_cr_path,or_cr_mime,vehicle_photo_path,photo_mime) VALUES (?,?,?,?,?,?,?,?,?,?,?)');
        $stmt->bind_param('isssissssss',$userId,$make,$model,$color,$year,$plate,$normalized,$orCr,$orCrMime,$photo,$photoMime);
        if (!$stmt->execute()) throw new RuntimeException('Could not register the vehicle.');
        return (int)$db->insert_id;
    } finally { $release=$db->prepare('SELECT RELEASE_LOCK(?)'); $release->bind_param('s',$lockName); $release->execute(); }
}
function decideVehicle(mysqli $db, int $id, string $decision, string $notes): bool {
    if (!canReviewPermits() || !in_array($decision,['approved','rejected'],true) || strlen($notes)>255 || ($decision === 'rejected' && trim($notes) === '')) return false;
    $actor=(int)$_SESSION['user_id'];
    $stmt=$db->prepare("UPDATE vehicles SET status=?, rejection_reason=?, decided_by=?, decided_at=NOW() WHERE id=? AND status='pending'");
    $stmt->bind_param('ssii',$decision,$notes,$actor,$id);
    return $stmt->execute() && $stmt->affected_rows===1;
}
function getVehiclesForResident(mysqli $db, int $userId): array {
    $stmt=$db->prepare('SELECT v.*, u.full_name, u.unit_number FROM vehicles v JOIN users u ON u.id=v.user_id WHERE v.user_id=? ORDER BY v.created_at DESC,v.id DESC');
    $stmt->bind_param('i',$userId); $stmt->execute(); return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}
function getAllVehicles(mysqli $db): array {
    return $db->query('SELECT v.*, u.full_name, u.unit_number FROM vehicles v JOIN users u ON u.id=v.user_id ORDER BY v.created_at DESC,v.id DESC')->fetch_all(MYSQLI_ASSOC);
}
function ensureStickerVehicleLinks(mysqli $db): bool {
    if (!schemaMutationAllowed()) return true;
    return ensureVehiclesTable($db) && ensureParkingStickerOrdersTable($db) && $db->query("CREATE TABLE IF NOT EXISTS parking_sticker_vehicles (
        id INT AUTO_INCREMENT PRIMARY KEY, order_id INT NOT NULL, vehicle_id INT NOT NULL,
        sticker_number VARCHAR(40) DEFAULT NULL UNIQUE,
        UNIQUE KEY ux_sticker_vehicle(vehicle_id), INDEX(order_id),
        FOREIGN KEY(order_id) REFERENCES parking_sticker_orders(id) ON DELETE CASCADE,
        FOREIGN KEY(vehicle_id) REFERENCES vehicles(id) ON DELETE RESTRICT
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4") === true;
}
function getStickerVehicles(mysqli $db, int $orderId): array {
    $stmt=$db->prepare('SELECT v.id,v.user_id,v.make,v.model,v.color,v.year,v.plate_number,v.status,v.parking_slot_id,ps.slot_code,ps.level,sv.sticker_number FROM parking_sticker_vehicles sv JOIN vehicles v ON v.id=sv.vehicle_id LEFT JOIN parking_slots ps ON ps.id=v.parking_slot_id WHERE sv.order_id=? ORDER BY v.id');
    $stmt->bind_param('i',$orderId); $stmt->execute(); return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

function stickerOrderSponsorUserId(mysqli $db, int $orderUserId): ?int {
    $context=residentContext($db,$orderUserId);
    return $context && $context['approved'] && $context['billing_user_id'] ? ($context['account_kind']==='tenant' ? $orderUserId : (int)$context['billing_user_id']) : null;
}

function stickerSponsorUserIds(mysqli $db, int $sponsorId): array {
    $context=residentContext($db,$sponsorId);
    if (!$context || !$context['approved']) return [];
    if ($context['account_kind']==='tenant') return [$sponsorId];
    if ($context['account_kind']!=='owner') return [];
    return residentBillingUserIds($db,$sponsorId);
}

/** Lock current relationships at allocation/issuance; same-unit text never sponsors a vehicle. */
function stickerVehicleBelongsToSponsor(mysqli $db, int $sponsorId, int $vehicleUserId, bool $lock = false): bool {
    if ($sponsorId<1 || $vehicleUserId<1) return false;
    if (!$lock) return in_array($vehicleUserId,stickerSponsorUserIds($db,$sponsorId),true);
    $ids=array_values(array_unique([$sponsorId,$vehicleUserId])); sort($ids);
    $rows=$db->query('SELECT id,role,account_type,unit_number,unit_owner_id,status,is_active,is_verified FROM users WHERE id IN (' . implode(',',$ids) . ') ORDER BY id FOR UPDATE')->fetch_all(MYSQLI_ASSOC);
    $accounts=[]; foreach ($rows as $row) $accounts[(int)$row['id']]=$row;
    $owner=$accounts[$sponsorId] ?? null; $vehicleOwner=$accounts[$vehicleUserId] ?? null;
    $approved=static fn(?array $account): bool => $account && $account['role']==='resident' && $account['status']==='approved' && (int)$account['is_active']===1 && (int)$account['is_verified']===1 && normalizeUnitNumber($account['unit_number'])!=='';
    if (!$approved($owner) || !$approved($vehicleOwner)) return false;
    if (residentAccountKind($owner['account_type'])==='tenant') {
        if ($sponsorId!==$vehicleUserId) return false;
        $linked=$db->prepare('SELECT id,role,account_type,unit_number,unit_owner_id,status,is_active,is_verified FROM users WHERE id=? FOR UPDATE');
        $linked->bind_param('i',$owner['unit_owner_id']); $linked->execute(); $unitOwner=$linked->get_result()->fetch_assoc();
        return $approved($unitOwner) && residentAccountKind($unitOwner['account_type'])==='owner' && normalizeUnitNumber($unitOwner['unit_number'])===normalizeUnitNumber($owner['unit_number']);
    }
    if (residentAccountKind($owner['account_type'])!=='owner') return false;
    if ($sponsorId===$vehicleUserId) return true;
    return in_array(residentAccountKind($vehicleOwner['account_type']),['tenant','occupant'],true)
        && (int)$vehicleOwner['unit_owner_id']===$sponsorId
        && normalizeUnitNumber($vehicleOwner['unit_number'])===normalizeUnitNumber($owner['unit_number']);
}

function getEligibleStickerVehicles(mysqli $db, int $userId): array {
    $users=stickerSponsorUserIds($db,$userId);
    if (!$users) return [];
    return $db->query("SELECT v.id,v.user_id,v.make,v.model,v.color,v.year,v.plate_number,v.status FROM vehicles v LEFT JOIN parking_sticker_vehicles sv ON sv.vehicle_id=v.id WHERE v.user_id IN (" . implode(',',$users) . ") AND v.status='approved' AND sv.id IS NULL ORDER BY v.plate_number")->fetch_all(MYSQLI_ASSOC);
}
/** Must run within the order transaction; vehicle rows and a unique key serialize allocation. */
function linkStickerVehicles(mysqli $db, int $orderId, int $userId, int $quantity, array $vehicleIds): bool {
    $ids=array_values(array_unique(array_map('intval',$vehicleIds))); sort($ids);
    if (count($ids)!==$quantity || !$ids || min($ids)<1) return false;
    $find=$db->prepare('SELECT o.user_id,o.quantity,p.status AS payment_status FROM parking_sticker_orders o JOIN payments p ON p.id=o.bill_payment_id AND p.user_id=o.user_id WHERE o.id=? FOR UPDATE');
    $find->bind_param('i',$orderId); $find->execute(); $order=$find->get_result()->fetch_assoc();
    if (!$order || (int)$order['quantity']!==$quantity || stickerOrderSponsorUserId($db,(int)$order['user_id'])!==$userId) return false;
    if (!stickerVehicleBelongsToSponsor($db,$userId,(int)$order['user_id'],true)) return false;
    // Existing paid tenant orders retain their original bill owner and amounts.
    // Management can bind their vehicles using the current approved unit owner.
    if ((int)$order['user_id']!==$userId && (!canReviewPermits() || $order['payment_status']!=='paid')) return false;
    foreach ($ids as $vehicleId) {
        $vehicle=$db->prepare("SELECT id,user_id FROM vehicles WHERE id=? AND status='approved' FOR UPDATE");
        $vehicle->bind_param('i',$vehicleId); $vehicle->execute(); $registered=$vehicle->get_result()->fetch_assoc();
        if (!$registered || !stickerVehicleBelongsToSponsor($db,$userId,(int)$registered['user_id'],true)) return false;
        $existing=$db->prepare('SELECT id FROM parking_sticker_vehicles WHERE vehicle_id=?');
        $existing->bind_param('i',$vehicleId); $existing->execute(); if ($existing->get_result()->fetch_assoc()) return false;
        $insert=$db->prepare('INSERT INTO parking_sticker_vehicles (order_id,vehicle_id) VALUES (?,?)');
        $insert->bind_param('ii',$orderId,$vehicleId); if (!$insert->execute()) return false;
    }
    return true;
}
