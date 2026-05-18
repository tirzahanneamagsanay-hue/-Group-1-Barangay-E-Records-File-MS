<?php

// auth.php handles session_start() safely
require_once 'includes/auth.php';

if (isset($_SESSION['user_id'])) {
    require_once 'includes/db.php';
    $conn = getConnection();
    $uid  = (int) $_SESSION['user_id'];   // Cast to int for safety
    $ip   = $_SERVER['REMOTE_ADDR'] ?? 'unknown';

    $log = $conn->prepare(
        "INSERT INTO audit_log (user_id, action, details, ip_address)
         VALUES (?, 'LOGOUT', 'User logged out', ?)"
    );
    $log->bind_param("is", $uid, $ip);
    $log->execute();
    $log->close();
    $conn->close();
}

// Destroy session and redirect
logout();
?>