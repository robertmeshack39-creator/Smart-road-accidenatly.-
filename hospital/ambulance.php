<?php
// hospital/ambulance.php - Ambulance Dispatch .
session_start();
include __DIR__ . '/../connection.php';

// Check if user is logged in and is hospital
if (empty($_SESSION['user_id']) || empty($_SESSION['role']) || strtolower($_SESSION['role']) !== 'hospital') {
    header("Location: ../login.php");
    exit();
}

// Helper function for active page
function is_active($page) {
    return basename($_SERVER['PHP_SELF']) === $page ? 'active' : '';
}

// Helper function to get accident image
function getAccidentImage($row) {
    if (!empty($row['images'])) {
        $images = json_decode($row['images'], true);
        if (is_array($images) && count($images) > 0) {
            return ltrim($images[0], '/');
        }
    }
    if (!empty($row['photo_path'])) {
        return ltrim($row['photo_path'], '/');
    }
    return null;
}

// Get hospital user details
$hospital_id = $_SESSION['user_id'];
$hospital_name = htmlspecialchars($_SESSION['name'] ?? 'Hospital Staff');
$hospital_init = strtoupper(substr($hospital_name, 0, 1));

// Get user's hospital station
$user_query = $conn->query("
    SELECT u.*, hs.id as station_id, hs.name as station_name, hs.location, 
           hs.latitude, hs.longitude, hs.phone as station_phone
    FROM users u
    LEFT JOIN hospital_stations hs ON u.hospital_station_id = hs.id
    WHERE u.id = $hospital_id
");
$user = $user_query->fetch_assoc();
$hospital_station = $user['station_name'] ?? 'Hospital Station';
$hospital_station_id = $user['station_id'] ?? null;
$hospital_lat = $user['latitude'] ?? null;
$hospital_lng = $user['longitude'] ?? null;

// ============================================
// GET CLOSED COUNT FOR SIDEBAR BADGE
// ============================================
$closed_q = $conn->query("
    SELECT COUNT(*) as c 
    FROM accidents 
    WHERE assigned_hospital_id = $hospital_station_id 
    AND hospital_status = 'closed'
");
$closed_row = $closed_q ? $closed_q->fetch_assoc() : null;
$closed_count = intval($closed_row['c'] ?? 0);

// ============================================
// 🚑 AMBULANCE MANAGEMENT - HANDLE POST REQUESTS
// ============================================

// ADD NEW AMBULANCE
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_ambulance'])) {
    $ambulance_name = mysqli_real_escape_string($conn, $_POST['ambulance_name']);
    $plate_number = mysqli_real_escape_string($conn, $_POST['plate_number']);
    $driver_name = mysqli_real_escape_string($conn, $_POST['driver_name']);
    $driver_phone = mysqli_real_escape_string($conn, $_POST['driver_phone']);
    $driver_license = mysqli_real_escape_string($conn, $_POST['driver_license']);
    $model = mysqli_real_escape_string($conn, $_POST['model']);
    $type = mysqli_real_escape_string($conn, $_POST['type']);
    $capacity = intval($_POST['capacity']);
    $status = 'active';
    
    $insert = $conn->query("
        INSERT INTO ambulances 
        (hospital_station_id, ambulance_name, plate_number, driver_name, driver_phone, driver_license, model, type, capacity, status) 
        VALUES ($hospital_station_id, '$ambulance_name', '$plate_number', '$driver_name', '$driver_phone', '$driver_license', '$model', '$type', $capacity, '$status')
    ");
    
    if ($insert) {
        // 🔴 AUDIT LOG - ADD AMBULANCE
        createAuditLog($conn, 'Added new ambulance | Ambulance: ' . $ambulance_name . ' | Plate: ' . $plate_number . ' | Driver: ' . $driver_name);
        
        header("Location: ambulance.php?msg=ambulance_added");
        exit();
    } else {
        $error = "Failed to add ambulance: " . $conn->error;
    }
}

// UPDATE AMBULANCE
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_ambulance'])) {
    $ambulance_id = intval($_POST['ambulance_id']);
    $ambulance_name = mysqli_real_escape_string($conn, $_POST['ambulance_name']);
    $plate_number = mysqli_real_escape_string($conn, $_POST['plate_number']);
    $driver_name = mysqli_real_escape_string($conn, $_POST['driver_name']);
    $driver_phone = mysqli_real_escape_string($conn, $_POST['driver_phone']);
    $driver_license = mysqli_real_escape_string($conn, $_POST['driver_license']);
    $model = mysqli_real_escape_string($conn, $_POST['model']);
    $type = mysqli_real_escape_string($conn, $_POST['type']);
    $capacity = intval($_POST['capacity']);
    
    // Get old ambulance data for audit log
    $old_data = $conn->query("SELECT ambulance_name, plate_number FROM ambulances WHERE id = $ambulance_id AND hospital_station_id = $hospital_station_id")->fetch_assoc();
    
    $update = $conn->query("
        UPDATE ambulances SET 
            ambulance_name = '$ambulance_name',
            plate_number = '$plate_number',
            driver_name = '$driver_name',
            driver_phone = '$driver_phone',
            driver_license = '$driver_license',
            model = '$model',
            type = '$type',
            capacity = $capacity
        WHERE id = $ambulance_id AND hospital_station_id = $hospital_station_id
    ");
    
    if ($update) {
        // 🔴 AUDIT LOG - UPDATE AMBULANCE
        createAuditLog($conn, 'Updated ambulance | Ambulance: ' . $old_data['ambulance_name'] . ' → ' . $ambulance_name . ' | Plate: ' . $old_data['plate_number'] . ' → ' . $plate_number);
        
        header("Location: ambulance.php?msg=ambulance_updated");
        exit();
    } else {
        $error = "Failed to update ambulance: " . $conn->error;
    }
}

// UPDATE AMBULANCE STATUS (Active/Inactive/Maintenance)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_ambulance_status'])) {
    $ambulance_id = intval($_POST['ambulance_id']);
    $status = mysqli_real_escape_string($conn, $_POST['status']);
    $allowed = ['active', 'inactive', 'maintenance'];
    
    if (in_array($status, $allowed)) {
        // Get old status for audit log
        $old_data = $conn->query("SELECT ambulance_name, status FROM ambulances WHERE id = $ambulance_id AND hospital_station_id = $hospital_station_id")->fetch_assoc();
        
        $result = $conn->query("UPDATE ambulances SET status = '$status' WHERE id = $ambulance_id AND hospital_station_id = $hospital_station_id");
        if ($result) {
            // 🔴 AUDIT LOG - UPDATE AMBULANCE STATUS
            createAuditLog($conn, 'Updated ambulance status | Ambulance: ' . $old_data['ambulance_name'] . ' | Status: ' . $old_data['status'] . ' → ' . $status);
            
            header("Location: ambulance.php?msg=status_updated");
            exit();
        } else {
            $error = "Failed to update status: " . $conn->error;
        }
    }
}

// ASSIGN AMBULANCE TO ACCIDENT
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['assign_ambulance'])) {
    $ambulance_id = intval($_POST['ambulance_id']);
    $accident_id = intval($_POST['accident_id']);
    
    // Check if ambulance is already on a mission
    $check = $conn->query("
        SELECT id FROM ambulance_assignments 
        WHERE ambulance_id = $ambulance_id AND status IN ('on_mission', 'arrived')
    ");
    
    if ($check->num_rows > 0) {
        $error = "This ambulance is already on a mission!";
    } else {
        // Check if accident already has an ambulance assigned
        $check_acc = $conn->query("
            SELECT id FROM ambulance_assignments 
            WHERE accident_id = $accident_id AND status IN ('on_mission', 'arrived')
        ");
        
        if ($check_acc->num_rows > 0) {
            $error = "This accident already has an ambulance assigned!";
        } else {
            // Get ambulance details for audit log
            $amb_data = $conn->query("SELECT ambulance_name, driver_name FROM ambulances WHERE id = $ambulance_id")->fetch_assoc();
            $acc_data = $conn->query("SELECT accident_type FROM accidents WHERE id = $accident_id")->fetch_assoc();
            
            $insert = $conn->query("
                INSERT INTO ambulance_assignments 
                (ambulance_id, accident_id, hospital_station_id, status, assigned_at) 
                VALUES ($ambulance_id, $accident_id, $hospital_station_id, 'on_mission', NOW())
            ");
            
            if ($insert) {
                // Update accident hospital_status to 'ambulance_dispatched'
                $conn->query("UPDATE accidents SET hospital_status = 'ambulance_dispatched' WHERE id = $accident_id");
                
                // 🔴 AUDIT LOG - ASSIGN AMBULANCE
                createAuditLog($conn, 'Assigned ambulance to accident | Ambulance: ' . $amb_data['ambulance_name'] . ' | Driver: ' . $amb_data['driver_name'] . ' | ACC-' . str_pad($accident_id, 4, '0', STR_PAD_LEFT) . ' | Type: ' . $acc_data['accident_type']);
                
                header("Location: ambulance.php?msg=assigned");
                exit();
            } else {
                $error = "Failed to assign ambulance: " . $conn->error;
            }
        }
    }
}

// UPDATE ASSIGNMENT STATUS
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_assignment'])) {
    $assignment_id = intval($_POST['assignment_id']);
    $status = mysqli_real_escape_string($conn, $_POST['assignment_status']);
    $allowed = ['on_mission', 'arrived', 'returned'];
    
    if (in_array($status, $allowed)) {
        // Get assignment details for audit log
        $assign_data = $conn->query("
            SELECT aa.accident_id, aa.status as old_status, a.ambulance_name 
            FROM ambulance_assignments aa
            JOIN ambulances a ON aa.ambulance_id = a.id
            WHERE aa.id = $assignment_id
        ")->fetch_assoc();
        
        // Update assignment status
        $conn->query("UPDATE ambulance_assignments SET status = '$status' WHERE id = $assignment_id");
        
        $accident_id = $assign_data['accident_id'];
        
        // LOGIC: Update accident status based on assignment status
        if ($status === 'arrived') {
            // Ambulance arrived at scene - No change to accident status
        } elseif ($status === 'returned') {
            // Ambulance returned with patient → Patient received at hospital
            $conn->query("UPDATE accidents SET hospital_status = 'patient_received' WHERE id = $accident_id");
        }
        
        // 🔴 AUDIT LOG - UPDATE ASSIGNMENT STATUS
        createAuditLog($conn, 'Updated ambulance mission status | Ambulance: ' . $assign_data['ambulance_name'] . ' | ACC-' . str_pad($accident_id, 4, '0', STR_PAD_LEFT) . ' | Status: ' . $assign_data['old_status'] . ' → ' . $status);
        
        header("Location: ambulance.php?msg=assignment_updated");
        exit();
    }
}

// DELETE AMBULANCE
if (isset($_GET['delete_ambulance'])) {
    $ambulance_id = intval($_GET['delete_ambulance']);
    
    // Get ambulance details for audit log
    $amb_data = $conn->query("SELECT ambulance_name, plate_number FROM ambulances WHERE id = $ambulance_id AND hospital_station_id = $hospital_station_id")->fetch_assoc();
    
    // Check if ambulance has active assignments
    $check = $conn->query("
        SELECT id FROM ambulance_assignments 
        WHERE ambulance_id = $ambulance_id AND status IN ('on_mission', 'arrived')
    ");
    
    if ($check->num_rows > 0) {
        header("Location: ambulance.php?msg=cannot_delete");
        exit();
    } else {
        $conn->query("DELETE FROM ambulances WHERE id = $ambulance_id AND hospital_station_id = $hospital_station_id");
        
        // 🔴 AUDIT LOG - DELETE AMBULANCE
        createAuditLog($conn, 'Deleted ambulance | Ambulance: ' . $amb_data['ambulance_name'] . ' | Plate: ' . $amb_data['plate_number']);
        
        header("Location: ambulance.php?msg=deleted");
        exit();
    }
}

// ============================================
// GET ACCIDENT ID FROM URL
// ============================================
$accident_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
$selected_accident = null;

if ($accident_id > 0) {
    $acc_query = $conn->query("
        SELECT a.*, ps.name as police_station_name, hs.name as hospital_station_name,
               a.hospital_status as status
        FROM accidents a
        LEFT JOIN police_stations ps ON a.assigned_police_id = ps.id
        LEFT JOIN hospital_stations hs ON a.assigned_hospital_id = hs.id
        WHERE a.id = $accident_id 
        AND a.assigned_hospital_id = $hospital_station_id
    ");
    $selected_accident = $acc_query->fetch_assoc();
}

// ============================================
// GET ALL ACCIDENTS FOR MAP
// ============================================
$accidents_query = $conn->query("
    SELECT id, accident_type, location_description, hospital_status as status, 
           created_at, reporter_name, injuries, latitude, longitude, assigned_hospital_id
    FROM accidents 
    WHERE latitude IS NOT NULL AND longitude IS NOT NULL
    AND assigned_hospital_id = $hospital_station_id
    ORDER BY created_at DESC LIMIT 100
");

// OPTIMIZED: Get all assignments in one query to avoid N+1
$assignments_map = [];
$assignment_query = $conn->query("
    SELECT aa.accident_id, aa.status as mission_status,
           a.ambulance_name, a.plate_number, a.driver_name
    FROM ambulance_assignments aa
    JOIN ambulances a ON aa.ambulance_id = a.id
    WHERE aa.hospital_station_id = $hospital_station_id 
    AND aa.status IN ('on_mission', 'arrived')
");
while ($assign = $assignment_query->fetch_assoc()) {
    $assignments_map[$assign['accident_id']] = $assign;
}

$map_data = [];
while($row = $accidents_query->fetch_assoc()) {
    $assignment = isset($assignments_map[$row['id']]) ? $assignments_map[$row['id']] : null;
    
    $map_data[] = [
        'id' => (int)$row['id'],
        'lat' => (float)$row['latitude'],
        'lng' => (float)$row['longitude'],
        'type' => htmlspecialchars($row['accident_type']),
        'location' => htmlspecialchars(substr($row['location_description'] ?? 'Unknown', 0, 60)),
        'status' => $row['status'],
        'injuries' => (int)$row['injuries'],
        'reporter' => htmlspecialchars($row['reporter_name']),
        'time' => date('H:i d/m/Y', strtotime($row['created_at'])),
        'ambulance' => $assignment ? [
            'name' => $assignment['ambulance_name'],
            'driver' => $assignment['driver_name'],
            'plate' => $assignment['plate_number']
        ] : null
    ];
}

// ============================================
// GET AMBULANCE STATISTICS
// ============================================
$stats_query = $conn->query("
    SELECT 
        (SELECT COUNT(*) FROM ambulances WHERE hospital_station_id = $hospital_station_id AND status = 'active') as total_active,
        (SELECT COUNT(*) FROM ambulances WHERE hospital_station_id = $hospital_station_id AND status = 'inactive') as total_inactive,
        (SELECT COUNT(*) FROM ambulances WHERE hospital_station_id = $hospital_station_id AND status = 'maintenance') as total_maintenance,
        (SELECT COUNT(*) FROM ambulance_assignments aa 
         JOIN ambulances a ON aa.ambulance_id = a.id 
         WHERE a.hospital_station_id = $hospital_station_id AND aa.status IN ('on_mission', 'arrived')) as on_mission,
        (SELECT COUNT(*) FROM ambulance_assignments aa 
         JOIN ambulances a ON aa.ambulance_id = a.id 
         WHERE a.hospital_station_id = $hospital_station_id AND aa.status = 'returned') as returned,
        (SELECT COUNT(*) FROM accidents WHERE assigned_hospital_id = $hospital_station_id AND hospital_status IN ('assigned', 'preparing')) as pending_count
");
$stats = $stats_query->fetch_assoc();

// Ensure pending_count is set
$pending_count = isset($stats['pending_count']) ? intval($stats['pending_count']) : 0;

// ============================================
// GET ALL AMBULANCES FOR THIS HOSPITAL
// ============================================
$ambulances = $conn->query("
    SELECT a.*,
           (SELECT status FROM ambulance_assignments 
            WHERE ambulance_id = a.id AND status IN ('on_mission', 'arrived') 
            ORDER BY assigned_at DESC LIMIT 1) as mission_status,
           (SELECT accident_id FROM ambulance_assignments 
            WHERE ambulance_id = a.id AND status IN ('on_mission', 'arrived') 
            ORDER BY assigned_at DESC LIMIT 1) as current_accident_id
    FROM ambulances a
    WHERE a.hospital_station_id = $hospital_station_id
    ORDER BY a.status = 'active' DESC, a.id DESC
");

// ============================================
// GET PENDING ACCIDENTS FOR ASSIGNMENT
// ============================================
$pending_accidents = $conn->query("
    SELECT id, accident_type, location_description, reporter_name, injuries
    FROM accidents 
    WHERE assigned_hospital_id = $hospital_station_id 
    AND hospital_status IN ('assigned', 'preparing')
    ORDER BY created_at DESC
");

// ============================================
// GET ACTIVE ASSIGNMENTS
// ============================================
$active_assignments = $conn->query("
    SELECT 
        aa.*,
        a.ambulance_name,
        a.plate_number,
        a.driver_name,
        a.driver_phone,
        acc.accident_type,
        acc.location_description,
        acc.reporter_name,
        acc.injuries,
        acc.latitude,
        acc.longitude
    FROM ambulance_assignments aa
    JOIN ambulances a ON aa.ambulance_id = a.id
    JOIN accidents acc ON aa.accident_id = acc.id
    WHERE aa.hospital_station_id = $hospital_station_id 
      AND aa.status IN ('on_mission', 'arrived')
    ORDER BY aa.assigned_at DESC
");

// Admin info for profile
$admin_name = htmlspecialchars($_SESSION['name'] ?? 'Hospital Staff');
$admin_email = htmlspecialchars($_SESSION['email'] ?? 'hospital@roadalert.tz');
$admin_role = htmlspecialchars($_SESSION['role'] ?? 'hospital');
$admin_init = strtoupper(substr($admin_name, 0, 1));
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width,initial-scale=1.0"/>
<title>Ambulance Dispatch — <?= htmlspecialchars($hospital_station) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&family=Poppins:wght@300;400;500;600;700;800&family=JetBrains+Mono:wght@400;500;600;700&display=swap" rel="stylesheet"/>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"/>
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"/>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>

<style>
/* ══════════════════════ TOKENS ══════════════════════ */
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{
  --bg: #F8FAFC;
  --bg2: #FFFFFF;
  --bg3: #EFF6FF;
  --bg4: #DBEAFE;
  --primary: #2563EB;
  --primary-light: #3B82F6;
  --primary-dark: #1D4ED8;
  --secondary: #0EA5E9;
  --critical: #EF4444;
  --high: #F97316;
  --moderate: #EAB308;
  --low: #22C55E;
  --success: #10B981;
  --text: #0F172A;
  --text-muted: #64748B;
  --border: #E2E8F0;
  --bord2: #CBD5E1;
  --white: #FFFFFF;
  --shadow: 0 1px 3px rgba(0,0,0,0.06);
  --shadow-hover: 0 4px 12px rgba(0,0,0,0.10);
  --sw: 260px;
  --th: 64px;
  --radius: 16px;
  
  --font-primary: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
  --font-display: 'Poppins', 'Inter', sans-serif;
  --font-mono: 'JetBrains Mono', 'Fira Code', monospace;
}

html,body{height:100%;background:var(--bg);color:var(--text);
  font-family:var(--font-primary);overflow:hidden}
::-webkit-scrollbar{width:4px;height:4px}
::-webkit-scrollbar-track{background:transparent}
::-webkit-scrollbar-thumb{background:var(--primary);border-radius:99px}

.shell{display:flex;height:100vh;overflow:hidden}

/* ══════════════════════ SIDEBAR ══════════════════════ */
.sidebar{
  width:var(--sw);flex-shrink:0;
  background:var(--white);border-right:1px solid var(--border);
  display:flex;flex-direction:column;
  position:relative;overflow:hidden;z-index:50;
  box-shadow:2px 0 8px rgba(0,0,0,0.04);
}

.sb-brand{display:flex;align-items:center;gap:.75rem;
  padding:1.05rem 1.25rem;border-bottom:1px solid var(--border);
  text-decoration:none;flex-shrink:0}
.sb-logo{width:40px;height:40px;border-radius:10px;flex-shrink:0;
  background:linear-gradient(135deg,var(--primary),var(--secondary));
  display:flex;align-items:center;justify-content:center;font-size:1.1rem;
  color:white;box-shadow:0 0 20px rgba(37,99,235,0.3)}
.sb-title{font-family:var(--font-display);font-size:1.05rem;font-weight:800;
  letter-spacing:-.01em;color:var(--text)}
.sb-sub{font-size:.55rem;color:var(--text-muted);letter-spacing:.14em;
  text-transform:uppercase;font-family:var(--font-mono)}

.sb-nav{flex:1;overflow-y:auto;padding:.65rem 0}
.sb-sect{font-family:var(--font-mono);font-size:.56rem;
  letter-spacing:.16em;text-transform:uppercase;color:var(--text-muted);
  padding:.6rem 1.25rem .25rem}
.sb-item{display:flex;align-items:center;gap:.75rem;
  padding:.55rem 1rem;margin:1px .55rem;border-radius:9px;
  text-decoration:none;font-size:.78rem;font-weight:500;color:var(--text-muted);
  transition:background .18s,color .18s;cursor:pointer;border:none;background:transparent;
  width:calc(100% - 1.1rem);text-align:left;font-family:var(--font-primary);position:relative}
.sb-ic{width:28px;height:28px;border-radius:7px;flex-shrink:0;
  display:flex;align-items:center;justify-content:center;font-size:.8rem;
  background:rgba(37,99,235,.06)}
.sb-item:hover{background:rgba(37,99,235,.06);color:var(--text)}
.sb-item:hover .sb-ic{background:rgba(37,99,235,.12);color:var(--primary)}
.sb-item.active{background:rgba(37,99,235,.08);color:var(--primary)}
.sb-item.active .sb-ic{background:rgba(37,99,235,.15);color:var(--primary)}
.sb-item.active::before{content:'';position:absolute;left:0;top:50%;
  transform:translateY(-50%);width:3px;height:56%;
  background:var(--primary);border-radius:0 3px 3px 0}
.sb-badge{margin-left:auto;font-size:.55rem;font-weight:700;
  padding:.12rem .4rem;border-radius:999px;font-family:var(--font-mono)}
.sb-badge.red{background:rgba(239,68,68,.12);color:var(--critical);
  border:1px solid rgba(239,68,68,.2);animation:badgePulse 2s infinite}
.sb-badge.grn{background:rgba(16,185,129,.12);color:var(--success);
  border:1px solid rgba(16,185,129,.2)}
@keyframes badgePulse{0%,100%{box-shadow:0 0 0 0 rgba(239,68,68,.25)}
  50%{box-shadow:0 0 0 4px rgba(239,68,68,0)}}

.sb-logout-item{display:flex;align-items:center;gap:.75rem;
  padding:.55rem 1rem;margin:1px .55rem;border-radius:9px;
  font-size:.78rem;font-weight:500;color:var(--text-muted);
  transition:background .18s,color .18s;cursor:pointer;border:none;background:transparent;
  width:calc(100% - 1.1rem);text-align:left;font-family:var(--font-primary);text-decoration:none}
.sb-logout-item .sb-ic{width:28px;height:28px;border-radius:7px;flex-shrink:0;
  display:flex;align-items:center;justify-content:center;font-size:.8rem;
  background:rgba(37,99,235,.06)}
.sb-logout-item:hover{background:rgba(239,68,68,.08);color:var(--critical)}
.sb-logout-item:hover .sb-ic{background:rgba(239,68,68,.12);color:var(--critical)}
.sb-divider{height:1px;background:var(--border);margin:.4rem .8rem}

/* ══════════════════════ MAIN ══════════════════════ */
.main{flex:1;display:flex;flex-direction:column;overflow:hidden;min-width:0}

/* ── TOPBAR ── */
.topbar{height:var(--th);flex-shrink:0;
  background:var(--white);border-bottom:1px solid var(--border);
  display:flex;align-items:center;gap:.9rem;padding:0 1.4rem;
  position:relative;z-index:40}
.tb-title{font-family:var(--font-display);font-size:1.1rem;
  font-weight:700;color:var(--text);flex:1}
.tb-title i{margin-right:.5rem;color:var(--primary)}
.tb-search{display:flex;align-items:center;gap:.5rem;
  background:var(--bg3);border:1px solid var(--border);border-radius:9px;
  padding:.3rem .8rem;transition:border-color .2s}
.tb-search:focus-within{border-color:var(--primary)}
.tb-search input{background:none;border:none;outline:none;
  font-family:var(--font-primary);font-size:.75rem;
  color:var(--text);width:160px}
.tb-search input::placeholder{color:var(--text-muted)}
.tb-search i{color:var(--text-muted);font-size:.75rem}
.tb-acts{display:flex;gap:.5rem;align-items:center}

.live-pill{display:flex;align-items:center;gap:.35rem;
  background:rgba(16,185,129,.08);border:1px solid rgba(16,185,129,.2);
  border-radius:999px;padding:.2rem .7rem;
  font-family:var(--font-mono);font-size:.55rem;color:var(--success)}
.live-dot{width:5px;height:5px;border-radius:50%;background:var(--success);
  animation:blink 1.3s step-end infinite}
@keyframes blink{0%,100%{opacity:1}50%{opacity:0}}

.station-badge{display:flex;align-items:center;gap:.5rem;
  background:var(--bg3);border:1px solid var(--primary);
  border-radius:999px;padding:.2rem .8rem .2rem .4rem;
  font-size:.65rem;color:var(--text)}
.station-badge i{color:var(--primary);font-size:.7rem}
.station-badge .status-dot{width:6px;height:6px;border-radius:50%;
  background:var(--success);display:inline-block;animation:blink 1.3s step-end infinite}

/* ─── NOTIFICATION ─── */
.notif-wrap{position:relative;display:inline-block}
.notif-btn{width:36px;height:36px;border-radius:9px;
  background:var(--bg3);border:1px solid var(--border);
  display:flex;align-items:center;justify-content:center;
  font-size:.85rem;color:var(--text-muted);cursor:pointer;
  transition:background .18s,color .18s;position:relative}
.notif-btn:hover{background:rgba(37,99,235,.08);color:var(--primary)}
.notif-count{position:absolute;top:-6px;right:-6px;
  background:var(--critical);color:#fff;font-size:.5rem;font-weight:700;
  min-width:18px;height:18px;border-radius:50%;
  display:flex;align-items:center;justify-content:center;
  font-family:var(--font-mono);border:2px solid var(--white);
  z-index:9999;padding:0 4px;box-shadow:0 0 10px rgba(239,68,68,0.3);
  animation:badgePulse 2s infinite}
.notif-drop{position:absolute;right:0;top:calc(100% + 8px);
  width:340px;background:var(--white);border:1px solid var(--border);
  border-radius:14px;overflow:hidden;box-shadow:0 16px 48px rgba(0,0,0,.12);
  opacity:0;transform:translateY(-8px) scale(.97);
  pointer-events:none;transition:opacity .2s,transform .2s;z-index:999}
.notif-drop.show{opacity:1;transform:none;pointer-events:all}
.notif-head{padding:.7rem 1rem;border-bottom:1px solid var(--border);
  display:flex;align-items:center;justify-content:space-between}
.nh-title{font-weight:700;font-size:.8rem;color:var(--text)}
.nh-sub{font-size:.65rem;color:var(--text-muted)}
.notif-list{max-height:280px;overflow-y:auto}
.notif-item{display:flex;align-items:flex-start;gap:.6rem;
  padding:.6rem 1rem;border-bottom:1px solid var(--border);
  cursor:pointer;transition:background .14s}
.notif-item:hover{background:rgba(37,99,235,.04)}
.ndot{width:6px;height:6px;border-radius:50%;background:var(--critical);
  flex-shrink:0;margin-top:3px;animation:badgePulse 1.5s infinite}
.nb{flex:1;min-width:0}
.nid{font-size:.7rem;font-weight:700;color:var(--primary);
  font-family:var(--font-mono)}
.nloc{font-size:.65rem;color:var(--text-muted);white-space:nowrap;
  overflow:hidden;text-overflow:ellipsis;margin-top:1px}
.nmeta{display:flex;gap:.4rem;align-items:center;margin-top:.2rem;flex-wrap:wrap}
.npill{font-size:.55rem;padding:.1rem .4rem;border-radius:999px;
  background:rgba(239,68,68,.1);color:var(--critical);
  border:1px solid rgba(239,68,68,.2)}
.ntime{font-size:.58rem;color:var(--text-muted)}
.notif-foot{padding:.5rem 1rem;text-align:center;border-top:1px solid var(--border)}
.notif-foot a{font-size:.68rem;color:var(--primary);text-decoration:none;font-family:var(--font-primary)}

.profile-wrap{position:relative}
.profile-btn{display:flex;align-items:center;gap:.5rem;
  background:var(--bg3);border:1px solid var(--border);
  border-radius:10px;padding:.3rem .6rem .3rem .4rem;
  cursor:pointer;transition:background .18s,border-color .18s}
.profile-btn:hover{background:rgba(37,99,235,.06);border-color:var(--primary)}
.pa{width:28px;height:28px;border-radius:7px;flex-shrink:0;
  background:linear-gradient(135deg,var(--primary),var(--secondary));
  display:flex;align-items:center;justify-content:center;
  font-size:.75rem;font-weight:700;color:white;font-family:var(--font-display)}
.pname{font-size:.72rem;font-weight:600;color:var(--text);
  max-width:80px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.pchev{font-size:.65rem;color:var(--text-muted);transition:transform .22s}
.profile-btn.open .pchev{transform:rotate(180deg)}

.profile-drop{position:absolute;right:0;top:calc(100% + 8px);
  width:220px;background:var(--white);border:1px solid var(--border);
  border-radius:14px;overflow:hidden;box-shadow:0 16px 48px rgba(0,0,0,.12);
  opacity:0;transform:translateY(-8px) scale(.97);
  pointer-events:none;transition:opacity .2s,transform .2s;z-index:999}
.profile-drop.show{opacity:1;transform:none;pointer-events:all}
.pd-head{padding:.7rem 1rem .5rem;border-bottom:1px solid var(--border)}
.pd-name{font-weight:700;font-size:.8rem;color:var(--text)}
.pd-email{font-size:.62rem;color:var(--text-muted);margin-top:2px;
  font-family:var(--font-mono)}
.pd-pill{display:inline-flex;align-items:center;gap:.25rem;
  background:rgba(37,99,235,.08);border:1px solid var(--primary);
  border-radius:999px;padding:.15rem .5rem;margin-top:.35rem;
  font-size:.55rem;color:var(--primary);font-family:var(--font-mono);
  letter-spacing:.06em;text-transform:uppercase}
.pd-items{padding:.3rem 0}
.pd-item{display:flex;align-items:center;gap:.6rem;
  padding:.45rem .8rem;font-size:.72rem;color:var(--text-muted);
  text-decoration:none;cursor:pointer;
  transition:background .14s,color .14s;
  border:none;background:none;width:100%;text-align:left;
  font-family:var(--font-primary)}
.pd-item i{width:14px;text-align:center;font-size:.72rem;color:var(--text-muted)}
.pd-item:hover{background:rgba(37,99,235,.04);color:var(--text)}
.pd-item:hover i{color:var(--primary)}
.pd-item.danger:hover{background:rgba(239,68,68,.08);color:var(--critical)}
.pd-item.danger:hover i{color:var(--critical)}
.pd-div{height:1px;background:var(--border);margin:.2rem 0}

.tb-btn{display:flex;align-items:center;gap:.3rem;
  padding:.3rem .6rem;border-radius:8px;font-size:.7rem;
  border:1px solid var(--border);background:transparent;
  color:var(--text);cursor:pointer;transition:background .18s;
  font-family:var(--font-primary);text-decoration:none}
.tb-btn:hover{background:rgba(239,68,68,.08);color:var(--critical);border-color:rgba(239,68,68,.2)}

/* ══════════════════════ CONTENT ══════════════════════ */
.content{flex:1;overflow-y:auto;padding:1.2rem 1.5rem}

/* ─── STAT CARDS ─── */
.stats-row{display:grid;grid-template-columns:repeat(6,1fr);gap:1rem;margin-bottom:1.1rem}
.sc{background:var(--white);border:1px solid var(--border);border-radius:var(--radius);
  padding:1rem 1.2rem;position:relative;overflow:hidden;
  transition:border-color .22s,transform .18s;box-shadow:var(--shadow)}
.sc:hover{border-color:var(--primary);transform:translateY(-2px);box-shadow:var(--shadow-hover)}
.sc::after{content:'';position:absolute;top:0;left:0;right:0;height:2px;opacity:0;transition:opacity .25s}
.sc:hover::after{opacity:1}
.sc.c1::after{background:linear-gradient(90deg,var(--success),transparent)}
.sc.c2::after{background:linear-gradient(90deg,var(--critical),transparent)}
.sc.c3::after{background:linear-gradient(90deg,var(--high),transparent)}
.sc.c4::after{background:linear-gradient(90deg,var(--secondary),transparent)}
.sc.c5::after{background:linear-gradient(90deg,var(--moderate),transparent)}
.sc.c6::after{background:linear-gradient(90deg,var(--text-muted),transparent)}
.sc-ico{width:34px;height:34px;border-radius:8px;
  display:flex;align-items:center;justify-content:center;
  font-size:.8rem;margin-bottom:.7rem}
.c1 .sc-ico{background:rgba(16,185,129,.1);color:var(--success)}
.c2 .sc-ico{background:rgba(239,68,68,.1);color:var(--critical)}
.c3 .sc-ico{background:rgba(249,115,22,.1);color:var(--high)}
.c4 .sc-ico{background:rgba(14,165,233,.1);color:var(--secondary)}
.c5 .sc-ico{background:rgba(234,179,8,.1);color:var(--moderate)}
.c6 .sc-ico{background:rgba(100,116,139,.1);color:var(--text-muted)}
.sc-num{font-family:var(--font-display);font-size:1.6rem;
  font-weight:800;line-height:1;margin-bottom:.15rem}
.c1 .sc-num{color:var(--success)}
.c2 .sc-num{color:var(--critical)}
.c3 .sc-num{color:var(--high)}
.c4 .sc-num{color:var(--secondary)}
.c5 .sc-num{color:var(--moderate)}
.c6 .sc-num{color:var(--text-muted)}
.sc-lbl{font-size:.55rem;color:var(--text-muted);letter-spacing:.08em;text-transform:uppercase}
.sc-tag{position:absolute;top:1rem;right:1rem;font-size:.5rem;font-family:var(--font-mono);color:var(--text-muted)}

/* ─── MAP ─── */
.map-container{background:var(--white);border:1px solid var(--border);border-radius:var(--radius);overflow:hidden;box-shadow:var(--shadow);margin-top:1rem}
.map-header{display:flex;align-items:center;justify-content:space-between;padding:.7rem 1.15rem;border-bottom:1px solid var(--border)}
.map-header-title{font-family:var(--font-display);font-size:.95rem;font-weight:700;color:var(--text)}
.map-header-title i{margin-right:.5rem;color:var(--primary)}
.map-wrapper{height:calc(100vh - 280px);min-height:400px;width:100%}
#ambulanceMap{height:100%;width:100%;background:#EFF6FF;border-radius:0 0 var(--radius) var(--radius)}

/* ─── FLEET ─── */
.fleet-section{margin-top:1rem}
.fleet-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:.5rem}
.fleet-header h3{font-family:var(--font-display);font-size:.95rem}
.fleet-header h3 i{color:var(--primary);margin-right:.5rem}
.fleet-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:1rem}

.amb-card{background:var(--white);border:1px solid var(--border);border-radius:12px;padding:1rem;transition:all .2s;box-shadow:var(--shadow)}
.amb-card:hover{box-shadow:var(--shadow-hover);transform:translateY(-2px)}
.amb-card .top{display:flex;justify-content:space-between;align-items:start}
.amb-card .name{font-weight:700;font-size:.85rem;color:var(--text)}
.amb-card .plate{font-family:var(--font-mono);font-size:.65rem;color:var(--text-muted)}
.amb-card .driver{font-size:.7rem;margin:.3rem 0}
.amb-card .driver i{color:var(--primary);width:16px}
.amb-card .info{display:grid;grid-template-columns:1fr 1fr;gap:.3rem;margin:.5rem 0;font-size:.65rem}
.amb-card .info .label{color:var(--text-muted)}
.amb-card .info .value{font-weight:600}
.amb-card .actions{display:flex;gap:.3rem;flex-wrap:wrap;margin-top:.5rem;padding-top:.5rem;border-top:1px solid var(--border)}
.amb-card .badge{display:inline-flex;align-items:center;gap:.2rem;padding:.15rem .5rem;border-radius:999px;font-family:var(--font-mono);font-size:.55rem;font-weight:600;text-transform:uppercase}
.amb-card .badge.active{background:rgba(16,185,129,.1);color:var(--success);border:1px solid rgba(16,185,129,.2)}
.amb-card .badge.inactive{background:rgba(100,116,139,.1);color:var(--text-muted);border:1px solid rgba(100,116,139,.2)}
.amb-card .badge.maintenance{background:rgba(249,115,22,.1);color:var(--high);border:1px solid rgba(249,115,22,.2)}
.amb-card .badge.on_mission{background:rgba(239,68,68,.1);color:var(--critical);border:1px solid rgba(239,68,68,.2)}
.amb-card .badge.arrived{background:rgba(234,179,8,.1);color:var(--moderate);border:1px solid rgba(234,179,8,.2)}
.amb-card .badge.returned{background:rgba(14,165,233,.1);color:var(--secondary);border:1px solid rgba(14,165,233,.2)}
.badge-dot{width:4px;height:4px;border-radius:50%;background:currentColor}

/* ─── BUTTONS ─── */
.btn{display:inline-flex;align-items:center;gap:.3rem;padding:.15rem .5rem;border-radius:6px;font-size:.55rem;font-weight:600;border:none;cursor:pointer;transition:all .15s;font-family:var(--font-mono);text-decoration:none}
.btn-primary{background:var(--primary);color:white}
.btn-primary:hover{background:var(--primary-dark);transform:scale(1.02)}
.btn-success{background:var(--success);color:white}
.btn-success:hover{background:#059669;transform:scale(1.02)}
.btn-danger{background:var(--critical);color:white}
.btn-danger:hover{background:#DC2626;transform:scale(1.02)}
.btn-outline{background:transparent;border:1px solid var(--border);color:var(--text)}
.btn-outline:hover{background:var(--bg3)}
.btn-sm{padding:.2rem .4rem;font-size:.5rem}
.btn-block{width:100%;justify-content:center;padding:.5rem;font-size:.7rem}

/* ─── PANEL ─── */
.panel{background:var(--white);border:1px solid var(--border);border-radius:var(--radius);overflow:hidden;box-shadow:var(--shadow);margin-top:1rem}
.ph{display:flex;align-items:center;justify-content:space-between;padding:.7rem 1.15rem;border-bottom:1px solid var(--border)}
.ph-t{font-family:var(--font-display);font-size:.95rem;font-weight:700;color:var(--text)}
.ph-t i{margin-right:.5rem;color:var(--primary)}
.tbl-wrap{overflow-x:auto}
table{width:100%;border-collapse:collapse;font-size:.72rem}
th{padding:.45rem .75rem;font-size:.55rem;letter-spacing:.1em;text-transform:uppercase;color:var(--text-muted);font-weight:600;border-bottom:1px solid var(--border);text-align:left}
td{padding:.45rem .75rem;color:var(--text);border-bottom:1px solid rgba(0,0,0,.04)}
tr:hover{background:rgba(37,99,235,.02)}
.td-mono{font-family:var(--font-mono);font-size:.65rem}

/* ─── MODAL ─── */
.modal-bg{display:none;position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,.5);z-index:999;align-items:center;justify-content:center}
.modal-bg.show{display:flex}
.modal{background:var(--white);border-radius:var(--radius);max-width:600px;width:95%;max-height:90vh;overflow-y:auto;padding:1.5rem;position:relative;animation:modalIn .25s ease}
@keyframes modalIn{from{opacity:0;transform:scale(.95) translateY(10px)}to{opacity:1;transform:none}}
.modal-close{position:absolute;top:.8rem;right:1rem;background:none;border:none;font-size:1.2rem;cursor:pointer;color:var(--text-muted)}
.modal-title{font-family:var(--font-display);font-size:1.2rem;font-weight:700;margin-bottom:.3rem}
.modal-title i{color:var(--primary);margin-right:.5rem}
.modal-sub{font-size:.7rem;color:var(--text-muted);margin-bottom:1rem}
.modal .form-group{margin-bottom:.8rem}
.modal .form-group label{display:block;font-size:.7rem;font-weight:600;color:var(--text-muted);margin-bottom:.2rem}
.modal .form-group input,.modal .form-group select{width:100%;padding:.4rem .6rem;border:1px solid var(--border);border-radius:8px;font-size:.75rem;font-family:var(--font-primary);outline:none}
.modal .form-group input:focus,.modal .form-group select:focus{border-color:var(--primary);box-shadow:0 0 0 3px rgba(37,99,235,.1)}
.modal .form-row{display:grid;grid-template-columns:1fr 1fr;gap:.8rem}

/* ─── TOAST ─── */
.toast-wrap{position:fixed;top:1rem;right:1.4rem;z-index:9999;display:flex;flex-direction:column;gap:.4rem}
.toast{display:flex;align-items:flex-start;gap:.6rem;background:var(--white);border:1px solid var(--border);
  border-left:3px solid var(--primary);border-radius:11px;padding:.6rem .8rem;min-width:240px;max-width:300px;
  box-shadow:0 8px 28px rgba(0,0,0,.12);animation:tIn .3s ease}
.toast.success{border-left-color:var(--success)}
.toast.error{border-left-color:var(--critical)}
@keyframes tIn{from{opacity:0;transform:translateX(26px)}to{opacity:1;transform:none}}
.t-ic{font-size:.85rem;flex-shrink:0}
.t-body{flex:1}
.t-title{font-weight:700;font-size:.72rem;color:var(--text)}
.t-msg{font-size:.65rem;color:var(--text-muted)}
.t-x{background:none;border:none;color:var(--text-muted);cursor:pointer;font-size:.7rem}

.footer{flex-shrink:0;border-top:1px solid var(--border);
  padding:.5rem 1.4rem;display:flex;align-items:center;justify-content:space-between;
  font-size:.6rem;color:var(--text-muted);
  font-family:var(--font-mono);background:var(--white)}
.footer .dual-status-badge{background:rgba(37,99,235,.08);border:1px solid rgba(37,99,235,.2);border-radius:999px;padding:.1rem .5rem;font-size:.5rem;color:var(--primary);font-weight:700}

.grid-bg{position:fixed;inset:0;pointer-events:none;z-index:0;
  background-image:linear-gradient(rgba(37,99,235,.01) 1px,transparent 1px),
    linear-gradient(90deg,rgba(37,99,235,.01) 1px,transparent 1px);
  background-size:42px 42px}

@media(max-width:1100px){.stats-row{grid-template-columns:repeat(3,1fr)}}
@media(max-width:760px){
  :root{--sw:0px}.sidebar{display:none}.stats-row{grid-template-columns:1fr 1fr}
  .content{padding:.8rem}.tb-search{display:none}.modal .form-row{grid-template-columns:1fr}
}
</style>
</head>
<body>
<div class="grid-bg"></div>
<div class="toast-wrap" id="toastWrap"></div>

<div class="shell">
<!-- SIDEBAR -->
<aside class="sidebar">
  <a href="dashboard.php" class="sb-brand">
    <div class="sb-logo">🏥</div>
    <div><div class="sb-title"><?= htmlspecialchars($hospital_station) ?></div>
      <div class="sb-sub">Emergency Medical Response</div></div>
  </a>
  <nav class="sb-nav">
    <div class="sb-sect">Main</div>
    <a href="dashboard.php" class="sb-item <?= is_active('dashboard.php') ?>">
      <span class="sb-ic"><i class="fas fa-chart-line"></i></span> Dashboard
    </a>
    <a href="emergencies.php" class="sb-item <?= is_active('emergencies.php') ?>">
      <span class="sb-ic"><i class="fas fa-ambulance"></i></span> Emergency Cases
      <?php if($pending_count > 0): ?>
        <span class="sb-badge red"><?= $pending_count ?></span>
      <?php endif; ?>
    </a>
    <a href="ambulance.php" class="sb-item active">
      <span class="sb-ic"><i class="fas fa-truck-medical"></i></span> Ambulance Dispatch
    </a>
    <div class="sb-sect" style="margin-top:.4rem">Medical</div>
    <a href="treatment.php" class="sb-item <?= is_active('treatment.php') ?>">
      <span class="sb-ic"><i class="fas fa-notes-medical"></i></span> Treatment Management
    </a>
    
    <!-- 🔴 NEW: Resolved Cases in Sidebar -->
    <a href="resolved_cases.php" class="sb-item <?= is_active('resolved_cases.php') ?>">
      <span class="sb-ic"><i class="fas fa-check-circle"></i></span> Resolved Cases
      <?php if($closed_count > 0): ?>
        <span class="sb-badge grn"><?= $closed_count ?></span>
      <?php endif; ?>
    </a>
    
    <div class="sb-sect" style="margin-top:.4rem">Account</div>
    <a href="profile.php" class="sb-item <?= is_active('profile.php') ?>">
      <span class="sb-ic"><i class="fas fa-user-cog"></i></span> My Profile
    </a>
    <div style="height:1px;background:var(--border);margin:.8rem .8rem"></div>
    <a href="../logout.php" class="sb-logout-item">
      <span class="sb-ic"><i class="fas fa-sign-out-alt"></i></span> Logout
    </a>
  </nav>
</aside>

<!-- MAIN -->
<div class="main">
  <!-- TOPBAR -->
  <div class="topbar">
    <div class="tb-title"><i class="fas fa-truck-medical"></i>Ambulance Dispatch</div>

    <div class="tb-search">
      <i class="fas fa-search"></i>
      <input type="text" placeholder="Search emergencies…" oninput="filterAccidents(this.value)"/>
    </div>

    <div class="tb-acts">
      <div class="live-pill"><span class="live-dot"></span>Live</div>

      <!-- Station Badge -->
      <div class="station-badge">
        <i class="fas fa-hospital"></i>
        <span class="station-name"><?= htmlspecialchars($hospital_station) ?></span>
        <span class="status-dot"></span>
      </div>

      <!-- Notification Bell -->
      <div class="notif-wrap">
        <button class="notif-btn" onclick="toggleNotif()" id="notificationBell">
          <i class="fas fa-bell"></i>
          <span class="notif-count" id="notifCount" style="<?= $pending_count > 0 ? '' : 'display:none;' ?>">
            <?= $pending_count ?>
          </span>
        </button>
        <div class="notif-drop" id="notifDrop">
          <div class="notif-head">
            <div><div class="nh-title">🚨 New Emergencies</div>
              <div class="nh-sub">Cases assigned to your hospital</div></div>
            <span style="font-size:.58rem;color:var(--text-muted);font-family:var(--font-mono)">
              <?= $pending_count ?> pending</span>
          </div>
          <div class="notif-list">
            <?php 
            // Get pending notifications for display
            $pending_notif_query = $conn->query("
                SELECT id, accident_type, location_description, created_at, reporter_name, injuries, hospital_status as status
                FROM accidents 
                WHERE assigned_hospital_id = $hospital_station_id 
                AND hospital_status = 'assigned'
                ORDER BY created_at DESC LIMIT 10
            ");
            if($pending_notif_query && $pending_notif_query->num_rows > 0):
              while($al = $pending_notif_query->fetch_assoc()): ?>
            <div class="notif-item" onclick="window.location.href='ambulance.php'">
              <div class="ndot"></div>
              <div class="nb">
                <div class="nid">#ACC-<?= str_pad($al['id'],4,'0',STR_PAD_LEFT) ?></div>
                <div class="nloc">📍 <?= htmlspecialchars(substr($al['location_description']??'Unknown',0,40)) ?></div>
                <div class="nmeta">
                  <span class="npill">assigned</span>
                  <span class="ntime">🤕 <?= $al['injuries'] ?> injured</span>
                  <span class="ntime"><?= date('H:i',strtotime($al['created_at'])) ?></span>
                </div>
              </div>
              <i class="fas fa-chevron-right" style="font-size:.62rem;color:var(--text-muted);flex-shrink:0;margin-top:2px"></i>
            </div>
            <?php endwhile; else: ?>
            <div style="padding:1.5rem;text-align:center">
              <i class="fas fa-check-circle" style="font-size:1.8rem;color:var(--success);opacity:.45"></i>
              <p style="font-size:.7rem;color:var(--text-muted);margin-top:.4rem">No pending emergencies</p>
            </div>
            <?php endif; ?>
          </div>
          <div class="notif-foot">
            <a href="ambulance.php">📋 View all emergencies →</a>
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
            <button class="pd-item" onclick="window.location.href='profile.php'">
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
    <!-- STATS -->
    <div class="stats-row">
      <div class="sc c1">
        <div class="sc-ico"><i class="fas fa-check-circle"></i></div>
        <div class="sc-num"><?= number_format($stats['total_active'] ?? 0) ?></div>
        <div class="sc-lbl">Active Ambulances</div>
      </div>
      <div class="sc c2">
        <div class="sc-ico"><i class="fas fa-truck-medical"></i></div>
        <div class="sc-num"><?= number_format($stats['on_mission'] ?? 0) ?></div>
        <div class="sc-lbl">On Mission</div>
      </div>
      <div class="sc c3">
        <div class="sc-ico"><i class="fas fa-clock"></i></div>
        <div class="sc-num"><?= number_format($stats['pending_count'] ?? 0) ?></div>
        <div class="sc-lbl">Pending Assignments</div>
      </div>
      <div class="sc c4">
        <div class="sc-ico"><i class="fas fa-undo-alt"></i></div>
        <div class="sc-num"><?= number_format($stats['returned'] ?? 0) ?></div>
        <div class="sc-lbl">Returned</div>
      </div>
      <div class="sc c5">
        <div class="sc-ico"><i class="fas fa-wrench"></i></div>
        <div class="sc-num"><?= number_format($stats['total_maintenance'] ?? 0) ?></div>
        <div class="sc-lbl">Maintenance</div>
      </div>
      <div class="sc c6">
        <div class="sc-ico"><i class="fas fa-ban"></i></div>
        <div class="sc-num"><?= number_format($stats['total_inactive'] ?? 0) ?></div>
        <div class="sc-lbl">Inactive</div>
      </div>
    </div>

    <!-- AMBULANCE FLEET -->
    <div class="fleet-section">
      <div class="fleet-header">
        <h3><i class="fas fa-truck-medical"></i> Ambulance Fleet</h3>
        <button class="btn btn-primary" onclick="openModal('addAmbulanceModal')">
          <i class="fas fa-plus"></i> Add Ambulance
        </button>
      </div>
      <div class="fleet-grid">
        <?php if($ambulances && $ambulances->num_rows > 0):
          while($amb = $ambulances->fetch_assoc()): 
            // Get accident location for navigation
            $nav_data = null;
            if($amb['current_accident_id']) {
              $acc_nav = $conn->query("
                SELECT latitude, longitude, location_description 
                FROM accidents 
                WHERE id = " . intval($amb['current_accident_id'])
              );
              $nav_data = $acc_nav->fetch_assoc();
            }
        ?>
        <div class="amb-card">
          <div class="top">
            <div>
              <div class="name">🚑 <?= htmlspecialchars($amb['ambulance_name']) ?></div>
              <div class="plate"><?= htmlspecialchars($amb['plate_number']) ?></div>
            </div>
            <span class="badge <?= $amb['status'] ?>">
              <span class="badge-dot"></span> <?= ucfirst($amb['status']) ?>
            </span>
          </div>
          <div class="driver">
            <i class="fas fa-user"></i> <?= htmlspecialchars($amb['driver_name']) ?> 
            <span style="font-size:.6rem;color:var(--text-muted)">📞 <?= htmlspecialchars($amb['driver_phone']) ?></span>
          </div>
          <div class="info">
            <div><span class="label">Model:</span> <span class="value"><?= htmlspecialchars($amb['model'] ?: 'N/A') ?></span></div>
            <div><span class="label">Type:</span> <span class="value"><?= ucfirst($amb['type']) ?></span></div>
            <div><span class="label">Capacity:</span> <span class="value"><?= $amb['capacity'] ?> patients</span></div>
            <div>
              <?php if($amb['mission_status']): ?>
                <span class="badge <?= $amb['mission_status'] ?>">
                  <span class="badge-dot"></span> <?= str_replace('_', ' ', ucfirst($amb['mission_status'])) ?>
                </span>
              <?php else: ?>
                <span style="font-size:.6rem;color:var(--text-muted)">✅ Available</span>
              <?php endif; ?>
            </div>
          </div>
          <div class="actions">
            <!-- Edit Button -->
            <button class="btn btn-outline btn-sm" onclick="editAmbulance(<?= $amb['id'] ?>)">
              <i class="fas fa-edit"></i>
            </button>
            
            <!-- 🔴 Status Update - FIXED: Removed onsubmit confirm -->
            <form method="POST" style="display:inline">
              <input type="hidden" name="ambulance_id" value="<?= $amb['id'] ?>">
              <select name="status" onchange="this.form.submit()" style="font-size:.5rem;padding:.1rem .3rem;border-radius:4px;border:1px solid var(--border)">
                <option value="active" <?= $amb['status'] == 'active' ? 'selected' : '' ?>>✅ Active</option>
                <option value="inactive" <?= $amb['status'] == 'inactive' ? 'selected' : '' ?>>⛔ Inactive</option>
                <option value="maintenance" <?= $amb['status'] == 'maintenance' ? 'selected' : '' ?>>🔧 Maintenance</option>
              </select>
              <button type="submit" name="update_ambulance_status" class="btn btn-primary btn-sm">
                <i class="fas fa-save"></i>
              </button>
            </form>
            
            <!-- Assign Button -->
            <?php if(!$amb['mission_status'] && $amb['status'] == 'active'): ?>
              <button class="btn btn-success btn-sm" onclick="openAssignModal(<?= $amb['id'] ?>, '<?= htmlspecialchars($amb['ambulance_name']) ?>')">
                <i class="fas fa-crosshairs"></i> Assign
              </button>
            <?php endif; ?>
            
            <!-- Navigate Button -->
            <?php if($amb['mission_status'] && $nav_data && $nav_data['latitude'] && $nav_data['longitude']): ?>
              <a href="https://www.google.com/maps/dir/<?= $hospital_lat ?>,<?= $hospital_lng ?>/<?= $nav_data['latitude'] ?>,<?= $nav_data['longitude'] ?>" 
                 target="_blank" 
                 class="btn btn-success btn-sm"
                 title="Navigate to emergency location">
                <i class="fas fa-directions"></i> Navigate
              </a>
            <?php endif; ?>
            
            <!-- Delete Button -->
            <?php if($amb['status'] != 'active' && !$amb['mission_status']): ?>
              <a href="?delete_ambulance=<?= $amb['id'] ?>" class="btn btn-danger btn-sm" onclick="return confirm('Delete this ambulance?')">
                <i class="fas fa-trash"></i>
              </a>
            <?php endif; ?>
          </div>
        </div>
        <?php endwhile; else: ?>
        <div style="text-align:center;padding:2rem;color:var(--text-muted);grid-column:1/-1">
          <i class="fas fa-ambulance" style="font-size:2rem;display:block;opacity:.3;margin-bottom:.5rem"></i>
          No ambulances registered. Click "Add Ambulance" to get started.
        </div>
        <?php endif; ?>
      </div>
    </div>

    <!-- MAP -->
    <div class="map-container">
      <div class="map-header">
        <div class="map-header-title"><i class="fas fa-map"></i> Emergency & Ambulance Location Map</div>
        <div class="map-header-info">
          <span><span class="dot" style="background:#F43F5E;display:inline-block;width:8px;height:8px;border-radius:50%;"></span> Assigned</span>
          <span><span class="dot" style="background:#22D3EE;display:inline-block;width:8px;height:8px;border-radius:50%;"></span> Dispatched</span>
          <span><span class="dot" style="background:#10B981;display:inline-block;width:8px;height:8px;border-radius:50%;"></span> Treated</span>
          <span><i class="fas fa-flag-checkered" style="color:var(--primary)"></i> Hospital</span>
          <span><i class="fas fa-ambulance" style="color:var(--critical)"></i> Ambulance</span>
        </div>
      </div>
      <div class="map-wrapper"><div id="ambulanceMap"></div></div>
    </div>

    <!-- ACTIVE ASSIGNMENTS - REMOVED 'completed' -->
    <div class="panel">
      <div class="ph">
        <div>
          <div class="ph-t"><i class="fas fa-tasks"></i> Active Ambulance Missions</div>
          <div style="font-size:.6rem;color:var(--text-muted)">Real-time tracking of ambulances on duty</div>
        </div>
        <span style="font-size:.6rem;color:var(--text-muted)">🔄 Auto-refresh: 30s</span>
      </div>
      <div class="tbl-wrap">
        <table>
          <thead>
            <tr>
              <th>🚑 Ambulance</th>
              <th>👤 Driver</th>
              <th>🚨 Accident</th>
              <th>📍 Location</th>
              <th>🤕 Injuries</th>
              <th>Status</th>
              <th>⏱ Assigned</th>
              <th style="text-align:right">Action</th>
            </tr>
          </thead>
          <tbody>
          <?php if($active_assignments && $active_assignments->num_rows > 0):
            while($assign = $active_assignments->fetch_assoc()): ?>
            <tr>
              <td><span style="font-weight:600"><?= htmlspecialchars($assign['ambulance_name']) ?></span><br>
                  <span style="font-size:.6rem;color:var(--text-muted)"><?= htmlspecialchars($assign['plate_number']) ?></span></td>
              <td><?= htmlspecialchars($assign['driver_name']) ?><br>
                  <span style="font-size:.6rem;color:var(--text-muted)">📞 <?= htmlspecialchars($assign['driver_phone']) ?></span></td>
              <td><span class="td-mono">#ACC-<?= str_pad($assign['accident_id'],4,'0',STR_PAD_LEFT) ?></span></td>
              <td style="font-size:.65rem"><?= htmlspecialchars(substr($assign['location_description'] ?? 'Unknown',0,25)) ?></td>
              <td style="font-weight:700;color:<?= $assign['injuries'] >= 4 ? 'var(--critical)' : 'var(--high)' ?>">
                <?= $assign['injuries'] ?> patients
              </td>
              <td>
                <span class="badge <?= $assign['status'] ?>">
                  <span class="badge-dot"></span> <?= str_replace('_', ' ', ucfirst($assign['status'])) ?>
                </span>
              </td>
              <td style="font-size:.6rem;color:var(--text-muted)"><?= date('H:i d/m/Y', strtotime($assign['assigned_at'])) ?></td>
              <td style="text-align:right;white-space:nowrap">
                <!-- Status Update Form - REMOVED 'completed' -->
                <form method="POST" style="display:inline-flex;gap:3px;align-items:center;flex-wrap:wrap">
                  <input type="hidden" name="assignment_id" value="<?= $assign['id'] ?>">
                  <select name="assignment_status" style="font-size:.5rem;padding:.1rem .3rem;border-radius:4px;border:1px solid var(--border)">
                    <option value="on_mission" <?= $assign['status'] == 'on_mission' ? 'selected' : '' ?>>🚑 En Route</option>
                    <option value="arrived" <?= $assign['status'] == 'arrived' ? 'selected' : '' ?>>📍 Arrived</option>
                    <option value="returned" <?= $assign['status'] == 'returned' ? 'selected' : '' ?>>🔄 Returned</option>
                  </select>
                  <button type="submit" name="update_assignment" class="btn btn-primary btn-sm">
                    <i class="fas fa-save"></i>
                  </button>
                </form>
                
                <!-- 🔴 Quick Action: Return Button (when arrived) -->
                <?php if($assign['status'] == 'arrived'): ?>
                <form method="POST" style="display:inline">
                  <input type="hidden" name="assignment_id" value="<?= $assign['id'] ?>">
                  <input type="hidden" name="assignment_status" value="returned">
                  <button type="submit" name="update_assignment" class="btn btn-success btn-sm" title="Mark as Returned - Patient Received">
                    <i class="fas fa-undo-alt"></i> Return
                  </button>
                </form>
                <?php endif; ?>
                
                <!-- Navigate Button -->
                <?php if($assign['latitude'] && $assign['longitude']): ?>
                <a href="https://www.google.com/maps/dir/<?= $hospital_lat ?>,<?= $hospital_lng ?>/<?= $assign['latitude'] ?>,<?= $assign['longitude'] ?>" 
                   target="_blank" 
                   class="btn btn-success btn-sm"
                   title="Navigate to this emergency">
                  <i class="fas fa-directions"></i>
                </a>
                <?php endif; ?>
              </td>
            </tr>
          <?php endwhile; else: ?>
            <tr><td colspan="8" style="text-align:center;padding:1.5rem;color:var(--text-muted)">
              <i class="fas fa-check-circle" style="font-size:1.5rem;display:block;opacity:.3;margin-bottom:.3rem"></i>
              No active ambulance missions
            </td></tr>
          <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <!-- STATUS FLOW GUIDE - Removed Completed -->
    <div style="display:grid;grid-template-columns:repeat(5,1fr);gap:.5rem;margin-top:1rem;padding:.8rem;background:var(--white);border:1px solid var(--border);border-radius:var(--radius)">
      <div style="text-align:center;padding:.3rem;background:rgba(239,68,68,.05);border-radius:8px;border-left:3px solid var(--critical)">
        <div style="font-size:.45rem;color:var(--text-muted);text-transform:uppercase;font-weight:600">Step 1</div>
        <div style="font-size:.65rem;font-weight:600;color:var(--critical)">Assigned</div>
        <div style="font-size:.45rem;color:var(--text-muted)">Case assigned</div>
      </div>
      <div style="text-align:center;padding:.3rem;background:rgba(249,115,22,.05);border-radius:8px;border-left:3px solid var(--high)">
        <div style="font-size:.45rem;color:var(--text-muted);text-transform:uppercase;font-weight:600">Step 2</div>
        <div style="font-size:.65rem;font-weight:600;color:var(--high)">Preparing</div>
        <div style="font-size:.45rem;color:var(--text-muted)">Ambulance prep</div>
      </div>
      <div style="text-align:center;padding:.3rem;background:rgba(14,165,233,.05);border-radius:8px;border-left:3px solid var(--secondary)">
        <div style="font-size:.45rem;color:var(--text-muted);text-transform:uppercase;font-weight:600">Step 3</div>
        <div style="font-size:.65rem;font-weight:600;color:var(--secondary)">Dispatched</div>
        <div style="font-size:.45rem;color:var(--text-muted)">En route</div>
      </div>
      <div style="text-align:center;padding:.3rem;background:rgba(234,179,8,.05);border-radius:8px;border-left:3px solid var(--moderate)">
        <div style="font-size:.45rem;color:var(--text-muted);text-transform:uppercase;font-weight:600">Step 4</div>
        <div style="font-size:.65rem;font-weight:600;color:var(--moderate)">Received</div>
        <div style="font-size:.45rem;color:var(--text-muted)">At hospital</div>
      </div>
      <div style="text-align:center;padding:.3rem;background:rgba(16,185,129,.05);border-radius:8px;border-left:3px solid var(--success)">
        <div style="font-size:.45rem;color:var(--text-muted);text-transform:uppercase;font-weight:600">Step 5</div>
        <div style="font-size:.65rem;font-weight:600;color:var(--success)">Treated</div>
        <div style="font-size:.45rem;color:var(--text-muted)">Patient treated</div>
      </div>
    </div>

  </div><!-- /content -->

  <div class="footer">
    <span>🏥 <?= htmlspecialchars($hospital_station) ?>&nbsp;·&nbsp;Assign Ambulance & Navigate Through Geogle Map</span>
    <span class="dual-status-badge"><i class="fas fa-shield-alt"></i>  Ambulance Dispatch </span>
    <span id="clock"></span>
  </div>
</div><!-- /main -->
</div><!-- /shell -->

<!-- ============================================ -->
<!-- MODALS -->
<!-- ============================================ -->

<!-- Add Ambulance Modal -->
<div class="modal-bg" id="addAmbulanceModal">
  <div class="modal">
    <button class="modal-close" onclick="closeModal('addAmbulanceModal')"><i class="fas fa-times"></i></button>
    <div class="modal-title"><i class="fas fa-plus-circle"></i> Add New Ambulance</div>
    <div class="modal-sub">Register a new ambulance for <?= htmlspecialchars($hospital_station) ?></div>
    <form method="POST">
      <div class="form-group">
        <label>Ambulance Name *</label>
        <input type="text" name="ambulance_name" placeholder="e.g., AMB-01" required>
      </div>
      <div class="form-group">
        <label>Plate Number *</label>
        <input type="text" name="plate_number" placeholder="e.g., T123 ABC" required>
      </div>
      <div class="form-row">
        <div class="form-group">
          <label>Driver Name *</label>
          <input type="text" name="driver_name" placeholder="John Doe" required>
        </div>
        <div class="form-group">
          <label>Driver Phone *</label>
          <input type="text" name="driver_phone" placeholder="+255 712 345 678" required>
        </div>
      </div>
      <div class="form-group">
        <label>Driver License Number</label>
        <input type="text" name="driver_license" placeholder="DL123456">
      </div>
      <div class="form-row">
        <div class="form-group">
          <label>Model</label>
          <input type="text" name="model" placeholder="e.g., Toyota Hiace">
        </div>
        <div class="form-group">
          <label>Type *</label>
          <select name="type" required>
            <option value="basic">Basic</option>
            <option value="advanced">Advanced</option>
            <option value="icu">ICU</option>
          </select>
        </div>
      </div>
      <div class="form-group">
        <label>Patient Capacity</label>
        <input type="number" name="capacity" value="2" min="1" max="10">
      </div>
      <button type="submit" name="add_ambulance" class="btn btn-primary btn-block">
        <i class="fas fa-plus"></i> Add Ambulance
      </button>
    </form>
  </div>
</div>

<!-- Edit Ambulance Modal -->
<div class="modal-bg" id="editAmbulanceModal">
  <div class="modal">
    <button class="modal-close" onclick="closeModal('editAmbulanceModal')"><i class="fas fa-times"></i></button>
    <div class="modal-title"><i class="fas fa-edit"></i> Edit Ambulance</div>
    <div class="modal-sub">Update ambulance details</div>
    <form method="POST" id="editAmbulanceForm">
      <input type="hidden" name="ambulance_id" id="editAmbulanceId">
      <div class="form-group">
        <label>Ambulance Name *</label>
        <input type="text" name="ambulance_name" id="editAmbulanceName" required>
      </div>
      <div class="form-group">
        <label>Plate Number *</label>
        <input type="text" name="plate_number" id="editPlateNumber" required>
      </div>
      <div class="form-row">
        <div class="form-group">
          <label>Driver Name *</label>
          <input type="text" name="driver_name" id="editDriverName" required>
        </div>
        <div class="form-group">
          <label>Driver Phone *</label>
          <input type="text" name="driver_phone" id="editDriverPhone" required>
        </div>
      </div>
      <div class="form-group">
        <label>Driver License Number</label>
        <input type="text" name="driver_license" id="editDriverLicense">
      </div>
      <div class="form-row">
        <div class="form-group">
          <label>Model</label>
          <input type="text" name="model" id="editModel">
        </div>
        <div class="form-group">
          <label>Type *</label>
          <select name="type" id="editType" required>
            <option value="basic">Basic</option>
            <option value="advanced">Advanced</option>
            <option value="icu">ICU</option>
          </select>
        </div>
      </div>
      <div class="form-group">
        <label>Patient Capacity</label>
        <input type="number" name="capacity" id="editCapacity" min="1" max="10">
      </div>
      <button type="submit" name="edit_ambulance" class="btn btn-primary btn-block">
        <i class="fas fa-save"></i> Update Ambulance
      </button>
    </form>
  </div>
</div>

<!-- Assign Ambulance Modal -->
<div class="modal-bg" id="assignModal">
  <div class="modal">
    <button class="modal-close" onclick="closeModal('assignModal')"><i class="fas fa-times"></i></button>
    <div class="modal-title"><i class="fas fa-crosshairs"></i> Assign Ambulance to Emergency</div>
    <div class="modal-sub" id="assignSubtitle">Assign <span id="assignAmbulanceName"></span> to an emergency</div>
    <form method="POST">
      <input type="hidden" name="ambulance_id" id="assignAmbulanceId">
      <div class="form-group">
        <label>Select Emergency *</label>
        <select name="accident_id" required>
          <option value="">-- Choose emergency --</option>
          <?php if($pending_accidents && $pending_accidents->num_rows > 0):
            while($acc = $pending_accidents->fetch_assoc()): ?>
            <option value="<?= $acc['id'] ?>">
              #ACC-<?= str_pad($acc['id'],4,'0',STR_PAD_LEFT) ?> - <?= htmlspecialchars(substr($acc['accident_type'] ?? 'Unknown',0,30)) ?> 
              (🤕 <?= $acc['injuries'] ?> injured)
            </option>
          <?php endwhile; else: ?>
            <option value="" disabled>No pending emergencies available</option>
          <?php endif; ?>
        </select>
      </div>
      <button type="submit" name="assign_ambulance" class="btn btn-success btn-block" <?= ($pending_accidents && $pending_accidents->num_rows > 0) ? '' : 'disabled' ?>>
        <i class="fas fa-crosshairs"></i> Assign Ambulance
      </button>
    </form>
  </div>
</div>

<script>
// ── CLOCK ──
function tick(){const n=new Date();document.getElementById('clock').textContent=
  n.toLocaleDateString('en-GB',{weekday:'short',day:'2-digit',month:'short'})+
  ' · '+n.toLocaleTimeString('en-GB',{hour:'2-digit',minute:'2-digit',second:'2-digit'})}
setInterval(tick,1000);tick();

// ── MAP DATA ──
const MAP_DATA = <?= json_encode($map_data) ?>;
const HOSPITAL_LAT = <?= $hospital_lat ?? 0 ?>;
const HOSPITAL_LNG = <?= $hospital_lng ?? 0 ?>;
const HOSPITAL_NAME = "<?= addslashes($hospital_station) ?>";

let map, selectedAccidentId = <?= $accident_id ?: 0 ?>;

// ── MODAL FUNCTIONS ──
function openModal(id){document.getElementById(id).classList.add('show');}
function closeModal(id){document.getElementById(id).classList.remove('show');}

// ── TOGGLE NOTIFICATION ──
function toggleNotif(){
  const d=document.getElementById('notifDrop');
  d.classList.toggle('show');
  if(d.classList.contains('show')) closeProfileDrop();
}

// ── PROFILE DROPDOWN ──
function toggleProfileDrop(){
  const btn=document.getElementById('profileBtn');
  const drop=document.getElementById('profileDrop');
  const open=drop.classList.toggle('show');
  btn.classList.toggle('open',open);
  if(open) document.getElementById('notifDrop').classList.remove('show');
}
function closeProfileDrop(){
  document.getElementById('profileDrop').classList.remove('show');
  document.getElementById('profileBtn').classList.remove('open');
}

// ── EDIT AMBULANCE ──
function editAmbulance(id) {
    console.log('✏️ Editing ambulance ID:', id);
    showToast('⏳ Loading', 'Fetching ambulance details...', 'info');
    
    fetch('get_ambulance.php?id=' + id)
        .then(response => {
            if (!response.ok) {
                throw new Error('HTTP error! status: ' + response.status);
            }
            return response.json();
        })
        .then(data => {
            console.log('📦 Ambulance data:', data);
            
            if(data.error) {
                showToast('❌ Error', data.error, 'error');
                return;
            }
            
            // Populate the edit form
            document.getElementById('editAmbulanceId').value = data.id || '';
            document.getElementById('editAmbulanceName').value = data.ambulance_name || '';
            document.getElementById('editPlateNumber').value = data.plate_number || '';
            document.getElementById('editDriverName').value = data.driver_name || '';
            document.getElementById('editDriverPhone').value = data.driver_phone || '';
            document.getElementById('editDriverLicense').value = data.driver_license || '';
            document.getElementById('editModel').value = data.model || '';
            document.getElementById('editType').value = data.type || 'basic';
            document.getElementById('editCapacity').value = data.capacity || 2;
            
            // Open the modal
            openModal('editAmbulanceModal');
            showToast('✅ Success', 'Ambulance data loaded', 'success');
        })
        .catch(error => {
            console.error('❌ Fetch error:', error);
            showToast('❌ Error', 'Failed to load ambulance: ' + error.message, 'error');
        });
}

// ── OPEN ASSIGN MODAL ──
function openAssignModal(ambulanceId, ambulanceName) {
    document.getElementById('assignAmbulanceId').value = ambulanceId;
    document.getElementById('assignAmbulanceName').textContent = ambulanceName;
    openModal('assignModal');
}

// ── SELECT ACCIDENT ──
function selectAccident(id) {
    if(!id) return;
    selectedAccidentId = parseInt(id);
    const acc = MAP_DATA.find(a => parseInt(a.id) === selectedAccidentId);
    if(acc && map) {
        map.setView([acc.lat, acc.lng], 14);
    }
}

// ── FILTER ──
function filterAccidents(q) {
    const select = document.getElementById('accidentSelect');
    if(!select) return;
    const options = select.options;
    q = q.toLowerCase();
    for(let i=1; i<options.length; i++) {
        options[i].style.display = options[i].text.toLowerCase().includes(q) ? '' : 'none';
    }
}

// ── TOAST ──
function showToast(title,msg,type){
  const w=document.getElementById('toastWrap');
  const icon=type==='success'||type==='ok'?'✅':type==='error'||type==='err'?'⚠️':'ℹ️';
  const el=document.createElement('div');
  el.className='toast '+(type==='success'?'success':type==='error'?'error':'');
  el.innerHTML=`<div class="t-ic">${icon}</div>
    <div class="t-body"><div class="t-title">${title}</div><div class="t-msg">${msg}</div></div>
    <button class="t-x" onclick="this.closest('.toast').remove()">✕</button>`;
  w.appendChild(el);
  setTimeout(()=>{ if(el.parentNode) el.remove(); },5000);
}

// ── INIT MAP ──
function initMap() {
    const center = (MAP_DATA.length > 0 && selectedAccidentId > 0) 
        ? MAP_DATA.find(a => parseInt(a.id) === selectedAccidentId) 
        : null;
    const lat = center ? center.lat : (HOSPITAL_LAT || -6.7924);
    const lng = center ? center.lng : (HOSPITAL_LNG || 39.2083);
    
    map = L.map('ambulanceMap').setView([lat, lng], 13);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '&copy; OpenStreetMap'
    }).addTo(map);
    L.control.scale({metric:true,imperial:false}).addTo(map);

    // Hospital marker
    if(HOSPITAL_LAT && HOSPITAL_LNG) {
        const hIcon = L.divIcon({
            html: `<div style="width:28px;height:28px;border-radius:50%;background:#2563EB;border:3px solid #fff;display:flex;align-items:center;justify-content:center;box-shadow:0 2px 10px rgba(37,99,235,0.5);">
                    <i class="fas fa-hospital" style="color:white;font-size:14px;"></i>
                   </div>`,
            iconSize:[28,28], iconAnchor:[14,14], className:''
        });
        L.marker([HOSPITAL_LAT, HOSPITAL_LNG], {icon:hIcon}).addTo(map)
            .bindPopup(`<div style="font-weight:700;color:#2563EB;">🏥 Hospital</div><div style="font-size:.7rem;color:#64748B;">${HOSPITAL_NAME}</div>`);
    }

    // Accident markers with navigation
    MAP_DATA.forEach(acc => {
        let color;
        if(acc.status === 'assigned') color = '#F43F5E';
        else if(acc.status === 'ambulance_dispatched') color = '#22D3EE';
        else if(acc.status === 'treated') color = '#10B981';
        else color = '#94A3B8';
        
        const icon = L.divIcon({
            html: `<div style="width:18px;height:18px;border-radius:50%;background:${color};border:2.5px solid #fff;box-shadow:0 2px 8px rgba(0,0,0,.35);display:flex;align-items:center;justify-content:center;">
                    <i class="fas fa-car-crash" style="color:#fff;font-size:8px;"></i>
                   </div>`,
            iconSize:[18,18], iconAnchor:[9,9], className:''
        });
        
        let popupHtml = `
            <div style="font-family:'Inter',sans-serif;min-width:220px;padding:5px;">
                <div style="font-weight:700;font-size:.85rem;color:#2563EB;">
                    🚨 #ACC-${String(acc.id).padStart(4,'0')}
                </div>
                <div style="font-size:.7rem;">${acc.type}</div>
                <div style="font-size:.65rem;color:#64748B;">${acc.location}</div>
                <div style="font-size:.65rem;margin:3px 0;">🤕 ${acc.injuries} injured · ${acc.status}</div>`;
        
        if(acc.ambulance) {
            popupHtml += `
                <div style="margin:4px 0;padding:4px;background:#f0f9ff;border-radius:6px;font-size:.65rem;">
                    <span style="font-weight:600;">🚑 Ambulance:</span> ${acc.ambulance.name}<br>
                    <span style="font-weight:600;">👤 Driver:</span> ${acc.ambulance.driver}
                </div>
                <a href="https://www.google.com/maps/dir/${HOSPITAL_LAT},${HOSPITAL_LNG}/${acc.lat},${acc.lng}" 
                   target="_blank" 
                   style="display:block;text-align:center;background:#10B981;color:white;padding:4px;border-radius:6px;text-decoration:none;font-size:.65rem;font-weight:600;margin-top:4px;">
                    <i class="fas fa-directions"></i> Navigate to Emergency
                </a>`;
        }
        popupHtml += `</div>`;
        
        L.marker([acc.lat, acc.lng], {icon}).addTo(map).bindPopup(popupHtml);
    });

    // Auto-select first accident
    if(MAP_DATA.length > 0 && !selectedAccidentId) {
        setTimeout(() => selectAccident(MAP_DATA[0].id), 500);
    }
}

// ── CLOSE ON OUTSIDE ──
document.addEventListener('click',function(e){
  if(!e.target.closest('#notifDrop')&&!e.target.closest('.notif-btn'))
    document.getElementById('notifDrop').classList.remove('show');
  if(!e.target.closest('#profileDrop')&&!e.target.closest('#profileBtn'))
    closeProfileDrop();
  if(e.target.classList.contains('modal-bg')) e.target.classList.remove('show');
});

// ── ESCAPE KEY ──
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        document.querySelectorAll('.modal-bg.show').forEach(m => m.classList.remove('show'));
    }
});

// ── INIT ──
document.addEventListener('DOMContentLoaded', function() {
    console.log('✅ DOM loaded, initializing map...');
    initMap();
});

// ── TOAST FROM URL ──
<?php if(isset($_GET['msg'])): ?>
const msgs = {
    'ambulance_added': {title: '✅ Success', msg: 'Ambulance added successfully!', type: 'success'},
    'ambulance_updated': {title: '✅ Success', msg: 'Ambulance updated successfully!', type: 'success'},
    'status_updated': {title: '✅ Success', msg: 'Ambulance status updated!', type: 'success'},
    'assigned': {title: '✅ Success', msg: 'Ambulance assigned to emergency successfully!', type: 'success'},
    'assignment_updated': {title: '✅ Success', msg: 'Assignment status updated!', type: 'success'},
    'deleted': {title: '✅ Success', msg: 'Ambulance deleted successfully!', type: 'success'},
    'cannot_delete': {title: '⚠️ Warning', msg: 'Cannot delete ambulance with active mission!', type: 'error'}
};
const msg = '<?= $_GET['msg'] ?>';
if(msgs[msg]) showToast(msgs[msg].title, msgs[msg].msg, msgs[msg].type);
<?php endif; ?>
</script>

</body>
</html>