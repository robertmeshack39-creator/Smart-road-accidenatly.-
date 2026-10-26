<?php
/**
 * GET PATIENT - Fetch patient data for editing
 * RoadAlert TZ - Hospital Treatment Management
 */

session_start();
include __DIR__ . '/../connection.php';

// ============================================
// 🔒 SECURITY CHECK
// ============================================
if (empty($_SESSION['user_id']) || empty($_SESSION['role']) || strtolower($_SESSION['role']) !== 'hospital') {
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

// ============================================
// 📋 GET PATIENT ID
// ============================================
$patient_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($patient_id == 0) {
    echo json_encode(['error' => 'Invalid patient ID']);
    exit();
}

// ============================================
// 📋 FETCH PATIENT
// ============================================
$result = $conn->query("
    SELECT * FROM treatment_patients 
    WHERE id = $patient_id
");

if ($result && $result->num_rows > 0) {
    $patient = $result->fetch_assoc();
    echo json_encode($patient);
} else {
    echo json_encode(['error' => 'Patient not found']);
}
?>