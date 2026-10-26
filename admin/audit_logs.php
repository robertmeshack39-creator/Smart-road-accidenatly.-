<?php
/**
 * ADMIN AUDIT LOGS
 * RoadAlert TZ - View system activity logs with real data
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
// 👤 ADMIN INFO
// ============================================
$admin_name = htmlspecialchars($_SESSION['name'] ?? $_SESSION['full_name'] ?? 'System Admin');
$admin_email = htmlspecialchars($_SESSION['email'] ?? 'admin@roadalert.tz');
$admin_role = htmlspecialchars($_SESSION['role'] ?? 'admin');
$admin_init = strtoupper(substr($admin_name, 0, 1));

// ============================================
// 📊 GET COUNTS FOR BADGES - FIXED
// ============================================
$pending_q = $conn->query("
    SELECT COUNT(*) as c 
    FROM accidents 
    WHERE police_status = 'pending' OR hospital_status = 'pending'
");
$pending_row = $pending_q ? $pending_q->fetch_assoc() : null;
$pending_count = intval($pending_row['c'] ?? 0);

// ─── 🔴 POLICE RESOLVED ───
$police_resolved = $conn->query("
    SELECT COUNT(*) as c 
    FROM accidents 
    WHERE police_status = 'resolved'
")->fetch_assoc()['c'] ?? 0;

// ─── 🔴 HOSPITAL TREATED ───
$hospital_treated = $conn->query("
    SELECT COUNT(*) as c 
    FROM accidents 
    WHERE hospital_status = 'treated'
")->fetch_assoc()['c'] ?? 0;

// ─── 🔴 TOTAL RESOLVED = Police Resolved + Hospital Treated ───
$resolved_count = $police_resolved + $hospital_treated;

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
// 📊 FETCH AUDIT STATISTICS
// ============================================
$stats = $conn->query("
    SELECT 
        COUNT(*) as total_logs,
        COUNT(DISTINCT user_id) as unique_users,
        COUNT(DISTINCT DATE(created_at)) as active_days,
        SUM(CASE WHEN action LIKE '%login%' THEN 1 ELSE 0 END) as login_actions,
        SUM(CASE WHEN action LIKE '%delete%' OR action LIKE '%update%' OR action LIKE '%insert%' OR action LIKE '%create%' THEN 1 ELSE 0 END) as data_changes
    FROM audit_logs
")->fetch_assoc();

// ============================================
// 🔍 GET FILTERS
// ============================================
$search = isset($_GET['search']) ? mysqli_real_escape_string($conn, $_GET['search']) : '';
$action_filter = isset($_GET['action']) ? mysqli_real_escape_string($conn, $_GET['action']) : '';
$date_filter = isset($_GET['date']) ? mysqli_real_escape_string($conn, $_GET['date']) : '';
$user_filter = isset($_GET['user']) ? intval($_GET['user']) : 0;

// ============================================
// 📋 BUILD QUERY
// ============================================
$where_conditions = [];
if (!empty($search)) {
    $where_conditions[] = "(username LIKE '%$search%' OR action LIKE '%$search%' OR page LIKE '%$search%' OR ip_address LIKE '%$search%')";
}
if (!empty($action_filter)) {
    $where_conditions[] = "action LIKE '%$action_filter%'";
}
if (!empty($date_filter)) {
    $where_conditions[] = "DATE(created_at) = '$date_filter'";
}
if ($user_filter > 0) {
    $where_conditions[] = "user_id = $user_filter";
}

$where_clause = !empty($where_conditions) ? "WHERE " . implode(" AND ", $where_conditions) : "";

// ============================================
// 📋 FETCH LOGS
// ============================================
$logs = $conn->query("
    SELECT * FROM audit_logs 
    $where_clause
    ORDER BY created_at DESC 
    LIMIT 200
");

// ============================================
// 📋 FETCH USERS FOR FILTER
// ============================================
// 🔴 Column name is 'full_name' in users table
$users = $conn->query("SELECT id, full_name as username, role FROM users ORDER BY full_name");

// If query fails, try fallback
if (!$users) {
    // Fallback - try to get any name column
    $columns = $conn->query("SHOW COLUMNS FROM users");
    $name_col = 'id';
    while ($col = $columns->fetch_assoc()) {
        if (in_array($col['Field'], ['full_name', 'username', 'name', 'user_name'])) {
            $name_col = $col['Field'];
            break;
        }
    }
    $users = $conn->query("SELECT id, $name_col as username, role FROM users ORDER BY $name_col");
}

// ============================================
// 🎨 GET ACTION COLOR & ICON
// ============================================
function getActionColor($action) {
    $colors = [
        'login' => '#22D3EE',
        'logout' => '#F43F5E',
        'create' => '#00E5A0',
        'insert' => '#00E5A0',
        'update' => '#FB923C',
        'edit' => '#FB923C',
        'delete' => '#F43F5E',
        'remove' => '#F43F5E',
        'assign' => '#A78BFA',
        'auto_assign' => '#A78BFA',
        'export' => '#FACC15',
        'view' => '#22D3EE',
        'approve' => '#00E5A0',
        'reject' => '#F43F5E',
        'change' => '#FB923C',
        'reset' => '#FB923C'
    ];
    
    foreach ($colors as $key => $color) {
        if (strpos(strtolower($action), $key) !== false) {
            return $color;
        }
    }
    return '#4E7090';
}

function getActionIcon($action) {
    $icons = [
        'login' => 'fa-sign-in-alt',
        'logout' => 'fa-sign-out-alt',
        'create' => 'fa-plus-circle',
        'insert' => 'fa-plus-circle',
        'update' => 'fa-edit',
        'edit' => 'fa-edit',
        'delete' => 'fa-trash',
        'remove' => 'fa-trash',
        'assign' => 'fa-user-cog',
        'auto_assign' => 'fa-magic',
        'export' => 'fa-file-export',
        'view' => 'fa-eye',
        'approve' => 'fa-check-circle',
        'reject' => 'fa-times-circle',
        'change' => 'fa-exchange-alt',
        'reset' => 'fa-undo'
    ];
    
    foreach ($icons as $key => $icon) {
        if (strpos(strtolower($action), $key) !== false) {
            return $icon;
        }
    }
    return 'fa-clock';
}

function getBadgeClass($action) {
    if (strpos(strtolower($action), 'login') !== false) return 'info';
    if (strpos(strtolower($action), 'logout') !== false) return 'danger';
    if (strpos(strtolower($action), 'create') !== false) return 'success';
    if (strpos(strtolower($action), 'insert') !== false) return 'success';
    if (strpos(strtolower($action), 'update') !== false) return 'warning';
    if (strpos(strtolower($action), 'edit') !== false) return 'warning';
    if (strpos(strtolower($action), 'delete') !== false) return 'danger';
    if (strpos(strtolower($action), 'remove') !== false) return 'danger';
    if (strpos(strtolower($action), 'assign') !== false) return 'info';
    if (strpos(strtolower($action), 'approve') !== false) return 'success';
    if (strpos(strtolower($action), 'reject') !== false) return 'danger';
    return 'info';
}

function getUserRoleClass($role) {
    $roles = [
        'admin' => 'admin',
        'police' => 'police',
        'hospital' => 'hospital',
        'citizen' => 'citizen'
    ];
    return $roles[strtolower($role)] ?? 'guest';
}

function getRoleIcon($role) {
    $icons = [
        'admin' => 'fa-user-shield',
        'police' => 'fa-user-shield',
        'hospital' => 'fa-user-md',
        'citizen' => 'fa-user'
    ];
    return $icons[strtolower($role)] ?? 'fa-user';
}

// ============================================
// 📋 GET UNIQUE ACTIONS FOR FILTER
// ============================================
$actions = $conn->query("
    SELECT DISTINCT 
        CASE 
            WHEN action LIKE '%login%' THEN 'login'
            WHEN action LIKE '%logout%' THEN 'logout'
            WHEN action LIKE '%create%' OR action LIKE '%insert%' THEN 'create'
            WHEN action LIKE '%update%' OR action LIKE '%edit%' THEN 'update'
            WHEN action LIKE '%delete%' OR action LIKE '%remove%' THEN 'delete'
            WHEN action LIKE '%assign%' THEN 'assign'
            WHEN action LIKE '%approve%' THEN 'approve'
            WHEN action LIKE '%reject%' THEN 'reject'
            ELSE action
        END as action_group
    FROM audit_logs 
    WHERE action IS NOT NULL AND action != ''
    ORDER BY action_group
");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Audit Logs — RoadAlert TZ</title>
    
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=Poppins:wght@300;400;500;600;700;800&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet"/>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"/>
    
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
            font-family: 'Inter', sans-serif;
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
        
        .shell {
            display: flex;
            height: 100vh;
            overflow: hidden;
        }
        
        /* ============================================
           SIDEBAR
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
        }
        
        .sb-title {
            font-family: 'Poppins', sans-serif;
            font-size: 1.15rem;
            font-weight: 800;
            color: var(--white);
        }
        
        .sb-sub {
            font-size: 0.58rem;
            color: var(--muted);
            letter-spacing: 0.14em;
            text-transform: uppercase;
            font-family: 'JetBrains Mono', monospace;
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
            font-family: 'Inter', sans-serif;
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
            font-family: 'Inter', sans-serif;
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
           MAIN CONTENT
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
            font-family: 'Poppins', sans-serif;
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
            font-family: 'Inter', sans-serif;
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
        
        /* ─── Notification Styles ─── */
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
        
        /* ─── Profile Styles ─── */
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
            font-family: 'JetBrains Mono', monospace;
        }
        .pd-pill {
            display: inline-flex;
            align-items: center;
            gap: 0.28rem;
            background: var(--acc-lo);
            border: 1px solid rgba(0, 229, 160, 0.22);
            border-radius: 999px;
            padding: 0.18rem 0.6rem;
            font-size: 0.6rem;
            color: var(--accent);
            font-family: 'JetBrains Mono', monospace;
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
            font-family: 'Inter', sans-serif;
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
            font-family: 'Inter', sans-serif;
            text-decoration: none;
        }
        .tb-btn:hover {
            background: rgba(244, 63, 94, 0.08);
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
            font-family: 'Poppins', sans-serif;
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
            font-family: 'Inter', sans-serif;
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
            font-family: 'Inter', sans-serif;
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
           CONTENT
           ============================================ */
        .content {
            flex: 1;
            overflow-y: auto;
            padding: 1.4rem 1.5rem;
        }
        
        /* ─── Stats Row ─── */
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
        .sc-ico.blue { background: rgba(34, 211, 238, 0.1); color: var(--sky); }
        .sc-ico.green { background: rgba(0, 229, 160, 0.1); color: var(--accent); }
        .sc-ico.orange { background: rgba(251, 146, 60, 0.1); color: var(--amber); }
        .sc-ico.purple { background: rgba(167, 139, 250, 0.1); color: var(--purple); }
        .sc-ico.red { background: rgba(244, 63, 94, 0.1); color: var(--red); }
        
        .sc-num {
            font-family: 'Poppins', sans-serif;
            font-size: 2.2rem;
            font-weight: 800;
            line-height: 1;
            margin-bottom: 0.22rem;
        }
        .sc-num.blue { color: var(--sky); }
        .sc-num.green { color: var(--accent); }
        .sc-num.orange { color: var(--amber); }
        .sc-num.purple { color: var(--purple); }
        .sc-num.red { color: var(--red); }
        
        .sc-lbl {
            font-size: 0.67rem;
            color: var(--muted);
            letter-spacing: 0.09em;
            text-transform: uppercase;
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
        
        .filter-bar input,
        .filter-bar select {
            background: var(--bg4);
            border: 1px solid var(--border);
            border-radius: 8px;
            padding: 0.4rem 0.7rem;
            font-family: 'Inter', sans-serif;
            font-size: 0.75rem;
            color: var(--light);
            outline: none;
            transition: border-color 0.18s;
        }
        .filter-bar input:focus,
        .filter-bar select:focus {
            border-color: var(--accent);
        }
        .filter-bar input::placeholder {
            color: var(--muted);
        }
        
        .filter-bar .btn-filter {
            padding: 0.4rem 1rem;
            background: var(--accent);
            color: #07111B;
            border: none;
            border-radius: 8px;
            font-size: 0.75rem;
            font-weight: 600;
            cursor: pointer;
            font-family: 'Inter', sans-serif;
            transition: all 0.18s;
        }
        .filter-bar .btn-filter:hover {
            background: var(--accent2);
        }
        
        .filter-bar .btn-reset {
            padding: 0.4rem 1rem;
            background: rgba(255, 255, 255, 0.05);
            color: var(--muted2);
            border: 1px solid var(--border);
            border-radius: 8px;
            font-size: 0.75rem;
            cursor: pointer;
            font-family: 'Inter', sans-serif;
            transition: all 0.18s;
        }
        .filter-bar .btn-reset:hover {
            background: rgba(255, 255, 255, 0.08);
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
            font-family: 'Poppins', sans-serif;
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
        
        .td-mono {
            font-family: 'JetBrains Mono', monospace;
            font-size: 0.7rem;
            color: var(--muted2);
        }
        .td-time {
            font-size: 0.66rem;
            color: var(--muted);
            white-space: nowrap;
        }
        .td-desc {
            max-width: 250px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            color: var(--muted2);
            font-size: 0.72rem;
        }
        
        /* ─── Badges ─── */
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
        .badge.info { background: rgba(34, 211, 238, 0.15); color: var(--sky); }
        .badge.success { background: rgba(0, 229, 160, 0.15); color: var(--accent); }
        .badge.warning { background: rgba(251, 146, 60, 0.15); color: var(--amber); }
        .badge.danger { background: rgba(244, 63, 94, 0.15); color: var(--red); }
        .badge.purple { background: rgba(167, 139, 250, 0.15); color: var(--purple); }
        .badge-dot {
            width: 5px;
            height: 5px;
            border-radius: 50%;
            background: currentColor;
        }
        
        /* ─── User Role Badge ─── */
        .user-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.3rem;
            padding: 0.15rem 0.5rem;
            border-radius: 999px;
            font-size: 0.65rem;
            font-weight: 500;
            white-space: nowrap;
        }
        .user-badge.admin {
            background: rgba(0, 229, 160, 0.1);
            color: var(--accent);
            border: 1px solid rgba(0, 229, 160, 0.2);
        }
        .user-badge.police {
            background: rgba(34, 211, 238, 0.1);
            color: var(--sky);
            border: 1px solid rgba(34, 211, 238, 0.2);
        }
        .user-badge.hospital {
            background: rgba(167, 139, 250, 0.1);
            color: var(--purple);
            border: 1px solid rgba(167, 139, 250, 0.2);
        }
        .user-badge.citizen {
            background: rgba(251, 146, 60, 0.1);
            color: var(--amber);
            border: 1px solid rgba(251, 146, 60, 0.2);
        }
        .user-badge.guest {
            background: rgba(148, 163, 184, 0.1);
            color: var(--muted2);
            border: 1px solid rgba(148, 163, 184, 0.2);
        }
        
        /* ─── Empty State ─── */
        .empty-state {
            text-align: center;
            padding: 3rem;
            color: var(--muted);
        }
        .empty-state i {
            font-size: 3rem;
            opacity: 0.3;
            margin-bottom: 1rem;
        }
        .empty-state p {
            font-size: 0.9rem;
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
        .t-ic { font-size: 0.95rem; flex-shrink: 0; }
        .t-body { flex: 1; }
        .t-title { font-weight: 700; font-size: 0.78rem; color: var(--accent); margin-bottom: 0.15rem; }
        .t-msg { font-size: 0.7rem; color: var(--muted2); line-height: 1.4; }
        .t-x { background: none; border: none; color: var(--muted); cursor: pointer; font-size: 0.76rem; }
        
        /* ─── Responsive ─── */
        @media (max-width: 1100px) {
            .stats-row {
                grid-template-columns: repeat(3, 1fr);
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

        <!-- SIDEBAR -->
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
                <a href="audit_logs.php" class="sb-item active">
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

        <!-- MAIN CONTENT -->
        <div class="main">

            <!-- TOPBAR -->
            <div class="topbar">
                <div class="tb-title">
                    <i class="fas fa-clipboard-list"></i>Audit Logs
                </div>

                <div class="tb-search">
                    <i class="fas fa-search"></i>
                    <input type="text" placeholder="Search audit logs…" oninput="filterTable(this.value)"/>
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

            <!-- CONTENT -->
            <div class="content">

                <!-- STAT CARDS -->
                <div class="stats-row">
                    <div class="sc">
                        <div class="sc-ico blue"><i class="fas fa-list"></i></div>
                        <div class="sc-num blue"><?= number_format($stats['total_logs'] ?? 0) ?></div>
                        <div class="sc-lbl">Total Activities</div>
                    </div>
                    <div class="sc">
                        <div class="sc-ico purple"><i class="fas fa-users"></i></div>
                        <div class="sc-num purple"><?= number_format($stats['unique_users'] ?? 0) ?></div>
                        <div class="sc-lbl">Active Users</div>
                    </div>
                    <div class="sc">
                        <div class="sc-ico green"><i class="fas fa-calendar-day"></i></div>
                        <div class="sc-num green"><?= number_format($stats['active_days'] ?? 0) ?></div>
                        <div class="sc-lbl">Active Days</div>
                    </div>
                    <div class="sc">
                        <div class="sc-ico orange"><i class="fas fa-sign-in-alt"></i></div>
                        <div class="sc-num orange"><?= number_format($stats['login_actions'] ?? 0) ?></div>
                        <div class="sc-lbl">Logins</div>
                    </div>
                    <div class="sc">
                        <div class="sc-ico red"><i class="fas fa-pen"></i></div>
                        <div class="sc-num red"><?= number_format($stats['data_changes'] ?? 0) ?></div>
                        <div class="sc-lbl">Data Changes</div>
                    </div>
                </div>

                <!-- FILTER BAR -->
                <div class="filter-bar">
                    <form method="GET" style="display:flex;flex-wrap:wrap;gap:0.5rem;width:100%;align-items:center;">
                        <input type="text" name="search" placeholder="Search logs..." value="<?= htmlspecialchars($search) ?>" style="flex:1;min-width:150px;">
                        
                        <select name="action">
                            <option value="">All Actions</option>
                            <?php while ($act = $actions->fetch_assoc()): ?>
                                <option value="<?= htmlspecialchars($act['action_group']) ?>" <?= $action_filter == $act['action_group'] ? 'selected' : '' ?>>
                                    <?= ucfirst($act['action_group']) ?>
                                </option>
                            <?php endwhile; ?>
                        </select>
                        
                        <select name="user">
                            <option value="0">All Users</option>
                            <?php 
                            if ($users && $users->num_rows > 0) {
                                while ($u = $users->fetch_assoc()): ?>
                                    <option value="<?= $u['id'] ?>" <?= $user_filter == $u['id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($u['username']) ?> (<?= ucfirst($u['role']) ?>)
                                    </option>
                                <?php endwhile; 
                            } ?>
                        </select>
                        
                        <input type="date" name="date" value="<?= htmlspecialchars($date_filter) ?>" style="max-width:150px;">
                        
                        <button type="submit" class="btn-filter">
                            <i class="fas fa-filter"></i> Filter
                        </button>
                        <a href="audit_logs.php" class="btn-reset">
                            <i class="fas fa-times"></i> Reset
                        </a>
                    </form>
                </div>

                <!-- TABLE -->
                <div class="panel">
                    <div class="ph">
                        <div>
                            <div class="ph-t"><i class="fas fa-history"></i>Activity Log</div>
                            <div class="ph-s"><?= $logs->num_rows ?> records found</div>
                        </div>
                        <div style="display:flex;gap:.6rem;align-items:center;">
                            <span style="font-size:.6rem;color:var(--muted);">
                                <i class="fas fa-sync-alt"></i> Real-time
                            </span>
                        </div>
                    </div>

                    <div class="tbl-wrap">
                        <table>
                            <thead>
                                <tr>
                                    <th>#ID</th>
                                    <th>User</th>
                                    <th>Action</th>
                                    <th>Details</th>
                                    <th>IP Address</th>
                                    <th>Time</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php if ($logs && $logs->num_rows > 0): ?>
                            <?php while ($log = $logs->fetch_assoc()): 
                                $color = getActionColor($log['action']);
                                $icon = getActionIcon($log['action']);
                                $badge_class = getBadgeClass($log['action']);
                                $role_class = getUserRoleClass($log['role']);
                                $role_icon = getRoleIcon($log['role']);
                            ?>
                            <tr>
                                <td class="td-mono">#<?= $log['id'] ?></td>
                                <td style="font-weight:500;">
                                    <?= htmlspecialchars($log['username'] ?? 'System') ?>
                                    <span class="user-badge <?= $role_class ?>" style="display:inline-flex;font-size:0.5rem;padding:0.1rem 0.4rem;">
                                        <i class="fas <?= $role_icon ?>"></i> <?= ucfirst($log['role'] ?? 'Guest') ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="badge <?= $badge_class ?>" style="border-color:<?= $color ?>40;">
                                        <i class="fas <?= $icon ?>"></i> 
                                        <?php 
                                        $display_action = $log['action'];
                                        if (strpos($log['action'], '|') !== false) {
                                            $display_action = trim(explode('|', $log['action'])[0]);
                                        }
                                        echo ucfirst(str_replace('_', ' ', $display_action));
                                        ?>
                                    </span>
                                </td>
                                <td class="td-desc" title="<?= htmlspecialchars($log['action']) ?>">
                                    <?= htmlspecialchars(substr($log['action'], 0, 80)) ?>
                                    <?= strlen($log['action'] ?? '') > 80 ? '...' : '' ?>
                                </td>
                                <td style="font-size:0.65rem;color:var(--muted);font-family:'JetBrains Mono',monospace;">
                                    <?= htmlspecialchars($log['ip_address'] ?? '—') ?>
                                </td>
                                <td class="td-time">
                                    <?= date('d M, H:i:s', strtotime($log['created_at'])) ?>
                                </td>
                            </tr>
                            <?php endwhile; ?>
                            <?php else: ?>
                            <tr>
                                <td colspan="6">
                                    <div class="empty-state">
                                        <i class="fas fa-inbox"></i>
                                        <p>No audit logs found</p>
                                        <p style="font-size:0.7rem;color:var(--muted2);">Activities will appear here as users interact with the system</p>
                                    </div>
                                </td>
                            </tr>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

            </div><!-- /content -->

            <!-- FOOTER -->
            <div class="footer">
                <span>© 2026 RoadAlert TZ &nbsp;·&nbsp; Audit Trail &nbsp;·&nbsp; All activities are logged</span>
                <span id="clock"></span>
            </div>

        </div><!-- /main -->
    </div><!-- /shell -->

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

        // ─── TABLE FILTER ───────────────────────────────
        function filterTable(q) {
            const rows = document.querySelectorAll('tbody tr');
            q = q.toLowerCase();
            rows.forEach(r => {
                r.style.display = r.textContent.toLowerCase().includes(q) ? '' : 'none';
            });
        }

        // ─── PROFILE MODAL ─────────────────────────────
        function openProfileModal() {
            document.getElementById('profileModal').classList.add('show');
            closeProfileDrop();
        }

        function closeProfileModal() {
            document.getElementById('profileModal').classList.remove('show');
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

        // ─── CLOSE ON OUTSIDE CLICK ─────────────────────
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

        // ─── ESCAPE KEY ──────────────────────────────────
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeProfileModal();
            }
        });
    </script>
</body>
</html>