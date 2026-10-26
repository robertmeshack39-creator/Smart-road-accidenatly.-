<?php
// police/get_accident.php - Get accident details for modal.
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
        hs.name as hospital_station_name,
        a.police_status as status
    FROM accidents a
    LEFT JOIN police_stations ps ON a.assigned_police_id = ps.id
    LEFT JOIN hospital_stations hs ON a.assigned_hospital_id = hs.id
    WHERE a.id = $accident_id 
    AND a.assigned_police_id = $police_station_id
");

if (!$query || $query->num_rows == 0) {
    echo json_encode(['error' => 'Accident not found or not assigned to your station']);
    exit();
}

$data = $query->fetch_assoc();

// Format response
$response = [
    'id' => $data['id'],
    'reporter_name' => $data['reporter_name'] ?? 'Anonymous',
    'reporter_phone' => $data['reporter_phone'] ?? 'N/A',
    'reporter_email' => $data['reporter_email'] ?? 'N/A',
    'accident_type' => $data['accident_type'] ?? 'Unknown',
    'injuries' => intval($data['injuries'] ?? 0),
    'hazards' => $data['hazards'] ?? 'None reported',
    'description' => $data['description'] ?? '',
    'location_description' => $data['location_description'] ?? 'Unknown location',
    'latitude' => $data['latitude'] ?? null,
    'longitude' => $data['longitude'] ?? null,
    'created_at' => $data['created_at'] ?? date('Y-m-d H:i:s'),
    'police_station_name' => $data['police_station_name'] ?? 'Not assigned yet',
    'hospital_station_name' => $data['hospital_station_name'] ?? 'Not assigned',
    'police_status' => $data['police_status'] ?? 'pending',
    'status' => $data['status'] ?? $data['police_status'] ?? 'pending',
    'hospital_status' => $data['hospital_status'] ?? 'not_assigned',
    'photo_path' => $data['photo_path'] ?? null,
    'images' => $data['images'] ?? null
];

echo json_encode($response);
?>