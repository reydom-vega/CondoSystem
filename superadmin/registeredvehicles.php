<?php
require_once __DIR__.'/../config.php';
if (in_array($_SESSION['role'] ?? '', ['admin','superadmin'], true)) {
    $parkingDefaultTab = 'vehicles';
    require __DIR__.'/parking.php';
} else {
    require __DIR__.'/../includes/vehicle_registry_page.php';
}
