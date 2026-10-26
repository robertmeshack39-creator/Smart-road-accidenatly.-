<?php
/**
 * ADMIN ACCIDENT REPORT FORM
 * RoadAlert TZ - Report new accident with AUTO-ASSIGN to nearest responders
 */

session_start();
include __DIR__ . '/../connection.php';

// ============================================
// 🔒 SECURITY CHECK
// ============================================
if (empty($_SESSION['user_id']) || strtolower($_SESSION['role'] ?? '') !== 'admin') {
    header("Location: ../login.php");
    exit();
}

// ============================================
// 📁 CREATE UPLOAD FOLDER
// ============================================
$upload_dir = __DIR__ . '/../uploads/accidents/';
if (!is_dir($upload_dir)) {
    mkdir($upload_dir, 0777, true);
}
// ============================================
// 📊 FETCH DASHBOARD STATS
// ============================================
$pending_count = $conn->query("
    SELECT COUNT(*) as count FROM accidents 
    WHERE police_status='pending' OR hospital_status='pending'
")->fetch_assoc()['count'];

// ─── 🔴 POLICE RESOLVED ───
$police_resolved = $conn->query("
    SELECT COUNT(*) as count FROM accidents 
    WHERE police_status='resolved'
")->fetch_assoc()['count'] ?? 0;

// ─── 🔴 HOSPITAL TREATED ───
$hospital_treated = $conn->query("
    SELECT COUNT(*) as count FROM accidents 
    WHERE hospital_status='treated'
")->fetch_assoc()['count'] ?? 0;

// ─── 🔴 TOTAL RESOLVED = Police Resolved + Hospital Treated ───
$resolved_count = $police_resolved + $hospital_treated;

// ============================================
// 📋 FETCH STATIONS FOR DROPDOWN
// ============================================
$police_stations = $conn->query("
    SELECT id, name, location FROM police_stations WHERE is_active = 1 ORDER BY name
");
$hospital_stations = $conn->query("
    SELECT id, name, location FROM hospital_stations WHERE is_active = 1 ORDER BY name
");

// ============================================
// 🔔 FETCH PENDING ACCIDENTS FOR NOTIFICATIONS
// ============================================
$pending_accidents = $conn->query("
    SELECT id, accident_type, location_description, police_status, hospital_status, 
           created_at, reporter_name, injuries 
    FROM accidents 
    WHERE police_status='pending' OR hospital_status='pending'
    ORDER BY created_at DESC LIMIT 10
");

// ============================================
// 🔴 AUTO-ASSIGNED TODAY FOR NOTIFICATION
// ============================================
$auto_assigned_today = $conn->query("
    SELECT COUNT(*) as count 
    FROM accidents 
    WHERE DATE(created_at) = CURDATE() 
    AND (assigned_police_id IS NOT NULL OR assigned_hospital_id IS NOT NULL)
    AND (police_status = 'assigned' OR hospital_status = 'assigned')
")->fetch_assoc()['count'] ?? 0;

// ─── 🔴 COMBINE FOR NOTIFICATION BADGE ───
$notification_count = $pending_count + $auto_assigned_today;

// ─── 🔴 FETCH AUTO-ASSIGNED LIST FOR DROPDOWN ───
$auto_assign_list = $conn->query("
    SELECT 
        a.id, a.accident_type, a.location_description, 
        a.created_at, a.reporter_name, a.injuries,
        ps.name as police_name,
        hs.name as hospital_name
    FROM accidents a
    LEFT JOIN police_stations ps ON a.assigned_police_id = ps.id
    LEFT JOIN hospital_stations hs ON a.assigned_hospital_id = hs.id
    WHERE DATE(a.created_at) = CURDATE() 
    AND (a.assigned_police_id IS NOT NULL OR a.assigned_hospital_id IS NOT NULL)
    AND (a.police_status = 'assigned' OR a.hospital_status = 'assigned')
    ORDER BY a.created_at DESC 
    LIMIT 5
");

// ============================================
// 👤 ADMIN USER INFO
// ============================================
$admin_name  = htmlspecialchars($_SESSION['name']  ?? 'System Admin');
$admin_email = htmlspecialchars($_SESSION['email'] ?? 'admin@roadalert.tz');
$admin_role  = htmlspecialchars($_SESSION['role']  ?? 'admin');
$admin_init  = strtoupper(substr($admin_name, 0, 1));

// ============================================
// 📝 HANDLE FORM SUBMISSION
// ============================================
$success = false;
$error = '';
$report_id = null;

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
    
    // ─── Admin Manual Assignment (Optional - Override Auto-Assign) ───
    $assigned_police_id = isset($_POST['assigned_police_id']) && $_POST['assigned_police_id'] > 0 
        ? intval($_POST['assigned_police_id']) 
        : null;
    $assigned_hospital_id = isset($_POST['assigned_hospital_id']) && $_POST['assigned_hospital_id'] > 0 
        ? intval($_POST['assigned_hospital_id']) 
        : null;
    
    // ─── Dual Status ───
    $police_status = isset($_POST['police_status']) 
        ? mysqli_real_escape_string($conn, $_POST['police_status']) 
        : 'pending';
    $hospital_status = isset($_POST['hospital_status']) 
        ? mysqli_real_escape_string($conn, $_POST['hospital_status']) 
        : 'pending';

    // ─── Validate Required Fields ───
    if (empty($latitude) || empty($longitude)) {
        $error = 'GPS location is required. Please select location on map.';
    } elseif (empty($reporter_name)) {
        $error = 'Reporter name is required.';
    } elseif (empty($reporter_phone)) {
        $error = 'Reporter phone number is required.';
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
                        $destination = 'uploads/accidents/' . $new_filename;
                        
                        if (move_uploaded_file($tmp_name, $upload_dir . $new_filename)) {
                            $image_paths[] = $destination;
                        } else {
                            throw new Exception("Failed to upload file: $file_name");
                        }
                    }
                }
            }
            
            $images_json = !empty($image_paths) ? json_encode($image_paths) : null;

            // ─── Insert into Accidents Table (start with pending) ───
            $sql = "INSERT INTO accidents 
                    (reporter_name, reporter_phone, reporter_email,
                     accident_type, injuries, hazards,
                     description, images, latitude, longitude,
                     location_description, 
                     police_status, hospital_status,
                     assigned_police_id, assigned_hospital_id, created_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', 'pending', NULL, NULL, NOW())";
            
            $stmt = $conn->prepare($sql);
            $reporter_email_val = $reporter_email ?: null;

            $stmt->bind_param("ssssissssss", 
                $reporter_name,           // s
                $reporter_phone,          // s
                $reporter_email_val,      // s
                $accident_type,           // s
                $injuries,                // i
                $hazards,                 // s
                $description,             // s
                $images_json,             // s
                $latitude,                // s
                $longitude,               // s
                $location_name            // s
            );
            
            if (!$stmt->execute()) {
                throw new Exception("Insert failed: " . $stmt->error);
            }

            $report_id = $conn->insert_id;

            // ============================================================
            // 🔴 AUTO-ASSIGN TO NEAREST RESPONDERS (Automatic)
            // ============================================================
            
            // Check if admin has manually assigned, if yes use manual assignment
            if ($assigned_police_id > 0 || $assigned_hospital_id > 0) {
                
                // ─── Use Manual Assignment ───
                $manual_updates = [];
                $log_details = 'Assigned case | ACC-' . str_pad($report_id, 4, '0', STR_PAD_LEFT);
                
                if ($assigned_police_id > 0) {
                    $manual_updates[] = "assigned_police_id = $assigned_police_id";
                    $manual_updates[] = "police_status = 'assigned'";
                    $police = $conn->query("SELECT name FROM police_stations WHERE id=$assigned_police_id")->fetch_assoc();
                    $log_details .= ' | Police: ' . $police['name'];
                }
                if ($assigned_hospital_id > 0) {
                    $manual_updates[] = "assigned_hospital_id = $assigned_hospital_id";
                    $manual_updates[] = "hospital_status = 'assigned'";
                    $hospital = $conn->query("SELECT name FROM hospital_stations WHERE id=$assigned_hospital_id")->fetch_assoc();
                    $log_details .= ' | Hospital: ' . $hospital['name'];
                }
                
                if (!empty($manual_updates)) {
                    $sql = "UPDATE accidents SET " . implode(', ', $manual_updates) . " WHERE id = $report_id";
                    $conn->query($sql);
                    
                    // 🔴 AUDIT LOG - MANUAL ASSIGNMENT
                    createAuditLog($conn, $log_details);
                }
                
            } else {
                
                // ─── AUTO-ASSIGN: Find Nearest Responders ───
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

            // 🔴 AUDIT LOG - ACCIDENT CREATED
            createAuditLog($conn, 'Created accident report | ACC-' . str_pad($report_id, 4, '0', STR_PAD_LEFT) . ' | Type: ' . $accident_type . ' | Injuries: ' . $injuries);

            $success = true;

        } catch (Exception $e) {
            $error = 'Error: ' . $e->getMessage();
        }
    }
}

// ============================================
// 🔧 HELPER FUNCTION
// ============================================
function is_active($page) {
    return basename($_SERVER['PHP_SELF']) === $page ? 'active' : '';
}
?>
<!-- REST OF HTML REMAINS THE SAME -->
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Report Accident — Admin</title>
    
    <!-- Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Sora:wght@300;400;500;600;700;800&family=Space+Grotesk:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    
    <!-- Leaflet Map -->
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    
    <style>
        /* ============================================
           CSS VARIABLES & RESET
           ============================================ */
        *, *::before, *::after {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }
        
        :root {
            --bg: #07111B;
            --bg2: #0A1825;
            --bg3: #0F2236;
            --bg4: #152C44;
            --bg5: #1C3654;
            --accent: #00E5A0;
            --accent2: #00B87A;
            --acc-lo: rgba(0, 229, 160, 0.1);
            --acc-lo2: rgba(0, 229, 160, 0.06);
            --yellow: #FACC15;
            --sky: #22D3EE;
            --red: #F43F5E;
            --amber: #FB923C;
            --purple: #A78BFA;
            --light: #D6E8F5;
            --muted: #4E7090;
            --muted2: #7A9BB5;
            --border: rgba(255, 255, 255, 0.06);
            --bord2: rgba(255, 255, 255, 0.11);
            --white: #FFFFFF;
            --sw: 258px;
            --th: 62px;
        }
        
        html, body {
            height: 100%;
            background: var(--bg);
            color: var(--light);
            font-family: 'Poppins', sans-serif;
            overflow: hidden;
        }
        
        ::-webkit-scrollbar {
            width: 4px;
            height: 4px;
        }
        ::-webkit-scrollbar-track {
            background: transparent;
        }
        ::-webkit-scrollbar-thumb {
            background: rgba(0, 229, 160, 0.2);
            border-radius: 99px;
        }
        ::-webkit-scrollbar-thumb:hover {
            background: rgba(0, 229, 160, 0.35);
        }
        
        .shell {
            display: flex;
            height: 100vh;
            overflow: hidden;
        }
        
        /* ============================================
           SIDEBAR STYLES
           ============================================ */
        .sidebar {
            width: var(--sw);
            flex-shrink: 0;
            background: var(--bg2);
            border-right: 1px solid var(--border);
            display: flex;
            flex-direction: column;
            position: relative;
            overflow: hidden;
            z-index: 50;
        }
        
        .sidebar::before {
            content: '';
            position: absolute;
            top: -100px;
            left: -80px;
            width: 300px;
            height: 300px;
            border-radius: 50%;
            pointer-events: none;
            background: radial-gradient(circle, rgba(0, 229, 160, 0.09) 0%, transparent 65%);
        }
        
        .sidebar::after {
            content: '';
            position: absolute;
            inset: 0;
            pointer-events: none;
            background-image: 
                linear-gradient(rgba(0, 229, 160, 0.02) 1px, transparent 1px),
                linear-gradient(90deg, rgba(0, 229, 160, 0.02) 1px, transparent 1px);
            background-size: 34px 34px;
        }
        
        .sb-brand {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 1.05rem 1.25rem;
            border-bottom: 1px solid var(--border);
            text-decoration: none;
            flex-shrink: 0;
            position: relative;
            z-index: 2;
        }
        
        .sb-logo {
            width: 38px;
            height: 38px;
            border-radius: 9px;
            flex-shrink: 0;
            background: linear-gradient(135deg, var(--accent), var(--accent2));
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.1rem;
            box-shadow: 0 0 18px rgba(0, 229, 160, 0.45);
            animation: glow 3s ease-in-out infinite;
        }
        
        @keyframes glow {
            0%, 100% { box-shadow: 0 0 16px rgba(0, 229, 160, 0.4); }
            50% { box-shadow: 0 0 32px rgba(0, 229, 160, 0.8); }
        }
        
        .sb-title {
            font-family: 'Sora', sans-serif;
            font-size: 1.15rem;
            font-weight: 800;
            letter-spacing: -0.01em;
            color: var(--white);
        }
        
        .sb-sub {
            font-size: 0.58rem;
            color: var(--muted);
            letter-spacing: 0.14em;
            text-transform: uppercase;
            font-family: 'JetBrains Mono', monospace;
            margin-top: 1px;
        }
        
        .sb-nav {
            flex: 1;
            overflow-y: auto;
            padding: 0.65rem 0;
            position: relative;
            z-index: 2;
        }
        
        .sb-sect {
            font-family: 'JetBrains Mono', monospace;
            font-size: 0.56rem;
            letter-spacing: 0.16em;
            text-transform: uppercase;
            color: var(--muted);
            padding: 0.6rem 1.25rem 0.25rem;
        }
        
        .sb-item {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 0.58rem 1rem;
            margin: 1px 0.55rem;
            border-radius: 9px;
            text-decoration: none;
            font-size: 0.8rem;
            font-weight: 500;
            color: var(--muted2);
            transition: background 0.18s, color 0.18s;
            cursor: pointer;
            border: none;
            background: transparent;
            width: calc(100% - 1.1rem);
            text-align: left;
            font-family: 'Space Grotesk', sans-serif;
            position: relative;
        }
        
        .sb-ic {
            width: 30px;
            height: 30px;
            border-radius: 7px;
            flex-shrink: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.82rem;
            background: rgba(255, 255, 255, 0.04);
            transition: background 0.18s, color 0.18s;
        }
        
        .sb-item:hover {
            background: var(--acc-lo2);
            color: var(--light);
        }
        .sb-item:hover .sb-ic {
            background: var(--acc-lo);
            color: var(--accent);
        }
        
        .sb-item.active {
            background: var(--acc-lo);
            color: var(--accent);
        }
        .sb-item.active .sb-ic {
            background: rgba(0, 229, 160, 0.18);
            color: var(--accent);
        }
        .sb-item.active::before {
            content: '';
            position: absolute;
            left: 0;
            top: 50%;
            transform: translateY(-50%);
            width: 3px;
            height: 56%;
            background: var(--accent);
            border-radius: 0 3px 3px 0;
        }
        
        .sb-badge {
            margin-left: auto;
            font-size: 0.58rem;
            font-weight: 700;
            padding: 0.14rem 0.42rem;
            border-radius: 999px;
            font-family: 'JetBrains Mono', monospace;
        }
        .sb-badge.red {
            background: rgba(244, 63, 94, 0.15);
            color: var(--red);
            border: 1px solid rgba(244, 63, 94, 0.25);
            animation: badgePulse 2s infinite;
        }
        .sb-badge.grn {
            background: rgba(0, 229, 160, 0.12);
            color: var(--accent);
            border: 1px solid rgba(0, 229, 160, 0.22);
        }
        
        @keyframes badgePulse {
            0%, 100% { box-shadow: 0 0 0 0 rgba(244, 63, 94, 0.35); }
            50% { box-shadow: 0 0 0 4px rgba(244, 63, 94, 0); }
        }
        
        .sb-logout-item {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 0.58rem 1rem;
            margin: 1px 0.55rem;
            border-radius: 9px;
            font-size: 0.8rem;
            font-weight: 500;
            color: var(--muted2);
            transition: background 0.18s, color 0.18s;
            cursor: pointer;
            border: none;
            background: transparent;
            width: calc(100% - 1.1rem);
            text-align: left;
            font-family: 'Space Grotesk', sans-serif;
            text-decoration: none;
        }
        
        .sb-logout-item .sb-ic {
            width: 30px;
            height: 30px;
            border-radius: 7px;
            flex-shrink: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.82rem;
            background: rgba(255, 255, 255, 0.04);
            transition: background 0.18s, color 0.18s;
        }
        
        .sb-logout-item:hover {
            background: rgba(244, 63, 94, 0.08);
            color: var(--red);
        }
        .sb-logout-item:hover .sb-ic {
            background: rgba(244, 63, 94, 0.14);
            color: var(--red);
        }
        
        .sb-divider {
            height: 1px;
            background: var(--border);
            margin: 0.5rem 0.8rem;
        }
        
        /* ============================================
           MAIN CONTENT AREA
           ============================================ */
        .main {
            flex: 1;
            display: flex;
            flex-direction: column;
            overflow: hidden;
            min-width: 0;
        }
        
        .topbar {
            height: var(--th);
            flex-shrink: 0;
            background: var(--bg2);
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: center;
            gap: 0.9rem;
            padding: 0 1.4rem;
            position: relative;
            z-index: 40;
        }
        
        .tb-title {
            font-family: 'Sora', sans-serif;
            font-size: 1.25rem;
            font-weight: 700;
            color: var(--white);
            flex: 1;
        }
        .tb-title i {
            margin-right: 0.5rem;
            color: var(--accent);
        }
        
        .tb-search {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            background: var(--bg3);
            border: 1px solid var(--bord2);
            border-radius: 9px;
            padding: 0.36rem 0.9rem;
            transition: border-color 0.2s;
        }
        .tb-search:focus-within {
            border-color: rgba(0, 229, 160, 0.4);
        }
        .tb-search input {
            background: none;
            border: none;
            outline: none;
            font-family: 'Space Grotesk', sans-serif;
            font-size: 0.78rem;
            color: var(--light);
            width: 185px;
        }
        .tb-search input::placeholder {
            color: var(--muted);
        }
        .tb-search i {
            color: var(--muted);
            font-size: 0.8rem;
        }
        
        .tb-acts {
            display: flex;
            gap: 0.5rem;
            align-items: center;
        }
        
        .live-pill {
            display: flex;
            align-items: center;
            gap: 0.38rem;
            background: rgba(0, 229, 160, 0.08);
            border: 1px solid rgba(0, 229, 160, 0.2);
            border-radius: 999px;
            padding: 0.26rem 0.75rem;
            font-family: 'JetBrains Mono', monospace;
            font-size: 0.6rem;
            color: var(--accent);
        }
        
        .live-dot {
            width: 6px;
            height: 6px;
            border-radius: 50%;
            background: var(--accent);
            animation: blink 1.3s step-end infinite;
        }
        
        @keyframes blink {
            0%, 100% { opacity: 1; }
            50% { opacity: 0; }
        }
        
        .notif-wrap {
            position: relative;
        }
        
        .notif-btn {
            width: 36px;
            height: 36px;
            border-radius: 9px;
            background: var(--bg3);
            border: 1px solid var(--bord2);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.88rem;
            color: var(--muted2);
            cursor: pointer;
            transition: background 0.18s, color 0.18s;
            position: relative;
        }
        .notif-btn:hover {
            background: var(--acc-lo);
            color: var(--accent);
        }
        
        .notif-count {
            position: absolute;
            top: -5px;
            right: -5px;
            background: var(--red);
            color: #fff;
            font-size: 0.54rem;
            font-weight: 700;
            width: 17px;
            height: 17px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: 'JetBrains Mono', monospace;
            border: 2px solid var(--bg2);
            animation: badgePulse 2s infinite;
        }
        
        .notif-drop {
            position: absolute;
            right: 0;
            top: calc(100% + 9px);
            width: 330px;
            background: var(--bg3);
            border: 1px solid var(--bord2);
            border-radius: 14px;
            overflow: hidden;
            box-shadow: 0 16px 48px rgba(0, 0, 0, 0.55);
            opacity: 0;
            transform: translateY(-8px) scale(0.97);
            pointer-events: none;
            transition: opacity 0.2s, transform 0.2s;
            z-index: 999;
        }
        .notif-drop.show {
            opacity: 1;
            transform: none;
            pointer-events: all;
        }
        
        .notif-head {
            padding: 0.8rem 1rem;
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .nh-title {
            font-weight: 700;
            font-size: 0.82rem;
            color: var(--white);
        }
        .nh-sub {
            font-size: 0.68rem;
            color: var(--muted);
        }
        
        .notif-list {
            max-height: 300px;
            overflow-y: auto;
        }
        
        .notif-item {
            display: flex;
            align-items: flex-start;
            gap: 0.65rem;
            padding: 0.7rem 1rem;
            border-bottom: 1px solid var(--border);
            cursor: pointer;
            transition: background 0.14s;
        }
        .notif-item:hover {
            background: var(--acc-lo2);
        }
        
        .ndot {
            width: 7px;
            height: 7px;
            border-radius: 50%;
            background: var(--red);
            flex-shrink: 0;
            margin-top: 4px;
            animation: badgePulse 1.5s infinite;
        }
        
        .nb {
            flex: 1;
            min-width: 0;
        }
        .nid {
            font-size: 0.75rem;
            font-weight: 700;
            color: var(--accent);
            font-family: 'JetBrains Mono', monospace;
        }
        .nloc {
            font-size: 0.7rem;
            color: var(--muted2);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            margin-top: 1px;
        }
        .nmeta {
            display: flex;
            gap: 0.5rem;
            align-items: center;
            margin-top: 0.28rem;
        }
        .npill {
            font-size: 0.58rem;
            padding: 0.13rem 0.45rem;
            border-radius: 999px;
            background: rgba(244, 63, 94, 0.1);
            color: var(--red);
            border: 1px solid rgba(244, 63, 94, 0.2);
            font-family: 'JetBrains Mono', monospace;
        }
        .ntime {
            font-size: 0.62rem;
            color: var(--muted);
        }
        
        .notif-foot {
            padding: 0.55rem 1rem;
            text-align: center;
            border-top: 1px solid var(--border);
        }
        .notif-foot a {
            font-size: 0.72rem;
            color: var(--sky);
            text-decoration: none;
            transition: opacity 0.2s;
        }
        .notif-foot a:hover {
            opacity: 0.7;
        }
        
        .profile-wrap {
            position: relative;
        }
        
        .profile-btn {
            display: flex;
            align-items: center;
            gap: 0.55rem;
            background: var(--bg3);
            border: 1px solid var(--bord2);
            border-radius: 10px;
            padding: 0.38rem 0.65rem 0.38rem 0.45rem;
            cursor: pointer;
            transition: background 0.18s, border-color 0.18s;
        }
        .profile-btn:hover {
            background: var(--acc-lo2);
            border-color: rgba(0, 229, 160, 0.25);
        }
        .profile-btn.open {
            background: var(--acc-lo);
            border-color: rgba(0, 229, 160, 0.35);
        }
        
        .pa {
            width: 30px;
            height: 30px;
            border-radius: 7px;
            flex-shrink: 0;
            background: linear-gradient(135deg, var(--accent), var(--accent2));
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.82rem;
            font-weight: 700;
            color: #07111B;
            box-shadow: 0 0 10px rgba(0, 229, 160, 0.35);
        }
        
        .pname {
            font-size: 0.76rem;
            font-weight: 600;
            color: var(--light);
            max-width: 90px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        
        .pchev {
            font-size: 0.7rem;
            color: var(--muted);
            transition: transform 0.22s;
        }
        .profile-btn.open .pchev {
            transform: rotate(180deg);
        }
        
        .profile-drop {
            position: absolute;
            right: 0;
            top: calc(100% + 9px);
            width: 240px;
            background: var(--bg3);
            border: 1px solid var(--bord2);
            border-radius: 14px;
            overflow: hidden;
            box-shadow: 0 16px 48px rgba(0, 0, 0, 0.55);
            opacity: 0;
            transform: translateY(-8px) scale(0.97);
            pointer-events: none;
            transition: opacity 0.2s, transform 0.2s;
            z-index: 999;
        }
        .profile-drop.show {
            opacity: 1;
            transform: none;
            pointer-events: all;
        }
        
        .pd-head {
            padding: 0.85rem 1rem 0.6rem;
            border-bottom: 1px solid var(--border);
        }
        .pd-name {
            font-weight: 700;
            font-size: 0.86rem;
            color: var(--white);
        }
        .pd-email {
            font-size: 0.67rem;
            color: var(--muted);
            margin-top: 2px;
            font-family: 'JetBrains Mono', monospace;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .pd-pill {
            display: inline-flex;
            align-items: center;
            gap: 0.28rem;
            background: var(--acc-lo);
            border: 1px solid rgba(0, 229, 160, 0.22);
            border-radius: 999px;
            padding: 0.18rem 0.6rem;
            margin-top: 0.45rem;
            font-size: 0.6rem;
            color: var(--accent);
            font-family: 'JetBrains Mono', monospace;
            letter-spacing: 0.06em;
            text-transform: uppercase;
        }
        
        .pd-items {
            padding: 0.35rem 0;
        }
        
        .pd-item {
            display: flex;
            align-items: center;
            gap: 0.65rem;
            padding: 0.52rem 0.9rem;
            font-size: 0.78rem;
            color: var(--muted2);
            text-decoration: none;
            cursor: pointer;
            transition: background 0.14s, color 0.14s;
            border: none;
            background: none;
            width: 100%;
            text-align: left;
            font-family: 'Space Grotesk', sans-serif;
        }
        .pd-item i {
            width: 14px;
            text-align: center;
            font-size: 0.78rem;
            color: var(--muted);
        }
        .pd-item:hover {
            background: var(--acc-lo2);
            color: var(--light);
        }
        .pd-item:hover i {
            color: var(--accent);
        }
        .pd-item.danger:hover {
            background: rgba(244, 63, 94, 0.08);
            color: var(--red);
        }
        .pd-item.danger:hover i {
            color: var(--red);
        }
        
        .pd-div {
            height: 1px;
            background: var(--border);
            margin: 0.3rem 0;
        }
        
        .tb-btn {
            display: flex;
            align-items: center;
            gap: 0.38rem;
            padding: 0.38rem 0.8rem;
            border-radius: 8px;
            font-size: 0.74rem;
            border: 1px solid var(--bord2);
            background: transparent;
            color: var(--light);
            cursor: pointer;
            transition: background 0.18s;
            font-family: 'Space Grotesk', sans-serif;
            text-decoration: none;
        }
        .tb-btn:hover {
            background: rgba(244, 63, 94, 0.08);
            color: var(--red);
            border-color: rgba(244, 63, 94, 0.25);
        }
        .tb-btn.primary {
            background: var(--accent);
            border-color: var(--accent);
            color: #07111B;
            font-weight: 600;
            box-shadow: 0 0 14px rgba(0, 229, 160, 0.3);
        }
        .tb-btn.primary:hover {
            background: var(--accent2);
            color: #07111B;
        }
        
        .content {
            flex: 1;
            overflow-y: auto;
            padding: 1.2rem 1.5rem;
        }
        
        /* ============================================
           FORM STYLES
           ============================================ */
        .form-container {
            max-width: 900px;
            margin: 0 auto;
        }
        
        .form-card {
            background: var(--bg3);
            border: 1px solid var(--border);
            border-radius: 16px;
            padding: 1.5rem;
        }
        
        .form-group {
            margin-bottom: 1.2rem;
        }
        
        .form-group label {
            display: block;
            font-size: 0.7rem;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            color: var(--muted);
            margin-bottom: 0.4rem;
            font-weight: 600;
        }
        .form-group label i {
            margin-right: 4px;
            color: var(--accent);
        }
        .form-group .required {
            color: var(--red);
        }
        
        .form-group input,
        .form-group select,
        .form-group textarea {
            width: 100%;
            background: var(--bg4);
            border: 1px solid var(--border);
            border-radius: 10px;
            padding: 0.7rem 0.85rem;
            font-family: 'Space Grotesk', sans-serif;
            font-size: 0.82rem;
            color: var(--light);
            outline: none;
            transition: all 0.2s;
        }
        
        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            border-color: var(--accent);
            box-shadow: 0 0 0 3px rgba(0, 229, 160, 0.12);
        }
        
        .form-group textarea {
            resize: vertical;
            min-height: 80px;
        }
        
        .form-group .hint {
            font-size: 0.6rem;
            color: var(--muted);
            margin-top: 4px;
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
            gap: 0.5rem;
            margin-top: 0.3rem;
        }
        
        .type-option {
            display: none;
        }
        
        .type-label {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 4px;
            padding: 0.6rem;
            background: var(--bg4);
            border: 2px solid var(--border);
            border-radius: 10px;
            cursor: pointer;
            transition: all 0.2s;
            text-align: center;
            font-size: 0.7rem;
            color: var(--muted2);
        }
        
        .type-label i {
            font-size: 1.3rem;
            color: var(--muted);
            transition: all 0.2s;
        }
        
        .type-option:checked + .type-label {
            background: var(--acc-lo);
            border-color: var(--accent);
        }
        .type-option:checked + .type-label i {
            color: var(--accent);
        }
        .type-option:checked + .type-label span {
            color: var(--light);
        }
        
        /* ─── Injury Counter ─── */
        .injury-counter {
            display: flex;
            align-items: center;
            gap: 1rem;
            background: var(--bg4);
            padding: 0.5rem 1rem;
            border-radius: 10px;
            border: 1px solid var(--border);
        }
        
        .injury-counter .counter-btn {
            width: 36px;
            height: 36px;
            border-radius: 8px;
            border: none;
            font-size: 1.2rem;
            cursor: pointer;
            transition: all 0.2s;
        }
        .injury-counter .counter-btn.minus {
            background: rgba(244, 63, 94, 0.15);
            color: var(--red);
        }
        .injury-counter .counter-btn.plus {
            background: var(--accent);
            color: #07111B;
        }
        .injury-counter .injury-value {
            font-size: 1.5rem;
            font-weight: 700;
            min-width: 40px;
            text-align: center;
            color: var(--white);
        }
        
        /* ─── Hazard Grid ─── */
        .hazard-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(140px, 1fr));
            gap: 0.5rem;
        }
        
        .hazard-item {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 0.5rem 0.7rem;
            background: var(--bg4);
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.2s;
            border: 1px solid var(--border);
        }
        .hazard-item:hover {
            background: var(--acc-lo2);
        }
        .hazard-item input[type="checkbox"] {
            width: 16px;
            height: 16px;
            accent-color: var(--accent);
            cursor: pointer;
        }
        
        /* ─── Photo Upload ─── */
        .photo-upload-area {
            border: 2px dashed var(--border);
            border-radius: 12px;
            padding: 1.5rem;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s;
            background: var(--bg4);
        }
        .photo-upload-area:hover {
            border-color: var(--accent);
            background: var(--acc-lo2);
        }
        .photo-upload-area i {
            font-size: 2rem;
            color: var(--muted);
            margin-bottom: 0.3rem;
        }
        
        .photo-preview-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(80px, 1fr));
            gap: 0.5rem;
            margin-top: 0.5rem;
        }
        
        .photo-preview-item {
            position: relative;
            border-radius: 8px;
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
            top: 2px;
            right: 2px;
            background: var(--red);
            color: #fff;
            border: none;
            border-radius: 50%;
            width: 20px;
            height: 20px;
            font-size: 10px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        /* ─── Map ─── */
        #map {
            height: 250px;
            border-radius: 10px;
            border: 1px solid var(--border);
            background: #0A1825;
        }
        
        /* ─── Submit Button ─── */
        .btn-submit {
            width: 100%;
            padding: 0.8rem;
            background: linear-gradient(135deg, var(--accent), var(--accent2));
            color: #07111B;
            border: none;
            border-radius: 10px;
            font-size: 0.95rem;
            font-weight: 700;
            font-family: 'Sora', sans-serif;
            cursor: pointer;
            transition: all 0.2s;
            box-shadow: 0 0 20px rgba(0, 229, 160, 0.2);
        }
        .btn-submit:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 30px rgba(0, 229, 160, 0.3);
        }
        
        /* ─── Error Box ─── */
        .error-box {
            background: rgba(244, 63, 94, 0.1);
            border: 1px solid rgba(244, 63, 94, 0.2);
            border-radius: 10px;
            padding: 0.8rem 1rem;
            margin-bottom: 1rem;
            color: var(--red);
        }
        
        /* ─── Profile Modal ─── */
        .modal-bg {
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, 0.65);
            backdrop-filter: blur(5px);
            z-index: 6000;
            display: flex;
            align-items: center;
            justify-content: center;
            opacity: 0;
            pointer-events: none;
            transition: opacity 0.22s;
        }
        .modal-bg.show {
            opacity: 1;
            pointer-events: all;
        }
        
        .modal {
            background: var(--bg3);
            border: 1px solid var(--bord2);
            border-radius: 18px;
            padding: 1.8rem;
            width: 100%;
            max-width: 420px;
            box-shadow: 0 24px 80px rgba(0, 0, 0, 0.65);
            transform: translateY(18px) scale(0.97);
            transition: transform 0.22s;
            position: relative;
        }
        .modal-bg.show .modal {
            transform: none;
        }
        
        .modal-close {
            position: absolute;
            top: 0.9rem;
            right: 0.9rem;
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid var(--border);
            border-radius: 7px;
            width: 27px;
            height: 27px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.7rem;
            color: var(--muted2);
            cursor: pointer;
            transition: background 0.18s, color 0.18s;
        }
        .modal-close:hover {
            background: rgba(244, 63, 94, 0.12);
            color: var(--red);
        }
        
        .modal-title {
            font-family: 'Sora', sans-serif;
            font-size: 1.4rem;
            font-weight: 800;
            color: var(--white);
            margin-bottom: 0.25rem;
        }
        .modal-sub {
            font-size: 0.75rem;
            color: var(--muted);
            margin-bottom: 1.3rem;
        }
        .modal-ref {
            text-align: center;
            font-size: 1.1rem;
            font-weight: 700;
            color: var(--accent);
            font-family: 'JetBrains Mono', monospace;
            padding: 0.8rem;
            background: var(--acc-lo);
            border-radius: 10px;
            margin: 1rem 0;
        }
        
        .modal-av-row {
            display: flex;
            align-items: center;
            gap: 0.9rem;
            padding: 0.8rem;
            background: rgba(255, 255, 255, 0.03);
            border: 1px solid var(--border);
            border-radius: 11px;
            margin-bottom: 1.3rem;
        }
        .modal-av {
            width: 48px;
            height: 48px;
            border-radius: 11px;
            flex-shrink: 0;
            background: linear-gradient(135deg, var(--accent), var(--accent2));
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.3rem;
            font-weight: 700;
            color: #07111B;
            box-shadow: 0 0 18px rgba(0, 229, 160, 0.35);
        }
        .mav-name {
            font-weight: 700;
            font-size: 0.9rem;
            color: var(--white);
        }
        .mav-role {
            font-size: 0.66rem;
            color: var(--muted);
            text-transform: capitalize;
            margin-top: 2px;
        }
        
        .modal-actions {
            display: flex;
            gap: 0.65rem;
            margin-top: 1.2rem;
        }
        .modal-actions .btn {
            flex: 1;
            padding: 0.65rem;
            border: none;
            border-radius: 9px;
            font-family: 'Space Grotesk', sans-serif;
            font-size: 0.82rem;
            font-weight: 600;
            cursor: pointer;
            text-align: center;
            text-decoration: none;
            transition: all 0.2s;
        }
        .modal-actions .btn-primary {
            background: var(--accent);
            color: #07111B;
            box-shadow: 0 0 14px rgba(0, 229, 160, 0.3);
        }
        .modal-actions .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 20px rgba(0, 229, 160, 0.5);
        }
        .modal-actions .btn-danger {
            background: rgba(244, 63, 94, 0.15);
            color: var(--red);
            border: 1px solid rgba(244, 63, 94, 0.2);
        }
        .modal-actions .btn-danger:hover {
            background: rgba(244, 63, 94, 0.25);
        }
        
        .mf {
            margin-bottom: 1rem;
        }
        .mf label {
            display: block;
            font-size: 0.64rem;
            letter-spacing: 0.1em;
            text-transform: uppercase;
            color: var(--muted);
            margin-bottom: 0.4rem;
            font-weight: 500;
        }
        .mf input {
            width: 100%;
            background: var(--bg4);
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 9px;
            padding: 0.68rem 0.85rem;
            font-family: 'Space Grotesk', sans-serif;
            font-size: 0.83rem;
            color: var(--white);
            outline: none;
            transition: border-color 0.18s, box-shadow 0.18s;
        }
        .mf input:focus {
            border-color: var(--accent);
            box-shadow: 0 0 0 3px rgba(0, 229, 160, 0.12);
        }
        .mf input::placeholder {
            color: rgba(255, 255, 255, 0.16);
        }
        
        .modal-acts {
            display: flex;
            gap: 0.65rem;
            margin-top: 1.4rem;
        }
        
        .btn-save {
            flex: 1;
            padding: 0.72rem;
            background: var(--accent);
            border: none;
            border-radius: 9px;
            font-family: 'Space Grotesk', sans-serif;
            font-size: 0.95rem;
            font-weight: 700;
            color: #07111B;
            cursor: pointer;
            box-shadow: 0 0 14px rgba(0, 229, 160, 0.3);
            transition: transform 0.15s, box-shadow 0.18s;
        }
        .btn-save:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 20px rgba(0, 229, 160, 0.5);
        }
        .btn-cancel2 {
            padding: 0.72rem 1.1rem;
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid var(--border);
            border-radius: 9px;
            font-size: 0.82rem;
            color: var(--muted2);
            cursor: pointer;
            transition: background 0.18s;
        }
        .btn-cancel2:hover {
            background: rgba(255, 255, 255, 0.08);
        }
        
        /* ─── Misc ─── */
        .grid-bg {
            position: fixed;
            inset: 0;
            pointer-events: none;
            z-index: 0;
            background-image: 
                linear-gradient(rgba(0, 229, 160, 0.012) 1px, transparent 1px),
                linear-gradient(90deg, rgba(0, 229, 160, 0.012) 1px, transparent 1px);
            background-size: 42px 42px;
        }
        
        .footer {
            flex-shrink: 0;
            border-top: 1px solid var(--border);
            padding: 0.5rem 1.4rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            font-size: 0.6rem;
            color: var(--muted);
            font-family: 'JetBrains Mono', monospace;
            background: var(--bg2);
        }
        
        .toast-wrap {
            position: fixed;
            top: 1rem;
            right: 1.4rem;
            z-index: 9999;
            display: flex;
            flex-direction: column;
            gap: 0.4rem;
            pointer-events: none;
        }
        
        .toast {
            display: flex;
            align-items: flex-start;
            gap: 0.6rem;
            background: var(--bg3);
            border: 1px solid rgba(0, 229, 160, 0.28);
            border-left: 3px solid var(--accent);
            border-radius: 11px;
            padding: 0.6rem 0.8rem;
            min-width: 240px;
            max-width: 300px;
            box-shadow: 0 8px 28px rgba(0, 0, 0, 0.45);
            animation: tIn 0.3s ease;
            pointer-events: all;
        }
        
        @keyframes tIn {
            from { opacity: 0; transform: translateX(26px); }
            to { opacity: 1; transform: none; }
        }
        
        .t-ic { font-size: 0.85rem; flex-shrink: 0; }
        .t-body { flex: 1; }
        .t-title { font-weight: 700; font-size: 0.72rem; color: var(--accent); margin-bottom: 0.1rem; }
        .t-msg { font-size: 0.65rem; color: var(--muted2); }
        .t-x { background: none; border: none; color: var(--muted); cursor: pointer; font-size: 0.7rem; }
        
        /* ─── Responsive ─── */
        @media (max-width: 760px) {
            :root { --sw: 0px; }
            .sidebar { display: none; }
            .two-col { grid-template-columns: 1fr; }
            .type-grid { grid-template-columns: repeat(2, 1fr); }
            .content { padding: 0.8rem; }
            .pname { display: none; }
        }
    </style>
</head>
<body>
    <div class="grid-bg"></div>
    <div class="toast-wrap" id="toastWrap"></div>

    <!-- ==========================================
    PROFILE MODAL
    ========================================== -->
    <div class="modal-bg" id="profileModal">
        <div class="modal">
            <button class="modal-close" onclick="closeProfileModal()"><i class="fas fa-times"></i></button>
            <div class="modal-title">Update Profile</div>
            <div class="modal-sub">Edit your account information</div>
            <div class="modal-av-row">
                <div class="modal-av"><?= $admin_init ?></div>
                <div>
                    <div class="mav-name"><?= $admin_name ?></div>
                    <div class="mav-role"><?= $admin_role ?></div>
                </div>
            </div>
            <form method="POST" action="update-profile.php">
                <div class="mf">
                    <label>Full Name</label>
                    <input type="text" name="name" value="<?= $admin_name ?>" placeholder="Your full name">
                </div>
                <div class="mf">
                    <label>Email Address</label>
                    <input type="email" name="email" value="<?= $admin_email ?>" placeholder="admin@roadalert.tz">
                </div>
                <div class="mf">
                    <label>Phone Number</label>
                    <input type="tel" name="phone" value="<?= htmlspecialchars($_SESSION['phone'] ?? '') ?>" placeholder="+255 7XX XXX XXX">
                </div>
                <div class="mf">
                    <label>New Password <span style="color:var(--muted);font-size:.62rem;text-transform:none;letter-spacing:0">(blank = keep current)</span></label>
                    <input type="password" name="password" placeholder="••••••••••">
                </div>
                <div class="mf">
                    <label>Confirm Password</label>
                    <input type="password" name="confirm_password" placeholder="••••••••••">
                </div>
                <div class="modal-acts">
                    <button type="submit" class="btn-save"><i class="fas fa-save"></i> &nbsp;Save Changes</button>
                    <button type="button" class="btn-cancel2" onclick="closeProfileModal()">Cancel</button>
                </div>
            </form>
        </div>
    </div>

    <!-- ==========================================
    MAIN SHELL
    ========================================== -->
    <div class="shell">

        <!-- ==========================================
        SIDEBAR
        ========================================== -->
        <aside class="sidebar">
            <a href="dashboard.php" class="sb-brand">
                <div class="sb-logo">⚠</div>
                <div>
                    <div class="sb-title">RoadAlert TZ</div>
                    <div class="sb-sub">Admin Terminal</div>
                </div>
            </a>

            <nav class="sb-nav">
                <div class="sb-sect">Main</div>
                <a href="dashboard.php" class="sb-item <?= is_active('dashboard.php') ?>">
                    <span class="sb-ic"><i class="fas fa-chart-line"></i></span> Dashboard
                </a>
                <a href="accident_report.php" class="sb-item <?= is_active('accident_report.php') ?>">
                    <span class="sb-ic"><i class="fas fa-car-crash"></i></span> Accident Reports
                    <?php if ($pending_count > 0): ?>
                        <span class="sb-badge red"><?= $pending_count ?></span>
                    <?php endif; ?>
                </a>
              <a href="resolved_cases.php" class="sb-item <?= is_active('resolved_cases.php') ?>">
    <span class="sb-ic"><i class="fas fa-check-circle"></i></span> Resolved Cases
    <?php if (($police_resolved ?? 0) > 0 || ($hospital_treated ?? 0) > 0): ?>
        <span class="sb-badge grn"><?= ($police_resolved ?? 0) + ($hospital_treated ?? 0) ?></span>
    <?php endif; ?>
</a>

                <div class="sb-sect" style="margin-top:.4rem">Management</div>
                <a href="manage_police.php" class="sb-item <?= is_active('manage_police.php') ?>">
                    <span class="sb-ic"><i class="fas fa-user-shield"></i></span> Police Stations
                </a>
                <a href="manage_hospital.php" class="sb-item <?= is_active('manage_hospital.php') ?>">
                    <span class="sb-ic"><i class="fas fa-hospital"></i></span> Hospitals
                </a>
                <a href="users.php" class="sb-item <?= is_active('users.php') ?>">
                    <span class="sb-ic"><i class="fas fa-users"></i></span> Manage Users
                </a>
                <a href="audit_logs.php" class="sb-item <?= is_active('audit_logs.php') ?>">
                    <span class="sb-ic"><i class="fas fa-clipboard-list"></i></span> Audit Logs
                </a>

                <div class="sb-sect" style="margin-top:.4rem">Quick</div>
                <a href="admin_report.php" class="sb-item active">
                    <span class="sb-ic"><i class="fas fa-plus-circle"></i></span> New Report
                </a>
             

                <div class="sb-divider" style="margin-top:.8rem"></div>
                <a href="../logout.php" class="sb-logout-item">
                    <span class="sb-ic"><i class="fas fa-sign-out-alt"></i></span>
                    Logout
                </a>
            </nav>
        </aside>

        <!-- ==========================================
        MAIN CONTENT
        ========================================== -->
        <div class="main">

            <!-- ─── TOPBAR ─── -->
            <div class="topbar">
                <div class="tb-title">
                    <i class="fas fa-plus-circle"></i>New Accident Report
                </div>

                <div class="tb-search">
                    <i class="fas fa-search"></i>
                    <input type="text" placeholder="Search…" oninput="">
                </div>

                <div class="tb-acts">
                    <div class="live-pill">
                        <span class="live-dot"></span>Live
                    </div>
<!-- Notification Bell -->
<div class="notif-wrap">
    <button class="notif-btn" onclick="toggleNotif()" id="notificationBell">
        <i class="fas fa-bell"></i>
        <span class="notif-count" id="notifCount" style="<?= $notification_count > 0 ? '' : 'display:none;' ?>">
            <?= $notification_count ?>
        </span>
    </button>
    <div class="notif-drop" id="notifDrop">
        
        <!-- PENDING SECTION -->
        <div class="notif-head">
            <div>
                <div class="nh-title">🚨 Pending Alerts</div>
                <div class="nh-sub">Cases waiting for response</div>
            </div>
            <span style="font-size:.62rem;color:var(--muted);font-family:'JetBrains Mono',monospace">
                <?= $pending_count ?> pending
            </span>
        </div>
        <div class="notif-list">
            <?php
            $pending_accidents->data_seek(0);
            if($pending_accidents && $pending_accidents->num_rows > 0):
                while($al = $pending_accidents->fetch_assoc()): ?>
            <div class="notif-item" onclick="window.location.href='accident_report.php?status=pending'">
                <div class="ndot"></div>
                <div class="nb">
                    <div class="nid">#ACC-<?= str_pad($al['id'], 4, '0', STR_PAD_LEFT) ?></div>
                    <div class="nloc"><?= htmlspecialchars(substr($al['location_description'] ?? 'Unknown', 0, 42)) ?></div>
                    <div class="nmeta">
                        <span class="npill">
                            <?php if($al['police_status'] == 'pending'): ?>🚔 Pending<?php endif; ?>
                            <?php if($al['hospital_status'] == 'pending'): ?> 🏥 Pending<?php endif; ?>
                        </span>
                        <span class="ntime">🤕 <?= $al['injuries'] ?> injured</span>
                        <span class="ntime"><?= date('H:i', strtotime($al['created_at'])) ?></span>
                    </div>
                </div>
                <i class="fas fa-chevron-right" style="font-size:.68rem;color:var(--muted);flex-shrink:0;margin-top:3px"></i>
            </div>
            <?php endwhile; else: ?>
            <div style="padding:1.2rem;text-align:center">
                <i class="fas fa-check-circle" style="font-size:1.5rem;color:var(--accent);opacity:.45"></i>
                <p style="font-size:.7rem;color:var(--muted);margin-top:.3rem">No pending alerts</p>
            </div>
            <?php endif; ?>
        </div>

        <!-- 🔴 AUTO-ASSIGNED SECTION -->
        <div class="notif-head" style="border-top:1px solid var(--border);background:rgba(250,204,21,.06);">
            <div>
                <div class="nh-title" style="color:var(--yellow);">🤖 Auto-Assigned Today</div>
                <div class="nh-sub" style="color:var(--yellow);">System assigned to nearest responders</div>
            </div>
            <span style="font-size:.62rem;color:var(--yellow);font-family:'JetBrains Mono',monospace">
                <?= $auto_assigned_today ?> assigned
            </span>
        </div>
        <div class="notif-list">
            <?php 
            if($auto_assign_list && $auto_assign_list->num_rows > 0):
                while($aa = $auto_assign_list->fetch_assoc()): ?>
            <div class="notif-item" onclick="window.location.href='accident_report.php'">
                <div class="ndot" style="background:var(--yellow);"></div>
                <div class="nb">
                    <div class="nid" style="color:var(--yellow);">#ACC-<?= str_pad($aa['id'], 4, '0', STR_PAD_LEFT) ?></div>
                    <div class="nloc">📍 <?= htmlspecialchars(substr($aa['location_description'] ?? 'Unknown', 0, 42)) ?></div>
                    <div class="nmeta" style="flex-wrap:wrap;">
                        <span style="font-size:.55rem;padding:.1rem .4rem;border-radius:999px;background:rgba(250,204,21,.15);color:var(--yellow);border:1px solid rgba(250,204,21,.2);">
                            🤖 Auto-Assigned
                        </span>
                        <?php if($aa['police_name']): ?>
                            <span style="font-size:.5rem;color:var(--sky);">🚔 <?= htmlspecialchars($aa['police_name']) ?></span>
                        <?php endif; ?>
                        <?php if($aa['hospital_name']): ?>
                            <span style="font-size:.5rem;color:var(--accent);">🏥 <?= htmlspecialchars($aa['hospital_name']) ?></span>
                        <?php endif; ?>
                        <span class="ntime">🤕 <?= $aa['injuries'] ?> injured</span>
                    </div>
                </div>
                <i class="fas fa-chevron-right" style="font-size:.68rem;color:var(--muted);flex-shrink:0;margin-top:3px"></i>
            </div>
            <?php endwhile; else: ?>
            <div style="padding:0.8rem;text-align:center;font-size:.65rem;color:var(--muted);">
                <i class="fas fa-check-circle" style="color:var(--accent);opacity:.45"></i>
                No auto-assigned cases today
            </div>
            <?php endif; ?>
        </div>

        <!-- FOOTER -->
        <div class="notif-foot">
            <a href="accident_report.php?status=pending">📋 View all pending →</a>
            <span style="color:var(--muted);font-size:.5rem;margin:0 0.3rem;">|</span>
            <a href="accident_report.php?status=assigned" style="color:var(--yellow);">🤖 View auto-assigned →</a>
        </div>
    </div>
</div>
                    <!-- Profile -->
                    <div class="profile-wrap">
                        <button class="profile-btn" id="profileBtn" onclick="toggleProfileDrop()">
                            <div class="pa"><?= $admin_init ?></div>
                            <span class="pname"><?= $admin_name ?></span>
                            <i class="fas fa-chevron-down pchev"></i>
                        </button>
                        <div class="profile-drop" id="profileDrop">
                            <div class="pd-head">
                                <div class="pd-name"><?= $admin_name ?></div>
                                <div class="pd-email"><?= $admin_email ?></div>
                                <div class="pd-pill">
                                    <i class="fas fa-shield-alt"></i> <?= $admin_role ?>
                                </div>
                            </div>
                            <div class="pd-items">
                                <button class="pd-item" onclick="openProfileModal()">
                                    <i class="fas fa-user-edit"></i> Edit Profile
                                </button>
                                <div class="pd-div"></div>
                                <a href="../logout.php" class="pd-item danger">
                                    <i class="fas fa-sign-out-alt"></i> Logout
                                </a>
                            </div>
                        </div>
                    </div>

                    <a href="../logout.php" class="tb-btn">
                        <i class="fas fa-sign-out-alt"></i>
                    </a>
                </div>
            </div>

            <!-- ─── CONTENT ─── -->
            <div class="content">
                <div class="form-container">

                    <?php if ($error): ?>
                    <div class="error-box">
                        <i class="fas fa-exclamation-triangle"></i> <?= htmlspecialchars($error) ?>
                    </div>
                    <?php endif; ?>

                    <div class="form-card">
                        <form method="POST" enctype="multipart/form-data" id="reportForm">

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
                                <label><i class="fas fa-band-aid"></i> Number of Injured</label>
                                <div class="injury-counter">
                                    <button type="button" class="counter-btn minus" onclick="decrementInjury()">
                                        <i class="fas fa-minus"></i>
                                    </button>
                                    <span class="injury-value" id="injuryDisplay">1</span>
                                    <button type="button" class="counter-btn plus" onclick="incrementInjury()">
                                        <i class="fas fa-plus"></i>
                                    </button>
                                    <span style="color:var(--muted);font-size:.7rem;margin-left:auto;">Estimate for EMS</span>
                                </div>
                                <input type="hidden" name="injuries" id="injuryInput" value="1">
                            </div>

                            <!-- Hazards -->
                            <div class="form-group">
                                <label><i class="fas fa-exclamation-triangle"></i> Additional Hazards</label>
                                <div class="hazard-grid">
                                    <label class="hazard-item">
                                        <input type="checkbox" name="hazard_list[]" value="Fire">
                                        <i class="fas fa-fire" style="color:#f59e0b"></i> Fire
                                    </label>
                                    <label class="hazard-item">
                                        <input type="checkbox" name="hazard_list[]" value="Fuel Spill">
                                        <i class="fas fa-oil-can" style="color:#f59e0b"></i> Fuel Spill
                                    </label>
                                    <label class="hazard-item">
                                        <input type="checkbox" name="hazard_list[]" value="Trapped Persons">
                                        <i class="fas fa-users-slash"></i> Trapped
                                    </label>
                                    <label class="hazard-item">
                                        <input type="checkbox" name="hazard_list[]" value="Hazardous Material">
                                        <i class="fas fa-skull"></i> HazMat
                                    </label>
                                </div>
                                <input type="hidden" name="hazards" id="hazardsHidden">
                            </div>

                            <!-- Description -->
                            <div class="form-group">
                                <label><i class="fas fa-pen-alt"></i> Description</label>
                                <textarea rows="3" name="description" placeholder="Describe what happened…"><?= htmlspecialchars($_POST['description'] ?? '') ?></textarea>
                            </div>

                            <!-- Photos -->
                            <div class="form-group">
                                <label><i class="fas fa-camera"></i> Photos</label>
                                <div class="photo-upload-area" onclick="document.getElementById('photoInput').click()">
                                    <i class="fas fa-cloud-upload-alt"></i>
                                    <p style="font-size:.75rem;color:var(--muted);">Click to upload photos (max 5)</p>
                                </div>
                                <input type="file" name="accident_images[]" id="photoInput" accept="image/*" multiple style="display:none" onchange="handlePhotoPreview(event)">
                                <div class="photo-preview-grid" id="photoPreviewGrid"></div>
                            </div>

                            <!-- Reporter Info -->
                            <div style="border:1px solid var(--border);border-radius:10px;padding:1rem;margin:1rem 0;">
                                <div style="font-size:.7rem;text-transform:uppercase;color:var(--muted);margin-bottom:.6rem;font-weight:600;">
                                    <i class="fas fa-user-circle"></i> Reporter Information
                                </div>
                                <div class="two-col">
                                    <div class="form-group" style="margin-bottom:0">
                                        <label>Full Name <span class="required">*</span></label>
                                        <input type="text" name="reporter_name" placeholder="Full name" value="<?= htmlspecialchars($_POST['reporter_name'] ?? '') ?>" required>
                                    </div>
                                    <div class="form-group" style="margin-bottom:0">
                                        <label>Phone <span class="required">*</span></label>
                                        <input type="tel" name="reporter_phone" placeholder="+255 7XX XXX XXX" value="<?= htmlspecialchars($_POST['reporter_phone'] ?? '') ?>" required>
                                    </div>
                                </div>
                                <div class="form-group" style="margin-bottom:0">
                                    <label>Email</label>
                                    <input type="email" name="reporter_email" placeholder="user@example.com" value="<?= htmlspecialchars($_POST['reporter_email'] ?? '') ?>">
                                </div>
                            </div>

                            <!-- Admin Assignment with Dual Status -->
                            <div style="border:1px solid rgba(0,229,160,.2);border-radius:10px;padding:1rem;margin:1rem 0;background:var(--acc-lo2);">
                                <div style="font-size:.7rem;text-transform:uppercase;color:var(--accent);margin-bottom:.6rem;font-weight:700;">
                                    <i class="fas fa-bolt"></i> Admin Assignment
                                </div>
                                <div class="two-col">
                                    <div class="form-group" style="margin-bottom:0">
                                        <label>🚔 Assign Police</label>
                                        <select name="assigned_police_id" id="assigned_police">
                                            <option value="0">-- None --</option>
                                            <?php 
                                            $police_stations->data_seek(0);
                                            while ($ps = $police_stations->fetch_assoc()): 
                                            ?>
                                                <option value="<?= $ps['id'] ?>"><?= htmlspecialchars($ps['name']) ?> - <?= htmlspecialchars($ps['location']) ?></option>
                                            <?php endwhile; ?>
                                        </select>
                                    </div>
                                    <div class="form-group" style="margin-bottom:0">
                                        <label>🏥 Assign Hospital</label>
                                        <select name="assigned_hospital_id" id="assigned_hospital">
                                            <option value="0">-- None --</option>
                                            <?php 
                                            $hospital_stations->data_seek(0);
                                            while ($hs = $hospital_stations->fetch_assoc()): 
                                            ?>
                                                <option value="<?= $hs['id'] ?>"><?= htmlspecialchars($hs['name']) ?> - <?= htmlspecialchars($hs['location']) ?></option>
                                            <?php endwhile; ?>
                                        </select>
                                    </div>
                                </div>
                                
                                <!-- Dual Status Selection -->
                                <div class="two-col" style="margin-top:.5rem;">
                                    <div class="form-group" style="margin-bottom:0">
                                        <label>🚔 Police Status</label>
                                        <select name="police_status" id="police_status">
                                            <option value="pending">Pending</option>
                                            <option value="assigned">Assigned</option>
                                            <option value="dispatched">Dispatched</option>
                                            <option value="arrived">Arrived</option>
                                            <option value="resolved">Resolved</option>
                                            <option value="false_alarm">False Alarm</option>
                                        </select>
                                    </div>
                                    <div class="form-group" style="margin-bottom:0">
                                        <label>🏥 Hospital Status</label>
                                        <select name="hospital_status" id="hospital_status">
                                            <option value="pending">Pending</option>
                                            <option value="assigned">Assigned</option>
                                            <option value="preparing">Preparing</option>
                                            <option value="ambulance_dispatched">Ambulance Dispatched</option>
                                            <option value="patient_received">Patient Received</option>
                                            <option value="treated">Treated</option>
                                            <option value="closed">Closed</option>
                                        </select>
                                    </div>
                                </div>
                            </div>

                            <!-- Map -->
                            <div class="form-group">
                                <label><i class="fas fa-map-marked-alt"></i> Location</label>
                                <div id="map"></div>
                            </div>

                            <input type="hidden" id="latitude" name="latitude">
                            <input type="hidden" id="longitude" name="longitude">
                            <input type="hidden" id="location_name" name="location_name">

                            <button type="submit" class="btn-submit" id="submitBtn">
                                <i class="fas fa-paper-plane"></i> Submit Report
                            </button>
                        </form>
                    </div>
                </div>
            </div><!-- /content -->

            <!-- ─── FOOTER ─── -->
            <div class="footer">
                <span>© 2026 RoadAlert TZ &nbsp;·&nbsp; Admin Report Form</span>
                <span id="clock"></span>
            </div>

        </div><!-- /main -->
    </div><!-- /shell -->

    <!-- ==========================================
    SUCCESS MODAL
    ========================================== -->
    <?php if ($success): ?>
    <div class="modal-bg show" id="successModal">
        <div class="modal">
            <button class="modal-close" onclick="document.getElementById('successModal').classList.remove('show')">
                <i class="fas fa-times"></i>
            </button>
            <div class="modal-title"><i class="fas fa-check-circle"></i> Report Submitted</div>
            <div style="text-align:center;color:var(--muted);font-size:.85rem;">Accident report recorded successfully</div>
            <div class="modal-ref">#ACC-<?= str_pad($report_id, 4, '0', STR_PAD_LEFT) ?></div>
            <div style="text-align:center;font-size:.75rem;color:var(--muted);">
                <i class="fas fa-check"></i> Report saved to database
            </div>
            <div class="modal-actions">
                <a href="dashboard.php" class="btn btn-primary"><i class="fas fa-home"></i> Dashboard</a>
                <a href="admin_report.php" class="btn btn-danger"><i class="fas fa-plus"></i> New Report</a>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- ==========================================
    JAVASCRIPT
    ========================================== -->
    <script>
        // ─── CLOCK ──────────────────────────────────────
        function tick() {
            const n = new Date();
            document.getElementById('clock').textContent =
                n.toLocaleDateString('en-GB', { weekday: 'short', day: '2-digit', month: 'short' }) +
                ' · ' + n.toLocaleTimeString('en-GB', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
        }
        setInterval(tick, 1000);
        tick();

        // ─── INJURY COUNTER ────────────────────────────
        let injuryCount = 1;

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

        // ─── PHOTO PREVIEW ─────────────────────────────
        let selectedFiles = [];
        const MAX_PHOTOS = 5;

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

        // ─── MAP ────────────────────────────────────────
        let map, marker, currentLat = -6.7924, currentLng = 39.2083;

        function initMap() {
            map = L.map('map').setView([currentLat, currentLng], 14);
            L.tileLayer('https://{s}.basemaps.cartocdn.com/light_all/{z}/{x}/{y}{r}.png', {
                attribution: '&copy; OSM',
                subdomains: 'abcd',
                maxZoom: 19
            }).addTo(map);
            
            marker = L.marker([currentLat, currentLng], { draggable: true }).addTo(map);
            marker.on('dragend', function() {
                const p = marker.getLatLng();
                currentLat = p.lat;
                currentLng = p.lng;
                document.getElementById('latitude').value = currentLat;
                document.getElementById('longitude').value = currentLng;
                reverseGeocode(currentLat, currentLng);
            });
            
            document.getElementById('latitude').value = currentLat;
            document.getElementById('longitude').value = currentLng;
            reverseGeocode(currentLat, currentLng);
        }

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

        // ─── FORM SUBMIT ──────────────────────────────
        document.getElementById('reportForm').addEventListener('submit', function(e) {
            const checked = Array.from(document.querySelectorAll('input[name="hazard_list[]"]:checked'))
                .map(c => c.value);
            document.getElementById('hazardsHidden').value = checked.join(', ');
            
            if (!document.getElementById('latitude').value) {
                e.preventDefault();
                alert('Please select a location on the map.');
                return;
            }
            
            const btn = document.getElementById('submitBtn');
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Submitting...';
            btn.disabled = true;
        });

        // ─── AUTO STATUS ON ASSIGNMENT ──────────────
        document.getElementById('assigned_police').addEventListener('change', function() {
            if (this.value > 0) {
                document.getElementById('police_status').value = 'assigned';
            }
        });

        document.getElementById('assigned_hospital').addEventListener('change', function() {
            if (this.value > 0) {
                document.getElementById('hospital_status').value = 'assigned';
            }
        });

        // ─── NOTIFICATION DROPDOWN ──────────────────
        function toggleNotif() {
            const d = document.getElementById('notifDrop');
            d.classList.toggle('show');
        }

        // ─── PROFILE DROPDOWN ──────────────────────
        function toggleProfileDrop() {
            const btn = document.getElementById('profileBtn');
            const drop = document.getElementById('profileDrop');
            const open = drop.classList.toggle('show');
            btn.classList.toggle('open', open);
        }

        function closeProfileDrop() {
            document.getElementById('profileDrop').classList.remove('show');
            document.getElementById('profileBtn').classList.remove('open');
        }

        // ─── PROFILE MODAL ─────────────────────────
        function openProfileModal() {
            document.getElementById('profileModal').classList.add('show');
            closeProfileDrop();
        }

        function closeProfileModal() {
            document.getElementById('profileModal').classList.remove('show');
        }

        // ─── CLOSE ON OUTSIDE CLICK ────────────────
        document.addEventListener('click', function(e) {
            if (!e.target.closest('#notifDrop') && !e.target.closest('.notif-btn')) {
                document.getElementById('notifDrop').classList.remove('show');
            }
            if (!e.target.closest('#profileDrop') && !e.target.closest('#profileBtn')) {
                closeProfileDrop();
            }
            if (e.target === document.getElementById('profileModal')) {
                closeProfileModal();
            }
        });

        // ─── ESCAPE KEY ────────────────────────────
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeProfileModal();
            }
        });

        // ─── INIT ──────────────────────────────────
        document.addEventListener('DOMContentLoaded', function() {
            syncInjury();
            initMap();
        });
    </script>
</body>
</html>