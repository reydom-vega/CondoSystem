<?php
/** Share the same history boundary between resident and management views. */
function amenityBookingIsHistory(array $booking): bool
{
    if (in_array($booking['status'], ['completed', 'rejected', 'cancelled'], true)) return true;
    $end = $booking['end_time'] ?? null;
    if (!$end) {
        $end = $booking['amenity'] === 'Function Hall' ? '21:00:00' : date('H:i:s', strtotime($booking['booking_time'] . ' +' . (int)(($booking['duration_hours'] ?? null) ?: 1) . ' hours'));
    }
    return $booking['booking_date'] . ' ' . $end <= date('Y-m-d H:i:s');
}
