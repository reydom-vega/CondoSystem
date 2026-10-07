<?php

$csvPath = dirname(__DIR__) . '/parking-inventory.csv';
$header = fgetcsv(fopen($csvPath, 'rb'));
$normalizedHeader = [];
foreach ($header as $index => $name) {
    $normalizedHeader[strtolower(str_replace([' ', '-', '_'], '', (string) $name))] = $index;
}

$rows = 0;
$invalidRows = 0;
$handle = fopen($csvPath, 'rb');
fgetcsv($handle);
while (($row = fgetcsv($handle)) !== false) {
    $rows++;
    $slotCode = strtoupper(trim((string) $row[$normalizedHeader['parkingslotcode']] ?? ''));
    $level = trim((string) $row[$normalizedHeader['level']] ?? ''));
    $slotType = strtolower(trim((string) $row[$normalizedHeader['type']] ?? 'resident'])));
    if ($slotCode === '' || $level === '' || !in_array($slotType, ['resident', 'visitor'], true)) {
        $invalidRows++;
    }
}
fclose($handle);

if ($rows === 0 || $invalidRows > 0) {
    fwrite(STDERR, "Parking CSV validation failed: rows={$rows}, invalid={$invalidRows}\n");
    exit(1);
}

fwrite(STDOUT, "Parking CSV validation passed: rows={$rows}, invalid={$invalidRows}\n");
