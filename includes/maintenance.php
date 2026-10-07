<?php

function maintenanceRequestCode(int $requestId): string {
    return 'MR-' . str_pad((string)$requestId, 5, '0', STR_PAD_LEFT);
}

function maintenanceStatusLabel(string $status): string {
    if ($status === 'closed') {
        return 'Successful';
    }

    return ucwords(str_replace('_', ' ', $status));
}

function storeMaintenanceImage(array $upload): array {
    $uploadError = (int)($upload['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($uploadError === UPLOAD_ERR_NO_FILE) {
        return ['path' => null, 'error' => ''];
    }
    if ($uploadError !== UPLOAD_ERR_OK) {
        return ['path' => null, 'error' => 'The image upload failed. Please try again.'];
    }
    if ((int)($upload['size'] ?? 0) > 5 * 1024 * 1024) {
        return ['path' => null, 'error' => 'Images must be 5 MB or smaller.'];
    }

    $temporaryPath = (string)($upload['tmp_name'] ?? '');
    $imageInfo = $temporaryPath !== '' ? @getimagesize($temporaryPath) : false;
    $extensions = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    $mimeType = is_array($imageInfo) ? (string)($imageInfo['mime'] ?? '') : '';
    if (!$imageInfo || !isset($extensions[$mimeType])) {
        return ['path' => null, 'error' => 'Upload a JPG, JPEG, PNG, or WebP image.'];
    }

    $directory = dirname(__DIR__) . '/private_uploads/maintenance_evidence';
    if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) {
        return ['path' => null, 'error' => 'Secure image storage is unavailable. Please contact management.'];
    }

    $storedName = bin2hex(random_bytes(20)) . '.' . $extensions[$mimeType];
    if (!move_uploaded_file($temporaryPath, $directory . '/' . $storedName)) {
        return ['path' => null, 'error' => 'Could not save the image. Please try again.'];
    }

    return ['path' => $storedName, 'error' => ''];
}

function maintenanceImagePath(?string $filename): ?string {
    if ($filename === null || !preg_match('/\A[a-f0-9]{40}\.(jpg|png|webp)\z/', $filename)) {
        return null;
    }

    $directory = realpath(dirname(__DIR__) . '/private_uploads/maintenance_evidence');
    $path = $directory ? realpath($directory . DIRECTORY_SEPARATOR . $filename) : false;
    if (!$directory || !$path || !str_starts_with($path, $directory . DIRECTORY_SEPARATOR) || !is_file($path)) {
        return null;
    }

    return $path;
}
