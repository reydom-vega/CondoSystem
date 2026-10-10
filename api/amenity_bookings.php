<?php
require_once __DIR__.'/../config.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
if(!isLoggedIn()) { http_response_code(401); echo json_encode(['success'=>false,'error'=>'Sign in to view or reserve an amenity.']); exit; }
$staff=canAccess('bookings.review');
if(!$staff && !canAccess('resident.amenities.book')) { http_response_code(403); echo json_encode(['success'=>false,'error'=>'Amenity booking is unavailable for this account.']); exit; }
try {
    $db=connectDb(); ensureAmenityBookingSchema($db);
    $integer=static function(mixed $value,string $name):int {
        $number=filter_var($value,FILTER_VALIDATE_INT);
        if($number===false || is_array($value)) throw new InvalidArgumentException('Enter a valid '.$name.'.');
        return $number;
    };
    $text=static function(mixed $value):string { if(!is_string($value)) throw new InvalidArgumentException('Invalid form value.'); return trim($value); };
    if($_SERVER['REQUEST_METHOD']==='GET') {
        echo json_encode(poolAvailability($db,$text($_GET['date'] ?? ''),$integer($_GET['duration'] ?? 1,'duration')),JSON_THROW_ON_ERROR); exit;
    }
    if($_SERVER['REQUEST_METHOD']!=='POST') { http_response_code(405); header('Allow: GET, POST'); echo json_encode(['success'=>false,'error'=>'Method not supported.']); exit; }
    $input=json_decode(file_get_contents('php://input'),true);
    if(!is_array($input)) throw new InvalidArgumentException('Invalid reservation request.');
    $token=is_string($input['csrf_token'] ?? null)?$input['csrf_token']:'';
    if($token==='' || !hash_equals(workflowCsrfToken(),$token)) { http_response_code(403); echo json_encode(['success'=>false,'error'=>'Your session token is invalid. Refresh and try again.']); exit; }
    $action=$text($input['action'] ?? '');
    if($action==='create' && !$staff) {
        createAmenityBooking($db,(int)$_SESSION['user_id'],$text($input['amenity'] ?? ''),$text($input['booking_date'] ?? ''),$text($input['booking_time'] ?? ''),$integer($input['attendees'] ?? 1,'guest count'),$integer($input['duration_hours'] ?? 1,'duration'));
        setFlash('success','Reservation submitted. Your request is waiting for Admin approval.');
        echo json_encode(['success'=>true,'message'=>'Reservation submitted. It is pending Admin approval; the schedule is reserved only after approval.']); exit;
    }
    if(!$staff && $action!=='cancelled') { http_response_code(403); echo json_encode(['success'=>false,'error'=>'Only Admin can review reservations.']); exit; }
    decideAmenityBooking($db,$integer($input['booking_id'] ?? 0,'booking ID'),$action,$text($input['reason'] ?? ''),($input['pmo_review'] ?? false)===true);
    echo json_encode(['success'=>true,'message'=>'Booking updated successfully.']);
} catch(AmenityScheduleConflict $error) {
    http_response_code(409); echo json_encode(['success'=>false,'code'=>'schedule_conflict','title'=>$error->approval?'Approval Failed':'Time Slot Already Booked','error'=>$error->getMessage()]);
} catch(InvalidArgumentException $error) { http_response_code(422); echo json_encode(['success'=>false,'code'=>'validation','error'=>$error->getMessage()]); }
catch(Throwable $error) { error_log('Amenity booking request: '.$error->getMessage()); http_response_code(503); echo json_encode(['success'=>false,'error'=>'Booking services are temporarily unavailable. Please try again.']); }
