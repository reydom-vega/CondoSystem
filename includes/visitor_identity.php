<?php
/** Identity documents never use the application's web-accessible upload folders. */
const VISITOR_ID_TYPES = ['National ID', "Driver's License", 'Passport', 'School ID', 'Company ID', 'Other Valid ID'];
const VISITOR_ID_MAX_BYTES = 5 * 1024 * 1024;

function ensureVisitorIdentitySchema(mysqli $db): bool {
    if (!schemaMutationAllowed()) return true;
    $columns = [
        'identity_status' => "ENUM('pending','verified','rejected') NOT NULL DEFAULT 'pending'",
        'id_type' => 'VARCHAR(30) DEFAULT NULL', 'id_number_last4' => 'VARCHAR(4) DEFAULT NULL',
        'id_document_name' => 'VARCHAR(64) DEFAULT NULL', 'id_document_mime' => 'VARCHAR(20) DEFAULT NULL',
        'id_uploaded_at' => 'DATETIME DEFAULT NULL', 'identity_reviewed_by' => 'INT DEFAULT NULL',
        'identity_reviewed_at' => 'DATETIME DEFAULT NULL', 'identity_review_note' => 'VARCHAR(500) DEFAULT NULL',
        'id_deleted_at' => 'DATETIME DEFAULT NULL',
    ];
    foreach ($columns as $name => $definition) {
        if (!$db->query("SHOW COLUMNS FROM resident_service_requests LIKE '$name'")->num_rows
            && !$db->query("ALTER TABLE resident_service_requests ADD `$name` $definition")) return false;
    }
    if (!$db->query("SHOW INDEX FROM resident_service_requests WHERE Key_name='ix_visitor_identity'")->num_rows) {
        return $db->query('ALTER TABLE resident_service_requests ADD INDEX ix_visitor_identity (request_kind,identity_status,end_date)');
    }
    return true;
}

function visitorIdRetentionDays(): int {
    $days = filter_var(appSetting('CONDO_VISITOR_ID_RETENTION_DAYS', '30'), FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 1, 'max_range' => 365]]);
    if ($days === false) throw new RuntimeException('Visitor ID retention must be between 1 and 365 days.');
    return $days;
}

function visitorPathWithin(string $path, string $root): bool {
    $path = str_replace('\\', '/', $path); $root = rtrim(str_replace('\\', '/', $root), '/');
    if (PHP_OS_FAMILY === 'Windows') { $path = strtolower($path); $root = strtolower($root); }
    return $path === $root || str_starts_with($path, $root . '/');
}

function visitorIdStorageDirectory(bool $create = false): string {
    $appRoot = dirname(__DIR__);
    $default = dirname($appRoot, 2) . '/condo_private/' . substr(hash('sha256', $appRoot), 0, 16) . '/visitor_ids';
    $directory = appSetting('CONDO_VISITOR_ID_STORAGE') ?: $default;
    if (!preg_match('~^(?:[A-Za-z]:[/\\\\]|/)~', $directory)) throw new RuntimeException('Visitor ID storage must be an absolute private path.');
    if (in_array('..', explode('/', str_replace('\\', '/', $directory)), true)) throw new RuntimeException('Visitor ID storage cannot contain parent path segments.');
    // Check an existing ancestor before creating anything, including symlink targets.
    $ancestor = $directory;
    while (!file_exists($ancestor) && dirname($ancestor) !== $ancestor) $ancestor = dirname($ancestor);
    $resolvedAncestor = realpath($ancestor);
    foreach ([$appRoot, dirname($appRoot), $_SERVER['DOCUMENT_ROOT'] ?? ''] as $publicRoot) {
        if ($publicRoot && ($resolvedRoot = realpath($publicRoot)) && $resolvedAncestor && visitorPathWithin($resolvedAncestor, $resolvedRoot)) {
            throw new RuntimeException('Visitor ID storage must be outside the public document root.');
        }
    }
    if ($create && !is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) throw new RuntimeException('Private ID storage is unavailable.');
    $resolved = realpath($directory);
    if (!$resolved || !is_dir($resolved)) throw new RuntimeException('Private ID storage is unavailable.');
    return $resolved;
}

function visitorIdFilePath(string $name): ?string {
    if (!preg_match('/^[a-f0-9]{48}\.(?:jpg|png|webp)$/D', $name)) return null;
    $root = visitorIdStorageDirectory();
    $path = realpath($root . '/' . $name);
    return $path && visitorPathWithin($path, $root) && is_file($path) ? $path : null;
}

function removeVisitorIdFile(?string $name): bool {
    if (!$name) return true;
    if (!preg_match('/^[a-f0-9]{48}\.(?:jpg|png|webp)$/D', $name)) return false;
    try { $path = visitorIdFilePath($name); } catch (RuntimeException $e) { return false; }
    return !$path || unlink($path);
}

function visitorIdentityInput(array $input): array {
    $type = $input['id_type'] ?? '';
    $number = $input['id_number'] ?? '';
    if (!is_string($type) || !in_array($type, VISITOR_ID_TYPES, true)) throw new InvalidArgumentException('Choose a valid ID type.');
    if (!is_string($number) || mb_strlen(trim($number)) > 80 || preg_match('/[\x00-\x1F\x7F]/', $number)) throw new InvalidArgumentException('Enter a valid optional ID number (up to 80 characters).');
    return ['type' => $type, 'last4' => trim($number) === '' ? null : mb_substr(trim($number), -4)];
}

function storeVisitorId(array $upload): array {
    $tmp = $upload['tmp_name'] ?? null;
    if (($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_string($tmp) || !is_uploaded_file($tmp)) {
        throw new InvalidArgumentException('A valid ID image is required. Upload a JPG, PNG or WEBP up to 5 MB.');
    }
    $size = filesize($tmp);
    if (!$size || $size > VISITOR_ID_MAX_BYTES) throw new InvalidArgumentException('The ID image must be no larger than 5 MB.');
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($tmp);
    $types = ['image/jpeg' => ['jpg', IMAGETYPE_JPEG], 'image/png' => ['png', IMAGETYPE_PNG], 'image/webp' => ['webp', IMAGETYPE_WEBP]];
    $image = @getimagesize($tmp);
    $extension = strtolower(pathinfo((string)($upload['name'] ?? ''), PATHINFO_EXTENSION));
    if (!isset($types[$mime]) || !$image || $image[2] !== $types[$mime][1]
        || !in_array($extension, $mime === 'image/jpeg' ? ['jpg','jpeg'] : [$types[$mime][0]], true)
        || $image[0] < 1 || $image[1] < 1 || $image[0] > 12000 || $image[1] > 12000 || $image[0] * $image[1] > 40000000) {
        throw new InvalidArgumentException('Upload an actual JPG, PNG or WEBP image with a matching filename.');
    }
    // Reject truncated containers; MIME and dimensions alone accept partial headers.
    $bytes = file_get_contents($tmp);
    $complete = match ($mime) {
        'image/jpeg' => str_starts_with($bytes, "\xff\xd8") && str_ends_with($bytes, "\xff\xd9"),
        'image/png' => str_starts_with($bytes, "\x89PNG\r\n\x1a\n") && str_ends_with($bytes, "\x00\x00\x00\x00IEND\xaeB`\x82"),
        'image/webp' => substr($bytes, 0, 4) === 'RIFF' && substr($bytes, 8, 4) === 'WEBP' && unpack('Vsize', substr($bytes, 4, 4))['size'] + 8 === $size,
        default => false,
    };
    if (!$complete) throw new InvalidArgumentException('The ID image is incomplete or damaged. Choose another image.');
    $directory = visitorIdStorageDirectory(true);
    $name = bin2hex(random_bytes(24)) . '.' . $types[$mime][0];
    if (!move_uploaded_file($tmp, $directory . '/' . $name)) throw new RuntimeException('Could not save the ID image.');
    @chmod($directory . '/' . $name, 0600);
    return ['name' => $name, 'mime' => $mime];
}

function visitorIdentityVerified(array $request): bool {
    return ($request['identity_status'] ?? '') === 'verified' && !empty($request['id_uploaded_at'])
        && !empty($request['id_document_name']) && empty($request['id_deleted_at']);
}

/** A linked parking request is another approval; cancelled parking means walking in. */
function visitorAccessApprovalsComplete(mysqli $db, array $request, bool $lock = false): bool {
    if (!visitorIdentityVerified($request)) return false;
    $stmt = $db->prepare("SELECT status FROM parking_requests WHERE visitor_registration_id=? ORDER BY id DESC LIMIT 1" . ($lock ? ' FOR UPDATE' : ''));
    $stmt->bind_param('i', $request['id']); $stmt->execute(); $parking = $stmt->get_result()->fetch_assoc();
    return !$parking || in_array($parking['status'], ['approved','cancelled'], true);
}

function visitorPassVehicle(mysqli $db, int $id): ?array {
    $stmt = $db->prepare("SELECT vehicle_plate,vehicle_description,status FROM parking_requests WHERE visitor_registration_id=? ORDER BY id DESC LIMIT 1");
    $stmt->bind_param('i', $id); $stmt->execute();
    $vehicle = $stmt->get_result()->fetch_assoc();
    return $vehicle && $vehicle['status'] !== 'cancelled' ? $vehicle : null;
}

function visitorIdExpired(array $request): bool {
    return $request['end_date'] < (new DateTimeImmutable('today'))->modify('-' . visitorIdRetentionDays() . ' days')->format('Y-m-d');
}

function visitorIdentityVersion(array $request): string {
    return hash('sha256', ($request['id_document_name'] ?? '') . '|' . ($request['id_uploaded_at'] ?? ''));
}

function reviewVisitorIdentity(mysqli $db, int $id, string $decision, string $note, ?string $expectedVersion = null): bool {
    if (!canAccess('visitors.identity.review') || !in_array($decision, ['verified','rejected'], true)
        || mb_strlen($note) > 500 || ($decision === 'rejected' && trim($note) === '')) return false;
    ensureAuditLogTable($db);
    $db->begin_transaction();
    try {
        $stmt = $db->prepare("SELECT * FROM resident_service_requests WHERE id=? AND request_kind='visitor' FOR UPDATE");
        $stmt->bind_param('i', $id); $stmt->execute(); $row = $stmt->get_result()->fetch_assoc();
        if (!$row || !in_array($row['status'], ['pending','approved'], true) || $row['end_date'] < date('Y-m-d')
            || empty($row['id_document_name']) || !empty($row['id_deleted_at']) || visitorIdExpired($row)
            || !visitorIdFilePath($row['id_document_name']) || $row['identity_status'] === $decision
            || ($expectedVersion !== null && !hash_equals(visitorIdentityVersion($row), $expectedVersion))) { $db->rollback(); return false; }
        $actor = (int)$_SESSION['user_id'];
        $token = $decision === 'rejected' ? bin2hex(random_bytes(32)) : $row['access_token'];
        $stmt = $db->prepare('UPDATE resident_service_requests SET identity_status=?,identity_review_note=?,identity_reviewed_by=?,identity_reviewed_at=UTC_TIMESTAMP(),access_token=? WHERE id=?');
        $stmt->bind_param('ssisi', $decision, $note, $actor, $token, $id); $stmt->execute();
        if (!logAudit($decision, 'visitor_identity', $id, 'Visitor identification reviewed.', $db)) throw new RuntimeException('Identity audit could not be saved.');
        $db->commit(); return true;
    } catch (Throwable $e) { $db->rollback(); error_log('Visitor identity review failed: ' . $e->getMessage()); return false; }
}

function replaceVisitorIdentity(mysqli $db, int $id, array $input, array $files): void {
    requireResidentPermission('resident.visitors.register');
    $identity = visitorIdentityInput($input); $upload = null; $oldName = null;
    ensureAuditLogTable($db);
    $db->begin_transaction();
    try {
        $actor = (int)$_SESSION['user_id'];
        $stmt = $db->prepare("SELECT * FROM resident_service_requests WHERE id=? AND user_id=? AND request_kind='visitor' FOR UPDATE");
        $stmt->bind_param('ii', $id, $actor); $stmt->execute(); $row = $stmt->get_result()->fetch_assoc();
        if (!$row || !in_array($row['status'], ['pending','approved'], true) || $row['end_date'] < date('Y-m-d')) throw new InvalidArgumentException('This visitor ID can no longer be updated.');
        $upload = storeVisitorId($files['valid_id'] ?? []); $oldName = $row['id_document_name'];
        $token = bin2hex(random_bytes(32));
        $stmt = $db->prepare("UPDATE resident_service_requests SET id_type=?,id_number_last4=?,id_document_name=?,id_document_mime=?,id_uploaded_at=UTC_TIMESTAMP(),identity_status='pending',identity_reviewed_by=NULL,identity_reviewed_at=NULL,identity_review_note=NULL,id_deleted_at=NULL,access_token=? WHERE id=?");
        $stmt->bind_param('sssssi', $identity['type'], $identity['last4'], $upload['name'], $upload['mime'], $token, $id); $stmt->execute();
        if (!logAudit('resubmit', 'visitor_identity', $id, 'Replacement ID submitted for review.', $db)) throw new RuntimeException('Identity audit could not be saved.');
        $db->commit();
    } catch (Throwable $e) { $db->rollback(); if ($upload) removeVisitorIdFile($upload['name']); throw $e; }
    if ($oldName && !removeVisitorIdFile($oldName)) error_log('Could not delete replaced visitor ID for request ' . $id);
}

/** CLI-only retention worker. Locks coordinate with replacement and review. */
function purgeExpiredVisitorIds(mysqli $db, bool $apply = false): array {
    if (PHP_SAPI !== 'cli') throw new RuntimeException('Run retention from the command line.');
    $cutoff = (new DateTimeImmutable('today'))->modify('-' . visitorIdRetentionDays() . ' days')->format('Y-m-d');
    $stmt = $db->prepare("SELECT id FROM resident_service_requests WHERE request_kind='visitor' AND end_date < ? AND (id_document_name IS NOT NULL OR id_number_last4 IS NOT NULL) ORDER BY id");
    $stmt->bind_param('s', $cutoff); $stmt->execute(); $ids = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $deleted = 0;
    if ($apply) foreach ($ids as $item) {
        $db->begin_transaction();
        try {
            $stmt = $db->prepare('SELECT * FROM resident_service_requests WHERE id=? FOR UPDATE');
            $stmt->bind_param('i', $item['id']); $stmt->execute(); $row = $stmt->get_result()->fetch_assoc();
            if (!$row || !visitorIdExpired($row)) { $db->rollback(); continue; }
            if (!removeVisitorIdFile($row['id_document_name'])) throw new RuntimeException('Could not delete expired visitor ID.');
            $stmt = $db->prepare('UPDATE resident_service_requests SET id_document_name=NULL,id_document_mime=NULL,id_number_last4=NULL,id_deleted_at=UTC_TIMESTAMP() WHERE id=?');
            $stmt->bind_param('i', $item['id']); $stmt->execute(); $db->commit(); $deleted++;
        } catch (Throwable $e) { $db->rollback(); throw $e; }
    }
    // Cascading account deletion or a crash must not leave unreferenced ID files.
    $referenced = [];
    $rows = $db->query('SELECT id_document_name FROM resident_service_requests WHERE id_document_name IS NOT NULL');
    while ($row = $rows->fetch_assoc()) $referenced[$row['id_document_name']] = true;
    $orphans = 0; $orphansDeleted = 0;
    $directory = visitorIdStorageDirectory();
    foreach (new DirectoryIterator($directory) as $file) {
        $name = $file->getFilename();
        if (!$file->isFile() || $file->isLink() || !preg_match('/^[a-f0-9]{48}\.(?:jpg|png|webp)$/D', $name)
            || isset($referenced[$name]) || $file->getMTime() > time() - 86400) continue;
        $orphans++;
        if ($apply) { if (!removeVisitorIdFile($name)) throw new RuntimeException('Could not remove an orphan ID.'); $orphansDeleted++; }
    }
    return ['eligible' => count($ids), 'deleted' => $deleted, 'orphans' => $orphans, 'orphans_deleted' => $orphansDeleted];
}
