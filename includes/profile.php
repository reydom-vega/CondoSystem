<?php
/** Shared self-service profile presentation and private, raster-only photos. */
function profilePhotoSchemaReady(mysqli $db, bool $refresh = false): bool {
    static $ready;
    if ($ready === null || $refresh) $ready = $db->query("SHOW COLUMNS FROM users LIKE 'profile_photo'")->num_rows > 0;
    return $ready;
}
function ensureProfilePhotoSchema(mysqli $db): bool {
    if (profilePhotoSchemaReady($db)) return true;
    if (!schemaMutationAllowed()) return false;
    $db->query('ALTER TABLE users ADD profile_photo VARCHAR(80) DEFAULT NULL');
    return profilePhotoSchemaReady($db, true);
}
function profileInitial(string $name): string {
    $name = preg_replace('/^[\s\p{Z}\p{C}]+/u', '', $name) ?? '';
    return $name === '' ? '?' : mb_strtoupper(mb_substr($name, 0, 1, 'UTF-8'), 'UTF-8');
}
function profileEditUrl(): string { return buildUrl(($_SESSION['role'] ?? '') === 'resident' ? 'resident/edit_profile.php' : 'edit_profile.php'); }
function profileRoleLabel(array $user): string {
    return digitalIdRoleLabel($user) ?? 'Account';
}
function profilePhotoDirectory(bool $create = false): string {
    $directory = dirname(__DIR__).'/private_uploads/profile_photos';
    if ($create && !is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) throw new RuntimeException('Profile photo storage is unavailable.');
    return $directory;
}
function profilePhotoFilename(?string $filename): bool { return preg_match('/\A[a-f0-9]{48}\.jpg\z/D', (string)$filename) === 1; }
function profilePhotoUrl(array $user): ?string {
    $filename = $user['profile_photo'] ?? null;
    if (!profilePhotoFilename($filename) || !is_file(profilePhotoDirectory().'/'.$filename)) return null;
    return buildUrl('profile_photo.php?user_id='.(int)$user['id'].'&v='.hash('sha256', $filename));
}
function profileCurrent(?mysqli $db = null, bool $refresh = false): ?array {
    $id = (int)($_SESSION['user_id'] ?? 0);
    if (!$id) return null;
    if (!$refresh && isset($GLOBALS['profileCurrentCache'][$id])) return $GLOBALS['profileCurrentCache'][$id];
    $db ??= connectDb();
    $photo = profilePhotoSchemaReady($db) ? 'profile_photo' : 'NULL AS profile_photo';
    $stmt = $db->prepare('SELECT id,full_name,username,role,account_type,unit_number,staff_id,'.$photo.' FROM users WHERE id=?');
    $stmt->bind_param('i', $id); $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    if ($row) $GLOBALS['profileCurrentCache'][$id] = $row;
    return $row ?: null;
}
function profilePublicData(array $user): array {
    return ['id'=>(int)$user['id'], 'name'=>$user['full_name'], 'initial'=>profileInitial($user['full_name'] ?: $user['username']),
        'photo_url'=>profilePhotoUrl($user), 'role'=>profileRoleLabel($user)];
}
function profileAvatar(string $class = 'profile-avatar', ?int $size = null, ?array $user = null): string {
    $user ??= profileCurrent();
    if (!$user) return '';
    $e = static fn($value): string => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    $url = profilePhotoUrl($user);
    $style = $size === null ? '' : ' style="width:'.max(24,min(120,$size)).'px;height:'.max(24,min(120,$size)).'px"';
    return '<span class="'.$e($class).' shared-profile-avatar" data-profile-avatar="'.(int)$user['id'].'" aria-hidden="true"'.$style.'><span data-avatar-initial>'.$e(profileInitial($user['full_name'] ?: $user['username'])).'</span>'.($url ? '<img src="'.$e($url).'" alt="" data-avatar-image decoding="async">' : '').'</span>';
}
function profileRequireSelf(): void {
    if (!isLoggedIn() || !array_key_exists($_SESSION['role'] ?? '', getUserRoles())) throw new InvalidArgumentException('Sign in again to edit your profile.');
    if ($_SESSION['role'] === 'resident' && !residentHasPermission('resident.profile.edit')) throw new InvalidArgumentException('Profile editing is unavailable for this account.');
}
function profileDeleteUnusedPhoto(mysqli $db, ?string $filename): void {
    if (!profilePhotoFilename($filename)) return;
    $stmt = $db->prepare('SELECT id FROM users WHERE profile_photo=? LIMIT 1'); $stmt->bind_param('s', $filename); $stmt->execute();
    if (!$stmt->get_result()->fetch_assoc()) {
        $file = profilePhotoDirectory().'/'.$filename;
        if (is_file($file) && !unlink($file)) error_log('Unused profile photo could not be removed.');
    }
}
function profileStorePhoto(mysqli $db, ?array $upload): array {
    profileRequireSelf();
    if (!ensureProfilePhotoSchema($db)) throw new RuntimeException('Profile storage needs the profile photo migration.');
    if (!extension_loaded('gd') || !function_exists('imagejpeg')) throw new RuntimeException('Profile image processing is unavailable. Enable the PHP GD extension.');
    if (!$upload || !is_int($upload['error'] ?? null) || $upload['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($upload['tmp_name'] ?? '')) throw new InvalidArgumentException('Choose a valid JPG, PNG or WebP image.');
    if (($upload['size'] ?? 0) < 1 || $upload['size'] > 5*1024*1024 || filesize($upload['tmp_name']) > 5*1024*1024) throw new InvalidArgumentException('Profile pictures must be 5 MB or smaller.');
    if (!preg_match('/\.(?:jpe?g|png|webp)\z/iD', (string)($upload['name'] ?? ''))) throw new InvalidArgumentException('Only JPG, PNG and WebP files are supported.');
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($upload['tmp_name']);
    $info = @getimagesize($upload['tmp_name']);
    if (!$info || !in_array($mime, ['image/jpeg','image/png','image/webp'], true) || $info['mime'] !== $mime) throw new InvalidArgumentException('The file is not a supported image.');
    [$width,$height] = $info;
    if ($width < 1 || $height < 1 || $width > 4096 || $height > 4096 || $width*$height > 4194304) throw new InvalidArgumentException('The image dimensions are too large. Crop the photo before uploading.');
    $source = @imagecreatefromstring((string)file_get_contents($upload['tmp_name']));
    if (!$source) throw new InvalidArgumentException('The image is corrupted or cannot be read.');
    $image = imagecreatetruecolor(512,512);
    try {
        imagefill($image,0,0,imagecolorallocate($image,255,255,255));
        $side = min($width,$height);
        imagecopyresampled($image,$source,0,0,(int)(($width-$side)/2),(int)(($height-$side)/2),512,512,$side,$side);
        $name = bin2hex(random_bytes(24)).'.jpg'; $file = profilePhotoDirectory(true).'/'.$name;
        if (!imagejpeg($image,$file,90)) { if (is_file($file)) unlink($file); throw new RuntimeException('The profile photo could not be saved.'); }
        chmod($file,0640);
    } finally { imagedestroy($source); imagedestroy($image); }
    $id = (int)$_SESSION['user_id']; $old = null;
    try {
        $db->begin_transaction();
        $stmt = $db->prepare('SELECT profile_photo,is_active,is_verified,session_version FROM users WHERE id=? FOR UPDATE'); $stmt->bind_param('i',$id); $stmt->execute();
        $account = $stmt->get_result()->fetch_assoc();
        if (!$account || !(int)$account['is_active'] || !(int)$account['is_verified'] || (int)$account['session_version'] !== (int)$_SESSION['session_version']) throw new InvalidArgumentException('Your account changed. Sign in again before changing your photo.');
        $old = $account['profile_photo'];
        $stmt = $db->prepare('UPDATE users SET profile_photo=? WHERE id=?'); $stmt->bind_param('si',$name,$id); $stmt->execute();
        $db->commit();
    } catch (Throwable $error) { try { $db->rollback(); } finally { if (is_file($file)) unlink($file); } throw $error; }
    // A cleanup failure must not report an already committed upload as failed.
    try { profileDeleteUnusedPhoto($db,$old); } catch (Throwable $error) { error_log('Unused profile photo cleanup failed.'); }
    return profilePublicData(profileCurrent($db,true));
}
function profileRemovePhoto(mysqli $db): array {
    profileRequireSelf();
    if (!ensureProfilePhotoSchema($db)) throw new RuntimeException('Profile storage needs the profile photo migration.');
    $id = (int)$_SESSION['user_id'];
    $db->begin_transaction();
    try {
        $stmt = $db->prepare('SELECT profile_photo,is_active,is_verified,session_version FROM users WHERE id=? FOR UPDATE'); $stmt->bind_param('i',$id); $stmt->execute();
        $account = $stmt->get_result()->fetch_assoc();
        if (!$account || !(int)$account['is_active'] || !(int)$account['is_verified'] || (int)$account['session_version'] !== (int)$_SESSION['session_version']) throw new InvalidArgumentException('Your account changed. Sign in again before changing your photo.');
        $old = $account['profile_photo'];
        $stmt = $db->prepare('UPDATE users SET profile_photo=NULL WHERE id=?'); $stmt->bind_param('i',$id); $stmt->execute();
        $db->commit();
    } catch (Throwable $error) { $db->rollback(); throw $error; }
    try { profileDeleteUnusedPhoto($db,$old); } catch (Throwable $error) { error_log('Unused profile photo cleanup failed.'); }
    return profilePublicData(profileCurrent($db,true));
}
