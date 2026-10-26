<?php
// police/dashboard.php - Tanzania Police Dashboard.
session_start();
include __DIR__ . '/../connection.php';

// Check if user is logged in and is police
if (empty($_SESSION['user_id']) || strtolower($_SESSION['role'] ?? '') !== 'police') {
    header("Location: ../login.php");
    exit();
}

// ============================================
// 🔴 AUDIT LOG - VIEW DASHBOARD (OPTIONAL)
// ============================================
// createAuditLog($conn, 'Viewed police dashboard');

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
// 🔴 STATISTICS - KWA STATION HII (DUAL STATUS)
// ============================================

// 🔴 1. PENDING = police_status = 'assigned' (zimesubiri police kudispatch)
$pending_query = $conn->query("
    SELECT COUNT(*) as total 
    FROM accidents 
    WHERE police_status = 'assigned' 
    AND assigned_police_id = $police_station_id
");
$pending_count = $pending_query->fetch_assoc()['total'] ?? 0;

// 🔴 2. DISPATCHED = police_status = 'dispatched'
$dispatched_query = $conn->query("
    SELECT COUNT(*) as total 
    FROM accidents 
    WHERE police_status = 'dispatched' 
    AND assigned_police_id = $police_station_id
");
$dispatched_count = $dispatched_query->fetch_assoc()['total'] ?? 0;

// 🔴 3. RESOLVED = police_status = 'resolved'
$resolved_query = $conn->query("
    SELECT COUNT(*) as total 
    FROM accidents 
    WHERE police_status = 'resolved' 
    AND assigned_police_id = $police_station_id
");
$resolved_count = $resolved_query->fetch_assoc()['total'] ?? 0;

// 🔴 4. ARRIVED = police_status = 'arrived'
$arrived_query = $conn->query("
    SELECT COUNT(*) as total 
    FROM accidents 
    WHERE police_status = 'arrived' 
    AND assigned_police_id = $police_station_id
");
$arrived_count = $arrived_query->fetch_assoc()['total'] ?? 0;

// 🔴 5. FALSE ALARM = police_status = 'false_alarm'
$false_alarm_query = $conn->query("
    SELECT COUNT(*) as total 
    FROM accidents 
    WHERE police_status = 'false_alarm' 
    AND assigned_police_id = $police_station_id
");
$false_alarm_count = $false_alarm_query->fetch_assoc()['total'] ?? 0;

// 🔴 6. ACTIVE = pending (assigned) + dispatched + arrived
$active_query = $conn->query("
    SELECT COUNT(*) as total 
    FROM accidents 
    WHERE assigned_police_id = $police_station_id
    AND police_status IN ('assigned', 'dispatched', 'arrived')
");
$active_count = $active_query->fetch_assoc()['total'] ?? 0;

// ============================================
// GET ACCIDENTS WITH POLICE_STATUS
// ============================================
$accidents_query = $conn->query("
    SELECT 
        a.*,
        ps.name as police_station_name,
        hs.name as hospital_station_name,
        a.police_status as status,
        CASE 
            WHEN a.latitude IS NOT NULL AND a.longitude IS NOT NULL AND $station_lat IS NOT NULL 
            THEN (6371 * acos(
                cos(radians($station_lat)) * 
                cos(radians(a.latitude)) * 
                cos(radians(a.longitude) - radians($station_lng)) + 
                sin(radians($station_lat)) * 
                sin(radians(a.latitude))
            ))
            ELSE NULL
        END AS distance_km
    FROM accidents a
    LEFT JOIN police_stations ps ON a.assigned_police_id = ps.id
    LEFT JOIN hospital_stations hs ON a.assigned_hospital_id = hs.id
    WHERE a.assigned_police_id = $police_station_id
    ORDER BY a.created_at DESC
");

// ============================================
// GET ACCIDENTS WITH COORDINATES FOR MAP
// ============================================
$map_query = $conn->query("
    SELECT id, accident_type, location_description, police_status as status, 
           created_at, reporter_name, injuries, latitude, longitude 
    FROM accidents 
    WHERE latitude IS NOT NULL AND longitude IS NOT NULL
    AND assigned_police_id = $police_station_id
    ORDER BY created_at DESC LIMIT 50
");
$map_data = [];
while($row = $map_query->fetch_assoc()) {
    $map_data[] = [
        'id' => $row['id'],
        'lat' => (float)$row['latitude'],
        'lng' => (float)$row['longitude'],
        'type' => htmlspecialchars($row['accident_type']),
        'location' => htmlspecialchars(substr($row['location_description'] ?? 'Unknown', 0, 60)),
        'status' => $row['status'],
        'injuries' => $row['injuries'],
        'reporter' => htmlspecialchars($row['reporter_name']),
        'time' => date('H:i d/m/Y', strtotime($row['created_at']))
    ];
}

// ============================================
// GET WEEKLY STATS FOR CHART
// ============================================
$weekly_query = $conn->query("
    SELECT DAYNAME(created_at) as day, COUNT(*) as count 
    FROM accidents 
    WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
    AND assigned_police_id = $police_station_id
    GROUP BY DAYNAME(created_at)
");
$week_days = ['Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday'];
$week_data = array_fill(0, 7, 0);
while($r = $weekly_query->fetch_assoc()) {
    $i = array_search($r['day'], $week_days);
    if($i !== false) $week_data[$i] = $r['count'];
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
<title>Police Dashboard — Tanzania Police</title>
<link href="https://fonts.googleapis.com/css2?family=Sora:wght@300;400;500;600;700;800&family=Space+Grotesk:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet"/>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"/>
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"/>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>

<!-- 🔴 SOUND FOR NOTIFICATIONS -->
<audio id="notificationSound" preload="auto">
    <source src="../assets/sounds/notification.mp3" type="audio/mpeg">
    <source src="../assets/sounds/notification.wav" type="audio/wav">
</audio>

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
.tb-title span{color:var(--police-gold)}
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

/* 🔴 NOTIFICATION BELL - FIXED */
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

/* 🔴 NOTIFICATION ANIMATIONS */
.notif-btn.has-notifications {
    animation: emergencyPulse 0.8s ease 3;
    background: rgba(244,63,94,0.15);
    border-color: var(--red);
}
@keyframes emergencyPulse {
    0%, 100% { 
        box-shadow: 0 0 0 0 rgba(244,63,94,0.4);
        transform: scale(1);
    }
    50% { 
        box-shadow: 0 0 0 12px rgba(244,63,94,0);
        transform: scale(1.1);
    }
}

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

/* 🔴 STATION BADGE */
.station-badge{display:flex;align-items:center;gap:.5rem;
  background:var(--bg3);border:1px solid var(--police-gold);
  border-radius:999px;padding:.2rem .8rem .2rem .4rem;
  font-size:.65rem;color:var(--white)}
.station-badge i{color:var(--police-gold);font-size:.7rem}
.station-badge .status-dot{width:6px;height:6px;border-radius:50%;
  background:var(--green);display:inline-block;animation:blink 1.3s step-end infinite}

/* ─── PROFILE BUTTON ─── */
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

/* ─── STAT CARDS ─── */
.stats-row{display:grid;grid-template-columns:repeat(4,1fr);gap:1rem;margin-bottom:1.1rem}
.sc{background:var(--bg3);border:1px solid var(--border);border-radius:14px;
  padding:1rem 1.2rem;position:relative;overflow:hidden;
  transition:border-color .22s,transform .18s}
.sc:hover{border-color:var(--bord2);transform:translateY(-2px)}
.sc::after{content:'';position:absolute;top:0;left:0;right:0;height:2px;
  opacity:0;transition:opacity .25s}
.sc:hover::after{opacity:1}
.sc.c1::after{background:linear-gradient(90deg,var(--police-gold),transparent)}
.sc.c2::after{background:linear-gradient(90deg,var(--red),transparent)}
.sc.c3::after{background:linear-gradient(90deg,var(--sky),transparent)}
.sc.c4::after{background:linear-gradient(90deg,var(--green),transparent)}
.sc-ico{width:34px;height:34px;border-radius:8px;
  display:flex;align-items:center;justify-content:center;
  font-size:.8rem;margin-bottom:.7rem}
.c1 .sc-ico{background:rgba(232,197,71,.1);color:var(--police-gold)}
.c2 .sc-ico{background:rgba(244,63,94,.1);color:var(--red)}
.c3 .sc-ico{background:rgba(34,211,238,.1);color:var(--sky)}
.c4 .sc-ico{background:rgba(74,222,128,.1);color:var(--green)}
.sc-num{font-family:'Sora',sans-serif;font-size:1.8rem;
  font-weight:800;line-height:1;margin-bottom:.15rem}
.c1 .sc-num{color:var(--police-gold)}
.c2 .sc-num{color:var(--red)}
.c3 .sc-num{color:var(--sky)}
.c4 .sc-num{color:var(--green)}
.sc-lbl{font-size:.62rem;color:var(--muted);letter-spacing:.09em;text-transform:uppercase}
.sc-tag{position:absolute;top:1rem;right:1rem;
  font-size:.55rem;font-family:'JetBrains Mono',monospace;color:var(--muted)}

/* ─── PANELS ─── */
.panel{background:var(--bg3);border:1px solid var(--border);
  border-radius:14px;overflow:hidden;display:flex;flex-direction:column}
.ph{display:flex;align-items:center;justify-content:space-between;
  padding:.7rem 1.15rem;border-bottom:1px solid var(--border);flex-shrink:0}
.ph-t{font-family:'Sora',sans-serif;font-size:.95rem;font-weight:700;color:var(--white)}
.ph-t i{margin-right:.5rem;color:var(--police-gold)}
.ph-s{font-size:.62rem;color:var(--muted);margin-top:.1rem}
.ph-a{font-size:.65rem;color:var(--sky);text-decoration:none;
  cursor:pointer;background:none;border:none;
  font-family:'Space Grotesk',sans-serif;transition:opacity .18s}
.ph-a:hover{opacity:.7}

/* ─── MAP ─── */
.map-row{margin-bottom:1.1rem}
.map-legend{display:flex;gap:.8rem;align-items:center}
.leg{display:flex;align-items:center;gap:.25rem;font-size:.62rem;color:var(--muted2)}
.leg-d{width:7px;height:7px;border-radius:50%}
#policeMap{height:320px;width:100%;border-radius:0 0 14px 14px;background:#0A1825}

/* ─── TABLE ─── */
.tbl-wrap{overflow-x:auto;flex:1}
table{width:100%;border-collapse:collapse;font-size:.72rem}
thead th{padding:.45rem .75rem;font-size:.55rem;letter-spacing:.1em;
  text-transform:uppercase;color:var(--muted);font-weight:600;
  border-bottom:1px solid var(--border);white-space:nowrap;text-align:left}
tbody tr{border-bottom:1px solid rgba(255,255,255,.04);transition:background .12s}
tbody tr:hover{background:rgba(232,197,71,.03)}
tbody td{padding:.45rem .75rem;color:var(--light);vertical-align:middle}
.td-id{font-family:'JetBrains Mono',monospace;font-size:.65rem;color:var(--muted2)}
.td-loc{font-size:.65rem;color:var(--muted2);max-width:140px;
  overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.td-time{font-size:.6rem;color:var(--muted);white-space:nowrap}
.td-dist{font-size:.6rem;color:var(--police-gold);font-weight:600}

/* 🔴 BADGE - Police Status */
.badge{display:inline-flex;align-items:center;gap:.2rem;
  padding:.15rem .5rem;border-radius:999px;
  font-family:'JetBrains Mono',monospace;font-size:.55rem;
  font-weight:600;text-transform:uppercase;white-space:nowrap}
.badge.pending{background:rgba(244,63,94,.1);color:var(--red);
  border:1px solid rgba(244,63,94,.2)}
.badge.assigned{background:rgba(251,146,60,.1);color:var(--amber);
  border:1px solid rgba(251,146,60,.2)}
.badge.resolved{background:rgba(74,222,128,.1);color:var(--green);
  border:1px solid rgba(74,222,128,.2)}
.badge.dispatched{background:rgba(34,211,238,.1);color:var(--sky);
  border:1px solid rgba(34,211,238,.2)}
.badge.arrived{background:rgba(34,211,238,.1);color:var(--sky);
  border:1px solid rgba(34,211,238,.2)}
.badge.false_alarm{background:rgba(148,163,184,.1);color:var(--muted2);
  border:1px solid rgba(148,163,184,.2)}
.badge-dot{width:4px;height:4px;border-radius:50%;background:currentColor}

.view-btn{background:rgba(232,197,71,.08);border:1px solid rgba(232,197,71,.2);
  color:var(--police-gold);padding:.15rem .5rem;border-radius:6px;font-size:.55rem;
  cursor:pointer;transition:background .15s;font-family:'JetBrains Mono',monospace}
.view-btn:hover{background:rgba(232,197,71,.15)}
.resolve-btn{background:rgba(74,222,128,.08);border:1px solid rgba(74,222,128,.2);
  color:var(--green);padding:.15rem .5rem;border-radius:6px;font-size:.55rem;
  cursor:pointer;transition:background .15s;font-family:'JetBrains Mono',monospace}
.resolve-btn:hover{background:rgba(74,222,128,.15)}
.dispatch-btn{background:rgba(34,211,238,.08);border:1px solid rgba(34,211,238,.2);
  color:var(--sky);padding:.15rem .5rem;border-radius:6px;font-size:.55rem;
  cursor:pointer;transition:background .15s;font-family:'JetBrains Mono',monospace}
.dispatch-btn:hover{background:rgba(34,211,238,.15)}
.gps-link{display:inline-flex;align-items:center;gap:.2rem;
  font-size:.62rem;color:var(--sky);text-decoration:none}
.gps-link:hover{opacity:.7}

.filter-bar{background:var(--bg3);border:1px solid var(--border);border-radius:14px;
  padding:.6rem 1rem;display:flex;flex-wrap:wrap;gap:.4rem;margin-bottom:1.1rem;align-items:center}
.filter-bar a{padding:.35rem .8rem;border-radius:8px;font-size:.65rem;
  font-weight:600;color:var(--muted2);text-decoration:none;
  transition:background .15s,color .15s}
.filter-bar a:hover{background:var(--gold-lo2);color:var(--light)}
.filter-bar a.active{background:var(--gold-lo);color:var(--police-gold)}
.filter-bar .count{margin-left:auto;font-size:.6rem;color:var(--muted)}

.charts-row{display:grid;grid-template-columns:2fr 1fr;gap:1rem;margin-bottom:1.1rem}
.chart-box{padding:.8rem;flex:1;height:180px}

.footer{flex-shrink:0;border-top:1px solid var(--border);
  padding:.5rem 1.4rem;display:flex;align-items:center;justify-content:space-between;
  font-size:.6rem;color:var(--muted);
  font-family:'JetBrains Mono',monospace;background:var(--bg2)}
.footer .dual-status-badge{background:rgba(232,197,71,.12);border:1px solid rgba(232,197,71,.22);
  border-radius:999px;padding:.1rem .5rem;font-size:.5rem;color:var(--police-gold);font-weight:700}

.grid-bg{position:fixed;inset:0;pointer-events:none;z-index:0;
  background-image:
    linear-gradient(rgba(232,197,71,.01) 1px,transparent 1px),
    linear-gradient(90deg,rgba(232,197,71,.01) 1px,transparent 1px);
  background-size:42px 42px}

.toast-wrap{position:fixed;top:1rem;right:1.4rem;z-index:9999;
  display:flex;flex-direction:column;gap:.4rem;pointer-events:none}
.toast{display:flex;align-items:flex-start;gap:.6rem;
  background:var(--bg3);border:1px solid var(--police-gold);
  border-left:3px solid var(--police-gold);border-radius:11px;
  padding:.6rem .8rem;min-width:240px;max-width:300px;
  box-shadow:0 8px 28px rgba(0,0,0,.45);
  animation:tIn .3s ease;pointer-events:all}
@keyframes tIn{from{opacity:0;transform:translateX(26px)}to{opacity:1;transform:none}}
.t-ic{font-size:.85rem;flex-shrink:0}
.t-body{flex:1}
.t-title{font-weight:700;font-size:.72rem;color:var(--police-gold);margin-bottom:.1rem}
.t-msg{font-size:.65rem;color:var(--muted2)}
.t-x{background:none;border:none;color:var(--muted);cursor:pointer;font-size:.7rem}

@media(max-width:1100px){
  .stats-row{grid-template-columns:repeat(2,1fr)}
  .charts-row{grid-template-columns:1fr}
}
@media(max-width:760px){
  :root{--sw:0px}
  .sidebar{display:none}
  .stats-row{grid-template-columns:1fr 1fr}
  .content{padding:.8rem}
  .pname{display:none}
  .station-badge .station-name{display:none}
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
    
    <!-- Dashboard - ACTIVE -->
    <a href="dashboard.php" class="sb-item active">
      <span class="sb-ic"><i class="fas fa-chart-line"></i></span> Dashboard
    </a>
    
    <!-- Live Map -->
    <a href="live_map.php" class="sb-item">
      <span class="sb-ic"><i class="fas fa-map-marked-alt"></i></span> Live Map
    </a>
    
    <!-- 🔴 INCIDENTS - Shows ACTIVE accidents (assigned + dispatched + arrived) -->
    <a href="view_accidents.php" class="sb-item">
      <span class="sb-ic"><i class="fas fa-car-crash"></i></span> Accidents
      <?php if($active_count > 0): ?>
        <span class="sb-badge grn"><?= $active_count ?></span>
      <?php endif; ?>
    </a>

    <div class="sb-sect" style="margin-top:.4rem">Dispatch</div>
    
    <!-- 🔴 DISPATCH CENTER - Shows ASSIGNED accidents only -->
    <a href="dispatch.php" class="sb-item">
      <span class="sb-ic"><i class="fas fa-bullhorn"></i></span> Dispatch Center
      <?php if($pending_count > 0): ?>
        <span class="sb-badge red"><?= $pending_count ?></span>
      <?php endif; ?>
    </a>

    <!-- 🔴 RESOLVED CASES -->
    <a href="resolved_cases.php" class="sb-item">
      <span class="sb-ic"><i class="fas fa-check-circle"></i></span> Resolved Cases
      <?php if($resolved_count > 0): ?>
        <span class="sb-badge green"><?= $resolved_count ?></span>
      <?php endif; ?>
    </a>

    <div class="sb-sect" style="margin-top:.4rem">Account</div>
    
    <!-- My Profile -->
    <a href="profile.php" class="sb-item">
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
    <div class="tb-title"><span>🚔</span> Police Dashboard</div>

    <div class="tb-search">
      <i class="fas fa-search"></i>
      <input type="text" placeholder="Search incidents…" oninput="filterTable(this.value)"/>
    </div>

    <div class="tb-acts">
      <div class="live-pill"><span class="live-dot"></span>Live</div>

      <!-- Station Badge -->
      <div class="station-badge">
        <i class="fas fa-user-shield"></i>
        <span class="station-name"><?= htmlspecialchars($station_name) ?></span>
        <span class="status-dot"></span>
      </div>

      <!-- 🔴 NOTIFICATION BELL - Shows ASSIGNED count -->
      <div class="notif-wrap">
        <button class="notif-btn" onclick="toggleNotif()" id="notificationBell">
          <i class="fas fa-bell"></i>
          <span class="notif-count" id="notifCount" style="<?= $pending_count > 0 ? '' : 'display:none;' ?>">
            <?= $pending_count ?>
          </span>
        </button>
        <div class="notif-drop" id="notifDrop">
          <div class="notif-head">
            <div>
              <div class="nh-title">🚨 Pending Accidents</div>
              <div class="nh-sub">Awaiting dispatch</div>
            </div>
            <span style="font-size:.58rem;color:var(--muted);font-family:'JetBrains Mono',monospace">
              <?= $pending_count ?> pending
            </span>
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
            <div style="padding:1.5rem;text-align:center">
              <i class="fas fa-check-circle" style="font-size:1.8rem;color:var(--green);opacity:.45"></i>
              <p style="font-size:.7rem;color:var(--muted);margin-top:.4rem">No pending accidents</p>
            </div>
            <?php endif; ?>
          </div>
          <div class="notif-foot">
            <a href="dispatch.php">📋 View all pending →</a>
          </div>
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
          <div class="pd-head">
            <div class="pd-name"><?= $police_name ?></div>
            <div class="pd-email"><?= $_SESSION['email'] ?? 'police@roadalert.tz' ?></div>
            <div class="pd-pill"><i class="fas fa-shield-alt"></i> Police</div>
          </div>
          <div class="pd-items">
            <button class="pd-item" onclick="window.location.href='profile.php'">
              <i class="fas fa-user-edit"></i> Profile
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

    <!-- 🔴 STAT CARDS (4 Cards) -->
    <div class="stats-row">
      <div class="sc c1">
        <div class="sc-ico"><i class="fas fa-inbox"></i></div>
        <div class="sc-num" id="activeCount"><?= number_format($active_count) ?></div>
        <div class="sc-lbl">Active Cases</div>
        <div class="sc-tag">Assigned + Dispatched + Arrived</div>
      </div>
      <div class="sc c2">
        <div class="sc-ico"><i class="fas fa-clock"></i></div>
        <div class="sc-num" id="pendingCount"><?= number_format($pending_count) ?></div>
        <div class="sc-lbl">Pending</div>
        <div class="sc-tag" style="color:var(--red)">⚠️ Needs dispatch</div>
      </div>
      <div class="sc c3">
        <div class="sc-ico"><i class="fas fa-directions"></i></div>
        <div class="sc-num" id="dispatchedCount"><?= number_format($dispatched_count) ?></div>
        <div class="sc-lbl">Dispatched</div>
        <div class="sc-tag" style="color:var(--sky)">🚔 En route</div>
      </div>
      <div class="sc c4">
        <div class="sc-ico"><i class="fas fa-check-circle"></i></div>
        <div class="sc-num" id="resolvedCount"><?= number_format($resolved_count) ?></div>
        <div class="sc-lbl">Resolved</div>
        <div class="sc-tag" style="color:var(--green)">✅ Closed</div>
      </div>
    </div>

    <!-- CHARTS ROW -->
    <div class="charts-row">
      <div class="panel">
        <div class="ph">
          <div><div class="ph-t"><i class="fas fa-chart-bar"></i>Weekly Incidents</div>
            <div class="ph-s">Last 7 days</div></div>
        </div>
        <div class="chart-box"><canvas id="weekChart"></canvas></div>
      </div>
      <div class="panel">
        <div class="ph">
          <div><div class="ph-t"><i class="fas fa-chart-pie"></i>Status Distribution</div>
            <div class="ph-s">Police Status Breakdown</div></div>
        </div>
        <div class="chart-box"><canvas id="statusChart"></canvas></div>
      </div>
    </div>

    <!-- MAP SECTION -->
    <div class="panel map-row">
      <div class="ph">
        <div>
          <div class="ph-t"><i class="fas fa-map-marked-alt"></i>Live Incident Map</div>
          <div class="ph-s"><?= count($map_data) ?> location<?= count($map_data)!==1?'s':'' ?> plotted</div>
        </div>
        <div class="map-legend">
          <div class="leg"><div class="leg-d" style="background:#F43F5E"></div>Pending</div>
          <div class="leg"><div class="leg-d" style="background:#22D3EE"></div>Dispatched</div>
          <div class="leg"><div class="leg-d" style="background:#4ADE80"></div>Resolved</div>
        </div>
      </div>
      <div id="policeMap"></div>
    </div>

    <!-- INCIDENTS TABLE -->
    <div class="panel">
      <div class="ph">
        <div><div class="ph-t"><i class="fas fa-list"></i>Recent Incidents</div>
          <div class="ph-s">Latest assigned cases</div></div>
        <a href="view_accidents.php" class="ph-a">View All →</a>
      </div>
      <div class="tbl-wrap">
        <table>
          <thead>
            <tr>
              <th>#ID</th><th>Reporter</th><th>Location</th>
              <th>Injured</th><th>Distance</th><th>Status</th>
              <th>GPS</th><th>Time</th>
              <th style="text-align:right">Action</th>
            </tr>
          </thead>
          <tbody id="tableBody">
          <?php if($accidents_query && $accidents_query->num_rows > 0):
            $displayed = 0;
            while($r = $accidents_query->fetch_assoc()):
              if($displayed >= 10) break;
              $displayed++;
          ?>
          <tr>
            <td class="td-id">#ACC-<?= str_pad($r['id'],4,'0',STR_PAD_LEFT) ?></td>
            <td style="font-weight:500"><?= htmlspecialchars($r['reporter_name']?:'Anonymous') ?></td>
            <td class="td-loc" title="<?= htmlspecialchars($r['location_description']??'') ?>">
              <?= htmlspecialchars(substr($r['location_description']??'Unknown',0,30)) ?>
            </td>
            <td style="font-family:'JetBrains Mono',monospace;color:var(--amber);font-weight:700;"><?= intval($r['injuries']) ?></td>
            <td class="td-dist">
              <?php if($r['distance_km'] !== null): ?>
                <?= number_format($r['distance_km'], 1) ?> km
              <?php else: ?>
                —
              <?php endif; ?>
            </td>
            <td>
              <span class="badge <?= $r['status'] ?>">
                <span class="badge-dot"></span><?= ucfirst($r['status']) ?>
              </span>
            </td>
            <td>
              <?php if(!empty($r['latitude']) && !empty($r['longitude'])): ?>
              <a href="https://maps.google.com/?q=<?= $r['latitude'] ?>,<?= $r['longitude'] ?>" target="_blank" class="gps-link">
                <i class="fas fa-map-marker-alt"></i> Navigate
              </a>
              <?php else: ?>
              <span style="color:var(--muted);font-size:.6rem">N/A</span>
              <?php endif; ?>
            </td>
            <td class="td-time"><?= date('d M, H:i',strtotime($r['created_at'])) ?></td>
            <td style="text-align:right;white-space:nowrap">
              <button class="view-btn" onclick="viewIncident(<?= $r['id'] ?>)">View</button>
              <?php if($r['status'] == 'assigned'): ?>
              <button class="dispatch-btn" onclick="dispatchIncident(<?= $r['id'] ?>)">Dispatch</button>
              <?php endif; ?>
              <?php if($r['status'] == 'dispatched' || $r['status'] == 'arrived'): ?>
              <button class="resolve-btn" onclick="resolveIncident(<?= $r['id'] ?>)">Resolve</button>
              <?php endif; ?>
            </td>
          </tr>
          <?php endwhile; else: ?>
          <tr><td colspan="9" style="text-align:center;padding:2rem;color:var(--muted)">No incidents assigned to your station</td></tr>
          <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

  </div><!-- /content -->

  <div class="footer">
    <span>🚔 Tanzania Police&nbsp;·&nbsp;</span>
    <span class="dual-status-badge"><i class="fas fa-shield-alt"></i> Police Dashboard.</span>
    <span id="clock"></span>
  </div>
</div><!-- /main -->
</div><!-- /shell -->

<script>
// ── MAP DATA ──
const MAP_DATA = <?= json_encode($map_data) ?>;

// ── CLOCK ──
function tick(){
  const n=new Date();
  document.getElementById('clock').textContent=
    n.toLocaleDateString('en-GB',{weekday:'short',day:'2-digit',month:'short'})+
    ' · '+n.toLocaleTimeString('en-GB',{hour:'2-digit',minute:'2-digit',second:'2-digit'});
}
setInterval(tick,1000);tick();

// ── CHARTS ──
Chart.defaults.font.family="Space Grotesk";
Chart.defaults.color="#4E7090";

new Chart(document.getElementById('weekChart'),{
  type:'line',
  data:{
    labels:['Mon','Tue','Wed','Thu','Fri','Sat','Sun'],
    datasets:[{
      label:'Incidents',data:<?= json_encode($week_data) ?>,
      borderColor:'#E8C547',
      backgroundColor:'rgba(232,197,71,0.07)',
      fill:true,tension:.4,
      pointBackgroundColor:'#E8C547',
      pointRadius:4,
      pointHoverRadius:6,
      borderWidth:2
    }]
  },
  options:{responsive:true,maintainAspectRatio:false,
    plugins:{legend:{display:false}},
    scales:{
      x:{grid:{color:'rgba(255,255,255,.04)'},ticks:{font:{size:9}}},
      y:{grid:{color:'rgba(255,255,255,.04)'},ticks:{font:{size:9},stepSize:1},min:0}
    }
  }
});

// Status Distribution Chart
new Chart(document.getElementById('statusChart'),{
  type:'doughnut',
  data:{
    labels:['Pending','Dispatched','Arrived','Resolved'],
    datasets:[{
      data:[<?= $pending_count ?>, <?= $dispatched_count ?>, <?= $arrived_count ?>, <?= $resolved_count ?>],
      backgroundColor:['#F43F5E','#22D3EE','#FB923C','#4ADE80'],
      borderWidth:0
    }]
  },
  options:{responsive:true,maintainAspectRatio:false,cutout:'68%',
    plugins:{legend:{position:'bottom',labels:{font:{size:10},padding:10,boxWidth:9}}}
  }
});

// ── MAP ──
function statusColor(s){
  if(s==='pending' || s==='assigned') return '#F43F5E';
  if(s==='dispatched') return '#22D3EE';
  if(s==='arrived') return '#FB923C';
  if(s==='resolved') return '#4ADE80';
  return '#94A3B8';
}
function makeIcon(s){
  const c=statusColor(s);
  return L.divIcon({
    html:`<div style="width:14px;height:14px;border-radius:50%;
      background:${c};border:2.5px solid #fff;
      box-shadow:0 2px 8px rgba(0,0,0,.35)"></div>`,
    iconSize:[14,14],iconAnchor:[7,7],className:''
  });
}
const center=MAP_DATA.length?[MAP_DATA[0].lat,MAP_DATA[0].lng]:[-6.7924,39.2083];
const map=L.map('policeMap').setView(center,MAP_DATA.length?11:6);

L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png',{
  attribution:'&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>'
}).addTo(map);

L.control.scale({metric:true,imperial:false}).addTo(map);

if(MAP_DATA.length > 0){
  MAP_DATA.forEach(a=>{
    const m=L.marker([a.lat,a.lng],{icon:makeIcon(a.status)}).addTo(map);
    m.bindPopup(`
      <div style="font-family:'Space Grotesk',sans-serif;min-width:200px;background:#1A237E;border-radius:12px;padding:10px;border:1px solid #E8C547;">
        <div style="font-weight:700;font-size:.85rem;color:#E8C547;margin-bottom:3px">
          🚨 #ACC-${String(a.id).padStart(4,'0')}
        </div>
        <div style="font-size:.7rem;color:#FFFFFF;margin-bottom:2px">${a.type}</div>
        <div style="font-size:.65rem;color:#94a3b8;margin-bottom:4px">${a.location}</div>
        <div style="display:flex;gap:6px;font-size:.65rem;align-items:center;flex-wrap:wrap;color:#fff">
          <span>🤕 ${a.injuries} injured</span>
          <span style="color:${statusColor(a.status)};font-weight:700;text-transform:uppercase">${a.status}</span>
        </div>
        <div style="font-size:.6rem;color:#94a3b8;margin-top:3px">${a.time}</div>
        <hr style="border:none;border-top:1px solid #E8C547;margin:4px 0">
        <button onclick="viewIncident(${a.id})" style="background:#E8C547;color:#222;border:none;border-radius:6px;padding:3px 10px;font-size:10px;font-weight:700;cursor:pointer;width:100%;">
          View Details →
        </button>
      </div>
    `);
    m.bindTooltip(`#ACC-${String(a.id).padStart(4,'0')}: ${a.type}`,{sticky:true});
  });
  if(MAP_DATA.length>1){
    map.fitBounds(L.latLngBounds(MAP_DATA.map(a=>[a.lat,a.lng])),{padding:[30,30]});
  }
}

// ── TABLE FILTER ──
function filterTable(q){
  const rows=document.querySelectorAll('#tableBody tr');q=q.toLowerCase();
  rows.forEach(r=>{r.style.display=r.textContent.toLowerCase().includes(q)?'':'none'});
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

// ── VIEW INCIDENT ──
function viewIncident(id){
  window.location.href = 'incident_details.php?id=' + id;
}

// ── DISPATCH INCIDENT ──
function dispatchIncident(id){
  if(!confirm('Dispatch police to this incident?')) return;
  fetch('dispatch_incident.php',{
    method:'POST',
    headers:{'Content-Type':'application/x-www-form-urlencoded'},
    body:'id='+id
  }).then(r=>r.json()).then(d=>{
    if(d.success){
      showToast('✅ Dispatched','Police dispatched to incident','ok');
      setTimeout(()=>location.reload(),1500);
    } else {
      showToast('❌ Error',d.message||'Failed to dispatch','err');
    }
  });
}

// ── RESOLVE INCIDENT ──
function resolveIncident(id){
  if(!confirm('Mark this incident as resolved?')) return;
  fetch('resolve_incident.php',{
    method:'POST',
    headers:{'Content-Type':'application/x-www-form-urlencoded'},
    body:'id='+id
  }).then(r=>r.json()).then(d=>{
    if(d.success){
      showToast('✅ Resolved','Incident marked as resolved','ok');
      setTimeout(()=>location.reload(),1500);
    } else {
      showToast('❌ Error',d.message||'Failed to resolve','err');
    }
  });
}

// ── 🔴 UPDATE COUNTS WITHOUT RELOAD ──
function updateCounts() {
    fetch('check_pending.php')
        .then(response => response.json())
        .then(data => {
            if (data.error) return;
            
            // Update stat cards
            if(document.getElementById('activeCount')) document.getElementById('activeCount').textContent = data.active_count || 0;
            if(document.getElementById('pendingCount')) document.getElementById('pendingCount').textContent = data.pending_count || 0;
            if(document.getElementById('dispatchedCount')) document.getElementById('dispatchedCount').textContent = data.dispatched_count || 0;
            if(document.getElementById('resolvedCount')) document.getElementById('resolvedCount').textContent = data.resolved_count || 0;
            
            // Update notification badge
            updateNotificationBadge(data.pending_count);
            
            // Update sidebar badges
            updateSidebarBadges(data);
        })
        .catch(() => {});
}

// ── 🔴 UPDATE NOTIFICATION BADGE ──
function updateNotificationBadge(count) {
    const badge = document.getElementById('notifCount');
    if (!badge) return;
    
    if (count > 0) {
        badge.textContent = count;
        badge.style.display = 'flex';
        badge.style.animation = 'none';
        badge.offsetHeight;
        badge.style.animation = 'badgePulse 2s infinite';
    } else {
        badge.style.display = 'none';
    }
}

// ── 🔴 CHECK FOR NEW PENDING ACCIDENTS (POLLING) ──
let lastPendingCount = <?= $pending_count ?>;

function checkPendingAccidents() {
    fetch('check_pending.php')
        .then(response => response.json())
        .then(data => {
            if (data.error) return;
            
            updateNotificationBadge(data.pending_count);
            
            // 🔴 PLAY SOUND IF NEW PENDING ACCIDENT DETECTED
            if (data.pending_count > lastPendingCount) {
                playNotificationSound();
                showToast('🚨 New Assignment', 'A new accident has been assigned to your station!', 'new');
                
                const bell = document.getElementById('notificationBell');
                if (bell) {
                    bell.classList.add('has-notifications');
                    setTimeout(() => {
                        bell.classList.remove('has-notifications');
                    }, 3000);
                }
            }
            
            // Update stat cards
            if (data.active_count !== undefined && document.getElementById('activeCount')) {
                document.getElementById('activeCount').textContent = data.active_count;
            }
            if (data.pending_count !== undefined && document.getElementById('pendingCount')) {
                document.getElementById('pendingCount').textContent = data.pending_count;
            }
            if (data.dispatched_count !== undefined && document.getElementById('dispatchedCount')) {
                document.getElementById('dispatchedCount').textContent = data.dispatched_count;
            }
            if (data.resolved_count !== undefined && document.getElementById('resolvedCount')) {
                document.getElementById('resolvedCount').textContent = data.resolved_count;
            }
            
            updateSidebarBadges(data);
            
            lastPendingCount = data.pending_count;
        })
        .catch(error => console.error('Polling error:', error));
}

// ── 🔴 UPDATE SIDEBAR BADGES ──
function updateSidebarBadges(data) {
    // Update Incidents badge (active = assigned + dispatched + arrived)
    const incidentBadges = document.querySelectorAll('.sb-item .sb-badge.grn');
    if (incidentBadges.length > 0 && data.active_count !== undefined) {
        incidentBadges.forEach(badge => {
            if (badge.classList.contains('green')) return;
            if (data.active_count > 0) {
                badge.textContent = data.active_count;
                badge.style.display = 'inline-block';
            } else {
                badge.style.display = 'none';
            }
        });
    }
    
    // Update Pending badge (Dispatch Center - assigned)
    const pendingBadges = document.querySelectorAll('.sb-item .sb-badge.red');
    if (pendingBadges.length > 0 && data.pending_count !== undefined) {
        if (data.pending_count > 0) {
            pendingBadges.forEach(badge => {
                badge.textContent = data.pending_count;
                badge.style.display = 'inline-block';
            });
        } else {
            pendingBadges.forEach(badge => {
                badge.style.display = 'none';
            });
        }
    }
    
    // Update Resolved badge
    const resolvedBadges = document.querySelectorAll('.sb-item .sb-badge.green');
    if (resolvedBadges.length > 0 && data.resolved_count !== undefined) {
        if (data.resolved_count > 0) {
            resolvedBadges.forEach(badge => {
                badge.textContent = data.resolved_count;
                badge.style.display = 'inline-block';
            });
        } else {
            resolvedBadges.forEach(badge => {
                badge.style.display = 'none';
            });
        }
    }
}

// ── 🔴 PLAY NOTIFICATION SOUND ──
function playNotificationSound() {
    const audio = document.getElementById('notificationSound');
    if (audio) {
        audio.currentTime = 0;
        audio.volume = 0.8;
        audio.play().catch(() => playBeepSound());
    } else {
        playBeepSound();
    }
}

// ── 🔴 FALLBACK BEEP SOUND ──
function playBeepSound() {
    try {
        const audioContext = new (window.AudioContext || window.webkitAudioContext)();
        const oscillator = audioContext.createOscillator();
        const gainNode = audioContext.createGain();
        oscillator.connect(gainNode);
        gainNode.connect(audioContext.destination);
        oscillator.frequency.value = 800;
        oscillator.type = 'square';
        gainNode.gain.value = 0.3;
        oscillator.start();
        oscillator.stop(audioContext.currentTime + 0.3);
        setTimeout(() => {
            oscillator.disconnect();
            audioContext.close();
        }, 500);
    } catch (e) {}
}

// ── TOAST ──
function showToast(title,msg,type){
  const w=document.getElementById('toastWrap');
  const icon=type==='ok'?'✅':type==='err'?'⚠️':'🚨';
  const el=document.createElement('div');el.className='toast';
  if(type === 'new') {
      el.style.borderLeftColor = '#F43F5E';
      el.style.border = '2px solid rgba(244,63,94,0.3)';
  }
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

// ── 🔴 START POLLING ──
setInterval(checkPendingAccidents, 5000);
setTimeout(checkPendingAccidents, 1000);

// ── AUTO REFRESH (30 sec) ──
setInterval(()=>{
  fetch('check_updates.php')
    .then(r=>r.json())
    .then(d=>{
      if(d.new_incidents > 0){
        showToast('🚨 New Incident', d.new_incidents + ' new incident(s) reported', 'new');
      }
    }).catch(()=>{});
}, 30000);
</script>
</body>
</html>