<?php
// police/dispatch.php - Dispatch Center for Police.
session_start();
include __DIR__ . '/../connection.php';

// Check if police is logged in
if (empty($_SESSION['user_id']) || empty($_SESSION['role']) || strtolower($_SESSION['role']) !== 'police') {
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

// Get police user details
$police_id = $_SESSION['user_id'];
$police_name = htmlspecialchars($_SESSION['name'] ?? 'Police Officer');
$police_init = strtoupper(substr($police_name, 0, 1));

// Get user's police station
$user_query = $conn->query("
    SELECT u.*, ps.id as station_id, ps.name as station_name, ps.location, 
           ps.latitude, ps.longitude, ps.phone as station_phone
    FROM users u
    LEFT JOIN police_stations ps ON u.police_station_id = ps.id
    WHERE u.id = $police_id
");
$user = $user_query->fetch_assoc();

$police_station_id = $user['station_id'] ?? null;
$station_name = $user['station_name'] ?? 'No Station Assigned';
$station_lat = $user['latitude'] ?? null;
$station_lng = $user['longitude'] ?? null;

// ============================================
// 🔴 HANDLE DISPATCH ACTION - WITH AUDIT LOG
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['dispatch'])) {
    $accident_id = intval($_POST['accident_id']);
    
    // Get accident details before dispatch
    $accident = $conn->query("
        SELECT accident_type, location_description, police_status 
        FROM accidents 
        WHERE id = $accident_id AND assigned_police_id = $police_station_id
    ")->fetch_assoc();
    
    if ($accident) {
        $conn->query("UPDATE accidents SET police_status = 'dispatched' WHERE id = $accident_id AND assigned_police_id = $police_station_id");
        
        // 🔴 AUDIT LOG - POLICE DISPATCH
        createAuditLog($conn, 'Dispatched police to accident | ACC-' . str_pad($accident_id, 4, '0', STR_PAD_LEFT) . ' | Type: ' . $accident['accident_type'] . ' | Location: ' . ($accident['location_description'] ?? 'Unknown'));
        
        header("Location: dispatch.php?msg=dispatched");
        exit();
    }
}

// ============================================
// 🔴 HANDLE RESOLVE ACTION - WITH AUDIT LOG
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['resolve'])) {
    $accident_id = intval($_POST['accident_id']);
    
    // Get accident details before resolve
    $accident = $conn->query("
        SELECT accident_type, location_description, police_status 
        FROM accidents 
        WHERE id = $accident_id AND assigned_police_id = $police_station_id
    ")->fetch_assoc();
    
    if ($accident) {
        $old_status = $accident['police_status'];
        $conn->query("UPDATE accidents SET police_status = 'resolved' WHERE id = $accident_id AND assigned_police_id = $police_station_id");
        
        // 🔴 AUDIT LOG - POLICE RESOLVE
        createAuditLog($conn, 'Resolved accident | ACC-' . str_pad($accident_id, 4, '0', STR_PAD_LEFT) . ' | Type: ' . $accident['accident_type'] . ' (was ' . $old_status . ')');
        
        header("Location: dispatch.php?msg=resolved");
        exit();
    }
}

// ============================================
// 🔴 GET STATISTICS - Using police_status
// ============================================
$stats_query = $conn->query("
    SELECT 
        (SELECT COUNT(*) FROM accidents WHERE assigned_police_id = $police_station_id AND police_status = 'assigned') as pending_count,
        (SELECT COUNT(*) FROM accidents WHERE assigned_police_id = $police_station_id AND police_status = 'dispatched') as dispatched_count,
        (SELECT COUNT(*) FROM accidents WHERE assigned_police_id = $police_station_id AND police_status = 'resolved') as resolved_count,
        (SELECT COUNT(*) FROM accidents WHERE assigned_police_id = $police_station_id) as total_count
");
$stats = $stats_query->fetch_assoc();

// ============================================
// 🔴 GET ASSIGNED/PENDING ACCIDENTS (police_status = 'assigned')
// ============================================
$pending_accidents = $conn->query("
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
    AND a.police_status = 'assigned'
    ORDER BY a.created_at ASC
");

// ============================================
// 🔴 GET DISPATCHED ACCIDENTS (police_status = 'dispatched')
// ============================================
$dispatched_accidents = $conn->query("
    SELECT 
        a.*,
        ps.name as police_station_name,
        hs.name as hospital_station_name,
        a.police_status as status
    FROM accidents a
    LEFT JOIN police_stations ps ON a.assigned_police_id = ps.id
    LEFT JOIN hospital_stations hs ON a.assigned_hospital_id = hs.id
    WHERE a.assigned_police_id = $police_station_id
    AND a.police_status = 'dispatched'
    ORDER BY a.created_at DESC
    LIMIT 30
");

// ============================================
// 🔴 GET RESOLVED ACCIDENTS (police_status = 'resolved')
// ============================================
$resolved_accidents = $conn->query("
    SELECT 
        a.*,
        ps.name as police_station_name,
        hs.name as hospital_station_name,
        a.police_status as status
    FROM accidents a
    LEFT JOIN police_stations ps ON a.assigned_police_id = ps.id
    LEFT JOIN hospital_stations hs ON a.assigned_hospital_id = hs.id
    WHERE a.assigned_police_id = $police_station_id
    AND a.police_status = 'resolved'
    ORDER BY a.created_at DESC
    LIMIT 20
");

// ============================================
// 🔴 GET PENDING FOR NOTIFICATIONS (police_status = 'assigned')
// ============================================
$pending_notifications = $conn->query("
    SELECT id, accident_type, location_description, created_at, reporter_name, injuries 
    FROM accidents 
    WHERE assigned_police_id = $police_station_id AND police_status = 'assigned'
    ORDER BY created_at DESC LIMIT 10
");

$pending_count = $stats['pending_count'] ?? 0;
$dispatched_count = $stats['dispatched_count'] ?? 0;
$resolved_count = $stats['resolved_count'] ?? 0;

// Admin info for profile
$admin_name = htmlspecialchars($_SESSION['name'] ?? 'Police Officer');
$admin_email = htmlspecialchars($_SESSION['email'] ?? 'police@roadalert.tz');
$admin_role = htmlspecialchars($_SESSION['role'] ?? 'police');
$admin_init = strtoupper(substr($admin_name, 0, 1));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width,initial-scale=1.0"/>
<title>Dispatch Center — Tanzania Police</title>
<link href="https://fonts.googleapis.com/css2?family=Sora:wght@300;400;500;600;700;800&family=Space+Grotesk:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet"/>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"/>
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

.station-badge{display:flex;align-items:center;gap:.5rem;
  background:var(--bg3);border:1px solid var(--police-gold);
  border-radius:999px;padding:.2rem .8rem .2rem .4rem;
  font-size:.65rem;color:var(--white)}
.station-badge i{color:var(--police-gold);font-size:.7rem}
.station-badge .status-dot{width:6px;height:6px;border-radius:50%;
  background:var(--green);display:inline-block;animation:blink 1.3s step-end infinite}

/* ─── NOTIFICATION ─── */
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
.sc.c1::after{background:linear-gradient(90deg,var(--red),transparent)}
.sc.c2::after{background:linear-gradient(90deg,var(--sky),transparent)}
.sc.c3::after{background:linear-gradient(90deg,var(--green),transparent)}
.sc.c4::after{background:linear-gradient(90deg,var(--police-gold),transparent)}
.sc-ico{width:34px;height:34px;border-radius:8px;
  display:flex;align-items:center;justify-content:center;
  font-size:.8rem;margin-bottom:.7rem}
.c1 .sc-ico{background:rgba(244,63,94,.1);color:var(--red)}
.c2 .sc-ico{background:rgba(34,211,238,.1);color:var(--sky)}
.c3 .sc-ico{background:rgba(74,222,128,.1);color:var(--green)}
.c4 .sc-ico{background:rgba(232,197,71,.1);color:var(--police-gold)}
.sc-num{font-family:'Sora',sans-serif;font-size:1.8rem;
  font-weight:800;line-height:1;margin-bottom:.15rem}
.c1 .sc-num{color:var(--red)}
.c2 .sc-num{color:var(--sky)}
.c3 .sc-num{color:var(--green)}
.c4 .sc-num{color:var(--police-gold)}
.sc-lbl{font-size:.62rem;color:var(--muted);letter-spacing:.09em;text-transform:uppercase}
.sc-tag{position:absolute;top:1rem;right:1rem;
  font-size:.55rem;font-family:'JetBrains Mono',monospace;color:var(--muted)}

/* ─── IMAGE STYLES ─── */
.thumbnail-img {
    width: 40px;
    height: 40px;
    border-radius: 6px;
    object-fit: cover;
    border: 2px solid var(--border);
    cursor: pointer;
    transition: all 0.2s ease;
}
.thumbnail-img:hover {
    transform: scale(1.8);
    z-index: 999;
    border-color: var(--police-gold);
    box-shadow: 0 4px 20px rgba(232,197,71,0.3);
}
.image-placeholder {
    display: inline-flex;
    width: 40px;
    height: 40px;
    background: var(--bg4);
    border-radius: 6px;
    border: 1px dashed var(--border);
    align-items: center;
    justify-content: center;
    color: var(--muted);
    font-size: 0.6rem;
}
.modal-image-container {
    position: relative;
    border-radius: 10px;
    overflow: hidden;
    border: 1px solid var(--border);
    background: var(--bg4);
}
.modal-image-container img {
    width: 100%;
    max-height: 350px;
    object-fit: contain;
    display: block;
    cursor: pointer;
}
.modal-image-overlay {
    position: absolute;
    bottom: 8px;
    right: 8px;
    background: rgba(0,0,0,0.7);
    padding: 4px 12px;
    border-radius: 6px;
    font-size: 11px;
    color: white;
    pointer-events: none;
    display: flex;
    align-items: center;
    gap: 6px;
}
.image-not-found {
    padding: 30px;
    text-align: center;
    color: var(--muted);
    background: var(--bg4);
    border-radius: 8px;
    border: 1px dashed var(--border);
}
.image-not-found i {
    font-size: 2.5rem;
    display: block;
    margin-bottom: 10px;
    opacity: 0.3;
}
.image-not-found p {
    font-size: 0.7rem;
}

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
.badge-dot{width:4px;height:4px;border-radius:50%;background:currentColor}

/* ─── BUTTONS ─── */
.dispatch-btn{background:var(--police-gold);border:1px solid var(--police-gold);
  color:var(--bg2);padding:.2rem .6rem;border-radius:6px;font-size:.55rem;
  font-weight:700;cursor:pointer;transition:all .15s;font-family:'JetBrains Mono',monospace}
.dispatch-btn:hover{background:var(--police-gold2);transform:scale(1.05)}

.resolve-btn{background:rgba(74,222,128,.15);border:1px solid rgba(74,222,128,.3);
  color:var(--green);padding:.2rem .6rem;border-radius:6px;font-size:.55rem;
  font-weight:700;cursor:pointer;transition:all .15s;font-family:'JetBrains Mono',monospace}
.resolve-btn:hover{background:var(--green);color:#222;transform:scale(1.05)}

.view-btn{background:rgba(232,197,71,.08);border:1px solid rgba(232,197,71,.2);
  color:var(--police-gold);padding:.15rem .5rem;border-radius:6px;font-size:.55rem;
  cursor:pointer;transition:background .15s;font-family:'JetBrains Mono',monospace}
.view-btn:hover{background:rgba(232,197,71,.15)}

.grid-bg{position:fixed;inset:0;pointer-events:none;z-index:0;
  background-image:linear-gradient(rgba(232,197,71,.01) 1px,transparent 1px),
    linear-gradient(90deg,rgba(232,197,71,.01) 1px,transparent 1px);
  background-size:42px 42px}
.footer{flex-shrink:0;border-top:1px solid var(--border);
  padding:.5rem 1.4rem;display:flex;align-items:center;justify-content:space-between;
  font-size:.6rem;color:var(--muted);
  font-family:'JetBrains Mono',monospace;background:var(--bg2)}
.footer .dual-status-badge{background:rgba(232,197,71,.12);border:1px solid rgba(232,197,71,.22);border-radius:999px;padding:.1rem .5rem;font-size:.5rem;color:var(--police-gold);font-weight:700}

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

/* ─── MODAL ─── */
.modal-bg{position:fixed;inset:0;background:rgba(0,0,0,.75);
  backdrop-filter:blur(6px);z-index:6000;
  display:flex;align-items:center;justify-content:center;
  opacity:0;pointer-events:none;transition:opacity .22s}
.modal-bg.show{opacity:1;pointer-events:all}
.modal{background:var(--bg3);border:1px solid var(--bord2);
  border-radius:18px;padding:1.8rem;width:100%;max-width:600px;
  box-shadow:0 24px 80px rgba(0,0,0,.65);
  transform:translateY(18px) scale(.97);
  transition:transform .22s;position:relative;max-height:95vh;overflow-y:auto}
.modal-bg.show .modal{transform:none}
.modal-close{position:absolute;top:.9rem;right:.9rem;
  background:rgba(255,255,255,.05);border:1px solid var(--border);
  border-radius:7px;width:27px;height:27px;
  display:flex;align-items:center;justify-content:center;
  font-size:.7rem;color:var(--muted2);cursor:pointer;
  transition:background .18s,color .18s}
.modal-close:hover{background:rgba(244,63,94,.12);color:var(--red)}
.modal-title{font-family:'Sora',sans-serif;font-size:1.2rem;font-weight:700;
  color:var(--white);margin-bottom:.25rem}
.modal-title i{margin-right:8px;color:var(--police-gold)}
.modal-sub{font-size:.7rem;color:var(--muted);margin-bottom:1rem}
.modal-detail{display:grid;grid-template-columns:1fr 1fr;gap:.8rem}
.modal-detail .item{background:var(--bg4);padding:.6rem .8rem;border-radius:8px;border:1px solid var(--border)}
.modal-detail .item .label{font-size:.55rem;text-transform:uppercase;color:var(--muted);letter-spacing:.05em}
.modal-detail .item .value{font-size:.8rem;font-weight:500;color:var(--light);margin-top:2px}
.modal-detail .item.full{grid-column:1/-1}

@media(max-width:1100px){.stats-row{grid-template-columns:repeat(2,1fr)}}
@media(max-width:760px){
  :root{--sw:0px}
  .sidebar{display:none}
  .stats-row{grid-template-columns:1fr 1fr}
  .content{padding:.8rem}
  .pname{display:none}
  .tb-search{display:none}
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
    <a href="dashboard.php" class="sb-item <?= is_active('dashboard.php') ?>">
      <span class="sb-ic"><i class="fas fa-chart-line"></i></span> Dashboard
    </a>
    <a href="live_map.php" class="sb-item <?= is_active('live_map.php') ?>">
      <span class="sb-ic"><i class="fas fa-map-marked-alt"></i></span> Live Map
    </a>
    <a href="view_accidents.php" class="sb-item <?= is_active('view_accidents.php') ?>">
      <span class="sb-ic"><i class="fas fa-car-crash"></i></span> Accidents
      <?php if($pending_count > 0): ?>
        <span class="sb-badge red"><?= $pending_count ?></span>
      <?php endif; ?>
    </a>

    <div class="sb-sect" style="margin-top:.4rem">Dispatch</div>
    <a href="dispatch.php" class="sb-item active">
      <span class="sb-ic"><i class="fas fa-bullhorn"></i></span> Dispatch Center
      <?php if($pending_count > 0): ?>
        <span class="sb-badge red"><?= $pending_count ?></span>
      <?php endif; ?>
    </a>

    <a href="resolved_cases.php" class="sb-item <?= is_active('resolved_cases.php') ?>">
      <span class="sb-ic"><i class="fas fa-check-circle"></i></span> Resolved Cases
      <?php if($resolved_count > 0): ?>
        <span class="sb-badge green"><?= $resolved_count ?></span>
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
    <div class="tb-title"><i class="fas fa-bullhorn"></i>Dispatch Center</div>

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
            <span style="font-size:.58rem;color:var(--muted);font-family:'JetBrains Mono',monospace">
              <?= $pending_count ?> pending</span>
          </div>
          <div class="notif-list">
            <?php if($pending_notifications && $pending_notifications->num_rows > 0):
              while($al = $pending_notifications->fetch_assoc()): ?>
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
              <p style="font-size:.7rem;color:var(--muted);margin-top:.4rem">No pending alerts</p>
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
        <div class="sc-num"><?= number_format($pending_count) ?></div>
        <div class="sc-lbl">Pending Dispatch</div>
        <div class="sc-tag" style="color:var(--red)">⚠️ Assigned</div>
      </div>
      <div class="sc c2">
        <div class="sc-ico"><i class="fas fa-directions"></i></div>
        <div class="sc-num"><?= number_format($dispatched_count) ?></div>
        <div class="sc-lbl">Dispatched</div>
        <div class="sc-tag" style="color:var(--sky)">🚔 En route</div>
      </div>
      <div class="sc c3">
        <div class="sc-ico"><i class="fas fa-check-circle"></i></div>
        <div class="sc-num"><?= number_format($resolved_count) ?></div>
        <div class="sc-lbl">Resolved</div>
        <div class="sc-tag" style="color:var(--green)">✅ Solved</div>
      </div>
      <div class="sc c4">
        <div class="sc-ico"><i class="fas fa-inbox"></i></div>
        <div class="sc-num"><?= number_format($stats['total_count'] ?? 0) ?></div>
        <div class="sc-lbl">Total Assigned</div>
        <div class="sc-tag" style="color:var(--police-gold)">📋 All cases</div>
      </div>
    </div>

    <!-- 🔴 PENDING INCIDENTS TABLE (police_status = 'assigned') -->
    <div class="panel" style="border-color:rgba(244,63,94,0.3);">
      <div class="ph" style="border-bottom-color:rgba(244,63,94,0.3);">
        <div>
          <div class="ph-t"><i class="fas fa-exclamation-triangle" style="color:var(--red);"></i>Pending Dispatch</div>
          <div class="ph-s" style="color:var(--red);">Accidents assigned - Ready for dispatch</div>
        </div>
        <span class="ph-a" style="cursor:default;color:var(--red);font-size:.7rem;">
          <i class="fas fa-circle" style="font-size:.4rem;color:var(--red);"></i> <?= $pending_count ?> pending
        </span>
      </div>
      <div class="tbl-wrap">
        <table>
          <thead>
            <tr>
              <th>#ID</th><th>📸 Photo</th><th>Reporter</th><th>Location</th>
              <th>Injured</th><th>Distance</th>
              <th>Time</th>
              <th style="text-align:right">Action</th>
            </tr>
          </thead>
          <tbody id="pendingTable">
          <?php if($pending_accidents && $pending_accidents->num_rows > 0):
            while($r = $pending_accidents->fetch_assoc()):
                $img_path = getAccidentImage($r);
                $has_img = $img_path && file_exists('../' . $img_path);
          ?>
          <tr>
            <td class="td-id">#ACC-<?= str_pad($r['id'],4,'0',STR_PAD_LEFT) ?></td>
            <td>
              <?php if($has_img): ?>
                <img src="../<?= htmlspecialchars($img_path) ?>" 
                     alt="Scene" 
                     class="thumbnail-img"
                     onclick="viewAccident(<?= $r['id'] ?>)"
                     onerror="this.style.display='none';this.parentElement.innerHTML='<span class=\'image-placeholder\'><i class=\'fas fa-image\'></i></span>'">
              <?php else: ?>
                <span class="image-placeholder"><i class="fas fa-image"></i></span>
              <?php endif; ?>
            </td>
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
            <td class="td-time"><?= date('H:i d/m',strtotime($r['created_at'])) ?></td>
            <td style="text-align:right;white-space:nowrap">
              <form method="POST" style="display:inline-block;" onsubmit="return confirm('Dispatch this accident?')">
                <input type="hidden" name="accident_id" value="<?= $r['id'] ?>">
                <button type="submit" name="dispatch" class="dispatch-btn">
                  <i class="fas fa-paper-plane"></i> Dispatch
                </button>
              </form>
              <button class="view-btn" onclick="viewAccident(<?= $r['id'] ?>)">
                <i class="fas fa-eye"></i>
              </button>
            </td>
          </tr>
          <?php endwhile; else: ?>
          <tr><td colspan="8" style="text-align:center;padding:2rem;color:var(--muted)">
            <i class="fas fa-check-circle" style="font-size:2rem;display:block;margin-bottom:.5rem;color:var(--green);"></i>
            No pending accidents to dispatch
          </td></tr>
          <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <!-- 🔴 DISPATCHED INCIDENTS WITH RESOLVE BUTTON -->
    <div class="panel" style="margin-top:1.1rem;border-color:rgba(34,211,238,0.3);">
      <div class="ph" style="border-bottom-color:rgba(34,211,238,0.3);">
        <div>
          <div class="ph-t"><i class="fas fa-directions" style="color:var(--sky);"></i>Dispatched Incidents</div>
          <div class="ph-s" style="color:var(--sky);">Officers en route - Mark as resolved when done</div>
        </div>
        <span class="ph-a" style="cursor:default;font-size:.7rem;">
          <i class="fas fa-circle" style="font-size:.4rem;color:var(--sky);"></i> <?= $dispatched_count ?> dispatched
        </span>
      </div>
      <div class="tbl-wrap">
        <table>
          <thead>
            <tr>
              <th>#ID</th><th>📸 Photo</th><th>Reporter</th><th>Location</th>
              <th>Injured</th><th>Status</th>
              <th>Time</th>
              <th style="text-align:right">Action</th>
            </tr>
          </thead>
          <tbody>
          <?php if($dispatched_accidents && $dispatched_accidents->num_rows > 0):
            while($r = $dispatched_accidents->fetch_assoc()):
                $img_path = getAccidentImage($r);
                $has_img = $img_path && file_exists('../' . $img_path);
          ?>
          <tr>
            <td class="td-id">#ACC-<?= str_pad($r['id'],4,'0',STR_PAD_LEFT) ?></td>
            <td>
              <?php if($has_img): ?>
                <img src="../<?= htmlspecialchars($img_path) ?>" 
                     alt="Scene" 
                     class="thumbnail-img"
                     onclick="viewAccident(<?= $r['id'] ?>)"
                     onerror="this.style.display='none';this.parentElement.innerHTML='<span class=\'image-placeholder\'><i class=\'fas fa-image\'></i></span>'">
              <?php else: ?>
                <span class="image-placeholder"><i class="fas fa-image"></i></span>
              <?php endif; ?>
            </td>
            <td style="font-weight:500"><?= htmlspecialchars($r['reporter_name']?:'Anonymous') ?></td>
            <td class="td-loc" title="<?= htmlspecialchars($r['location_description']??'') ?>">
              <?= htmlspecialchars(substr($r['location_description']??'Unknown',0,30)) ?>
            </td>
            <td style="font-family:'JetBrains Mono',monospace;color:var(--amber);font-weight:700;"><?= intval($r['injuries']) ?></td>
            <td>
              <span class="badge dispatched">
                <span class="badge-dot"></span>Dispatched
              </span>
            </td>
            <td class="td-time"><?= date('H:i d/m',strtotime($r['created_at'])) ?></td>
            <td style="text-align:right;white-space:nowrap">
              <form method="POST" style="display:inline-block;" onsubmit="return confirm('Mark this incident as resolved/solved?')">
                <input type="hidden" name="accident_id" value="<?= $r['id'] ?>">
                <button type="submit" name="resolve" class="resolve-btn">
                  <i class="fas fa-check-circle"></i> Resolve
                </button>
              </form>
              <button class="view-btn" onclick="viewAccident(<?= $r['id'] ?>)">
                <i class="fas fa-eye"></i>
              </button>
            </td>
          </tr>
          <?php endwhile; else: ?>
          <tr><td colspan="8" style="text-align:center;padding:2rem;color:var(--muted)">
            No dispatched incidents
          </td></tr>
          <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <!-- 🔴 RESOLVED INCIDENTS -->
    <div class="panel" style="margin-top:1.1rem;border-color:rgba(74,222,128,0.3);">
      <div class="ph" style="border-bottom-color:rgba(74,222,128,0.3);">
        <div>
          <div class="ph-t"><i class="fas fa-check-circle" style="color:var(--green);"></i>Resolved Incidents</div>
          <div class="ph-s" style="color:var(--green);">Cases successfully resolved</div>
        </div>
        <span class="ph-a" style="cursor:default;font-size:.7rem;">
          <i class="fas fa-circle" style="font-size:.4rem;color:var(--green);"></i> <?= $resolved_count ?> resolved
        </span>
      </div>
      <div class="tbl-wrap">
        <table>
          <thead>
            <tr>
              <th>#ID</th><th>📸 Photo</th><th>Reporter</th><th>Location</th>
              <th>Injured</th><th>Status</th>
              <th>Time</th>
              <th style="text-align:right">Action</th>
            </tr>
          </thead>
          <tbody>
          <?php if($resolved_accidents && $resolved_accidents->num_rows > 0):
            while($r = $resolved_accidents->fetch_assoc()):
                $img_path = getAccidentImage($r);
                $has_img = $img_path && file_exists('../' . $img_path);
          ?>
          <tr>
            <td class="td-id">#ACC-<?= str_pad($r['id'],4,'0',STR_PAD_LEFT) ?></td>
            <td>
              <?php if($has_img): ?>
                <img src="../<?= htmlspecialchars($img_path) ?>" 
                     alt="Scene" 
                     class="thumbnail-img"
                     onclick="viewAccident(<?= $r['id'] ?>)"
                     onerror="this.style.display='none';this.parentElement.innerHTML='<span class=\'image-placeholder\'><i class=\'fas fa-image\'></i></span>'">
              <?php else: ?>
                <span class="image-placeholder"><i class="fas fa-image"></i></span>
              <?php endif; ?>
            </td>
            <td style="font-weight:500"><?= htmlspecialchars($r['reporter_name']?:'Anonymous') ?></td>
            <td class="td-loc" title="<?= htmlspecialchars($r['location_description']??'') ?>">
              <?= htmlspecialchars(substr($r['location_description']??'Unknown',0,30)) ?>
            </td>
            <td style="font-family:'JetBrains Mono',monospace;color:var(--amber);font-weight:700;"><?= intval($r['injuries']) ?></td>
            <td>
              <span class="badge resolved">
                <span class="badge-dot"></span>Resolved ✅
              </span>
            </td>
            <td class="td-time"><?= date('H:i d/m',strtotime($r['created_at'])) ?></td>
            <td style="text-align:right;white-space:nowrap">
              <button class="view-btn" onclick="viewAccident(<?= $r['id'] ?>)">
                <i class="fas fa-eye"></i> View
              </button>
            </td>
          </tr>
          <?php endwhile; else: ?>
          <tr><td colspan="8" style="text-align:center;padding:2rem;color:var(--muted)">
            No resolved incidents yet
          </td></tr>
          <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

  </div><!-- /content -->

  <div class="footer">
    <span>🚔 Tanzania Police - Dispatch & Solve Accidents&nbsp;·&nbsp;</span>
    <span class="dual-status-badge"><i class="fas fa-shield-alt"></i> Dispatch Center</span>
    <span id="clock"></span>
  </div>
</div><!-- /main -->
</div><!-- /shell -->

<!-- ═══════════════════ ACCIDENT DETAILS MODAL ═══════════════════ -->
<div class="modal-bg" id="accidentModal">
    <div class="modal">
        <button class="modal-close" onclick="closeModal()"><i class="fas fa-times"></i></button>
        <div class="modal-title"><i class="fas fa-car-crash"></i>Accident Details</div>
        <div class="modal-sub" id="modalSubtitle">#ACC-0000</div>
        <div id="modalBody">
            <div style="text-align:center;padding:2rem;color:var(--muted)">
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
  const rows=document.querySelectorAll('#pendingTable tr');q=q.toLowerCase();
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

// ── VIEW ACCIDENT WITH IMAGES ──
function viewAccident(id) {
    const modal = document.getElementById('accidentModal');
    const body = document.getElementById('modalBody');
    const subtitle = document.getElementById('modalSubtitle');
    
    modal.classList.add('show');
    subtitle.textContent = '#ACC-' + String(id).padStart(4, '0');
    body.innerHTML = '<div style="text-align:center;padding:2rem;color:var(--muted)"><i class="fas fa-spinner fa-spin"></i> Loading...</div>';
    
    fetch('get_accident.php?id=' + id)
        .then(response => response.json())
        .then(data => {
            if (data.error) {
                body.innerHTML = `<div style="text-align:center;padding:2rem;color:var(--red)">${data.error}</div>`;
                return;
            }
            
            // ── PROCESS IMAGES ──
            let imageHtml = '';
            let hasImage = false;
            let imageUrl = null;
            
            // Check images column (JSON array)
            if (data.images) {
                try {
                    let images = typeof data.images === 'string' ? JSON.parse(data.images) : data.images;
                    if (Array.isArray(images) && images.length > 0) {
                        imageUrl = '../' + ltrim(images[0], '/');
                        hasImage = true;
                    }
                } catch(e) {}
            }
            
            // If no image from images column, check photo_path
            if (!hasImage && data.photo_path) {
                imageUrl = '../' + ltrim(data.photo_path, '/');
                hasImage = true;
            }
            
            // Build image HTML
            if (hasImage && imageUrl) {
                imageHtml = `
                    <div class="item full">
                        <div class="label">📸 Accident Scene Photo</div>
                        <div class="value" style="margin-top:8px;">
                            <div class="modal-image-container">
                                <img src="${imageUrl}" 
                                     alt="Accident scene" 
                                     onerror="this.style.display='none';this.parentElement.innerHTML='<div class=\\'image-not-found\\'><i class=\\'fas fa-image\\'></i><p>Image not found</p></div>';"
                                     onclick="window.open('${imageUrl}','_blank')">
                                <div class="modal-image-overlay">
                                    <i class="fas fa-expand"></i> Click to enlarge
                                </div>
                            </div>
                        </div>
                    </div>
                `;
            } else {
                imageHtml = `
                    <div class="item full">
                        <div class="label">📸 Accident Scene Photo</div>
                        <div class="value">
                            <div class="image-not-found">
                                <i class="fas fa-image"></i>
                                <p>No photo uploaded</p>
                            </div>
                        </div>
                    </div>
                `;
            }
            
            // 🔴 Get police status from data
            const policeStatus = data.police_status || data.status || 'pending';
            
            let html = `
                <div class="modal-detail">
                    <div class="item">
                        <div class="label">Reporter</div>
                        <div class="value">${data.reporter_name || 'Anonymous'}</div>
                    </div>
                    <div class="item">
                        <div class="label">Phone</div>
                        <div class="value">${data.reporter_phone || 'N/A'}</div>
                    </div>
                    <div class="item">
                        <div class="label">Accident Type</div>
                        <div class="value">${data.accident_type || 'Unknown'}</div>
                    </div>
                    <div class="item">
                        <div class="label">Injuries</div>
                        <div class="value" style="color:${data.injuries > 2 ? 'var(--red)' : (data.injuries > 0 ? 'var(--amber)' : 'var(--green)')}">${data.injuries} person(s)</div>
                    </div>
                    ${imageHtml}
                    <div class="item full">
                        <div class="label">📍 Location</div>
                        <div class="value">${data.location_description || 'Unknown location'}</div>
                        <div style="font-size:.65rem;color:var(--muted);margin-top:3px;">
                            Lat: ${data.latitude || 'N/A'}, Lng: ${data.longitude || 'N/A'}
                        </div>
                    </div>
                    <div class="item full">
                        <div class="label">🚔 Police Status</div>
                        <div class="value">
                            <span class="badge ${policeStatus}">
                                <span class="badge-dot"></span>${policeStatus.toUpperCase()}
                            </span>
                        </div>
                    </div>
                    <div class="item full">
                        <div class="label">Description</div>
                        <div class="value" style="font-size:.75rem;">${data.description || 'No description provided'}</div>
                    </div>
                    <div class="item full">
                        <div class="label">Reported Time</div>
                        <div class="value" style="font-size:.75rem;">${data.created_at ? new Date(data.created_at).toLocaleString() : 'N/A'}</div>
                    </div>
                </div>
            `;
            
            body.innerHTML = html;
        })
        .catch(error => {
            console.error('Error:', error);
            body.innerHTML = `<div style="text-align:center;padding:2rem;color:var(--red)">
                <i class="fas fa-exclamation-triangle" style="font-size:2rem;display:block;margin-bottom:8px;"></i>
                Error loading details
            </div>`;
        });
}

function closeModal() {
    document.getElementById('accidentModal').classList.remove('show');
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

// ── SHOW TOAST ──
<?php if(isset($_GET['msg']) && $_GET['msg'] === 'dispatched'): ?>
showToast('✅ Dispatched', 'Accident dispatched successfully!', 'ok');
<?php elseif(isset($_GET['msg']) && $_GET['msg'] === 'resolved'): ?>
showToast('✅ Resolved', 'Accident marked as resolved/solved!', 'ok');
<?php endif; ?>
</script>

</body>
</html>