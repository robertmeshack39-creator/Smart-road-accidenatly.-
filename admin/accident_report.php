<?php
/**
 * ADMIN ACCIDENT REPORTS
 * RoadAlert TZ - View and manage accident reports with dual status & audit logging
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
// 🔧 HELPER FUNCTION
// ============================================
function is_active($page) {
    return basename($_SERVER['PHP_SELF']) === $page ? 'active' : '';
}
// ============================================
// 📊 FETCH STATISTICS
// ============================================
$stats = $conn->query("SELECT
    (SELECT COUNT(*) FROM accidents) as total_accidents,
    (SELECT COUNT(*) FROM accidents WHERE police_status='pending' OR hospital_status='pending') as pending_count,
    (SELECT COUNT(*) FROM accidents WHERE police_status='assigned' OR hospital_status='assigned') as assigned_count,
    (SELECT COUNT(*) FROM accidents WHERE police_status='resolved' AND hospital_status='treated') as resolved_count,
    (SELECT COUNT(*) FROM accidents WHERE DATE(created_at)=CURDATE()) as today_accidents
")->fetch_assoc();

// ─── 🔴 ADD THESE FOR SIDEBAR BADGE ───
$police_stats = $conn->query("
    SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN police_status = 'resolved' THEN 1 ELSE 0 END) as resolved
    FROM accidents
")->fetch_assoc();

$hospital_stats = $conn->query("
    SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN hospital_status = 'treated' THEN 1 ELSE 0 END) as treated
    FROM accidents
")->fetch_assoc();
// ============================================
// 🔴 GET FILTER
// ============================================
$status_filter = $_GET['status'] ?? 'all';
$status_type = $_GET['type'] ?? 'police';

if ($status_filter != 'all') {
    if ($status_type === 'police') {
        $where = "WHERE police_status = '$status_filter'";
    } else {
        $where = "WHERE hospital_status = '$status_filter'";
    }
} else {
    $where = "";
}

// ============================================
// 📋 FETCH ACCIDENTS
// ============================================
$accidents = $conn->query("
    SELECT 
        a.*,
        ps.name as police_station_name,
        hs.name as hospital_station_name
    FROM accidents a
    LEFT JOIN police_stations ps ON a.assigned_police_id = ps.id
    LEFT JOIN hospital_stations hs ON a.assigned_hospital_id = hs.id
    $where 
    ORDER BY a.created_at DESC
");

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
// 🗺️ FETCH MAP DATA
// ============================================
$map_accidents = $conn->query("
    SELECT id, accident_type, location_description, police_status, hospital_status, 
           created_at, reporter_name, injuries, latitude, longitude 
    FROM accidents 
    WHERE latitude IS NOT NULL AND longitude IS NOT NULL
    ORDER BY created_at DESC 
    LIMIT 100
");
$map_data = [];
while ($row = $map_accidents->fetch_assoc()) {
    $map_data[] = [
        'id' => $row['id'],
        'lat' => (float)$row['latitude'],
        'lng' => (float)$row['longitude'],
        'type' => htmlspecialchars($row['accident_type']),
        'location' => htmlspecialchars(substr($row['location_description'] ?? 'Unknown', 0, 60)),
        'police_status' => $row['police_status'],
        'hospital_status' => $row['hospital_status'],
        'injuries' => $row['injuries'],
        'reporter' => htmlspecialchars($row['reporter_name']),
        'time' => date('H:i d/m/Y', strtotime($row['created_at']))
    ];
}

// ============================================
// 🔴 HANDLE DELETE - WITH AUDIT LOG
// ============================================
if (isset($_GET['delete'])) {
    $id = intval($_GET['delete']);
    
    // Get accident details before deleting
    $acc = $conn->query("SELECT accident_type, location_description FROM accidents WHERE id=$id")->fetch_assoc();
    
    // 🔴 AUDIT LOG - DELETE ACCIDENT
    createAuditLog($conn, 'Deleted accident report | ACC-' . str_pad($id, 4, '0', STR_PAD_LEFT) . ' | Type: ' . ($acc['accident_type'] ?? 'Unknown'));
    
    $conn->query("DELETE FROM accidents WHERE id = $id");
    header("Location: accident_report.php?msg=deleted");
    exit();
}

// ============================================
// 🔴 HANDLE STATUS UPDATE - WITH AUDIT LOG
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_status'])) {
    $id = intval($_POST['accident_id']);
    $status_type = $_POST['status_type'] ?? 'police';
    $status = mysqli_real_escape_string($conn, $_POST['status']);
    
    $allowed_police = ['pending', 'assigned', 'dispatched', 'arrived', 'resolved', 'false_alarm'];
    $allowed_hospital = ['pending', 'assigned', 'preparing', 'ambulance_dispatched', 'patient_received', 'treated', 'closed'];
    
    $column = $status_type . '_status';
    $allowed = ($status_type === 'police') ? $allowed_police : $allowed_hospital;
    
    if (in_array($status, $allowed)) {
        $conn->query("UPDATE accidents SET $column='$status' WHERE id=$id");
        
        // 🔴 AUDIT LOG - STATUS UPDATE
        createAuditLog($conn, 'Updated ' . $status_type . ' status to ' . $status . ' | ACC-' . str_pad($id, 4, '0', STR_PAD_LEFT));
        
        header("Location: accident_report.php?msg=updated");
        exit();
    }
}

// ============================================
// 🔴 HANDLE MANUAL ASSIGNMENT - WITH AUDIT LOG
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['assign_case'])) {
    $accident_id = intval($_POST['accident_id']);
    $police_id = isset($_POST['police_id']) ? intval($_POST['police_id']) : 0;
    $hospital_id = isset($_POST['hospital_id']) ? intval($_POST['hospital_id']) : 0;
    
    $updates = [];
    $log_details = 'Assigned case | ACC-' . str_pad($accident_id, 4, '0', STR_PAD_LEFT);
    
    if ($police_id > 0) {
        $updates[] = "assigned_police_id = $police_id";
        $updates[] = "police_status = 'assigned'";
        $police = $conn->query("SELECT name FROM police_stations WHERE id=$police_id")->fetch_assoc();
        $log_details .= ' | Police: ' . $police['name'];
    }
    if ($hospital_id > 0) {
        $updates[] = "assigned_hospital_id = $hospital_id";
        $updates[] = "hospital_status = 'assigned'";
        $hospital = $conn->query("SELECT name FROM hospital_stations WHERE id=$hospital_id")->fetch_assoc();
        $log_details .= ' | Hospital: ' . $hospital['name'];
    }
    
    if (!empty($updates)) {
        $sql = "UPDATE accidents SET " . implode(', ', $updates) . " WHERE id = $accident_id";
        if ($conn->query($sql)) {
            // 🔴 AUDIT LOG - MANUAL ASSIGNMENT
            createAuditLog($conn, $log_details);
            
            header("Location: accident_report.php?msg=assigned");
            exit();
        } else {
            $error = "Error: " . $conn->error;
        }
    } else {
        $error = "Please select at least one responder";
    }
}

// ============================================
// 🔴 HANDLE AUTO-ASSIGN - WITH AUDIT LOG
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['auto_assign'])) {
    $accident_id = intval($_POST['accident_id']);
    
    $acc = $conn->query("SELECT latitude, longitude, id FROM accidents WHERE id = $accident_id")->fetch_assoc();
    
    if ($acc) {
        $lat = $acc['latitude'];
        $lng = $acc['longitude'];
        
        $nearest_police = $conn->query("
            SELECT id, name, 
                   (6371 * acos(cos(radians($lat)) * cos(radians(latitude)) * 
                   cos(radians(longitude) - radians($lng)) + sin(radians($lat)) * sin(radians(latitude)))) AS distance
            FROM police_stations 
            WHERE is_active = 1
            ORDER BY distance ASC 
            LIMIT 1
        ");
        
        $nearest_hospital = $conn->query("
            SELECT id, name, 
                   (6371 * acos(cos(radians($lat)) * cos(radians(latitude)) * 
                   cos(radians(longitude) - radians($lng)) + sin(radians($lat)) * sin(radians(latitude)))) AS distance
            FROM hospital_stations 
            WHERE is_active = 1
            ORDER BY distance ASC 
            LIMIT 1
        ");
        
        $updates = [];
        $assigned = false;
        $log_details = 'Auto-assigned to nearest responders | ACC-' . str_pad($accident_id, 4, '0', STR_PAD_LEFT);
        
        if ($nearest_police && $nearest_police->num_rows > 0) {
            $police = $nearest_police->fetch_assoc();
            $updates[] = "assigned_police_id = " . $police['id'];
            $updates[] = "police_status = 'assigned'";
            $log_details .= ' | Police: ' . $police['name'];
            $assigned = true;
        }
        if ($nearest_hospital && $nearest_hospital->num_rows > 0) {
            $hospital = $nearest_hospital->fetch_assoc();
            $updates[] = "assigned_hospital_id = " . $hospital['id'];
            $updates[] = "hospital_status = 'assigned'";
            $log_details .= ' | Hospital: ' . $hospital['name'];
            $assigned = true;
        }
        
        if ($assigned) {
            $sql = "UPDATE accidents SET " . implode(', ', $updates) . " WHERE id = $accident_id";
            if ($conn->query($sql)) {
                // 🔴 AUDIT LOG - AUTO ASSIGNMENT
                createAuditLog($conn, $log_details);
                
                header("Location: accident_report.php?msg=auto_assigned");
                exit();
            }
        } else {
            $error = "No active responders found near this location";
        }
    }
}

// ============================================
// 📨 MESSAGE HANDLING
// ============================================
$message = '';
if (isset($_GET['msg'])) {
    if ($_GET['msg'] === 'deleted') $message = 'Accident deleted successfully!';
    if ($_GET['msg'] === 'updated') $message = 'Status updated successfully!';
    if ($_GET['msg'] === 'assigned') $message = '✅ Case assigned successfully!';
    if ($_GET['msg'] === 'auto_assigned') $message = '✅ Auto-assigned to nearest responders!';
}

// ============================================
// 👤 ADMIN USER INFO
// ============================================
$admin_name = htmlspecialchars($_SESSION['name'] ?? 'System Admin');
$admin_email = htmlspecialchars($_SESSION['email'] ?? 'admin@roadalert.tz');
$admin_role = htmlspecialchars($_SESSION['role'] ?? 'admin');
$admin_init = strtoupper(substr($admin_name, 0, 1));

// ============================================
// 🔔 PENDING COUNT FOR NOTIFICATIONS
// ============================================
$pending_count = $conn->query("
    SELECT COUNT(*) as count FROM accidents 
    WHERE police_status='pending' OR hospital_status='pending'
")->fetch_assoc()['count'];

// ============================================
// 🔔 PENDING ACCIDENTS FOR DROPDOWN
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
// 📁 BASE URL
// ============================================
$base_url = '/Smart-Road-reporting-system/';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Accident Reports — RoadAlert TZ</title>
    
    <!-- Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Sora:wght@300;400;500;600;700;800&family=Space+Grotesk:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
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
        
        /* ============================================
           PROFILE MODAL
           ============================================ */
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
            font-family: 'Sora', sans-serif;
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
        
        /* ============================================
           CONTENT AREA
           ============================================ */
        .content {
            flex: 1;
            overflow-y: auto;
            padding: 1.4rem 1.5rem;
        }
        
        /* ─── Stat Cards ─── */
        .stats-row {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 1rem;
            margin-bottom: 1.1rem;
        }
        
        .sc {
            background: var(--bg3);
            border: 1px solid var(--border);
            border-radius: 14px;
            padding: 1.1rem 1.25rem;
            position: relative;
            overflow: hidden;
            transition: border-color 0.22s, transform 0.18s;
        }
        .sc:hover {
            border-color: var(--bord2);
            transform: translateY(-2px);
        }
        
        .sc::after {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 2px;
            opacity: 0;
            transition: opacity 0.25s;
        }
        .sc:hover::after {
            opacity: 1;
        }
        
        .sc.c1::after { background: linear-gradient(90deg, var(--sky), transparent); }
        .sc.c2::after { background: linear-gradient(90deg, var(--red), transparent); }
        .sc.c3::after { background: linear-gradient(90deg, var(--amber), transparent); }
        .sc.c4::after { background: linear-gradient(90deg, var(--accent), transparent); }
        
        .sc-ico {
            width: 36px;
            height: 36px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.88rem;
            margin-bottom: 0.85rem;
        }
        .c1 .sc-ico { background: rgba(34, 211, 238, 0.1); color: var(--sky); }
        .c2 .sc-ico { background: rgba(244, 63, 94, 0.1); color: var(--red); }
        .c3 .sc-ico { background: rgba(251, 146, 60, 0.1); color: var(--amber); }
        .c4 .sc-ico { background: var(--acc-lo); color: var(--accent); }
        
        .sc-num {
            font-family: 'Sora', sans-serif;
            font-size: 2.2rem;
            font-weight: 800;
            line-height: 1;
            margin-bottom: 0.22rem;
        }
        .c1 .sc-num { color: var(--sky); }
        .c2 .sc-num { color: var(--red); }
        .c3 .sc-num { color: var(--amber); }
        .c4 .sc-num { color: var(--accent); }
        
        .sc-lbl {
            font-size: 0.67rem;
            color: var(--muted);
            letter-spacing: 0.09em;
            text-transform: uppercase;
        }
        .sc-tag {
            position: absolute;
            top: 1.1rem;
            right: 1.1rem;
            font-size: 0.6rem;
            font-family: 'JetBrains Mono', monospace;
            color: var(--muted);
        }
        
        /* ─── Filter Bar ─── */
        .filter-bar {
            background: var(--bg3);
            border: 1px solid var(--border);
            border-radius: 14px;
            padding: 0.8rem 1rem;
            display: flex;
            flex-wrap: wrap;
            gap: 0.5rem;
            margin-bottom: 1.1rem;
            align-items: center;
        }
        
        .filter-bar a {
            padding: 0.45rem 0.9rem;
            border-radius: 8px;
            font-size: 0.7rem;
            font-weight: 600;
            color: var(--muted2);
            text-decoration: none;
            transition: background 0.15s, color 0.15s;
        }
        .filter-bar a:hover {
            background: var(--acc-lo2);
            color: var(--light);
        }
        .filter-bar a.active {
            background: var(--acc-lo);
            color: var(--accent);
        }
        
        .filter-bar .status-type-select {
            background: var(--bg4);
            border: 1px solid var(--border);
            border-radius: 6px;
            padding: 0.3rem 0.5rem;
            font-family: 'Space Grotesk', sans-serif;
            font-size: 0.65rem;
            color: var(--light);
            cursor: pointer;
            outline: none;
        }
        
        .filter-bar .count {
            margin-left: auto;
            font-size: 0.65rem;
            color: var(--muted);
        }
        
        /* ─── Panel ─── */
        .panel {
            background: var(--bg3);
            border: 1px solid var(--border);
            border-radius: 14px;
            overflow: hidden;
            display: flex;
            flex-direction: column;
        }
        
        .ph {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0.85rem 1.15rem;
            border-bottom: 1px solid var(--border);
            flex-shrink: 0;
        }
        
        .ph-t {
            font-family: 'Sora', sans-serif;
            font-size: 1rem;
            font-weight: 700;
            color: var(--white);
        }
        .ph-t i {
            margin-right: 0.5rem;
            color: var(--accent);
        }
        .ph-s {
            font-size: 0.67rem;
            color: var(--muted);
            margin-top: 0.1rem;
        }
        .ph-a {
            font-size: 0.7rem;
            color: var(--sky);
            text-decoration: none;
            cursor: pointer;
            background: none;
            border: none;
            font-family: 'Space Grotesk', sans-serif;
            transition: opacity 0.18s;
        }
        .ph-a:hover {
            opacity: 0.7;
        }
        
        .tbl-wrap {
            overflow-x: auto;
            flex: 1;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.76rem;
        }
        
        thead th {
            padding: 0.52rem 0.85rem;
            font-size: 0.6rem;
            letter-spacing: 0.1em;
            text-transform: uppercase;
            color: var(--muted);
            font-weight: 600;
            border-bottom: 1px solid var(--border);
            white-space: nowrap;
            text-align: left;
        }
        
        tbody tr {
            border-bottom: 1px solid rgba(255, 255, 255, 0.04);
            transition: background 0.12s;
        }
        tbody tr:hover {
            background: rgba(0, 229, 160, 0.03);
        }
        
        tbody td {
            padding: 0.58rem 0.85rem;
            color: var(--light);
            vertical-align: middle;
        }
        
        .td-id {
            font-family: 'JetBrains Mono', monospace;
            font-size: 0.7rem;
            color: var(--muted2);
        }
        .td-inj {
            font-family: 'JetBrains Mono', monospace;
            color: var(--amber);
            font-weight: 700;
            text-align: center;
        }
        .td-loc {
            font-size: 0.7rem;
            color: var(--muted2);
            max-width: 155px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        .td-time {
            font-size: 0.66rem;
            color: var(--muted);
            white-space: nowrap;
        }
        
        /* ─── Photo Thumbnails ─── */
        .photo-thumbnails {
            display: flex;
            gap: 4px;
            flex-wrap: wrap;
            align-items: center;
        }
        .photo-thumbnails img {
            width: 40px;
            height: 40px;
            border-radius: 6px;
            object-fit: cover;
            border: 2px solid rgba(255, 255, 255, 0.1);
            cursor: pointer;
            transition: all 0.3s;
        }
        .photo-thumbnails img:hover {
            transform: scale(1.15);
            border-color: var(--accent);
            box-shadow: 0 0 15px rgba(0, 229, 160, 0.3);
        }
        .photo-thumbnails .more-indicator {
            width: 40px;
            height: 40px;
            border-radius: 6px;
            background: rgba(255, 255, 255, 0.05);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 10px;
            font-weight: 700;
            color: var(--muted2);
            cursor: pointer;
            border: 2px solid rgba(255, 255, 255, 0.1);
        }
        .photo-thumbnails .more-indicator:hover {
            background: var(--accent);
            color: #07111B;
        }
        
        /* ─── Status Badges ─── */
        .badge {
            display: inline-flex;
            align-items: center;
            gap: 0.26rem;
            padding: 0.18rem 0.6rem;
            border-radius: 999px;
            font-family: 'JetBrains Mono', monospace;
            font-size: 0.58rem;
            font-weight: 600;
            text-transform: uppercase;
            white-space: nowrap;
        }
        .badge.pending { background: rgba(244, 63, 94, 0.1); color: var(--red); border: 1px solid rgba(244, 63, 94, 0.2); }
        .badge.assigned { background: rgba(251, 146, 60, 0.1); color: var(--amber); border: 1px solid rgba(251, 146, 60, 0.2); }
        .badge.dispatched { background: rgba(34, 211, 238, 0.1); color: var(--sky); border: 1px solid rgba(34, 211, 238, 0.2); }
        .badge.arrived { background: rgba(34, 211, 238, 0.1); color: var(--sky); border: 1px solid rgba(34, 211, 238, 0.2); }
        .badge.resolved { background: rgba(0, 229, 160, 0.1); color: var(--accent); border: 1px solid rgba(0, 229, 160, 0.2); }
        .badge.false_alarm { background: rgba(148, 163, 184, 0.1); color: var(--muted2); border: 1px solid rgba(148, 163, 184, 0.2); }
        .badge.preparing { background: rgba(251, 146, 60, 0.1); color: var(--amber); border: 1px solid rgba(251, 146, 60, 0.2); }
        .badge.ambulance_dispatched { background: rgba(34, 211, 238, 0.1); color: var(--sky); border: 1px solid rgba(34, 211, 238, 0.2); }
        .badge.patient_received { background: rgba(167, 139, 250, 0.1); color: var(--purple); border: 1px solid rgba(167, 139, 250, 0.2); }
        .badge.treated { background: rgba(0, 229, 160, 0.1); color: var(--accent); border: 1px solid rgba(0, 229, 160, 0.2); }
        .badge.closed { background: rgba(100, 116, 139, 0.1); color: var(--muted2); border: 1px solid rgba(100, 116, 139, 0.2); }
        
        .badge-dot {
            width: 5px;
            height: 5px;
            border-radius: 50%;
            background: currentColor;
        }
        
        /* ─── Status Selects ─── */
        .st-sel-police {
            background: var(--bg4);
            border: 1px solid var(--border);
            border-radius: 6px;
            padding: 0.22rem 0.45rem;
            font-family: 'JetBrains Mono', monospace;
            font-size: 0.62rem;
            color: var(--light);
            cursor: pointer;
            outline: none;
            transition: border-color 0.18s;
        }
        .st-sel-police:focus {
            border-color: var(--sky);
        }
        
        .st-sel-hospital {
            background: var(--bg4);
            border: 1px solid var(--border);
            border-radius: 6px;
            padding: 0.22rem 0.45rem;
            font-family: 'JetBrains Mono', monospace;
            font-size: 0.62rem;
            color: var(--light);
            cursor: pointer;
            outline: none;
            transition: border-color 0.18s;
        }
        .st-sel-hospital:focus {
            border-color: var(--amber);
        }
        
        /* ─── Action Buttons ─── */
        .btn-assign {
            background: rgba(0, 229, 160, 0.08);
            border: 1px solid rgba(0, 229, 160, 0.2);
            color: var(--accent);
            padding: 0.2rem 0.6rem;
            border-radius: 6px;
            font-size: 0.6rem;
            cursor: pointer;
            transition: background 0.15s;
            font-family: 'JetBrains Mono', monospace;
        }
        .btn-assign:hover {
            background: var(--acc-lo);
        }
        
        .btn-auto {
            background: rgba(250, 204, 21, 0.08);
            border: 1px solid rgba(250, 204, 21, 0.2);
            color: var(--yellow);
            padding: 0.2rem 0.6rem;
            border-radius: 6px;
            font-size: 0.6rem;
            cursor: pointer;
            transition: background 0.15s;
            font-family: 'JetBrains Mono', monospace;
        }
        .btn-auto:hover {
            background: rgba(250, 204, 21, 0.15);
        }
        
        .btn-view {
            background: rgba(34, 211, 238, 0.08);
            border: 1px solid rgba(34, 211, 238, 0.2);
            color: var(--sky);
            padding: 0.2rem 0.6rem;
            border-radius: 6px;
            font-size: 0.6rem;
            cursor: pointer;
            transition: background 0.15s;
            font-family: 'JetBrains Mono', monospace;
        }
        .btn-view:hover {
            background: rgba(34, 211, 238, 0.15);
        }
        
        .btn-del {
            background: rgba(244, 63, 94, 0.08);
            border: 1px solid rgba(244, 63, 94, 0.2);
            color: var(--red);
            padding: 0.2rem 0.6rem;
            border-radius: 6px;
            font-size: 0.6rem;
            cursor: pointer;
            transition: background 0.15s;
            font-family: 'JetBrains Mono', monospace;
            text-decoration: none;
        }
        .btn-del:hover {
            background: rgba(244, 63, 94, 0.15);
        }
        
        .assigned-badge {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 4px;
            font-size: 0.58rem;
            font-weight: 600;
        }
        .assigned-badge.police {
            background: rgba(34, 211, 238, 0.1);
            color: var(--sky);
            border: 1px solid rgba(34, 211, 238, 0.2);
        }
        .assigned-badge.hospital {
            background: rgba(0, 229, 160, 0.1);
            color: var(--accent);
            border: 1px solid rgba(0, 229, 160, 0.2);
        }
        .assigned-badge.both {
            background: rgba(232, 197, 71, 0.1);
            color: #E8C547;
            border: 1px solid rgba(232, 197, 71, 0.2);
        }
        .assigned-badge.none {
            background: rgba(255, 255, 255, 0.05);
            color: var(--muted);
            border: 1px solid var(--border);
        }
        
        .gps-link {
            display: inline-flex;
            align-items: center;
            gap: 0.26rem;
            font-size: 0.68rem;
            color: var(--sky);
            text-decoration: none;
            transition: opacity 0.18s;
        }
        .gps-link:hover {
            opacity: 0.7;
        }
        
        /* ─── Smart Assignment Modal ─── */
        .smart-modal-overlay {
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, 0.85);
            backdrop-filter: blur(10px);
            z-index: 1000;
            display: none;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .smart-modal-overlay.active {
            display: flex;
        }
        
        .smart-modal-content {
            background: var(--bg3);
            border: 1px solid var(--bord2);
            border-radius: 24px;
            max-width: 950px;
            width: 100%;
            max-height: 95vh;
            overflow-y: auto;
            padding: 0;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.5);
        }
        
        .smart-modal-header {
            padding: 1.2rem 1.5rem;
            border-bottom: 1px solid var(--border);
            display: flex;
            justify-content: space-between;
            align-items: center;
            position: sticky;
            top: 0;
            background: var(--bg3);
            border-radius: 24px 24px 0 0;
            z-index: 10;
        }
        .smart-modal-header h2 {
            font-family: 'Sora', sans-serif;
            font-size: 1.2rem;
            font-weight: 700;
            color: var(--white);
        }
        .smart-modal-header .subtitle {
            font-size: 0.85rem;
            color: var(--muted);
        }
        
        .smart-modal-close {
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
            transition: background 0.18s;
        }
        .smart-modal-close:hover {
            background: rgba(244, 63, 94, 0.12);
            color: var(--red);
        }
        
        .smart-modal-body {
            padding: 1.5rem;
        }
        
        .assignment-section {
            background: rgba(0, 229, 160, 0.04);
            border: 1px solid rgba(0, 229, 160, 0.12);
            border-radius: 16px;
            padding: 1.2rem;
            margin-top: 1rem;
        }
        .assignment-section .section-title {
            color: var(--accent);
            font-weight: 700;
            font-size: 0.95rem;
            margin-bottom: 0.8rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .assignment-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
        }
        .assignment-grid .form-group label {
            display: block;
            color: var(--muted);
            font-size: 0.7rem;
            text-transform: uppercase;
            font-weight: 600;
            letter-spacing: 0.05em;
            margin-bottom: 0.3rem;
        }
        .assignment-grid select {
            width: 100%;
            background: var(--bg4);
            border: 1px solid var(--border);
            border-radius: 8px;
            padding: 0.5rem 0.7rem;
            font-family: 'Space Grotesk', sans-serif;
            font-size: 0.78rem;
            color: var(--light);
            cursor: pointer;
            outline: none;
            transition: border-color 0.18s;
        }
        .assignment-grid select:focus {
            border-color: var(--accent);
        }
        
        .btn-assign-submit {
            width: 100%;
            margin-top: 1rem;
            padding: 0.7rem;
            background: var(--accent);
            border: none;
            border-radius: 8px;
            font-size: 0.85rem;
            font-weight: 700;
            color: #07111B;
            cursor: pointer;
            transition: transform 0.15s, box-shadow 0.18s;
            box-shadow: 0 0 14px rgba(0, 229, 160, 0.3);
        }
        .btn-assign-submit:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 20px rgba(0, 229, 160, 0.5);
        }
        
        .btn-auto-submit {
            width: 100%;
            margin-top: 0.5rem;
            padding: 0.7rem;
            background: var(--yellow);
            border: none;
            border-radius: 8px;
            font-size: 0.85rem;
            font-weight: 700;
            color: #07111B;
            cursor: pointer;
            transition: transform 0.15s, box-shadow 0.18s;
            box-shadow: 0 0 14px rgba(250, 204, 21, 0.3);
        }
        .btn-auto-submit:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 20px rgba(250, 204, 21, 0.5);
        }
        
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
            padding: 0.6rem 1.4rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            font-size: 0.66rem;
            color: var(--muted);
            font-family: 'JetBrains Mono', monospace;
            background: var(--bg2);
        }
        
        .toast-wrap {
            position: fixed;
            top: 1.1rem;
            right: 1.4rem;
            z-index: 9999;
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
            pointer-events: none;
        }
        
        .toast {
            display: flex;
            align-items: flex-start;
            gap: 0.65rem;
            background: var(--bg3);
            border: 1px solid rgba(0, 229, 160, 0.28);
            border-left: 3px solid var(--accent);
            border-radius: 11px;
            padding: 0.75rem 0.9rem;
            min-width: 265px;
            max-width: 315px;
            box-shadow: 0 8px 28px rgba(0, 0, 0, 0.45);
            animation: tIn 0.3s ease;
            pointer-events: all;
        }
        
        @keyframes tIn {
            from { opacity: 0; transform: translateX(26px); }
            to { opacity: 1; transform: none; }
        }
        
        .t-ic {
            font-size: 0.95rem;
            flex-shrink: 0;
            margin-top: 1px;
        }
        .t-body {
            flex: 1;
        }
        .t-title {
            font-weight: 700;
            font-size: 0.78rem;
            color: var(--accent);
            margin-bottom: 0.15rem;
        }
        .t-msg {
            font-size: 0.7rem;
            color: var(--muted2);
            line-height: 1.4;
        }
        .t-x {
            background: none;
            border: none;
            color: var(--muted);
            cursor: pointer;
            font-size: 0.76rem;
            padding: 0;
            transition: color 0.18s;
        }
        .t-x:hover {
            color: var(--light);
        }
        
        /* ─── Responsive ─── */
        @media (max-width: 1100px) {
            .stats-row {
                grid-template-columns: repeat(2, 1fr);
            }
            .assignment-grid {
                grid-template-columns: 1fr;
            }
        }
        
        @media (max-width: 760px) {
            :root { --sw: 0px; }
            .sidebar { display: none; }
            .stats-row {
                grid-template-columns: 1fr 1fr;
            }
            .content {
                padding: 0.9rem;
            }
            .pname {
                display: none;
            }
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
                <a href="dashboard.php" class="sb-item <?= basename($_SERVER['PHP_SELF']) == 'dashboard.php' ? 'active' : '' ?>">
                    <span class="sb-ic"><i class="fas fa-chart-line"></i></span> Dashboard
                </a>
                <a href="accident_report.php" class="sb-item <?= basename($_SERVER['PHP_SELF']) == 'accident_report.php' ? 'active' : '' ?>">
                    <span class="sb-ic"><i class="fas fa-car-crash"></i></span> Accident Reports
                    <?php if ($pending_count > 0): ?>
                        <span class="sb-badge red"><?= $pending_count ?></span>
                    <?php endif; ?>
                </a>
               <a href="resolved_cases.php" class="sb-item <?= basename($_SERVER['PHP_SELF']) == 'resolved_cases.php' ? 'active' : '' ?>">
                   <span class="sb-ic"><i class="fas fa-check-circle"></i></span> Resolved Cases
                  <?php if(($police_stats['resolved'] ?? 0) > 0 || ($hospital_stats['treated'] ?? 0) > 0): ?>
                         <span class="sb-badge grn"><?= ($police_stats['resolved'] ?? 0) + ($hospital_stats['treated'] ?? 0) ?></span>
                    <?php endif; ?>
               </a>

                <div class="sb-sect" style="margin-top:.4rem">Management</div>
                <a href="manage_police.php" class="sb-item <?= basename($_SERVER['PHP_SELF']) == 'manage_police.php' ? 'active' : '' ?>">
                    <span class="sb-ic"><i class="fas fa-user-shield"></i></span> Police Stations
                </a>
                <a href="manage_hospital.php" class="sb-item <?= basename($_SERVER['PHP_SELF']) == 'manage_hospital.php' ? 'active' : '' ?>">
                    <span class="sb-ic"><i class="fas fa-hospital"></i></span> Hospitals
                </a>
                <a href="users.php" class="sb-item <?= basename($_SERVER['PHP_SELF']) == 'users.php' ? 'active' : '' ?>">
                    <span class="sb-ic"><i class="fas fa-users"></i></span> Manage Users
                </a>
                <a href="audit_logs.php" class="sb-item <?= basename($_SERVER['PHP_SELF']) == 'audit_logs.php' ? 'active' : '' ?>">
                    <span class="sb-ic"><i class="fas fa-clipboard-list"></i></span> Audit Logs
                </a>

                <div class="sb-sect" style="margin-top:.4rem">Quick</div>
                <a href="admin_report.php" class="sb-item" target="_blank">
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
                    <i class="fas fa-car-crash"></i>Accident Reports
                </div>

                <div class="tb-search">
                    <i class="fas fa-search"></i>
                    <input type="text" placeholder="Search reports, locations…" oninput="filterTable(this.value)">
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
                    <!-- Profile Button -->
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
                                    <i class="fas fa-shield-alt" style="font-size:.58rem"></i>
                                    <?= $admin_role ?>
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

                <!-- Stat Cards -->
                <div class="stats-row">
                    <div class="sc c1">
                        <div class="sc-ico"><i class="fas fa-car-crash"></i></div>
                        <div class="sc-num"><?= number_format($stats['total_accidents'] ?? 0) ?></div>
                        <div class="sc-lbl">Total Accidents</div>
                        <div class="sc-tag">+<?= $stats['today_accidents'] ?? 0 ?> today</div>
                    </div>
                    <div class="sc c2">
                        <div class="sc-ico"><i class="fas fa-clock"></i></div>
                        <div class="sc-num"><?= number_format($pending_count) ?></div>
                        <div class="sc-lbl">Pending</div>
                        <div class="sc-tag" style="color:var(--red)">Needs action</div>
                    </div>
                    <div class="sc c3">
                        <div class="sc-ico"><i class="fas fa-user-shield"></i></div>
                        <div class="sc-num"><?= number_format($stats['assigned_count'] ?? 0) ?></div>
                        <div class="sc-lbl">Assigned</div>
                        <div class="sc-tag" style="color:var(--amber)">In progress</div>
                    </div>
                    <div class="sc c4">
                        <div class="sc-ico"><i class="fas fa-check-circle"></i></div>
                        <div class="sc-num"><?= number_format($stats['resolved_count'] ?? 0) ?></div>
                        <div class="sc-lbl">Resolved</div>
                        <div class="sc-tag" style="color:var(--accent)">Closed</div>
                    </div>
                </div>

                <!-- Filter Bar -->
                <div class="filter-bar">
                    <select class="status-type-select" id="statusType" onchange="changeStatusType()">
                        <option value="police" <?= $status_type == 'police' ? 'selected' : '' ?>>🚔 Police Status</option>
                        <option value="hospital" <?= $status_type == 'hospital' ? 'selected' : '' ?>>🏥 Hospital Status</option>
                    </select>
                    
                    <a href="?status=all&type=<?= $status_type ?>" class="<?= $status_filter == 'all' ? 'active' : '' ?>">All</a>
                    <a href="?status=pending&type=<?= $status_type ?>" class="<?= $status_filter == 'pending' ? 'active' : '' ?>">Pending</a>
                    <a href="?status=assigned&type=<?= $status_type ?>" class="<?= $status_filter == 'assigned' ? 'active' : '' ?>">Assigned</a>
                    <a href="?status=dispatched&type=<?= $status_type ?>" class="<?= $status_filter == 'dispatched' ? 'active' : '' ?>">Dispatched</a>
                    <a href="?status=resolved&type=<?= $status_type ?>" class="<?= $status_filter == 'resolved' ? 'active' : '' ?>">Resolved</a>
                    
                    <?php if ($status_type == 'police'): ?>
                        <a href="?status=arrived&type=<?= $status_type ?>" class="<?= $status_filter == 'arrived' ? 'active' : '' ?>">Arrived</a>
                        <a href="?status=false_alarm&type=<?= $status_type ?>" class="<?= $status_filter == 'false_alarm' ? 'active' : '' ?>">False Alarm</a>
                    <?php else: ?>
                        <a href="?status=preparing&type=<?= $status_type ?>" class="<?= $status_filter == 'preparing' ? 'active' : '' ?>">Preparing</a>
                        <a href="?status=ambulance_dispatched&type=<?= $status_type ?>" class="<?= $status_filter == 'ambulance_dispatched' ? 'active' : '' ?>">Ambulance</a>
                        <a href="?status=patient_received&type=<?= $status_type ?>" class="<?= $status_filter == 'patient_received' ? 'active' : '' ?>">Received</a>
                        <a href="?status=treated&type=<?= $status_type ?>" class="<?= $status_filter == 'treated' ? 'active' : '' ?>">Treated</a>
                        <a href="?status=closed&type=<?= $status_type ?>" class="<?= $status_filter == 'closed' ? 'active' : '' ?>">Closed</a>
                    <?php endif; ?>
                    
                    <span class="count"><?= $accidents->num_rows ?> records</span>
                </div>

                <!-- Table -->
                <div class="panel">
                    <div class="ph">
                        <div>
                            <div class="ph-t"><i class="fas fa-list"></i>All Accident Reports</div>
                            <div class="ph-s">Manage and assign cases to responders</div>
                        </div>
                        <div style="display:flex;gap:.6rem;align-items:center">
                            <span style="font-size:.6rem;color:var(--muted);">
                                <i class="fas fa-sync-alt"></i> Auto-refresh: 30s
                            </span>
                        </div>
                    </div>

                    <div class="tbl-wrap">
                        <table>
                            <thead>
                                <tr>
                                    <th>#ID</th>
                                    <th>Reporter</th>
                                    <th>Location</th>
                                    <th>Type</th>
                                    <th>Injured</th>
                                    <th>📸 Photos</th>
                                    <th>🚔 Police</th>
                                    <th>🏥 Hospital</th>
                                    <th>Assigned</th>
                                    <th>Time</th>
                                    <th style="text-align:right">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php if ($accidents->num_rows > 0): ?>
                            <?php while ($r = $accidents->fetch_assoc()): 
                                // Decode images
                                $images = [];
                                if (!empty($r['images'])) {
                                    $decoded = json_decode($r['images'], true);
                                    if (is_array($decoded) && !empty($decoded)) {
                                        $images = $decoded;
                                    } elseif (is_string($r['images']) && strpos($r['images'], 'uploads/') !== false) {
                                        $images = [$r['images']];
                                    }
                                }
                                $has_photos = !empty($images);
                                
                                // Assigned display
                                $assigned_class = 'none';
                                $assigned_text = '—';
                                if ($r['assigned_police_id'] && $r['assigned_hospital_id']) {
                                    $assigned_class = 'both';
                                    $assigned_text = '🚔🏥';
                                } elseif ($r['assigned_police_id']) {
                                    $assigned_class = 'police';
                                    $assigned_text = '🚔';
                                } elseif ($r['assigned_hospital_id']) {
                                    $assigned_class = 'hospital';
                                    $assigned_text = '🏥';
                                }
                            ?>
                            <tr id="row-<?= $r['id'] ?>">
                                <td class="td-id">#ACC-<?= str_pad($r['id'], 4, '0', STR_PAD_LEFT) ?></td>
                                <td style="font-weight:500"><?= htmlspecialchars($r['reporter_name'] ?: 'Anonymous') ?></td>
                                <td class="td-loc" title="<?= htmlspecialchars($r['location_description'] ?? '') ?>">
                                    <?= htmlspecialchars(substr($r['location_description'] ?? 'Unknown', 0, 30)) ?>
                                </td>
                                <td><?= htmlspecialchars($r['accident_type'] ?? '—') ?></td>
                                <td class="td-inj"><?= intval($r['injuries']) ?></td>
                                <td>
                                    <?php if ($has_photos): ?>
                                        <div class="photo-thumbnails">
                                            <?php 
                                            $displayCount = 0;
                                            foreach ($images as $img): 
                                                if ($displayCount >= 2) break;
                                                $displayCount++;
                                                $imgPath = trim($img);
                                                if (strpos($imgPath, '../') === 0) $imgPath = substr($imgPath, 3);
                                                if (strpos($imgPath, './') === 0) $imgPath = substr($imgPath, 2);
                                                if (!strpos($imgPath, 'uploads/')) $imgPath = 'uploads/accidents/' . basename($imgPath);
                                                $fullPath = $imgPath;
                                                if (strpos($fullPath, 'uploads/') !== 0 && strpos($fullPath, '/uploads/') !== 0) {
                                                    $fullPath = 'uploads/accidents/' . basename($imgPath);
                                                }
                                            ?>
                                                <img src="<?= $base_url . ltrim($fullPath, '/') ?>" alt="photo" onclick="viewAccident(<?= $r['id'] ?>)" onerror="this.style.display='none'">
                                            <?php endforeach; ?>
                                            <?php if (count($images) > 2): ?>
                                                <div class="more-indicator" onclick="viewAccident(<?= $r['id'] ?>)">+<?= count($images) - 2 ?></div>
                                            <?php endif; ?>
                                        </div>
                                    <?php else: ?>
                                        <span style="color:var(--muted);font-size:.6rem;">—</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="badge <?= $r['police_status'] ?>">
                                        <span class="badge-dot"></span><?= ucfirst(str_replace('_', ' ', $r['police_status'] ?? 'pending')) ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="badge <?= $r['hospital_status'] ?>">
                                        <span class="badge-dot"></span><?= ucfirst(str_replace('_', ' ', $r['hospital_status'] ?? 'pending')) ?>
                                    </span>
                                </td>
                                <td style="text-align:center;">
                                    <span class="assigned-badge <?= $assigned_class ?>">
                                        <?= $assigned_text ?>
                                    </span>
                                </td>
                                <td class="td-time"><?= date('d M, H:i', strtotime($r['created_at'])) ?></td>
                                <td style="text-align:right;white-space:nowrap">
                                    <button class="btn-assign" onclick="openAssignModal(<?= $r['id'] ?>)">
                                        <i class="fas fa-user-cog"></i> Assign
                                    </button>
                                    <button class="btn-auto" onclick="autoAssign(<?= $r['id'] ?>)">
                                        <i class="fas fa-magic"></i> Auto
                                    </button>
                                    <a href="?delete=<?= $r['id'] ?>" onclick="return confirm('Delete this accident report?')" class="btn-del" style="text-decoration:none;">
                                        <i class="fas fa-trash"></i>
                                    </a>
                                </td>
                            </tr>
                            <?php endwhile; ?>
                            <?php else: ?>
                            <tr><td colspan="11" style="text-align:center;padding:2rem;color:var(--muted)">No accident reports found</td></tr>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Map -->
                <div class="panel" style="margin-top:1.1rem;">
                    <div class="ph">
                        <div>
                            <div class="ph-t">📍 Live Accident Map</div>
                            <div class="ph-s"><?= count($map_data) ?> location<?= count($map_data) !== 1 ? 's' : '' ?> plotted</div>
                        </div>
                        <div style="display:flex;gap:.8rem;align-items:center">
                            <div style="display:flex;gap:.8rem;align-items:center">
                                <span style="display:flex;align-items:center;gap:.25rem;font-size:.65rem;color:var(--muted2)"><span style="width:8px;height:8px;border-radius:50%;background:#F43F5E;display:inline-block;"></span>Pending</span>
                                <span style="display:flex;align-items:center;gap:.25rem;font-size:.65rem;color:var(--muted2)"><span style="width:8px;height:8px;border-radius:50%;background:#FB923C;display:inline-block;"></span>Assigned</span>
                                <span style="display:flex;align-items:center;gap:.25rem;font-size:.65rem;color:var(--muted2)"><span style="width:8px;height:8px;border-radius:50%;background:#22D3EE;display:inline-block;"></span>Dispatched</span>
                                <span style="display:flex;align-items:center;gap:.25rem;font-size:.65rem;color:var(--muted2)"><span style="width:8px;height:8px;border-radius:50%;background:#00E5A0;display:inline-block;"></span>Resolved</span>
                            </div>
                        </div>
                    </div>
                    <div id="accidentMap" style="height:320px;width:100%;border-radius:0 0 14px 14px;background:#0A1825;"></div>
                </div>

            </div><!-- /content -->

            <!-- ─── FOOTER ─── -->
            <div class="footer">
                <span>© 2026 RoadAlert TZ &nbsp;·&nbsp; System: Operational &nbsp;·&nbsp; View all Accident & Assign to Near Responder.</span>
                <span id="clock"></span>
            </div>

        </div><!-- /main -->
    </div><!-- /shell -->

    <!-- ==========================================
    SMART ASSIGNMENT MODAL
    ========================================== -->
    <div class="smart-modal-overlay" id="smartModal">
        <div class="smart-modal-content">
            <div class="smart-modal-header">
                <div>
                    <h2>📋 Assign Case</h2>
                    <div class="subtitle" id="modalSubtitle">#ACC-0000</div>
                </div>
                <button class="smart-modal-close" onclick="closeSmartModal()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="smart-modal-body">
                <form method="POST" id="assignForm">
                    <input type="hidden" name="accident_id" id="assign_accident_id">
                    
                    <div class="assignment-section">
                        <div class="section-title">
                            <i class="fas fa-bolt"></i> Manual Assignment
                        </div>
                        <div class="assignment-grid">
                            <div class="form-group">
                                <label>🚔 Police Station</label>
                                <select name="police_id" id="assign_police">
                                    <option value="0">Select Police</option>
                                    <?php 
                                    $police_stations->data_seek(0);
                                    while ($ps = $police_stations->fetch_assoc()): 
                                    ?>
                                        <option value="<?= $ps['id'] ?>">
                                            <?= htmlspecialchars($ps['name']) ?> - <?= htmlspecialchars($ps['location']) ?>
                                        </option>
                                    <?php endwhile; ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label>🏥 Hospital</label>
                                <select name="hospital_id" id="assign_hospital">
                                    <option value="0">Select Hospital</option>
                                    <?php 
                                    $hospital_stations->data_seek(0);
                                    while ($hs = $hospital_stations->fetch_assoc()): 
                                    ?>
                                        <option value="<?= $hs['id'] ?>">
                                            <?= htmlspecialchars($hs['name']) ?> - <?= htmlspecialchars($hs['location']) ?>
                                        </option>
                                    <?php endwhile; ?>
                                </select>
                            </div>
                        </div>
                        <button type="submit" name="assign_case" class="btn-assign-submit">
                            <i class="fas fa-check-circle"></i> Assign Case
                        </button>
                    </div>
                </form>

                <form method="POST" id="autoAssignForm">
                    <input type="hidden" name="accident_id" id="auto_accident_id">
                    <button type="submit" name="auto_assign" class="btn-auto-submit">
                        <i class="fas fa-magic"></i> 🤖 Auto-Assign (Nearest Responders)
                    </button>
                </form>

                <!-- Dual Status Update -->
                <div style="margin-top:1rem;padding:1rem;background:rgba(255,255,255,.02);border:1px solid var(--border);border-radius:12px;">
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:0.75rem;">
                        <form method="POST" style="display:flex;gap:0.5rem;align-items:center;flex-wrap:wrap;">
                            <input type="hidden" name="accident_id" id="status_accident_id">
                            <input type="hidden" name="status_type" value="police">
                            <label style="color:var(--sky);font-size:0.7rem;text-transform:uppercase;font-weight:600;">🚔 Police</label>
                            <select name="status" class="st-sel-police" style="flex:1;">
                                <option value="pending">Pending</option>
                                <option value="assigned">Assigned</option>
                                <option value="dispatched">Dispatched</option>
                                <option value="arrived">Arrived</option>
                                <option value="resolved">Resolved</option>
                                <option value="false_alarm">False Alarm</option>
                            </select>
                            <button type="submit" name="update_status" class="btn-view" style="padding:0.3rem 0.8rem;">
                                <i class="fas fa-save"></i>
                            </button>
                        </form>
                        <form method="POST" style="display:flex;gap:0.5rem;align-items:center;flex-wrap:wrap;">
                            <input type="hidden" name="accident_id" id="status_accident_id2">
                            <input type="hidden" name="status_type" value="hospital">
                            <label style="color:var(--amber);font-size:0.7rem;text-transform:uppercase;font-weight:600;">🏥 Hospital</label>
                            <select name="status" class="st-sel-hospital" style="flex:1;">
                                <option value="pending">Pending</option>
                                <option value="assigned">Assigned</option>
                                <option value="preparing">Preparing</option>
                                <option value="ambulance_dispatched">Ambulance</option>
                                <option value="patient_received">Received</option>
                                <option value="treated">Treated</option>
                                <option value="closed">Closed</option>
                            </select>
                            <button type="submit" name="update_status" class="btn-view" style="padding:0.3rem 0.8rem;">
                                <i class="fas fa-save"></i>
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- ==========================================
    JAVASCRIPT
    ========================================== -->
    <script>
        // ─── MAP DATA ───────────────────────────────────
        const MAP_DATA = <?= json_encode($map_data) ?>;

        // ─── CLOCK ──────────────────────────────────────
        function tick() {
            const n = new Date();
            document.getElementById('clock').textContent =
                n.toLocaleDateString('en-GB', { weekday: 'short', day: '2-digit', month: 'short' }) +
                ' · ' + n.toLocaleTimeString('en-GB', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
        }
        setInterval(tick, 1000);
        tick();

        // ─── TABLE FILTER ───────────────────────────────
        function filterTable(q) {
            const rows = document.querySelectorAll('tbody tr');
            q = q.toLowerCase();
            rows.forEach(r => {
                r.style.display = r.textContent.toLowerCase().includes(q) ? '' : 'none';
            });
        }

        // ─── STATUS TYPE CHANGE ─────────────────────────
        function changeStatusType() {
            const type = document.getElementById('statusType').value;
            const currentStatus = '<?= $status_filter ?>';
            window.location.href = '?status=' + currentStatus + '&type=' + type;
        }

        // ─── NOTIFICATION DROPDOWN ──────────────────────
        function toggleNotif() {
            const d = document.getElementById('notifDrop');
            const open = d.classList.toggle('show');
            if (open) closeProfileDrop();
        }

        // ─── PROFILE DROPDOWN ───────────────────────────
        function toggleProfileDrop() {
            const btn = document.getElementById('profileBtn');
            const drop = document.getElementById('profileDrop');
            const open = drop.classList.toggle('show');
            btn.classList.toggle('open', open);
            if (open) document.getElementById('notifDrop').classList.remove('show');
        }

        function closeProfileDrop() {
            document.getElementById('profileDrop').classList.remove('show');
            document.getElementById('profileBtn').classList.remove('open');
        }

        // ─── PROFILE MODAL ──────────────────────────────
        function openProfileModal() {
            document.getElementById('profileModal').classList.add('show');
            closeProfileDrop();
        }

        function closeProfileModal() {
            document.getElementById('profileModal').classList.remove('show');
        }

        // ─── SMART ASSIGNMENT MODAL ─────────────────────
        function openAssignModal(id) {
            const modal = document.getElementById('smartModal');
            const subtitle = document.getElementById('modalSubtitle');
            
            document.getElementById('assign_accident_id').value = id;
            document.getElementById('auto_accident_id').value = id;
            document.getElementById('status_accident_id').value = id;
            document.getElementById('status_accident_id2').value = id;
            
            subtitle.textContent = '#ACC-' + String(id).padStart(4, '0');
            modal.classList.add('active');
        }

        function closeSmartModal() {
            document.getElementById('smartModal').classList.remove('active');
        }

        // ─── AUTO ASSIGN ────────────────────────────────
        function autoAssign(id) {
            if (!confirm('Auto-assign to nearest responders?')) return;
            
            const form = document.getElementById('autoAssignForm');
            document.getElementById('auto_accident_id').value = id;
            form.submit();
        }

        // ─── TOAST ──────────────────────────────────────
        function showToast(title, msg, type) {
            const w = document.getElementById('toastWrap');
            const icon = type === 'ok' ? '✅' : type === 'err' ? '⚠️' : '🚨';
            const el = document.createElement('div');
            el.className = 'toast';
            el.innerHTML = `
                <div class="t-ic">${icon}</div>
                <div class="t-body">
                    <div class="t-title">${title}</div>
                    <div class="t-msg">${msg}</div>
                </div>
                <button class="t-x" onclick="this.closest('.toast').remove()">✕</button>
            `;
            w.appendChild(el);
            setTimeout(() => el.remove(), 5500);
        }

        // ─── MAP ────────────────────────────────────────
        function statusColor(s) {
            if (s === 'pending') return '#F43F5E';
            if (s === 'assigned') return '#FB923C';
            if (s === 'dispatched') return '#22D3EE';
            if (s === 'resolved') return '#00E5A0';
            if (s === 'arrived') return '#22D3EE';
            if (s === 'treated') return '#00E5A0';
            return '#4E7090';
        }

        function makeIcon(s) {
            const c = statusColor(s);
            return L.divIcon({
                html: `<div style="width:14px;height:14px;border-radius:50%;background:${c};border:2.5px solid #fff;box-shadow:0 2px 8px rgba(0,0,0,.35)"></div>`,
                iconSize: [14, 14],
                iconAnchor: [7, 7],
                className: ''
            });
        }

        const center = MAP_DATA.length ? [MAP_DATA[0].lat, MAP_DATA[0].lng] : [-6.7924, 39.2083];
        const map = L.map('accidentMap').setView(center, MAP_DATA.length ? 11 : 6);

        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>'
        }).addTo(map);

        L.control.scale({ metric: true, imperial: false }).addTo(map);

        if (MAP_DATA.length > 0) {
            MAP_DATA.forEach(a => {
                const m = L.marker([a.lat, a.lng], { icon: makeIcon(a.police_status) }).addTo(map);
                m.bindPopup(`
                    <div style="font-family:'Space Grotesk',sans-serif;min-width:200px">
                        <div style="font-weight:700;font-size:.85rem;color:#E8C547;margin-bottom:3px">
                            🚨 #ACC-${String(a.id).padStart(4,'0')}
                        </div>
                        <div style="font-size:.7rem;color:#fff;margin-bottom:2px">${a.type}</div>
                        <div style="font-size:.65rem;color:#94a3b8;margin-bottom:4px">${a.location}</div>
                        <div style="display:flex;gap:6px;font-size:.65rem;align-items:center;flex-wrap:wrap;color:#fff">
                            <span>🤕 ${a.injuries} injured</span>
                            <span style="color:${statusColor(a.police_status)};font-weight:700;text-transform:uppercase">🚔 ${a.police_status}</span>
                            <span style="color:${statusColor(a.hospital_status)};font-weight:700;text-transform:uppercase">🏥 ${a.hospital_status}</span>
                        </div>
                        <div style="font-size:.6rem;color:#94a3b8;margin-top:3px">${a.time}</div>
                        <hr style="border:none;border-top:1px solid #e2e8f0;margin:4px 0">
                        <button onclick="openAssignModal(${a.id})" style="background:#E8C547;color:#222;border:none;border-radius:6px;padding:3px 10px;font-size:10px;font-weight:700;cursor:pointer;">
                            Assign →
                        </button>
                    </div>
                `);
                m.bindTooltip(`#ACC-${String(a.id).padStart(4,'0')}: ${a.type}`, { sticky: true });
            });
            
            if (MAP_DATA.length > 1) {
                map.fitBounds(L.latLngBounds(MAP_DATA.map(a => [a.lat, a.lng])), { padding: [30, 30] });
            }
        }

        // ─── CLOSE ON OUTSIDE CLICK ─────────────────────
        document.addEventListener('click', function(e) {
            if (!e.target.closest('#notifDrop') && !e.target.closest('.notif-btn')) {
                document.getElementById('notifDrop').classList.remove('show');
            }
            if (!e.target.closest('#profileDrop') && !e.target.closest('#profileBtn')) {
                closeProfileDrop();
            }
            if (e.target === document.getElementById('smartModal')) {
                closeSmartModal();
            }
            if (e.target === document.getElementById('profileModal')) {
                closeProfileModal();
            }
        });

        // ─── ESCAPE KEY ────────────────────────────────
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeSmartModal();
                closeProfileModal();
            }
        });

        // ─── VIEW ACCIDENT ─────────────────────────────
        function viewAccident(id) {
            window.location.href = 'accident_report.php?view=' + id;
        }

        // ─── SHOW TOAST ────────────────────────────────
        <?php if ($message): ?>
        showToast('<?= strpos($message, '✅') !== false ? 'Success' : 'Info' ?>', '<?= addslashes($message) ?>', 'ok');
        <?php endif; ?>
    </script>
</body>
</html>