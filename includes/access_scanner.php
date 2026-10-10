<?php
/** Verification is read-only; the scanner service records a deduplicated snapshot. */
function verifyAccessScan(mysqli $db, string $content): array {
    $parts=parse_url($content); $base=parse_url(buildUrl(''));
    $invalid=['recognized'=>false,'valid'=>false,'verification_result'=>'invalid','qr_type'=>'unknown','message'=>'Unrecognized QR code. No access authorization.','remarks'=>'Unknown or invalid QR verification attempt.'];
    if(!is_array($parts) || !isset($parts['host'],$parts['scheme'],$parts['path']) || isset($parts['user']) || isset($parts['pass']) || $parts['host']!==($base['host'] ?? '') || $parts['scheme']!==($base['scheme'] ?? '') || ($parts['port'] ?? null)!==($base['port'] ?? null)) return $invalid;
    $root=rtrim($base['path'] ?? '', '/'); parse_str($parts['query'] ?? '',$query); $pass=null; $type='unknown';
    if($parts['path']===$root.'/resident_service_pass.php') {
        $id=filter_var($query['id'] ?? null,FILTER_VALIDATE_INT); $token=$query['token'] ?? '';
        if($id && is_string($token)) { ensureResidentServicesTables($db); $pass=getResidentServicePass($db,$id,$token); }
        if($pass) $type=!empty($pass['gate_data'])?'property':($pass['request_kind']==='visitor'?'visitor':'permit');
    } elseif($parts['path']===$root.'/parking_pass.php') {
        $id=filter_var($query['request_id'] ?? null,FILTER_VALIDATE_INT); $signature=$query['signature'] ?? '';
        if($id && is_string($signature) && preg_match('/^[a-f0-9]{64}$/D',$signature) && hash_equals(parkingPassSignature($id),$signature)) {
            $stmt=$db->prepare("SELECT pr.*,u.full_name,u.unit_number FROM parking_requests pr JOIN users u ON u.id=pr.user_id WHERE pr.id=? AND pr.request_type='visitor'"); $stmt->bind_param('i',$id); $stmt->execute(); $pass=$stmt->get_result()->fetch_assoc();
            if($pass && !(residentContext($db,(int)$pass['user_id'])['approved'] ?? false)) $pass=null;
        }
        $type='parking';
    } else return $invalid;
    if(!$pass) return array_replace($invalid,['recognized'=>true,'message'=>'Invalid or revoked access pass.','remarks'=>'Invalid or revoked verification token.']);
    $status=$type==='property'?gateEffectiveStatus($pass):$pass['status'];
    $outcome='invalid'; $valid=false;
    if($status==='cancelled') $outcome='cancelled';
    elseif(in_array($status,['completed','checked_out'],true)) $outcome='already_used';
    elseif($status==='expired' || ($type!=='property' && ($pass['end_date'] ?: $pass['start_date'])<date('Y-m-d'))) $outcome='expired';
    elseif($type==='property') $valid=gateValid($pass);
    elseif(in_array($status,['approved','checked_in'],true) && $pass['start_date']<=date('Y-m-d')) {
        $valid=$type!=='parking' || getParkingPass((int)$pass['id'],$signature)!==null;
    }
    if($valid) $outcome='valid';
    $message=$valid?($type==='property'?'Property Gate Pass is valid and ready for physical verification.':($status==='checked_in'?'Visitor is checked in. Verify identity before confirming exit.':'Visitor QR verified successfully. Confirm identity before admitting.')):match($outcome) {'expired'=>'Pass has expired.','cancelled'=>'Pass has been cancelled.','already_used'=>'Pass has already been used. Confirmed movements cannot be replayed.',default=>'Access refused: pass is not currently authorized.'};
    return ['recognized'=>true,'valid'=>$valid,'verification_result'=>$outcome,'qr_type'=>$type,'reference_id'=>(int)$pass['id'],'subject_name'=>$type==='visitor'?($pass['visitor_name'] ?? ''):$pass['full_name'],'requester_name'=>$pass['full_name'],'unit_number'=>$type==='property'?gateData($pass)['unit_number']:$pass['unit_number'],'pass_number'=>$type==='property'?gateNumber((int)$pass['id']):($type==='visitor'?'VIS-':($type==='parking'?'PARK-':'PERMIT-')).str_pad((string)$pass['id'],8,'0',STR_PAD_LEFT),'message'=>$message,'remarks'=>$message,'pass_url'=>$content];
}