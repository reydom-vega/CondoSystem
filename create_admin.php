<?php
require_once 'config.php';

//php create_admin.php username email password "Full Name" [role]
// php create_admin.php username email password "Full Name" role
// php create_admin.php admin1 admin1@example.com "Admin@12345" "Admin User" admin

if (PHP_SAPI !== 'cli') {
    exit("Run this file from the command line. Example: php create_admin.php admin admin@example.com \"Admin@12345\"\n");
}

if ($argc < 4) {
    exit("Usage: php create_admin.php <username> <email> <password> [full name]\n");
}

$username = trim($argv[1]);
$email = trim($argv[2]);
$password = $argv[3];
$fullName = trim($argv[4] ?? 'System Administrator');
$role = strtolower(trim($argv[5] ?? 'superadmin'));
$availableRoles = getUserRoles();

if (!preg_match('/^[a-zA-Z0-9_]{3,20}$/', $username)) {
    exit("Username must be 3-20 characters and contain only letters, numbers, or underscores.\n");
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    exit("Please provide a valid email address.\n");
}
if (!isPasswordStrong($password)) {
    exit("Password must be at least 8 characters and include uppercase, lowercase, a number, and a special character.\n");
}
if (!isset($availableRoles[$role]) || $role === 'resident') {
    exit('Role must be one of: admin, superadmin, treasurer, maintenance, security.\n');
}

$connection = connectDb();
$columns = $connection->query("SHOW COLUMNS FROM users LIKE 'role'");
if ($columns && $columns->num_rows === 0) {
    if (!$connection->query("ALTER TABLE users ADD role ENUM('resident', 'admin', 'superadmin', 'treasurer', 'maintenance', 'security') NOT NULL DEFAULT 'resident' AFTER locked_until")) {
        exit("Unable to add the role column: {$connection->error}\n");
    }
} else {
    ensureUserRoles($connection);
}

$check = $connection->prepare('SELECT id FROM users WHERE username = ? OR email = ? LIMIT 1');
$check->bind_param('ss', $username, $email);
$check->execute();
$existing = $check->get_result()->fetch_assoc();
$passwordHash = password_hash($password, PASSWORD_DEFAULT);

if ($existing) {
    $update = $connection->prepare("UPDATE users SET full_name = ?, password_hash = ?, is_verified = 1, is_active = 1, role = ?, staff_id = COALESCE(staff_id, CONCAT('STAFF-', LPAD(id, 5, '0'))) WHERE id = ?");
    $update->bind_param('sssi', $fullName, $passwordHash, $role, $existing['id']);
    $success = $update->execute();
    $message = 'Existing account assigned the ' . $availableRoles[$role] . ' role.';
} else {
    $contactNumber = 'ADMIN';
    $unitNumber = 'ADMIN';
    $isVerified = 1;
    $insert = $connection->prepare('INSERT INTO users (full_name, username, email, contact_number, unit_number, password_hash, is_verified, role) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
    $insert->bind_param('ssssssis', $fullName, $username, $email, $contactNumber, $unitNumber, $passwordHash, $isVerified, $role);
    $success = $insert->execute();
    if ($success) {
        $staffId = 'STAFF-' . str_pad((string)$connection->insert_id, 5, '0', STR_PAD_LEFT);
        $staffUpdate = $connection->prepare('UPDATE users SET staff_id = ? WHERE id = ?');
        $staffUpdate->bind_param('si', $staffId, $connection->insert_id);
        $success = $staffUpdate->execute();
    }
    $message = $availableRoles[$role] . ' account created.';
}

if (!$success) {
    exit("Admin account was not created: {$connection->error}\n");
}

echo $message . " Username: {$username}\n";
