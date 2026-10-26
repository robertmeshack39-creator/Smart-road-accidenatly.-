<?php
// police/profile.php - Police Profile Management
session_start();
include __DIR__ . '/../connection.php';

// Check if user is logged in and is police
if (empty($_SESSION['user_id']) || strtolower($_SESSION['role'] ?? '') !== 'police') {
    header("Location: ../login.php");
    exit();
}

// Helper function for active page
function is_active($page) {
    return basename($_SERVER['PHP_SELF']) === $page ? 'active' : '';
}

// Get police user details
$police_id = $_SESSION['user_id'];
$police_name = htmlspecialchars($_SESSION['name'] ?? 'Police Officer');
$police_init = strtoupper(substr($police_name, 0, 1));

// Get user's police station with full details
$user_query = $conn->query("
    SELECT u.*, ps.id as station_id, ps.name as station_name, ps.location, 
           ps.phone as station_phone, ps.latitude, ps.longitude, ps.email as station_email
    FROM users u
    LEFT JOIN police_stations ps ON u.police_station_id = ps.id
    WHERE u.id = $police_id
");
$user = $user_query->fetch_assoc();

if (!$user) {
    header("Location: ../logout.php");
    exit();
}

$police_station = $user['station_name'] ?? 'Police Station';
$police_station_id = $user['station_id'] ?? null;
$police_station_phone = $user['station_phone'] ?? '';
$police_station_location = $user['location'] ?? '';
$police_station_lat = $user['latitude'] ?? '';
$police_station_lng = $user['longitude'] ?? '';
$police_station_email = $user['station_email'] ?? '';

// ============================================
// HANDLE PROFILE UPDATE
// ============================================

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {
    $full_name = mysqli_real_escape_string($conn, $_POST['full_name']);
    $phone = mysqli_real_escape_string($conn, $_POST['phone']);
    $email = mysqli_real_escape_string($conn, $_POST['email']);
    $station_name = mysqli_real_escape_string($conn, $_POST['station_name']);
    $station_phone = mysqli_real_escape_string($conn, $_POST['station_phone']);
    $station_location = mysqli_real_escape_string($conn, $_POST['station_location']);
    $station_lat = floatval($_POST['station_lat'] ?? 0);
    $station_lng = floatval($_POST['station_lng'] ?? 0);
    $station_email = mysqli_real_escape_string($conn, $_POST['station_email']);
    
    // Update user details
    $user_update = $conn->query("
        UPDATE users SET 
            full_name = '$full_name',
            phone = '$phone',
            email = '$email'
        WHERE id = $police_id
    ");
    
    // Update police station details
    $station_update = $conn->query("
        UPDATE police_stations SET 
            name = '$station_name',
            phone = '$station_phone',
            location = '$station_location',
            latitude = $station_lat,
            longitude = $station_lng,
            email = '$station_email'
        WHERE id = $police_station_id
    ");
    
    if ($user_update && $station_update) {
        // Update session
        $_SESSION['name'] = $full_name;
        $_SESSION['email'] = $email;
        
        header("Location: profile.php?msg=updated");
        exit();
    } else {
        $error = "Failed to update profile: " . $conn->error;
    }
}

// ============================================
// HANDLE PASSWORD CHANGE
// ============================================

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_password'])) {
    $current_password = $_POST['current_password'] ?? '';
    $new_password = $_POST['new_password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    
    // Get current password from database
    $pass_query = $conn->query("SELECT password FROM users WHERE id = $police_id");
    $pass_data = $pass_query->fetch_assoc();
    $stored_password = $pass_data['password'] ?? '';
    
    // Verify current password (plain text or hashed)
    if (password_verify($current_password, $stored_password) || $current_password === $stored_password) {
        if ($new_password === $confirm_password && strlen($new_password) >= 6) {
            // Hash new password
            $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
            $conn->query("UPDATE users SET password = '$hashed_password' WHERE id = $police_id");
            header("Location: profile.php?msg=password_changed");
            exit();
        } else {
            $pass_error = "Passwords do not match or is too short (min 6 characters)";
        }
    } else {
        $pass_error = "Current password is incorrect";
    }
}

// ============================================
// GET COUNTS FOR SIDEBAR BADGES
// ============================================
$pending_q = $conn->query("
    SELECT COUNT(*) as c 
    FROM accidents 
    WHERE assigned_police_id = $police_station_id 
    AND police_status = 'assigned'
");
$pending_row = $pending_q ? $pending_q->fetch_assoc() : null;
$pending_count = intval($pending_row['c'] ?? 0);

$active_q = $conn->query("
    SELECT COUNT(*) as c 
    FROM accidents 
    WHERE assigned_police_id = $police_station_id 
    AND police_status IN ('assigned', 'dispatched', 'arrived')
");
$active_row = $active_q ? $active_q->fetch_assoc() : null;
$active_count = intval($active_row['c'] ?? 0);

$resolved_q = $conn->query("
    SELECT COUNT(*) as c 
    FROM accidents 
    WHERE assigned_police_id = $police_station_id 
    AND police_status = 'resolved'
");
$resolved_row = $resolved_q ? $resolved_q->fetch_assoc() : null;
$resolved_count = intval($resolved_row['c'] ?? 0);

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
<title>My Profile — <?= htmlspecialchars($police_station) ?></title>
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

.station-badge{display:flex;align-items:center;gap:.5rem;
  background:var(--bg3);border:1px solid var(--police-gold);
  border-radius:999px;padding:.2rem .8rem .2rem .4rem;
  font-size:.65rem;color:var(--white)}
.station-badge i{color:var(--police-gold);font-size:.7rem}
.station-badge .status-dot{width:6px;height:6px;border-radius:50%;
  background:var(--green);display:inline-block;animation:blink 1.3s step-end infinite}

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

.profile-header{display:flex;align-items:center;gap:1.5rem;margin-bottom:1.5rem;
  background:var(--bg3);border:1px solid var(--border);border-radius:14px;
  padding:1.5rem;box-shadow:0 4px 20px rgba(0,0,0,.3)}
.profile-avatar{width:80px;height:80px;border-radius:50%;flex-shrink:0;
  background:linear-gradient(135deg,var(--police-gold),var(--police-gold2));
  display:flex;align-items:center;justify-content:center;font-size:2rem;
  font-weight:700;color:var(--bg2);font-family:'Sora',sans-serif;
  box-shadow:0 4px 20px rgba(232,197,71,0.3)}
.profile-info h2{font-family:'Sora',sans-serif;font-size:1.3rem;font-weight:700;color:var(--white)}
.profile-info .role{font-size:.7rem;color:var(--muted2);background:var(--bg4);
  padding:.15rem .8rem;border-radius:999px;display:inline-block;margin-top:.2rem}
.profile-info .station{font-size:.75rem;color:var(--muted2);margin-top:.2rem}
.profile-info .station i{color:var(--police-gold);width:18px}

.panel{background:var(--bg3);border:1px solid var(--border);border-radius:14px;
  overflow:hidden;box-shadow:0 4px 20px rgba(0,0,0,.2);margin-bottom:1rem}
.ph{display:flex;align-items:center;justify-content:space-between;
  padding:.7rem 1.15rem;border-bottom:1px solid var(--border)}
.ph-t{font-family:'Sora',sans-serif;font-size:.95rem;font-weight:700;color:var(--white)}
.ph-t i{margin-right:.5rem;color:var(--police-gold)}
.ph-body{padding:1.2rem 1.15rem}

.form-group{margin-bottom:.8rem}
.form-group label{display:block;font-size:.7rem;font-weight:600;color:var(--muted);margin-bottom:.2rem}
.form-group label .required{color:var(--red);margin-left:2px}
.form-group input,.form-group select,.form-group textarea{width:100%;padding:.5rem .7rem;
  border:1px solid var(--border);border-radius:8px;font-size:.75rem;
  background:var(--bg4);color:var(--light);font-family:'Space Grotesk',sans-serif;
  outline:none;transition:border-color .2s}
.form-group input:focus,.form-group select:focus,.form-group textarea:focus{border-color:var(--police-gold)}
.form-group input::placeholder{color:var(--muted)}
.form-group textarea{resize:vertical;min-height:60px}
.form-row{display:grid;grid-template-columns:1fr 1fr;gap:.8rem}
.form-hint{font-size:.55rem;color:var(--muted);margin-top:2px}

.btn{display:inline-flex;align-items:center;gap:.5rem;padding:.5rem 1.2rem;
  border-radius:8px;font-size:.75rem;font-weight:600;border:none;
  cursor:pointer;transition:all .15s;font-family:'Space Grotesk',sans-serif;
  text-decoration:none}
.btn-primary{background:var(--police-gold);color:var(--bg2)}
.btn-primary:hover{background:var(--police-gold2);transform:scale(1.02)}
.btn-success{background:var(--green);color:#222}
.btn-success:hover{background:#22c55e;transform:scale(1.02)}
.btn-danger{background:var(--red);color:white}
.btn-danger:hover{background:#dc2626;transform:scale(1.02)}
.btn-outline{background:transparent;border:1px solid var(--border);color:var(--light)}
.btn-outline:hover{background:var(--gold-lo2)}
.btn-block{width:100%;justify-content:center;padding:.6rem}

.toast-wrap{position:fixed;top:1rem;right:1.4rem;z-index:9999;display:flex;flex-direction:column;gap:.4rem}
.toast{display:flex;align-items:flex-start;gap:.6rem;background:var(--bg3);border:1px solid var(--police-gold);
  border-left:3px solid var(--police-gold);border-radius:11px;padding:.6rem .8rem;min-width:240px;max-width:300px;
  box-shadow:0 8px 28px rgba(0,0,0,.45);animation:tIn .3s ease}
.toast.success{border-left-color:var(--green)}
.toast.error{border-left-color:var(--red)}
@keyframes tIn{from{opacity:0;transform:translateX(26px)}to{opacity:1;transform:none}}
.t-ic{font-size:.85rem;flex-shrink:0}
.t-body{flex:1}
.t-title{font-weight:700;font-size:.72rem;color:var(--police-gold)}
.t-msg{font-size:.65rem;color:var(--muted2)}
.t-x{background:none;border:none;color:var(--muted);cursor:pointer;font-size:.7rem}

.grid-bg{position:fixed;inset:0;pointer-events:none;z-index:0;
  background-image:linear-gradient(rgba(232,197,71,.01) 1px,transparent 1px),
    linear-gradient(90deg,rgba(232,197,71,.01) 1px,transparent 1px);
  background-size:42px 42px}
.footer{flex-shrink:0;border-top:1px solid var(--border);
  padding:.5rem 1.4rem;display:flex;align-items:center;justify-content:space-between;
  font-size:.6rem;color:var(--muted);
  font-family:'JetBrains Mono',monospace;background:var(--bg2)}
.footer .dual-status-badge{background:rgba(232,197,71,.12);border:1px solid rgba(232,197,71,.22);
  border-radius:999px;padding:.1rem .5rem;font-size:.5rem;color:var(--police-gold);font-weight:700}

@media(max-width:760px){
  :root{--sw:0px}.sidebar{display:none}.content{padding:.8rem}
  .profile-header{flex-direction:column;text-align:center}
  .form-row{grid-template-columns:1fr}
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
      <?php if($active_count > 0): ?>
        <span class="sb-badge grn"><?= $active_count ?></span>
      <?php endif; ?>
    </a>

    <div class="sb-sect" style="margin-top:.4rem">Dispatch</div>
    
    <a href="dispatch.php" class="sb-item <?= is_active('dispatch.php') ?>">
      <span class="sb-ic"><i class="fas fa-bullhorn"></i></span> Dispatch Center
      <?php if($pending_count > 0): ?>
        <span class="sb-badge red"><?= $pending_count ?></span>
      <?php endif; ?>
    </a>

    <div class="sb-sect" style="margin-top:.4rem">Reports</div>
    
    <a href="reports.php" class="sb-item <?= is_active('reports.php') ?>">
      <span class="sb-ic"><i class="fas fa-file-alt"></i></span> Reports
    </a>
    
    <a href="resolved_cases.php" class="sb-item <?= is_active('resolved_cases.php') ?>">
      <span class="sb-ic"><i class="fas fa-check-circle"></i></span> Resolved Cases
      <?php if($resolved_count > 0): ?>
        <span class="sb-badge green"><?= $resolved_count ?></span>
      <?php endif; ?>
    </a>

    <div class="sb-sect" style="margin-top:.4rem">Account</div>
    
    <a href="profile.php" class="sb-item active">
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
    <div class="tb-title"><span>🚔</span> My Profile</div>

    <div class="tb-search">
      <i class="fas fa-search"></i>
      <input type="text" placeholder="Search…" disabled style="opacity:0.5;"/>
    </div>

    <div class="tb-acts">
      <div class="live-pill"><span class="live-dot"></span>Live</div>

      <div class="station-badge">
        <i class="fas fa-user-shield"></i>
        <span class="station-name"><?= htmlspecialchars($police_station) ?></span>
        <span class="status-dot"></span>
      </div>

      <div class="notif-wrap">
        <button class="notif-btn" onclick="toggleNotif()" id="notificationBell">
          <i class="fas fa-bell"></i>
          <span class="notif-count" id="notifCount" style="<?= $pending_count > 0 ? '' : 'display:none;' ?>">
            <?= $pending_count ?>
          </span>
        </button>
        <div class="notif-drop" id="notifDrop">
          <div class="notif-head">
            <div><div class="nh-title">🚨 Pending Accidents</div>
              <div class="nh-sub">Awaiting dispatch</div></div>
            <span style="font-size:.58rem;color:var(--muted);font-family:'JetBrains Mono',monospace">
              <?= $pending_count ?> pending
            </span>
          </div>
          <div class="notif-list">
            <?php 
            $pending_notif = $conn->query("
                SELECT id, accident_type, location_description, created_at, reporter_name, injuries 
                FROM accidents 
                WHERE assigned_police_id = $police_station_id AND police_status = 'assigned'
                ORDER BY created_at DESC LIMIT 10
            ");
            if($pending_notif && $pending_notif->num_rows > 0):
              while($al = $pending_notif->fetch_assoc()): ?>
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

  <!-- ═══ CONTENT ═══ -->
  <div class="content">

    <!-- Profile Header -->
    <div class="profile-header">
      <div class="profile-avatar"><?= $police_init ?></div>
      <div class="profile-info">
        <h2><?= htmlspecialchars($user['full_name'] ?? 'Police Officer') ?></h2>
        <div class="role"><i class="fas fa-user-shield"></i> <?= ucfirst($admin_role) ?></div>
        <div class="station"><i class="fas fa-map-pin"></i> <?= htmlspecialchars($police_station) ?></div>
        <div class="station" style="margin-top:2px;"><i class="fas fa-envelope"></i> <?= htmlspecialchars($user['email'] ?? 'N/A') ?></div>
        <div class="station"><i class="fas fa-phone"></i> <?= htmlspecialchars($user['phone'] ?? 'N/A') ?></div>
      </div>
    </div>

    <!-- Update Profile Form -->
    <div class="panel">
      <div class="ph"><div class="ph-t"><i class="fas fa-user-edit"></i>Edit Profile</div></div>
      <div class="ph-body">
        <form method="POST">
          <div class="form-row">
            <div class="form-group">
              <label>Full Name <span class="required">*</span></label>
              <input type="text" name="full_name" value="<?= htmlspecialchars($user['full_name'] ?? '') ?>" required>
            </div>
            <div class="form-group">
              <label>Phone Number <span class="required">*</span></label>
              <input type="text" name="phone" value="<?= htmlspecialchars($user['phone'] ?? '') ?>" required>
            </div>
          </div>
          <div class="form-group">
            <label>Email Address <span class="required">*</span></label>
            <input type="email" name="email" value="<?= htmlspecialchars($user['email'] ?? '') ?>" required>
          </div>

          <hr style="border:none;border-top:1px solid var(--border);margin:1rem 0;">

          <div class="form-row">
            <div class="form-group">
              <label>Police Station Name <span class="required">*</span></label>
              <input type="text" name="station_name" value="<?= htmlspecialchars($police_station) ?>" required>
            </div>
            <div class="form-group">
              <label>Station Phone <span class="required">*</span></label>
              <input type="text" name="station_phone" value="<?= htmlspecialchars($police_station_phone) ?>" required>
            </div>
          </div>
          <div class="form-group">
            <label>Station Email</label>
            <input type="email" name="station_email" value="<?= htmlspecialchars($police_station_email) ?>">
          </div>
          <div class="form-group">
            <label>Location/Address</label>
            <input type="text" name="station_location" value="<?= htmlspecialchars($police_station_location) ?>" placeholder="e.g., Dar es Salaam, Tanzania">
          </div>
          <div class="form-row">
            <div class="form-group">
              <label>Latitude</label>
              <input type="number" step="any" name="station_lat" value="<?= htmlspecialchars($police_station_lat) ?>" placeholder="-6.7924">
            </div>
            <div class="form-group">
              <label>Longitude</label>
              <input type="number" step="any" name="station_lng" value="<?= htmlspecialchars($police_station_lng) ?>" placeholder="39.2083">
            </div>
          </div>
          <div class="form-hint"><i class="fas fa-info-circle"></i> Latitude and Longitude are used for map navigation. Leave empty if not applicable.</div>

          <button type="submit" name="update_profile" class="btn btn-primary btn-block" style="margin-top:.8rem;">
            <i class="fas fa-save"></i> Update Profile
          </button>
        </form>
      </div>
    </div>

    <!-- Change Password -->
    <div class="panel">
      <div class="ph"><div class="ph-t"><i class="fas fa-key"></i>Change Password</div></div>
      <div class="ph-body">
        <form method="POST">
          <div class="form-group">
            <label>Current Password <span class="required">*</span></label>
            <input type="password" name="current_password" placeholder="Enter current password" required>
          </div>
          <div class="form-row">
            <div class="form-group">
              <label>New Password <span class="required">*</span></label>
              <input type="password" name="new_password" placeholder="Min 6 characters" required minlength="6">
            </div>
            <div class="form-group">
              <label>Confirm New Password <span class="required">*</span></label>
              <input type="password" name="confirm_password" placeholder="Confirm new password" required minlength="6">
            </div>
          </div>
          <div class="form-hint"><i class="fas fa-info-circle"></i> Password must be at least 6 characters long.</div>

          <button type="submit" name="change_password" class="btn btn-success btn-block" style="margin-top:.8rem;">
            <i class="fas fa-key"></i> Change Password
          </button>
        </form>
      </div>
    </div>

  </div><!-- /content -->

  <div class="footer">
    <span>🚔 <?= htmlspecialchars($police_station) ?> &nbsp;·&nbsp;</span>
    <span class="dual-status-badge"><i class="fas fa-shield-alt"></i> Update Profile</span>
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
  const icon=type==='ok'||type==='success'?'✅':type==='err'||type==='error'?'⚠️':'ℹ️';
  const el=document.createElement('div');
  el.className='toast '+(type==='success'||type==='ok'?'success':type==='error'||type==='err'?'error':'');
  el.innerHTML=`<div class="t-ic">${icon}</div>
    <div class="t-body"><div class="t-title">${title}</div><div class="t-msg">${msg}</div></div>
    <button class="t-x" onclick="this.closest('.toast').remove()">✕</button>`;
  w.appendChild(el);
  setTimeout(()=>{ if(el.parentNode) el.remove(); },5000);
}

// ── TOAST FROM URL ──
<?php if(isset($_GET['msg']) && $_GET['msg'] === 'updated'): ?>
showToast('✅ Success', 'Profile updated successfully!', 'success');
<?php elseif(isset($_GET['msg']) && $_GET['msg'] === 'password_changed'): ?>
showToast('✅ Success', 'Password changed successfully!', 'success');
<?php endif; ?>

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