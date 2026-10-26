<?php
// hospital/get_ambulance.php - Get ambulance data for editing.
session_start();
include __DIR__ . '/../connection.php';

header('Content-Type: application/json');

// Check if user is logged in and is hospital
if (empty($_SESSION['user_id']) || empty($_SESSION['role']) || strtolower($_SESSION['role']) !== 'hospital') {
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

$hospital_id = $_SESSION['user_id'];
$ambulance_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

// 🔴 FIX: Get hospital_station_id from database using user_id
$user_query = $conn->query("
    SELECT hospital_station_id 
    FROM users 
    WHERE id = $hospital_id
");
$user_data = $user_query->fetch_assoc();
$hospital_station_id = $user_data['hospital_station_id'] ?? null;

if ($ambulance_id > 0 && $hospital_station_id) {
    $query = $conn->query("
        SELECT id, ambulance_name, plate_number, driver_name, driver_phone, 
               driver_license, model, type, capacity, status
        FROM ambulances 
        WHERE id = $ambulance_id AND hospital_station_id = $hospital_station_id
    ");
    
    if ($query && $query->num_rows > 0) {
        $data = $query->fetch_assoc();
        echo json_encode($data);
    } else {
        echo json_encode(['error' => 'Ambulance not found']);
    }
} else {
    echo json_encode(['error' => 'Invalid request - hospital_station_id: ' . $hospital_station_id]);
}
?>