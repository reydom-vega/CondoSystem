<?php
/** Existing payment table, shared by current bills and completed history. */
?>
                <div class="unit-table-wrap"><table class="unit-table payment-table"><thead><tr><th>Resident</th><th>Unit</th><th>Amount</th><th>Method</th><th>Date</th><th>Time</th><th>Reference</th><th>Status</th><th>Actions</th></tr></thead><tbody>
                    <?php if (empty($payments)): ?><tr><td colspan="9" class="unit-empty">No payment records found.</td></tr><?php else: ?>
                        <?php foreach ($payments as $payment): $items = getBillItems($connection, (int)$payment['id']); $reference = 'DUES-' . (int)$payment['id']; $paymentDate = !empty($payment['paid_at']) ? $payment['paid_at'] : $payment['created_at']; ?><tr><td><strong><?php echo htmlspecialchars($payment['full_name']); ?></strong><small>@<?php echo htmlspecialchars($payment['username']); ?></small></td><td><?php echo htmlspecialchars($payment['unit_number']); ?></td><td>
                            <?php if (empty($items)): ?>
                                <strong>₱<?php echo number_format((float)$payment['amount'], 2); ?></strong>
                            <?php else: ?>
                                <details><summary><strong>₱<?php echo number_format((float)$payment['amount'], 2); ?></strong></summary>
                                    <div style="margin-top:6px; font-size:12px; color:#9ca3af;">
                                        <?php foreach ($items as $item): ?><div><?php echo htmlspecialchars($item['category']); ?>: ₱<?php echo number_format((float)$item['amount'], 2); ?></div><?php endforeach; ?>
                                    </div>
                                </details>
                            <?php endif; ?>
                        </td><td><?php echo htmlspecialchars(paymentChannelLabel($payment['payment_channel'] ?? null, $payment['payment_method'] ?? null)); ?></td><td class="payment-date-cell"><?php echo htmlspecialchars(date('M j, Y', strtotime($paymentDate))); ?></td><td class="payment-time-cell"><?php echo htmlspecialchars(date('g:i A', strtotime($paymentDate))); ?></td><td><strong><?php echo htmlspecialchars($reference); ?></strong><?php if (!empty($payment['paymongo_payment_id'])): ?><br><small>Payment ID: <?php echo htmlspecialchars($payment['paymongo_payment_id']); ?></small><?php endif; ?></td><td><span class="unit-status <?php echo htmlspecialchars($payment['status']); ?>"><?php echo htmlspecialchars(ucfirst($payment['status'])); ?></span></td><td><?php if (in_array($payment['status'], ['pending', 'overdue'], true) && $payment['payment_method'] === 'cash'): ?><form method="post" action="unitpayments.php" class="manual-payment-confirm"><input type="hidden" name="tab" value="billing"><input type="hidden" name="search" value="<?php echo htmlspecialchars($search, ENT_QUOTES, 'UTF-8'); ?>"><?php echo workflowCsrfField(); ?><input type="hidden" name="action" value="mark_paid"><input type="hidden" name="payment_id" value="<?php echo (int)$payment['id']; ?>"><button type="submit" class="btn-small btn-approve">Confirm Cash Paid</button></form><?php elseif (in_array($payment['status'], ['pending', 'overdue'], true) && in_array($payment['payment_method'], ['bank', 'online'], true)): ?><span>Awaiting PayMongo</span><?php else: ?>—<?php endif; ?></td></tr><?php endforeach; ?>
                    <?php endif; ?>
                </tbody></table></div>
