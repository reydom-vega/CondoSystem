<?php
// One canonical maintenance workflow prevents legacy routes bypassing reviews.
require_once __DIR__ . '/../config.php';
requireCapability('maintenance.work');
redirect(buildUrl('superadmin/maintenancerequests.php'));
