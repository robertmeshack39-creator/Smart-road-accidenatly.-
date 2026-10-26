<?php
// admin/get_user.php - Get user details for editing
header('Content-Type: application/json');
session_start();
include __DIR__ . '/../connection.php';

// Check if user is logged in and is admin
if (empty($_SESSION['user_id']) || empty($_SESSION['role']) || strtolower($_SESSION['role']) !== 'admin') {
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

if (!isset($_GET['id']) || empty($_GET['id'])) {
    echo json_encode(['error' => 'User ID required']);
    exit();
}

$id = intval($_GET['id']);
$query = "SELECT 
            u.*,
            ps.name as police_station_name,
            hs.name as hospital_station_name
          FROM users u
          LEFT JOIN police_stations ps ON u.police_station_id = ps.id
          LEFT JOIN hospital_stations hs ON u.hospital_station_id = hs.id
          WHERE u.id = $id";
$result = $conn->query($query);

if ($result->num_rows === 0) {
    echo json_encode(['error' => 'User not found']);
    exit();
}

$user = $result->fetch_assoc();

// Remove sensitive data
unset($user['password']);

echo json_encode($user);
?>