<?php
require_once 'config.php';
forgetRememberToken();
session_unset();
session_destroy();
redirect('login.php');
