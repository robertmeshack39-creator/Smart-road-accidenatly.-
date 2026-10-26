<?php
/**
 * TREATMENT DETAILS - Manage patients for a specific accident
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
$hospital_name = htmlspecialchars($_SESSION['name'] ?? 'Hospital Staff');
$hospital_init = strtoupper(substr($hospital_name, 0, 1));

$user_query = $conn->query("
    SELECT u.*, hs.id as station_id, hs.name as station_name, hs.location
    FROM users u
    LEFT JOIN hospital_stations hs ON u.hospital_station_id = hs.id
    WHERE u.id = $hospital_id
");
$user = $user_query->fetch_assoc();
$hospital_station_id = $user['station_id'] ?? null;
$hospital_station_name = $user['station_name'] ?? 'Hospital Station';

// ============================================
// 📋 GET ACCIDENT ID
// ============================================
$accident_id = isset($_GET['accident_id']) ? intval($_GET['accident_id']) : 0;

if ($accident_id == 0) {
    header("Location: treatment.php");
    exit();
}

// ============================================
// 📋 GET ACCIDENT DETAILS
// ============================================
$accident = $conn->query("
    SELECT a.*, ps.name as police_station_name
    FROM accidents a
    LEFT JOIN police_stations ps ON a.assigned_police_id = ps.id
    WHERE a.id = $accident_id AND a.assigned_hospital_id = $hospital_station_id
")->fetch_assoc();

if (!$accident) {
    header("Location: treatment.php?error=not_found");
    exit();
}

// ============================================
// 📋 GET PATIENTS FOR THIS ACCIDENT
// ============================================
$patients = $conn->query("
    SELECT * FROM treatment_patients 
    WHERE accident_id = $accident_id AND hospital_station_id = $hospital_station_id
    ORDER BY created_at DESC
");

$patient_count = $patients ? $patients->num_rows : 0;

// ============================================
// 📋 GET TREATMENT STATUS SUMMARY
// ============================================
$status_summary = $conn->query("
    SELECT 
        treatment_status,
        COUNT(*) as count
    FROM treatment_patients
    WHERE accident_id = $accident_id AND hospital_station_id = $hospital_station_id
    GROUP BY treatment_status
");

$status_counts = [];
while ($s = $status_summary->fetch_assoc()) {
    $status_counts[$s['treatment_status']] = $s['count'];
}

// ============================================
// 📋 GET PENDING NOTIFICATIONS
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

// ============================================
// 📊 GET CLOSED COUNT FOR SIDEBAR
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
// 🔧 HELPER FUNCTION
// ============================================
function is_active($page) {
    return basename($_SERVER['PHP_SELF']) === $page ? 'active' : '';
}

function getStatusBadge($status) {
    $badges = [
        'pending' => 'pending',
        'in_progress' => 'in_progress',
        'completed' => 'completed',
        'discharged' => 'treated',
        'referred' => 'ambulance_dispatched',
        'deceased' => 'closed'
    ];
    return $badges[$status] ?? 'pending';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width,initial-scale=1.0"/>
<title>Treatment Details — <?= htmlspecialchars($hospital_station_name) ?></title>
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

.btn-back{display:inline-flex;align-items:center;gap:.3rem;
  padding:.35rem .8rem;background:var(--bg3);border:1px solid var(--border);
  border-radius:8px;font-size:.7rem;color:var(--text);text-decoration:none;
  transition:all .2s;font-family:var(--font-primary)}
.btn-back:hover{background:var(--bg4);border-color:var(--primary);color:var(--primary)}

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

/* ─── ACCIDENT SUMMARY ─── */
.accident-summary{
  background:var(--white);
  border:1px solid var(--border);
  border-radius:var(--radius);
  padding:1.2rem;
  margin-bottom:1.2rem;
  box-shadow:var(--shadow);
}
.accident-summary .header{
  display:flex;
  justify-content:space-between;
  align-items:center;
  margin-bottom:0.8rem;
  flex-wrap:wrap;
  gap:.5rem;
}
.accident-summary .header h2{
  font-family:var(--font-display);
  font-size:1.2rem;
}
.accident-summary .header h2 i{
  color:var(--primary);
}
.accident-meta{
  display:grid;
  grid-template-columns:repeat(auto-fill, minmax(200px, 1fr));
  gap:0.5rem;
}
.accident-meta .meta-item{
  font-size:0.75rem;
}
.accident-meta .meta-item label{
  color:var(--text-muted);
  font-size:0.6rem;
  text-transform:uppercase;
  letter-spacing:0.08em;
  display:block;
}
.accident-meta .meta-item .value{
  font-weight:600;
  color:var(--text);
}

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

/* ─── PATIENTS SECTION ─── */
.patients-section{
  background:var(--white);
  border:1px solid var(--border);
  border-radius:var(--radius);
  overflow:hidden;
  box-shadow:var(--shadow);
  margin-bottom:1.2rem;
}
.patients-section .header{
  display:flex;
  justify-content:space-between;
  align-items:center;
  padding:.7rem 1.15rem;
  border-bottom:1px solid var(--border);
  flex-wrap:wrap;
  gap:.5rem;
}
.patients-section .header h3{
  font-family:var(--font-display);
  font-size:.95rem;
  font-weight:700;
}
.patients-section .header h3 i{
  color:var(--primary);
  margin-right:.3rem;
}

.btn-add-patient{
  padding:.35rem .8rem;
  background:var(--primary);
  color:white;
  border:none;
  border-radius:8px;
  font-size:.7rem;
  font-weight:600;
  cursor:pointer;
  transition:all .2s;
  font-family:var(--font-primary);
}
.btn-add-patient:hover{
  background:var(--primary-dark);
  transform:scale(1.02);
}

/* ─── STATUS SUMMARY PILLS ─── */
.status-summary{
  display:flex;
  gap:.8rem;
  flex-wrap:wrap;
  padding:.5rem 1.15rem;
  border-bottom:1px solid var(--border);
}
.status-summary .pill{
  padding:.15rem .6rem;
  border-radius:999px;
  font-size:.6rem;
  font-weight:600;
  font-family:var(--font-mono);
}
.status-summary .pill.pending{background:rgba(239,68,68,.1);color:var(--critical);border:1px solid rgba(239,68,68,.2);}
.status-summary .pill.in_progress{background:rgba(249,115,22,.1);color:var(--high);border:1px solid rgba(249,115,22,.2);}
.status-summary .pill.completed{background:rgba(16,185,129,.1);color:var(--success);border:1px solid rgba(16,185,129,.2);}
.status-summary .pill.discharged{background:rgba(37,99,235,.1);color:var(--primary);border:1px solid rgba(37,99,235,.2);}
.status-summary .pill.referred{background:rgba(14,165,233,.1);color:var(--secondary);border:1px solid rgba(14,165,233,.2);}
.status-summary .pill.deceased{background:rgba(100,116,139,.1);color:var(--text-muted);border:1px solid rgba(100,116,139,.2);}

/* ─── TABLE ─── */
.tbl-wrap{overflow-x:auto}
table{width:100%;border-collapse:collapse;font-size:.72rem}
th{padding:.45rem .75rem;font-size:.55rem;letter-spacing:.1em;text-transform:uppercase;color:var(--text-muted);font-weight:600;border-bottom:1px solid var(--border);text-align:left}
td{padding:.45rem .75rem;color:var(--text);border-bottom:1px solid rgba(0,0,0,.04)}
tr:hover{background:rgba(37,99,235,.02)}
.td-mono{font-family:var(--font-mono);font-size:.65rem}

.btn-edit-patient{
  padding:.15rem .4rem;
  background:rgba(37,99,235,.1);
  border:1px solid rgba(37,99,235,.2);
  border-radius:4px;
  font-size:.55rem;
  color:var(--primary);
  cursor:pointer;
  transition:all .2s;
  font-family:var(--font-mono);
}
.btn-edit-patient:hover{
  background:var(--primary);
  color:white;
}

.empty-patients{
  text-align:center;
  padding:2rem;
  color:var(--text-muted);
}
.empty-patients i{
  font-size:2rem;
  opacity:0.3;
  margin-bottom:.5rem;
  display:block;
}

/* ─── STATUS UPDATE SECTION ─── */
.status-update-section{
  background:var(--white);
  border:1px solid var(--border);
  border-radius:var(--radius);
  padding:1rem 1.15rem;
  box-shadow:var(--shadow);
}
.status-update-section h4{
  font-family:var(--font-display);
  font-size:.85rem;
  margin-bottom:.5rem;
}
.status-update-section h4 i{
  color:var(--primary);
  margin-right:.3rem;
}
.status-update-section form{
  display:flex;
  gap:.5rem;
  align-items:center;
  flex-wrap:wrap;
}
.status-update-section select{
  padding:.4rem .7rem;
  border:1px solid var(--border);
  border-radius:8px;
  font-size:.75rem;
  font-family:var(--font-primary);
  background:var(--white);
}
.status-update-section select:focus{
  border-color:var(--primary);
  outline:none;
  box-shadow:0 0 0 3px rgba(37,99,235,.1);
}
.btn-update{
  padding:.4rem 1rem;
  background:var(--success);
  color:white;
  border:none;
  border-radius:8px;
  font-size:.7rem;
  font-weight:600;
  cursor:pointer;
  transition:all .2s;
  font-family:var(--font-primary);
}
.btn-update:hover{
  background:#059669;
  transform:scale(1.02);
}

.status-hint{
  font-size:.55rem;
  color:var(--text-muted);
  margin-top:.4rem;
  width:100%;
}
.status-hint i{
  color:var(--primary);
}

/* ─── MODAL ─── */
.modal-bg{display:none;position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,.5);z-index:999;align-items:center;justify-content:center}
.modal-bg.show{display:flex}
.modal{background:var(--white);border-radius:var(--radius);max-width:500px;width:95%;max-height:90vh;overflow-y:auto;padding:1.5rem;position:relative;animation:modalIn .25s ease}
@keyframes modalIn{from{opacity:0;transform:scale(.95) translateY(10px)}to{opacity:1;transform:none}}
.modal-close{position:absolute;top:.8rem;right:1rem;background:none;border:none;font-size:1.2rem;cursor:pointer;color:var(--text-muted)}
.modal-close:hover{color:var(--critical)}
.modal-title{font-family:var(--font-display);font-size:1.2rem;font-weight:700;margin-bottom:.3rem}
.modal-title i{color:var(--primary);margin-right:.5rem}
.modal-sub{font-size:.7rem;color:var(--text-muted);margin-bottom:1rem}

/* ─── FORM ─── */
.form-group{margin-bottom:.8rem}
.form-group label{display:block;font-size:.65rem;font-weight:600;color:var(--text-muted);margin-bottom:.2rem}
.form-group label .required{color:var(--critical);margin-left:2px}
.form-group input,.form-group select,.form-group textarea{width:100%;padding:.4rem .6rem;border:1px solid var(--border);border-radius:8px;font-size:.75rem;font-family:var(--font-primary);outline:none;transition:border-color .2s}
.form-group input:focus,.form-group select:focus,.form-group textarea:focus{border-color:var(--primary);box-shadow:0 0 0 3px rgba(37,99,235,.1)}
.form-group textarea{resize:vertical;min-height:50px}
.form-group .form-row{display:grid;grid-template-columns:1fr 1fr;gap:.8rem}

.form-actions{display:flex;gap:.5rem;margin-top:1rem;padding-top:1rem;border-top:1px solid var(--border)}
.btn{display:inline-flex;align-items:center;gap:.3rem;padding:.15rem .5rem;border-radius:6px;font-size:.55rem;font-weight:600;border:none;cursor:pointer;transition:all .15s;font-family:var(--font-mono);text-decoration:none}
.btn-primary{background:var(--primary);color:white}
.btn-primary:hover{background:var(--primary-dark);transform:scale(1.02)}
.btn-outline{background:transparent;border:1px solid var(--border);color:var(--text)}
.btn-outline:hover{background:var(--bg3)}
.form-actions .btn{flex:1;padding:.6rem;justify-content:center;font-size:.7rem}

/* ─── FOOTER ─── */
.footer{flex-shrink:0;border-top:1px solid var(--border);
  padding:.5rem 1.4rem;display:flex;align-items:center;justify-content:space-between;
  font-size:.6rem;color:var(--text-muted);
  font-family:var(--font-mono);background:var(--white)}
.footer .dual-status-badge{background:rgba(37,99,235,.08);border:1px solid rgba(37,99,235,.2);border-radius:999px;padding:.1rem .5rem;font-size:.5rem;color:var(--primary);font-weight:700}

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

/* ─── RESPONSIVE ─── */
@media(max-width:1100px){
  .stats-row{grid-template-columns:repeat(2,1fr)}
}
@media(max-width:760px){
  :root{--sw:0px}
  .sidebar{display:none}
  .stats-row{grid-template-columns:1fr 1fr}
  .content{padding:.8rem}
  .tb-search{display:none}
  .accident-meta{grid-template-columns:1fr 1fr}
  .form-group .form-row{grid-template-columns:1fr}
}
</style>
</head>
<body>
<div class="shell">
<!-- SIDEBAR -->
<aside class="sidebar">
  <a href="dashboard.php" class="sb-brand">
    <div class="sb-logo">🏥</div>
    <div><div class="sb-title"><?= htmlspecialchars($hospital_station_name) ?></div>
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
    <a href="treatment.php" class="sb-item <?= is_active('treatment.php') ?>">
      <span class="sb-ic"><i class="fas fa-notes-medical"></i></span> Treatment Management
    </a>
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
    <div class="tb-title"><i class="fas fa-notes-medical"></i>Treatment Details</div>

    <a href="treatment.php" class="btn-back">
      <i class="fas fa-arrow-left"></i> Back
    </a>

    <div class="tb-search">
      <i class="fas fa-search"></i>
      <input type="text" placeholder="Search patients…" oninput="filterTable(this.value)"/>
    </div>

    <div class="tb-acts">
      <div class="live-pill"><span class="live-dot"></span>Live</div>

      <!-- Station Badge -->
      <div class="station-badge">
        <i class="fas fa-hospital"></i>
        <span class="station-name"><?= htmlspecialchars($hospital_station_name) ?></span>
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
            <button class="pd-item" onclick="window.location.href='settings.php'">
              <i class="fas fa-cog"></i> Settings
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

    <!-- ACCIDENT SUMMARY -->
    <div class="accident-summary">
      <div class="header">
        <h2>
          <i class="fas fa-car-crash"></i> 
          #ACC-<?= str_pad($accident_id, 4, '0', STR_PAD_LEFT) ?>
        </h2>
        <span class="badge <?= $accident['hospital_status'] ?>">
          <span class="badge-dot"></span>
          <?= str_replace('_', ' ', ucfirst($accident['hospital_status'] ?? 'unknown')) ?>
        </span>
      </div>

      <div class="accident-meta">
        <div class="meta-item">
          <label>Type</label>
          <div class="value"><?= htmlspecialchars($accident['accident_type'] ?? 'Unknown') ?></div>
        </div>
        <div class="meta-item">
          <label>Location</label>
          <div class="value"><?= htmlspecialchars($accident['location_description'] ?? 'Unknown') ?></div>
        </div>
        <div class="meta-item">
          <label>Reporter</label>
          <div class="value"><?= htmlspecialchars($accident['reporter_name'] ?? 'Unknown') ?></div>
        </div>
        <div class="meta-item">
          <label>Injuries</label>
          <div class="value"><?= $accident['injuries'] ?></div>
        </div>
        <div class="meta-item">
          <label>Received</label>
          <div class="value"><?= date('d M Y, H:i', strtotime($accident['created_at'])) ?></div>
        </div>
        <?php if($accident['police_station_name']): ?>
        <div class="meta-item">
          <label>Police Station</label>
          <div class="value">🚔 <?= htmlspecialchars($accident['police_station_name']) ?></div>
        </div>
        <?php endif; ?>
      </div>
    </div>

    <!-- PATIENTS SECTION -->
    <div class="patients-section">
      <div class="header">
        <h3><i class="fas fa-users"></i> Patients (<?= $patient_count ?>)</h3>
        <button class="btn-add-patient" onclick="openAddPatient(<?= $accident_id ?>)">
          <i class="fas fa-plus"></i> Add Patient
        </button>
      </div>

      <!-- Status Summary -->
      <?php if(!empty($status_counts)): ?>
      <div class="status-summary">
        <?php foreach($status_counts as $status => $count): ?>
          <span class="pill <?= $status ?>">
            <?= str_replace('_', ' ', ucfirst($status)) ?>: <?= $count ?>
          </span>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>

      <!-- Table -->
      <div class="tbl-wrap">
        <table>
          <thead>
            <tr>
              <th>#</th>
              <th>Patient Name</th>
              <th>Age</th>
              <th>Gender</th>
              <th>Blood Pressure</th>
              <th>Temp</th>
              <th>Status</th>
              <th>Doctor</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody id="tableBody">
          <?php if($patients && $patients->num_rows > 0):
            $i = 1;
            while($p = $patients->fetch_assoc()): ?>
            <tr>
              <td class="td-mono"><?= $i++ ?></td>
              <td style="font-weight:600;"><?= htmlspecialchars($p['patient_name'] ?: 'Unknown') ?></td>
              <td><?= $p['age'] ?? '-' ?></td>
              <td><?= ucfirst($p['gender'] ?? '-') ?></td>
              <td><?= htmlspecialchars($p['blood_pressure'] ?? '-') ?></td>
              <td><?= $p['temperature'] ? $p['temperature'].'°C' : '-' ?></td>
              <td>
                <span class="badge <?= getStatusBadge($p['treatment_status']) ?>">
                  <span class="badge-dot"></span>
                  <?= str_replace('_', ' ', ucfirst($p['treatment_status'] ?? 'pending')) ?>
                </span>
              </td>
              <td style="font-size:.65rem"><?= htmlspecialchars($p['doctor_name'] ?: '-') ?></td>
              <td>
                <button class="btn-edit-patient" onclick="openEditPatient(<?= $p['id'] ?>, <?= $accident_id ?>)">
                  <i class="fas fa-edit"></i> Edit
                </button>
              </td>
            </tr>
            <?php endwhile; ?>
          <?php else: ?>
            <tr>
              <td colspan="9">
                <div class="empty-patients">
                  <i class="fas fa-user-plus"></i>
                  <p>No patients added yet</p>
                  <p style="font-size:.65rem;color:var(--text-muted);">Click "Add Patient" to start treatment</p>
                </div>
              </td>
            </tr>
          <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <!-- UPDATE ACCIDENT STATUS -->
    <div class="status-update-section">
      <h4><i class="fas fa-flag-checkered"></i> Update Accident Status</h4>
      <form method="POST" action="update_accident_status.php">
        <input type="hidden" name="accident_id" value="<?= $accident_id ?>">
        <select name="hospital_status">
          <option value="patient_received" <?= $accident['hospital_status'] == 'patient_received' ? 'selected' : '' ?>>Patient Received</option>
          <option value="treated" <?= $accident['hospital_status'] == 'treated' ? 'selected' : '' ?>>Treated (Completed)</option>
          <option value="closed" <?= $accident['hospital_status'] == 'closed' ? 'selected' : '' ?>>Closed</option>
        </select>
        <button type="submit" class="btn-update">
          <i class="fas fa-save"></i> Update Status
        </button>
        <div class="status-hint">
          <i class="fas fa-info-circle"></i> 
          <strong>Treated</strong> = All patients have been treated. 
          <strong>Closed</strong> = Case is completely closed.
        </div>
      </form>
    </div>

  </div><!-- /content -->

  <div class="footer">
    <span>🏥 <?= htmlspecialchars($hospital_station_name) ?> &nbsp;·&nbsp; Treatment Details</span>
    <span class="dual-status-badge"><i class="fas fa-shield-alt"></i> treatment_patients</span>
    <span id="clock"></span>
  </div>
</div><!-- /main -->
</div><!-- /shell -->

<!-- ============================================ -->
<!-- ADD/EDIT PATIENT MODAL -->
<!-- ============================================ -->
<div class="modal-bg" id="patientModal">
  <div class="modal">
    <button class="modal-close" onclick="closeModal('patientModal')"><i class="fas fa-times"></i></button>
    <div class="modal-title" id="patientModalTitle">
      <i class="fas fa-user-plus"></i> Add Patient
    </div>
    <div class="modal-sub" id="patientModalSub">Enter patient details</div>
    
    <form method="POST" action="save_patient.php" id="patientForm">
      <input type="hidden" name="patient_id" id="patientId" value="0">
      <input type="hidden" name="accident_id" id="patientAccidentId" value="<?= $accident_id ?>">
      
      <div class="form-group">
        <label>Patient Name <span class="required">*</span></label>
        <input type="text" name="patient_name" id="patientName" placeholder="Full name" required>
      </div>
      
      <div class="form-group">
        <label>Age</label>
        <input type="number" name="age" id="patientAge" placeholder="Age">
      </div>
      
      <div class="form-group">
        <label>Gender</label>
        <select name="gender" id="patientGender">
          <option value="male">Male</option>
          <option value="female">Female</option>
          <option value="other">Other</option>
        </select>
      </div>
      
      <div class="form-group">
        <div class="form-row">
          <div>
            <label>Blood Pressure</label>
            <input type="text" name="blood_pressure" id="patientBloodPressure" placeholder="120/80">
          </div>
          <div>
            <label>Temperature (°C)</label>
            <input type="number" step="0.1" name="temperature" id="patientTemperature" placeholder="36.5">
          </div>
        </div>
      </div>
      
      <div class="form-group">
        <label>Diagnosis</label>
        <textarea name="diagnosis" id="patientDiagnosis" placeholder="Initial diagnosis" rows="2"></textarea>
      </div>
      
      <div class="form-group">
        <label>Treatment Given</label>
        <textarea name="treatment_given" id="patientTreatment" placeholder="Treatment provided" rows="2"></textarea>
      </div>
      
      <div class="form-group">
        <label>Medications</label>
        <textarea name="medications" id="patientMedications" placeholder="Medications prescribed" rows="2"></textarea>
      </div>
      
      <div class="form-group">
        <label>Doctor Name</label>
        <input type="text" name="doctor_name" id="patientDoctor" placeholder="Doctor's name">
      </div>
      
      <div class="form-group">
        <label>Treatment Status</label>
        <select name="treatment_status" id="patientStatus">
          <option value="pending">Pending</option>
          <option value="in_progress">In Progress</option>
          <option value="completed">Completed</option>
          <option value="discharged">Discharged</option>
          <option value="referred">Referred</option>
          <option value="deceased">Deceased</option>
        </select>
      </div>
      
      <div class="form-group">
        <label>Notes</label>
        <textarea name="notes" id="patientNotes" placeholder="Additional notes" rows="2"></textarea>
      </div>
      
      <div class="form-actions">
        <button type="button" class="btn btn-outline" onclick="closeModal('patientModal')">Cancel</button>
        <button type="submit" class="btn btn-primary">
          <i class="fas fa-save"></i> <span id="patientSubmitLabel">Save Patient</span>
        </button>
      </div>
    </form>
  </div>
</div>

<script>
// ── CLOCK ──
function tick(){const n=new Date();document.getElementById('clock').textContent=
  n.toLocaleDateString('en-GB',{weekday:'short',day:'2-digit',month:'short'})+
  ' · '+n.toLocaleTimeString('en-GB',{hour:'2-digit',minute:'2-digit',second:'2-digit'})}
setInterval(tick,1000);tick();

// ── MODAL FUNCTIONS ──
function openModal(id){document.getElementById(id).classList.add('show');}
function closeModal(id){document.getElementById(id).classList.remove('show');}

// ── ADD PATIENT ──
function openAddPatient(accidentId) {
    document.getElementById('patientModalTitle').innerHTML = '<i class="fas fa-user-plus"></i> Add Patient';
    document.getElementById('patientModalSub').textContent = 'Enter patient details for #ACC-' + String(accidentId).padStart(4, '0');
    document.getElementById('patientId').value = 0;
    document.getElementById('patientAccidentId').value = accidentId;
    document.getElementById('patientSubmitLabel').textContent = 'Save Patient';
    
    // Clear form
    document.getElementById('patientName').value = '';
    document.getElementById('patientAge').value = '';
    document.getElementById('patientGender').value = 'male';
    document.getElementById('patientBloodPressure').value = '';
    document.getElementById('patientTemperature').value = '';
    document.getElementById('patientDiagnosis').value = '';
    document.getElementById('patientTreatment').value = '';
    document.getElementById('patientMedications').value = '';
    document.getElementById('patientDoctor').value = '';
    document.getElementById('patientStatus').value = 'pending';
    document.getElementById('patientNotes').value = '';
    
    openModal('patientModal');
}

// ── EDIT PATIENT ──
function openEditPatient(patientId, accidentId) {
    fetch('get_patient.php?id=' + patientId)
        .then(response => response.json())
        .then(data => {
            if(data.error) {
                alert('Error: ' + data.error);
                return;
            }
            
            document.getElementById('patientModalTitle').innerHTML = '<i class="fas fa-user-edit"></i> Edit Patient';
            document.getElementById('patientModalSub').textContent = 'Update patient details for #ACC-' + String(accidentId).padStart(4, '0');
            document.getElementById('patientId').value = patientId;
            document.getElementById('patientAccidentId').value = accidentId;
            document.getElementById('patientSubmitLabel').textContent = 'Update Patient';
            
            document.getElementById('patientName').value = data.patient_name || '';
            document.getElementById('patientAge').value = data.age || '';
            document.getElementById('patientGender').value = data.gender || 'male';
            document.getElementById('patientBloodPressure').value = data.blood_pressure || '';
            document.getElementById('patientTemperature').value = data.temperature || '';
            document.getElementById('patientDiagnosis').value = data.diagnosis || '';
            document.getElementById('patientTreatment').value = data.treatment_given || '';
            document.getElementById('patientMedications').value = data.medications || '';
            document.getElementById('patientDoctor').value = data.doctor_name || '';
            document.getElementById('patientStatus').value = data.treatment_status || 'pending';
            document.getElementById('patientNotes').value = data.notes || '';
            
            openModal('patientModal');
        })
        .catch(error => {
            console.error('Error:', error);
            alert('Error loading patient data. Please try again.');
        });
}

// ── FILTER TABLE ──
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

// ── CLOSE ON ESCAPE ──
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        document.querySelectorAll('.modal-bg.show').forEach(m => m.classList.remove('show'));
    }
});

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
</script>

</body>
</html>