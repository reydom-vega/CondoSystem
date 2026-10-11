            <section class="amenity-booking-history" id="<?= $viewKey ?>" aria-labelledby="<?= $viewKey ?>Heading"><h2 class="amenity-section-title" id="<?= $viewKey ?>Heading"><?= $viewKey === 'bookingHistory' ? 'Reservation history' : 'My reservations' ?></h2><p><?= $viewKey === 'bookingHistory' ? 'Past, completed, rejected, and cancelled reservations.' : 'Pending pool requests do not hold hours. Function Hall requests hold the full date while awaiting approval.' ?></p>
            <?php if(!$bookings): ?><div class="amenity-empty"><h3><?= $viewKey === 'bookingHistory' ? 'No reservation history yet' : 'No active reservations' ?></h3><p>Choose the Swimming Pool or Function Hall to plan your next visit.</p><a class="service-btn service-btn-secondary" href="#exploreAmenities" data-amenity-view-link>Explore amenities</a></div><?php endif; ?>
            <?php foreach($bookings as $booking):
                $future=$booking['booking_date'].' '.$booking['booking_time']>date('Y-m-d H:i:s');
                $review=$booking['payment_id'] && ($booking['payment_status']==='paid' || $booking['paymongo_checkout_id']);
            ?>
                <article class="amenity-history-card"><div><span class="amenity-kicker">BOOKING #<?= (int)$booking['id'] ?></span><h3><?= $escape($booking['amenity']) ?></h3><p><?= $escape(date('F j, Y',strtotime($booking['booking_date']))) ?> &middot; <?= $escape(date('g:i A',strtotime($booking['booking_time']))) ?><?= $booking['end_time']?' - '.$escape(date('g:i A',strtotime($booking['end_time']))):'' ?></p><p>Unit <?= $escape($booking['unit_number']) ?> &middot; <?= (int)$booking['attendees'] ?> <?= (int)$booking['attendees']===1?'guest':'guests' ?><?= $booking['duration_hours']?' · '.(int)$booking['duration_hours'].' '.((int)$booking['duration_hours']===1?'hour':'hours'):'' ?></p></div>
                <div class="amenity-history-status"><span class="amenity-status amenity-status-<?= $escape($booking['status']) ?>"><?= $escape(ucfirst($booking['status'])) ?></span><strong>&#8369;<?= number_format((float)$booking['total_fee'],2) ?></strong><span><?= $booking['payment_id']?'Payment: '.$escape($booking['payment_status']==='rejected'?'Voided':($booking['payment_status']==='pending'?'Unpaid':ucfirst($booking['payment_status']))):'No automatic bill issued' ?></span></div>
                <div class="amenity-history-actions">
                <?php if($booking['rejection_reason'] || $booking['cancellation_note']): ?><p class="amenity-history-note"><?= $escape($booking['rejection_reason'] ?: $booking['cancellation_note']) ?></p><?php endif; ?>
                <?php if($future && in_array($booking['status'],['pending','approved','confirmed'],true)): ?>
                    <?php if($review): ?><p class="amenity-history-note">Contact PMO to review cancellation and any payment or refund.</p><?php else: ?><form method="post" action="book_amenity.php"><?= workflowCsrfField() ?><input type="hidden" name="action" value="cancel"><input type="hidden" name="booking_id" value="<?= (int)$booking['id'] ?>"><button class="service-btn service-btn-danger" type="submit">Cancel reservation</button></form><?php endif; ?>
                <?php endif; ?>
                <?php if($booking['payment_id'] && in_array($booking['payment_status'],['pending','overdue'],true)): ?><a href="payments.php" class="service-btn service-btn-secondary">View unit bill</a><?php endif; ?>
                </div>
                </article>
            <?php endforeach; ?>
            </section>
