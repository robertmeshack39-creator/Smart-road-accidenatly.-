<?php
// hospital/treatment.php - Treatment Management with treatment_patients table
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
// GET STATISTICS - Using treatment_patients
// ============================================
$stats_query = $conn->query("
    SELECT 
        (SELECT COUNT(*) FROM accidents 
         WHERE assigned_hospital_id = $hospital_station_id 
         AND hospital_status = 'patient_received') as pending_treatment,
        (SELECT COUNT(*) FROM treatment_patients tp
         JOIN accidents a ON tp.accident_id = a.id
         WHERE tp.hospital_station_id = $hospital_station_id 
         AND tp.treatment_status = 'in_progress') as in_progress,
        (SELECT COUNT(*) FROM treatment_patients tp
         JOIN accidents a ON tp.accident_id = a.id
         WHERE tp.hospital_station_id = $hospital_station_id 
         AND tp.treatment_status = 'completed') as completed,
        (SELECT COUNT(*) FROM accidents 
         WHERE assigned_hospital_id = $hospital_station_id 
         AND hospital_status IN ('treated', 'closed')) as total_treated
");
$stats = $stats_query->fetch_assoc();

$stats['pending_treatment'] = $stats['pending_treatment'] ?? 0;
$stats['in_progress'] = $stats['in_progress'] ?? 0;
$stats['completed'] = $stats['completed'] ?? 0;
$stats['total_treated'] = $stats['total_treated'] ?? 0;

// ============================================
// GET CASES FOR TREATMENT - Using treatment_patients
// ============================================
$filter = isset($_GET['filter']) ? $_GET['filter'] : 'all';
$where_clause = "a.assigned_hospital_id = $hospital_station_id AND a.hospital_status IN ('assigned', 'patient_received', 'treated', 'closed')";

if ($filter === 'pending') {
    $where_clause .= " AND a.hospital_status = 'patient_received' AND (SELECT COUNT(*) FROM treatment_patients WHERE accident_id = a.id) = 0";
} elseif ($filter === 'in_progress') {
    $where_clause .= " AND EXISTS (SELECT 1 FROM treatment_patients tp WHERE tp.accident_id = a.id AND tp.treatment_status = 'in_progress')";
} elseif ($filter === 'completed') {
    $where_clause .= " AND NOT EXISTS (SELECT 1 FROM treatment_patients tp WHERE tp.accident_id = a.id AND tp.treatment_status IN ('pending', 'in_progress')) AND a.hospital_status != 'closed'";
} elseif ($filter === 'history') {
    $where_clause .= " AND a.hospital_status IN ('treated', 'closed')";
}

$cases_query = $conn->query("
    SELECT 
        a.id as accident_id,
        a.accident_type,
        a.location_description,
        a.injuries,
        a.reporter_name,
        a.reporter_phone,
        a.created_at as received_at,
        a.hospital_status,
        COUNT(tp.id) as patient_count,
        SUM(CASE WHEN tp.treatment_status = 'completed' THEN 1 ELSE 0 END) as completed_patients,
        SUM(CASE WHEN tp.treatment_status = 'in_progress' THEN 1 ELSE 0 END) as in_progress_patients,
        SUM(CASE WHEN tp.treatment_status = 'pending' THEN 1 ELSE 0 END) as pending_patients,
        GROUP_CONCAT(DISTINCT tp.doctor_name SEPARATOR ', ') as doctor_names,
        GROUP_CONCAT(DISTINCT tp.patient_name SEPARATOR ', ') as patient_names
    FROM accidents a
    LEFT JOIN treatment_patients tp ON a.id = tp.accident_id
    WHERE $where_clause
    GROUP BY a.id
    ORDER BY a.created_at DESC
");

// ============================================
// GET PENDING NOTIFICATIONS
// ============================================
$pending_notifications = $conn->query("
    SELECT id, accident_type, location_description, created_at, reporter_name, injuries, hospital_status as status
    FROM accidents 
    WHERE assigned_hospital_id = $hospital_station_id 
    AND hospital_status = 'assigned'
    ORDER BY created_at DESC LIMIT 10
");
$pending_count = $pending_notifications ? $pending_notifications->num_rows : 0;

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
<title>Treatment Management — <?= htmlspecialchars($hospital_station) ?></title>
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
.stats-row{display:grid;grid-template-columns:repeat(4,1fr);gap:1rem;margin-bottom:1.1rem}
.sc{background:var(--white);border:1px solid var(--border);border-radius:var(--radius);
  padding:1rem 1.2rem;position:relative;overflow:hidden;
  transition:border-color .22s,transform .18s;box-shadow:var(--shadow)}
.sc:hover{border-color:var(--primary);transform:translateY(-2px);box-shadow:var(--shadow-hover)}
.sc::after{content:'';position:absolute;top:0;left:0;right:0;height:2px;opacity:0;transition:opacity .25s}
.sc:hover::after{opacity:1}
.sc.c1::after{background:linear-gradient(90deg,var(--critical),transparent)}
.sc.c2::after{background:linear-gradient(90deg,var(--high),transparent)}
.sc.c3::after{background:linear-gradient(90deg,var(--success),transparent)}
.sc.c4::after{background:linear-gradient(90deg,var(--primary),transparent)}
.sc-ico{width:34px;height:34px;border-radius:8px;
  display:flex;align-items:center;justify-content:center;
  font-size:.8rem;margin-bottom:.7rem}
.c1 .sc-ico{background:rgba(239,68,68,.1);color:var(--critical)}
.c2 .sc-ico{background:rgba(249,115,22,.1);color:var(--high)}
.c3 .sc-ico{background:rgba(16,185,129,.1);color:var(--success)}
.c4 .sc-ico{background:rgba(37,99,235,.1);color:var(--primary)}
.sc-num{font-family:var(--font-display);font-size:1.6rem;
  font-weight:800;line-height:1;margin-bottom:.15rem}
.c1 .sc-num{color:var(--critical)}
.c2 .sc-num{color:var(--high)}
.c3 .sc-num{color:var(--success)}
.c4 .sc-num{color:var(--primary)}
.sc-lbl{font-size:.55rem;color:var(--text-muted);letter-spacing:.08em;text-transform:uppercase}
.sc-tag{position:absolute;top:1rem;right:1rem;font-size:.5rem;font-family:var(--font-mono);color:var(--text-muted)}

/* ─── FILTER TABS ─── */
.filter-tabs{display:flex;gap:.5rem;margin-bottom:1rem;flex-wrap:wrap}
.filter-tab{padding:.4rem .8rem;border-radius:999px;font-size:.7rem;font-weight:600;
  border:1px solid var(--border);background:var(--white);color:var(--text-muted);
  cursor:pointer;transition:all .18s;text-decoration:none;font-family:var(--font-primary)}
.filter-tab:hover{background:var(--bg3);border-color:var(--primary);color:var(--primary)}
.filter-tab.active{background:var(--primary);color:white;border-color:var(--primary)}
.filter-tab .count{background:rgba(255,255,255,.2);padding:.05rem .4rem;border-radius:999px;font-size:.55rem;margin-left:.2rem}
.filter-tab.active .count{background:rgba(255,255,255,.3)}

/* ─── TABLE ─── */
.panel{background:var(--white);border:1px solid var(--border);border-radius:var(--radius);overflow:hidden;box-shadow:var(--shadow)}
.ph{display:flex;align-items:center;justify-content:space-between;padding:.7rem 1.15rem;border-bottom:1px solid var(--border)}
.ph-t{font-family:var(--font-display);font-size:.95rem;font-weight:700;color:var(--text)}
.ph-t i{margin-right:.5rem;color:var(--primary)}
.tbl-wrap{overflow-x:auto}
table{width:100%;border-collapse:collapse;font-size:.72rem}
th{padding:.45rem .75rem;font-size:.55rem;letter-spacing:.1em;text-transform:uppercase;color:var(--text-muted);font-weight:600;border-bottom:1px solid var(--border);text-align:left}
td{padding:.45rem .75rem;color:var(--text);border-bottom:1px solid rgba(0,0,0,.04)}
tr:hover{background:rgba(37,99,235,.02)}
.td-mono{font-family:var(--font-mono);font-size:.65rem}
tr.clickable{cursor:pointer}
tr.clickable:hover{background:rgba(37,99,235,.06)}

/* ─── BADGES ─── */
.badge{display:inline-flex;align-items:center;gap:.2rem;padding:.15rem .5rem;border-radius:999px;font-family:var(--font-mono);font-size:.55rem;font-weight:600;text-transform:uppercase}
.badge.assigned{background:rgba(239,68,68,.1);color:var(--critical);border:1px solid rgba(239,68,68,.2)}
.badge.preparing{background:rgba(249,115,22,.1);color:var(--high);border:1px solid rgba(249,115,22,.2)}
.badge.ambulance_dispatched{background:rgba(14,165,233,.1);color:var(--secondary);border:1px solid rgba(14,165,233,.2)}
.badge.patient_received{background:rgba(234,179,8,.1);color:var(--moderate);border:1px solid rgba(234,179,8,.2)}
.badge.treated{background:rgba(16,185,129,.1);color:var(--success);border:1px solid rgba(16,185,129,.2)}
.badge.closed{background:rgba(100,116,139,.1);color:var(--text-muted);border:1px solid rgba(100,116,139,.2)}
.badge.pending{background:rgba(239,68,68,.1);color:var(--critical);border:1px solid rgba(239,68,68,.2)}
.badge.in_progress{background:rgba(249,115,22,.1);color:var(--high);border:1px solid rgba(249,115,22,.2)}
.badge.completed{background:rgba(16,185,129,.1);color:var(--success);border:1px solid rgba(16,185,129,.2)}
.badge-dot{width:4px;height:4px;border-radius:50%;background:currentColor}
.no-treatment{font-size:.6rem;color:var(--text-muted);font-style:italic}

/* ─── BUTTONS ─── */
.btn{display:inline-flex;align-items:center;gap:.3rem;padding:.15rem .5rem;border-radius:6px;font-size:.55rem;font-weight:600;border:none;cursor:pointer;transition:all .15s;font-family:var(--font-mono);text-decoration:none}
.btn-primary{background:var(--primary);color:white}
.btn-primary:hover{background:var(--primary-dark);transform:scale(1.02)}
.btn-outline{background:transparent;border:1px solid var(--border);color:var(--text)}
.btn-outline:hover{background:var(--bg3)}
.btn-block{width:100%;justify-content:center;padding:.5rem;font-size:.7rem}
.btn-success{background:var(--success);color:white}
.btn-success:hover{background:#059669}

/* ─── BADGE PATIENT COUNT ─── */
.patient-count-badge{
    display:inline-flex;
    align-items:center;
    gap:.2rem;
    padding:.1rem .5rem;
    border-radius:999px;
    font-size:.5rem;
    font-weight:600;
    font-family:var(--font-mono);
}
.patient-count-badge.all-done{
    background:rgba(16,185,129,.12);
    color:var(--success);
    border:1px solid rgba(16,185,129,.2);
}
.patient-count-badge.in-progress{
    background:rgba(249,115,22,.12);
    color:var(--high);
    border:1px solid rgba(249,115,22,.2);
}
.patient-count-badge.pending-patients{
    background:rgba(239,68,68,.12);
    color:var(--critical);
    border:1px solid rgba(239,68,68,.2);
}

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

@media(max-width:1100px){.stats-row{grid-template-columns:repeat(2,1fr)}}
@media(max-width:760px){
  :root{--sw:0px}.sidebar{display:none}.stats-row{grid-template-columns:1fr 1fr}
  .content{padding:.8rem}.tb-search{display:none}
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
    <a href="ambulance.php" class="sb-item <?= is_active('ambulance.php') ?>">
      <span class="sb-ic"><i class="fas fa-truck-medical"></i></span> Ambulance Dispatch
    </a>
    <div class="sb-sect" style="margin-top:.4rem">Medical</div>
    <a href="treatment.php" class="sb-item active">
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
    <div class="tb-title"><i class="fas fa-notes-medical"></i>Treatment Management</div>

    <div class="tb-search">
      <i class="fas fa-search"></i>
      <input type="text" placeholder="Search cases…" oninput="filterTable(this.value)"/>
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
            <div class="notif-item" onclick="window.location.href='emergencies.php'">
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
            <a href="emergencies.php">📋 View all emergencies →</a>
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
        <div class="sc-ico"><i class="fas fa-clock"></i></div>
        <div class="sc-num"><?= number_format($stats['pending_treatment'] ?? 0) ?></div>
        <div class="sc-lbl">Pending Treatment</div>
        <div class="sc-tag">🕐 Waiting</div>
      </div>
      <div class="sc c2">
        <div class="sc-ico"><i class="fas fa-spinner"></i></div>
        <div class="sc-num"><?= number_format($stats['in_progress'] ?? 0) ?></div>
        <div class="sc-lbl">In Progress</div>
        <div class="sc-tag" style="color:var(--high)">🔄 Ongoing</div>
      </div>
      <div class="sc c3">
        <div class="sc-ico"><i class="fas fa-check-circle"></i></div>
        <div class="sc-num"><?= number_format($stats['completed'] ?? 0) ?></div>
        <div class="sc-lbl">Completed</div>
        <div class="sc-tag" style="color:var(--success)">✅ Done</div>
      </div>
      <div class="sc c4">
        <div class="sc-ico"><i class="fas fa-history"></i></div>
        <div class="sc-num"><?= number_format($stats['total_treated'] ?? 0) ?></div>
        <div class="sc-lbl">Total Treated</div>
        <div class="sc-tag" style="color:var(--primary)">📊 All</div>
      </div>
    </div>

    <!-- FILTER TABS -->
    <div class="filter-tabs">
      <a href="?filter=all" class="filter-tab <?= $filter === 'all' ? 'active' : '' ?>">
        <i class="fas fa-list"></i> All <span class="count"><?= $cases_query ? $cases_query->num_rows : 0 ?></span>
      </a>
      <a href="?filter=pending" class="filter-tab <?= $filter === 'pending' ? 'active' : '' ?>">
        <i class="fas fa-clock"></i> Pending <span class="count"><?= $stats['pending_treatment'] ?? 0 ?></span>
      </a>
      <a href="?filter=in_progress" class="filter-tab <?= $filter === 'in_progress' ? 'active' : '' ?>">
        <i class="fas fa-spinner"></i> In Progress <span class="count"><?= $stats['in_progress'] ?? 0 ?></span>
      </a>
      <a href="?filter=completed" class="filter-tab <?= $filter === 'completed' ? 'active' : '' ?>">
        <i class="fas fa-check-circle"></i> Completed <span class="count"><?= $stats['completed'] ?? 0 ?></span>
      </a>
      <a href="?filter=history" class="filter-tab <?= $filter === 'history' ? 'active' : '' ?>">
        <i class="fas fa-history"></i> History <span class="count"><?= $stats['total_treated'] ?? 0 ?></span>
      </a>
    </div>

    <!-- CASES TABLE -->
    <div class="panel">
      <div class="ph">
        <div>
          <div class="ph-t"><i class="fas fa-notes-medical"></i>Treatment Cases</div>
          <div style="font-size:.6rem;color:var(--text-muted)">Click any row to manage patients</div>
        </div>
        <span style="font-size:.6rem;color:var(--text-muted)">🔄 Auto-refresh: 30s</span>
      </div>
      <div class="tbl-wrap">
        <table>
          <thead>
            <tr>
              <th>#ID</th>
              <th>Reporter</th>
              <th>Type</th>
              <th>Patients</th>
              <th>Status</th>
              <th>Doctors</th>
              <th>Time</th>
            </tr>
          </thead>
          <tbody id="tableBody">
          <?php if($cases_query && $cases_query->num_rows > 0):
            while($row = $cases_query->fetch_assoc()): 
                $total_patients = $row['patient_count'] ?? 0;
                $completed = $row['completed_patients'] ?? 0;
                $in_progress = $row['in_progress_patients'] ?? 0;
                $pending = $row['pending_patients'] ?? 0;
                
                $patient_status_class = 'pending-patients';
                $patient_status_text = 'No patients';
                if($total_patients > 0 && $completed == $total_patients) {
                    $patient_status_class = 'all-done';
                    $patient_status_text = '✅ All done';
                } elseif($in_progress > 0) {
                    $patient_status_class = 'in-progress';
                    $patient_status_text = '🔄 In progress';
                } elseif($pending > 0) {
                    $patient_status_class = 'pending-patients';
                    $patient_status_text = '⏳ Pending';
                }
            ?>
            <tr class="clickable" onclick="window.location.href='treatment_details.php?accident_id=<?= $row['accident_id'] ?>'">
              <td class="td-mono">#ACC-<?= str_pad($row['accident_id'],4,'0',STR_PAD_LEFT) ?></td>
              <td>
                <span style="font-weight:600"><?= htmlspecialchars($row['reporter_name'] ?: 'Unknown') ?></span>
              </td>
              <td style="font-size:.65rem"><?= htmlspecialchars(substr($row['accident_type'] ?? 'Unknown',0,15)) ?></td>
              <td>
                <?php if($total_patients > 0): ?>
                    <div>
                        <span class="patient-count-badge <?= $patient_status_class ?>">
                            <?= $total_patients ?> patient(s)
                        </span>
                        <span style="font-size:.5rem;color:var(--text-muted);display:block;margin-top:2px;">
                            <?= $completed ? $completed : 0 ?> completed · <?= $pending ? $pending : 0 ?> pending
                        </span>
                    </div>
                <?php else: ?>
                    <span class="no-treatment">No patients yet</span>
                <?php endif; ?>
              </td>
              <td>
                <span class="badge <?= $row['hospital_status'] ?>">
                  <span class="badge-dot"></span>
                  <?= str_replace('_', ' ', ucfirst($row['hospital_status'] ?? 'unknown')) ?>
                </span>
                <?php if($row['hospital_status'] == 'patient_received' && $total_patients == 0): ?>
                    <span style="font-size:.45rem;color:var(--critical);display:block;font-weight:600;font-family:var(--font-mono);">
                        ⚠️ No patients added
                    </span>
                <?php endif; ?>
              </td>
              <td style="font-size:.6rem;color:var(--text-muted);">
                <?= $row['doctor_names'] ? htmlspecialchars(substr($row['doctor_names'],0,20)) : '-' ?>
              </td>
              <td style="font-size:.6rem;color:var(--text-muted)"><?= date('d M, H:i',strtotime($row['received_at'])) ?></td>
            </tr>
          <?php endwhile; else: ?>
            <tr><td colspan="7" style="text-align:center;padding:2rem;color:var(--text-muted)">
              <i class="fas fa-inbox" style="font-size:2rem;display:block;opacity:.3;margin-bottom:.5rem"></i>
              No cases found
            </td></tr>
          <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

  </div><!-- /content -->

  <div class="footer">
    <span>🏥 <?= htmlspecialchars($hospital_station) ?> &nbsp;·&nbsp;Treatment Management</span>
    <span class="dual-status-badge"><i class="fas fa-shield-alt"></i> treatment_patients</span>
    <span id="clock"></span>
  </div>
</div><!-- /main -->
</div><!-- /shell -->

<script>
// ── CLOCK ──
function tick(){const n=new Date();document.getElementById('clock').textContent=
  n.toLocaleDateString('en-GB',{weekday:'short',day:'2-digit',month:'short'})+
  ' · '+n.toLocaleTimeString('en-GB',{hour:'2-digit',minute:'2-digit',second:'2-digit'})}
setInterval(tick,1000);tick();

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

// ── FILTER TABLE ──
function filterTable(q){
  const rows=document.querySelectorAll('#tableBody tr');q=q.toLowerCase();
  rows.forEach(r=>{
    r.style.display=r.textContent.toLowerCase().includes(q)?'':'none';
  });
}

// ── CLOSE ON OUTSIDE ──
document.addEventListener('click',function(e){
  if(!e.target.closest('#notifDrop')&&!e.target.closest('.notif-btn'))
    document.getElementById('notifDrop').classList.remove('show');
  if(!e.target.closest('#profileDrop')&&!e.target.closest('#profileBtn'))
    closeProfileDrop();
});
</script>

</body>
</html>