<?php
// police/live_map.php - Live Map with Google Maps Navigation.
session_start();
include __DIR__ . '/../connection.php';

// Check if user is logged in and is police
if (empty($_SESSION['user_id']) || strtolower($_SESSION['role'] ?? '') !== 'police') {
    header("Location: ../login.php");
    exit();
}

$police_id = $_SESSION['user_id'];
$police_name = htmlspecialchars($_SESSION['name'] ?? 'Police Officer');
$police_init = strtoupper(substr($police_name, 0, 1));

// ============================================
// GET POLICE OFFICER DETAILS & STATION
// ============================================
$user_query = $conn->query("
    SELECT u.*, ps.id as station_id, ps.name as station_name, 
           ps.location, ps.latitude, ps.longitude, ps.phone as station_phone
    FROM users u
    LEFT JOIN police_stations ps ON u.police_station_id = ps.id
    WHERE u.id = $police_id
");
$user = $user_query->fetch_assoc();

$police_station_id = $user['station_id'] ?? null;
$station_name = $user['station_name'] ?? 'No Station Assigned';
$station_lat = $user['latitude'] ?? null;
$station_lng = $user['longitude'] ?? null;
$station_location = $user['location'] ?? '';
$station_phone = $user['station_phone'] ?? '';

// ============================================
// 🔴 STATISTICS - Using police_status
// ============================================

// 🔴 PENDING = police_status = 'assigned'
$pending_query = $conn->query("
    SELECT COUNT(*) as total 
    FROM accidents 
    WHERE police_status = 'assigned' 
    AND assigned_police_id = $police_station_id
");
$pending_count = $pending_query->fetch_assoc()['total'] ?? 0;

// 🔴 ACTIVE = police_status IN ('assigned', 'dispatched', 'arrived')
$active_query = $conn->query("
    SELECT COUNT(*) as total 
    FROM accidents 
    WHERE assigned_police_id = $police_station_id
    AND police_status IN ('assigned', 'dispatched', 'arrived')
");
$active_count = $active_query->fetch_assoc()['total'] ?? 0;

// 🔴 RESOLVED = police_status = 'resolved'
$resolved_query = $conn->query("
    SELECT COUNT(*) as total 
    FROM accidents 
    WHERE police_status = 'resolved' 
    AND assigned_police_id = $police_station_id
");
$resolved_count = $resolved_query->fetch_assoc()['total'] ?? 0;

// ============================================
// GET ACCIDENT ID FROM URL - Using police_status
// ============================================
$accident_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
$selected_accident = null;

if ($accident_id > 0) {
    $acc_query = $conn->query("
        SELECT a.*, ps.name as police_station_name, hs.name as hospital_station_name,
               a.police_status as status
        FROM accidents a
        LEFT JOIN police_stations ps ON a.assigned_police_id = ps.id
        LEFT JOIN hospital_stations hs ON a.assigned_hospital_id = hs.id
        WHERE a.id = $accident_id 
        AND a.assigned_police_id = $police_station_id
    ");
    $selected_accident = $acc_query->fetch_assoc();
}

// ============================================
// GET ALL ACCIDENTS FOR MAP - Using police_status
// ============================================
$accidents_query = $conn->query("
    SELECT id, accident_type, location_description, police_status as status, 
           created_at, reporter_name, injuries, latitude, longitude, assigned_police_id
    FROM accidents 
    WHERE latitude IS NOT NULL AND longitude IS NOT NULL
    AND assigned_police_id = $police_station_id
    ORDER BY created_at DESC LIMIT 100
");

$map_data = [];
while($row = $accidents_query->fetch_assoc()) {
    $map_data[] = [
        'id' => (int)$row['id'],
        'lat' => (float)$row['latitude'],
        'lng' => (float)$row['longitude'],
        'type' => htmlspecialchars($row['accident_type']),
        'location' => htmlspecialchars(substr($row['location_description'] ?? 'Unknown', 0, 60)),
        'status' => $row['status'],
        'injuries' => (int)$row['injuries'],
        'reporter' => htmlspecialchars($row['reporter_name']),
        'time' => date('H:i d/m/Y', strtotime($row['created_at']))
    ];
}

// ============================================
// 🔴 PENDING ACCIDENTS FOR NOTIFICATION DROPDOWN - police_status = 'assigned'
// ============================================
$pending_accidents = $conn->query("
    SELECT id, accident_type, location_description, police_status as status, 
           created_at, reporter_name, injuries 
    FROM accidents 
    WHERE police_status = 'assigned' 
    AND assigned_police_id = $police_station_id
    ORDER BY created_at DESC LIMIT 10
");

$police_station = $station_name;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width,initial-scale=1.0"/>
<title>Live Map — Tanzania Police</title>
<link href="https://fonts.googleapis.com/css2?family=Sora:wght@300;400;500;600;700;800&family=Space+Grotesk:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet"/>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"/>
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"/>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>

<style>
/* ─── TOKENS ─── */
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{
  --bg: #0A0E17;
  --bg2: #0D1B5E;
  --bg3: #1A237E;
  --bg4: #283593;
  --bg5: #3949AB;
  --police-blue: #1A237E;
  --police-blue2: #0D1B5E;
  --police-gold: #E8C547;
  --police-gold2: #D4AF37;
  --gold-lo: rgba(232,197,71,0.1);
  --gold-lo2: rgba(232,197,71,0.06);
  --red: #F43F5E;
  --green: #4ADE80;
  --sky: #22D3EE;
  --amber: #FB923C;
  --light: #D6E8F5;
  --muted: #7A9BB5;
  --muted2: #94A3B8;
  --border: rgba(255,255,255,0.08);
  --bord2: rgba(255,255,255,0.15);
  --white: #FFFFFF;
  --sw: 260px;
  --th: 64px;
}
html,body{height:100%;background:var(--bg);color:var(--light);
  font-family:'Poppins',sans-serif;overflow:hidden}
::-webkit-scrollbar{width:4px;height:4px}
::-webkit-scrollbar-track{background:transparent}
::-webkit-scrollbar-thumb{background:var(--police-gold);border-radius:99px}
::-webkit-scrollbar-thumb:hover{background:var(--police-gold2)}

.shell{display:flex;height:100vh;overflow:hidden}

/* ─── SIDEBAR ─── */
.sidebar{
  width:var(--sw);flex-shrink:0;
  background:var(--bg2);border-right:1px solid var(--border);
  display:flex;flex-direction:column;
  position:relative;overflow:hidden;z-index:50;
}
.sidebar::before{content:'';position:absolute;top:-100px;left:-80px;
  width:300px;height:300px;border-radius:50%;pointer-events:none;
  background:radial-gradient(circle,rgba(232,197,71,.06) 0%,transparent 65%)}
.sidebar::after{content:'';position:absolute;inset:0;pointer-events:none;
  background-image:linear-gradient(rgba(232,197,71,.01) 1px,transparent 1px),
    linear-gradient(90deg,rgba(232,197,71,.01) 1px,transparent 1px);
  background-size:34px 34px}

.sb-brand{display:flex;align-items:center;gap:.75rem;
  padding:1.05rem 1.25rem;border-bottom:1px solid var(--border);
  text-decoration:none;flex-shrink:0;position:relative;z-index:2}
.sb-logo{width:38px;height:38px;border-radius:9px;flex-shrink:0;
  background:linear-gradient(135deg,var(--police-gold),var(--police-gold2));
  display:flex;align-items:center;justify-content:center;font-size:1.1rem;
  box-shadow:0 0 18px rgba(232,197,71,.3)}
.sb-title{font-family:'Sora',sans-serif;font-size:1.05rem;font-weight:800;
  letter-spacing:-.01em;color:var(--white)}
.sb-sub{font-size:.55rem;color:var(--muted);letter-spacing:.14em;
  text-transform:uppercase;font-family:'JetBrains Mono',monospace;margin-top:1px}

.sb-nav{flex:1;overflow-y:auto;padding:.65rem 0;position:relative;z-index:2}
.sb-sect{font-family:'JetBrains Mono',monospace;font-size:.56rem;
  letter-spacing:.16em;text-transform:uppercase;color:var(--muted);
  padding:.6rem 1.25rem .25rem}
.sb-item{display:flex;align-items:center;gap:.75rem;
  padding:.55rem 1rem;margin:1px .55rem;border-radius:9px;
  text-decoration:none;font-size:.78rem;font-weight:500;color:var(--muted2);
  transition:background .18s,color .18s;
  cursor:pointer;border:none;background:transparent;
  width:calc(100% - 1.1rem);text-align:left;
  font-family:'Space Grotesk',sans-serif;position:relative}
.sb-ic{width:28px;height:28px;border-radius:7px;flex-shrink:0;
  display:flex;align-items:center;justify-content:center;font-size:.8rem;
  background:rgba(255,255,255,.04);transition:background .18s,color .18s}
.sb-item:hover{background:var(--gold-lo2);color:var(--light)}
.sb-item:hover .sb-ic{background:var(--gold-lo);color:var(--police-gold)}
.sb-item.active{background:var(--gold-lo);color:var(--police-gold)}
.sb-item.active .sb-ic{background:rgba(232,197,71,.18);color:var(--police-gold)}
.sb-item.active::before{content:'';position:absolute;left:0;top:50%;
  transform:translateY(-50%);width:3px;height:56%;
  background:var(--police-gold);border-radius:0 3px 3px 0}
.sb-badge{margin-left:auto;font-size:.55rem;font-weight:700;
  padding:.12rem .4rem;border-radius:999px;
  font-family:'JetBrains Mono',monospace}
.sb-badge.red{background:rgba(244,63,94,.15);color:var(--red);
  border:1px solid rgba(244,63,94,.25);animation:badgePulse 2s infinite}
.sb-badge.grn{background:rgba(232,197,71,.12);color:var(--police-gold);
  border:1px solid rgba(232,197,71,.22)}
.sb-badge.green{background:rgba(74,222,128,.12);color:var(--green);
  border:1px solid rgba(74,222,128,.22)}
@keyframes badgePulse{0%,100%{box-shadow:0 0 0 0 rgba(244,63,94,.35)}
  50%{box-shadow:0 0 0 4px rgba(244,63,94,0)}}

.sb-logout-item{
  display:flex;align-items:center;gap:.75rem;
  padding:.55rem 1rem;margin:1px .55rem;border-radius:9px;
  font-size:.78rem;font-weight:500;color:var(--muted2);
  transition:background .18s,color .18s;
  cursor:pointer;border:none;background:transparent;
  width:calc(100% - 1.1rem);text-align:left;
  font-family:'Space Grotesk',sans-serif;text-decoration:none}
.sb-logout-item .sb-ic{width:28px;height:28px;border-radius:7px;flex-shrink:0;
  display:flex;align-items:center;justify-content:center;font-size:.8rem;
  background:rgba(255,255,255,.04)}
.sb-logout-item:hover{background:rgba(244,63,94,.08);color:var(--red)}
.sb-logout-item:hover .sb-ic{background:rgba(244,63,94,.14);color:var(--red)}
.sb-divider{height:1px;background:var(--border);margin:.4rem .8rem}

/* ─── MAIN ─── */
.main{flex:1;display:flex;flex-direction:column;overflow:hidden;min-width:0}

/* ─── TOPBAR ─── */
.topbar{height:var(--th);flex-shrink:0;
  background:var(--bg2);border-bottom:1px solid var(--border);
  display:flex;align-items:center;gap:.9rem;padding:0 1.4rem;
  position:relative;z-index:40}
.tb-title{font-family:'Sora',sans-serif;font-size:1.1rem;
  font-weight:700;color:var(--white);flex:1}
.tb-title i{margin-right:.5rem;color:var(--police-gold)}
.tb-search{display:flex;align-items:center;gap:.5rem;
  background:var(--bg3);border:1px solid var(--bord2);border-radius:9px;
  padding:.3rem .8rem;transition:border-color .2s}
.tb-search:focus-within{border-color:var(--police-gold)}
.tb-search input{background:none;border:none;outline:none;
  font-family:'Space Grotesk',sans-serif;font-size:.75rem;
  color:var(--light);width:160px}
.tb-search input::placeholder{color:var(--muted)}
.tb-search i{color:var(--muted);font-size:.75rem}
.tb-acts{display:flex;gap:.5rem;align-items:center}

.live-pill{display:flex;align-items:center;gap:.35rem;
  background:rgba(74,222,128,.08);border:1px solid rgba(74,222,128,.2);
  border-radius:999px;padding:.2rem .7rem;
  font-family:'JetBrains Mono',monospace;font-size:.55rem;color:var(--green)}
.live-dot{width:5px;height:5px;border-radius:50%;background:var(--green);
  animation:blink 1.3s step-end infinite}
@keyframes blink{0%,100%{opacity:1}50%{opacity:0}}

.notif-wrap{position:relative;display:inline-block}
.notif-btn{width:36px;height:36px;border-radius:9px;
  background:var(--bg3);border:1px solid var(--bord2);
  display:flex;align-items:center;justify-content:center;
  font-size:.85rem;color:var(--muted2);cursor:pointer;
  transition:background .18s,color .18s;position:relative}
.notif-btn:hover{background:var(--gold-lo);color:var(--police-gold)}
.notif-count{position:absolute;top:-6px;right:-6px;
  background:var(--red);color:#fff;font-size:.5rem;font-weight:700;
  min-width:18px;height:18px;border-radius:50%;
  display:flex;align-items:center;justify-content:center;
  font-family:'JetBrains Mono',monospace;border:2px solid var(--bg2);
  z-index:9999;padding:0 4px;box-shadow:0 0 10px rgba(244,63,94,0.4);
  animation:badgePulse 2s infinite}
.notif-drop{position:absolute;right:0;top:calc(100% + 8px);
  width:340px;background:var(--bg3);border:1px solid var(--bord2);
  border-radius:14px;overflow:hidden;box-shadow:0 16px 48px rgba(0,0,0,.55);
  opacity:0;transform:translateY(-8px) scale(.97);
  pointer-events:none;transition:opacity .2s,transform .2s;z-index:999}
.notif-drop.show{opacity:1;transform:none;pointer-events:all}
.notif-head{padding:.7rem 1rem;border-bottom:1px solid var(--border);
  display:flex;align-items:center;justify-content:space-between}
.nh-title{font-weight:700;font-size:.8rem;color:var(--white)}
.nh-sub{font-size:.65rem;color:var(--muted)}
.notif-list{max-height:280px;overflow-y:auto}
.notif-item{display:flex;align-items:flex-start;gap:.6rem;
  padding:.6rem 1rem;border-bottom:1px solid var(--border);
  cursor:pointer;transition:background .14s}
.notif-item:hover{background:var(--gold-lo2)}
.ndot{width:6px;height:6px;border-radius:50%;background:var(--red);
  flex-shrink:0;margin-top:3px;animation:badgePulse 1.5s infinite}
.nb{flex:1;min-width:0}
.nid{font-size:.7rem;font-weight:700;color:var(--police-gold);
  font-family:'JetBrains Mono',monospace}
.nloc{font-size:.65rem;color:var(--muted2);white-space:nowrap;
  overflow:hidden;text-overflow:ellipsis;margin-top:1px}
.nmeta{display:flex;gap:.4rem;align-items:center;margin-top:.2rem;flex-wrap:wrap}
.npill{font-size:.55rem;padding:.1rem .4rem;border-radius:999px;
  background:rgba(244,63,94,.1);color:var(--red);
  border:1px solid rgba(244,63,94,.2)}
.ntime{font-size:.58rem;color:var(--muted)}
.notif-foot{padding:.5rem 1rem;text-align:center;border-top:1px solid var(--border)}
.notif-foot a{font-size:.68rem;color:var(--sky);text-decoration:none}

.station-badge{display:flex;align-items:center;gap:.5rem;
  background:var(--bg3);border:1px solid var(--police-gold);
  border-radius:999px;padding:.2rem .8rem .2rem .4rem;
  font-size:.65rem;color:var(--white)}
.station-badge i{color:var(--police-gold);font-size:.7rem}
.station-badge .status-dot{width:6px;height:6px;border-radius:50%;
  background:var(--green);display:inline-block;animation:blink 1.3s step-end infinite}

.profile-wrap{position:relative}
.profile-btn{display:flex;align-items:center;gap:.5rem;
  background:var(--bg3);border:1px solid var(--bord2);
  border-radius:10px;padding:.3rem .6rem .3rem .4rem;
  cursor:pointer;transition:background .18s,border-color .18s}
.profile-btn:hover{background:var(--gold-lo2);border-color:var(--police-gold)}
.pa{width:28px;height:28px;border-radius:7px;flex-shrink:0;
  background:linear-gradient(135deg,var(--police-gold),var(--police-gold2));
  display:flex;align-items:center;justify-content:center;
  font-size:.75rem;font-weight:700;color:var(--bg2)}
.pname{font-size:.72rem;font-weight:600;color:var(--light);
  max-width:80px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.pchev{font-size:.65rem;color:var(--muted);transition:transform .22s}
.profile-btn.open .pchev{transform:rotate(180deg)}

.profile-drop{position:absolute;right:0;top:calc(100% + 8px);
  width:220px;background:var(--bg3);border:1px solid var(--bord2);
  border-radius:14px;overflow:hidden;box-shadow:0 16px 48px rgba(0,0,0,.55);
  opacity:0;transform:translateY(-8px) scale(.97);
  pointer-events:none;transition:opacity .2s,transform .2s;z-index:999}
.profile-drop.show{opacity:1;transform:none;pointer-events:all}
.pd-head{padding:.7rem 1rem .5rem;border-bottom:1px solid var(--border)}
.pd-name{font-weight:700;font-size:.8rem;color:var(--white)}
.pd-email{font-size:.62rem;color:var(--muted);margin-top:2px;
  font-family:'JetBrains Mono',monospace}
.pd-pill{display:inline-flex;align-items:center;gap:.25rem;
  background:var(--gold-lo);border:1px solid var(--police-gold);
  border-radius:999px;padding:.15rem .5rem;margin-top:.35rem;
  font-size:.55rem;color:var(--police-gold);font-family:'JetBrains Mono',monospace;
  letter-spacing:.06em;text-transform:uppercase}
.pd-items{padding:.3rem 0}
.pd-item{display:flex;align-items:center;gap:.6rem;
  padding:.45rem .8rem;font-size:.72rem;color:var(--muted2);
  text-decoration:none;cursor:pointer;
  transition:background .14s,color .14s;
  border:none;background:none;width:100%;text-align:left;
  font-family:'Space Grotesk',sans-serif}
.pd-item i{width:14px;text-align:center;font-size:.72rem;color:var(--muted)}
.pd-item:hover{background:var(--gold-lo2);color:var(--light)}
.pd-item:hover i{color:var(--police-gold)}
.pd-item.danger:hover{background:rgba(244,63,94,.08);color:var(--red)}
.pd-item.danger:hover i{color:var(--red)}
.pd-div{height:1px;background:var(--border);margin:.2rem 0}

.tb-btn{display:flex;align-items:center;gap:.3rem;
  padding:.3rem .6rem;border-radius:8px;font-size:.7rem;
  border:1px solid var(--bord2);background:transparent;
  color:var(--light);cursor:pointer;transition:background .18s;
  font-family:'Space Grotesk',sans-serif;text-decoration:none}
.tb-btn:hover{background:rgba(244,63,94,.08);color:var(--red);border-color:rgba(244,63,94,.25)}

/* ─── CONTENT ─── */
.content{flex:1;overflow-y:auto;padding:1.2rem 1.5rem}

/* ─── MAP ─── */
.map-container{background:var(--bg3);border:1px solid var(--border);border-radius:14px;overflow:hidden}
.map-header{display:flex;align-items:center;justify-content:space-between;padding:.7rem 1.15rem;border-bottom:1px solid var(--border)}
.map-header-title{font-family:'Sora',sans-serif;font-size:.95rem;font-weight:700;color:var(--white)}
.map-header-title i{margin-right:.5rem;color:var(--police-gold)}
.map-header-info{display:flex;gap:.8rem;align-items:center;font-size:.62rem;color:var(--muted2)}
.map-header-info .dot{width:7px;height:7px;border-radius:50%;display:inline-block}
.map-wrapper{height:calc(100vh - 280px);min-height:400px;width:100%}
#map{height:100%;width:100%;background:#0A1825}

/* ─── ACCIDENT SELECTOR ─── */
.accident-selector{background:var(--bg3);border:1px solid var(--border);border-radius:14px;padding:.8rem 1rem;margin-bottom:1rem;
  display:flex;align-items:center;gap:0.8rem;flex-wrap:wrap}
.accident-selector label{font-size:.7rem;color:var(--muted);font-weight:600}
.accident-selector select{background:var(--bg4);border:1px solid var(--border);border-radius:8px;padding:.5rem .8rem;
  font-family:'Space Grotesk',sans-serif;font-size:.75rem;color:var(--light);outline:none;flex:1;min-width:200px}
.accident-selector select:focus{border-color:var(--police-gold)}
.accident-selector .btn-route{background:var(--police-gold);color:#222;border:none;border-radius:8px;padding:.5rem 1.2rem;
  font-weight:700;font-size:.75rem;cursor:pointer;transition:all .15s;font-family:'Space Grotesk',sans-serif}
.accident-selector .btn-route:hover{background:var(--police-gold2);transform:scale(1.02)}
.accident-selector .btn-route:disabled{opacity:.5;cursor:not-allowed;transform:none}
.accident-selector .btn-route.navigate{background:#22D3EE;color:#222}
.accident-selector .btn-route.navigate:hover{background:#0ea5e9}

/* ─── ROUTE INFO ─── */
.route-info{background:var(--bg3);border:1px solid var(--border);border-radius:14px;padding:.8rem 1rem;margin-top:1rem;
  display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:.8rem}
.route-info .ri-item{display:flex;align-items:center;gap:.5rem}
.route-info .ri-item i{color:var(--police-gold);font-size:.9rem}
.route-info .ri-item .ri-label{font-size:.55rem;color:var(--muted);text-transform:uppercase}
.route-info .ri-item .ri-value{font-size:.9rem;font-weight:700;color:var(--white)}

.footer{flex-shrink:0;border-top:1px solid var(--border);padding:.5rem 1.4rem;display:flex;align-items:center;justify-content:space-between;font-size:.6rem;color:var(--muted);font-family:'JetBrains Mono',monospace;background:var(--bg2)}
.footer .dual-status-badge{background:rgba(232,197,71,.12);border:1px solid rgba(232,197,71,.22);border-radius:999px;padding:.1rem .5rem;font-size:.5rem;color:var(--police-gold);font-weight:700}

.grid-bg{position:fixed;inset:0;pointer-events:none;z-index:0;background-image:linear-gradient(rgba(232,197,71,.01) 1px,transparent 1px),linear-gradient(90deg,rgba(232,197,71,.01) 1px,transparent 1px);background-size:42px 42px}
.toast-wrap{position:fixed;top:1rem;right:1.4rem;z-index:9999;display:flex;flex-direction:column;gap:.4rem;pointer-events:none}
.toast{display:flex;align-items:flex-start;gap:.6rem;background:var(--bg3);border:1px solid var(--police-gold);border-left:3px solid var(--police-gold);border-radius:11px;padding:.6rem .8rem;min-width:240px;max-width:300px;box-shadow:0 8px 28px rgba(0,0,0,.45);animation:tIn .3s ease;pointer-events:all}
@keyframes tIn{from{opacity:0;transform:translateX(26px)}to{opacity:1;transform:none}}
.t-ic{font-size:.85rem;flex-shrink:0}.t-body{flex:1}.t-title{font-weight:700;font-size:.72rem;color:var(--police-gold);margin-bottom:.1rem}.t-msg{font-size:.65rem;color:var(--muted2)}.t-x{background:none;border:none;color:var(--muted);cursor:pointer;font-size:.7rem}

@media(max-width:760px){
  :root{--sw:0px}
  .sidebar{display:none}
  .content{padding:.8rem}
  .pname{display:none}
  .station-badge .station-name{display:none}
  .accident-selector{flex-direction:column}
  .accident-selector select{width:100%}
  .route-info{grid-template-columns:1fr 1fr}
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
    <div class="sb-logo">🚔</div>
    <div>
      <div class="sb-title">TANZANIA POLICE</div>
      <div class="sb-sub">Smart Response System</div>
    </div>
  </a>

  <nav class="sb-nav">
    <div class="sb-sect">Main</div>
    <a href="dashboard.php" class="sb-item <?= basename($_SERVER['PHP_SELF']) == 'dashboard.php' ? 'active' : '' ?>">
      <span class="sb-ic"><i class="fas fa-chart-line"></i></span> Dashboard
    </a>
    <a href="live_map.php" class="sb-item active">
      <span class="sb-ic"><i class="fas fa-map-marked-alt"></i></span> Live Map
    </a>
    <a href="view_accidents.php" class="sb-item <?= basename($_SERVER['PHP_SELF']) == 'view_accidents.php' ? 'active' : '' ?>">
      <span class="sb-ic"><i class="fas fa-car-crash"></i></span> Accidents
      <?php if($active_count > 0): ?>
        <span class="sb-badge grn"><?= $active_count ?></span>
      <?php endif; ?>
    </a>

    <div class="sb-sect" style="margin-top:.4rem">Dispatch</div>
    <a href="dispatch.php" class="sb-item <?= basename($_SERVER['PHP_SELF']) == 'dispatch.php' ? 'active' : '' ?>">
      <span class="sb-ic"><i class="fas fa-bullhorn"></i></span> Dispatch Center
      <?php if($pending_count > 0): ?>
        <span class="sb-badge red"><?= $pending_count ?></span>
      <?php endif; ?>
    
    <a href="resolved_cases.php" class="sb-item <?= basename($_SERVER['PHP_SELF']) == 'resolved_cases.php' ? 'active' : '' ?>">
      <span class="sb-ic"><i class="fas fa-check-circle"></i></span> Resolved Cases
      <?php if($resolved_count > 0): ?>
        <span class="sb-badge green"><?= $resolved_count ?></span>
      <?php endif; ?>
    </a>

    <div class="sb-sect" style="margin-top:.4rem">Account</div>
    <a href="profile.php" class="sb-item <?= basename($_SERVER['PHP_SELF']) == 'profile.php' ? 'active' : '' ?>">
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
    <div class="tb-title"><i class="fas fa-map-marked-alt"></i>Live Map</div>

    <div class="tb-search">
      <i class="fas fa-search"></i>
      <input type="text" placeholder="Search location…" oninput="filterAccidents(this.value)"/>
    </div>

    <div class="tb-acts">
      <div class="live-pill"><span class="live-dot"></span>Live</div>

      <div class="station-badge">
        <i class="fas fa-user-shield"></i>
        <span class="station-name"><?= htmlspecialchars($station_name) ?></span>
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
            <div><div class="nh-title">🚨 Pending Alerts</div>
              <div class="nh-sub">Accidents awaiting dispatch</div></div>
            <span style="font-size:.58rem;color:var(--muted);font-family:'JetBrains Mono',monospace"><?= $pending_count ?> pending</span>
          </div>
          <div class="notif-list">
            <?php if($pending_accidents && $pending_accidents->num_rows > 0):
              while($al = $pending_accidents->fetch_assoc()): ?>
            <div class="notif-item" onclick="window.location.href='dispatch.php'">
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
              <i class="fas fa-chevron-right" style="font-size:.62rem;color:var(--muted);flex-shrink:0;margin-top:2px"></i>
            </div>
            <?php endwhile; else: ?>
            <div style="padding:1.5rem;text-align:center"><i class="fas fa-check-circle" style="font-size:1.8rem;color:var(--green);opacity:.45"></i><p style="font-size:.7rem;color:var(--muted);margin-top:.4rem">No pending alerts</p></div>
            <?php endif; ?>
          </div>
          <div class="notif-foot"><a href="dispatch.php">📋 View all pending →</a></div>
        </div>
      </div>

      <!-- Profile Button -->
      <div class="profile-wrap">
        <button class="profile-btn" id="profileBtn" onclick="toggleProfileDrop()">
          <div class="pa"><?= $police_init ?></div>
          <span class="pname"><?= $police_name ?></span>
          <i class="fas fa-chevron-down pchev"></i>
        </button>
        <div class="profile-drop" id="profileDrop">
          <div class="pd-head"><div class="pd-name"><?= $police_name ?></div><div class="pd-email"><?= $_SESSION['email'] ?? 'police@roadalert.tz' ?></div><div class="pd-pill"><i class="fas fa-shield-alt"></i> Police</div></div>
          <div class="pd-items">
            <button class="pd-item" onclick="window.location.href='profile.php'"><i class="fas fa-user-edit"></i> Profile</button>
            <button class="pd-item" onclick="window.location.href='settings.php'"><i class="fas fa-cog"></i> Settings</button>
            <div class="pd-div"></div>
            <a href="../logout.php" class="pd-item danger"><i class="fas fa-sign-out-alt"></i> Logout</a>
          </div>
        </div>
      </div>

      <a href="../logout.php" class="tb-btn"><i class="fas fa-sign-out-alt"></i></a>
    </div>
  </div>

  <!-- ═══ CONTENT ═══ -->
  <div class="content">

    <!-- Accident Selector -->
    <div class="accident-selector">
      <label><i class="fas fa-car-crash"></i> Select Accident:</label>
      <select id="accidentSelect" onchange="selectAccident(this.value)">
        <option value="">-- Choose an accident to navigate --</option>
        <?php foreach($map_data as $acc): ?>
          <option value="<?= $acc['id'] ?>" <?= ($accident_id == $acc['id']) ? 'selected' : '' ?>>
            #ACC-<?= str_pad($acc['id'], 4, '0', STR_PAD_LEFT) ?> - <?= $acc['type'] ?> (<?= $acc['status'] ?>)
          </option>
        <?php endforeach; ?>
      </select>
      
      <!-- 🔴 Show Route - Opens Google Maps -->
      <button class="btn-route" id="routeBtn" onclick="showRoute()" <?= !$selected_accident ? 'disabled' : '' ?>>
        <i class="fas fa-route"></i> Show Route
      </button> 
    </div>

    <!-- Map Container -->
    <div class="map-container">
      <div class="map-header">
        <div class="map-header-title"><i class="fas fa-map"></i> Incident Map</div>
        <div class="map-header-info">
          <span><span class="dot" style="background:#F43F5E"></span> Pending</span>
          <span><span class="dot" style="background:#22D3EE"></span> Dispatched</span>
          <span><span class="dot" style="background:#FB923C"></span> Arrived</span>
          <span><span class="dot" style="background:#4ADE80"></span> Resolved</span>
          <span><i class="fas fa-flag-checkered" style="color:var(--police-gold)"></i> Police Station</span>
        </div>
      </div>
      <div class="map-wrapper">
        <div id="map"></div>
      </div>
    </div>

    <!-- Route Info -->
    <div class="route-info" id="routeInfo">
      <div class="ri-item">
        <i class="fas fa-flag-checkered"></i>
        <div>
          <div class="ri-label">From (Police Station)</div>
          <div class="ri-value" id="fromLocation"><?= htmlspecialchars($station_name) ?></div>
        </div>
      </div>
      <div class="ri-item">
        <i class="fas fa-map-pin"></i>
        <div>
          <div class="ri-label">To (Accident Location)</div>
          <div class="ri-value" id="toLocation"><?= $selected_accident ? htmlspecialchars(substr($selected_accident['location_description'] ?? 'Unknown', 0, 30)) : 'Select an accident' ?></div>
        </div>
      </div>
      <div class="ri-item">
        <i class="fas fa-road"></i>
        <div>
          <div class="ri-label">Distance</div>
          <div class="ri-value" id="distanceValue">-- km</div>
        </div>
      </div>
      <div class="ri-item">
        <i class="fas fa-clock"></i>
        <div>
          <div class="ri-label">Estimated Time</div>
          <div class="ri-value" id="timeValue">-- min</div>
        </div>
      </div>
    </div>

  </div><!-- /content -->

  <div class="footer">
    <span>🚔 Tanzania Police - Navigate & Share Location Direct Through Geogle Map&nbsp;·&nbsp; </span>
    <span class="dual-status-badge"><i class="fas fa-shield-alt"></i> Police Live Map</span>
    <span id="clock"></span>
  </div>
</div><!-- /main -->
</div><!-- /shell -->

<script>
// ── CLOCK ──
function tick(){
  const n=new Date();
  document.getElementById('clock').textContent=
    n.toLocaleDateString('en-GB',{weekday:'short',day:'2-digit',month:'short'})+
    ' · '+n.toLocaleTimeString('en-GB',{hour:'2-digit',minute:'2-digit',second:'2-digit'});
}
setInterval(tick,1000);tick();

// ── MAP DATA ──
const MAP_DATA = <?= json_encode($map_data) ?>;
const STATION_LAT = <?= $station_lat ?? 0 ?>;
const STATION_LNG = <?= $station_lng ?? 0 ?>;
const STATION_NAME = "<?= addslashes($station_name) ?>";

console.log('✅ MAP_DATA loaded:', MAP_DATA.length, 'accidents');
console.log('📍 STATION:', STATION_NAME, STATION_LAT, STATION_LNG);

let map, selectedAccidentId = <?= $accident_id ?: 0 ?>;

// ── SELECT ACCIDENT ──
function selectAccident(id) {
    console.log('🔍 selectAccident called with id:', id);
    
    if (!id) {
        showToast('⚠️ Warning', 'Please select an accident', 'err');
        return;
    }
    
    selectedAccidentId = parseInt(id);
    console.log('✅ Selected accident ID (int):', selectedAccidentId);
    
    document.getElementById('accidentSelect').value = selectedAccidentId;
    document.getElementById('routeBtn').disabled = false;
    
    const acc = MAP_DATA.find(a => parseInt(a.id) === selectedAccidentId);
    console.log('📍 Accident found:', acc);
    
    if (acc) {
        document.getElementById('toLocation').textContent = acc.location.substring(0, 30);
        map.setView([acc.lat, acc.lng], 14);
        
        // Update route info with estimated distance
        if (STATION_LAT && STATION_LNG) {
            const distance = calculateDistance(STATION_LAT, STATION_LNG, acc.lat, acc.lng);
            document.getElementById('distanceValue').textContent = distance.toFixed(1) + ' km';
            
            // Estimate time (assuming average speed 40 km/h)
            const time = (distance / 40) * 60;
            document.getElementById('timeValue').textContent = Math.round(time) + ' min';
        }
    } else {
        console.log('❌ Accident NOT found');
        console.log('Available IDs:', MAP_DATA.map(a => a.id));
        showToast('❌ Error', 'Accident not found in map data', 'err');
    }
}

// ── CALCULATE DISTANCE (Haversine formula) ──
function calculateDistance(lat1, lon1, lat2, lon2) {
    const R = 6371; // Radius of Earth in km
    const dLat = (lat2 - lat1) * Math.PI / 180;
    const dLon = (lon2 - lon1) * Math.PI / 180;
    const a = Math.sin(dLat/2) * Math.sin(dLat/2) +
              Math.cos(lat1 * Math.PI / 180) * Math.cos(lat2 * Math.PI / 180) *
              Math.sin(dLon/2) * Math.sin(dLon/2);
    const c = 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1-a));
    return R * c;
}

// ── SHOW ROUTE (Google Maps) ──
function showRoute() {
    console.log('🛣️ showRoute called');
    
    if (!selectedAccidentId) {
        showToast('⚠️ Warning', 'Please select an accident first', 'err');
        return;
    }
    
    const accident = MAP_DATA.find(a => parseInt(a.id) === selectedAccidentId);
    if (!accident) {
        showToast('❌ Error', 'Accident not found', 'err');
        return;
    }
    
    const fromLat = STATION_LAT || -6.7924;
    const fromLng = STATION_LNG || 39.2083;
    const toLat = accident.lat;
    const toLng = accident.lng;
    
    // Calculate distance
    const distance = calculateDistance(fromLat, fromLng, toLat, toLng);
    const time = (distance / 40) * 60;
    
    // Update route info
    document.getElementById('distanceValue').textContent = distance.toFixed(1) + ' km';
    document.getElementById('timeValue').textContent = Math.round(time) + ' min';
    document.getElementById('toLocation').textContent = accident.location.substring(0, 30);
    
    // Open Google Maps
    const url = `https://www.google.com/maps/dir/${fromLat},${fromLng}/${toLat},${toLng}`;
    console.log('🔗 Opening Google Maps URL:', url);
    
    const newWindow = window.open(url, '_blank');
    if (!newWindow || newWindow.closed || typeof newWindow.closed === 'undefined') {
        showToast('⚠️ Popup Blocked', 'Please allow popups or use Navigate button', 'err');
        console.log('URL to copy:', url);
    } else {
        showToast('🗺️ Opening Google Maps', `Distance: ${distance.toFixed(1)} km | Est. time: ${Math.round(time)} min`, 'ok');
    }
}

// ── NAVIGATE TO ACCIDENT (Google Maps) ──
function navigateToAccident() {
    console.log('🧭 navigateToAccident called');
    showRoute();
}

// ── INIT MAP ──
function initMap() {
    console.log('🗺️ initMap called, MAP_DATA count:', MAP_DATA.length);
    
    const center = (MAP_DATA.length > 0 && selectedAccidentId > 0) 
        ? MAP_DATA.find(a => parseInt(a.id) === selectedAccidentId) 
        : null;
    
    const lat = center ? center.lat : (STATION_LAT || -6.7924);
    const lng = center ? center.lng : (STATION_LNG || 39.2083);
    
    map = L.map('map').setView([lat, lng], 13);

    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>'
    }).addTo(map);

    L.control.scale({metric: true, imperial: false}).addTo(map);

    addAccidentMarkers();
    addPoliceStationMarker();

    // 🔴 Auto-select after map is ready
    setTimeout(function() {
        const urlParams = new URLSearchParams(window.location.search);
        const urlId = urlParams.get('id');
        
        let targetId = null;
        
        if (urlId && parseInt(urlId) > 0) {
            targetId = parseInt(urlId);
            console.log('📌 Accident ID from URL:', targetId);
        } else {
            const assignedAcc = MAP_DATA.find(a => a.status === 'assigned');
            if (assignedAcc) {
                targetId = parseInt(assignedAcc.id);
                console.log('📌 Found assigned accident:', targetId);
            } else if (MAP_DATA.length > 0) {
                targetId = parseInt(MAP_DATA[0].id);
                console.log('📌 Default to first accident:', targetId);
            }
        }
        
        if (targetId) {
            const acc = MAP_DATA.find(a => parseInt(a.id) === targetId);
            if (acc) {
                console.log('✅ Auto-selecting accident:', targetId);
                document.getElementById('accidentSelect').value = targetId;
                setTimeout(() => selectAccident(targetId), 500);
            }
        }
    }, 1000);
}

// ── ADD POLICE STATION MARKER ──
function addPoliceStationMarker() {
    if (STATION_LAT && STATION_LNG) {
        const stationIcon = L.divIcon({
            html: `<div style="width:24px;height:24px;border-radius:50%;background:#E8C547;border:3px solid #fff;display:flex;align-items:center;justify-content:center;box-shadow:0 2px 10px rgba(232,197,71,0.5);">
                    <i class="fas fa-flag-checkered" style="color:#0D1B5E;font-size:12px;"></i>
                   </div>`,
            iconSize: [24, 24],
            iconAnchor: [12, 12],
            className: ''
        });
        
        const marker = L.marker([STATION_LAT, STATION_LNG], {icon: stationIcon}).addTo(map);
        marker.bindPopup(`
            <div style="font-family:'Space Grotesk',sans-serif;min-width:180px;">
                <div style="font-weight:700;color:#E8C547;font-size:.85rem;">🚔 Police Station</div>
                <div style="font-size:.7rem;color:#94a3b8;">${STATION_NAME}</div>
                <div style="font-size:.6rem;color:#64748b;margin-top:3px;">Your current location</div>
            </div>
        `);
    }
}

// ── ADD ACCIDENT MARKERS ──
function addAccidentMarkers() {
    console.log('📍 Adding accident markers...');
    
    // 🔴 Color mapping for police_status
    MAP_DATA.forEach(acc => {
        let color;
        if (acc.status === 'assigned' || acc.status === 'pending') {
            color = '#F43F5E';
        } else if (acc.status === 'dispatched') {
            color = '#22D3EE';
        } else if (acc.status === 'arrived') {
            color = '#FB923C';
        } else if (acc.status === 'resolved') {
            color = '#4ADE80';
        } else {
            color = '#94A3B8'; // default gray
        }
        
        const icon = L.divIcon({
            html: `<div style="width:18px;height:18px;border-radius:50%;background:${color};border:2.5px solid #fff;box-shadow:0 2px 8px rgba(0,0,0,.35);display:flex;align-items:center;justify-content:center;">
                    <i class="fas fa-car-crash" style="color:#fff;font-size:8px;"></i>
                   </div>`,
            iconSize: [18, 18],
            iconAnchor: [9, 9],
            className: ''
        });
        
        const marker = L.marker([acc.lat, acc.lng], {icon: icon}).addTo(map);
        const isSelected = parseInt(acc.id) === selectedAccidentId;
        
        marker.bindPopup(`
            <div style="font-family:'Space Grotesk',sans-serif;min-width:200px;background:#1A237E;border-radius:12px;padding:10px;border:1px solid #E8C547;">
                <div style="font-weight:700;font-size:.85rem;color:#E8C547;margin-bottom:3px;">
                    🚨 #ACC-${String(acc.id).padStart(4, '0')}
                </div>
                <div style="font-size:.7rem;color:#FFFFFF;margin-bottom:2px;">${acc.type}</div>
                <div style="font-size:.65rem;color:#94a3b8;margin-bottom:4px;">${acc.location}</div>
                <div style="display:flex;gap:6px;font-size:.65rem;align-items:center;flex-wrap:wrap;color:#fff;">
                    <span>🤕 ${acc.injuries} injured</span>
                    <span style="color:${color};font-weight:700;text-transform:uppercase;">${acc.status}</span>
                </div>
                <div style="font-size:.6rem;color:#94a3b8;margin-top:3px;">${acc.time}</div>
                <hr style="border:none;border-top:1px solid #E8C547;margin:6px 0;">
                <button onclick="selectAccident(${acc.id})" style="background:#E8C547;color:#222;border:none;border-radius:6px;padding:4px 12px;font-size:10px;font-weight:700;cursor:pointer;width:100%;">
                    <i class="fas fa-route"></i> Show Route
                </button>
            </div>
        `);
        
        if (isSelected) {
            setTimeout(() => marker.openPopup(), 1500);
        }
    });
}

// ── FILTER ACCIDENTS ──
function filterAccidents(q) {
    const select = document.getElementById('accidentSelect');
    const options = select.options;
    q = q.toLowerCase();
    for (let i = 1; i < options.length; i++) {
        const text = options[i].text.toLowerCase();
        options[i].style.display = text.includes(q) ? '' : 'none';
    }
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

// ── CLOSE ON OUTSIDE ──
document.addEventListener('click',function(e){
  if(!e.target.closest('#notifDrop')&&!e.target.closest('.notif-btn'))
    document.getElementById('notifDrop').classList.remove('show');
  if(!e.target.closest('#profileDrop')&&!e.target.closest('#profileBtn'))
    closeProfileDrop();
});

// ── INIT MAP ──
document.addEventListener('DOMContentLoaded', function() {
    console.log('✅ DOM loaded, initializing map...');
    initMap();
});
</script>
</body>
</html>