<?php
/**
 * POLICE DISPATCH INCIDENT
 * RoadAlert TZ - Dispatch police to incident with audit logging
 */

session_start();
include __DIR__ . '/../connection.php';

header('Content-Type: application/json');

// ============================================
// 🔒 SECURITY CHECK
// ============================================
if (empty($_SESSION['user_id']) || strtolower($_SESSION['role'] ?? '') !== 'police') {
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

$police_id = $_SESSION['user_id'];

// ============================================
// 👤 GET POLICE STATION ID
// ============================================
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

$incident_id = isset($_POST['id']) ? intval($_POST['id']) : 0;

if ($incident_id <= 0) {
    echo json_encode(['error' => 'Invalid incident ID']);
    exit();
}

// ============================================
// 📋 CHECK IF INCIDENT EXISTS
// ============================================
$check = $conn->query("
    SELECT id, accident_type, location_description, police_status 
    FROM accidents 
    WHERE id = $incident_id 
    AND assigned_police_id = $police_station_id 
    AND police_status = 'assigned'
");

if ($check->num_rows == 0) {
    echo json_encode(['error' => 'Incident not found or already dispatched']);
    exit();
}

$incident = $check->fetch_assoc();

// ============================================
// 🔴 UPDATE STATUS TO DISPATCHED
// ============================================
$update = $conn->query("
    UPDATE accidents 
    SET police_status = 'dispatched' 
    WHERE id = $incident_id
");

if ($update) {
    // 🔴 AUDIT LOG - POLICE DISPATCH
    createAuditLog($conn, 'Dispatched police to accident | ACC-' . str_pad($incident_id, 4, '0', STR_PAD_LEFT) . ' | Type: ' . $incident['accident_type'] . ' | Location: ' . ($incident['location_description'] ?? 'Unknown'));
    
    echo json_encode(['success' => true, 'message' => 'Police dispatched successfully']);
} else {
    echo json_encode(['error' => 'Failed to dispatch: ' . $conn->error]);
}
?>