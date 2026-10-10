<?php
/** Legacy bookmark: visitor log management now belongs to Admin / Superadmin. */
require_once '../config.php';
requireCapability('visitors.logs');
redirect('../superadmin/visitorlog.php');
