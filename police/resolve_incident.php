<?php
// police/resolve_incident.php - Resolve incident for police.
session_start();
include __DIR__ . '/../connection.php';

// Check if user is logged in and is police
if (empty($_SESSION['user_id']) || strtolower($_SESSION['role'] ?? '') !== 'police') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

$police_id = $_SESSION['user_id'];

// Get accident ID from POST or GET
$accident_id = isset($_POST['id']) ? intval($_POST['id']) : 0;
if ($accident_id <= 0 && isset($_GET['id'])) {
    $accident_id = intval($_GET['id']);
}

if ($accident_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid accident ID']);
    exit();
}

// Get police user's station
$user_query = $conn->query("
    SELECT police_station_id FROM users WHERE id = $police_id
");
$user = $user_query->fetch_assoc();
$police_station_id = $user['police_station_id'] ?? null;

if (!$police_station_id) {
    echo json_encode(['success' => false, 'message' => 'No station assigned to you']);
    exit();
}

// Check if accident exists and is assigned to this police station
$check = $conn->query("
    SELECT id, police_status, hospital_status, assigned_police_id 
    FROM accidents 
    WHERE id = $accident_id AND assigned_police_id = $police_station_id
");

if (!$check || $check->num_rows == 0) {
    echo json_encode(['success' => false, 'message' => 'Accident not found or not assigned to your station']);
    exit();
}

$accident = $check->fetch_assoc();

// Check if already resolved
if ($accident['police_status'] === 'resolved') {
    echo json_encode(['success' => false, 'message' => 'This accident is already resolved by police']);
    exit();
}

// 🔴 UPDATE police_status to resolved
$update = $conn->query("
    UPDATE accidents 
    SET police_status = 'resolved' 
    WHERE id = $accident_id AND assigned_police_id = $police_station_id
");

if (!$update || $conn->affected_rows == 0) {
    echo json_encode(['success' => false, 'message' => 'Failed to resolve: ' . $conn->error]);
    exit();
}

// ============================================
// 🔴 CHECK IF HOSPITAL HAS ALSO RESOLVED
// If both resolved, set status = 'resolved'
// ============================================
$check_status = $conn->query("
    SELECT hospital_status
    FROM accidents
    WHERE id = $accident_id
");

$row = $check_status->fetch_assoc();

if ($row['hospital_status'] === 'resolved') {
    // Both police and hospital resolved - fully resolved
    $conn->query("
        UPDATE accidents
        SET status = 'resolved'
        WHERE id = $accident_id
    ");
}

// ============================================
// RETURN SUCCESS RESPONSE
// ============================================
echo json_encode([
    'success' => true,
    'message' => 'Incident resolved successfully by police',
    'fully_resolved' => ($row['hospital_status'] === 'resolved')
]);
?>