<?php
// admin/resolved_cases.php - View All Resolved Cases.
session_start();
include __DIR__ . '/../connection.php';

if (empty($_SESSION['user_id']) || strtolower($_SESSION['role'] ?? '') !== 'admin') {
    header("Location: ../login.php"); exit();
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

// ============================================
// GET FILTER
// ============================================
$filter_by = $_GET['filter'] ?? 'all'; // all, police, hospital
$search = $_GET['search'] ?? '';

// ============================================
// 🔴 BUILD QUERY FOR RESOLVED CASES (DUAL STATUS)
// ============================================
$where = "WHERE (police_status = 'resolved' OR hospital_status = 'treated')";

if ($filter_by === 'police') {
    $where .= " AND police_status = 'resolved'";
} elseif ($filter_by === 'hospital') {
    $where .= " AND hospital_status = 'treated'";
}

if (!empty($search)) {
    $where .= " AND (reporter_name LIKE '%$search%' OR location_description LIKE '%$search%' OR accident_type LIKE '%$search%')";
}

$resolved_cases = $conn->query("
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
// 🔴 GET STATISTICS WITH DUAL STATUS
// ============================================
$stats = $conn->query("SELECT
    (SELECT COUNT(*) FROM accidents WHERE police_status='pending' OR hospital_status='pending') as pending_count,
    (SELECT COUNT(*) FROM accidents WHERE police_status='resolved' OR hospital_status='treated') as resolved_count,
    (SELECT COUNT(*) FROM accidents WHERE DATE(created_at)=CURDATE()) as today_accidents
")->fetch_assoc();

// ─── 🔴 ADD THESE FOR SIDEBAR BADGE (SAME AS OTHER PAGES) ───
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
// PENDING ACCIDENTS FOR NOTIFICATION
// ============================================
$pending_accidents = $conn->query("
    SELECT id, accident_type, location_description, police_status, hospital_status, 
           created_at, reporter_name, injuries 
    FROM accidents 
    WHERE police_status='pending' OR hospital_status='pending'
    ORDER BY created_at DESC LIMIT 10
");
$pending_count = $stats['pending_count'] ?? 0;

// Admin info
$admin_name = htmlspecialchars($_SESSION['name'] ?? 'System Admin');
$admin_email = htmlspecialchars($_SESSION['email'] ?? 'admin@roadalert.tz');
$admin_role = htmlspecialchars($_SESSION['role'] ?? 'admin');
$admin_init = strtoupper(substr($admin_name, 0, 1));

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
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width,initial-scale=1.0"/>
<title>Resolved Cases — RoadAlert TZ</title>
<link href="https://fonts.googleapis.com/css2?family=Sora:wght@300;400;500;600;700;800&family=Space+Grotesk:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet"/>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"/>
<style>
/* ══════════════════════ TOKENS ══════════════════════ */
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{
  --bg:      #07111B;
  --bg2:     #0A1825;
  --bg3:     #0F2236;
  --bg4:     #152C44;
  --bg5:     #1C3654;
  --accent:  #00E5A0;
  --accent2: #00B87A;
  --acc-lo:  rgba(0,229,160,.1);
  --acc-lo2: rgba(0,229,160,.06);
  --yellow:  #FACC15;
  --sky:     #22D3EE;
  --red:     #F43F5E;
  --amber:   #FB923C;
  --purple:  #A78BFA;
  --light:   #D6E8F5;
  --muted:   #4E7090;
  --muted2:  #7A9BB5;
  --border:  rgba(255,255,255,0.06);
  --bord2:   rgba(255,255,255,0.11);
  --white:   #FFFFFF;
  --sw:      258px;
  --th:      62px;
}
html,body{height:100%;background:var(--bg);color:var(--light);
  font-family:'Poppins',sans-serif;overflow:hidden}
::-webkit-scrollbar{width:4px;height:4px}
::-webkit-scrollbar-track{background:transparent}
::-webkit-scrollbar-thumb{background:rgba(0,229,160,.2);border-radius:99px}
::-webkit-scrollbar-thumb:hover{background:rgba(0,229,160,.35)}

.shell{display:flex;height:100vh;overflow:hidden}

/* ══════════════════════ SIDEBAR ══════════════════════ */
.sidebar{
  width:var(--sw);flex-shrink:0;
  background:var(--bg2);border-right:1px solid var(--border);
  display:flex;flex-direction:column;
  position:relative;overflow:hidden;z-index:50;
}
.sidebar::before{content:'';position:absolute;top:-100px;left:-80px;
  width:300px;height:300px;border-radius:50%;pointer-events:none;
  background:radial-gradient(circle,rgba(0,229,160,.09) 0%,transparent 65%)}
.sidebar::after{content:'';position:absolute;inset:0;pointer-events:none;
  background-image:linear-gradient(rgba(0,229,160,.02) 1px,transparent 1px),
    linear-gradient(90deg,rgba(0,229,160,.02) 1px,transparent 1px);
  background-size:34px 34px}

.sb-brand{display:flex;align-items:center;gap:.75rem;
  padding:1.05rem 1.25rem;border-bottom:1px solid var(--border);
  text-decoration:none;flex-shrink:0;position:relative;z-index:2}
.sb-logo{width:38px;height:38px;border-radius:9px;flex-shrink:0;
  background:linear-gradient(135deg,var(--accent),var(--accent2));
  display:flex;align-items:center;justify-content:center;font-size:1.1rem;
  box-shadow:0 0 18px rgba(0,229,160,.45);animation:glow 3s ease-in-out infinite}
@keyframes glow{0%,100%{box-shadow:0 0 16px rgba(0,229,160,.4)}
  50%{box-shadow:0 0 32px rgba(0,229,160,.8)}}
.sb-title{font-family:'Sora',sans-serif;font-size:1.15rem;font-weight:800;
  letter-spacing:-.01em;color:var(--white)}
.sb-sub{font-size:.58rem;color:var(--muted);letter-spacing:.14em;
  text-transform:uppercase;font-family:'JetBrains Mono',monospace;margin-top:1px}

.sb-nav{flex:1;overflow-y:auto;padding:.65rem 0;position:relative;z-index:2}
.sb-sect{font-family:'JetBrains Mono',monospace;font-size:.56rem;
  letter-spacing:.16em;text-transform:uppercase;color:var(--muted);
  padding:.6rem 1.25rem .25rem}
.sb-item{display:flex;align-items:center;gap:.75rem;
  padding:.58rem 1rem;margin:1px .55rem;border-radius:9px;
  text-decoration:none;font-size:.8rem;font-weight:500;color:var(--muted2);
  transition:background .18s,color .18s;
  cursor:pointer;border:none;background:transparent;
  width:calc(100% - 1.1rem);text-align:left;
  font-family:'Space Grotesk',sans-serif;position:relative}
.sb-ic{width:30px;height:30px;border-radius:7px;flex-shrink:0;
  display:flex;align-items:center;justify-content:center;font-size:.82rem;
  background:rgba(255,255,255,.04);transition:background .18s,color .18s}
.sb-item:hover{background:var(--acc-lo2);color:var(--light)}
.sb-item:hover .sb-ic{background:var(--acc-lo);color:var(--accent)}
.sb-item.active{background:var(--acc-lo);color:var(--accent)}
.sb-item.active .sb-ic{background:rgba(0,229,160,.18);color:var(--accent)}
.sb-item.active::before{content:'';position:absolute;left:0;top:50%;
  transform:translateY(-50%);width:3px;height:56%;
  background:var(--accent);border-radius:0 3px 3px 0}
.sb-badge{margin-left:auto;font-size:.58rem;font-weight:700;
  padding:.14rem .42rem;border-radius:999px;
  font-family:'JetBrains Mono',monospace}
.sb-badge.red{background:rgba(244,63,94,.15);color:var(--red);
  border:1px solid rgba(244,63,94,.25);animation:badgePulse 2s infinite}
.sb-badge.grn{background:rgba(0,229,160,.12);color:var(--accent);
  border:1px solid rgba(0,229,160,.22)}
@keyframes badgePulse{0%,100%{box-shadow:0 0 0 0 rgba(244,63,94,.35)}
  50%{box-shadow:0 0 0 4px rgba(244,63,94,0)}}

.sb-logout-item{
  display:flex;align-items:center;gap:.75rem;
  padding:.58rem 1rem;margin:1px .55rem;border-radius:9px;
  font-size:.8rem;font-weight:500;color:var(--muted2);
  transition:background .18s,color .18s;
  cursor:pointer;border:none;background:transparent;
  width:calc(100% - 1.1rem);text-align:left;
  font-family:'Space Grotesk',sans-serif;text-decoration:none;}
.sb-logout-item .sb-ic{width:30px;height:30px;border-radius:7px;flex-shrink:0;
  display:flex;align-items:center;justify-content:center;font-size:.82rem;
  background:rgba(255,255,255,.04);transition:background .18s,color .18s}
.sb-logout-item:hover{background:rgba(244,63,94,.08);color:var(--red)}
.sb-logout-item:hover .sb-ic{background:rgba(244,63,94,.14);color:var(--red)}
.sb-divider{height:1px;background:var(--border);margin:.5rem .8rem}

/* ══════════════════════ MAIN ══════════════════════ */
.main{flex:1;display:flex;flex-direction:column;overflow:hidden;min-width:0}

/* ── TOPBAR ── */
.topbar{height:var(--th);flex-shrink:0;
  background:var(--bg2);border-bottom:1px solid var(--border);
  display:flex;align-items:center;gap:.9rem;padding:0 1.4rem;
  position:relative;z-index:40}
.tb-title{font-family:'Sora',sans-serif;font-size:1.25rem;
  font-weight:700;color:var(--white);flex:1}
.tb-title i{margin-right:.5rem;color:var(--accent)}
.tb-search{display:flex;align-items:center;gap:.5rem;
  background:var(--bg3);border:1px solid var(--bord2);border-radius:9px;
  padding:.36rem .9rem;transition:border-color .2s}
.tb-search:focus-within{border-color:rgba(0,229,160,.4)}
.tb-search input{background:none;border:none;outline:none;
  font-family:'Space Grotesk',sans-serif;font-size:.78rem;
  color:var(--light);width:185px}
.tb-search input::placeholder{color:var(--muted)}
.tb-search i{color:var(--muted);font-size:.8rem}
.tb-acts{display:flex;gap:.5rem;align-items:center}

.live-pill{display:flex;align-items:center;gap:.38rem;
  background:rgba(0,229,160,.08);border:1px solid rgba(0,229,160,.2);
  border-radius:999px;padding:.26rem .75rem;
  font-family:'JetBrains Mono',monospace;font-size:.6rem;color:var(--accent)}
.live-dot{width:6px;height:6px;border-radius:50%;background:var(--accent);
  animation:blink 1.3s step-end infinite}
@keyframes blink{0%,100%{opacity:1}50%{opacity:0}}

.notif-wrap{position:relative}
.notif-btn{width:36px;height:36px;border-radius:9px;
  background:var(--bg3);border:1px solid var(--bord2);
  display:flex;align-items:center;justify-content:center;
  font-size:.88rem;color:var(--muted2);cursor:pointer;
  transition:background .18s,color .18s;position:relative}
.notif-btn:hover{background:var(--acc-lo);color:var(--accent)}
.notif-count{position:absolute;top:-5px;right:-5px;
  background:var(--red);color:#fff;font-size:.54rem;font-weight:700;
  width:17px;height:17px;border-radius:50%;
  display:flex;align-items:center;justify-content:center;
  font-family:'JetBrains Mono',monospace;border:2px solid var(--bg2);
  animation:badgePulse 2s infinite}
.notif-drop{position:absolute;right:0;top:calc(100% + 9px);
  width:330px;background:var(--bg3);border:1px solid var(--bord2);
  border-radius:14px;overflow:hidden;box-shadow:0 16px 48px rgba(0,0,0,.55);
  opacity:0;transform:translateY(-8px) scale(.97);
  pointer-events:none;transition:opacity .2s,transform .2s;z-index:999}
.notif-drop.show{opacity:1;transform:none;pointer-events:all}
.notif-head{padding:.8rem 1rem;border-bottom:1px solid var(--border);
  display:flex;align-items:center;justify-content:space-between}
.nh-title{font-weight:700;font-size:.82rem;color:var(--white)}
.nh-sub{font-size:.68rem;color:var(--muted)}
.notif-list{max-height:300px;overflow-y:auto}
.notif-item{display:flex;align-items:flex-start;gap:.65rem;
  padding:.7rem 1rem;border-bottom:1px solid var(--border);
  cursor:pointer;transition:background .14s}
.notif-item:hover{background:var(--acc-lo2)}
.ndot{width:7px;height:7px;border-radius:50%;background:var(--red);
  flex-shrink:0;margin-top:4px;animation:badgePulse 1.5s infinite}
.nb{flex:1;min-width:0}
.nid{font-size:.75rem;font-weight:700;color:var(--accent);
  font-family:'JetBrains Mono',monospace}
.nloc{font-size:.7rem;color:var(--muted2);white-space:nowrap;
  overflow:hidden;text-overflow:ellipsis;margin-top:1px}
.nmeta{display:flex;gap:.5rem;align-items:center;margin-top:.28rem}
.npill{font-size:.58rem;padding:.13rem .45rem;border-radius:999px;
  background:rgba(244,63,94,.1);color:var(--red);
  border:1px solid rgba(244,63,94,.2);font-family:'JetBrains Mono',monospace}
.ntime{font-size:.62rem;color:var(--muted)}
.notif-foot{padding:.55rem 1rem;text-align:center;border-top:1px solid var(--border)}
.notif-foot a{font-size:.72rem;color:var(--sky);text-decoration:none;transition:opacity .2s}
.notif-foot a:hover{opacity:.7}

/* ─── PROFILE MODAL ─── */
.modal-bg{position:fixed;inset:0;background:rgba(0,0,0,.65);
  backdrop-filter:blur(5px);z-index:6000;
  display:flex;align-items:center;justify-content:center;
  opacity:0;pointer-events:none;transition:opacity .22s}
.modal-bg.show{opacity:1;pointer-events:all}
.modal{background:var(--bg3);border:1px solid var(--bord2);
  border-radius:18px;padding:1.8rem;width:100%;max-width:420px;
  box-shadow:0 24px 80px rgba(0,0,0,.65);
  transform:translateY(18px) scale(.97);
  transition:transform .22s;position:relative}
.modal-bg.show .modal{transform:none}
.modal-close{position:absolute;top:.9rem;right:.9rem;
  background:rgba(255,255,255,.05);border:1px solid var(--border);
  border-radius:7px;width:27px;height:27px;
  display:flex;align-items:center;justify-content:center;
  font-size:.7rem;color:var(--muted2);cursor:pointer;
  transition:background .18s,color .18s}
.modal-close:hover{background:rgba(244,63,94,.12);color:var(--red)}
.modal-title{font-family:'Sora',sans-serif;font-size:1.4rem;font-weight:800;
  color:var(--white);margin-bottom:.25rem}
.modal-sub{font-size:.75rem;color:var(--muted);margin-bottom:1.3rem}
.modal-av-row{display:flex;align-items:center;gap:.9rem;
  padding:.8rem;background:rgba(255,255,255,.03);
  border:1px solid var(--border);border-radius:11px;margin-bottom:1.3rem}
.modal-av{width:48px;height:48px;border-radius:11px;flex-shrink:0;
  background:linear-gradient(135deg,var(--accent),var(--accent2));
  display:flex;align-items:center;justify-content:center;
  font-size:1.3rem;font-weight:700;color:#07111B;
  box-shadow:0 0 18px rgba(0,229,160,.35)}
.mav-name{font-weight:700;font-size:.9rem;color:var(--white)}
.mav-role{font-size:.66rem;color:var(--muted);text-transform:capitalize;margin-top:2px}
.mf{margin-bottom:1rem}
.mf label{display:block;font-size:.64rem;letter-spacing:.1em;
  text-transform:uppercase;color:var(--muted);margin-bottom:.4rem;font-weight:500}
.mf input{width:100%;background:var(--bg4);border:1px solid rgba(255,255,255,.08);
  border-radius:9px;padding:.68rem .85rem;
  font-family:'Space Grotesk',sans-serif;font-size:.83rem;color:var(--white);
  outline:none;transition:border-color .18s,box-shadow .18s}
.mf input:focus{border-color:var(--accent);box-shadow:0 0 0 3px rgba(0,229,160,.12)}
.mf input::placeholder{color:rgba(255,255,255,.16)}
.modal-acts{display:flex;gap:.65rem;margin-top:1.4rem}
.btn-save{flex:1;padding:.72rem;background:var(--accent);border:none;border-radius:9px;
  font-family:'Sora',sans-serif;font-size:.95rem;font-weight:700;
  color:#07111B;cursor:pointer;box-shadow:0 0 14px rgba(0,229,160,.3);
  transition:transform .15s,box-shadow .18s}
.btn-save:hover{transform:translateY(-2px);box-shadow:0 4px 20px rgba(0,229,160,.5)}
.btn-cancel2{padding:.72rem 1.1rem;background:rgba(255,255,255,.05);
  border:1px solid var(--border);border-radius:9px;
  font-size:.82rem;color:var(--muted2);cursor:pointer;transition:background .18s}
.btn-cancel2:hover{background:rgba(255,255,255,.08)}

.profile-wrap{position:relative}
.profile-btn{display:flex;align-items:center;gap:.55rem;
  background:var(--bg3);border:1px solid var(--bord2);
  border-radius:10px;padding:.38rem .65rem .38rem .45rem;
  cursor:pointer;transition:background .18s,border-color .18s;}
.profile-btn:hover{background:var(--acc-lo2);border-color:rgba(0,229,160,.25)}
.profile-btn.open{background:var(--acc-lo);border-color:rgba(0,229,160,.35)}
.pa{width:30px;height:30px;border-radius:7px;flex-shrink:0;
  background:linear-gradient(135deg,var(--accent),var(--accent2));
  display:flex;align-items:center;justify-content:center;
  font-size:.82rem;font-weight:700;color:#07111B;
  box-shadow:0 0 10px rgba(0,229,160,.35);}
.pname{font-size:.76rem;font-weight:600;color:var(--light);
  max-width:90px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.pchev{font-size:.7rem;color:var(--muted);transition:transform .22s}
.profile-btn.open .pchev{transform:rotate(180deg)}

.profile-drop{position:absolute;right:0;top:calc(100% + 9px);
  width:240px;background:var(--bg3);border:1px solid var(--bord2);
  border-radius:14px;overflow:hidden;box-shadow:0 16px 48px rgba(0,0,0,.55);
  opacity:0;transform:translateY(-8px) scale(.97);
  pointer-events:none;transition:opacity .2s,transform .2s;z-index:999;}
.profile-drop.show{opacity:1;transform:none;pointer-events:all}
.pd-head{padding:.85rem 1rem .6rem;border-bottom:1px solid var(--border)}
.pd-name{font-weight:700;font-size:.86rem;color:var(--white)}
.pd-email{font-size:.67rem;color:var(--muted);margin-top:2px;
  font-family:'JetBrains Mono',monospace;
  white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.pd-pill{display:inline-flex;align-items:center;gap:.28rem;
  background:var(--acc-lo);border:1px solid rgba(0,229,160,.22);
  border-radius:999px;padding:.18rem .6rem;margin-top:.45rem;
  font-size:.6rem;color:var(--accent);font-family:'JetBrains Mono',monospace;
  letter-spacing:.06em;text-transform:uppercase}
.pd-items{padding:.35rem 0}
.pd-item{display:flex;align-items:center;gap:.65rem;
  padding:.52rem .9rem;font-size:.78rem;color:var(--muted2);
  text-decoration:none;cursor:pointer;
  transition:background .14s,color .14s;
  border:none;background:none;width:100%;text-align:left;
  font-family:'Space Grotesk',sans-serif}
.pd-item i{width:14px;text-align:center;font-size:.78rem;color:var(--muted)}
.pd-item:hover{background:var(--acc-lo2);color:var(--light)}
.pd-item:hover i{color:var(--accent)}
.pd-item.danger:hover{background:rgba(244,63,94,.08);color:var(--red)}
.pd-item.danger:hover i{color:var(--red)}
.pd-div{height:1px;background:var(--border);margin:.3rem 0}

.tb-btn{display:flex;align-items:center;gap:.38rem;
  padding:.38rem .8rem;border-radius:8px;font-size:.74rem;
  border:1px solid var(--bord2);background:transparent;
  color:var(--light);cursor:pointer;transition:background .18s;
  font-family:'Space Grotesk',sans-serif;text-decoration:none}
.tb-btn:hover{background:rgba(244,63,94,.08);color:var(--red);border-color:rgba(244,63,94,.25)}
.tb-btn.primary{background:var(--accent);border-color:var(--accent);
  color:#07111B;font-weight:600;box-shadow:0 0 14px rgba(0,229,160,.3)}
.tb-btn.primary:hover{background:var(--accent2);color:#07111B}

/* ══════════════════════ CONTENT ══════════════════════ */
.content{flex:1;overflow-y:auto;padding:1.4rem 1.5rem}

/* ── STAT CARDS ── */
.stats-row{display:grid;grid-template-columns:repeat(4,1fr);gap:1rem;margin-bottom:1.1rem}
.sc{background:var(--bg3);border:1px solid var(--border);border-radius:14px;
  padding:1.1rem 1.25rem;position:relative;overflow:hidden;
  transition:border-color .22s,transform .18s}
.sc:hover{border-color:var(--bord2);transform:translateY(-2px)}
.sc::after{content:'';position:absolute;top:0;left:0;right:0;height:2px;
  opacity:0;transition:opacity .25s}
.sc:hover::after{opacity:1}
.sc.c1::after{background:linear-gradient(90deg,var(--sky),transparent)}
.sc.c2::after{background:linear-gradient(90deg,var(--accent),transparent)}
.sc.c3::after{background:linear-gradient(90deg,var(--purple),transparent)}
.sc.c4::after{background:linear-gradient(90deg,var(--amber),transparent)}
.sc-ico{width:36px;height:36px;border-radius:8px;
  display:flex;align-items:center;justify-content:center;
  font-size:.88rem;margin-bottom:.85rem}
.c1 .sc-ico{background:rgba(34,211,238,.1);color:var(--sky)}
.c2 .sc-ico{background:var(--acc-lo);color:var(--accent)}
.c3 .sc-ico{background:rgba(167,139,250,.1);color:var(--purple)}
.c4 .sc-ico{background:rgba(251,146,60,.1);color:var(--amber)}
.sc-num{font-family:'Sora',sans-serif;font-size:2rem;
  font-weight:800;line-height:1;margin-bottom:.22rem}
.c1 .sc-num{color:var(--sky)}
.c2 .sc-num{color:var(--accent)}
.c3 .sc-num{color:var(--purple)}
.c4 .sc-num{color:var(--amber)}
.sc-lbl{font-size:.62rem;color:var(--muted);letter-spacing:.09em;text-transform:uppercase}

/* ── FILTER BAR ── */
.filter-bar{background:var(--bg3);border:1px solid var(--border);border-radius:14px;
  padding:.8rem 1rem;display:flex;flex-wrap:wrap;gap:.5rem;margin-bottom:1.1rem;align-items:center}
.filter-bar a{padding:.45rem .9rem;border-radius:8px;font-size:.7rem;
  font-weight:600;color:var(--muted2);text-decoration:none;
  transition:background .15s,color .15s}
.filter-bar a:hover{background:var(--acc-lo2);color:var(--light)}
.filter-bar a.active{background:var(--acc-lo);color:var(--accent)}
.filter-bar .count{margin-left:auto;font-size:.65rem;color:var(--muted)}

/* ─── TABLE ─── */
.panel{background:var(--bg3);border:1px solid var(--border);
  border-radius:14px;overflow:hidden;display:flex;flex-direction:column}
.ph{display:flex;align-items:center;justify-content:space-between;
  padding:.85rem 1.15rem;border-bottom:1px solid var(--border);flex-shrink:0}
.ph-t{font-family:'Sora',sans-serif;font-size:1rem;font-weight:700;color:var(--white)}
.ph-t i{margin-right:.5rem;color:var(--accent)}
.ph-s{font-size:.67rem;color:var(--muted);margin-top:.1rem}
.ph-a{font-size:.7rem;color:var(--sky);text-decoration:none;
  cursor:pointer;background:none;border:none;
  font-family:'Space Grotesk',sans-serif;transition:opacity .18s}
.ph-a:hover{opacity:.7}

.tbl-wrap{overflow-x:auto;flex:1}
table{width:100%;border-collapse:collapse;font-size:.76rem}
thead th{padding:.52rem .85rem;font-size:.6rem;letter-spacing:.1em;
  text-transform:uppercase;color:var(--muted);font-weight:600;
  border-bottom:1px solid var(--border);white-space:nowrap;text-align:left}
tbody tr{border-bottom:1px solid rgba(255,255,255,.04);transition:background .12s}
tbody tr:hover{background:rgba(0,229,160,.03)}
tbody td{padding:.58rem .85rem;color:var(--light);vertical-align:middle}
.td-id{font-family:'JetBrains Mono',monospace;font-size:.7rem;color:var(--muted2)}
.td-loc{font-size:.7rem;color:var(--muted2);max-width:155px;
  overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.td-time{font-size:.66rem;color:var(--muted);white-space:nowrap}

/* ─── IMAGE THUMBNAIL ─── */
.thumbnail-img{width:35px;height:35px;border-radius:6px;object-fit:cover;
  border:2px solid var(--border);cursor:pointer;transition:all 0.2s ease}
.thumbnail-img:hover{transform:scale(2);z-index:999;border-color:var(--accent);
  box-shadow:0 4px 20px rgba(0,229,160,0.3)}
.image-placeholder{display:inline-flex;width:35px;height:35px;background:var(--bg4);
  border-radius:6px;border:1px dashed var(--border);align-items:center;
  justify-content:center;color:var(--muted);font-size:.6rem}

/* 🔴 DUAL STATUS BADGES - ALL HOSPITAL STATUSES */
.badge{display:inline-flex;align-items:center;gap:.26rem;
  padding:.18rem .6rem;border-radius:999px;
  font-family:'JetBrains Mono',monospace;font-size:.58rem;
  font-weight:600;text-transform:uppercase;white-space:nowrap}
.badge.resolved{background:rgba(0,229,160,.15);color:var(--accent);
  border:1px solid rgba(0,229,160,.3)}
.badge.treated{background:rgba(16,185,129,.15);color:#059669;
  border:1px solid rgba(16,185,129,.3)}
.badge.closed{background:rgba(0,229,160,.15);color:var(--accent);
  border:1px solid rgba(0,229,160,.3)}
.badge.assigned{background:rgba(251,146,60,.15);color:var(--amber);
  border:1px solid rgba(251,146,60,.3)}
.badge.preparing{background:rgba(249,115,22,.15);color:#FB923C;
  border:1px solid rgba(249,115,22,.3)}
.badge.ambulance_dispatched{background:rgba(34,211,238,.15);color:var(--sky);
  border:1px solid rgba(34,211,238,.3)}
.badge.patient_received{background:rgba(167,139,250,.15);color:var(--purple);
  border:1px solid rgba(167,139,250,.3)}
.badge.pending{background:rgba(244,63,94,.15);color:var(--red);
  border:1px solid rgba(244,63,94,.3)}
.badge-dot{width:5px;height:5px;border-radius:50%;background:currentColor}

/* 🔴 RESOLVED BY BADGES */
.resolved-by{display:inline-flex;align-items:center;gap:.3rem;
  padding:.15rem .6rem;border-radius:999px;font-size:.55rem;font-weight:600}
.resolved-by.both{background:rgba(232,197,71,.15);color:#E8C547;border:1px solid rgba(232,197,71,.3)}
.resolved-by.police_only{background:rgba(34,211,238,.15);color:var(--sky);border:1px solid rgba(34,211,238,.3)}
.resolved-by.hospital_only{background:rgba(251,146,60,.15);color:var(--amber);border:1px solid rgba(251,146,60,.3)}
.resolved-by.unknown{background:rgba(100,116,139,.1);color:var(--muted);border:1px solid rgba(100,116,139,.2)}

.view-btn{background:rgba(0,229,160,.08);border:1px solid rgba(0,229,160,.2);
  color:var(--accent);padding:.2rem .6rem;border-radius:6px;font-size:.6rem;
  cursor:pointer;transition:background .15s;font-family:'JetBrains Mono',monospace}
.view-btn:hover{background:var(--acc-lo)}

/* ─── MODAL ─── */
.modal-bg{position:fixed;inset:0;background:rgba(0,0,0,.75);
  backdrop-filter:blur(6px);z-index:6000;
  display:flex;align-items:center;justify-content:center;
  opacity:0;pointer-events:none;transition:opacity .22s}
.modal-bg.show{opacity:1;pointer-events:all}
.modal{background:var(--bg3);border:1px solid var(--bord2);
  border-radius:18px;padding:1.8rem;width:100%;max-width:620px;
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
.modal-title i{margin-right:8px;color:var(--accent)}
.modal-sub{font-size:.7rem;color:var(--muted);margin-bottom:1rem}
.modal-detail{display:grid;grid-template-columns:1fr 1fr;gap:.8rem}
.modal-detail .item{background:var(--bg4);padding:.6rem .8rem;border-radius:8px;border:1px solid var(--border)}
.modal-detail .item .label{font-size:.55rem;text-transform:uppercase;color:var(--muted);letter-spacing:.05em}
.modal-detail .item .value{font-size:.8rem;font-weight:500;color:var(--light);margin-top:2px}
.modal-detail .item.full{grid-column:1/-1}
.modal-image-container{position:relative;border-radius:8px;overflow:hidden;border:1px solid var(--border);background:var(--bg4)}
.modal-image-container img{width:100%;max-height:300px;object-fit:contain;display:block;cursor:pointer}
.modal-image-overlay{position:absolute;bottom:8px;right:8px;background:rgba(0,0,0,0.7);padding:4px 12px;border-radius:6px;font-size:11px;color:white;pointer-events:none}
.image-not-found{padding:30px;text-align:center;color:var(--muted);background:var(--bg4);border-radius:8px;border:1px dashed var(--border)}
.image-not-found i{font-size:2.5rem;display:block;margin-bottom:10px;opacity:0.3}
.image-not-found p{font-size:.7rem}

.grid-bg{position:fixed;inset:0;pointer-events:none;z-index:0;
  background-image:linear-gradient(rgba(0,229,160,.012) 1px,transparent 1px),
    linear-gradient(90deg,rgba(0,229,160,.012) 1px,transparent 1px);
  background-size:42px 42px}
.footer{flex-shrink:0;border-top:1px solid var(--border);
  padding:.6rem 1.4rem;display:flex;align-items:center;justify-content:space-between;
  font-size:.66rem;color:var(--muted);
  font-family:'JetBrains Mono',monospace;background:var(--bg2)}
.toast-wrap{position:fixed;top:1.1rem;right:1.4rem;z-index:9999;
  display:flex;flex-direction:column;gap:.5rem;pointer-events:none}
.toast{display:flex;align-items:flex-start;gap:.65rem;
  background:var(--bg3);border:1px solid rgba(0,229,160,.28);
  border-left:3px solid var(--accent);border-radius:11px;
  padding:.75rem .9rem;min-width:265px;max-width:315px;
  box-shadow:0 8px 28px rgba(0,0,0,.45);
  animation:tIn .3s ease;pointer-events:all}
@keyframes tIn{from{opacity:0;transform:translateX(26px)}to{opacity:1;transform:none}}
.t-ic{font-size:.95rem;flex-shrink:0;margin-top:1px}
.t-body{flex:1}
.t-title{font-weight:700;font-size:.78rem;color:var(--accent);margin-bottom:.15rem}
.t-msg{font-size:.7rem;color:var(--muted2);line-height:1.4}
.t-x{background:none;border:none;color:var(--muted);cursor:pointer;font-size:.76rem;padding:0;transition:color .18s}
.t-x:hover{color:var(--light)}

@media(max-width:1100px){.stats-row{grid-template-columns:repeat(2,1fr)}}
@media(max-width:760px){
  :root{--sw:0px}
  .sidebar{display:none}
  .stats-row{grid-template-columns:1fr 1fr}
  .content{padding:.9rem}
  .pname{display:none}
}
</style>
</head>
<body>
<div class="grid-bg"></div>
<div class="toast-wrap" id="toastWrap"></div>

<!-- ═══════════════════ PROFILE MODAL ═══════════════════ -->
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
      <div class="mf"><label>Full Name</label>
        <input type="text" name="name" value="<?= $admin_name ?>" placeholder="Your full name"/></div>
      <div class="mf"><label>Email Address</label>
        <input type="email" name="email" value="<?= $admin_email ?>" placeholder="admin@roadalert.tz"/></div>
      <div class="mf"><label>Phone Number</label>
        <input type="tel" name="phone" value="<?= htmlspecialchars($_SESSION['phone']??'') ?>" placeholder="+255 7XX XXX XXX"/></div>
      <div class="mf"><label>New Password <span style="color:var(--muted);font-size:.62rem;text-transform:none;letter-spacing:0">(blank = keep current)</span></label>
        <input type="password" name="password" placeholder="••••••••••"/></div>
      <div class="mf"><label>Confirm Password</label>
        <input type="password" name="confirm_password" placeholder="••••••••••"/></div>
      <div class="modal-acts">
        <button type="submit" class="btn-save"><i class="fas fa-save"></i> &nbsp;Save Changes</button>
        <button type="button" class="btn-cancel2" onclick="closeProfileModal()">Cancel</button>
      </div>
    </form>
  </div>
</div>

<div class="shell">

<!-- ═══════════════════ SIDEBAR ═══════════════════ -->
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
      <?php if($pending_count > 0): ?>
        <span class="sb-badge red"><?= $pending_count ?></span>
      <?php endif; ?>
    </a>
    <a href="resolved_cases.php" class="sb-item <?= is_active('resolved_cases.php') ?>">
    <span class="sb-ic"><i class="fas fa-check-circle"></i></span> Resolved Cases
    <?php if(($police_stats['resolved'] ?? 0) > 0 || ($hospital_stats['treated'] ?? 0) > 0): ?>
        <span class="sb-badge grn"><?= ($police_stats['resolved'] ?? 0) + ($hospital_stats['treated'] ?? 0) ?></span>
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
    
    <!-- 🔴 REPLACED: Settings → Audit Logs -->
    <a href="audit_logs.php" class="sb-item <?= is_active('audit_logs.php') ?>">
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

<!-- ═══════════════════ MAIN ═══════════════════ -->
<div class="main">

  <!-- TOPBAR -->
  <div class="topbar">
    <div class="tb-title"><i class="fas fa-check-circle"></i>Resolved Cases</div>

    <div class="tb-search">
      <i class="fas fa-search"></i>
      <input type="text" placeholder="Search resolved cases…" oninput="filterTable(this.value)"/>
    </div>

    <div class="tb-acts">
      <div class="live-pill"><span class="live-dot"></span>Live</div>

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

  <!-- ═══ CONTENT ═══ -->
  <div class="content">

    <!-- 🔴 STAT CARDS WITH DUAL STATUS -->
    <div class="stats-row">
      <div class="sc c1">
        <div class="sc-ico"><i class="fas fa-check-double"></i></div>
        <div class="sc-num"><?= number_format($stats['resolved_count']??0) ?></div>
        <div class="sc-lbl">Total Resolved</div>
      </div>
      <div class="sc c2">
        <div class="sc-ico"><i class="fas fa-user-shield"></i></div>
        <div class="sc-num" style="color:var(--sky)"><?= number_format($stats['police_resolved']??0) ?></div>
        <div class="sc-lbl">🚔 Resolved by Police</div>
      </div>
      <div class="sc c3">
        <div class="sc-ico"><i class="fas fa-hospital"></i></div>
        <div class="sc-num" style="color:var(--amber)"><?= number_format($stats['hospital_resolved']??0) ?></div>
        <div class="sc-lbl">🏥 Resolved by Hospital</div>
      </div>
      <div class="sc c4">
        <div class="sc-ico"><i class="fas fa-calendar-day"></i></div>
        <div class="sc-num" style="color:var(--accent)"><?= number_format($stats['today_accidents']??0) ?></div>
        <div class="sc-lbl">Today's Accidents</div>
      </div>
    </div>

    <!-- HOSPITAL STATUS BREAKDOWN -->
    <?php if(!empty($hospital_stats)): ?>
    <div style="display:flex;flex-wrap:wrap;gap:0.5rem;margin-bottom:1rem;padding:0.8rem 1rem;background:var(--bg3);border:1px solid var(--border);border-radius:12px;">
      <span style="font-size:.65rem;color:var(--muted);font-weight:600;margin-right:0.5rem;">🏥 Hospital Status Breakdown:</span>
      <?php foreach($hospital_stats as $status => $count): ?>
        <?php if($count > 0): ?>
          <span class="badge <?= $status ?>" style="font-size:.5rem;padding:.1rem .5rem;">
            <?= ucfirst(str_replace('_', ' ', $status)) ?>: <?= $count ?>
          </span>
        <?php endif; ?>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- FILTER BAR -->
    <div class="filter-bar">
      <a href="?filter=all" class="<?= $filter_by=='all'?'active':'' ?>">All Resolved</a>
      <a href="?filter=police" class="<?= $filter_by=='police'?'active':'' ?>"><i class="fas fa-user-shield"></i> By Police</a>
      <a href="?filter=hospital" class="<?= $filter_by=='hospital'?'active':'' ?>"><i class="fas fa-hospital"></i> By Hospital</a>
      <span class="count"><?= $resolved_cases->num_rows ?> cases</span>
    </div>

    <!-- 🔴 RESOLVED CASES TABLE -->
    <div class="panel">
      <div class="ph">
        <div>
          <div class="ph-t"><i class="fas fa-check-circle"></i>Resolved Cases</div>
          <div class="ph-s">All cases that have been successfully resolved</div>
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
              <th>Type</th>
              <th>Injured</th>
              <th>🚔 Police Status</th>
              <th>🏥 Hospital Status</th>
              <th>Resolved By</th>
              <th>Time</th>
              <th style="text-align:right">Action</th>
            </tr>
          </thead>
          <tbody id="tableBody">
          <?php if($resolved_cases && $resolved_cases->num_rows > 0):
            while($r = $resolved_cases->fetch_assoc()):
              $img_path = getAccidentImage($r);
              $has_img = $img_path && file_exists('../' . $img_path);
              
              $is_police_resolved = ($r['police_status'] == 'resolved');
              $is_hospital_resolved = ($r['hospital_status'] == 'treated' || $r['hospital_status'] == 'closed');
              
              if($is_police_resolved && $is_hospital_resolved){
                $resolved_class = 'both';
                $resolved_text = '🚔🏥 Both';
              } elseif($is_police_resolved){
                $resolved_class = 'police_only';
                $resolved_text = '🚔 Police';
              } elseif($is_hospital_resolved){
                $resolved_class = 'hospital_only';
                $resolved_text = '🏥 Hospital';
              } else {
                $resolved_class = 'unknown';
                $resolved_text = '—';
              }
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
            <td><?= htmlspecialchars($r['accident_type']??'—') ?></td>
            <td style="font-family:'JetBrains Mono',monospace;color:var(--amber);font-weight:700;text-align:center;"><?= intval($r['injuries']) ?></td>
            <td>
              <?php if($is_police_resolved): ?>
                <span class="badge resolved">
                  <span class="badge-dot"></span>✅ Resolved
                </span>
              <?php else: ?>
                <span style="color:var(--muted);font-size:.6rem;">—</span>
              <?php endif; ?>
            </td>
            <td>
              <?php if($is_hospital_resolved): ?>
                <span class="badge closed">
                  <span class="badge-dot"></span>✅ Closed
                </span>
              <?php else: ?>
                <span class="badge <?= $r['hospital_status'] ?? 'pending' ?>">
                  <span class="badge-dot"></span>
                  <?= ucfirst(str_replace('_', ' ', $r['hospital_status'] ?? 'pending')) ?>
                </span>
              <?php endif; ?>
            </td>
            <td>
              <span class="resolved-by <?= $resolved_class ?>">
                <?= $resolved_text ?>
              </span>
            </td>
            <td class="td-time"><?= date('d M, H:i',strtotime($r['created_at'])) ?></td>
            <td style="text-align:right;white-space:nowrap">
              <button class="view-btn" onclick="viewAccident(<?= $r['id'] ?>)">
                <i class="fas fa-eye"></i> View
              </button>
            </td>
          </tr>
          <?php endwhile; else: ?>
          <tr>
            <td colspan="11" style="text-align:center;padding:2rem;color:var(--muted)">
              <i class="fas fa-check-circle" style="font-size:2rem;display:block;margin-bottom:.5rem;opacity:.3;"></i>
              No resolved cases found
            </td>
          </tr>
          <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

  </div><!-- /content -->

  <div class="footer">
    <span>© 2026 RoadAlert TZ &nbsp;·&nbsp; System: Operational &nbsp;·&nbsp; View Resolved Accidents By Police & Hospital.</span>
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
/* ── CLOCK ────────────────────────────────────── */
function tick(){
  const n=new Date();
  document.getElementById('clock').textContent=
    n.toLocaleDateString('en-GB',{weekday:'short',day:'2-digit',month:'short'})+
    ' · '+n.toLocaleTimeString('en-GB',{hour:'2-digit',minute:'2-digit',second:'2-digit'});
}
setInterval(tick,1000);tick();

/* ── TABLE FILTER ─────────────────────────────── */
function filterTable(q){
  const rows=document.querySelectorAll('#tableBody tr');q=q.toLowerCase();
  rows.forEach(r=>{
    r.style.display=r.textContent.toLowerCase().includes(q)?'':'none';
  });
}

/* ── NOTIFICATION DROPDOWN ────────────────────── */
function toggleNotif(){
  const d=document.getElementById('notifDrop');
  const open=d.classList.toggle('show');
  if(open) closeProfileDrop();
}

/* ── PROFILE DROPDOWN ─────────────────────────── */
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

/* ── PROFILE MODAL ────────────────────────────── */
function openProfileModal(){
    document.getElementById('profileModal').classList.add('show');
    closeProfileDrop();
}
function closeProfileModal(){
    document.getElementById('profileModal').classList.remove('show');
}

/* ── HELPER: ltrim ───────────────────────────── */
function ltrim(str) {
    if (!str) return '';
    return str.replace(/^\/+/, '');
}

/* ── VIEW ACCIDENT WITH MODAL ────────────────── */
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
            
            if (data.images) {
                try {
                    let images = typeof data.images === 'string' ? JSON.parse(data.images) : data.images;
                    if (Array.isArray(images) && images.length > 0) {
                        imageUrl = '../' + ltrim(images[0], '/');
                        hasImage = true;
                    }
                } catch(e) {}
            }
            
            if (!hasImage && data.photo_path) {
                imageUrl = '../' + ltrim(data.photo_path, '/');
                hasImage = true;
            }
            
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
            
            // Get statuses
            const policeStatus = data.police_status || data.status || 'pending';
            const hospitalStatus = data.hospital_status || 'not_assigned';
            
            // Determine resolved by
            let resolvedBy = '';
            if (policeStatus === 'resolved' && (hospitalStatus === 'treated' || hospitalStatus === 'closed')) {
                resolvedBy = '🚔🏥 Both (Police & Hospital)';
            } else if (policeStatus === 'resolved') {
                resolvedBy = '🚔 Police Only';
            } else if (hospitalStatus === 'treated' || hospitalStatus === 'closed') {
                resolvedBy = '🏥 Hospital Only';
            } else {
                resolvedBy = '—';
            }
            
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
                    <div class="item">
                        <div class="label">🚔 Police Status</div>
                        <div class="value">
                            <span class="badge ${policeStatus}">
                                <span class="badge-dot"></span>${policeStatus.toUpperCase()}
                            </span>
                        </div>
                    </div>
                    <div class="item">
                        <div class="label">🏥 Hospital Status</div>
                        <div class="value">
                            <span class="badge ${hospitalStatus}">
                                <span class="badge-dot"></span>${hospitalStatus.replace('_', ' ').toUpperCase()}
                            </span>
                        </div>
                    </div>
                    <div class="item full">
                        <div class="label">Resolved By</div>
                        <div class="value">${resolvedBy}</div>
                    </div>
                    <div class="item full">
                        <div class="label">Description</div>
                        <div class="value" style="font-size:.75rem;">${data.description || 'No description provided'}</div>
                    </div>
                    <div class="item full">
                        <div class="label">Reported Time</div>
                        <div class="value" style="font-size:.75rem;">${data.created_at ? new Date(data.created_at).toLocaleString() : 'N/A'}</div>
                    </div>
                    <div class="item full">
                        <div class="label">🏛️ Assigned Police</div>
                        <div class="value" style="font-size:.75rem;">${data.police_station_name || 'Not assigned'}</div>
                    </div>
                    <div class="item full">
                        <div class="label">🏥 Assigned Hospital</div>
                        <div class="value" style="font-size:.75rem;">${data.hospital_station_name || 'Not assigned'}</div>
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

/* ── CLOSE ON OUTSIDE ────────────────────────── */
document.addEventListener('click',function(e){
  if(!e.target.closest('#notifDrop')&&!e.target.closest('.notif-btn'))
    document.getElementById('notifDrop').classList.remove('show');
  if(!e.target.closest('#profileDrop')&&!e.target.closest('#profileBtn'))
    closeProfileDrop();
  if(e.target.classList.contains('modal-bg')) {
    e.target.classList.remove('show');
  }
  if(e.target === document.getElementById('profileModal')) {
    closeProfileModal();
  }
});

/* ── ESCAPE KEY ────────────────────────────────── */
document.addEventListener('keydown', function(e){
  if(e.key === 'Escape'){ 
    closeModal();
    closeProfileModal();
  }
});

/* ── TOAST ────────────────────────────────────── */
function showToast(title,msg,type){
  const w=document.getElementById('toastWrap');
  const icon=type==='ok'?'✅':type==='err'?'⚠️':'🚨';
  const el=document.createElement('div');el.className='toast';
  el.innerHTML=`<div class="t-ic">${icon}</div>
    <div class="t-body"><div class="t-title">${title}</div><div class="t-msg">${msg}</div></div>
    <button class="t-x" onclick="this.closest('.toast').remove()">✕</button>`;
  w.appendChild(el);setTimeout(()=>el.remove(),5500);
}
</script>
</body>
</html>