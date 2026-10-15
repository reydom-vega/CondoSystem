            <?php if ($success !== ''): ?>
                <div class="alert success"><?php echo htmlspecialchars($success); ?></div>
            <?php endif; ?>
            <?php if (!empty($errors)): ?>
                <div class="alert error">
                    <ul>
                        <?php foreach ($errors as $error): ?>
                            <li><?php echo htmlspecialchars($error); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <section class="unit-page-head">
                <div>
                    <h2>Parking Slot Inventory</h2>
                    <p><?php if (canAccess('parking.configure')): ?>Manage resident and visitor parking slots across all levels. Select Occupied when a vehicle is using a slot. For visitor slots, select Available again after the vehicle leaves; approved reservation dates still prevent overlapping bookings.<?php else: ?>View resident and visitor parking slots, current status and assignments across all levels. Superadmin manages inventory and slot availability.<?php endif; ?></p>
                </div>
                <div class="unit-summary">
                    <span><?php echo $totalSlots; ?> Total Slots</span>
                </div>
            </section>

            <section class="admin-stats-grid">
                <div class="admin-stat-card"><?php echo systemIconFromGlyph('🅿️', 'admin-stat-icon admin-icon-blue'); ?><strong><?php echo $totalSlots; ?></strong><span>Total Slots</span></div>
                <div class="admin-stat-card"><?php echo systemIconFromGlyph('✅', 'admin-stat-icon admin-icon-green'); ?><strong><?php echo $availableSlots; ?></strong><span>Available</span></div>
                <div class="admin-stat-card"><?php echo systemIconFromGlyph('🚗', 'admin-stat-icon admin-icon-yellow'); ?><strong><?php echo $occupiedSlots; ?></strong><span>Occupied</span></div>
                <div class="admin-stat-card"><?php echo systemIconFromGlyph('🛠️', 'admin-stat-icon admin-icon-pink'); ?><strong><?php echo $maintenanceSlots; ?></strong><span>Maintenance</span></div>
            </section>

            <section class="unit-management-panel parking-panel parking-inventory-panel">
                <div class="csv-import-panel">
                    <div>
                        <h3 class="section-title">Parking Inventory</h3>
                </div>
                <?php if (canAccess('parking.configure')): ?><div class="service-actions parking-inventory-actions"><form method="post" action="parkinginventory.php"><?php echo workflowCsrfField(); ?><input type="hidden" name="action" value="import_inventory"><button class="service-btn service-btn-secondary" type="submit">Import missing inventory slots</button></form></div><?php endif; ?>

                <form method="get" action="parking.php" class="parking-search-form"><input type="hidden" name="tab" value="slots">
                    <div class="field">
                        <label class="field-label" for="inventory_search">Search parking inventory</label>
                        <input type="search" id="inventory_search" name="search" value="<?php echo htmlspecialchars($searchTerm); ?>" placeholder="Slot number, plate number, or unit number">
                    </div>
                    <div class="parking-search-actions">
                        <button type="submit" class="btn-primary">Search</button>
                        <?php if ($searchTerm !== ''): ?>
                            <a class="service-btn service-btn-secondary" href="parking.php?tab=slots">Clear</a>
                        <?php endif; ?>
                    </div>
                </form>

                <div class="unit-table-wrap">
                    <table class="unit-table">
                        <thead>
                            <tr>
                                <th>Slot Code</th>
                                <th>Level</th>
                                <th>Type</th>
                                <th>Status</th>
                                <th>Assigned To</th>
                                <th>Plate</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($slots)): ?>
                                <tr>
                                    <td colspan="7" class="unit-empty"><?php echo $searchTerm === '' ? 'No parking slots have been added yet.' : 'No parking slots match your search.'; ?></td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($slots as $slot): ?>
                                    <tr>
                                        <td><strong><?php echo htmlspecialchars((string)($slot['slot_code'] ?? '—')); ?></strong></td>
                                        <td><?php echo htmlspecialchars((string)($slot['level'] ?? '—')); ?></td>
                                        <td><?php echo htmlspecialchars(ucfirst((string)($slot['slot_type'] ?? 'resident'))); ?></td>
                                        <td>
                                            <span class="unit-status <?php echo htmlspecialchars((string)($slot['status'] ?? 'available')); ?>"><?php echo htmlspecialchars(ucfirst((string)($slot['status'] ?? 'available'))); ?></span>
                                        </td>
                                        <td><?php echo htmlspecialchars((string)($slot['assigned_name'] ?? 'Unassigned')); ?><?php if (!empty($slot['assigned_unit'])): ?><br><small><?php echo htmlspecialchars((string)$slot['assigned_unit']); ?></small><?php endif; ?></td>
                                        <td><?php echo htmlspecialchars((string)($slot['vehicle_plate'] ?? '—')); ?></td>
                                        <td>
                                            <?php if (canAccess('parking.configure')): ?><div class="parking-slot-actions">
                                                <form method="post" action="parkinginventory.php" class="parking-status-form" data-confirm="Update the status of parking slot <?php echo htmlspecialchars((string)($slot['slot_code'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>?" data-confirm-title="Update parking slot" data-confirm-action="Update"><?php echo workflowCsrfField(); ?>
                                                    <input type="hidden" name="action" value="update_status">
                                                    <input type="hidden" name="slot_id" value="<?php echo (int)($slot['id'] ?? 0); ?>">
                                                    <select name="status" aria-label="Update status for <?php echo htmlspecialchars((string)($slot['slot_code'] ?? 'slot')); ?>">
                                                        <option value="available" <?php echo (($slot['status'] ?? 'available') === 'available') ? 'selected' : ''; ?>>Available</option>
                                                        <option value="occupied" <?php echo (($slot['status'] ?? 'available') === 'occupied') ? 'selected' : ''; ?>>Occupied</option>
                                                        <option value="maintenance" <?php echo (($slot['status'] ?? 'available') === 'maintenance') ? 'selected' : ''; ?>>Maintenance</option>
                                                    </select>
                                                    <button type="submit" class="btn-small btn-neutral">Update</button>
                                                </form>
                                            </div><?php else: ?><span>Managed by Superadmin</span><?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                </div>
            </section>
