<?php
// admin/assign_case.php - API for assigning cases
session_start();
include __DIR__ . '/../connection.php';

if (empty($_SESSION['user_id']) || strtolower($_SESSION['role'] ?? '') !== 'admin') {
    header("Location: ../login.php");
    exit();
}

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accident_id = intval($_POST['accident_id'] ?? 0);
    $police_id = intval($_POST['police_id'] ?? 0);
    $hospital_id = intval($_POST['hospital_id'] ?? 0);
    
    if ($accident_id > 0) {
        $sql = "UPDATE accidents SET ";
        $updates = [];
        
        // Check if police exists
        if ($police_id > 0) {
            $check = $conn->query("SELECT id FROM police_stations WHERE id = $police_id AND is_active = 1");
            if ($check && $check->num_rows > 0) {
                $updates[] = "assigned_police_id = $police_id";
            }
        }
        
        // Check if hospital exists
        if ($hospital_id > 0) {
            $check = $conn->query("SELECT id FROM hospital_stations WHERE id = $hospital_id AND is_active = 1");
            if ($check && $check->num_rows > 0) {
                $updates[] = "assigned_hospital_id = $hospital_id";
            }
        }
        
        if (!empty($updates)) {
            $updates[] = "status = 'dispatched'";
            $sql .= implode(", ", $updates) . " WHERE id = $accident_id";
            
            if ($conn->query($sql)) {
                echo json_encode(['success' => true, 'message' => 'Case assigned successfully']);
            } else {
                echo json_encode(['success' => false, 'message' => 'Database error: ' . $conn->error]);
            }
        } else {
            echo json_encode(['success' => false, 'message' => 'No valid assignment selected']);
        }
    } else {
        echo json_encode(['success' => false, 'message' => 'Invalid accident ID']);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'Only POST method allowed']);
}
?>