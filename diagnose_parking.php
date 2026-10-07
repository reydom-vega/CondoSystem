<?php
register_shutdown_function(function (): void {
    file_put_contents(__DIR__ . '/diagnose_parking_result.json', json_encode([
        'error' => error_get_last() ?? null,
        'path' => is_file(__DIR__ . '/parking-inventory.csv') ? __DIR__ . '/parking-inventory.csv' : null,
    ], JSON_PRETTY_PRINT));
});

require_once __DIR__ . '/config.php';
$connection = connectDb();
$result = importParkingInventoryCsv($connection);
file_put_contents(__DIR__ . '/diagnose_parking_result.json', json_encode([
    'path' => getParkingInventoryFilePath(),
    'result' => $result,
    'db' => $configuredDbName,
    'host' => $configuredDbHost,
], JSON_PRETTY_PRINT));
