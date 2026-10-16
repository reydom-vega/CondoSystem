<?php
/** Verification is read-only; the scanner service records a deduplicated snapshot. */
function verifyAccessScan(mysqli $db, string $content): array {
    $parts=parse_url($content); $base=parse_url(buildUrl(''));
    $invalid=['recognized'=>false,'valid'=>false,'verification_result'=>'invalid','qr_type'=>'unknown','message'=>'Unrecognized QR code. No access authorization.','remarks'=>'Unknown or invalid QR verification attempt.'];
    if(!is_array($parts) || !isset($parts['host'],$parts['scheme'],$parts['path']) || isset($parts['user']) || isset($parts['pass']) || $parts['host']!==($base['host'] ?? '') || $parts['scheme']!==($base['scheme'] ?? '') || ($parts['port'] ?? null)!==($base['port'] ?? null)) return $invalid;
    $root=rtrim($base['path'] ?? '', '/'); parse_str($parts['query'] ?? '',$query); $pass=null; $type='unknown';
    if($parts['path']===$root.'/digital_id_verify.php') {
        if (!digitalIdCanReview()) return array_replace($invalid, ['recognized'=>true,'qr_type'=>'digital_id','message'=>'Access Not Authorized']);
        parse_str($parts['fragment'] ?? '', $fragment);
        $token = $fragment['credential'] ?? '';
        if (!is_string($token)) return $invalid;
        $verification = digitalIdVerify($db, $token, true);
        if ($verification['valid']) $verification['pass_url'] = $content;
        return $verification;
    } elseif($parts['path']===$root.'/resident_service_pass.php') {
        $id=filter_var($query['id'] ?? null,FILTER_VALIDATE_INT); $token=$query['token'] ?? '';
        if($id && is_string($token)) { ensureResidentServicesTables($db); $pass=getResidentServicePass($db,$id,$token,true); }
        if($pass) $type=!empty($pass['gate_data'])?'property':($pass['request_kind']==='visitor'?'visitor':'permit');
    } elseif($parts['path']===$root.'/parking_pass.php') {
        $id=filter_var($query['request_id'] ?? null,FILTER_VALIDATE_INT); $signature=$query['signature'] ?? '';
        if($id && is_string($signature) && preg_match('/^[a-f0-9]{64}$/D',$signature) && hash_equals(parkingPassSignature($id),$signature)) {
            $stmt=$db->prepare("SELECT pr.*,u.full_name,COALESCE(pr.sponsor_unit_snapshot,u.unit_number) unit_number FROM parking_requests pr JOIN users u ON u.id=pr.user_id WHERE pr.id=? AND pr.request_type='visitor'"); $stmt->bind_param('i',$id); $stmt->execute(); $pass=$stmt->get_result()->fetch_assoc();
            if($pass && (!accountOccupiesUnit($db,(int)$pass['user_id'],$pass['unit_number']) || !accountHasUnitEntitlement($db,(int)$pass['user_id'],$pass['unit_number']) || !residentUserHasPermission($db,(int)$pass['user_id'],'resident.parking.request'))) {
                if(canAccess('security.gate')&&!empty($pass['actual_check_in'])&&empty($pass['actual_check_out']))$pass['exit_only']=true;else $pass=null;
            }
        }
        $type='parking';
    } else return $invalid;
    if(!$pass) return array_replace($invalid,['recognized'=>true,'message'=>'Invalid or revoked access pass.','remarks'=>'Invalid or revoked verification token.']);
    $status=$type==='property'?gateEffectiveStatus($pass):$pass['status'];
    $outcome='invalid'; $valid=false;
    if($status==='cancelled') $outcome='cancelled';
    elseif(in_array($status,['completed','checked_out'],true)) $outcome='already_used';
    elseif($status==='expired' || ($status!=='checked_in' && $type!=='property' && ($pass['end_date'] ?: $pass['start_date'])<date('Y-m-d'))) $outcome='expired';
    elseif($type==='property') $valid=gateValid($pass);
    elseif(in_array($status,['approved','checked_in'],true) && $pass['start_date']<=date('Y-m-d')) {
        $valid=match($type) {
            'visitor' => visitorAccessApprovalsComplete($db, $pass),
            'parking' => getParkingPass((int)$pass['id'],$signature)!==null,
            default => true,
        };
    }
    if($valid) $outcome='valid';
    if(!empty($pass['exit_only'])){$valid=true;$outcome='valid';}
    $message=$valid?($type==='property'?'Property Gate Pass is valid and ready for physical verification.':($status==='checked_in'?'Visitor is checked in. Verify identity before confirming exit.':'Visitor QR verified successfully. Confirm identity before admitting.')):match($outcome) {'expired'=>'Pass has expired.','cancelled'=>'Pass has been cancelled.','already_used'=>'Pass has already been used. Confirmed movements cannot be replayed.',default=>'Access refused: pass is not currently authorized.'};
    if(!empty($pass['exit_only']))$message='Entry authorization revoked. Exit verification only: confirm the visitor’s identity and actual checkout. Sponsoring account access has ended.';
    $visitorInfo = [];
    if ($type === 'visitor') {
        $vehicle = visitorPassVehicle($db, (int)$pass['id']);
        if(visitorParkingFeesReady($db)){
            $session=$db->query('SELECT id FROM parking_requests WHERE visitor_registration_id='.(int)$pass['id']." AND status='approved' ORDER BY id DESC LIMIT 1")->fetch_assoc();
            if($session && (canAccess('parking.review')||canAccess('parking.gate'))){ $vp=vpSessionData($db,vpLoad($db,(int)$session['id']),vpNow());$visitorInfo=['parking_session_id'=>$vp['id'],'parking_grace_remaining'=>isset($vp['quote']['grace_remaining_seconds'])?gmdate('i:s',$vp['quote']['grace_remaining_seconds']):null,'parking_rate_review'=>!empty($vp['quote']['needs_rate_review'])?'Subject to management rate review':null,'parking_slot'=>$vp['slot'],'parking_compliance'=>$vp['compliance_label'],'parking_rate'=>'See the applied visitor parking rate breakdown','parking_time_in'=>$vp['arrival']?vpLocal($vp['arrival']):'Not checked in','parking_estimate'=>$vp['quote']?'PHP '.$vp['quote']['amount']:'Not started','parking_approved_until'=>$vp['authorized_until']?vpLocal($vp['authorized_until']):'Standard 24-hour limit']; }
        }
        $visitorInfo += ['identity_status' => $pass['identity_status'], 'visit_date' => $pass['start_date'],
            'visit_purpose' => $pass['details'], 'visitor_count' => (int)$pass['visitor_count'],
            'vehicle_plate' => $vehicle['vehicle_plate'] ?? '', 'vehicle_description' => $vehicle['vehicle_description'] ?? '',
            'parking_status' => $vehicle['status'] ?? ''];
        if (!$valid && !visitorIdentityVerified($pass) && in_array($status, ['pending','approved'], true)) $message = 'Access refused: visitor identity is ' . $pass['identity_status'] . '.';
    }
    return $visitorInfo + ['exit_only'=>!empty($pass['exit_only']),'recognized'=>true,'valid'=>$valid,'verification_result'=>$outcome,'qr_type'=>$type,'reference_id'=>(int)$pass['id'],'subject_name'=>$type==='visitor'?($pass['visitor_name'] ?? ''):$pass['full_name'],'requester_name'=>$pass['full_name'],'unit_number'=>$type==='property'?gateData($pass)['unit_number']:$pass['unit_number'],'pass_number'=>$type==='property'?gateNumber((int)$pass['id']):($type==='visitor'?'VIS-':($type==='parking'?'PARK-':'PERMIT-')).str_pad((string)$pass['id'],8,'0',STR_PAD_LEFT),'message'=>$message,'remarks'=>$message,'pass_url'=>$content];
}
