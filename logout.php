<?php
/**
 * LOGOUT PAGE
 * RoadAlert TZ - User Logout
 */

session_start();

// Log logout activity
if (isset($_SESSION['user_id'])) {
    include "connection.php";
    createAuditLog($conn, 'User logged out');
}

// Destroy session and redirect
session_destroy();
header("Location: login.php");
exit();
?>