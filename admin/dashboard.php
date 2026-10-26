<?php
/**
 * ADMIN DASHBOARD
 * RoadAlert TZ - Admin Control Panel with Auto-Assign Notifications
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
// 📊 FETCH DASHBOARD STATISTICS
// ============================================

// ─── Total Accidents ───
$total_result = $conn->query("SELECT COUNT(*) as total FROM accidents");
$total_accidents = $total_result->fetch_assoc()['total'];

// ─── Police Status Statistics ───
$police_stats = $conn->query("
    SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN police_status = 'pending' THEN 1 ELSE 0 END) as pending,
        SUM(CASE WHEN police_status = 'assigned' THEN 1 ELSE 0 END) as assigned,
        SUM(CASE WHEN police_status = 'dispatched' THEN 1 ELSE 0 END) as dispatched,
        SUM(CASE WHEN police_status = 'arrived' THEN 1 ELSE 0 END) as arrived,
        SUM(CASE WHEN police_status = 'resolved' THEN 1 ELSE 0 END) as resolved,
        SUM(CASE WHEN police_status = 'false_alarm' THEN 1 ELSE 0 END) as false_alarm
    FROM accidents
")->fetch_assoc();

// ─── Hospital Status Statistics ───
$hospital_stats = $conn->query("
    SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN hospital_status = 'pending' THEN 1 ELSE 0 END) as pending,
        SUM(CASE WHEN hospital_status = 'assigned' THEN 1 ELSE 0 END) as assigned,
        SUM(CASE WHEN hospital_status = 'preparing' THEN 1 ELSE 0 END) as preparing,
        SUM(CASE WHEN hospital_status = 'ambulance_dispatched' THEN 1 ELSE 0 END) as ambulance_dispatched,
        SUM(CASE WHEN hospital_status = 'patient_received' THEN 1 ELSE 0 END) as patient_received,
        SUM(CASE WHEN hospital_status = 'treated' THEN 1 ELSE 0 END) as treated,
        SUM(CASE WHEN hospital_status = 'closed' THEN 1 ELSE 0 END) as closed
    FROM accidents
")->fetch_assoc();

// ─── Assigned Cases Summary ───
$assigned_cases = $conn->query("
    SELECT
        (SELECT COUNT(*) FROM accidents WHERE assigned_police_id IS NOT NULL) as assigned_to_police,
        (SELECT COUNT(*) FROM accidents WHERE assigned_hospital_id IS NOT NULL) as assigned_to_hospital,
        (SELECT COUNT(*) FROM accidents WHERE assigned_police_id IS NOT NULL OR assigned_hospital_id IS NOT NULL) as total_assigned
")->fetch_assoc();

// ─── 🔴 NEW: Auto-Assigned Today ───
$auto_assigned_today = $conn->query("
    SELECT COUNT(*) as count 
    FROM accidents 
    WHERE DATE(created_at) = CURDATE() 
    AND (assigned_police_id IS NOT NULL OR assigned_hospital_id IS NOT NULL)
    AND (police_status = 'assigned' OR hospital_status = 'assigned')
")->fetch_assoc()['count'] ?? 0;

// ─── 🔴 NEW: Recent Auto-Assignments (Last 5) ───
$recent_auto_assign = $conn->query("
    SELECT 
        a.id as accident_id,
        a.accident_type,
        a.location_description,
        a.created_at,
        a.police_status,
        a.hospital_status,
        ps.name as police_name,
        hs.name as hospital_name
    FROM accidents a
    LEFT JOIN police_stations ps ON a.assigned_police_id = ps.id
    LEFT JOIN hospital_stations hs ON a.assigned_hospital_id = hs.id
    WHERE (a.assigned_police_id IS NOT NULL OR a.assigned_hospital_id IS NOT NULL)
    AND (a.police_status = 'assigned' OR a.hospital_status = 'assigned')
    ORDER BY a.created_at DESC 
    LIMIT 5
");

// ─── Pending User Approvals ───
$pending_users = $conn->query("
    SELECT
        (SELECT COUNT(*) FROM users WHERE approval_status = 'pending') as pending_approval,
        (SELECT COUNT(*) FROM users WHERE approval_status = 'rejected') as rejected_count
")->fetch_assoc();

// ─── User Role Distribution ───
$users = $conn->query("
    SELECT
        (SELECT COUNT(*) FROM users) as total_users,
        (SELECT COUNT(*) FROM users WHERE role = 'admin') as admin_count,
        (SELECT COUNT(*) FROM users WHERE role = 'police') as police_count,
        (SELECT COUNT(*) FROM users WHERE role = 'hospital') as hospital_count,
        (SELECT COUNT(*) FROM users WHERE role = 'citizen') as citizen_count
")->fetch_assoc();
// ─── Pending Notifications ───
$pending_q = $conn->query("
    SELECT id, accident_type, location_description, police_status, hospital_status, 
           created_at, reporter_name, injuries 
    FROM accidents 
    WHERE police_status = 'pending' OR hospital_status = 'pending'
    ORDER BY created_at DESC
");
$pending_count = $pending_q->num_rows;

// ─── 🔴 Auto-Assigned Today ───
$auto_assigned_today = $conn->query("
    SELECT COUNT(*) as count 
    FROM accidents 
    WHERE DATE(created_at) = CURDATE() 
    AND (assigned_police_id IS NOT NULL OR assigned_hospital_id IS NOT NULL)
    AND (police_status = 'assigned' OR hospital_status = 'assigned')
")->fetch_assoc()['count'] ?? 0;

// ─── 🔴 COMBINE FOR NOTIFICATION BADGE ───
$notification_count = $pending_count + $auto_assigned_today;
// ─── Recent Accidents ───
$recent_q = $conn->query("
    SELECT 
        a.id, a.accident_type, a.location_description, 
        a.police_status, a.hospital_status,
        a.created_at, a.reporter_name, a.injuries, 
        a.latitude, a.longitude,
        a.assigned_police_id,
        a.assigned_hospital_id,
        ps.name as police_station_name,
        hs.name as hospital_station_name
    FROM accidents a
    LEFT JOIN police_stations ps ON a.assigned_police_id = ps.id
    LEFT JOIN hospital_stations hs ON a.assigned_hospital_id = hs.id
    ORDER BY a.created_at DESC 
    LIMIT 10
");

// ─── Accident Types Distribution ───
$type_q = $conn->query("
    SELECT accident_type, COUNT(*) as count 
    FROM accidents 
    GROUP BY accident_type 
    LIMIT 6
");
$type_labels = [];
$type_counts = [];
while ($r = $type_q->fetch_assoc()) {
    $type_labels[] = $r['accident_type'];
    $type_counts[] = $r['count'];
}

// ─── Weekly Trends (Last 7 Days) ───
$weekly_q = $conn->query("
    SELECT DAYNAME(created_at) as day, COUNT(*) as count 
    FROM accidents 
    WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) 
    GROUP BY DAYNAME(created_at)
");
$week_days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
$week_data = array_fill(0, 7, 0);
while ($r = $weekly_q->fetch_assoc()) {
    $i = array_search($r['day'], $week_days);
    if ($i !== false) $week_data[$i] = $r['count'];
}

// ─── Map Data (Locations with Coordinates) ───
$map_q = $conn->query("
    SELECT 
        id, 
        accident_type, 
        location_description, 
        police_status, 
        hospital_status,
        created_at, 
        reporter_name, 
        injuries, 
        latitude, 
        longitude,
        assigned_police_id, 
        assigned_hospital_id
    FROM accidents 
    WHERE latitude IS NOT NULL AND longitude IS NOT NULL 
    ORDER BY created_at DESC LIMIT 50
");
$map_data = [];
while ($r = $map_q->fetch_assoc()) {
    $map_data[] = [
        'id' => $r['id'],
        'lat' => (float)$r['latitude'],
        'lng' => (float)$r['longitude'],
        'type' => htmlspecialchars($r['accident_type']),
        'location' => htmlspecialchars(substr($r['location_description'] ?? 'Unknown', 0, 60)),
        'status' => $r['police_status'],
        'hospital_status' => $r['hospital_status'],
        'injuries' => $r['injuries'],
        'reporter' => htmlspecialchars($r['reporter_name']),
        'time' => date('d M Y, H:i', strtotime($r['created_at'])),
        'assigned_police' => !empty($r['assigned_police_id']),
        'assigned_hospital' => !empty($r['assigned_hospital_id'])
    ];
}

// ─── Admin User Info ───
$admin_name  = htmlspecialchars($_SESSION['name']  ?? 'System Admin');
$admin_email = htmlspecialchars($_SESSION['email'] ?? 'admin@roadalert.tz');
$admin_role  = htmlspecialchars($_SESSION['role']  ?? 'admin');
$admin_init  = strtoupper(substr($admin_name, 0, 1));

// ─── Helper: Active Page Check ───
function is_active($page) {
    return basename($_SERVER['PHP_SELF']) === $page ? 'active' : '';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard — RoadAlert TZ</title>
    
    <!-- Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Sora:wght@300;400;500;600;700;800&family=Space+Grotesk:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    
    <!-- Leaflet Map -->
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    
    <!-- Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    
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
            grid-template-columns: repeat(5, 1fr);
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
        .sc.c5::after { background: linear-gradient(90deg, var(--yellow), transparent); }
        
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
        .c5 .sc-ico { background: rgba(250, 204, 21, 0.1); color: var(--yellow); }
        
        .sc-num {
            font-family: 'Sora', sans-serif;
            font-size: 2rem;
            font-weight: 800;
            line-height: 1;
            margin-bottom: 0.22rem;
        }
        .c1 .sc-num { color: var(--sky); }
        .c2 .sc-num { color: var(--red); }
        .c3 .sc-num { color: var(--amber); }
        .c4 .sc-num { color: var(--accent); }
        .c5 .sc-num { color: var(--yellow); }
        
        .sc-lbl {
            font-size: 0.62rem;
            color: var(--muted);
            letter-spacing: 0.09em;
            text-transform: uppercase;
        }
        .sc-tag {
            position: absolute;
            top: 1.1rem;
            right: 1.1rem;
            font-size: 0.55rem;
            font-family: 'JetBrains Mono', monospace;
            color: var(--muted);
        }
        
        /* ─── Case Cards ─── */
        .case-row {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 1rem;
            margin-bottom: 1.1rem;
        }
        
        .case-card {
            border-radius: 13px;
            padding: 0.9rem 1.1rem;
            display: flex;
            align-items: center;
            gap: 0.85rem;
            position: relative;
            overflow: hidden;
        }
        .case-card::before {
            content: '';
            position: absolute;
            inset: 0;
            background: rgba(0, 0, 0, 0.18);
        }
        .case-card > * {
            position: relative;
            z-index: 1;
        }
        .case-card.cp { background: linear-gradient(135deg, #1e3a5f, #2d6da8); }
        .case-card.ch { background: linear-gradient(135deg, #5f1e3a, #a82d5d); }
        .case-card.cu { background: linear-gradient(135deg, #1e5f3a, #2da86d); }
        
        .case-ic {
            font-size: 1.5rem;
            opacity: 0.65;
        }
        .case-num {
            font-family: 'Sora', sans-serif;
            font-size: 1.85rem;
            font-weight: 800;
            color: #fff;
            line-height: 1;
        }
        .case-lbl {
            font-size: 0.6rem;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            color: rgba(255, 255, 255, 0.65);
            margin-top: 2px;
        }
        .case-sub {
            font-size: 0.55rem;
            color: rgba(255, 255, 255, 0.5);
            margin-top: 1px;
        }
        
        /* ─── User Cards ─── */
        .user-row {
            display: grid;
            grid-template-columns: repeat(5, 1fr);
            gap: 1rem;
            margin-bottom: 1.1rem;
        }
        
        .uc {
            background: var(--bg3);
            border: 1px solid var(--border);
            border-radius: 13px;
            padding: 0.9rem;
            text-align: center;
            transition: border-color 0.2s, transform 0.18s;
        }
        .uc:hover {
            border-color: var(--bord2);
            transform: translateY(-2px);
        }
        
        .uc-num {
            font-family: 'Sora', sans-serif;
            font-size: 2rem;
            font-weight: 800;
            line-height: 1;
            margin-bottom: 0.28rem;
        }
        .uc-lbl {
            font-size: 0.62rem;
            color: var(--muted);
            text-transform: uppercase;
            letter-spacing: 0.08em;
        }
        
        .uc.manage {
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .btn-manage {
            display: flex;
            align-items: center;
            gap: 0.45rem;
            padding: 0.62rem 0.9rem;
            background: var(--accent);
            border: none;
            border-radius: 9px;
            cursor: pointer;
            font-size: 0.76rem;
            font-weight: 700;
            color: #07111B;
            text-decoration: none;
            font-family: 'Space Grotesk', sans-serif;
            box-shadow: 0 0 14px rgba(0, 229, 160, 0.3);
            transition: transform 0.15s, box-shadow 0.18s;
            width: 100%;
            justify-content: center;
        }
        .btn-manage:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 20px rgba(0, 229, 160, 0.5);
        }
        
        /* ─── Auto-Assign Notification Badge ─── */
        .auto-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.3rem;
            font-size: 0.55rem;
            font-weight: 600;
            padding: 0.15rem 0.5rem;
            border-radius: 999px;
            background: rgba(250, 204, 21, 0.15);
            color: var(--yellow);
            border: 1px solid rgba(250, 204, 21, 0.25);
            font-family: 'JetBrains Mono', monospace;
        }
        
        /* ─── Charts ─── */
        .charts-row {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 1rem;
            margin-bottom: 1.1rem;
        }
        
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
        
        .chart-box {
            padding: 1rem;
            flex: 1;
        }
        
        /* ─── Map ─── */
        .map-row {
            margin-bottom: 1.1rem;
        }
        
        .map-legend {
            display: flex;
            gap: 0.9rem;
            align-items: center;
        }
        .leg {
            display: flex;
            align-items: center;
            gap: 0.32rem;
            font-size: 0.68rem;
            color: var(--muted2);
        }
        .leg-d {
            width: 8px;
            height: 8px;
            border-radius: 50%;
        }
        
        #accidentMap {
            height: 370px;
            width: 100%;
            border-radius: 0 0 14px 14px;
            background: #0A1825;
        }
        
        /* ─── Table ─── */
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
        .badge.assigned { background: rgba(34, 211, 238, 0.1); color: var(--sky); border: 1px solid rgba(34, 211, 238, 0.2); }
        .badge.dispatched { background: rgba(34, 211, 238, 0.1); color: var(--sky); border: 1px solid rgba(34, 211, 238, 0.2); }
        .badge.arrived { background: rgba(251, 146, 60, 0.1); color: var(--amber); border: 1px solid rgba(251, 146, 60, 0.2); }
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
        
        /* ─── Status Update Selects ─── */
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
        
        /* ============================================
           TOAST NOTIFICATIONS
           ============================================ */
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
        
        /* ============================================
           MODAL
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
        
        /* ─── Background Grid ─── */
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
        
        /* ─── Footer ─── */
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
        
        /* ============================================
           RESPONSIVE
           ============================================ */
        @media (max-width: 1100px) {
            .stats-row { grid-template-columns: repeat(3, 1fr); }
            .case-row { grid-template-columns: repeat(2, 1fr); }
            .charts-row { grid-template-columns: 1fr; }
            .user-row { grid-template-columns: repeat(3, 1fr); }
        }
        
        @media (max-width: 760px) {
            :root { --sw: 0px; }
            .sidebar { display: none; }
            .stats-row, .case-row, .user-row {
                grid-template-columns: 1fr 1fr;
            }
            .content { padding: 0.9rem; }
            .pname { display: none; }
        }
    </style>
</head>
<body>
    <div class="grid-bg"></div>
    <div class="toast-wrap" id="toastWrap"></div>

    <!-- ─── PROFILE MODAL ─── -->
    <div class="modal-bg" id="profileModal">
        <div class="modal">
            <button class="modal-close" onclick="closeModal()"><i class="fas fa-times"></i></button>
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
                    <input type="tel" name="phone" value="<?= htmlspecialchars($_SESSION['phone']??'') ?>" placeholder="+255 7XX XXX XXX">
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
                    <button type="button" class="btn-cancel2" onclick="closeModal()">Cancel</button>
                </div>
            </form>
        </div>
    </div>

    <!-- ─── MAIN SHELL ─── -->
    <div class="shell">

        <!-- ─── SIDEBAR ─── -->
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
                <a href="dashboard.php" class="sb-item active">
                    <span class="sb-ic"><i class="fas fa-chart-line"></i></span> Dashboard
                </a>
                <a href="accident_report.php" class="sb-item">
                    <span class="sb-ic"><i class="fas fa-car-crash"></i></span> Accident Reports
                    <?php if($pending_count > 0): ?>
                        <span class="sb-badge red"><?= $pending_count ?></span>
                    <?php endif; ?>
                </a>
                <a href="resolved_cases.php" class="sb-item">
                    <span class="sb-ic"><i class="fas fa-check-circle"></i></span> Resolved Cases
                    <?php if(($police_stats['resolved'] ?? 0) > 0 || ($hospital_stats['treated'] ?? 0) > 0): ?>
                        <span class="sb-badge grn"><?= ($police_stats['resolved'] ?? 0) + ($hospital_stats['treated'] ?? 0) ?></span>
                    <?php endif; ?>
                </a>

                <div class="sb-sect" style="margin-top:.4rem">Management</div>
                <a href="manage_police.php" class="sb-item">
                    <span class="sb-ic"><i class="fas fa-user-shield"></i></span> Police Stations
                </a>
                <a href="manage_hospital.php" class="sb-item">
                    <span class="sb-ic"><i class="fas fa-hospital"></i></span> Hospitals
                </a>
                <a href="users.php" class="sb-item">
                    <span class="sb-ic"><i class="fas fa-users"></i></span> Manage Users
                </a>
                <a href="audit_logs.php" class="sb-item">
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

        <!-- ─── MAIN CONTENT ─── -->
        <div class="main">

            <!-- ─── TOPBAR ─── -->
            <div class="topbar">
                <div class="tb-title">
                    <i class="fas fa-chart-line"></i>Dashboard
                </div>

                <div class="tb-search">
                    <i class="fas fa-search"></i>
                    <input type="text" placeholder="Search reports, locations…" oninput="filterTable(this.value)">
                </div>

                <div class="tb-acts">
                    <div class="live-pill">
                        <span class="live-dot"></span>Live
                    </div>
<!-- ─── NOTIFICATION BELL ─── -->
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
            $pending_q->data_seek(0);
            if($pending_q->num_rows > 0):
                while($al = $pending_q->fetch_assoc()): ?>
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

                    <!-- ─── PROFILE BUTTON ─── -->
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
                                <button class="pd-item" onclick="openModal();closeProfileDrop()">
                                    <i class="fas fa-user-edit"></i> Edit Profile
                                </button>
                                <button class="pd-item" onclick="openModal();closeProfileDrop()">
                                    <i class="fas fa-lock"></i> Change Password
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

                <!-- ─── STAT CARDS ─── -->
                <div class="stats-row">
                    <div class="sc c1">
                        <div class="sc-ico"><i class="fas fa-car-crash"></i></div>
                        <div class="sc-num"><?= number_format($total_accidents) ?></div>
                        <div class="sc-lbl">Total Accidents</div>
                        <div class="sc-tag">All cases</div>
                    </div>
                    <div class="sc c2">
                        <div class="sc-ico"><i class="fas fa-clock"></i></div>
                        <div class="sc-num"><?= number_format($pending_count) ?></div>
                        <div class="sc-lbl">Pending</div>
                        <div class="sc-tag" style="color:var(--red)">Needs action</div>
                    </div>
                    <div class="sc c3">
                        <div class="sc-ico"><i class="fas fa-user-shield"></i></div>
                        <div class="sc-num"><?= number_format($police_stats['resolved'] ?? 0) ?></div>
                        <div class="sc-lbl">Police Resolved</div>
                        <div class="sc-tag" style="color:var(--sky)">🚔 Closed</div>
                    </div>
                    <div class="sc c4">
                        <div class="sc-ico"><i class="fas fa-hospital"></i></div>
                        <div class="sc-num"><?= number_format($hospital_stats['treated'] ?? 0) ?></div>
                        <div class="sc-lbl">Hospital Treated</div>
                        <div class="sc-tag" style="color:var(--accent)">🏥 Done</div>
                    </div>
                    <div class="sc c5">
                        <div class="sc-ico"><i class="fas fa-robot"></i></div>
                        <div class="sc-num" style="color:var(--yellow);"><?= number_format($auto_assigned_today) ?></div>
                        <div class="sc-lbl">Auto-Assigned Today</div>
                        <div class="sc-tag" style="color:var(--yellow);">🤖 System assigned</div>
                    </div>
                </div>

                <!-- ─── ASSIGNED CASES & PENDING USERS ─── -->
                <div class="case-row">
                    <div class="case-card cp">
                        <div class="case-ic"><i class="fas fa-user-shield"></i></div>
                        <div>
                            <div class="case-num"><?= number_format($assigned_cases['assigned_to_police'] ?? 0) ?></div>
                            <div class="case-lbl">Assigned to Police</div>
                            <div class="case-sub">🚔 Cases with police assigned</div>
                        </div>
                    </div>
                    <div class="case-card ch">
                        <div class="case-ic"><i class="fas fa-hospital"></i></div>
                        <div>
                            <div class="case-num"><?= number_format($assigned_cases['assigned_to_hospital'] ?? 0) ?></div>
                            <div class="case-lbl">Assigned to Hospital</div>
                            <div class="case-sub">🏥 Cases with hospital assigned</div>
                        </div>
                    </div>
                    <div class="case-card cu">
                        <div class="case-ic"><i class="fas fa-user-clock"></i></div>
                        <div>
                            <div class="case-num"><?= number_format($pending_users['pending_approval'] ?? 0) ?></div>
                            <div class="case-lbl">Pending Users</div>
                            <div class="case-sub">⏳ Awaiting admin approval</div>
                        </div>
                    </div>
                </div>

                <!-- ─── USER DISTRIBUTION ─── -->
                <div class="user-row">
                    <div class="uc">
                        <div class="uc-num" style="color:var(--sky)"><?= $users['total_users'] ?? 0 ?></div>
                        <div class="uc-lbl">Total Users</div>
                    </div>
                    <div class="uc">
                        <div class="uc-num" style="color:var(--accent)"><?= $users['admin_count'] ?? 0 ?></div>
                        <div class="uc-lbl">Admins</div>
                    </div>
                    <div class="uc">
                        <div class="uc-num" style="color:var(--amber)"><?= $users['police_count'] ?? 0 ?></div>
                        <div class="uc-lbl">Police</div>
                    </div>
                    <div class="uc">
                        <div class="uc-num" style="color:var(--purple)"><?= $users['hospital_count'] ?? 0 ?></div>
                        <div class="uc-lbl">Hospital</div>
                    </div>
                    <div class="uc manage">
                        <a href="users.php" class="btn-manage">
                            <i class="fas fa-users"></i>&nbsp; Manage Users
                        </a>
                    </div>
                </div>

                <!-- ─── RECENT AUTO-ASSIGNMENTS ─── -->
                <?php if($recent_auto_assign && $recent_auto_assign->num_rows > 0): ?>
                <div class="panel" style="margin-bottom:1.1rem;border-color:rgba(250,204,21,0.3);">
                    <div class="ph" style="border-bottom-color:rgba(250,204,21,0.3);">
                        <div>
                            <div class="ph-t"><i class="fas fa-robot" style="color:var(--yellow);"></i> Recent Auto-Assignments</div>
                            <div class="ph-s" style="color:var(--yellow);">Cases automatically assigned to nearest responders</div>
                        </div>
                        <span class="auto-badge">
                            <i class="fas fa-magic"></i> <?= $auto_assigned_today ?> today
                        </span>
                    </div>
                    <div class="tbl-wrap">
                        <table>
                            <thead>
                                <tr>
                                    <th>#ID</th>
                                    <th>Type</th>
                                    <th>Location</th>
                                    <th>🚔 Police Assigned</th>
                                    <th>🏥 Hospital Assigned</th>
                                    <th>Time</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php while($aa = $recent_auto_assign->fetch_assoc()): ?>
                                <tr>
                                    <td class="td-id">#ACC-<?= str_pad($aa['accident_id'], 4, '0', STR_PAD_LEFT) ?></td>
                                    <td><?= htmlspecialchars($aa['accident_type'] ?? '—') ?></td>
                                    <td class="td-loc" title="<?= htmlspecialchars($aa['location_description'] ?? '') ?>">
                                        <?= htmlspecialchars(substr($aa['location_description'] ?? 'Unknown', 0, 30)) ?>
                                    </td>
                                    <td>
                                        <?php if($aa['police_name']): ?>
                                            <span style="color:var(--sky);font-size:.65rem;">
                                                <i class="fas fa-check-circle"></i> <?= htmlspecialchars($aa['police_name']) ?>
                                            </span>
                                        <?php else: ?>
                                            <span style="color:var(--muted);font-size:.6rem;">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if($aa['hospital_name']): ?>
                                            <span style="color:var(--accent);font-size:.65rem;">
                                                <i class="fas fa-check-circle"></i> <?= htmlspecialchars($aa['hospital_name']) ?>
                                            </span>
                                        <?php else: ?>
                                            <span style="color:var(--muted);font-size:.6rem;">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="td-time"><?= date('d M, H:i', strtotime($aa['created_at'])) ?></td>
                                </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <?php endif; ?>

                <!-- ─── CHARTS ─── -->
                <div class="charts-row">
                    <div class="panel">
                        <div class="ph">
                            <div>
                                <div class="ph-t">Weekly Accident Trends</div>
                                <div class="ph-s">Last 7 days</div>
                            </div>
                        </div>
                        <div class="chart-box" style="height:210px">
                            <canvas id="weekChart"></canvas>
                        </div>
                    </div>
                    <div class="panel">
                        <div class="ph">
                            <div>
                                <div class="ph-t">By Type</div>
                                <div class="ph-s">Distribution</div>
                            </div>
                        </div>
                        <div class="chart-box" style="height:210px">
                            <canvas id="typeChart"></canvas>
                        </div>
                    </div>
                </div>

                <!-- ─── MAP ─── -->
                <div class="panel map-row">
                    <div class="ph">
                        <div>
                            <div class="ph-t">🛰️ Live Accident Map — Satellite View</div>
                            <div class="ph-s"><?= count($map_data) ?> location<?= count($map_data) !== 1 ? 's' : '' ?> plotted</div>
                        </div>
                        <div class="map-legend">
                            <div class="leg"><div class="leg-d" style="background:#F43F5E"></div>Pending</div>
                            <div class="leg"><div class="leg-d" style="background:#22D3EE"></div>Dispatched</div>
                            <div class="leg"><div class="leg-d" style="background:#00E5A0"></div>Resolved</div>
                            <div class="leg"><div class="leg-d" style="background:#FB923C"></div>Arrived</div>
                        </div>
                    </div>
                    <div id="accidentMap"></div>
                </div>

                <!-- ─── RECENT REPORTS TABLE ─── -->
                <div class="panel">
                    <div class="ph">
                        <div>
                            <div class="ph-t">Recent Reports</div>
                            <div class="ph-s">Latest 10 records</div>
                        </div>
                        <a href="accident_report.php" class="ph-a">View All →</a>
                    </div>
                    <div class="tbl-wrap">
                        <table>
                            <thead>
                                <tr>
                                    <th>#ID</th>
                                    <th>Reporter</th>
                                    <th>Type</th>
                                    <th>Location</th>
                                    <th>Injured</th>
                                    <th>🚔 Police Status</th>
                                    <th>🏥 Hospital Status</th>
                                    <th>Assigned</th>
                                    <th>GPS</th>
                                    <th>Time</th>
                                    <th>Update</th>
                                </tr>
                            </thead>
                            <tbody id="tableBody">
                            <?php $recent_q->data_seek(0); while($r = $recent_q->fetch_assoc()): ?>
                            <tr id="row-<?= $r['id'] ?>">
                                <td class="td-id">#ACC-<?= str_pad($r['id'], 4, '0', STR_PAD_LEFT) ?></td>
                                <td style="font-weight:500"><?= htmlspecialchars($r['reporter_name'] ?: 'Anonymous') ?></td>
                                <td><?= htmlspecialchars($r['accident_type'] ?? '—') ?></td>
                                <td class="td-loc" title="<?= htmlspecialchars($r['location_description'] ?? '') ?>">
                                    <?= htmlspecialchars(substr($r['location_description'] ?? 'Unknown', 0, 36)) ?>…
                                </td>
                                <td class="td-inj"><?= intval($r['injuries']) ?></td>
                                <td>
                                    <span class="badge <?= $r['police_status'] ?>">
                                        <span class="badge-dot"></span><?= ucfirst(str_replace('_', ' ', $r['police_status'] ?? 'pending')) ?>
                                    </span>
                                    <?php if($r['assigned_police_id']): ?>
                                    <span class="auto-badge" style="font-size:.45rem;padding:0.05rem 0.3rem;">
                                        <i class="fas fa-robot"></i> auto
                                    </span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="badge <?= $r['hospital_status'] ?>">
                                        <span class="badge-dot"></span><?= ucfirst(str_replace('_', ' ', $r['hospital_status'] ?? 'pending')) ?>
                                    </span>
                                    <?php if($r['assigned_hospital_id']): ?>
                                    <span class="auto-badge" style="font-size:.45rem;padding:0.05rem 0.3rem;">
                                        <i class="fas fa-robot"></i> auto
                                    </span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if($r['assigned_police_id'] && $r['assigned_hospital_id']): ?>
                                        <span style="color:var(--yellow);font-size:.6rem;">🚔🏥 Both</span>
                                    <?php elseif($r['assigned_police_id']): ?>
                                        <span style="color:var(--sky);font-size:.6rem;">🚔 Police</span>
                                    <?php elseif($r['assigned_hospital_id']): ?>
                                        <span style="color:var(--accent);font-size:.6rem;">🏥 Hospital</span>
                                    <?php else: ?>
                                        <span style="color:var(--muted);font-size:.6rem;">—</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if(!empty($r['latitude']) && !empty($r['longitude'])): ?>
                                    <a href="https://maps.google.com/?q=<?= $r['latitude'] ?>,<?= $r['longitude'] ?>"
                                       target="_blank" class="gps-link">
                                        <i class="fas fa-map-marker-alt"></i> Map
                                    </a>
                                    <?php else: ?>
                                    <span style="color:var(--muted);font-size:.68rem">N/A</span>
                                    <?php endif; ?>
                                </td>
                                <td class="td-time"><?= date('d M, H:i', strtotime($r['created_at'])) ?></td>
                                <td>
                                    <select class="st-sel-police" onchange="updateStatus(<?= $r['id'] ?>,'police',this.value,this)">
                                        <option value="pending"    <?= $r['police_status'] === 'pending'    ? 'selected' : '' ?>>Pending</option>
                                        <option value="assigned"   <?= $r['police_status'] === 'assigned'   ? 'selected' : '' ?>>Assigned</option>
                                        <option value="dispatched" <?= $r['police_status'] === 'dispatched' ? 'selected' : '' ?>>Dispatched</option>
                                        <option value="arrived"    <?= $r['police_status'] === 'arrived'    ? 'selected' : '' ?>>Arrived</option>
                                        <option value="resolved"   <?= $r['police_status'] === 'resolved'   ? 'selected' : '' ?>>Resolved</option>
                                        <option value="false_alarm"<?= $r['police_status'] === 'false_alarm' ? 'selected' : '' ?>>False Alarm</option>
                                    </select>
                                    <select class="st-sel-hospital" onchange="updateStatus(<?= $r['id'] ?>,'hospital',this.value,this)">
                                        <option value="pending"              <?= $r['hospital_status'] === 'pending'              ? 'selected' : '' ?>>Pending</option>
                                        <option value="assigned"             <?= $r['hospital_status'] === 'assigned'             ? 'selected' : '' ?>>Assigned</option>
                                        <option value="preparing"            <?= $r['hospital_status'] === 'preparing'            ? 'selected' : '' ?>>Preparing</option>
                                        <option value="ambulance_dispatched" <?= $r['hospital_status'] === 'ambulance_dispatched' ? 'selected' : '' ?>>Ambulance</option>
                                        <option value="patient_received"     <?= $r['hospital_status'] === 'patient_received'     ? 'selected' : '' ?>>Received</option>
                                        <option value="treated"              <?= $r['hospital_status'] === 'treated'              ? 'selected' : '' ?>>Treated</option>
                                        <option value="closed"               <?= $r['hospital_status'] === 'closed'               ? 'selected' : '' ?>>Closed</option>
                                    </select>
                                </td>
                            </tr>
                            <?php endwhile; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

            </div><!-- /content -->

            <!-- ─── FOOTER ─── -->
            <div class="footer">
                <span>© 2026 RoadAlert TZ &nbsp;·&nbsp; System: Operational &nbsp;·&nbsp; Admin Dashboard</span>
                <span id="clock"></span>
            </div>

        </div><!-- /main -->
    </div><!-- /shell -->

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
                n.toLocaleDateString('en-GB', {weekday: 'short', day: '2-digit', month: 'short'}) +
                ' · ' + n.toLocaleTimeString('en-GB', {hour: '2-digit', minute: '2-digit', second: '2-digit'});
        }
        setInterval(tick, 1000);
        tick();

        // ─── CHARTS ─────────────────────────────────────
        Chart.defaults.font.family = "Space Grotesk";
        Chart.defaults.color = "#4E7090";

        new Chart(document.getElementById('weekChart'), {
            type: 'line',
            data: {
                labels: ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'],
                datasets: [{
                    label: 'Accidents',
                    data: <?= json_encode($week_data) ?>,
                    borderColor: '#00E5A0',
                    backgroundColor: 'rgba(0,229,160,0.07)',
                    fill: true,
                    tension: 0.42,
                    pointBackgroundColor: '#00E5A0',
                    pointRadius: 4,
                    pointHoverRadius: 6,
                    borderWidth: 2
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: {
                    x: { grid: { color: 'rgba(255,255,255,.04)' }, ticks: { font: { size: 10 } } },
                    y: { grid: { color: 'rgba(255,255,255,.04)' }, ticks: { font: { size: 10 }, stepSize: 1 }, min: 0 }
                }
            }
        });

        new Chart(document.getElementById('typeChart'), {
            type: 'doughnut',
            data: {
                labels: <?= json_encode($type_labels) ?>,
                datasets: [{
                    data: <?= json_encode($type_counts) ?>,
                    backgroundColor: ['#00E5A0', '#22D3EE', '#FB923C', '#F43F5E', '#A78BFA', '#FACC15'],
                    borderWidth: 0
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '68%',
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: { font: { size: 10 }, padding: 10, boxWidth: 9 }
                    }
                }
            }
        });

        // ─── LEAFLET MAP ──────────────────────────────
        function statusColor(s) {
            return s === 'pending' ? '#F43F5E' :
                   s === 'dispatched' ? '#22D3EE' :
                   s === 'arrived' ? '#FB923C' : '#00E5A0';
        }

        function makeIcon(s) {
            const c = statusColor(s);
            return L.divIcon({
                html: `<div style="width:16px;height:16px;border-radius:50%;background:${c};border:2.5px solid #fff;box-shadow:0 2px 8px rgba(0,0,0,.35)"></div>`,
                iconSize: [16, 16],
                iconAnchor: [8, 8],
                className: ''
            });
        }

        const center = MAP_DATA.length ? [MAP_DATA[0].lat, MAP_DATA[0].lng] : [-6.7924, 39.2083];
        const map = L.map('accidentMap').setView(center, MAP_DATA.length ? 12 : 11);

        L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}', {
            attribution: '&copy; <a href="https://www.esri.com">Esri</a>',
            maxZoom: 19,
            minZoom: 3
        }).addTo(map);

        L.tileLayer('https://services.arcgisonline.com/ArcGIS/rest/services/Reference/World_Boundaries_and_Places/MapServer/tile/{z}/{y}/{x}', {
            attribution: '&copy; <a href="https://www.esri.com">Esri</a>',
            maxZoom: 19,
            minZoom: 3
        }).addTo(map);

        L.control.scale({ metric: true, imperial: false }).addTo(map);

        if (MAP_DATA.length > 0) {
            MAP_DATA.forEach(a => {
                const isAssigned = a.assigned_police || a.assigned_hospital;
                const icon = makeIcon(a.status);
                const m = L.marker([a.lat, a.lng], { icon }).addTo(map);
                
                let assignedText = '';
                if (a.assigned_police && a.assigned_hospital) {
                    assignedText = '🚔🏥 Both assigned';
                } else if (a.assigned_police) {
                    assignedText = '🚔 Police assigned';
                } else if (a.assigned_hospital) {
                    assignedText = '🏥 Hospital assigned';
                }
                
                m.bindPopup(`
                    <div style="font-family:'Space Grotesk',sans-serif;min-width:210px;background:#1A237E;border-radius:12px;padding:10px;border:1px solid #E8C547;">
                        <div style="font-weight:700;font-size:.88rem;color:#E8C547;margin-bottom:4px">
                            🚨 #ACC-${String(a.id).padStart(4,'0')}
                        </div>
                        <div style="font-size:.76rem;color:#FFFFFF;font-weight:500;margin-bottom:3px">${a.type}</div>
                        <div style="font-size:.72rem;color:#94a3b8;margin-bottom:6px">${a.location}</div>
                        <div style="display:flex;gap:8px;font-size:.72rem;align-items:center;flex-wrap:wrap">
                            <span style="color:#FFFFFF;">🤕 ${a.injuries} injured</span>
                            <span style="color:${statusColor(a.status)};font-weight:700;text-transform:uppercase">🚔 ${a.status}</span>
                            <span style="color:#FB923C;font-weight:700;text-transform:uppercase">🏥 ${a.hospital_status}</span>
                        </div>
                        ${isAssigned ? `<div style="color:var(--yellow);font-size:.65rem;margin-top:3px;">🤖 ${assignedText}</div>` : ''}
                        <div style="font-size:.64rem;color:#94a3b8;margin-top:4px">${a.time}</div>
                        <hr style="border:none;border-top:1px solid #E8C547;margin:6px 0">
                        <button onclick="viewAccidentFromMap(${a.id})" style="background:#E8C547;color:#222;border:none;border-radius:6px;padding:4px 12px;font-size:11px;font-weight:700;cursor:pointer;width:100%;">
                            View Details →
                        </button>
                    </div>
                `);
                m.bindTooltip(`#ACC-${String(a.id).padStart(4,'0')}: ${a.type}`, { sticky: true });
            });

            if (MAP_DATA.length > 1) {
                map.fitBounds(L.latLngBounds(MAP_DATA.map(a => [a.lat, a.lng])), { padding: [40, 40] });
            }
        } else {
            L.marker([-6.7924, 39.2083], {
                icon: L.divIcon({
                    html: `<div style="background:#E8C547;color:#222;padding:12px 20px;border-radius:12px;font-weight:700;font-size:14px;box-shadow:0 4px 15px rgba(0,0,0,0.3);">
                            📍 No accident locations available
                          </div>`,
                    iconSize: [250, 40],
                    iconAnchor: [125, 20]
                })
            }).addTo(map);
        }

        function viewAccidentFromMap(id) {
            window.location.href = 'accident_report.php?view=' + id;
        }

        // ─── DUAL STATUS UPDATE ─────────────────────────
        function updateStatus(id, type, status, sel) {
            sel.disabled = true;
            fetch('update-status.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `accident_id=${id}&status_type=${type}&new_status=${encodeURIComponent(status)}`
            })
            .then(r => r.json())
            .then(d => {
                sel.disabled = false;
                if (d.success) {
                    const row = document.getElementById('row-' + id);
                    if (row) {
                        const badges = row.querySelectorAll('.badge');
                        if (type === 'police') {
                            const b = row.querySelectorAll('.badge')[0];
                            if (b) {
                                b.className = 'badge ' + status;
                                b.innerHTML = `<span class="badge-dot"></span>${status.charAt(0).toUpperCase() + status.slice(1).replace('_', ' ')}`;
                            }
                        } else {
                            const b = row.querySelectorAll('.badge')[1];
                            if (b) {
                                b.className = 'badge ' + status;
                                b.innerHTML = `<span class="badge-dot"></span>${status.charAt(0).toUpperCase() + status.slice(1).replace('_', ' ')}`;
                            }
                        }
                    }
                    showToast('Updated', `#ACC-${String(id).padStart(4,'0')} ${type} → ${status}`, 'ok');
                }
            })
            .catch(() => {
                sel.disabled = false;
                showToast('Error', 'Update failed', 'err');
            });
        }

        // ─── TABLE FILTER ───────────────────────────────
        function filterTable(q) {
            const rows = document.querySelectorAll('#tableBody tr');
            q = q.toLowerCase();
            rows.forEach(r => {
                r.style.display = r.textContent.toLowerCase().includes(q) ? '' : 'none';
            });
        }

        // ─── TOAST NOTIFICATIONS ────────────────────────
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

        // ─── MODAL ──────────────────────────────────────
        function openModal() {
            document.getElementById('profileModal').classList.add('show');
        }

        function closeModal() {
            document.getElementById('profileModal').classList.remove('show');
        }

        // ─── CLOSE ON OUTSIDE CLICK ─────────────────────
        document.addEventListener('click', function(e) {
            if (!e.target.closest('#notifDrop') && !e.target.closest('.notif-btn')) {
                document.getElementById('notifDrop').classList.remove('show');
            }
            if (!e.target.closest('#profileDrop') && !e.target.closest('#profileBtn')) {
                closeProfileDrop();
            }
            if (e.target === document.getElementById('profileModal')) {
                closeModal();
            }
        });

        // ─── POLL NEW REPORTS ──────────────────────────
        let lastId = <?= !empty($map_data) ? $map_data[0]['id'] : 0 ?>;
        setInterval(() => {
            fetch(`get-new-reports.php?last_id=${lastId}`)
                .then(r => r.json())
                .then(d => {
                    if (d.reports && d.reports.length) {
                        d.reports.forEach(a => {
                            lastId = Math.max(lastId, a.id);
                            showToast(`🚨 New Accident #ACC-${String(a.id).padStart(4,'0')}`,
                                `${a.accident_type || 'Accident'} · ${a.injuries} injured`, 'new');
                        });
                    }
                })
                .catch(() => {});
        }, 30000);

        // ─── POLL AUTO-ASSIGNMENTS ──────────────────────
        setInterval(() => {
            fetch('check_auto_assign.php')
                .then(r => r.json())
                .then(d => {
                    if (d.new_auto_assign && d.new_auto_assign > 0) {
                        showToast('🤖 New Auto-Assigned', 
                            d.new_auto_assign + ' accident(s) auto-assigned to nearest responders', 
                            'new'
                        );
                        setTimeout(() => location.reload(), 3000);
                    }
                })
                .catch(() => {});
        }, 15000);
    </script>
  
</body>
</html>