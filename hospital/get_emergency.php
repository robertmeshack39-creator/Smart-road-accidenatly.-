<?php
// hospital/get_emergency.php - Get emergency details for modal
session_start();
include __DIR__ . '/../connection.php';

header('Content-Type: application/json');

// Check if user is logged in and is hospital
if (empty($_SESSION['user_id']) || empty($_SESSION['role']) || strtolower($_SESSION['role']) !== 'hospital') {
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

$hospital_id = $_SESSION['user_id'];
$hospital_station_id = $_SESSION['station_id'] ?? null;

// Get hospital station ID if not in session
if (!$hospital_station_id) {
    $user_query = $conn->query("SELECT hospital_station_id FROM users WHERE id = $hospital_id");
    $user_data = $user_query->fetch_assoc();
    $hospital_station_id = $user_data['hospital_station_id'] ?? null;
    $_SESSION['station_id'] = $hospital_station_id;
}

$accident_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($accident_id <= 0) {
    echo json_encode(['error' => 'Invalid accident ID']);
    exit();
}

// Get accident data
$query = $conn->query("
    SELECT 
        a.*,
        ps.name as police_station_name,
        hs.name as hospital_station_name
    FROM accidents a
    LEFT JOIN police_stations ps ON a.assigned_police_id = ps.id
    LEFT JOIN hospital_stations hs ON a.assigned_hospital_id = hs.id
    WHERE a.id = $accident_id 
    AND a.assigned_hospital_id = $hospital_station_id
");

if (!$query || $query->num_rows == 0) {
    echo json_encode(['error' => 'Accident not found or not assigned to your hospital']);
    exit();
}

$data = $query->fetch_assoc();

// Format response
$response = [
    'id' => $data['id'],
    'reporter_name' => $data['reporter_name'],
    'reporter_phone' => $data['reporter_phone'],
    'reporter_email' => $data['reporter_email'],
    'accident_type' => $data['accident_type'],
    'injuries' => $data['injuries'],
    'hazards' => $data['hazards'],
    'description' => $data['description'],
    'location_description' => $data['location_description'],
    'latitude' => $data['latitude'],
    'longitude' => $data['longitude'],
    'created_at' => $data['created_at'],
    'police_station_name' => $data['police_station_name'],
    'hospital_station_name' => $data['hospital_station_name'],
    'hospital_status' => $data['hospital_status'] ?? 'assigned',
    'status' => $data['status'] ?? $data['hospital_status'] ?? 'assigned',
    'image_path' => $data['photo_path'],
    'images' => $data['images']
];

echo json_encode($response);
?>