<?php
// police/check_pending.php - Check pending accidents.
session_start();
include __DIR__ . '/../connection.php';

header('Content-Type: application/json');

// Check if user is logged in and is police
if (empty($_SESSION['user_id']) || strtolower($_SESSION['role'] ?? '') !== 'police') {
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

$police_id = $_SESSION['user_id'];

// Get police station ID
$user_query = $conn->query("
    SELECT police_station_id 
    FROM users 
    WHERE id = $police_id
");
$user_data = $user_query->fetch_assoc();
$police_station_id = $user_data['police_station_id'] ?? null;

if (!$police_station_id) {
    echo json_encode(['error' => 'No station assigned']);
    exit();
}

// Get counts
$query = $conn->query("
    SELECT 
        (SELECT COUNT(*) FROM accidents WHERE assigned_police_id = $police_station_id AND police_status IN ('assigned', 'dispatched', 'arrived')) as active_count,
        (SELECT COUNT(*) FROM accidents WHERE assigned_police_id = $police_station_id AND police_status = 'assigned') as pending_count,
        (SELECT COUNT(*) FROM accidents WHERE assigned_police_id = $police_station_id AND police_status = 'dispatched') as dispatched_count,
        (SELECT COUNT(*) FROM accidents WHERE assigned_police_id = $police_station_id AND police_status = 'resolved') as resolved_count,
        (SELECT COUNT(*) FROM accidents WHERE assigned_police_id = $police_station_id AND police_status = 'arrived') as arrived_count
");

$data = $query->fetch_assoc();

$response = [
    'active_count' => intval($data['active_count'] ?? 0),
    'pending_count' => intval($data['pending_count'] ?? 0),
    'dispatched_count' => intval($data['dispatched_count'] ?? 0),
    'resolved_count' => intval($data['resolved_count'] ?? 0),
    'arrived_count' => intval($data['arrived_count'] ?? 0)
];

echo json_encode($response);
?>