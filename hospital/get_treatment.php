<?php
// hospital/get_treatment.php - Get treatment data
session_start();
include __DIR__ . '/../connection.php';

header('Content-Type: application/json');

if (empty($_SESSION['user_id']) || empty($_SESSION['role']) || strtolower($_SESSION['role']) !== 'hospital') {
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

$hospital_station_id = $_SESSION['station_id'] ?? null;
$treatment_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($treatment_id > 0 && $hospital_station_id) {
    $query = $conn->query("
        SELECT * FROM treatments 
        WHERE id = $treatment_id AND hospital_station_id = $hospital_station_id
    ");
    
    if ($query && $query->num_rows > 0) {
        $data = $query->fetch_assoc();
        // Decode patient details if exists
        if ($data['patient_details']) {
            $data['patient_details'] = json_decode($data['patient_details'], true);
        }
        echo json_encode($data);
    } else {
        echo json_encode(['error' => 'Treatment not found']);
    }
} else {
    echo json_encode(['error' => 'Invalid request']);
}
?>