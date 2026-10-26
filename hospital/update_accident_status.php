<?php
/**
 * UPDATE ACCIDENT STATUS - Update hospital status for accident
 * RoadAlert TZ - Hospital Treatment Management
 */

session_start();
include __DIR__ . '/../connection.php';

// ============================================
// 🔒 SECURITY CHECK
// ============================================
if (empty($_SESSION['user_id']) || empty($_SESSION['role']) || strtolower($_SESSION['role']) !== 'hospital') {
    header("Location: ../login.php");
    exit();
}

// ============================================
// 👤 GET HOSPITAL INFO
// ============================================
$hospital_id = $_SESSION['user_id'];
$hospital_station_id = $_SESSION['hospital_station_id'] ?? 0;

$user_query = $conn->query("
    SELECT hospital_station_id FROM users WHERE id = $hospital_id
");
$user = $user_query->fetch_assoc();
$hospital_station_id = $user['hospital_station_id'] ?? 0;

// ============================================
// 📋 GET DATA
// ============================================
$accident_id = isset($_POST['accident_id']) ? intval($_POST['accident_id']) : 0;
$hospital_status = mysqli_real_escape_string($conn, $_POST['hospital_status'] ?? 'patient_received');

if ($accident_id == 0) {
    header("Location: treatment.php?error=missing_accident");
    exit();
}

// ============================================
// 📋 UPDATE ACCIDENT STATUS
// ============================================
$sql = "UPDATE accidents SET hospital_status = '$hospital_status' WHERE id = $accident_id AND assigned_hospital_id = $hospital_station_id";

if ($conn->query($sql)) {
    // 🔴 AUDIT LOG
    createAuditLog($conn, 'Updated accident hospital status | ACC-' . str_pad($accident_id, 4, '0', STR_PAD_LEFT) . ' | Status: ' . $hospital_status);
    header("Location: treatment_details.php?accident_id=$accident_id&msg=status_updated");
} else {
    header("Location: treatment_details.php?accident_id=$accident_id&error=update_failed");
}
?>