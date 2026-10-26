<?php
/*RoadAlert TZ - Citizens can report accidents with GPS, photos & AUTO-ASSIGN */
 

error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();
include "connection.php";

$success = false;
$error = '';
$report_id = null;

// ============================================
// 📁 CREATE UPLOAD FOLDER
// ============================================
$upload_dir = 'uploads/accidents/';
if (!is_dir($upload_dir)) {
    mkdir($upload_dir, 0777, true);
}

// ============================================
// 📝 HANDLE FORM SUBMISSION
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // ─── Collect & Sanitize Inputs ───
    $reporter_name  = trim($_POST['reporter_name']  ?? '');
    $reporter_phone = trim($_POST['reporter_phone'] ?? '');
    $reporter_email = trim($_POST['reporter_email'] ?? '');
    $accident_type  = trim($_POST['accident_type']  ?? 'Unknown');
    $injuries       = intval($_POST['injuries']      ?? 0);
    $hazards        = trim($_POST['hazards']         ?? '');
    $description    = trim($_POST['description']     ?? '');
    $latitude       = $_POST['latitude']             ?? null;
    $longitude      = $_POST['longitude']            ?? null;
    $location_name  = trim($_POST['location_name']  ?? '');

    // ─── Validate Required Fields ───
    if (empty($latitude) || empty($longitude)) {
        $error = 'GPS location is required. Please enable location access and try again.';
    } elseif (empty($reporter_name)) {
        $error = 'Reporter name is required for emergency contact.';
    } elseif (empty($reporter_phone)) {
        $error = 'Reporter phone number is required in case responders need to contact you.';
    } else {
        try {
            // ─── Handle Image Uploads ───
            $image_paths = [];
            
            if (isset($_FILES['accident_images']) && !empty($_FILES['accident_images']['name'][0])) {
                
                $allowed_types = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/jpg'];
                $max_file_size = 5 * 1024 * 1024; // 5MB
                
                foreach ($_FILES['accident_images']['tmp_name'] as $key => $tmp_name) {
                    if ($_FILES['accident_images']['error'][$key] === UPLOAD_ERR_OK) {
                        $file_name = $_FILES['accident_images']['name'][$key];
                        $file_type = $_FILES['accident_images']['type'][$key];
                        $file_size = $_FILES['accident_images']['size'][$key];
                        
                        if (!in_array($file_type, $allowed_types)) {
                            throw new Exception("File '$file_name' is not an allowed image type.");
                        }
                        
                        if ($file_size > $max_file_size) {
                            throw new Exception("File '$file_name' is too large. Maximum size is 5MB.");
                        }
                        
                        $ext = pathinfo($file_name, PATHINFO_EXTENSION);
                        $new_filename = 'accident_' . date('Ymd_His') . '_' . uniqid() . '.' . $ext;
                        $destination = $upload_dir . $new_filename;
                        
                        if (move_uploaded_file($tmp_name, $destination)) {
                            $image_paths[] = $destination;
                        } else {
                            throw new Exception("Failed to upload file: $file_name");
                        }
                    }
                }
            }
            
            $images_json = !empty($image_paths) ? json_encode($image_paths) : null;

            // ─── Insert into Accidents Table ───
            // Default statuses: police_status = 'pending', hospital_status = 'pending'
            $sql = "INSERT INTO accidents 
                    (reporter_name, reporter_phone, reporter_email,
                     accident_type, injuries, hazards,
                     description, images, latitude, longitude,
                     location_description, 
                     police_status, hospital_status,
                     created_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', 'pending', NOW())";
            
            $stmt = $conn->prepare($sql);
            $reporter_email_val = $reporter_email ?: null;

            $stmt->bind_param("ssssissssss", 
                $reporter_name,      // s
                $reporter_phone,     // s
                $reporter_email_val, // s
                $accident_type,      // s
                $injuries,           // i
                $hazards,            // s
                $description,        // s
                $images_json,        // s
                $latitude,           // s
                $longitude,          // s
                $location_name       // s
            );
            
            if (!$stmt->execute()) {
                throw new Exception("Insert failed: " . $stmt->error);
            }

            $report_id = $conn->insert_id;

            // ============================================================
            // 🔴 AUTO-ASSIGN TO NEAREST RESPONDERS (Automatic)
            // ============================================================
            if ($report_id && $latitude && $longitude) {
                
                // ─── Find Nearest Police Station ───
                $nearest_police = $conn->query("
                    SELECT id, name, 
                           (6371 * acos(cos(radians($latitude)) * cos(radians(latitude)) * 
                           cos(radians(longitude) - radians($longitude)) + 
                           sin(radians($latitude)) * sin(radians(latitude)))) AS distance
                    FROM police_stations 
                    WHERE is_active = 1
                    ORDER BY distance ASC 
                    LIMIT 1
                ");
                
                // ─── Find Nearest Hospital ───
                $nearest_hospital = $conn->query("
                    SELECT id, name, 
                           (6371 * acos(cos(radians($latitude)) * cos(radians(latitude)) * 
                           cos(radians(longitude) - radians($longitude)) + 
                           sin(radians($latitude)) * sin(radians(latitude)))) AS distance
                    FROM hospital_stations 
                    WHERE is_active = 1
                    ORDER BY distance ASC 
                    LIMIT 1
                ");
                
                $auto_updates = [];
                $log_details = 'Auto-assigned to nearest responders | ACC-' . str_pad($report_id, 4, '0', STR_PAD_LEFT);
                
                if ($nearest_police && $nearest_police->num_rows > 0) {
                    $police = $nearest_police->fetch_assoc();
                    $auto_updates[] = "assigned_police_id = " . $police['id'];
                    $auto_updates[] = "police_status = 'assigned'";
                    $log_details .= ' | Police: ' . $police['name'] . ' (' . round($police['distance'], 2) . ' km)';
                }
                
                if ($nearest_hospital && $nearest_hospital->num_rows > 0) {
                    $hospital = $nearest_hospital->fetch_assoc();
                    $auto_updates[] = "assigned_hospital_id = " . $hospital['id'];
                    $auto_updates[] = "hospital_status = 'assigned'";
                    $log_details .= ' | Hospital: ' . $hospital['name'] . ' (' . round($hospital['distance'], 2) . ' km)';
                }
                
                if (!empty($auto_updates)) {
                    $sql = "UPDATE accidents SET " . implode(', ', $auto_updates) . " WHERE id = $report_id";
                    $conn->query($sql);
                    
                    // 🔴 AUDIT LOG - AUTO ASSIGNMENT
                    createAuditLog($conn, $log_details);
                }
            }

            $success = true;

        } catch (Exception $e) {
            $error = 'Error: ' . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Report Accident | RoadAlert TZ</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"/>
    <style>
        /* ============================================
           CSS VARIABLES & RESET
           ============================================ */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 20px;
        }
        
        :root {
            --primary: #0052cc;
            --primary-dark: #003d99;
            --danger: #e63946;
            --danger-dark: #c1121f;
            --success: #00a86b;
            --warning: #ff8c42;
            --dark: #1e293b;
            --gray: #64748b;
            --light: #f8fafc;
            --white: #ffffff;
            --border: #e2e8f0;
            --shadow: 0 10px 25px -5px rgba(0,0,0,0.1);
            --shadow-lg: 0 20px 40px -12px rgba(0,0,0,0.15);
        }
        
        .container {
            max-width: 700px;
            margin: 0 auto;
        }
        
        .report-card {
            background: var(--white);
            border-radius: 28px;
            box-shadow: var(--shadow-lg);
            overflow: hidden;
            animation: slideUp 0.5s ease;
        }
        
        @keyframes slideUp {
            from { opacity: 0; transform: translateY(30px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        /* ─── Header ─── */
        .report-header {
            background: linear-gradient(135deg, var(--danger), var(--danger-dark));
            padding: 1.5rem;
            color: white;
            text-align: center;
        }
        
        .report-header h1 {
            font-size: 1.8rem;
            margin-bottom: 0.5rem;
        }
        
        .report-header p {
            opacity: 0.9;
            font-size: 0.9rem;
        }
        
        .report-body {
            padding: 2rem;
        }
        
        /* ─── GPS Status ─── */
        .gps-status {
            background: #e6f0ff;
            border-radius: 16px;
            padding: 1rem;
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 12px;
            border-left: 4px solid var(--primary);
        }
        
        .gps-dot {
            width: 12px;
            height: 12px;
            background: var(--success);
            border-radius: 50%;
            animation: pulse 1.5s infinite;
            flex-shrink: 0;
        }
        
        @keyframes pulse {
            0%, 100% { opacity: 1; transform: scale(1); }
            50% { opacity: 0.5; transform: scale(1.2); }
        }
        
        /* ─── Form Elements ─── */
        .form-group {
            margin-bottom: 1.5rem;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 600;
            color: var(--dark);
        }
        
        .form-group label i {
            color: var(--danger);
            margin-right: 8px;
        }
        
        .required-star {
            color: var(--danger);
            margin-left: 4px;
        }
        
        input, select, textarea {
            width: 100%;
            padding: 0.875rem;
            border: 2px solid var(--border);
            border-radius: 12px;
            font-family: inherit;
            font-size: 1rem;
            transition: all 0.3s;
        }
        
        input:focus, select:focus, textarea:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(0, 82, 204, 0.1);
        }
        
        .opt-badge {
            font-size: 0.72rem;
            font-weight: 400;
            color: var(--gray);
            background: #f1f5f9;
            border-radius: 6px;
            padding: 1px 7px;
            margin-left: 8px;
            vertical-align: middle;
        }
        
        .required-badge {
            font-size: 0.72rem;
            font-weight: 400;
            color: white;
            background: var(--danger);
            border-radius: 6px;
            padding: 1px 7px;
            margin-left: 8px;
            vertical-align: middle;
        }
        
        /* ─── Reporter Section ─── */
        .reporter-section {
            background: #f8fafc;
            border: 1.5px dashed var(--border);
            border-radius: 16px;
            padding: 1.2rem 1.4rem;
            margin-bottom: 1.5rem;
        }
        
        .reporter-section .sec-title {
            font-size: 0.85rem;
            font-weight: 600;
            color: var(--gray);
            text-transform: uppercase;
            letter-spacing: 0.06em;
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .reporter-section .sec-title i {
            color: var(--primary);
        }
        
        .two-col {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
        }
        
        /* ─── Type Grid ─── */
        .type-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 0.8rem;
            margin-top: 0.5rem;
        }
        
        .type-option {
            display: none;
        }
        
        .type-label {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 6px;
            padding: 0.8rem 0.5rem;
            background: var(--light);
            border: 2px solid var(--border);
            border-radius: 14px;
            cursor: pointer;
            transition: all 0.3s;
            text-align: center;
        }
        
        .type-label i {
            font-size: 1.6rem;
            width: 40px;
            height: 40px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--gray);
            transition: color 0.3s;
        }
        
        .type-label span {
            font-size: 0.75rem;
            font-weight: 500;
            color: var(--gray);
            line-height: 1.2;
        }
        
        .type-option:checked + .type-label {
            background: #e6f0ff;
            border-color: var(--primary);
        }
        
        .type-option:checked + .type-label i,
        .type-option:checked + .type-label span {
            color: var(--primary);
        }
        
        /* ─── Injury Counter ─── */
        .injury-counter {
            display: flex;
            align-items: center;
            justify-content: space-between;
            background: var(--light);
            padding: 1rem;
            border-radius: 16px;
            border: 2px solid var(--border);
        }
        
        .counter-buttons {
            display: flex;
            align-items: center;
            gap: 1rem;
        }
        
        .counter-btn {
            width: 44px;
            height: 44px;
            border-radius: 12px;
            border: none;
            background: var(--white);
            font-size: 1.5rem;
            cursor: pointer;
            transition: all 0.3s;
            box-shadow: var(--shadow);
        }
        
        .counter-btn.minus {
            color: var(--danger);
        }
        
        .counter-btn.plus {
            background: var(--primary);
            color: white;
        }
        
        .counter-btn:hover {
            transform: scale(1.05);
        }
        
        .injury-value {
            font-size: 2rem;
            font-weight: bold;
            min-width: 60px;
            text-align: center;
            color: var(--dark);
        }
        
        /* ─── Hazard Grid ─── */
        .hazard-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 0.8rem;
        }
        
        .hazard-item {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 0.7rem 1rem;
            background: var(--light);
            border-radius: 10px;
            cursor: pointer;
            transition: all 0.3s;
            border: 1px solid var(--border);
        }
        
        .hazard-item input {
            width: 18px;
            height: 18px;
            cursor: pointer;
            accent-color: var(--primary);
        }
        
        .hazard-item:hover {
            background: #e6f0ff;
            border-color: var(--primary);
        }
        
        .hazard-item i {
            font-size: 1.1rem;
            width: 24px;
            text-align: center;
        }
        
        /* ─── Photo Upload ─── */
        .photo-upload-area {
            border: 2px dashed var(--border);
            border-radius: 16px;
            padding: 2rem;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s;
            background: var(--light);
        }
        
        .photo-upload-area:hover {
            border-color: var(--primary);
            background: #e6f0ff;
        }
        
        .photo-upload-area i {
            font-size: 2.5rem;
            color: var(--gray);
            margin-bottom: 0.5rem;
        }
        
        .photo-upload-area p {
            color: var(--gray);
            font-size: 0.9rem;
        }
        
        .photo-upload-area .sub-text {
            font-size: 0.75rem;
            color: var(--gray);
        }
        
        .photo-preview-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(90px, 1fr));
            gap: 0.6rem;
            margin-top: 0.8rem;
        }
        
        .photo-preview-item {
            position: relative;
            border-radius: 10px;
            overflow: hidden;
            border: 2px solid var(--border);
            aspect-ratio: 1;
        }
        
        .photo-preview-item img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        
        .photo-preview-item .remove-photo {
            position: absolute;
            top: 4px;
            right: 4px;
            background: var(--danger);
            color: white;
            border: none;
            border-radius: 50%;
            width: 22px;
            height: 22px;
            font-size: 10px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            opacity: 0.8;
            transition: all 0.3s;
        }
        
        .photo-preview-item .remove-photo:hover {
            opacity: 1;
            transform: scale(1.1);
        }
        
        /* ─── Map ─── */
        #map {
            height: 250px;
            border-radius: 16px;
            margin-bottom: 1rem;
            border: 2px solid var(--border);
        }
        
        /* ─── Buttons ─── */
        .submit-btn {
            width: 100%;
            padding: 1rem;
            background: linear-gradient(135deg, var(--danger), var(--danger-dark));
            color: white;
            border: none;
            border-radius: 16px;
            font-size: 1.1rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
        }
        
        .submit-btn:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow);
        }
        
        .back-link {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            margin-top: 1.5rem;
            color: white;
            text-decoration: none;
            font-weight: 500;
            transition: opacity 0.3s;
        }
        
        .back-link:hover {
            opacity: 0.8;
        }
        
        /* ─── Alerts ─── */
        .alert {
            padding: 1rem;
            border-radius: 12px;
            margin-bottom: 1.5rem;
        }
        
        .alert-success {
            background: #d1fae5;
            border: 1px solid var(--success);
            color: #065f46;
        }
        
        .alert-danger {
            background: #fee2e2;
            border: 1px solid var(--danger);
            color: #991b1b;
        }
        
        /* ─── Success Modal ─── */
        .modal-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            backdrop-filter: blur(10px);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 9999;
            animation: fadeIn 0.3s ease;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }
        
        .modal-container {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(20px);
            border-radius: 32px;
            max-width: 500px;
            width: 90%;
            padding: 2rem;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
            animation: slideIn 0.3s ease;
            border: 1px solid rgba(255, 255, 255, 0.3);
        }
        
        @keyframes slideIn {
            from { transform: translateY(-50px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }
        
        .modal-success-icon {
            width: 80px;
            height: 80px;
            background: linear-gradient(135deg, var(--success), #059669);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 1.5rem;
        }
        
        .modal-success-icon i {
            font-size: 3rem;
            color: white;
        }
        
        .modal-container h2 {
            font-size: 1.8rem;
            color: var(--dark);
            text-align: center;
            margin-bottom: 0.5rem;
        }
        
        .modal-container p {
            color: var(--gray);
            text-align: center;
            margin-bottom: 0.5rem;
        }
        
        .modal-ref {
            background: #d1fae5;
            color: #065f46;
            border-radius: 12px;
            padding: 0.5rem 1rem;
            font-weight: 700;
            font-size: 1.1rem;
            text-align: center;
            display: inline-block;
            margin: 1rem auto;
        }
        
        .modal-buttons {
            display: flex;
            gap: 1rem;
            justify-content: center;
            margin-top: 1.5rem;
        }
        
        .modal-btn {
            padding: 0.7rem 1.5rem;
            border-radius: 12px;
            font-weight: 600;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s;
            cursor: pointer;
            border: none;
            font-size: 0.95rem;
        }
        
        .modal-btn-primary {
            background: var(--primary);
            color: white;
        }
        
        .modal-btn-primary:hover {
            background: var(--primary-dark);
            transform: translateY(-2px);
        }
        
        .modal-btn-danger {
            background: linear-gradient(135deg, var(--danger), var(--danger-dark));
            color: white;
        }
        
        .modal-btn-danger:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow);
        }
        
        /* ─── Info Box ─── */
        .info-box {
            margin-top: 1.5rem;
            padding: 1rem;
            background: #fef3c7;
            border-radius: 12px;
            text-align: center;
        }
        
        .info-box i {
            color: var(--warning);
        }
        
        .info-box span {
            font-size: 0.8rem;
            color: #92400e;
        }
        
        /* ─── Responsive ─── */
        @media (max-width: 600px) {
            body { padding: 10px; }
            .report-body { padding: 1.5rem; }
            .type-grid { grid-template-columns: repeat(2, 1fr); }
            .two-col { grid-template-columns: 1fr; }
        }
        
        @media (max-width: 400px) {
            .type-grid { grid-template-columns: 1fr 1fr; }
            .type-label { padding: 0.6rem 0.3rem; }
            .type-label i { font-size: 1.3rem; width: 32px; height: 32px; }
            .type-label span { font-size: 0.65rem; }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="report-card">
            <!-- ─── HEADER ─── -->
            <div class="report-header">
                <h1><i class="fas fa-exclamation-triangle"></i> Emergency Report</h1>
                <p>Provide critical details to dispatch responders immediately</p>
            </div>

            <div class="report-body">

                <?php if ($success): ?>
                <!-- ─── SUCCESS MODAL ─── -->
                <div class="modal-overlay" id="successModal">
                    <div class="modal-container">
                        <div class="modal-success-icon">
                            <i class="fas fa-check-circle"></i>
                        </div>
                        <h2>Emergency Alert Sent!</h2>
                        <p>Report has been submitted successfully.</p>
                        <p>Stay calm and remain at the scene.</p>
                        <div style="text-align: center;">
                            <div class="modal-ref">Report ID: #<?= htmlspecialchars($report_id) ?></div>
                        </div>
                        <div class="modal-buttons">
                            <a href="index.php" class="modal-btn modal-btn-primary">
                                <i class="fas fa-home"></i> Home
                            </a>
                            <a href="report.php" class="modal-btn modal-btn-danger">
                                <i class="fas fa-plus"></i> New Report
                            </a>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <?php if (!$success): ?>
                
                <?php if ($error): ?>
                <div class="alert alert-danger">
                    <i class="fas fa-times-circle"></i> <?= htmlspecialchars($error) ?>
                </div>
                <?php endif; ?>

                <!-- ─── GPS STATUS ─── -->
                <div class="gps-status" id="gpsStatus">
                    <div class="gps-dot"></div>
                    <div>
                        <strong><i class="fas fa-satellite-dish"></i> Detecting Location...</strong>
                        <p style="font-size:0.8rem;margin-top:4px">Please allow location access</p>
                    </div>
                </div>

                <!-- ─── FORM ─── -->
                <form id="reportForm" method="POST" action="report.php" enctype="multipart/form-data">

                    <!-- Accident Type -->
                    <div class="form-group">
                        <label><i class="fas fa-car-crash"></i> Accident Type</label>
                        <div class="type-grid">
                            <div>
                                <input type="radio" name="accident_type" id="head_on" value="Head-on Collision" class="type-option" checked>
                                <label for="head_on" class="type-label">
                                    <i class="fas fa-arrows-left-right"></i>
                                    <span>Head-on</span>
                                </label>
                            </div>
                            <div>
                                <input type="radio" name="accident_type" id="rollover" value="Rollover" class="type-option">
                                <label for="rollover" class="type-label">
                                    <i class="fas fa-truck"></i>
                                    <span>Rollover</span>
                                </label>
                            </div>
                            <div>
                                <input type="radio" name="accident_type" id="pedestrian" value="Pedestrian Hit" class="type-option">
                                <label for="pedestrian" class="type-label">
                                    <i class="fas fa-person-walking"></i>
                                    <span>Pedestrian</span>
                                </label>
                            </div>
                            <div>
                                <input type="radio" name="accident_type" id="multi_vehicle" value="Multi-Vehicle" class="type-option">
                                <label for="multi_vehicle" class="type-label">
                                    <i class="fas fa-car-crash"></i>
                                    <span>Multi-Vehicle</span>
                                </label>
                            </div>
                        </div>
                    </div>

                    <!-- Injuries -->
                    <div class="form-group">
                        <label><i class="fas fa-band-aid"></i> Number of Injured People</label>
                        <div class="injury-counter">
                            <span style="color:var(--gray)">Estimated injuries for EMS preparation</span>
                            <div class="counter-buttons">
                                <button type="button" class="counter-btn minus" onclick="decrementInjury()">
                                    <i class="fas fa-minus"></i>
                                </button>
                                <span class="injury-value" id="injuryDisplay">1</span>
                                <button type="button" class="counter-btn plus" onclick="incrementInjury()">
                                    <i class="fas fa-plus"></i>
                                </button>
                            </div>
                        </div>
                        <input type="hidden" name="injuries" id="injuryInput" value="1">
                    </div>

                    <!-- Hazards -->
                    <div class="form-group">
                        <label><i class="fas fa-exclamation-triangle"></i> Additional Hazards</label>
                        <div class="hazard-grid">
                            <label class="hazard-item">
                                <input type="checkbox" name="hazard_list[]" value="Fire">
                                <i class="fas fa-fire"></i><span>Fire Present</span>
                            </label>
                            <label class="hazard-item">
                                <input type="checkbox" name="hazard_list[]" value="Fuel Spill">
                                <i class="fas fa-oil-can"></i><span>Fuel Spill</span>
                            </label>
                            <label class="hazard-item">
                                <input type="checkbox" name="hazard_list[]" value="Trapped Persons">
                                <i class="fas fa-users-slash"></i><span>Trapped Persons</span>
                            </label>
                            <label class="hazard-item">
                                <input type="checkbox" name="hazard_list[]" value="Hazardous Material">
                                <i class="fas fa-skull"></i><span>Hazardous Material</span>
                            </label>
                        </div>
                        <input type="hidden" name="hazards" id="hazardsHidden">
                    </div>

                    <!-- Description -->
                    <div class="form-group">
                        <label><i class="fas fa-pen-alt"></i> Accident Description <span class="opt-badge">optional</span></label>
                        <textarea rows="3" name="description" placeholder="Describe what happened — landmarks, blocked road, vehicle types…"><?= htmlspecialchars($_POST['description'] ?? '') ?></textarea>
                    </div>

                    <!-- Photo Upload -->
                    <div class="form-group">
                        <label><i class="fas fa-camera"></i> Upload Accident Photos <span class="opt-badge">optional</span></label>
                        <div class="photo-upload-area" onclick="document.getElementById('photoInput').click()">
                            <i class="fas fa-cloud-upload-alt"></i>
                            <p><strong>Click to upload photos</strong></p>
                            <p class="sub-text">Supported: JPG, PNG, GIF, WEBP (Max 5MB each)</p>
                            <p class="sub-text">You can upload up to 5 photos</p>
                        </div>
                        <input type="file" name="accident_images[]" id="photoInput" accept="image/*" multiple style="display:none" onchange="handlePhotoPreview(event)">
                        
                        <div class="photo-preview-grid" id="photoPreviewGrid"></div>
                        <p class="sub-text" style="margin-top:0.5rem;color:var(--gray)">
                            <i class="fas fa-info-circle"></i> Photos will help responders understand the situation better
                        </p>
                    </div>

                    <!-- Reporter Information -->
                    <div class="reporter-section">
                        <div class="sec-title">
                            <i class="fas fa-user-circle"></i> Reporter Information
                            <span class="required-badge" style="background:var(--danger)">Name & Phone Required</span>
                        </div>

                        <div class="form-group" style="margin-bottom:1rem">
                            <label><i class="fas fa-user"></i> Full Name <span class="required-star">*</span></label>
                            <input type="text"
                                   name="reporter_name"
                                   placeholder="Your full name (required)"
                                   value="<?= htmlspecialchars($_POST['reporter_name'] ?? '') ?>"
                                   required>
                        </div>

                        <div class="two-col">
                            <div class="form-group" style="margin-bottom:0">
                                <label><i class="fas fa-phone"></i> Phone Number <span class="required-star">*</span></label>
                                <input type="tel"
                                       name="reporter_phone"
                                       placeholder="+255 7XX XXX XXX (required)"
                                       value="<?= htmlspecialchars($_POST['reporter_phone'] ?? '') ?>"
                                       required>
                            </div>
                            <div class="form-group" style="margin-bottom:0">
                                <label><i class="fas fa-envelope"></i> Email Address <span class="opt-badge">optional</span></label>
                                <input type="email"
                                       name="reporter_email"
                                       placeholder="you@example.com"
                                       value="<?= htmlspecialchars($_POST['reporter_email'] ?? '') ?>">
                            </div>
                        </div>
                    </div>

                    <!-- Map -->
                    <div class="form-group">
                        <label><i class="fas fa-map-marker-alt"></i> Accident Location (drag pin to correct)</label>
                        <div id="map"></div>
                    </div>

                    <input type="hidden" id="latitude" name="latitude">
                    <input type="hidden" id="longitude" name="longitude">
                    <input type="hidden" id="location_name" name="location_name">

                    <!-- Submit Button -->
                    <button type="submit" class="submit-btn" id="submitBtn">
                        <i class="fas fa-paper-plane"></i> SEND EMERGENCY ALERT
                    </button>
                </form>

                <!-- Call Police Button -->
                <button class="submit-btn" style="background:var(--primary);margin-top:1rem" onclick="window.location.href='tel:112'">
                    <i class="fas fa-phone-alt"></i> Call Police Directly (112)
                </button>

                <!-- Info Box -->
                <div class="info-box">
                    <i class="fas fa-shield-alt"></i>
                    <span>Police and nearest hospital will be notified via SMS within 5 seconds of submission.</span>
                </div>

                <!-- Back Link -->
                <a href="index.php" class="back-link">
                    <i class="fas fa-arrow-left"></i> Back to Home
                </a>

                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- ==========================================
    JAVASCRIPT
    ========================================== -->
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <script>
        // ─── Variables ──────────────────────────────────
        let currentLat = null, currentLng = null, map = null, marker = null, injuryCount = 1;
        let selectedFiles = [];
        const MAX_PHOTOS = 5;

        // ─── Injury Counter ────────────────────────────
        function incrementInjury() {
            injuryCount++;
            syncInjury();
        }

        function decrementInjury() {
            if (injuryCount > 0) {
                injuryCount--;
                syncInjury();
            }
        }

        function syncInjury() {
            document.getElementById('injuryDisplay').textContent = injuryCount;
            document.getElementById('injuryInput').value = injuryCount;
        }

        // ─── Photo Preview ─────────────────────────────
        function handlePhotoPreview(event) {
            const files = event.target.files;
            const grid = document.getElementById('photoPreviewGrid');
            
            if (selectedFiles.length + files.length > MAX_PHOTOS) {
                alert(`You can only upload up to ${MAX_PHOTOS} photos.`);
                event.target.value = '';
                return;
            }
            
            for (let i = 0; i < files.length; i++) {
                if (files[i].size > 5 * 1024 * 1024) {
                    alert(`File "${files[i].name}" is too large. Maximum size is 5MB.`);
                    continue;
                }
                selectedFiles.push(files[i]);
            }
            
            const dataTransfer = new DataTransfer();
            selectedFiles.forEach(file => dataTransfer.items.add(file));
            document.getElementById('photoInput').files = dataTransfer.files;
            
            renderPhotoPreviews();
        }

        function renderPhotoPreviews() {
            const grid = document.getElementById('photoPreviewGrid');
            grid.innerHTML = '';
            
            selectedFiles.forEach((file, index) => {
                const reader = new FileReader();
                reader.onload = function(e) {
                    const div = document.createElement('div');
                    div.className = 'photo-preview-item';
                    div.innerHTML = `
                        <img src="${e.target.result}" alt="Photo ${index + 1}">
                        <button type="button" class="remove-photo" onclick="removePhoto(${index})">
                            <i class="fas fa-times"></i>
                        </button>
                    `;
                    grid.appendChild(div);
                };
                reader.readAsDataURL(file);
            });
        }

        function removePhoto(index) {
            selectedFiles.splice(index, 1);
            
            const dataTransfer = new DataTransfer();
            selectedFiles.forEach(file => dataTransfer.items.add(file));
            document.getElementById('photoInput').files = dataTransfer.files;
            
            renderPhotoPreviews();
        }

        // ─── GPS Location ──────────────────────────────
        function getLocation() {
            const box = document.getElementById('gpsStatus');
            
            if (!navigator.geolocation) {
                box.innerHTML = `
                    <div class="gps-dot" style="background:#e63946"></div>
                    <div>
                        <strong>GPS Not Supported</strong>
                        <p style="font-size:0.8rem;margin-top:4px">Enter location manually on the map.</p>
                    </div>
                `;
                initMap(-6.7924, 39.2083);
                return;
            }
            
            navigator.geolocation.getCurrentPosition(
                function(pos) {
                    currentLat = pos.coords.latitude;
                    currentLng = pos.coords.longitude;
                    document.getElementById('latitude').value  = currentLat;
                    document.getElementById('longitude').value = currentLng;
                    
                    box.innerHTML = `
                        <div class="gps-dot" style="background:#00a86b"></div>
                        <div>
                            <strong><i class="fas fa-check-circle"></i> Location Detected</strong>
                            <p style="font-size:0.8rem;margin-top:4px">
                                Lat: ${currentLat.toFixed(5)}, Lng: ${currentLng.toFixed(5)}
                            </p>
                        </div>
                    `;
                    box.style.background = '#d1fae5';
                    box.style.borderLeftColor = '#00a86b';
                    
                    initMap(currentLat, currentLng);
                    reverseGeocode(currentLat, currentLng);
                },
                function() {
                    box.innerHTML = `
                        <div class="gps-dot" style="background:#ff8c42"></div>
                        <div>
                            <strong>Location Access Denied</strong>
                            <p style="font-size:0.8rem;margin-top:4px">Using Dar es Salaam as default. Drag the map pin.</p>
                        </div>
                    `;
                    currentLat = -6.7924;
                    currentLng = 39.2083;
                    document.getElementById('latitude').value  = currentLat;
                    document.getElementById('longitude').value = currentLng;
                    initMap(currentLat, currentLng);
                }
            );
        }

        // ─── Map ────────────────────────────────────────
        function initMap(lat, lng) {
            map = L.map('map').setView([lat, lng], 15);
            
            L.tileLayer('https://{s}.basemaps.cartocdn.com/light_all/{z}/{x}/{y}{r}.png', {
                attribution: '&copy; OSM contributors',
                subdomains: 'abcd',
                maxZoom: 19
            }).addTo(map);
            
            marker = L.marker([lat, lng], { draggable: true }).addTo(map);
            
            marker.on('dragend', function() {
                const p = marker.getLatLng();
                currentLat = p.lat;
                currentLng = p.lng;
                document.getElementById('latitude').value  = currentLat;
                document.getElementById('longitude').value = currentLng;
                reverseGeocode(currentLat, currentLng);
            });
        }

        // ─── Reverse Geocode ───────────────────────────
        function reverseGeocode(lat, lng) {
            fetch('https://nominatim.openstreetmap.org/reverse?format=json&lat=' + lat + '&lon=' + lng + '&zoom=18')
                .then(r => r.json())
                .then(d => {
                    if (d.display_name) {
                        document.getElementById('location_name').value = d.display_name;
                    }
                })
                .catch(() => {});
        }

        // ─── Form Submit ───────────────────────────────
        document.getElementById('reportForm').addEventListener('submit', function(e) {
            const checked = Array.from(document.querySelectorAll('input[name="hazard_list[]"]:checked'))
                .map(c => c.value);
            document.getElementById('hazardsHidden').value = checked.join(', ');

            if (!document.getElementById('latitude').value) {
                e.preventDefault();
                alert('GPS location not detected yet. Please wait or drag the map pin to your location.');
                return;
            }

            const btn = document.getElementById('submitBtn');
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> SUBMITTING REPORT...';
            btn.disabled = true;
        });

        // ─── Success Modal ─────────────────────────────
        document.addEventListener('DOMContentLoaded', function() {
            syncInjury();
            getLocation();
            
            const modal = document.getElementById('successModal');
            if (modal) {
                modal.addEventListener('click', function(e) {
                    if (e.target === modal) {
                        modal.style.display = 'none';
                    }
                });
            }
        });
    </script>
</body>
</html>