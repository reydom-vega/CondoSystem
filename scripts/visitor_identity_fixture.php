<?php
/** Legacy feature suites seed an already-reviewed ID; real uploads have their own HTTP suite. */
function fixtureCreateResidentServiceRequest(mysqli $db, int $userId, string $kind, array $input): int {
    if (!preg_match('/^condo_[a-z_]+_test_[a-f0-9]{12}$/D', (string)$db->query('SELECT DATABASE() AS n')->fetch_assoc()['n'])) throw new RuntimeException('Visitor fixtures require a disposable database.');
    if ($kind !== 'visitor') return createResidentServiceRequest($db, $userId, $kind, $input);
    $id = insertResidentServiceRequest($db, $userId, $kind, $input,
        ['type'=>'National ID','last4'=>null,'name'=>str_repeat('f',48).'.png','mime'=>'image/png']);
    $db->query("UPDATE resident_service_requests SET identity_status='verified' WHERE id=$id");
    return $id;
}
function fixtureRegisterVisitorWithParking(mysqli $db, int $userId, array $input): int {
    ensureResidentServicesTables($db); ensureParkingTables($db); $policy = getParkingPolicy($db);
    $db->begin_transaction();
    try {
        $id = fixtureCreateResidentServiceRequest($db, $userId, 'visitor', $input);
        if (($input['needs_parking'] ?? '') === '1') insertLinkedVisitorParking($db, $userId, $id, $input['vehicle_plate'] ?? '', $input['vehicle_description'] ?? '', $input['start_date'], $input['start_date'], $policy);
        $db->commit(); return $id;
    } catch (Throwable $e) { $db->rollback(); throw $e; }
}
