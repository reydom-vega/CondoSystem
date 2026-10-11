<?php
require_once __DIR__.'/../config.php';
if (($_SESSION['role'] ?? '') === 'resident') requireResidentPermission('resident.vehicles.register');
// Preserve the registration URL, multipart forms and bookmarks.
$parkingDefaultTab = 'vehicles';
require __DIR__ . '/parking.php';
