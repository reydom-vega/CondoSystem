<?php
/** One integer-centavo calculator. Reservation windows never determine charges. */
function vpDefaultRate(): array {
    return ['id'=>2,'grace_seconds'=>900,'base_seconds'=>10800,'base_cents'=>5000,'hour_cents'=>2500,'overnight_cents'=>30000,'overnight_start'=>'22:00','overnight_end'=>'06:00','late_rule'=>'review_late','extension_required'=>1];
}
function ensureVisitorParkingRateSchema(mysqli $db): void {
    $db->query("CREATE TABLE IF NOT EXISTS parking_rate_versions (
        id INT AUTO_INCREMENT PRIMARY KEY,effective_from DATETIME(6) NOT NULL,policy_json LONGTEXT NOT NULL,
        created_by INT DEFAULT NULL,created_at DATETIME(6) NOT NULL,reason VARCHAR(1000) NOT NULL,
        INDEX ix_parking_rate_effective(effective_from,id),
        CONSTRAINT fk_parking_rate_actor FOREIGN KEY(created_by) REFERENCES users(id) ON DELETE RESTRICT
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $json=json_encode(vpDefaultRate(),JSON_THROW_ON_ERROR);
    $s=$db->prepare("INSERT IGNORE INTO parking_rate_versions(id,effective_from,policy_json,created_at,reason) VALUES(2,UTC_TIMESTAMP(6),?,UTC_TIMESTAMP(6),'Initial grace, standard and overnight policy; late arrivals require review.')");$s->bind_param('s',$json);$s->execute();
    foreach(['fee_ack_rate_id','rate_version_id'] as $column){
        $name='fk_parking_'.$column;
        $s=$db->prepare("SELECT 1 FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME='parking_requests' AND CONSTRAINT_NAME=?");$s->bind_param('s',$name);$s->execute();
        if(!$s->get_result()->num_rows)$db->query("ALTER TABLE parking_requests ADD CONSTRAINT $name FOREIGN KEY($column) REFERENCES parking_rate_versions(id) ON DELETE RESTRICT");
    }
}
function vpCurrentRate(mysqli $db,?string $at=null): array {
    $at??=vpNow();$s=$db->prepare('SELECT id,policy_json,effective_from FROM parking_rate_versions WHERE effective_from<=? ORDER BY effective_from DESC,id DESC LIMIT 1');$s->bind_param('s',$at);$s->execute();$row=$s->get_result()->fetch_assoc();
    if(!$row)throw new InvalidArgumentException('No visitor parking rate is effective. Ask management to configure the rate.');
    $rate=json_decode($row['policy_json'],true,512,JSON_THROW_ON_ERROR);$rate['id']=(int)$row['id'];$rate['effective_from']=$row['effective_from'];return $rate;
}
function vpMoney(int $cents): string {return sprintf('%d.%02d',intdiv($cents,100),$cents%100);}
function vpRatePolicyView(mysqli $db): array {
    if(!isLoggedIn()||(!canAccess('parking.review')&&!canAccess('parking.gate')&&!residentHasPermission('resident.visitors.register')&&!residentHasPermission('resident.parking.request')))throw new InvalidArgumentException('Visitor parking access is required.');
    return vpCurrentRate($db);
}
function vpCalculate(string $start,string $end,?array $policy=null): array {
    $p=$policy??vpDefaultRate();$a=vpMicros($start);$b=vpMicros($end);$delta=$b-$a;
    if($delta<0||$delta>3660*86400000000)throw new InvalidArgumentException('Invalid parking duration.');
    foreach(['grace_seconds','base_seconds','base_cents','hour_cents','overnight_cents']as$key)if(!isset($p[$key])||!is_int($p[$key])||$p[$key]<0)throw new InvalidArgumentException('Invalid rate snapshot.');
    if(!in_array($p['late_rule']??'', ['review_late','present_at_start','arrive_before_end'],true))throw new InvalidArgumentException('Invalid overnight policy.');
    $seconds=intdiv($delta+999999,1000000);$free=$delta<=$p['grace_seconds']*1000000;$segments=[];$nights=[];$review=[];
    $base=0;$succeeding=0;$overnight=0;$hours=0;$baseUsed=false;
    $utc=static fn(int $micros):string=>gmdate('Y-m-d H:i:s',intdiv($micros,1000000)).sprintf('.%06d',$micros%1000000);
    $regular=function(int $from,int $to)use(&$segments,&$base,&$succeeding,&$hours,&$baseUsed,$p,$utc):void{
        if($to<=$from)return;$length=$to-$from;$initial=$baseUsed?0:$p['base_cents'];$included=$baseUsed?0:$p['base_seconds']*1000000;
        $extra=max(0,$length-$included);$started=intdiv($extra+3599999999,3600000000);$amount=$initial+$started*$p['hour_cents'];
        $segments[]=['kind'=>'regular','start'=>$utc($from),'end'=>$utc($to),'seconds'=>intdiv($length+999999,1000000),'base_cents'=>$initial,'succeeding_hours'=>$started,'succeeding_cents'=>$started*$p['hour_cents'],'amount_cents'=>$amount];
        $base+=$initial;$succeeding+=$started*$p['hour_cents'];$hours+=$started;$baseUsed=true;
    };
    if(!$free){
        $tz=new DateTimeZone('Asia/Manila');$day=(new DateTimeImmutable($start,new DateTimeZone('UTC')))->setTimezone($tz)->setTime(0,0)->modify('-1 day');$last=(new DateTimeImmutable($end,new DateTimeZone('UTC')))->setTimezone($tz)->setTime(0,0);$cursor=$a;
        for(;$day<=$last;$day=$day->modify('+1 day')){
            $nightStart=new DateTimeImmutable($day->format('Y-m-d').' '.$p['overnight_start'],$tz);$nightEnd=new DateTimeImmutable($day->modify('+1 day')->format('Y-m-d').' '.$p['overnight_end'],$tz);
            $ns=(int)$nightStart->format('U')*1000000;$ne=(int)$nightEnd->format('U')*1000000;
            if($b<$ne||$a>=$ne)continue; // Early departure is always regular parking.
            $late=$a>$ns;
            if($late&&$p['late_rule']==='review_late'){$review[]=['night'=>$day->format('Y-m-d'),'reason'=>'Arrival after overnight start; qualification is unconfirmed.'];continue;}
            if($late&&$p['late_rule']==='present_at_start')continue;
            $covered=max($a,$ns);$regular($cursor,$covered);$segments[]=['kind'=>'overnight','start'=>$utc($covered),'end'=>$utc($ne),'seconds'=>intdiv($ne-$covered,1000000),'amount_cents'=>$p['overnight_cents']];
            $nights[]=['start'=>$utc($covered),'end'=>$utc($ne),'amount_cents'=>$p['overnight_cents']];$overnight+=$p['overnight_cents'];$baseUsed=true;$cursor=$ne;
        }
        $regular($cursor,$b);
    }elseif($delta>0)$segments[]=['kind'=>'grace','start'=>$start,'end'=>$end,'seconds'=>$seconds,'amount_cents'=>0];
    $total=$base+$succeeding+$overnight;if($total>9999999999)throw new InvalidArgumentException('Parking fee exceeds the billing limit.');
    return ['seconds'=>$seconds,'hours'=>intdiv($seconds+3599,3600),'amount'=>vpMoney($total),'amount_cents'=>$total,'rate'=>'tiered','rate_version'=>$p['id'],'policy'=>$p,'grace_applied'=>$free,'grace_remaining_seconds'=>max(0,$p['grace_seconds']-$seconds),'regular_cents'=>$base,'succeeding_cents'=>$succeeding,'succeeding_hours'=>$hours,'overnight_cents'=>$overnight,'overnight_periods'=>$nights,'segments'=>$segments,'needs_rate_review'=>(bool)$review,'review_reasons'=>$review,'extended'=>$delta>86400000000];
}
function vpSessionQuote(array $row,string $end): ?array {
    if(empty($row['actual_check_in']))return null;
    if(!empty($row['actual_check_out'])&&!empty($row['calculation_snapshot']))return json_decode($row['calculation_snapshot'],true,512,JSON_THROW_ON_ERROR);
    if((int)$row['fee_policy_version']<2)return vpQuote($row['actual_check_in'],$end,$row['rate_snapshot']?:'50.00');
    if(empty($row['rate_policy_snapshot']))throw new RuntimeException('The session rate snapshot is missing.');
    return vpCalculate($row['actual_check_in'],$end,json_decode($row['rate_policy_snapshot'],true,512,JSON_THROW_ON_ERROR));
}
function vpSaveRate(mysqli $db,array $input): int {
    if(!canAccess('parking.configure'))throw new InvalidArgumentException('Only authorized parking management may change rates.');
    if(($input['confirm_rate']??'')!=='1')throw new InvalidArgumentException('Confirm the new parking rate policy.');
    $effective=vpUtcInput(vpText($input,'effective_from',16,true));if($effective<vpNow())throw new InvalidArgumentException('Choose a future effective date/time.');
    $rate=vpDefaultRate();
    if(isset($input['grace_minutes'])){
        foreach(['base_price'=>'base_cents','hour_price'=>'hour_cents','overnight_price'=>'overnight_cents']as$field=>$target){$value=vpText($input,$field,12,true);if(!preg_match('/^\d{1,5}(?:\.\d{1,2})?$/D',$value))throw new InvalidArgumentException('Enter a valid price with up to two decimals.');$parts=explode('.',$value);$input[$target]=(int)$parts[0]*100+(int)str_pad($parts[1]??'',2,'0');}
        foreach(['grace_minutes'=>['grace_seconds',60,0,60],'base_hours'=>['base_seconds',3600,1,24]]as$field=>[$target,$factor,$min,$max]){$v=filter_var($input[$field]??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>$min,'max_range'=>$max]]);if($v===false||$v===null)throw new InvalidArgumentException('Enter valid grace minutes and initial hours.');$input[$target]=$v*$factor;}
    }
    foreach(['grace_seconds'=>[0,3600],'base_seconds'=>[3600,86400],'base_cents'=>[1,10000000],'hour_cents'=>[1,10000000],'overnight_cents'=>[1,10000000]]as$key=>[$min,$max]){
        $value=filter_var($input[$key]??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>$min,'max_range'=>$max]]);if($value===false||$value===null)throw new InvalidArgumentException('Check the rate values.');$rate[$key]=$value;
    }
    if($rate['grace_seconds']>$rate['base_seconds'])throw new InvalidArgumentException('Grace must fit within the initial parking period.');
    $rate['late_rule']=vpText($input,'late_rule',30,true);if(!in_array($rate['late_rule'],['review_late','present_at_start','arrive_before_end'],true))throw new InvalidArgumentException('Choose the late-arrival qualification rule.');
    $rate['extension_required']=($input['extension_required']??'')==='1'?1:0;$reason=vpText($input,'reason',1000,true);$actor=(int)$_SESSION['user_id'];
    $db->begin_transaction();try{$json=json_encode($rate,JSON_THROW_ON_ERROR);$s=$db->prepare('INSERT INTO parking_rate_versions(effective_from,policy_json,created_by,created_at,reason) VALUES(?,?,?,UTC_TIMESTAMP(6),?)');$s->bind_param('ssis',$effective,$json,$actor,$reason);$s->execute();$id=(int)$db->insert_id;if(!logAudit('configure','parking_rate',$id,'New effective visitor parking rate version. '.$reason,$db))throw new RuntimeException('Rate audit failed.');$db->commit();return $id;}catch(Throwable $error){$db->rollback();throw $error;}
}
function renderVisitorParkingRateCard(mysqli $db): void {
    $p=vpCurrentRate($db);$e=static fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');
    echo '<section class="vp-rate-card service-wide"><h3>Visitor Parking Rates</h3><div class="vp-rate-grid">';
    foreach(['Free grace period'=>'First '.intdiv($p['grace_seconds'],60).' minutes - FREE','Standard parking'=>'First '.($p['base_seconds']/3600).' hours - PHP '.vpMoney($p['base_cents']),'Succeeding hours'=>'PHP '.vpMoney($p['hour_cents']).' per started hour','Qualifying overnight'=>'PHP '.vpMoney($p['overnight_cents']).' - 10:00 PM to 6:00 AM','Extended parking'=>$p['extension_required']?'More than 24 hours - management approval required':'More than 24 hours - fees continue']as$title=>$value)echo '<p><strong>'.$e($title).'</strong><span>'.$e($value).'</span></p>';
    echo '</div><p>Parking fees begin only when Security confirms the vehicle\'s actual entry. The final amount is calculated when Security records the vehicle\'s checkout.</p><p>Visitors leaving within the first '.intdiv($p['grace_seconds'],60).' minutes are not charged. The overnight rate does not apply when the vehicle leaves before 6:00 AM.</p><p>After a qualifying overnight stay, PHP '.vpMoney($p['hour_cents']).' per additional started hour applies; the base fee does not restart.</p>';
    if($p['late_rule']==='review_late')echo '<p>Arrivals after 10:00 PM that remain until 6:00 AM need management rate review. Departure is still permitted.</p>';
    echo '<small>Rate version '.$p['id'].'</small><input type="hidden" name="parking_rate_version" value="'.$p['id'].'"></section>';
}
