<?php
/** Presentation composition only; existing modules retain their guards and writes. */
function parkingWorkspaceModule(string $module): string {
    $parkingWorkspaceEmbedded = true;
    $residentVehiclePage = $module === 'resident-vehicles';
    $path = match ($module) {
        'resident-vehicles', 'staff-vehicles' => __DIR__.'/vehicle_registry_page.php',
        'slots' => __DIR__.'/../superadmin/parkinginventory.php',
        'configuration' => __DIR__.'/../superadmin/parking_configuration.php',
        default => throw new InvalidArgumentException('Unknown parking module.'),
    };
    ob_start();
    try { include $path; return (string)ob_get_clean(); }
    catch (Throwable $error) { ob_end_clean(); throw $error; }
}

function parkingWorkspaceTabs(bool $resident): array {
    if ($resident) {
        $tabs = [];
        if (residentHasPermission('resident.parking.request') || residentHasPermission('resident.stickers.order')) $tabs['parking'] = 'Parking & Sticker';
        if (residentHasPermission('resident.vehicles.register')) $tabs['vehicles'] = 'Registered Vehicles';
        if (isset($tabs['parking'])) $tabs['requests'] = 'Requests';
        return $tabs;
    }
    $tabs = ['overview'=>'Overview'];
    if (canAccess('management.dashboard')) $tabs['slots'] = 'Parking Slots';
    if (canAccess('vehicles.read')) $tabs['vehicles'] = 'Registered Vehicles';
    if (canAccess('stickers.issue') || canAccess('billing.manage')) $tabs['stickers'] = 'Sticker Requests';
    $tabs['visitors'] = 'Visitor Parking';
    if(canAccess('parking.extensions.review'))$tabs['extensions']='Extended Parking Requests';
    $tabs['records'] = 'Parking Records';
    return $tabs;
}

function parkingWorkspaceActiveTab(array $tabs, string $default): string {
    $action = $_POST['action'] ?? '';
    $requested = $_GET['tab'] ?? $default;
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $requested = match ($action) {
            'register_vehicle', 'review_vehicle' => 'vehicles',
            'update_status', 'import_inventory' => 'slots',
            'issue_sticker', 'reassign_vehicle_slot' => 'stickers',
            'decide_request', 'reassign_request_slot' => 'visitors',
            default => isset($_POST['sticker_price']) ? 'slots' : $default,
        };
        if (isset($tabs['requests']) && ($_POST['form_action'] ?? '') === 'cancel_visitor_request') $requested = 'requests';
    }
    return is_string($requested) && isset($tabs[$requested]) ? $requested : (string)array_key_first($tabs);
}

function renderParkingWorkspaceTabs(array $tabs, string $active, array $counts = []): void {
    echo '<div class="qr-tabs parking-tabs" role="tablist" aria-label="Parking sections">';
    foreach ($tabs as $key=>$label) {
        echo '<a href="parking.php?tab='.$key.'" role="tab" id="parking-tab-'.$key.'" aria-controls="parking-panel-'.$key.'" aria-selected="'.($active===$key?'true':'false').'" tabindex="'.($active===$key?'0':'-1').'" data-parking-tab="'.$key.'">'.htmlspecialchars($label, ENT_QUOTES, 'UTF-8');
        if (array_key_exists($key, $counts)) echo ' <span class="parking-tab-count">'.max(0,(int)$counts[$key]).'</span>';
        echo '</a>';
    }
    echo '</div>';
}

function parkingWorkspacePanelAttributes(string $key, string $active): string {
    return 'id="parking-panel-'.$key.'" role="tabpanel" aria-labelledby="parking-tab-'.$key.'" data-parking-panel="'.$key.'"'.($active===$key?'':' hidden');
}

/** Search only records already visible to the authenticated staff member. */
function renderParkingListSearch(string $key, string $label, string $placeholder): void {
    $name = $key.'_search';
    $value = is_string($_GET[$name] ?? null) ? $_GET[$name] : '';
    $escape = static fn(string $text): string => htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    ?>
    <form method="get" action="parking.php" class="parking-search-form" data-parking-list-search="<?= $escape($key) ?>">
        <input type="hidden" name="tab" value="<?= $escape($key) ?>">
        <div class="field">
            <label class="field-label" for="<?= $escape($name) ?>"><?= $escape($label) ?></label>
            <input type="search" id="<?= $escape($name) ?>" name="<?= $escape($name) ?>" value="<?= $escape($value) ?>" placeholder="<?= $escape($placeholder) ?>" aria-controls="parking-search-list-<?= $escape($key) ?>">
        </div>
        <div class="parking-search-actions">
            <button type="submit" class="btn-primary">Search</button>
            <button type="button" class="service-btn service-btn-secondary" data-parking-search-clear>Clear</button>
        </div>
    </form>
    <p class="parking-record-count" data-parking-search-count="<?= $escape($key) ?>" role="status" aria-live="polite" aria-atomic="true"></p>
    <p class="bookings-empty" data-parking-search-empty="<?= $escape($key) ?>" hidden>No matching <?= $key === 'vehicles' ? 'vehicles' : 'sticker requests' ?>. Try another name, unit, plate, sticker number or slot.</p>
    <?php
}
