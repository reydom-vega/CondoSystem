<?php
require_once __DIR__ . '/config.php';

$connection = connectDb();
$path = getParkingInventoryFilePath();
$handle = fopen($path, 'rb');
$header = fgetcsv($handle);
$firstRow = fgetcsv($handle);
fclose($handle);

$result = importParkingInventoryCsv($connection);
file_put_contents(__DIR__ . '/debug_parking_import_result.json', json_encode([
    'path' => $path,
    'header' => $header,
    'first_row' => $firstRow,
    'result' => $result,
], JSON_PRETTY_PRINT));
