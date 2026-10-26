<?php
// hospital/get_accident_patient.php - Get accident patient data
session_start();
include __DIR__ . '/../connection.php';

header('Content-Type: application/json');

if (empty($_SESSION['user_id']) || empty($_SESSION['role']) || strtolower($_SESSION['role']) !== 'hospital') {
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

$hospital_station_id = $_SESSION['station_id'] ?? null;
$accident_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($accident_id > 0 && $hospital_station_id) {
    $query = $conn->query("
        SELECT id, reporter_name, reporter_phone, injuries, accident_type,
               location_description, latitude, longitude, hospital_status
        FROM accidents 
        WHERE id = $accident_id AND assigned_hospital_id = $hospital_station_id
    ");
    
    if ($query && $query->num_rows > 0) {
        echo json_encode($query->fetch_assoc());
    } else {
        echo json_encode(['error' => 'Accident not found']);
    }
} else {
    echo json_encode(['error' => 'Invalid request']);
}
?>