<?php
function ensureAmenityBookingSchema(mysqli $db): bool {
    if (!schemaMutationAllowed()) return true;
    if (!ensureBookingsTable($db)) return false;
    $column = $db->query("SHOW COLUMNS FROM bookings LIKE 'attendees'");
    return $column && ($column->num_rows > 0 || $db->query('ALTER TABLE bookings ADD attendees INT NOT NULL DEFAULT 1'));
}
function createAmenityBooking(mysqli $db, int $userId, string $amenity, string $date, string $time, int $attendees): bool {
    if (!ensureAmenityBookingSchema($db)) throw new RuntimeException('Booking storage is unavailable.');
    if (!in_array($amenity, ['Swimming Pool', 'Function Hall'], true) || !workflowDate($date) || !preg_match('/^(?:[01][0-9]|2[0-3]):00$/D', $time)) {
        throw new InvalidArgumentException('Choose an amenity, valid date, and a time on the hour.');
    }
    $capacity = $amenity === 'Swimming Pool' ? 20 : 100;
    if ($attendees < 1 || $attendees > $capacity) throw new InvalidArgumentException('Guest count must be from 1 to ' . $capacity . '.');
    if ($date . ' ' . $time <= date('Y-m-d H:i')) throw new InvalidArgumentException('Choose a future booking time.');
    if ($time < '06:00' || $time > '20:00') throw new InvalidArgumentException('Choose an hourly start time between 6 AM and 8 PM.');
    // One mutex per amenity/day keeps concurrent capacity checks consistent.
    $lockName = 'amenity:' . sha1($amenity . ':' . $date);
    $lock = $db->prepare('SELECT GET_LOCK(?, 5) AS acquired');
    $lock->bind_param('s', $lockName); $lock->execute();
    if ((int)$lock->get_result()->fetch_assoc()['acquired'] !== 1) throw new InvalidArgumentException('Booking is busy. Please try again.');
    try {
        $db->begin_transaction();
        $resident = $db->prepare("SELECT id FROM users WHERE id = ? AND role = 'resident' AND is_verified = 1 AND is_active = 1 AND status = 'approved' AND unit_number IS NOT NULL AND unit_number <> '' FOR UPDATE");
        $resident->bind_param('i', $userId); $resident->execute();
        if (!$resident->get_result()->fetch_assoc()) throw new InvalidArgumentException('An active approved resident with an assigned unit is required.');
        if (!residentUserHasPermission($db,$userId,'resident.amenities.book')) throw new InvalidArgumentException('Amenity bookings are disabled for this resident account.');
        $sql = "SELECT COUNT(*) AS bookings, COALESCE(SUM(attendees),0) AS guests, SUM(user_id = ?) AS own_bookings FROM bookings WHERE amenity = ? AND booking_date = ? AND status IN ('pending','confirmed')";
        if ($amenity === 'Swimming Pool') $sql .= ' AND booking_time = ?';
        $check = $db->prepare($sql);
        if ($amenity === 'Swimming Pool') $check->bind_param('isss', $userId, $amenity, $date, $time); else $check->bind_param('iss', $userId, $amenity, $date);
        $check->execute(); $existing = $check->get_result()->fetch_assoc();
        if ((int)$existing['own_bookings'] > 0) throw new InvalidArgumentException('You already have a booking for this period.');
        if (($amenity === 'Function Hall' && (int)$existing['bookings'] > 0) || ($amenity === 'Swimming Pool' && (int)$existing['guests'] + $attendees > 20)) {
            throw new InvalidArgumentException('This period is fully booked. Choose another date or time.');
        }
        $insert = $db->prepare('INSERT INTO bookings (user_id, amenity, booking_date, booking_time, attendees) VALUES (?, ?, ?, ?, ?)');
        $insert->bind_param('isssi', $userId, $amenity, $date, $time, $attendees);
        if (!$insert->execute()) throw new RuntimeException('Could not save the booking.');
        $db->commit();
        return true;
    } catch (Throwable $error) {
        $db->rollback();
        throw $error;
    } finally {
        $release = $db->prepare('SELECT RELEASE_LOCK(?)'); $release->bind_param('s', $lockName); $release->execute();
    }
}
