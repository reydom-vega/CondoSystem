<?php
require_once 'config.php';

if (isLoggedIn()) {
    redirect(isSecurity() ? 'security/security_dashboard.php' : (isMaintenance() ? 'maintenance/maintenance_dashboard.php' : (isTreasurer() ? 'treasurer/treasurer_dashboard.php' : (isAdmin() ? 'admin/admin_dashboard.php' : 'resident/dashboard.php'))));
} else {
    redirect('login.php');
}
