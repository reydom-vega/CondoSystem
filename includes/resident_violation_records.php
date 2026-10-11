<?php
/** Original record cards and incident details, rendered for one authorized tab. */
?>
<div class="resident-violation-workspace">
					<section class="resident-violation-list-panel" aria-label="Your violations">
						<div class="violation-panel-heading"><div><span class="violation-eyebrow"><?php echo $panelKey === 'history' ? 'YOUR HISTORY' : 'CURRENT RECORDS'; ?></span><h2><?php echo $panelKey === 'history' ? 'Violation History' : 'Current violations'; ?></h2></div><span class="violation-record-count"><?php echo count($visibleViolations); ?> of <?php echo (int)$panelStats['total']; ?> records</span></div>
						<form method="get" action="residentviolation.php" class="violation-history-search" role="search">
							<input type="hidden" name="tab" value="<?php echo $panelKey; ?>">
							<input type="hidden" name="filter" value="<?php echo htmlspecialchars($activeFilter); ?>">
							<label class="field-label" for="violationSearch-<?php echo $panelKey; ?>">Search your records</label>
							<div><input type="search" id="violationSearch-<?php echo $panelKey; ?>" name="search" maxlength="100" value="<?php echo htmlspecialchars($violationSearch); ?>" placeholder="Type, plate, location or reference"><button type="submit">Search</button></div>
						</form>
						<nav class="resident-violation-filters" aria-label="Filter violations">
							<?php foreach ($panelFilters as $filterKey => $filterLabel): ?>
								<a class="resident-violation-filter<?php echo $activeFilter === $filterKey ? ' active' : ''; ?>" href="residentviolation.php?<?php echo htmlspecialchars(http_build_query(['tab'=>$panelKey, 'filter'=>$filterKey, 'search'=>$violationSearch])); ?>" <?php echo $activeFilter === $filterKey ? 'aria-current="page"' : ''; ?>>
									<?php echo htmlspecialchars($filterLabel); ?><span><?php echo (int)$panelStats[match($filterKey) { 'all'=>'total', 'unpaid'=>'outstanding', default=>$filterKey }]; ?></span>
								</a>
							<?php endforeach; ?>
						</nav>

						<?php if (!$visibleViolations): ?>
							<div class="resident-violation-filter-empty"><?php echo systemIcon('clipboard'); ?><h3>No matching records</h3><p>Try another search or status filter.</p><a href="residentviolation.php?tab=<?php echo $panelKey; ?>">View all records</a></div>
						<?php else: foreach ($visibleViolations as $violation):
							$violationIcon = $violationIcons[$violation['violation_type']] ?? 'violations';
							$isSelected = (int)$selectedViolation['id'] === (int)$violation['id'];
							$detailUrl = 'residentviolation.php?' . http_build_query(['tab'=>$panelKey, 'filter'=>$activeFilter, 'search'=>$violationSearch, 'violation'=>(int)$violation['id']]) . '#violationDetails';
						?>
							<article class="resident-violation-card<?php echo $isSelected ? ' selected' : ''; ?>">
								<a class="resident-violation-card-main" href="<?php echo htmlspecialchars($detailUrl); ?>" aria-current="<?php echo $isSelected ? 'true' : 'false'; ?>">
									<span class="resident-violation-type-icon"><?php echo systemIcon($violationIcon, 'resident-violation-type-svg'); ?></span>
									<span class="resident-violation-card-copy">
										<small class="violation-reference">Violation #<?php echo (int)$violation['id']; ?></small>
										<strong><?php echo htmlspecialchars($violation['violation_type']); ?></strong>
										<span class="resident-violation-badges"><em class="penalty-badge penalty-<?php echo htmlspecialchars($violation['penalty_type']); ?>"><?php echo htmlspecialchars($violation['penalty_type'] === 'fine' ? 'Fine' : 'Warning'); ?></em><small><?php echo htmlspecialchars(date('M j, Y · g:i A', strtotime($violation['incident_at'] ?: $violation['created_at']))); ?></small></span>
										<?php if (!empty($violation['location'])): ?><span class="resident-violation-location">⌖ <?php echo htmlspecialchars($violation['location']); ?></span><?php endif; ?>
										<?php if (!empty($violation['description'])): ?><span class="resident-violation-description"><?php echo htmlspecialchars($violation['description']); ?></span><?php endif; ?>
									</span>
									<span class="resident-violation-card-end">
										<?php if ($violation['penalty_type'] === 'fine'): ?><strong class="resident-violation-fine">₱<?php echo number_format((float)$violation['fine_amount'], 2); ?><small>Fine</small></strong><?php endif; ?>
										<em class="violation-status status-<?php echo htmlspecialchars($violation['status']); ?>"><?php echo htmlspecialchars($statusLabels[$violation['status']] ?? $violation['status']); ?></em>
									</span>
								</a>
								<?php if (!empty($violation['evidence_path'])): ?>
									<div class="resident-violation-card-evidence">
										<a href="../violation_evidence.php?id=<?php echo (int)$violation['id']; ?>&amp;inline=1" target="_blank" rel="noopener" class="resident-violation-thumb"><img src="../violation_evidence.php?id=<?php echo (int)$violation['id']; ?>&amp;inline=1" alt="Evidence for <?php echo htmlspecialchars($violation['violation_type']); ?>"></a>
										<a class="resident-violation-evidence-link" href="../violation_evidence.php?id=<?php echo (int)$violation['id']; ?>&amp;inline=1" target="_blank" rel="noopener">View Evidence</a>
									</div>
								<?php endif; ?>
							</article>
						<?php endforeach; endif; ?>
					</section>

					<?php if ($selectedViolation):
						$selectedIcon = $violationIcons[$selectedViolation['violation_type']] ?? 'violations';
					?>
						<aside class="resident-violation-detail" id="<?php echo $detailId; ?>" aria-label="Violation details" tabindex="0">
							<div class="violation-panel-heading"><span class="violation-eyebrow">INCIDENT DETAILS</span><span class="violation-reference">Violation #<?php echo (int)$selectedViolation['id']; ?></span></div>
							<div class="resident-violation-detail-head">
								<span class="resident-violation-type-icon"><?php echo systemIcon($selectedIcon, 'resident-violation-type-svg'); ?></span>
								<div><h2><?php echo htmlspecialchars($selectedViolation['violation_type']); ?></h2><div class="resident-violation-badges"><em class="penalty-badge penalty-<?php echo htmlspecialchars($selectedViolation['penalty_type']); ?>"><?php echo htmlspecialchars($selectedViolation['penalty_type'] === 'fine' ? 'Fine' : 'Warning'); ?></em><em class="violation-status status-<?php echo htmlspecialchars($selectedViolation['status']); ?>"><?php echo htmlspecialchars($statusLabels[$selectedViolation['status']] ?? $selectedViolation['status']); ?></em></div></div>
								<?php if ($selectedViolation['penalty_type'] === 'fine'): ?><strong class="resident-violation-detail-amount">₱<?php echo number_format((float)$selectedViolation['fine_amount'], 2); ?><small>Fine amount</small></strong><?php endif; ?>
							</div>
							<div class="resident-violation-detail-grid">
								<div><small>Date</small><strong><?php echo htmlspecialchars(date('F j, Y', strtotime($selectedViolation['incident_at'] ?: $selectedViolation['created_at']))); ?></strong></div>
								<div><small>Time</small><strong><?php echo htmlspecialchars(date('g:i A', strtotime($selectedViolation['incident_at'] ?: $selectedViolation['created_at']))); ?></strong></div>
								<div><small>Violation type</small><strong><?php echo htmlspecialchars($selectedViolation['violation_type']); ?></strong></div>
								<div><small>Reported by</small><strong><?php echo htmlspecialchars($selectedViolation['issued_by_name'] ?? 'Management'); ?></strong></div>
								<div><small>Unit number</small><strong><?php echo htmlspecialchars($selectedViolation['unit_snapshot'] ?? $_SESSION['unit_number'] ?? 'Not assigned'); ?></strong></div>
								<?php if (!empty($selectedViolation['observed_plate'])): ?><div><small>Recorded vehicle plate</small><strong><?= htmlspecialchars($selectedViolation['observed_plate']) ?></strong></div><?php endif; ?>
								<?php if (!empty($selectedViolation['location'])): ?><div><small>Location</small><strong><?php echo htmlspecialchars($selectedViolation['location']); ?></strong></div><?php endif; ?>
								<?php if (!empty($selectedViolation['due_date'])): ?><div><small>Fine due date</small><strong><?php echo htmlspecialchars(date('F j, Y', strtotime($selectedViolation['due_date']))); ?></strong></div><?php endif; ?>
							</div>
							<?php if (!empty($selectedViolation['description'])): ?><section class="resident-violation-detail-section"><h3>Description</h3><p><?php echo nl2br(htmlspecialchars($selectedViolation['description'])); ?></p></section><?php endif; ?>
							<?php if (!empty($selectedViolation['evidence_path'])): ?>
								<section class="resident-violation-detail-section"><h3>Evidence</h3><a class="resident-violation-detail-image" href="../violation_evidence.php?id=<?php echo (int)$selectedViolation['id']; ?>&amp;inline=1" target="_blank" rel="noopener"><img src="../violation_evidence.php?id=<?php echo (int)$selectedViolation['id']; ?>&amp;inline=1" alt="Evidence for <?php echo htmlspecialchars($selectedViolation['violation_type']); ?>"></a><a class="resident-violation-download" href="../violation_evidence.php?id=<?php echo (int)$selectedViolation['id']; ?>&amp;download=1" download>Download Evidence</a></section>
							<?php endif; ?>
							<?php if ($selectedViolation['status'] === 'unpaid' && $selectedViolation['penalty_type'] === 'fine'): ?>
								<a class="resident-violation-primary-action" href="payments.php"><?php echo residentHasPermission('resident.billing.pay') ? 'View Bill & Pay Fine' : 'View Unit Bill'; ?><?php echo systemIcon('arrow-right'); ?></a>
								<?php if (!residentHasPermission('resident.billing.pay')): ?><p class="violation-action-help">The unit owner handles shared unit bill payments.</p><?php endif; ?>
								<form method="post" action="residentviolation.php" class="resident-violation-dispute-form"><?php echo workflowCsrfField(); ?><input type="hidden" name="tab" value="current"><input type="hidden" name="form_action" value="dispute_violation"><input type="hidden" name="violation_id" value="<?php echo (int)$selectedViolation['id']; ?>"><label class="field-label" for="dispute_reason_<?php echo (int)$selectedViolation['id']; ?>">Dispute this fine</label><p class="violation-action-help">Explain why you believe this fine should be reviewed.</p><textarea id="dispute_reason_<?php echo (int)$selectedViolation['id']; ?>" name="dispute_reason" maxlength="1000" rows="3" placeholder="Describe what management should review..." required><?php echo $errors && $violationId === (int)$selectedViolation['id'] ? htmlspecialchars($reason) : ''; ?></textarea><button type="submit">Submit Dispute</button></form>
							<?php elseif ($selectedViolation['status'] === 'disputed' && !empty($selectedViolation['dispute_reason'])): ?>
								<section class="resident-violation-dispute-note"><strong>Dispute submitted</strong><p><?php echo htmlspecialchars($selectedViolation['dispute_reason']); ?></p><small>Management is reviewing your dispute.</small></section>
							<?php elseif ($selectedViolation['status'] === 'paid' && !empty($selectedViolation['payment_id']) && residentHasPermission('resident.billing.pay')): ?>
								<a class="resident-violation-primary-action" href="payment_receipt.php?id=<?php echo (int)$selectedViolation['payment_id']; ?>">View Receipt <span>↗</span></a>
							<?php elseif ($selectedViolation['status'] === 'waived'): ?>
								<section class="violation-resolution-note"><strong>Fine waived</strong><p>No payment is required for this violation.</p></section>
							<?php elseif ($selectedViolation['penalty_type'] === 'warning'): ?>
								<section class="violation-resolution-note"><strong>Warning only</strong><p>This record does not carry a fine.</p></section>
							<?php elseif ($selectedViolation['status'] === 'paid'): ?>
								<section class="violation-resolution-note"><strong>Payment recorded</strong><p>This fine has been settled.</p></section>
							<?php endif; ?>
						</aside>
					<?php endif; ?>
				</div>
