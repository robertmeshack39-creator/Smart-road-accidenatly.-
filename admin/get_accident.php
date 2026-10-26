<?php
// admin/get_accident.php - Get accident details for admin modal
session_start();
include __DIR__ . '/../connection.php';

header('Content-Type: application/json');

if (empty($_SESSION['user_id']) || strtolower($_SESSION['role'] ?? '') !== 'admin') {
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

$accident_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($accident_id <= 0) {
    echo json_encode(['error' => 'Invalid accident ID']);
    exit();
}

$query = $conn->query("
    SELECT 
        a.*,
        ps.name as police_station_name,
        hs.name as hospital_station_name
    FROM accidents a
    LEFT JOIN police_stations ps ON a.assigned_police_id = ps.id
    LEFT JOIN hospital_stations hs ON a.assigned_hospital_id = hs.id
    WHERE a.id = $accident_id
");

if (!$query || $query->num_rows == 0) {
    echo json_encode(['error' => 'Accident not found']);
    exit();
}

$data = $query->fetch_assoc();

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
    'police_station_name' => $data['police_station_name'] ?? 'Not assigned',
    'hospital_station_name' => $data['hospital_station_name'] ?? 'Not assigned',
    'police_status' => $data['police_status'] ?? 'pending',
    'status' => $data['status'] ?? $data['police_status'] ?? 'pending',
    'hospital_status' => $data['hospital_status'] ?? 'not_assigned',
    'photo_path' => $data['photo_path'] ?? null,
    'images' => $data['images'] ?? null
];

echo json_encode($response);
?>