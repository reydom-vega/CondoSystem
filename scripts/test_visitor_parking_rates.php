<?php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once dirname(__DIR__).'/includes/visitor_parking_fees.php';
$checks=0;
function rateCheck(bool $ok,string $label):void{global $checks;if(!$ok)throw new RuntimeException($label);$checks++;}
function rateUtc(string $local):string{return (new DateTimeImmutable($local,new DateTimeZone('Asia/Manila')))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');}
foreach([300=>0,600=>0,900=>0,901=>5000,1500=>5000,1800=>5000,3600=>5000,7200=>5000,10800=>5000,10801=>7500,14400=>7500,18000=>10000,21600=>12500]as$seconds=>$cents){
    $start=rateUtc('2026-10-15 08:00:00');$end=(new DateTimeImmutable($start,new DateTimeZone('UTC')))->modify('+'.$seconds.' seconds')->format('Y-m-d H:i:s.u');$q=vpQuote($start,$end);rateCheck($q['amount_cents']===$cents,'Duration '.$seconds);rateCheck($q['seconds']===$seconds,'Duration preserved');
}
foreach([['22:00','00:00',5000],['22:00','02:00',7500],['22:00','05:00',15000],['22:00','06:00',30000],['22:00','08:00',35000],['20:00','09:00',42500]]as[$in,$out,$cents]){
    $q=vpQuote(rateUtc('2026-10-15 '.$in),rateUtc('2026-10-16 '.$out));rateCheck($q['amount_cents']===$cents,'Overnight '.$in.' - '.$out);rateCheck(!$q['needs_rate_review'],'Confirmed night policy');
}
$q=vpQuote(rateUtc('2026-10-15 23:00'),rateUtc('2026-10-16 06:00'));rateCheck($q['needs_rate_review'],'Unconfirmed late arrival requires review');rateCheck($q['overnight_cents']===0,'No unconfirmed overnight billed');
$p=vpDefaultRate();$p['late_rule']='arrive_before_end';$q=vpCalculate(rateUtc('2026-10-15 23:00'),rateUtc('2026-10-16 08:00'),$p);rateCheck($q['amount_cents']===35000&&!$q['needs_rate_review'],'Configured late qualification');
$p['late_rule']='present_at_start';$q=vpCalculate(rateUtc('2026-10-15 23:00'),rateUtc('2026-10-16 06:00'),$p);rateCheck($q['amount_cents']===15000&&!$q['needs_rate_review'],'Configured late hourly rule');
$q=vpQuote(rateUtc('2026-10-15 23:00'),rateUtc('2026-10-16 05:00'));rateCheck($q['amount_cents']===12500&&!$q['needs_rate_review'],'Early late-arrival departure remains hourly');
foreach([['2026-10-15 22:00','2026-10-17 06:00',100000],['2026-10-15 22:00','2026-10-17 02:00',80000],['2026-12-31 20:00','2027-01-01 09:00',42500],['2026-10-31 20:00','2026-11-01 09:00',42500]]as[$start,$end,$cents]){
    $q=vpQuote(rateUtc($start),rateUtc($end));rateCheck($q['amount_cents']===$cents,'Continuous multi-day / date boundary');$cursor=vpMicros(rateUtc($start));$sum=0;
    foreach($q['segments']as$segment){rateCheck(vpMicros($segment['start'])===$cursor,'No overlaps or gaps');$cursor=vpMicros($segment['end']);$sum+=$segment['amount_cents'];}rateCheck($cursor===vpMicros(rateUtc($end))&&$sum===$q['amount_cents'],'Complete itemized coverage');
}
rateCheck(vpQuote('2026-10-15 00:00:00.000000','2026-10-15 00:15:00.000001')['amount_cents']===5000,'A microsecond beyond grace is chargeable');
rateCheck(vpQuote('2026-10-15 00:00:00.000000','2026-10-15 03:00:00.000001')['amount_cents']===7500,'Started hour precision');
rateCheck(!vpQuote(rateUtc('2026-10-15 06:00'),rateUtc('2026-10-16 06:00'))['extended'],'Exactly 24 hours');
rateCheck(vpQuote(rateUtc('2026-10-15 06:00'),rateUtc('2026-10-16 06:00:01'))['extended'],'Beyond 24 hours');
rateCheck(vpQuote('2026-10-15 00:00:00','2026-10-15 01:30:00','50.00')['amount']==='100.00','Legacy explicit rate remains historical');
echo "Passed $checks centralized visitor parking rate checks. No database or provider used.\n";
