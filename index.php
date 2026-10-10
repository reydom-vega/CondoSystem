<?php
require_once 'config.php';

if (isLoggedIn()) {
    redirect(dashboardPathForRole());
} else {
    redirect('homepage.php');
}
