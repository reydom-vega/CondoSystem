<?php
/** Caller locks the parking row and owns the transaction. No provider calls. */
function vpPostFinalCharge(mysqli $db,array $row,array $quote): int {
    if($quote['amount']==='0.00'||!empty($quote['needs_rate_review']))throw new LogicException('Only confirmed positive charges may be invoiced.');
    if(!empty($row['payment_id']))return (int)$row['payment_id'];
    $payer=(int)$row['billing_user_id'];if(!$payer||!in_array($row['billing_scope_snapshot'],['visitor_parking','personal_parking'],true))throw new InvalidArgumentException('Resolve the original sponsoring billing account before posting this charge.');
    $s=$db->prepare('SELECT id,full_name,email,contact_number FROM users WHERE id=?');$s->bind_param('i',$payer);$s->execute();$contact=$s->get_result()->fetch_assoc();if(!$contact)throw new InvalidArgumentException('The original billing account is missing.');
    $due=(new DateTimeImmutable('today',new DateTimeZone('Asia/Manila')))->modify('+15 days')->format('Y-m-d');$period=(new DateTimeImmutable($row['actual_check_in'],new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('Asia/Manila'))->format('Y-m-01');$periodEnd=(new DateTimeImmutable($period))->format('Y-m-t');
    $s=$db->prepare("INSERT INTO payments(user_id,amount,payment_method,status,due_date,billing_scope,billing_period_start,billing_period_end) VALUES(?,?,'unbilled','pending',?,?,?,?)");$s->bind_param('isssss',$payer,$quote['amount'],$due,$row['billing_scope_snapshot'],$period,$periodEnd);$s->execute();$payment=(int)$db->insert_id;
        lifecycleQuery($db,'UPDATE payments SET unit_number_snapshot=? WHERE id=?',[$row['sponsor_unit_snapshot'],$payment]);
    $description='VP-'.str_pad((string)$row['id'],8,'0',STR_PAD_LEFT).' / '.$row['vehicle_plate'].'; '.vpDuration($quote['seconds']);
    if(isset($quote['regular_cents']))$description.='; initial PHP '.vpMoney($quote['regular_cents']).'; additional PHP '.vpMoney($quote['succeeding_cents']).'; overnight PHP '.vpMoney($quote['overnight_cents']).'; rate version '.$quote['rate_version'];
    else $description.='; original hourly rate PHP '.$row['rate_snapshot'];
    $s=$db->prepare("INSERT INTO bill_items(payment_id,category,description,amount) VALUES(?,'Visitor Parking Fee',?,?)");$s->bind_param('iss',$payment,$description,$quote['amount']);$s->execute();
    $s=$db->prepare('UPDATE parking_requests SET payment_id=? WHERE id=? AND payment_id IS NULL');$s->bind_param('ii',$payment,$row['id']);$s->execute();if($s->affected_rows!==1)throw new RuntimeException('This session already has a bill.');
    if(!logAudit('bill_created','payment',$payment,'Confirmed visitor parking charge created.',$db,'success'))throw new RuntimeException('Parking bill audit failed.');
    queueResidentChannels($db,'visitor-parking-bill:'.$row['id'],$contact,'[The Celandine] Visitor Parking Fee Added',emailLayout('Visitor Parking Fee','Parking charge finalized','<p>Parking '.htmlspecialchars($row['vehicle_plate'],ENT_QUOTES,'UTF-8').' has a finalized charge of PHP '.$quote['amount'].'. Bill DUES-'.$payment.' and its breakdown are available in Billing &amp; Payments.</p>'),'The Celandine: Visitor parking fee PHP '.$quote['amount'].' added to bill DUES-'.$payment.'.','bill_notice',$payment,true);
    return $payment;
}
function vpFinalizeRateReview(mysqli $db,int $id,array $input): array {
    if(!canAccess('parking.extensions.review'))throw new InvalidArgumentException('Only Admin or Super Admin may finalize rate reviews.');
    $reason=vpText($input,'reason',1000,true);$rule=vpText($input,'late_rule',30);$actor=(int)$_SESSION['user_id'];
    $db->begin_transaction();try{
        $row=vpLoad($db,$id,true);if(!$row['actual_check_out'])throw new InvalidArgumentException('Only completed parking sessions can be finalized.');
        if(!in_array($row['fee_state'],['pending_rate_review','pending_account_review'],true)){$db->commit();return vpSessionData($db,$row,vpNow());}
        $policy=$row['rate_policy_snapshot']?json_decode($row['rate_policy_snapshot'],true,512,JSON_THROW_ON_ERROR):null;
        if($row['fee_state']==='pending_rate_review'){if(!in_array($rule,['present_at_start','arrive_before_end'],true))throw new InvalidArgumentException('Confirm whether this late arrival qualifies for the overnight fee.');$policy['late_rule']=$rule;}
        $quote=(int)$row['fee_policy_version']<2?vpQuote($row['actual_check_in'],$row['actual_check_out'],$row['rate_snapshot']):vpCalculate($row['actual_check_in'],$row['actual_check_out'],$policy);
        if(!empty($quote['needs_rate_review']))throw new InvalidArgumentException('Resolve the late-arrival rule before finalizing.');
        if(!$row['billing_user_id']||!in_array($row['billing_scope_snapshot'],['visitor_parking','personal_parking'],true)){
            $payer=vpResponsibleAccount($db,(int)$row['user_id']);$row['billing_user_id']=$payer['id'];$row['billing_scope_snapshot']=$payer['scope'];$row['sponsor_unit_snapshot']=$payer['unit'];
            $s=$db->prepare('UPDATE parking_requests SET billing_user_id=?,billing_scope_snapshot=?,sponsor_unit_snapshot=? WHERE id=?');$s->bind_param('issi',$payer['id'],$payer['scope'],$payer['unit'],$id);$s->execute();
        }
        $payment=$quote['amount']==='0.00'?null:vpPostFinalCharge($db,$row,$quote);$state=$payment?'billed':'free';$snapshot=json_encode($quote,JSON_THROW_ON_ERROR);$now=vpNow();
        $s=$db->prepare('UPDATE parking_requests SET parking_fee=?,calculation_snapshot=?,fee_state=?,rate_reviewed_by=?,rate_reviewed_at=?,rate_review_reason=? WHERE id=?');$s->bind_param('sssissi',$quote['amount'],$snapshot,$state,$actor,$now,$reason,$id);$s->execute();
        if(!logAudit('finalize_rate','visitor_parking',$id,'Late-arrival decision '.$rule.'; '.$reason,$db))throw new RuntimeException('Rate review audit failed.');
        vpNotify($db,$id,'rate-finalized','Management finalized the parking rate review. Confirmed charge: PHP '.$quote['amount'].'.');$db->commit();return vpSessionData($db,vpLoad($db,$id),vpNow());
    }catch(Throwable $error){$db->rollback();throw $error;}
}
