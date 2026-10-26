<?php
// admin/manage_hospital.php - Manage Hospital Stations .
session_start();
include __DIR__ . '/../connection.php';

if (empty($_SESSION['user_id']) || strtolower($_SESSION['role'] ?? '') !== 'admin') {
    header("Location: ../login.php"); exit();
}

// Helper function for active page
function is_active($page) {
    return basename($_SERVER['PHP_SELF']) === $page ? 'active' : '';
}

// ============================================
// 🔴 HANDLE DELETE - WITH AUDIT LOG
// ============================================
if (isset($_GET['delete'])) {
    $id = intval($_GET['delete']);
    
    // Get hospital details before deleting
    $hospital = $conn->query("SELECT name, location FROM hospital_stations WHERE id = $id")->fetch_assoc();
    
    // 🔴 AUDIT LOG - DELETE HOSPITAL
    createAuditLog($conn, 'Deleted Hospital | Hospital: ' . $hospital['name'] . ' | Location: ' . ($hospital['location'] ?? 'Unknown'));
    
    $conn->query("DELETE FROM hospital_stations WHERE id = $id");
    header("Location: manage_hospital.php?msg=deleted");
    exit();
}

// ============================================
// 🔴 HANDLE ADD/UPDATE - WITH AUDIT LOG
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = intval($_POST['id'] ?? 0);
    $name = mysqli_real_escape_string($conn, $_POST['name']);
    $location = mysqli_real_escape_string($conn, $_POST['location'] ?? '');
    $phone = mysqli_real_escape_string($conn, $_POST['phone'] ?? '');
    $email = mysqli_real_escape_string($conn, $_POST['email'] ?? '');
    $region = mysqli_real_escape_string($conn, $_POST['region'] ?? '');
    $latitude = floatval($_POST['latitude'] ?? 0);
    $longitude = floatval($_POST['longitude'] ?? 0);
    $is_active = isset($_POST['is_active']) ? 1 : 0;

    if ($id > 0) {
        // Get old data for audit log
        $old_hospital = $conn->query("SELECT name, location FROM hospital_stations WHERE id = $id")->fetch_assoc();
        
        $sql = "UPDATE hospital_stations SET 
                name='$name', location='$location', phone='$phone', 
                email='$email', region='$region', 
                latitude='$latitude', longitude='$longitude',
                is_active=$is_active 
                WHERE id=$id";
        $conn->query($sql);
        
        // 🔴 AUDIT LOG - UPDATE HOSPITAL
        createAuditLog($conn, 'Updated Hospital | Hospital: ' . $name . ' | Location: ' . $location . ' | Status: ' . ($is_active ? 'Active' : 'Inactive'));
        
        $msg = 'Hospital updated successfully!';
    } else {
        $sql = "INSERT INTO hospital_stations (name, location, phone, email, region, latitude, longitude, is_active) 
                VALUES ('$name', '$location', '$phone', '$email', '$region', '$latitude', '$longitude', $is_active)";
        $conn->query($sql);
        
        // 🔴 AUDIT LOG - ADD HOSPITAL
        createAuditLog($conn, 'Created Hospital | Hospital: ' . $name . ' | Location: ' . $location . ' | Status: ' . ($is_active ? 'Active' : 'Inactive'));
        
        $msg = 'Hospital added successfully!';
    }
    header("Location: manage_hospital.php?msg=" . urlencode($msg));
    exit();
}

// Get edit data
$edit_data = null;
if (isset($_GET['edit'])) {
    $id = intval($_GET['edit']);
    $result = $conn->query("SELECT * FROM hospital_stations WHERE id = $id");
    $edit_data = $result->fetch_assoc();
}

// Get all hospital stations
$stations = $conn->query("SELECT * FROM hospital_stations ORDER BY name");

$msg = $_GET['msg'] ?? '';

// ============================================
// 🔴 FETCH PENDING COUNT WITH DUAL STATUS - FIXED
// ============================================
$pending_count = $conn->query("
    SELECT COUNT(*) as count FROM accidents 
    WHERE police_status='pending' OR hospital_status='pending'
")->fetch_assoc()['count'];

// ─── 🔴 POLICE RESOLVED ───
$police_resolved = $conn->query("
    SELECT COUNT(*) as count FROM accidents 
    WHERE police_status='resolved'
")->fetch_assoc()['count'] ?? 0;

// ─── 🔴 HOSPITAL TREATED ───
$hospital_treated = $conn->query("
    SELECT COUNT(*) as count FROM accidents 
    WHERE hospital_status='treated'
")->fetch_assoc()['count'] ?? 0;

// ─── 🔴 TOTAL RESOLVED = Police Resolved + Hospital Treated ───
$total_resolved = $police_resolved + $hospital_treated;

$stats = [
    'pending_count' => $pending_count,
    'resolved_count' => $total_resolved,
    'police_resolved' => $police_resolved,
    'hospital_treated' => $hospital_treated,
];

// Admin info
$admin_name = htmlspecialchars($_SESSION['name'] ?? 'System Admin');
$admin_email = htmlspecialchars($_SESSION['email'] ?? 'admin@roadalert.tz');
$admin_role = htmlspecialchars($_SESSION['role'] ?? 'admin');
$admin_init = strtoupper(substr($admin_name, 0, 1));

// ============================================
// 🔴 FETCH PENDING ACCIDENTS WITH DUAL STATUS
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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8"/>
    <meta name="viewport" content="width=device-width,initial-scale=1.0"/>
    <title>Manage Hospitals — RoadAlert TZ</title>
    <link href="https://fonts.googleapis.com/css2?family=Sora:wght@300;400;500;600;700;800&family=Space+Grotesk:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet"/>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"/>
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"/>
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <script src="https://cdn.tailwindcss.com"></script>
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
        .status-active { background: #d1fae5; color: #065f46; padding: 4px 12px; border-radius: 9999px; font-size: 11px; font-weight: 500; display: inline-block; }
        .status-inactive { background: #fee2e2; color: #991b1b; padding: 4px 12px; border-radius: 9999px; font-size: 11px; font-weight: 500; display: inline-block; }
        .modal-overlay {
            position: fixed; inset: 0; background: rgba(0,0,0,0.5); backdrop-filter: blur(8px);
            z-index: 1000; display: none; align-items: center; justify-content: center; padding: 20px;
        }
        .modal-overlay.active { display: flex; }
        .modal-content {
            background: var(--bg3); border: 1px solid var(--bord2);
            border-radius: 24px; max-width: 700px; width: 100%;
            max-height: 90vh; overflow-y: auto; padding: 0; box-shadow: 0 25px 50px -12px rgba(0,0,0,0.25);
        }
        .modal-header {
            padding: 1.2rem 1.5rem; border-bottom: 1px solid var(--border);
            display: flex; justify-content: space-between; align-items: center;
            position: sticky; top: 0; background: var(--bg3); border-radius: 24px 24px 0 0;
            z-index: 10;
        }
        .modal-header h3 { color: var(--white); font-family: 'Sora', sans-serif; }
        .modal-body { padding: 1.5rem; }
        .modal-body label { color: var(--muted); font-size: 0.7rem; text-transform: uppercase; font-weight: 600; letter-spacing: 0.05em; display: block; margin-bottom: 0.3rem; }
        .modal-body input, .modal-body select { 
            width: 100%; background: var(--bg4); border: 1px solid var(--border);
            border-radius: 8px; padding: 0.5rem 0.7rem;
            font-family: 'Space Grotesk', sans-serif; font-size: 0.78rem;
            color: var(--light); outline: none; transition: border-color .18s;
        }
        .modal-body input:focus, .modal-body select:focus { border-color: var(--accent); }
        .modal-body .form-group { margin-bottom: 1rem; }
        .btn-gold { background: #E8C547; color: #222222; font-weight: 700; transition: all 0.3s; border: none; cursor: pointer; }
        .btn-gold:hover { background: #D4AF37; transform: scale(1.02); }
        .btn-gold-outline { border: 1px solid #E8C547; color: #E8C547; background: transparent; font-weight: 600; transition: all 0.3s; }
        .btn-gold-outline:hover { background: #E8C547; color: #222222; transform: scale(1.02); }
        .notification-item:hover { background: #f2f3fd; transform: translateX(5px); }
        @keyframes pulse { 0%,100% { opacity: 1; } 50% { opacity: 0.5; } }
        .pulse { animation: pulse 1s infinite; }
        
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
          font-family:'Space Grotesk',sans-serif;text-decoration:none;
        }
        .sb-logout-item .sb-ic{width:30px;height:30px;border-radius:7px;flex-shrink:0;
          display:flex;align-items:center;justify-content:center;font-size:.82rem;
          background:rgba(255,255,255,.04);transition:background .18s,color .18s}
        .sb-logout-item:hover{background:rgba(244,63,94,.08);color:var(--red)}
        .sb-logout-item:hover .sb-ic{background:rgba(244,63,94,.14);color:var(--red)}
        .sb-divider{height:1px;background:var(--border);margin:.5rem .8rem}

        /* ── TOPBAR ── */
        .topbar{height:var(--th);flex-shrink:0;
          background:var(--bg2);border-bottom:1px solid var(--border);
          display:flex;align-items:center;gap:.9rem;padding:0 1.4rem;
          position:relative;z-index:40}
        .tb-title{font-family:'Sora',sans-serif;font-size:1.25rem;
          font-weight:700;color:var(--white);flex:1}
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

        /* live pill */
        .live-pill{display:flex;align-items:center;gap:.38rem;
          background:rgba(0,229,160,.08);border:1px solid rgba(0,229,160,.2);
          border-radius:999px;padding:.26rem .75rem;
          font-family:'JetBrains Mono',monospace;font-size:.6rem;color:var(--accent)}
        .live-dot{width:6px;height:6px;border-radius:50%;background:var(--accent);
          animation:blink 1.3s step-end infinite}
        @keyframes blink{0%,100%{opacity:1}50%{opacity:0}}

        /* notif bell */
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

        /* ── PROFILE MODAL ── */
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

        /* ── PROFILE BUTTON (top-right) ── */
        .profile-wrap{position:relative}
        .profile-btn{
          display:flex;align-items:center;gap:.55rem;
          background:var(--bg3);border:1px solid var(--bord2);
          border-radius:10px;padding:.38rem .65rem .38rem .45rem;
          cursor:pointer;transition:background .18s,border-color .18s;
        }
        .profile-btn:hover{background:var(--acc-lo2);border-color:rgba(0,229,160,.25)}
        .profile-btn.open{background:var(--acc-lo);border-color:rgba(0,229,160,.35)}
        .pa{
          width:30px;height:30px;border-radius:7px;flex-shrink:0;
          background:linear-gradient(135deg,var(--accent),var(--accent2));
          display:flex;align-items:center;justify-content:center;
          font-size:.82rem;font-weight:700;color:#07111B;
          box-shadow:0 0 10px rgba(0,229,160,.35);
        }
        .pname{font-size:.76rem;font-weight:600;color:var(--light);
          max-width:90px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
        .pchev{font-size:.7rem;color:var(--muted);transition:transform .22s}
        .profile-btn.open .pchev{transform:rotate(180deg)}

        /* profile dropdown */
        .profile-drop{
          position:absolute;right:0;top:calc(100% + 9px);
          width:240px;background:var(--bg3);border:1px solid var(--bord2);
          border-radius:14px;overflow:hidden;box-shadow:0 16px 48px rgba(0,0,0,.55);
          opacity:0;transform:translateY(-8px) scale(.97);
          pointer-events:none;transition:opacity .2s,transform .2s;z-index:999;
        }
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

        /* logout topbar btn */
        .tb-btn{display:flex;align-items:center;gap:.38rem;
          padding:.38rem .8rem;border-radius:8px;font-size:.74rem;
          border:1px solid var(--bord2);background:transparent;
          color:var(--light);cursor:pointer;transition:background .18s;
          font-family:'Space Grotesk',sans-serif;text-decoration:none}
        .tb-btn:hover{background:rgba(244,63,94,.08);color:var(--red);border-color:rgba(244,63,94,.25)}
        .tb-btn.primary{background:var(--accent);border-color:var(--accent);
          color:#07111B;font-weight:600;box-shadow:0 0 14px rgba(0,229,160,.3)}
        .tb-btn.primary:hover{background:var(--accent2);color:#07111B}

        .footer{flex-shrink:0;border-top:1px solid var(--border);
          padding:.6rem 1.4rem;display:flex;align-items:center;justify-content:space-between;
          font-size:.66rem;color:var(--muted);
          font-family:'JetBrains Mono',monospace;background:var(--bg2)}
        
        #hospitalMap { height: 350px; width: 100%; border-radius: 12px; border: 1px solid var(--border); background: #0A1825; }
        .map-legend { display: flex; gap: 1rem; align-items: center; padding: 0.5rem 0; }
        .leg { display: flex; align-items: center; gap: 0.3rem; font-size: 0.68rem; color: var(--muted2); }
        .leg-d { width: 10px; height: 10px; border-radius: 50%; background: #E8C547; }
        
        @media(max-width:760px){ :root{--sw:0px} .sidebar{display:none} }
    </style>
</head>
<body>
<div class="shell" style="display:flex;height:100vh;overflow:hidden;">

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
    
    <!-- Dashboard -->
    <a href="dashboard.php" class="sb-item <?= basename($_SERVER['PHP_SELF']) == 'dashboard.php' ? 'active' : '' ?>">
      <span class="sb-ic"><i class="fas fa-chart-line"></i></span> Dashboard
    </a>
    
    <!-- Accident Reports -->
    <a href="accident_report.php" class="sb-item <?= basename($_SERVER['PHP_SELF']) == 'accident_report.php' ? 'active' : '' ?>">
      <span class="sb-ic"><i class="fas fa-car-crash"></i></span> Accident Reports
      <?php if($pending_count > 0): ?>
        <span class="sb-badge red"><?= $pending_count ?></span>
      <?php endif; ?>
    </a>
    
    <a href="resolved_cases.php" class="sb-item <?= basename($_SERVER['PHP_SELF']) == 'resolved_cases.php' ? 'active' : '' ?>">
    <span class="sb-ic"><i class="fas fa-check-circle"></i></span> Resolved Cases
    <?php if(($police_resolved ?? 0) > 0 || ($hospital_treated ?? 0) > 0): ?>
        <span class="sb-badge grn"><?= ($police_resolved ?? 0) + ($hospital_treated ?? 0) ?></span>
    <?php endif; ?>
</a>

    <div class="sb-sect" style="margin-top:.4rem">Management</div>
    
    <!-- Police Stations -->
    <a href="manage_police.php" class="sb-item <?= basename($_SERVER['PHP_SELF']) == 'manage_police.php' ? 'active' : '' ?>">
      <span class="sb-ic"><i class="fas fa-user-shield"></i></span> Police Stations
    </a>
    
    <!-- Hospitals - ACTIVE -->
    <a href="manage_hospital.php" class="sb-item <?= basename($_SERVER['PHP_SELF']) == 'manage_hospital.php' ? 'active' : '' ?>">
      <span class="sb-ic"><i class="fas fa-hospital"></i></span> Hospitals
    </a>
    
    <!-- Manage Users -->
    <a href="users.php" class="sb-item <?= basename($_SERVER['PHP_SELF']) == 'users.php' ? 'active' : '' ?>">
      <span class="sb-ic"><i class="fas fa-users"></i></span> Manage Users
    </a>
    
    <!-- 🔴 REPLACED: Settings → Audit Logs -->
    <a href="audit_logs.php" class="sb-item <?= basename($_SERVER['PHP_SELF']) == 'audit_logs.php' ? 'active' : '' ?>">
      <span class="sb-ic"><i class="fas fa-clipboard-list"></i></span> Audit Logs
    </a>

    <div class="sb-sect" style="margin-top:.4rem">Quick</div>
    
    <!-- New Report -->
    <a href="admin_report.php" class="sb-item" target="_blank">
      <span class="sb-ic"><i class="fas fa-plus-circle"></i></span> New Report
    </a>
    
    <!-- ── DIVIDER + LOGOUT at bottom of nav ── -->
    <div class="sb-divider" style="margin-top:.8rem"></div>
    <a href="../logout.php" class="sb-logout-item">
      <span class="sb-ic"><i class="fas fa-sign-out-alt"></i></span>
      Logout
    </a>
  </nav>
</aside>
<!-- ═══════════════════ MAIN ═══════════════════ -->
<div class="main" style="flex:1;display:flex;flex-direction:column;overflow:hidden;min-width:0;background:var(--bg);">

  <!-- ═══ TOPBAR (SAME AS DASHBOARD) ═══ -->
  <div class="topbar">
    <div class="tb-title">🏥 Manage Hospitals</div>

    <div class="tb-search">
      <i class="fas fa-search"></i>
      <input type="text" placeholder="Search hospitals..." oninput="filterTable(this.value)" id="searchInput"/>
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

      <!-- Logout button topbar -->
      <a href="../logout.php" class="tb-btn">
        <i class="fas fa-sign-out-alt"></i>
      </a>
    </div>
  </div>

  <!-- CONTENT -->
  <div class="content" style="flex:1;overflow-y:auto;padding:1.4rem 1.5rem;">

    <!-- Header -->
    <div class="flex justify-between items-center mb-4 flex-wrap gap-3">
        <div>
            <h2 style="color:var(--white);font-family:'Sora',sans-serif;font-size:1.5rem;font-weight:700;">Manage Hospitals</h2>
            <p style="color:var(--muted);font-size:0.8rem;">Add, edit, and manage hospital locations</p>
        </div>
        <button onclick="openAddModal()" class="btn-gold px-4 py-2 rounded-lg text-sm">
            <i class="fas fa-plus"></i> Add Hospital
        </button>
    </div>

    <!-- Stats -->
    <?php
    $total = $conn->query("SELECT COUNT(*) as c FROM hospital_stations")->fetch_assoc()['c'];
    $active = $conn->query("SELECT COUNT(*) as c FROM hospital_stations WHERE is_active=1")->fetch_assoc()['c'];
    $inactive = $conn->query("SELECT COUNT(*) as c FROM hospital_stations WHERE is_active=0")->fetch_assoc()['c'];
    ?>
    <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-4">
        <div style="background:var(--bg3);border:1px solid var(--border);border-radius:12px;padding:0.9rem;text-align:center;">
            <p style="font-size:1.8rem;font-weight:800;color:var(--accent);"><?= $total ?></p>
            <p style="color:var(--muted);font-size:0.6rem;text-transform:uppercase;">Total Hospitals</p>
        </div>
        <div style="background:var(--bg3);border:1px solid var(--border);border-radius:12px;padding:0.9rem;text-align:center;">
            <p style="font-size:1.8rem;font-weight:800;color:#4ade80;"><?= $active ?></p>
            <p style="color:var(--muted);font-size:0.6rem;text-transform:uppercase;">Active</p>
        </div>
        <div style="background:var(--bg3);border:1px solid var(--border);border-radius:12px;padding:0.9rem;text-align:center;">
            <p style="font-size:1.8rem;font-weight:800;color:var(--red);"><?= $inactive ?></p>
            <p style="color:var(--muted);font-size:0.6rem;text-transform:uppercase;">Inactive</p>
        </div>
    </div>

    <!-- Message -->
    <?php if ($msg): ?>
        <div class="mb-4 p-3 rounded-lg" style="background:rgba(0,229,160,.1);border:1px solid rgba(0,229,160,.2);color:var(--accent);">
            <?= htmlspecialchars($msg) ?>
        </div>
    <?php endif; ?>

    <!-- Table -->
    <div style="background:var(--bg3);border:1px solid var(--border);border-radius:14px;overflow:hidden;margin-bottom:1.5rem;">
        <div style="overflow-x:auto;">
            <table style="width:100%;border-collapse:collapse;font-size:0.76rem;" id="hospitalTable">
                <thead>
                    <tr style="border-bottom:1px solid var(--border);">
                        <th style="padding:0.6rem 0.9rem;text-align:left;font-size:0.6rem;text-transform:uppercase;color:var(--muted);font-weight:600;">ID</th>
                        <th style="padding:0.6rem 0.9rem;text-align:left;font-size:0.6rem;text-transform:uppercase;color:var(--muted);font-weight:600;">Name</th>
                        <th style="padding:0.6rem 0.9rem;text-align:left;font-size:0.6rem;text-transform:uppercase;color:var(--muted);font-weight:600;">Location</th>
                        <th style="padding:0.6rem 0.9rem;text-align:left;font-size:0.6rem;text-transform:uppercase;color:var(--muted);font-weight:600;">Phone</th>
                        <th style="padding:0.6rem 0.9rem;text-align:left;font-size:0.6rem;text-transform:uppercase;color:var(--muted);font-weight:600;">Region</th>
                        <th style="padding:0.6rem 0.9rem;text-align:left;font-size:0.6rem;text-transform:uppercase;color:var(--muted);font-weight:600;">Status</th>
                        <th style="padding:0.6rem 0.9rem;text-align:right;font-size:0.6rem;text-transform:uppercase;color:var(--muted);font-weight:600;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($stations && $stations->num_rows > 0): ?>
                        <?php while($row = $stations->fetch_assoc()): ?>
                            <tr style="border-bottom:1px solid rgba(255,255,255,.04);">
                                <td style="padding:0.6rem 0.9rem;color:var(--muted2);">#<?= $row['id'] ?></td>
                                <td style="padding:0.6rem 0.9rem;font-weight:500;color:var(--light);"><?= htmlspecialchars($row['name']) ?></td>
                                <td style="padding:0.6rem 0.9rem;color:var(--muted2);"><?= htmlspecialchars($row['location'] ?? '—') ?></td>
                                <td style="padding:0.6rem 0.9rem;color:var(--muted2);"><?= htmlspecialchars($row['phone'] ?? '—') ?></td>
                                <td style="padding:0.6rem 0.9rem;color:var(--muted2);"><?= htmlspecialchars($row['region'] ?? '—') ?></td>
                                <td style="padding:0.6rem 0.9rem;">
                                    <span class="<?= $row['is_active'] ? 'status-active' : 'status-inactive' ?>">
                                        <?= $row['is_active'] ? 'Active' : 'Inactive' ?>
                                    </span>
                                </td>
                                <td style="padding:0.6rem 0.9rem;text-align:right;">
                                    <a href="?edit=<?= $row['id'] ?>" style="color:var(--sky);text-decoration:none;padding:0 4px;">
                                        <i class="fas fa-edit"></i>
                                    </a>
                                    <a href="?delete=<?= $row['id'] ?>" onclick="return confirm('Delete this hospital?')" style="color:var(--red);text-decoration:none;padding:0 4px;">
                                        <i class="fas fa-trash"></i>
                                    </a>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="7" style="padding:2rem;text-align:center;color:var(--muted);">
                                <i class="fas fa-hospital" style="font-size:2rem;display:block;margin-bottom:0.5rem;opacity:0.3;"></i>
                                No hospitals found
                                <button onclick="openAddModal()" style="display:block;margin:0.5rem auto 0;background:transparent;color:var(--accent);border:1px solid var(--accent);padding:0.3rem 1rem;border-radius:6px;cursor:pointer;">Add your first hospital</button>
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

  </div>

  <div class="footer">
    <span>© 2026 RoadAlert TZ &nbsp;·&nbsp; System: Operational &nbsp;·&nbsp; Hospital Management.</span>
    <span id="clock"></span>
  </div>
</div>
</div>

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

<!-- ═══════════════════ ADD/EDIT MODAL WITH MAP ═══════════════════ -->
<div class="modal-overlay <?= $edit_data ? 'active' : '' ?>" id="stationModal">
    <div class="modal-content">
        <div class="modal-header">
            <h3><?= $edit_data ? '✏️ Edit Hospital' : '➕ Add Hospital' ?></h3>
            <button onclick="closeStationModal()" style="background:rgba(255,255,255,.05);border:1px solid var(--border);border-radius:7px;width:27px;height:27px;display:flex;align-items:center;justify-content:center;font-size:.7rem;color:var(--muted2);cursor:pointer;">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <form method="POST" class="modal-body">
            <input type="hidden" name="id" value="<?= $edit_data['id'] ?? 0 ?>">
            
            <div class="form-group">
                <label>🏥 Hospital Name *</label>
                <input type="text" name="name" required value="<?= htmlspecialchars($edit_data['name'] ?? '') ?>" placeholder="e.g., Muhimbili National Hospital">
            </div>
            
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div class="form-group">
                    <label>📍 Location</label>
                    <input type="text" name="location" value="<?= htmlspecialchars($edit_data['location'] ?? '') ?>" placeholder="e.g., Upanga, Dar es Salaam">
                </div>
                <div class="form-group">
                    <label>📞 Phone Number</label>
                    <input type="tel" name="phone" value="<?= htmlspecialchars($edit_data['phone'] ?? '') ?>" placeholder="+255 765 432 109">
                </div>
            </div>
            
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div class="form-group">
                    <label>📧 Email</label>
                    <input type="email" name="email" value="<?= htmlspecialchars($edit_data['email'] ?? '') ?>" placeholder="info@hospital.tz">
                </div>
                <div class="form-group">
                    <label>🌍 Region</label>
                    <input type="text" name="region" value="<?= htmlspecialchars($edit_data['region'] ?? '') ?>" placeholder="e.g., Dar es Salaam">
                </div>
            </div>
            
            <!-- 🔴 MAP SECTION -->
            <div class="form-group">
                <label>🗺️ Click on map to set location</label>
                <div id="hospitalMap"></div>
                <div class="map-legend">
                    <div class="leg"><div class="leg-d"></div> Selected Location</div>
                    <div class="leg" style="color:var(--muted);font-size:0.6rem;">
                        <i class="fas fa-info-circle"></i> Click on map to set hospital location
                    </div>
                </div>
            </div>
            
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div class="form-group">
                    <label>🌐 Latitude</label>
                    <input type="number" name="latitude" id="latitude" step="0.00000001" 
                           value="<?= $edit_data['latitude'] ?? '-6.7924' ?>" 
                           placeholder="-6.7924">
                </div>
                <div class="form-group">
                    <label>🌐 Longitude</label>
                    <input type="number" name="longitude" id="longitude" step="0.00000001" 
                           value="<?= $edit_data['longitude'] ?? '39.2083' ?>" 
                           placeholder="39.2083">
                </div>
            </div>
            
            <div class="form-group">
                <label class="flex items-center gap-3 cursor-pointer" style="color:var(--light);font-size:0.85rem;text-transform:none;">
                    <input type="checkbox" name="is_active" class="w-4 h-4" <?= ($edit_data && $edit_data['is_active']) || !$edit_data ? 'checked' : '' ?>>
                    <span>✅ Active (available for assignment)</span>
                </label>
            </div>
            
            <div class="flex gap-3 pt-4 border-t" style="border-color:var(--border);">
                <button type="button" onclick="closeStationModal()" class="btn-gold-outline flex-1 px-4 py-2 rounded-lg text-sm">Cancel</button>
                <button type="submit" class="btn-gold flex-1 px-4 py-2 rounded-lg text-sm">
                    <i class="fas fa-save"></i> <?= $edit_data ? 'Update' : 'Add' ?> Hospital
                </button>
            </div>
        </form>
    </div>
</div>

<script>
// ── CLOCK ──────────────────────────────────────
function tick(){
  const n=new Date();
  const clock = document.getElementById('clock');
  if(clock){
    clock.textContent = n.toLocaleDateString('en-GB',{weekday:'short',day:'2-digit',month:'short'}) +
      ' · ' + n.toLocaleTimeString('en-GB',{hour:'2-digit',minute:'2-digit',second:'2-digit'});
  }
}
setInterval(tick,1000);tick();

// ── PROFILE MODAL ─────────────────────────────
function openProfileModal(){
    document.getElementById('profileModal').classList.add('show');
    closeProfileDrop();
}
function closeProfileModal(){
    document.getElementById('profileModal').classList.remove('show');
}

// ── STATION MODAL ─────────────────────────────
function openAddModal() {
    document.getElementById('stationModal').classList.add('active');
    setTimeout(initMap, 300);
}
function closeStationModal() {
    document.getElementById('stationModal').classList.remove('active');
}
document.getElementById('stationModal').addEventListener('click', function(e) {
    if (e.target === this) closeStationModal();
});

// ── PROFILE DROPDOWN ───────────────────────────
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

// ── NOTIFICATION DROPDOWN ──────────────────────
function toggleNotif(){
  const d=document.getElementById('notifDrop');
  const open=d.classList.toggle('show');
  if(open) closeProfileDrop();
}

// ── TABLE FILTER ───────────────────────────────
function filterTable(q){
  const rows=document.querySelectorAll('#hospitalTable tbody tr');
  const search = q.toLowerCase();
  rows.forEach(row => {
    const text = row.textContent.toLowerCase();
    row.style.display = text.includes(search) ? '' : 'none';
  });
}

// ── LEAFLET MAP — OPENSTREETMAP ──────────────
let map;
let marker;

function initMap() {
    const lat = parseFloat(document.getElementById('latitude').value) || -6.7924;
    const lng = parseFloat(document.getElementById('longitude').value) || 39.2083;
    
    if (map) {
        map.remove();
    }
    
    map = L.map('hospitalMap').setView([lat, lng], 13);
    
    // 🔴 OPENSTREETMAP — Free, clear labels
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
        maxZoom: 19,
        minZoom: 3
    }).addTo(map);
    
    // Add marker
    marker = L.marker([lat, lng], { draggable: true }).addTo(map);
    
    // Update coordinates when marker is dragged
    marker.on('dragend', function() {
        const pos = marker.getLatLng();
        document.getElementById('latitude').value = pos.lat.toFixed(8);
        document.getElementById('longitude').value = pos.lng.toFixed(8);
    });
    
    // Click on map to set marker
    map.on('click', function(e) {
        const pos = e.latlng;
        marker.setLatLng(pos);
        document.getElementById('latitude').value = pos.lat.toFixed(8);
        document.getElementById('longitude').value = pos.lng.toFixed(8);
    });
    
    // Fit view
    setTimeout(() => {
        map.invalidateSize();
    }, 500);
}

// ── ESCAPE KEY ─────────────────────────────────
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeStationModal();
        closeProfileModal();
    }
});

// ── CLOSE ON OUTSIDE CLICK ─────────────────────
document.addEventListener('click',function(e){
  if(!e.target.closest('#notifDrop')&&!e.target.closest('.notif-btn'))
    document.getElementById('notifDrop').classList.remove('show');
  if(!e.target.closest('#profileDrop')&&!e.target.closest('#profileBtn'))
    closeProfileDrop();
  if(e.target === document.getElementById('profileModal')) {
    closeProfileModal();
  }
});

// ── INIT MAP WHEN MODAL OPENS ─────────────────
<?php if ($edit_data): ?>
document.addEventListener('DOMContentLoaded', function() {
    setTimeout(initMap, 500);
});
<?php endif; ?>

const observer = new MutationObserver(function() {
    if (document.getElementById('stationModal').classList.contains('active')) {
        setTimeout(initMap, 300);
    }
});
observer.observe(document.getElementById('stationModal'), { attributes: true, attributeFilter: ['class'] });
</script>

</body>
</html>