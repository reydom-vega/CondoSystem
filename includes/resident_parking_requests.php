<?php if (residentHasPermission('resident.parking.request')): ?>
            <section class="bookings-section">
                <h3 class="section-title">Your Parking Requests</h3>
                <?php if (empty($myRequests)): ?>
                    <p class="bookings-empty">You have no parking requests yet.</p>
                <?php else: ?>
                    <?php foreach ($myRequests as $request): ?>
                        <div class="booking-item" id="parking-request-<?php echo (int)$request['id']; ?>">
                            <?php echo systemIconFromGlyph('🚗', 'booking-icon'); ?>
                            <div class="booking-content">
                                <h4><?php echo $request['request_type'] === 'resident_assignment' ? 'Resident Slot Request' : 'Visitor Parking'; ?> — <?php echo htmlspecialchars($request['vehicle_plate']); ?></h4>
                                <p>
                                    <?php if (!empty($request['visitor_name'])): ?><a href="visitors.php"><?php echo htmlspecialchars($request['visitor_name']); ?></a> · Visitor <?php echo htmlspecialchars(str_replace('_', ' ', $request['visitor_status'])); ?> · <?php endif; ?>
                                    <?php echo htmlspecialchars(date('M j, Y', strtotime($request['start_date']))); ?>
                                    <?php if ($request['end_date']): ?> to <?php echo htmlspecialchars(date('M j, Y', strtotime($request['end_date']))); ?><?php endif; ?>
                                    <?php if ($request['status'] === 'approved' && !empty($request['slot_code'])): ?> · Slot <?php echo htmlspecialchars($request['slot_code']); ?><?php endif; ?>
                                </p>
                            </div>
                            <span class="badge badge-success unit-status <?php echo htmlspecialchars($request['status']); ?>"><?php echo htmlspecialchars(ucfirst($request['status'])); ?></span>
                            <?php if ($request['request_type'] === 'visitor' && $request['status'] === 'approved' && (empty($request['visitor_registration_id']) || in_array($request['visitor_status'], ['approved','checked_in'], true))): ?>
                                <a href="../parking_pass.php?request_id=<?php echo (int)$request['id']; ?>&amp;signature=<?php echo htmlspecialchars(parkingPassSignature((int)$request['id'])); ?>" class="service-btn service-btn-secondary">Download QR Pass</a>
                            <?php endif; ?>
                            <?php if ($request['request_type'] === 'visitor' && in_array($request['status'], ['pending','approved'], true)): ?><form method="post" action="parking.php"><?php echo workflowCsrfField(); ?><input type="hidden" name="form_action" value="cancel_visitor_request"><input type="hidden" name="request_id" value="<?php echo (int)$request['id']; ?>"><button type="submit" class="service-btn service-btn-danger">Cancel parking</button></form><?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </section>
<?php endif; ?>
<?php if (residentHasPermission('resident.stickers.order')): ?>
<section class="payment-methods-card"><h3 class="section-title">Your Sticker Requests</h3>
<?php if (!$myStickerRequests): ?><p class="bookings-empty">You have no sticker requests yet.</p><?php endif; ?>
<?php foreach ($myStickerRequests as $stickerRequest): $requestVehicles = $stickerRequestVehicles[(int)$stickerRequest['id']]; include __DIR__.'/resident_sticker_request.php'; endforeach; ?>
</section>
<?php endif; ?>
