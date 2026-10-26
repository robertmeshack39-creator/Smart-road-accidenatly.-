<?php
// hospital/emergencies.php - All Emergencies Assigned to Hospital.
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
    SELECT u.*, hs.id as station_id, hs.name as station_name, hs.location, hs.phone as station_phone
    FROM users u
    LEFT JOIN hospital_stations hs ON u.hospital_station_id = hs.id
    WHERE u.id = $hospital_id
");
$user = $user_query->fetch_assoc();
$hospital_station = $user['station_name'] ?? 'Hospital Station';
$hospital_station_id = $user['station_id'] ?? null;

// ============================================
// 🔴 HANDLE STATUS UPDATE - WITH AUDIT LOG
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_status'])) {
    $accident_id = intval($_POST['accident_id']);
    $status = mysqli_real_escape_string($conn, $_POST['status']);
    
    // Allowed hospital statuses
    $allowed_status = ['assigned', 'preparing', 'ambulance_dispatched', 'patient_received', 'treated'];
    
    if (in_array($status, $allowed_status)) {
        // Get accident details before update
        $accident = $conn->query("
            SELECT accident_type, location_description, hospital_status 
            FROM accidents 
            WHERE id = $accident_id AND assigned_hospital_id = $hospital_station_id
        ")->fetch_assoc();
        
        if ($accident) {
            $old_status = $accident['hospital_status'];
            
            $conn->query("UPDATE accidents SET hospital_status = '$status' WHERE id = $accident_id AND assigned_hospital_id = $hospital_station_id");
            
            // 🔴 AUDIT LOG - HOSPITAL STATUS UPDATE
            createAuditLog($conn, 'Updated hospital status from ' . $old_status . ' to ' . $status . ' | ACC-' . str_pad($accident_id, 4, '0', STR_PAD_LEFT) . ' | Type: ' . $accident['accident_type']);
            
            header("Location: emergencies.php?msg=updated");
            exit();
        }
    }
}

// ============================================
// GET FILTER PARAMETERS
// ============================================
$search = $_GET['search'] ?? '';
$status_filter = $_GET['status'] ?? 'all';
$page = $_GET['page'] ?? 1;
$limit = 15;
$offset = ($page - 1) * $limit;

// ============================================
// 🔴 BUILD QUERY - Using hospital_status
// ============================================
$where_conditions = ["assigned_hospital_id = $hospital_station_id"];
if (!empty($search)) {
    $where_conditions[] = "(reporter_name LIKE '%$search%' OR reporter_phone LIKE '%$search%' OR location_description LIKE '%$search%')";
}
if ($status_filter != 'all') {
    $where_conditions[] = "hospital_status = '$status_filter'";
}
$where_sql = "WHERE " . implode(" AND ", $where_conditions);

// ============================================
// GET TOTAL RECORDS
// ============================================
$total_query = "SELECT COUNT(*) as total FROM accidents $where_sql";
$total_result = $conn->query($total_query);
$total_records = $total_result->fetch_assoc()['total'];
$total_pages = ceil($total_records / $limit);

// ============================================
// 🔴 GET EMERGENCIES DATA - Using hospital_status
// ============================================
$query = "SELECT 
            a.*,
            ps.name as police_station_name,
            a.hospital_status as status
          FROM accidents a
          LEFT JOIN police_stations ps ON a.assigned_police_id = ps.id
          $where_sql 
          ORDER BY a.created_at DESC 
          LIMIT $limit OFFSET $offset";
$emergencies = $conn->query($query);

// ============================================
// 🔴 GET STATISTICS - Using hospital_status
// ============================================
$stats_query = $conn->query("
    SELECT 
        (SELECT COUNT(*) FROM accidents WHERE assigned_hospital_id = $hospital_station_id AND hospital_status = 'assigned') as pending_count,
        (SELECT COUNT(*) FROM accidents WHERE assigned_hospital_id = $hospital_station_id AND hospital_status = 'ambulance_dispatched') as dispatched_count,
        (SELECT COUNT(*) FROM accidents WHERE assigned_hospital_id = $hospital_station_id AND hospital_status = 'treated') as treated_count,
        (SELECT COUNT(*) FROM accidents WHERE assigned_hospital_id = $hospital_station_id AND hospital_status = 'patient_received') as received_count,
        (SELECT COUNT(*) FROM accidents WHERE assigned_hospital_id = $hospital_station_id) as total_count
");
$stats = $stats_query->fetch_assoc();

// ============================================
// 🔴 GET PENDING NOTIFICATIONS - hospital_status = 'assigned'
// ============================================
$pending_notifications = $conn->query("
    SELECT id, accident_type, location_description, created_at, reporter_name, injuries, hospital_status as status
    FROM accidents 
    WHERE assigned_hospital_id = $hospital_station_id 
    AND hospital_status = 'assigned'
    ORDER BY created_at DESC LIMIT 10
");
$pending_count = $pending_notifications ? $pending_notifications->num_rows : 0;

// ============================================
// 🔴 GET CLOSED COUNT FOR SIDEBAR BADGE
// ============================================
$closed_q = $conn->query("
    SELECT COUNT(*) as c 
    FROM accidents 
    WHERE assigned_hospital_id = $hospital_station_id 
    AND hospital_status = 'closed'
");
$closed_row = $closed_q ? $closed_q->fetch_assoc() : null;
$closed_count = intval($closed_row['c'] ?? 0);

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
<title>Emergency Cases — <?= htmlspecialchars($hospital_station) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&family=Poppins:wght@300;400;500;600;700;800&family=JetBrains+Mono:wght@400;500;600;700&display=swap" rel="stylesheet"/>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"/>
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
::-webkit-scrollbar-thumb:hover{background:var(--primary-dark)}

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
  text-decoration:none;flex-shrink:0;position:relative;z-index:2}
.sb-logo{width:40px;height:40px;border-radius:10px;flex-shrink:0;
  background:linear-gradient(135deg,var(--primary),var(--secondary));
  display:flex;align-items:center;justify-content:center;font-size:1.1rem;
  color:white;box-shadow:0 0 20px rgba(37,99,235,0.3)}
.sb-title{font-family:var(--font-display);font-size:1.05rem;font-weight:800;
  letter-spacing:-.01em;color:var(--text)}
.sb-sub{font-size:.55rem;color:var(--text-muted);letter-spacing:.14em;
  text-transform:uppercase;font-family:var(--font-mono);margin-top:1px}

.sb-nav{flex:1;overflow-y:auto;padding:.65rem 0;position:relative;z-index:2}
.sb-sect{font-family:var(--font-mono);font-size:.56rem;
  letter-spacing:.16em;text-transform:uppercase;color:var(--text-muted);
  padding:.6rem 1.25rem .25rem}
.sb-item{display:flex;align-items:center;gap:.75rem;
  padding:.55rem 1rem;margin:1px .55rem;border-radius:9px;
  text-decoration:none;font-size:.78rem;font-weight:500;color:var(--text-muted);
  transition:background .18s,color .18s;
  cursor:pointer;border:none;background:transparent;
  width:calc(100% - 1.1rem);text-align:left;
  font-family:var(--font-primary);position:relative}
.sb-ic{width:28px;height:28px;border-radius:7px;flex-shrink:0;
  display:flex;align-items:center;justify-content:center;font-size:.8rem;
  background:rgba(37,99,235,.06);transition:background .18s,color .18s}
.sb-item:hover{background:rgba(37,99,235,.06);color:var(--text)}
.sb-item:hover .sb-ic{background:rgba(37,99,235,.12);color:var(--primary)}
.sb-item.active{background:rgba(37,99,235,.08);color:var(--primary)}
.sb-item.active .sb-ic{background:rgba(37,99,235,.15);color:var(--primary)}
.sb-item.active::before{content:'';position:absolute;left:0;top:50%;
  transform:translateY(-50%);width:3px;height:56%;
  background:var(--primary);border-radius:0 3px 3px 0}
.sb-badge{margin-left:auto;font-size:.55rem;font-weight:700;
  padding:.12rem .4rem;border-radius:999px;
  font-family:var(--font-mono)}
.sb-badge.red{background:rgba(239,68,68,.12);color:var(--critical);
  border:1px solid rgba(239,68,68,.2);animation:badgePulse 2s infinite}
.sb-badge.grn{background:rgba(16,185,129,.12);color:var(--success);
  border:1px solid rgba(16,185,129,.2)}
@keyframes badgePulse{0%,100%{box-shadow:0 0 0 0 rgba(239,68,68,.25)}
  50%{box-shadow:0 0 0 4px rgba(239,68,68,0)}}

.sb-logout-item{
  display:flex;align-items:center;gap:.75rem;
  padding:.55rem 1rem;margin:1px .55rem;border-radius:9px;
  font-size:.78rem;font-weight:500;color:var(--text-muted);
  transition:background .18s,color .18s;
  cursor:pointer;border:none;background:transparent;
  width:calc(100% - 1.1rem);text-align:left;
  font-family:var(--font-primary);text-decoration:none}
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
.tb-btn.primary{background:var(--primary);border-color:var(--primary);
  color:white;font-weight:600;}
.tb-btn.primary:hover{background:var(--primary-dark);color:white;}

/* ══════════════════════ CONTENT ══════════════════════ */
.content{flex:1;overflow-y:auto;padding:1.2rem 1.5rem}

/* ─── STAT CARDS ─── */
.stats-row{display:grid;grid-template-columns:repeat(5,1fr);gap:1rem;margin-bottom:1.1rem}
.sc{background:var(--white);border:1px solid var(--border);border-radius:var(--radius);
  padding:1rem 1.2rem;position:relative;overflow:hidden;
  transition:border-color .22s,transform .18s,box-shadow .22s;
  box-shadow:var(--shadow)}
.sc:hover{border-color:var(--primary);transform:translateY(-2px);
  box-shadow:var(--shadow-hover)}
.sc::after{content:'';position:absolute;top:0;left:0;right:0;height:2px;
  opacity:0;transition:opacity .25s}
.sc:hover::after{opacity:1}
.sc.c1::after{background:linear-gradient(90deg,var(--critical),transparent)}
.sc.c2::after{background:linear-gradient(90deg,var(--secondary),transparent)}
.sc.c3::after{background:linear-gradient(90deg,var(--moderate),transparent)}
.sc.c4::after{background:linear-gradient(90deg,var(--success),transparent)}
.sc.c5::after{background:linear-gradient(90deg,var(--primary),transparent)}
.sc-ico{width:34px;height:34px;border-radius:8px;
  display:flex;align-items:center;justify-content:center;
  font-size:.8rem;margin-bottom:.7rem}
.c1 .sc-ico{background:rgba(239,68,68,.1);color:var(--critical)}
.c2 .sc-ico{background:rgba(14,165,233,.1);color:var(--secondary)}
.c3 .sc-ico{background:rgba(234,179,8,.1);color:var(--moderate)}
.c4 .sc-ico{background:rgba(16,185,129,.1);color:var(--success)}
.c5 .sc-ico{background:rgba(37,99,235,.1);color:var(--primary)}
.sc-num{font-family:var(--font-display);font-size:1.6rem;
  font-weight:800;line-height:1;margin-bottom:.15rem}
.c1 .sc-num{color:var(--critical)}
.c2 .sc-num{color:var(--secondary)}
.c3 .sc-num{color:var(--moderate)}
.c4 .sc-num{color:var(--success)}
.c5 .sc-num{color:var(--primary)}
.sc-lbl{font-size:.55rem;color:var(--text-muted);letter-spacing:.08em;text-transform:uppercase}
.sc-tag{position:absolute;top:1rem;right:1rem;
  font-size:.5rem;font-family:var(--font-mono);color:var(--text-muted)}

/* ─── FILTER BAR ─── */
.filter-bar{background:var(--white);border:1px solid var(--border);border-radius:var(--radius);
  padding:.6rem 1rem;display:flex;flex-wrap:wrap;gap:.4rem;margin-bottom:1.1rem;align-items:center;
  box-shadow:var(--shadow)}
.filter-bar a{padding:.35rem .8rem;border-radius:8px;font-size:.65rem;
  font-weight:600;color:var(--text-muted);text-decoration:none;
  transition:background .15s,color .15s}
.filter-bar a:hover{background:var(--bg3);color:var(--text)}
.filter-bar a.active{background:var(--primary);color:white}
.filter-bar .count{margin-left:auto;font-size:.6rem;color:var(--text-muted)}

/* ─── TABLE ─── */
.panel{background:var(--white);border:1px solid var(--border);
  border-radius:var(--radius);overflow:hidden;display:flex;flex-direction:column;
  box-shadow:var(--shadow)}
.ph{display:flex;align-items:center;justify-content:space-between;
  padding:.7rem 1.15rem;border-bottom:1px solid var(--border);flex-shrink:0}
.ph-t{font-family:var(--font-display);font-size:.95rem;font-weight:700;color:var(--text)}
.ph-t i{margin-right:.5rem;color:var(--primary)}
.ph-s{font-size:.62rem;color:var(--text-muted);margin-top:.1rem}
.ph-a{font-size:.65rem;color:var(--primary);text-decoration:none;
  cursor:pointer;background:none;border:none;
  font-family:var(--font-primary);transition:opacity .18s}
.ph-a:hover{opacity:.7}

.tbl-wrap{overflow-x:auto;flex:1}
table{width:100%;border-collapse:collapse;font-size:.72rem}
thead th{padding:.45rem .75rem;font-size:.55rem;letter-spacing:.1em;
  text-transform:uppercase;color:var(--text-muted);font-weight:600;
  border-bottom:1px solid var(--border);white-space:nowrap;text-align:left}
tbody tr{border-bottom:1px solid rgba(0,0,0,.04);transition:background .12s}
tbody tr:hover{background:rgba(37,99,235,.02)}
tbody td{padding:.45rem .75rem;color:var(--text);vertical-align:middle}
.td-id{font-family:var(--font-mono);font-size:.65rem;color:var(--text-muted)}
.td-loc{font-size:.65rem;color:var(--text-muted);max-width:140px;
  overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.td-time{font-size:.6rem;color:var(--text-muted);white-space:nowrap}
.td-inj{font-family:var(--font-mono);font-weight:700;text-align:center}

/* ─── IMAGE THUMBNAIL ─── */
.thumbnail-img{width:35px;height:35px;border-radius:6px;object-fit:cover;
  border:2px solid var(--border);cursor:pointer;transition:all 0.2s ease}
.thumbnail-img:hover{transform:scale(2);z-index:999;border-color:var(--primary);
  box-shadow:0 4px 20px rgba(37,99,235,0.3)}
.image-placeholder{display:inline-flex;width:35px;height:35px;background:var(--bg3);
  border-radius:6px;border:1px dashed var(--border);align-items:center;
  justify-content:center;color:var(--text-muted);font-size:.6rem}

/* ─── HOSPITAL STATUS BADGES ─── */
.badge{display:inline-flex;align-items:center;gap:.2rem;
  padding:.15rem .5rem;border-radius:999px;
  font-family:var(--font-mono);font-size:.55rem;
  font-weight:600;text-transform:uppercase;white-space:nowrap}
.badge.assigned{background:rgba(239,68,68,.1);color:var(--critical);
  border:1px solid rgba(239,68,68,.2)}
.badge.preparing{background:rgba(249,115,22,.1);color:var(--high);
  border:1px solid rgba(249,115,22,.2)}
.badge.ambulance_dispatched{background:rgba(14,165,233,.1);color:var(--secondary);
  border:1px solid rgba(14,165,233,.2)}
.badge.patient_received{background:rgba(234,179,8,.1);color:var(--moderate);
  border:1px solid rgba(234,179,8,.2)}
.badge.treated{background:rgba(16,185,129,.1);color:var(--success);
  border:1px solid rgba(16,185,129,.2)}
.badge.closed{background:rgba(100,116,139,.1);color:var(--text-muted);
  border:1px solid rgba(100,116,139,.2)}
.badge-dot{width:4px;height:4px;border-radius:50%;background:currentColor}

.severity-badge{display:inline-flex;align-items:center;gap:.3rem;
  padding:.12rem .4rem;border-radius:999px;font-size:.55rem;font-weight:600}
.severity-badge.critical{background:rgba(239,68,68,.1);color:var(--critical)}
.severity-badge.high{background:rgba(249,115,22,.1);color:var(--high)}
.severity-badge.moderate{background:rgba(234,179,8,.1);color:var(--moderate)}
.severity-badge.low{background:rgba(34,197,94,.1);color:var(--low)}

.btn-view{background:var(--primary);border:1px solid var(--primary);
  color:white;padding:.15rem .5rem;border-radius:6px;font-size:.55rem;
  font-weight:600;cursor:pointer;transition:all .15s;font-family:var(--font-mono)}
.btn-view:hover{background:var(--primary-dark);transform:scale(1.05)}

/* 🔴 STATUS UPDATE - FIXED STYLES */
.status-update-form{display:inline-flex;align-items:center;gap:4px}
.status-update-form select{background:var(--bg3);border:1px solid var(--border);border-radius:6px;
  padding:.15rem .4rem;font-family:var(--font-mono);font-size:.55rem;
  color:var(--text);cursor:pointer;outline:none;transition:border-color .18s}
.status-update-form select:focus{border-color:var(--primary);box-shadow:0 0 0 3px rgba(37,99,235,.1)}
.status-update-form button{background:var(--primary);border:none;border-radius:6px;
  padding:.15rem .4rem;color:white;font-size:.5rem;cursor:pointer;
  transition:background .15s;font-family:var(--font-mono)}
.status-update-form button:hover{background:var(--primary-dark)}

/* ─── PAGINATION ─── */
.pagination{display:flex;gap:.4rem;align-items:center}
.pagination a{padding:.25rem .6rem;border-radius:6px;font-size:.6rem;
  color:var(--text-muted);text-decoration:none;border:1px solid var(--border);
  transition:all .15s}
.pagination a:hover{background:var(--bg3);color:var(--primary);border-color:var(--primary)}
.pagination .active{background:var(--primary);color:white;border-color:var(--primary);font-weight:700}
.pagination .disabled{opacity:.3;cursor:not-allowed}

/* ─── MODAL ─── */
.modal-bg{position:fixed;inset:0;background:rgba(0,0,0,.5);
  backdrop-filter:blur(4px);z-index:6000;
  display:flex;align-items:center;justify-content:center;
  opacity:0;pointer-events:none;transition:opacity .22s}
.modal-bg.show{opacity:1;pointer-events:all}
.modal{background:var(--white);border-radius:18px;
  padding:1.8rem;width:100%;max-width:600px;
  box-shadow:0 24px 80px rgba(0,0,0,.2);
  transform:translateY(18px) scale(.97);
  transition:transform .22s;position:relative;max-height:95vh;overflow-y:auto}
.modal-bg.show .modal{transform:none}
.modal-close{position:absolute;top:.9rem;right:.9rem;
  background:rgba(0,0,0,.04);border:1px solid var(--border);
  border-radius:7px;width:27px;height:27px;
  display:flex;align-items:center;justify-content:center;
  font-size:.7rem;color:var(--text-muted);cursor:pointer;
  transition:background .18s,color .18s}
.modal-close:hover{background:rgba(239,68,68,.08);color:var(--critical)}
.modal-title{font-family:var(--font-display);font-size:1.2rem;font-weight:700;
  color:var(--text);margin-bottom:.25rem}
.modal-title i{margin-right:8px;color:var(--primary)}
.modal-sub{font-size:.7rem;color:var(--text-muted);margin-bottom:1rem}
.modal-detail{display:grid;grid-template-columns:1fr 1fr;gap:.8rem}
.modal-detail .item{background:var(--bg3);padding:.6rem .8rem;border-radius:8px;border:1px solid var(--border)}
.modal-detail .item .label{font-size:.55rem;text-transform:uppercase;color:var(--text-muted);letter-spacing:.05em}
.modal-detail .item .value{font-size:.8rem;font-weight:500;color:var(--text);margin-top:2px}
.modal-detail .item.full{grid-column:1/-1}

/* ─── TOAST ─── */
.toast-wrap{position:fixed;top:1rem;right:1.4rem;z-index:9999;
  display:flex;flex-direction:column;gap:.4rem;pointer-events:none}
.toast{display:flex;align-items:flex-start;gap:.6rem;
  background:var(--white);border:1px solid var(--border);
  border-left:3px solid var(--primary);border-radius:11px;
  padding:.6rem .8rem;min-width:240px;max-width:300px;
  box-shadow:0 8px 28px rgba(0,0,0,.12);
  animation:tIn .3s ease;pointer-events:all}
@keyframes tIn{from{opacity:0;transform:translateX(26px)}to{opacity:1;transform:none}}
.t-ic{font-size:.85rem;flex-shrink:0}
.t-body{flex:1}
.t-title{font-weight:700;font-size:.72rem;color:var(--text);margin-bottom:.1rem}
.t-msg{font-size:.65rem;color:var(--text-muted)}
.t-x{background:none;border:none;color:var(--text-muted);cursor:pointer;font-size:.7rem}

.grid-bg{position:fixed;inset:0;pointer-events:none;z-index:0;
  background-image:linear-gradient(rgba(37,99,235,.01) 1px,transparent 1px),
    linear-gradient(90deg,rgba(37,99,235,.01) 1px,transparent 1px);
  background-size:42px 42px}
.footer{flex-shrink:0;border-top:1px solid var(--border);
  padding:.5rem 1.4rem;display:flex;align-items:center;justify-content:space-between;
  font-size:.6rem;color:var(--text-muted);
  font-family:var(--font-mono);background:var(--white)}
.footer .dual-status-badge{background:rgba(37,99,235,.08);border:1px solid rgba(37,99,235,.2);border-radius:999px;padding:.1rem .5rem;font-size:.5rem;color:var(--primary);font-weight:700}

@media(max-width:1100px){.stats-row{grid-template-columns:repeat(3,1fr)}}
@media(max-width:760px){
  :root{--sw:0px}
  .sidebar{display:none}
  .stats-row{grid-template-columns:1fr 1fr}
  .content{padding:.8rem}
  .pname{display:none}
  .tb-search{display:none}
}
</style>
</head>
<body>
<div class="grid-bg"></div>
<div class="toast-wrap" id="toastWrap"></div>

<div class="shell">

<!-- ═══════════════════ SIDEBAR ═══════════════════ -->
<aside class="sidebar">
  <a href="dashboard.php" class="sb-brand">
    <div class="sb-logo">🏥</div>
    <div>
      <div class="sb-title"><?= htmlspecialchars($hospital_station) ?></div>
      <div class="sb-sub">Emergency Medical Response</div>
    </div>
  </a>

  <nav class="sb-nav">
    <div class="sb-sect">Main</div>
    <a href="dashboard.php" class="sb-item <?= is_active('dashboard.php') ?>">
      <span class="sb-ic"><i class="fas fa-chart-line"></i></span> Dashboard
    </a>
    <a href="emergencies.php" class="sb-item active">
      <span class="sb-ic"><i class="fas fa-ambulance"></i></span> Emergency Cases
      <?php if($pending_count > 0): ?>
        <span class="sb-badge red"><?= $pending_count ?></span>
      <?php endif; ?>
    </a>
    <a href="ambulance.php" class="sb-item <?= is_active('ambulance.php') ?>">
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

    <div class="sb-divider" style="margin-top:.8rem"></div>
    <a href="../logout.php" class="sb-logout-item">
      <span class="sb-ic"><i class="fas fa-sign-out-alt"></i></span>
      Logout
    </a>
  </nav>
</aside>

<!-- ═══════════════════ MAIN ═══════════════════ -->
<div class="main">

  <!-- TOPBAR -->
  <div class="topbar">
    <div class="tb-title"><i class="fas fa-ambulance"></i>Emergency Cases</div>

    <div class="tb-search">
      <i class="fas fa-search"></i>
      <input type="text" placeholder="Search emergencies…" oninput="filterTable(this.value)"/>
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
            <?php if($pending_notifications && $pending_notifications->num_rows > 0):
              while($al = $pending_notifications->fetch_assoc()): ?>
            <div class="notif-item" onclick="window.location.href='emergencies.php?status=assigned'">
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
            <a href="emergencies.php?status=assigned">📋 View all pending →</a>
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

  <!-- ═══ CONTENT ═══ -->
  <div class="content">

    <!-- STAT CARDS -->
    <div class="stats-row">
      <div class="sc c1">
        <div class="sc-ico"><i class="fas fa-clock"></i></div>
        <div class="sc-num"><?= number_format($stats['pending_count'] ?? 0) ?></div>
        <div class="sc-lbl">Pending</div>
        <div class="sc-tag" style="color:var(--critical)">⚠️ New</div>
      </div>
      <div class="sc c2">
        <div class="sc-ico"><i class="fas fa-truck-medical"></i></div>
        <div class="sc-num"><?= number_format($stats['dispatched_count'] ?? 0) ?></div>
        <div class="sc-lbl">Dispatched</div>
        <div class="sc-tag" style="color:var(--secondary)">🚑 En route</div>
      </div>
      <div class="sc c3">
        <div class="sc-ico"><i class="fas fa-user-check"></i></div>
        <div class="sc-num"><?= number_format($stats['received_count'] ?? 0) ?></div>
        <div class="sc-lbl">Received</div>
        <div class="sc-tag" style="color:var(--moderate)">🏥 At Hospital</div>
      </div>
      <div class="sc c4">
        <div class="sc-ico"><i class="fas fa-check-circle"></i></div>
        <div class="sc-num"><?= number_format($stats['treated_count'] ?? 0) ?></div>
        <div class="sc-lbl">Treated</div>
        <div class="sc-tag" style="color:var(--success)">✅ Done</div>
      </div>
      <div class="sc c5">
        <div class="sc-ico"><i class="fas fa-inbox"></i></div>
        <div class="sc-num"><?= number_format($stats['total_count'] ?? 0) ?></div>
        <div class="sc-lbl">Total Cases</div>
        <div class="sc-tag" style="color:var(--primary)">📋 All</div>
      </div>
    </div>

    <!-- FILTER BAR - Removed Closed -->
    <div class="filter-bar">
      <a href="?status=all" class="<?= $status_filter=='all'?'active':'' ?>">All</a>
      <a href="?status=assigned" class="<?= $status_filter=='assigned'?'active':'' ?>">Pending</a>
      <a href="?status=preparing" class="<?= $status_filter=='preparing'?'active':'' ?>">Preparing</a>
      <a href="?status=ambulance_dispatched" class="<?= $status_filter=='ambulance_dispatched'?'active':'' ?>">Dispatched</a>
      <a href="?status=patient_received" class="<?= $status_filter=='patient_received'?'active':'' ?>">Received</a>
      <a href="?status=treated" class="<?= $status_filter=='treated'?'active':'' ?>">Treated</a>
      <span class="count"><?= $total_records ?> records</span>
    </div>

    <!-- EMERGENCIES TABLE -->
    <div class="panel">
      <div class="ph">
        <div>
          <div class="ph-t"><i class="fas fa-list"></i>All Emergency Cases</div>
          <div class="ph-s">Showing <?= $total_records ?> cases assigned to <?= htmlspecialchars($hospital_station) ?></div>
        </div>
        <div style="display:flex;gap:.5rem;align-items:center">
          <span class="ph-a" style="font-size:.6rem;color:var(--text-muted);cursor:default;">
            <i class="fas fa-sync-alt"></i> Auto-refresh: 30s
          </span>
        </div>
      </div>

      <div class="tbl-wrap">
        <table>
          <thead>
            <tr>
              <th>#ID</th>
              <th>📸 Photo</th>
              <th>Reporter</th>
              <th>Location</th>
              <th>Injured</th>
              <th>Severity</th>
              <th>🏥 Status</th>
              <th>Police</th>
              <th>Time</th>
              <th style="text-align:right">Action</th>
            </tr>
          </thead>
          <tbody id="tableBody">
          <?php if($emergencies && $emergencies->num_rows > 0):
            while($r = $emergencies->fetch_assoc()):
              $img_path = getAccidentImage($r);
              $has_img = $img_path && file_exists('../' . $img_path);
              $severity_class = $r['injuries'] >= 4 ? 'critical' : ($r['injuries'] >= 3 ? 'high' : ($r['injuries'] >= 2 ? 'moderate' : 'low'));
              $hospital_status = $r['hospital_status'] ?? $r['status'] ?? 'assigned';
          ?>
            <tr>
              <td class="td-id">#ACC-<?= str_pad($r['id'],4,'0',STR_PAD_LEFT) ?></td>
              <td>
                <?php if($has_img): ?>
                  <img src="../<?= htmlspecialchars($img_path) ?>" 
                       alt="Scene" 
                       class="thumbnail-img"
                       onclick="viewEmergency(<?= $r['id'] ?>)"
                       onerror="this.style.display='none';this.parentElement.innerHTML='<span class=\'image-placeholder\'><i class=\'fas fa-image\'></i></span>'">
                <?php else: ?>
                  <span class="image-placeholder"><i class="fas fa-image"></i></span>
                <?php endif; ?>
              </td>
              <td style="font-weight:500"><?= htmlspecialchars($r['reporter_name']?:'Anonymous') ?></td>
              <td class="td-loc" title="<?= htmlspecialchars($r['location_description']??'') ?>">
                <?= htmlspecialchars(substr($r['location_description']??'Unknown',0,25)) ?>
              </td>
              <td class="td-inj" style="color:<?= $r['injuries'] >= 4 ? 'var(--critical)' : ($r['injuries'] >= 3 ? 'var(--high)' : ($r['injuries'] >= 2 ? 'var(--moderate)' : 'var(--success)')) ?>">
                <?= intval($r['injuries']) ?>
              </td>
              <td>
                <span class="severity-badge <?= $severity_class ?>">
                  <i class="fas fa-circle" style="font-size:.35rem"></i>
                  <?= ucfirst($severity_class) ?>
                </span>
              </td>
              <td>
                <span class="badge <?= $hospital_status ?>">
                  <span class="badge-dot"></span>
                  <?= str_replace('_', ' ', ucfirst($hospital_status ?? 'unknown')) ?>
                </span>
              </td>
              <td style="font-size:.6rem;color:var(--text-muted)"><?= htmlspecialchars($r['police_station_name'] ?? 'N/A') ?></td>
              <td class="td-time"><?= date('d M, H:i',strtotime($r['created_at'])) ?></td>
              <td style="text-align:right;white-space:nowrap">
                <!-- 🔴 STATUS UPDATE - Only Save Button, No Eye Icon -->
                <form method="POST" class="status-update-form">
                  <input type="hidden" name="accident_id" value="<?= $r['id'] ?>">
                  <select name="status">
                    <option value="assigned" <?= $hospital_status == 'assigned' ? 'selected' : '' ?>>⏳ Assigned</option>
                    <option value="preparing" <?= $hospital_status == 'preparing' ? 'selected' : '' ?>>🔄 Preparing</option>
                    <option value="ambulance_dispatched" <?= $hospital_status == 'ambulance_dispatched' ? 'selected' : '' ?>>🚑 Dispatched</option>
                    <option value="patient_received" <?= $hospital_status == 'patient_received' ? 'selected' : '' ?>>🏥 Received</option>
                    <option value="treated" <?= $hospital_status == 'treated' ? 'selected' : '' ?>>✅ Treated</option>
                  </select>
                  <button type="submit" name="update_status">
                    <i class="fas fa-save"></i>
                  </button>
                </form>
              </td>
            </tr>
          <?php endwhile; else: ?>
            <tr><td colspan="10" style="text-align:center;padding:2rem;color:var(--text-muted)">
              <i class="fas fa-ambulance" style="font-size:2rem;display:block;margin-bottom:.5rem;opacity:.3"></i>
              No emergencies assigned to your hospital
            </td></tr>
          <?php endif; ?>
          </tbody>
        </table>
      </div>

      <!-- Pagination -->
      <?php if($total_pages > 1): ?>
      <div class="ph" style="border-top:1px solid var(--border);border-bottom:none;">
        <div class="ph-s">Showing <?= $offset + 1 ?> to <?= min($offset + $limit, $total_records) ?> of <?= number_format($total_records) ?></div>
        <div class="pagination">
          <?php if($page > 1): ?>
            <a href="?page=<?= $page-1 ?>&status=<?= $status_filter ?>&search=<?= urlencode($search) ?>">
              <i class="fas fa-chevron-left"></i>
            </a>
          <?php else: ?>
            <span class="disabled"><i class="fas fa-chevron-left"></i></span>
          <?php endif; ?>
          
          <?php for($i = 1; $i <= $total_pages; $i++): ?>
            <?php if($i == $page): ?>
              <span class="active"><?= $i ?></span>
            <?php elseif($i <= 2 || $i > $total_pages - 2 || abs($i - $page) <= 2): ?>
              <a href="?page=<?= $i ?>&status=<?= $status_filter ?>&search=<?= urlencode($search) ?>"><?= $i ?></a>
            <?php elseif($i == 3 && $page > 4): ?>
              <span>…</span>
            <?php elseif($i == $total_pages - 2 && $page < $total_pages - 3): ?>
              <span>…</span>
            <?php endif; ?>
          <?php endfor; ?>
          
          <?php if($page < $total_pages): ?>
            <a href="?page=<?= $page+1 ?>&status=<?= $status_filter ?>&search=<?= urlencode($search) ?>">
              <i class="fas fa-chevron-right"></i>
            </a>
          <?php else: ?>
            <span class="disabled"><i class="fas fa-chevron-right"></i></span>
          <?php endif; ?>
        </div>
      </div>
      <?php endif; ?>
    </div>

    <!-- 🔴 STATUS FLOW GUIDE - Removed Closed -->
    <div style="display:grid;grid-template-columns:repeat(5,1fr);gap:0.5rem;margin-top:1rem;padding:1rem;background:var(--white);border:1px solid var(--border);border-radius:var(--radius);box-shadow:var(--shadow)">
      <div style="text-align:center;padding:0.5rem;background:rgba(239,68,68,.05);border-radius:8px;border-left:3px solid var(--critical)">
        <div style="font-size:.5rem;color:var(--text-muted);text-transform:uppercase;font-weight:600">Step 1</div>
        <div style="font-size:.7rem;font-weight:600;color:var(--critical)">Assigned</div>
        <div style="font-size:.5rem;color:var(--text-muted)">Case assigned</div>
      </div>
      <div style="text-align:center;padding:0.5rem;background:rgba(249,115,22,.05);border-radius:8px;border-left:3px solid var(--high)">
        <div style="font-size:.5rem;color:var(--text-muted);text-transform:uppercase;font-weight:600">Step 2</div>
        <div style="font-size:.7rem;font-weight:600;color:var(--high)">Preparing</div>
        <div style="font-size:.5rem;color:var(--text-muted)">Ambulance prep</div>
      </div>
      <div style="text-align:center;padding:0.5rem;background:rgba(14,165,233,.05);border-radius:8px;border-left:3px solid var(--secondary)">
        <div style="font-size:.5rem;color:var(--text-muted);text-transform:uppercase;font-weight:600">Step 3</div>
        <div style="font-size:.7rem;font-weight:600;color:var(--secondary)">Dispatched</div>
        <div style="font-size:.5rem;color:var(--text-muted)">En route</div>
      </div>
      <div style="text-align:center;padding:0.5rem;background:rgba(234,179,8,.05);border-radius:8px;border-left:3px solid var(--moderate)">
        <div style="font-size:.5rem;color:var(--text-muted);text-transform:uppercase;font-weight:600">Step 4</div>
        <div style="font-size:.7rem;font-weight:600;color:var(--moderate)">Received</div>
        <div style="font-size:.5rem;color:var(--text-muted)">At hospital</div>
      </div>
      <div style="text-align:center;padding:0.5rem;background:rgba(16,185,129,.05);border-radius:8px;border-left:3px solid var(--success)">
        <div style="font-size:.5rem;color:var(--text-muted);text-transform:uppercase;font-weight:600">Step 5</div>
        <div style="font-size:.7rem;font-weight:600;color:var(--success)">Treated</div>
        <div style="font-size:.5rem;color:var(--text-muted)">Patient treated</div>
      </div>
    </div>

  </div><!-- /content -->

  <div class="footer">
    <span>🏥 <?= htmlspecialchars($hospital_station) ?> &nbsp;·&nbsp; View Accidents & Update Status</span>
    <span class="dual-status-badge"><i class="fas fa-shield-alt"></i> View Accidents</span>
    <span id="clock"></span>
  </div>
</div><!-- /main -->
</div><!-- /shell -->

<!-- ═══════════════════ EMERGENCY DETAILS MODAL ═══════════════════ -->
<div class="modal-bg" id="emergencyModal">
    <div class="modal">
        <button class="modal-close" onclick="closeModal()"><i class="fas fa-times"></i></button>
        <div class="modal-title"><i class="fas fa-ambulance"></i>Emergency Details</div>
        <div class="modal-sub" id="modalSubtitle">#ACC-0000</div>
        <div id="modalBody">
            <div style="text-align:center;padding:2rem;color:var(--text-muted)">
                <i class="fas fa-spinner fa-spin"></i> Loading...
            </div>
        </div>
    </div>
</div>

<script>
// ── CLOCK ──
function tick(){
  const n=new Date();
  document.getElementById('clock').textContent=
    n.toLocaleDateString('en-GB',{weekday:'short',day:'2-digit',month:'short'})+
    ' · '+n.toLocaleTimeString('en-GB',{hour:'2-digit',minute:'2-digit',second:'2-digit'});
}
setInterval(tick,1000);tick();

// ── TABLE FILTER ──
function filterTable(q){
  const rows=document.querySelectorAll('#tableBody tr');q=q.toLowerCase();
  rows.forEach(r=>{
    r.style.display=r.textContent.toLowerCase().includes(q)?'':'none';
  });
}

// ── NOTIFICATION DROPDOWN ──
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

// ── HELPER: ltrim ──
function ltrim(str) {
    if (!str) return '';
    return str.replace(/^\/+/, '');
}

// ── VIEW EMERGENCY ──
function viewEmergency(id) {
    const modal = document.getElementById('emergencyModal');
    const body = document.getElementById('modalBody');
    const subtitle = document.getElementById('modalSubtitle');
    
    modal.classList.add('show');
    subtitle.textContent = '#ACC-' + String(id).padStart(4, '0');
    body.innerHTML = '<div style="text-align:center;padding:2rem;color:var(--text-muted)"><i class="fas fa-spinner fa-spin"></i> Loading...</div>';
    
    fetch('get_emergency.php?id=' + id)
        .then(response => response.json())
        .then(data => {
            if (data.error) {
                body.innerHTML = `<div style="text-align:center;padding:2rem;color:var(--critical)">${data.error}</div>`;
                return;
            }
            
            let severity = data.injuries >= 4 ? 'Critical' : (data.injuries >= 3 ? 'High' : (data.injuries >= 2 ? 'Moderate' : 'Low'));
            let severityColor = data.injuries >= 4 ? 'var(--critical)' : (data.injuries >= 3 ? 'var(--high)' : (data.injuries >= 2 ? 'var(--moderate)' : 'var(--success)'));
            
            // Check if image exists
            let imageHtml = '';
            if (data.image_path || data.images) {
                let imgPath = null;
                if (data.images) {
                    try {
                        let images = typeof data.images === 'string' ? JSON.parse(data.images) : data.images;
                        if (Array.isArray(images) && images.length > 0) {
                            imgPath = '../' + ltrim(images[0], '/');
                        }
                    } catch(e) {}
                }
                if (!imgPath && data.image_path) {
                    imgPath = '../' + ltrim(data.image_path, '/');
                }
                if (imgPath) {
                    imageHtml = `
                        <div class="item full">
                            <div class="label">📸 Scene Photo</div>
                            <div class="value" style="margin-top:6px;">
                                <img src="${imgPath}" alt="Scene" style="max-width:100%;max-height:200px;border-radius:8px;border:1px solid var(--border);cursor:pointer;"
                                     onclick="window.open('${imgPath}','_blank')"
                                     onerror="this.style.display='none'">
                            </div>
                        </div>
                    `;
                }
            }
            
            // Get hospital status
            const hospitalStatus = data.hospital_status || data.status || 'assigned';
            
            let html = `
                <div class="modal-detail">
                    <div class="item">
                        <div class="label">👤 Reporter</div>
                        <div class="value">${data.reporter_name || 'Anonymous'}</div>
                    </div>
                    <div class="item">
                        <div class="label">📞 Phone</div>
                        <div class="value">${data.reporter_phone || 'N/A'}</div>
                    </div>
                    <div class="item">
                        <div class="label">🚗 Accident Type</div>
                        <div class="value">${data.accident_type || 'Unknown'}</div>
                    </div>
                    <div class="item">
                        <div class="label">🤕 Injuries</div>
                        <div class="value" style="color:${severityColor};font-weight:700;">${data.injuries} person(s)</div>
                    </div>
                    <div class="item">
                        <div class="label">⚠️ Severity</div>
                        <div class="value">
                            <span class="severity-badge ${severity.toLowerCase()}">
                                <i class="fas fa-circle" style="font-size:.35rem"></i> ${severity}
                            </span>
                        </div>
                    </div>
                    <div class="item">
                        <div class="label">🏥 Hospital Status</div>
                        <div class="value">
                            <span class="badge ${hospitalStatus}">
                                <span class="badge-dot"></span> ${(hospitalStatus || 'UNKNOWN').replace('_', ' ').toUpperCase()}
                            </span>
                        </div>
                    </div>
                    ${imageHtml}
                    <div class="item full">
                        <div class="label">📍 Location</div>
                        <div class="value">${data.location_description || 'Unknown location'}</div>
                        <div style="font-size:.65rem;color:var(--text-muted);margin-top:3px;">
                            Lat: ${data.latitude || 'N/A'}, Lng: ${data.longitude || 'N/A'}
                        </div>
                    </div>
                    <div class="item full">
                        <div class="label">📝 Description</div>
                        <div class="value" style="font-size:.75rem;">${data.description || 'No description provided'}</div>
                    </div>
                    <div class="item full">
                        <div class="label">🕐 Reported Time</div>
                        <div class="value" style="font-size:.75rem;">${data.created_at ? new Date(data.created_at).toLocaleString() : 'N/A'}</div>
                    </div>
                    <div class="item full">
                        <div class="label">🚔 Assigned Police</div>
                        <div class="value" style="font-size:.75rem;">${data.police_station_name || 'Not assigned yet'}</div>
                    </div>
                </div>
            `;
            
            body.innerHTML = html;
        })
        .catch(error => {
            console.error('Error:', error);
            body.innerHTML = '<div style="text-align:center;padding:2rem;color:var(--critical)">Error loading details</div>';
        });
}

function closeModal() {
    document.getElementById('emergencyModal').classList.remove('show');
}

// ── CLOSE ON OUTSIDE ──
document.addEventListener('click',function(e){
  if(!e.target.closest('#notifDrop')&&!e.target.closest('.notif-btn'))
    document.getElementById('notifDrop').classList.remove('show');
  if(!e.target.closest('#profileDrop')&&!e.target.closest('#profileBtn'))
    closeProfileDrop();
  if(e.target.classList.contains('modal-bg')) {
    e.target.classList.remove('show');
  }
});

// ── ESCAPE KEY ──
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeModal();
        document.querySelectorAll('.modal-bg.show').forEach(m => m.classList.remove('show'));
    }
});

// ── TOAST ──
function showToast(title,msg,type){
  const w=document.getElementById('toastWrap');
  const icon=type==='ok'?'✅':type==='err'?'⚠️':'ℹ️';
  const el=document.createElement('div');el.className='toast';
  el.innerHTML=`<div class="t-ic">${icon}</div>
    <div class="t-body"><div class="t-title">${title}</div><div class="t-msg">${msg}</div></div>
    <button class="t-x" onclick="this.closest('.toast').remove()">✕</button>`;
  w.appendChild(el);setTimeout(()=>el.remove(),5000);
}

// ── SHOW TOAST FROM URL ──
<?php if(isset($_GET['msg']) && $_GET['msg'] === 'updated'): ?>
showToast('✅ Updated', 'Emergency status updated successfully!', 'ok');
<?php endif; ?>
</script>

</body>
</html>