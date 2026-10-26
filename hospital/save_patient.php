<?php
/**
 * SAVE PATIENT - Add or update patient for accident
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
$patient_id = isset($_POST['patient_id']) ? intval($_POST['patient_id']) : 0;
$accident_id = isset($_POST['accident_id']) ? intval($_POST['accident_id']) : 0;

if ($accident_id == 0) {
    header("Location: treatment.php?error=missing_accident");
    exit();
}

// Collect and sanitize data
$patient_name = mysqli_real_escape_string($conn, $_POST['patient_name'] ?? '');
$age = intval($_POST['age'] ?? 0);
$gender = mysqli_real_escape_string($conn, $_POST['gender'] ?? 'male');
$blood_pressure = mysqli_real_escape_string($conn, $_POST['blood_pressure'] ?? '');
$temperature = floatval($_POST['temperature'] ?? 0);
$diagnosis = mysqli_real_escape_string($conn, $_POST['diagnosis'] ?? '');
$treatment_given = mysqli_real_escape_string($conn, $_POST['treatment_given'] ?? '');
$medications = mysqli_real_escape_string($conn, $_POST['medications'] ?? '');
$doctor_name = mysqli_real_escape_string($conn, $_POST['doctor_name'] ?? '');
$treatment_status = mysqli_real_escape_string($conn, $_POST['treatment_status'] ?? 'pending');
$notes = mysqli_real_escape_string($conn, $_POST['notes'] ?? '');

// ============================================
// 📋 VALIDATE
// ============================================
if (empty($patient_name)) {
    header("Location: treatment_details.php?accident_id=$accident_id&error=name_required");
    exit();
}

// ============================================
// 📋 SAVE PATIENT
// ============================================
if ($patient_id > 0) {
    // ─── UPDATE EXISTING PATIENT ───
    $sql = "UPDATE treatment_patients SET 
        patient_name = '$patient_name',
        age = $age,
        gender = '$gender',
        blood_pressure = '$blood_pressure',
        temperature = $temperature,
        diagnosis = '$diagnosis',
        treatment_given = '$treatment_given',
        medications = '$medications',
        doctor_name = '$doctor_name',
        treatment_status = '$treatment_status',
        notes = '$notes',
        updated_at = CURRENT_TIMESTAMP
    WHERE id = $patient_id AND accident_id = $accident_id AND hospital_station_id = $hospital_station_id";
    
    if ($conn->query($sql)) {
        // 🔴 AUDIT LOG
        createAuditLog($conn, 'Updated patient treatment | ACC-' . str_pad($accident_id, 4, '0', STR_PAD_LEFT) . ' | Patient: ' . $patient_name . ' | Status: ' . $treatment_status);
        header("Location: treatment_details.php?accident_id=$accident_id&msg=patient_updated");
    } else {
        header("Location: treatment_details.php?accident_id=$accident_id&error=update_failed");
    }
} else {
    // ─── INSERT NEW PATIENT ───
    $sql = "INSERT INTO treatment_patients (
        accident_id, hospital_station_id, patient_name, age, gender,
        blood_pressure, temperature, diagnosis, treatment_given,
        medications, doctor_name, treatment_status, notes
    ) VALUES (
        $accident_id, $hospital_station_id, '$patient_name', $age, '$gender',
        '$blood_pressure', $temperature, '$diagnosis', '$treatment_given',
        '$medications', '$doctor_name', '$treatment_status', '$notes'
    )";
    
    if ($conn->query($sql)) {
        // 🔴 Update accident status to patient_received if not already
        $conn->query("UPDATE accidents SET hospital_status = 'patient_received' WHERE id = $accident_id AND hospital_status = 'assigned'");
        
        // 🔴 AUDIT LOG
        createAuditLog($conn, 'Added patient to treatment | ACC-' . str_pad($accident_id, 4, '0', STR_PAD_LEFT) . ' | Patient: ' . $patient_name);
        header("Location: treatment_details.php?accident_id=$accident_id&msg=patient_added");
    } else {
        header("Location: treatment_details.php?accident_id=$accident_id&error=insert_failed");
    }
}
?>