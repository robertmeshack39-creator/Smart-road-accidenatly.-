<?php
// admin/users.php - User Management with Station Assignment.
session_start();
include __DIR__ . '/../connection.php';

// Check if user is logged in and is admin
if (empty($_SESSION['user_id']) || empty($_SESSION['role']) || strtolower($_SESSION['role']) !== 'admin') {
    header("Location: ../login.php");
    exit();
}

// Helper function for active page
function is_active($page) {
    return basename($_SERVER['PHP_SELF']) === $page ? 'active' : '';
}

// ==================== HANDLE ACTIONS ====================
$message = '';
$message_type = '';

// --- Approve User ---
if (isset($_GET['approve'])) {
    $id = intval($_GET['approve']);
    
    // Get user details before approving
    $user = $conn->query("SELECT full_name, role FROM users WHERE id = $id")->fetch_assoc();
    
    $sql = "UPDATE users SET approval_status = 'approved' WHERE id = $id";
    if ($conn->query($sql)) {
        // 🔴 AUDIT LOG - APPROVE USER
        createAuditLog($conn, 'Approved user account | User: ' . $user['full_name'] . ' (' . $user['role'] . ')');
        
        $message = 'User approved successfully!';
        $message_type = 'success';
    } else {
        $message = 'Error: ' . $conn->error;
        $message_type = 'error';
    }
}

// --- Reject User ---
if (isset($_GET['reject'])) {
    $id = intval($_GET['reject']);
    
    // Get user details before rejecting
    $user = $conn->query("SELECT full_name, role FROM users WHERE id = $id")->fetch_assoc();
    
    $sql = "UPDATE users SET approval_status = 'rejected' WHERE id = $id";
    if ($conn->query($sql)) {
        // 🔴 AUDIT LOG - REJECT USER
        createAuditLog($conn, 'Rejected user account | User: ' . $user['full_name'] . ' (' . $user['role'] . ')');
        
        $message = 'User rejected!';
        $message_type = 'success';
    } else {
        $message = 'Error: ' . $conn->error;
        $message_type = 'error';
    }
}

// --- Add New User with Station Assignment ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_user'])) {
    $full_name = mysqli_real_escape_string($conn, $_POST['full_name']);
    $email = mysqli_real_escape_string($conn, $_POST['email']);
    $password = $_POST['password'];
    $role = mysqli_real_escape_string($conn, $_POST['role']);
    $phone = mysqli_real_escape_string($conn, $_POST['phone'] ?? '');
    
    // Get station IDs from form
    $police_station_id = isset($_POST['police_station_id']) && $_POST['police_station_id'] > 0 
        ? intval($_POST['police_station_id']) 
        : 'NULL';
    $hospital_station_id = isset($_POST['hospital_station_id']) && $_POST['hospital_station_id'] > 0 
        ? intval($_POST['hospital_station_id']) 
        : 'NULL';
    
    // Password strength check
    if (!preg_match('/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d).{8,}$/', $password)) {
        $message = "Password must be at least 8 characters with uppercase, lowercase and number!";
        $message_type = "error";
    } else {
        $hashed = password_hash($password, PASSWORD_DEFAULT);
        
        $check = $conn->query("SELECT id FROM users WHERE email = '$email'");
        if ($check && $check->num_rows > 0) {
            $message = 'User with this email already exists!';
            $message_type = 'error';
        } else {
            $sql = "INSERT INTO users (full_name, email, password, role, phone, police_station_id, hospital_station_id, approval_status, created_at) 
                    VALUES ('$full_name', '$email', '$hashed', '$role', '$phone', $police_station_id, $hospital_station_id, 'approved', NOW())";
            
            if ($conn->query($sql)) {
                // 🔴 AUDIT LOG - ADD USER
                $new_user_id = $conn->insert_id;
                $station_info = '';
                if ($role == 'police' && $police_station_id > 0) {
                    $station = $conn->query("SELECT name FROM police_stations WHERE id = $police_station_id")->fetch_assoc();
                    $station_info = ' | Police Station: ' . $station['name'];
                } elseif ($role == 'hospital' && $hospital_station_id > 0) {
                    $station = $conn->query("SELECT name FROM hospital_stations WHERE id = $hospital_station_id")->fetch_assoc();
                    $station_info = ' | Hospital: ' . $station['name'];
                }
                createAuditLog($conn, 'Created new user account | User: ' . $full_name . ' (' . $role . ')' . $station_info);
                
                $message = 'User added successfully with station assignment!';
                $message_type = 'success';
                $_POST = array();
            } else {
                $message = 'Error: ' . $conn->error;
                $message_type = 'error';
            }
        }
    }
}

// --- Edit User with Station Assignment ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_user'])) {
    $id = intval($_POST['user_id']);
    $full_name = mysqli_real_escape_string($conn, $_POST['full_name']);
    $email = mysqli_real_escape_string($conn, $_POST['email']);
    $role = mysqli_real_escape_string($conn, $_POST['role']);
    $phone = mysqli_real_escape_string($conn, $_POST['phone'] ?? '');
    $approval_status = mysqli_real_escape_string($conn, $_POST['approval_status'] ?? 'pending');
    
    $police_station_id = isset($_POST['police_station_id']) && $_POST['police_station_id'] > 0 
        ? intval($_POST['police_station_id']) 
        : 'NULL';
    $hospital_station_id = isset($_POST['hospital_station_id']) && $_POST['hospital_station_id'] > 0 
        ? intval($_POST['hospital_station_id']) 
        : 'NULL';
    
    // Get old user data for audit log
    $old_user = $conn->query("SELECT full_name, role FROM users WHERE id = $id")->fetch_assoc();
    
    $sql = "UPDATE users SET 
            full_name = '$full_name', 
            email = '$email', 
            role = '$role', 
            phone = '$phone', 
            police_station_id = $police_station_id,
            hospital_station_id = $hospital_station_id,
            approval_status = '$approval_status' 
            WHERE id = $id";
    
    if ($conn->query($sql)) {
        // 🔴 AUDIT LOG - EDIT USER
        $station_info = '';
        if ($role == 'police' && $police_station_id > 0) {
            $station = $conn->query("SELECT name FROM police_stations WHERE id = $police_station_id")->fetch_assoc();
            $station_info = ' | Police Station: ' . $station['name'];
        } elseif ($role == 'hospital' && $hospital_station_id > 0) {
            $station = $conn->query("SELECT name FROM hospital_stations WHERE id = $hospital_station_id")->fetch_assoc();
            $station_info = ' | Hospital: ' . $station['name'];
        }
        createAuditLog($conn, 'Updated user account | User: ' . $full_name . ' (' . $role . ')' . $station_info . ' | Status: ' . $approval_status);
        
        $message = 'User updated successfully!';
        $message_type = 'success';
    } else {
        $message = 'Error: ' . $conn->error;
        $message_type = 'error';
    }
}

// --- Reset Password ---
if (isset($_GET['reset_password'])) {
    $id = intval($_GET['reset_password']);
    
    // Get user details
    $user = $conn->query("SELECT full_name, role FROM users WHERE id = $id")->fetch_assoc();
    
    $new_password = password_hash('password123', PASSWORD_DEFAULT);
    
    $sql = "UPDATE users SET password = '$new_password' WHERE id = $id";
    if ($conn->query($sql)) {
        // 🔴 AUDIT LOG - RESET PASSWORD
        createAuditLog($conn, 'Reset password for user | User: ' . $user['full_name'] . ' (' . $user['role'] . ')');
        
        $message = 'Password reset to default: password123';
        $message_type = 'success';
    } else {
        $message = 'Error: ' . $conn->error;
        $message_type = 'error';
    }
}

// --- Delete User ---
if (isset($_GET['delete'])) {
    $id = intval($_GET['delete']);
    
    if ($id == $_SESSION['user_id']) {
        $message = 'You cannot delete your own account!';
        $message_type = 'error';
    } else {
        // Get user details before deleting
        $user = $conn->query("SELECT full_name, role FROM users WHERE id = $id")->fetch_assoc();
        
        $sql = "DELETE FROM users WHERE id = $id";
        if ($conn->query($sql)) {
            // 🔴 AUDIT LOG - DELETE USER
            createAuditLog($conn, 'Deleted user account | User: ' . $user['full_name'] . ' (' . $user['role'] . ')');
            
            $message = 'User deleted successfully!';
            $message_type = 'success';
        } else {
            $message = 'Error: ' . $conn->error;
            $message_type = 'error';
        }
    }
}

// --- Get all users with station info ---
$users = $conn->query("
    SELECT 
        u.*,
        ps.name as police_station_name,
        ps.location as police_station_location,
        hs.name as hospital_station_name,
        hs.location as hospital_station_location
    FROM users u
    LEFT JOIN police_stations ps ON u.police_station_id = ps.id
    LEFT JOIN hospital_stations hs ON u.hospital_station_id = hs.id
    ORDER BY u.created_at DESC
");
$total_users = $users ? $users->num_rows : 0;

// --- Get statistics ---
$column_check = $conn->query("SHOW COLUMNS FROM users LIKE 'approval_status'");
$has_approval = $column_check && $column_check->num_rows > 0;

// Check if station columns exist
$police_col_check = $conn->query("SHOW COLUMNS FROM users LIKE 'police_station_id'");
$has_police_station = $police_col_check && $police_col_check->num_rows > 0;
$hospital_col_check = $conn->query("SHOW COLUMNS FROM users LIKE 'hospital_station_id'");
$has_hospital_station = $hospital_col_check && $hospital_col_check->num_rows > 0;

$stats = [
    'total' => $conn->query("SELECT COUNT(*) as count FROM users")->fetch_assoc()['count'],
    'admin' => $conn->query("SELECT COUNT(*) as count FROM users WHERE role = 'admin'")->fetch_assoc()['count'],
    'police' => $conn->query("SELECT COUNT(*) as count FROM users WHERE role = 'police'")->fetch_assoc()['count'],
    'hospital' => $conn->query("SELECT COUNT(*) as count FROM users WHERE role = 'hospital'")->fetch_assoc()['count'],
    'citizen' => $conn->query("SELECT COUNT(*) as count FROM users WHERE role = 'citizen'")->fetch_assoc()['count'],
    'pending' => $has_approval ? $conn->query("SELECT COUNT(*) as count FROM users WHERE approval_status = 'pending'")->fetch_assoc()['count'] : 0,
];

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

// Admin info
$admin_name = htmlspecialchars($_SESSION['name'] ?? 'System Admin');
$admin_email = htmlspecialchars($_SESSION['email'] ?? 'admin@roadalert.tz');
$admin_role = htmlspecialchars($_SESSION['role'] ?? 'admin');
$admin_init = strtoupper(substr($admin_name, 0, 1));

// Fetch police and hospital stations for dropdowns
$police_stations = $conn->query("SELECT id, name, location FROM police_stations WHERE is_active = 1 ORDER BY name");
$hospital_stations = $conn->query("SELECT id, name, location FROM hospital_stations WHERE is_active = 1 ORDER BY name");
?>



<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width,initial-scale=1.0"/>
<title>User Management — RoadAlert TZ</title>
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

/* ══════════════════════ LAYOUT ══════════════════════ */
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
  font-family:'Space Grotesk',sans-serif;text-decoration:none;
}
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

/* ── PROFILE BUTTON ── */
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
.stats-row{display:grid;grid-template-columns:repeat(6,1fr);gap:1rem;margin-bottom:1.1rem}
.sc{background:var(--bg3);border:1px solid var(--border);border-radius:14px;
  padding:1.1rem 1.25rem;position:relative;overflow:hidden;
  transition:border-color .22s,transform .18s}
.sc:hover{border-color:var(--bord2);transform:translateY(-2px)}
.sc::after{content:'';position:absolute;top:0;left:0;right:0;height:2px;
  opacity:0;transition:opacity .25s}
.sc:hover::after{opacity:1}
.sc.c1::after{background:linear-gradient(90deg,var(--sky),transparent)}
.sc.c2::after{background:linear-gradient(90deg,var(--red),transparent)}
.sc.c3::after{background:linear-gradient(90deg,var(--amber),transparent)}
.sc.c4::after{background:linear-gradient(90deg,var(--accent),transparent)}
.sc.c5::after{background:linear-gradient(90deg,var(--purple),transparent)}
.sc.c6::after{background:linear-gradient(90deg,var(--yellow),transparent)}
.sc-ico{width:36px;height:36px;border-radius:8px;
  display:flex;align-items:center;justify-content:center;
  font-size:.88rem;margin-bottom:.85rem}
.c1 .sc-ico{background:rgba(34,211,238,.1);color:var(--sky)}
.c2 .sc-ico{background:rgba(244,63,94,.1);color:var(--red)}
.c3 .sc-ico{background:rgba(251,146,60,.1);color:var(--amber)}
.c4 .sc-ico{background:var(--acc-lo);color:var(--accent)}
.c5 .sc-ico{background:rgba(167,139,250,.1);color:var(--purple)}
.c6 .sc-ico{background:rgba(250,204,21,.1);color:var(--yellow)}
.sc-num{font-family:'Sora',sans-serif;font-size:2rem;
  font-weight:800;line-height:1;margin-bottom:.22rem}
.c1 .sc-num{color:var(--sky)}
.c2 .sc-num{color:var(--red)}
.c3 .sc-num{color:var(--amber)}
.c4 .sc-num{color:var(--accent)}
.c5 .sc-num{color:var(--purple)}
.c6 .sc-num{color:var(--yellow)}
.sc-lbl{font-size:.62rem;color:var(--muted);letter-spacing:.09em;text-transform:uppercase}

/* ── TABLE ── */
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
.td-name{font-weight:500;display:flex;align-items:center;gap:.4rem}
.td-name .self-badge{font-size:.55rem;background:var(--accent);color:#07111B;
  padding:.08rem .45rem;border-radius:999px;font-weight:700}
.role-badge{display:inline-flex;align-items:center;gap:.26rem;
  padding:.18rem .6rem;border-radius:999px;
  font-family:'JetBrains Mono',monospace;font-size:.58rem;
  font-weight:600;text-transform:uppercase;white-space:nowrap}
.role-badge.admin{background:rgba(244,63,94,.1);color:var(--red);
  border:1px solid rgba(244,63,94,.2)}
.role-badge.police{background:rgba(34,211,238,.1);color:var(--sky);
  border:1px solid rgba(34,211,238,.2)}
.role-badge.hospital{background:rgba(251,146,60,.1);color:var(--amber);
  border:1px solid rgba(251,146,60,.2)}
.role-badge.citizen{background:rgba(167,139,250,.1);color:var(--purple);
  border:1px solid rgba(167,139,250,.2)}

.approval-badge{display:inline-flex;align-items:center;gap:.26rem;
  padding:.15rem .5rem;border-radius:999px;
  font-family:'JetBrains Mono',monospace;font-size:.55rem;
  font-weight:600;text-transform:uppercase;white-space:nowrap}
.approval-badge.pending{background:rgba(251,146,60,.1);color:var(--amber);
  border:1px solid rgba(251,146,60,.2)}
.approval-badge.approved{background:rgba(0,229,160,.1);color:var(--accent);
  border:1px solid rgba(0,229,160,.2)}
.approval-badge.rejected{background:rgba(244,63,94,.1);color:var(--red);
  border:1px solid rgba(244,63,94,.2)}

.station-badge{display:inline-flex;align-items:center;gap:4px;
  font-size:.58rem;padding:2px 8px;border-radius:999px;font-weight:500}
.station-badge.police{background:rgba(34,211,238,.1);color:var(--sky);
  border:1px solid rgba(34,211,238,.2)}
.station-badge.hospital{background:rgba(251,146,60,.1);color:var(--amber);
  border:1px solid rgba(251,146,60,.2)}
.station-badge.none{background:rgba(255,255,255,.05);color:var(--muted);
  border:1px solid var(--border)}

.btn-approve{background:rgba(0,229,160,.1);border:1px solid rgba(0,229,160,.2);
  color:var(--accent);padding:.12rem .5rem;border-radius:4px;
  font-size:.55rem;font-weight:600;text-decoration:none;
  transition:all .2s;display:inline-block;font-family:'JetBrains Mono',monospace}
.btn-approve:hover{background:var(--accent);color:#07111B}
.btn-reject{background:rgba(244,63,94,.1);border:1px solid rgba(244,63,94,.2);
  color:var(--red);padding:.12rem .5rem;border-radius:4px;
  font-size:.55rem;font-weight:600;text-decoration:none;
  transition:all .2s;display:inline-block;font-family:'JetBrains Mono',monospace}
.btn-reject:hover{background:var(--red);color:#fff}

.action-btn{background:none;border:none;color:var(--muted2);
  cursor:pointer;padding:.2rem .4rem;border-radius:4px;
  transition:all .18s;font-size:.75rem;text-decoration:none}
.action-btn:hover{background:var(--acc-lo);color:var(--accent)}
.action-btn.danger:hover{background:rgba(244,63,94,.1);color:var(--red)}
.action-btn.warning:hover{background:rgba(251,146,60,.1);color:var(--amber)}

/* ── MODAL (Add User & Edit User) ── */
.modal-bg{position:fixed;inset:0;background:rgba(0,0,0,.75);
  backdrop-filter:blur(6px);z-index:6000;
  display:flex;align-items:center;justify-content:center;
  opacity:0;pointer-events:none;transition:opacity .22s}
.modal-bg.show{opacity:1;pointer-events:all}
.modal{background:var(--bg3);border:1px solid var(--bord2);
  border-radius:18px;padding:1.8rem;width:100%;max-width:540px;
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
.modal-title{font-family:'Sora',sans-serif;font-size:1.4rem;font-weight:800;
  color:var(--white);margin-bottom:.25rem}
.modal-title i{margin-right:8px;color:var(--accent)}
.modal-sub{font-size:.75rem;color:var(--muted);margin-bottom:1.3rem}
.mf{margin-bottom:1rem}
.mf label{display:block;font-size:.64rem;letter-spacing:.1em;
  text-transform:uppercase;color:var(--muted);margin-bottom:.4rem;font-weight:500}
.mf label i{margin-right:4px;color:var(--accent)}
.mf input,.mf select{width:100%;background:var(--bg4);border:1px solid rgba(255,255,255,.08);
  border-radius:9px;padding:.68rem .85rem;
  font-family:'Space Grotesk',sans-serif;font-size:.83rem;color:var(--white);
  outline:none;transition:border-color .18s,box-shadow .18s}
.mf input:focus,.mf select:focus{border-color:var(--accent);box-shadow:0 0 0 3px rgba(0,229,160,.12)}
.mf input::placeholder{color:rgba(255,255,255,.16)}
.mf select option{background:var(--bg4);color:var(--white)}
.modal-acts{display:flex;gap:.65rem;margin-top:1.4rem}
.btn-save{flex:1;padding:.72rem;background:var(--accent);border:none;border-radius:9px;
  font-family:'Sora',sans-serif;font-size:.95rem;font-weight:700;
  color:#07111B;cursor:pointer;box-shadow:0 0 14px rgba(0,229,160,.3);
  transition:transform .15s,box-shadow .18s}
.btn-save:hover{transform:translateY(-2px);box-shadow:0 4px 20px rgba(0,229,160,.5)}
.btn-cancel{padding:.72rem 1.1rem;background:rgba(255,255,255,.05);
  border:1px solid var(--border);border-radius:9px;
  font-size:.82rem;color:var(--muted2);cursor:pointer;transition:background .18s}
.btn-cancel:hover{background:rgba(255,255,255,.08)}

/* 🔴 Station select group */
.station-select-group{display:none;margin-bottom:1rem}
.station-select-group.show{display:block}
.station-select-group label{display:block;font-size:.64rem;letter-spacing:.1em;
  text-transform:uppercase;color:var(--muted);margin-bottom:.4rem;font-weight:500}
.station-select-group label i{margin-right:4px;color:var(--accent)}
.station-select-group select{width:100%;background:var(--bg4);border:1px solid rgba(255,255,255,.08);
  border-radius:9px;padding:.68rem .85rem;font-family:'Space Grotesk',sans-serif;
  font-size:.83rem;color:var(--white);outline:none;transition:border-color .18s,box-shadow .18s}
.station-select-group select:focus{border-color:var(--accent);box-shadow:0 0 0 3px rgba(0,229,160,.12)}
.station-select-group select option{background:var(--bg4);color:var(--white)}
.station-select-group small{color:var(--muted);font-size:.6rem;display:block;margin-top:4px}

/* 🔴 Password strength */
.strength-meter{margin-top:6px;height:3px;background:var(--bg4);border-radius:3px;overflow:hidden}
.strength-bar{height:100%;width:0%;transition:width .3s ease,background-color .3s ease;border-radius:3px}
.strength-text{font-size:10px;color:var(--muted);margin-top:3px}
.weak{background-color:#F43F5E}
.medium{background-color:#FB923C}
.strong{background-color:#00E5A0}
.weak-text{color:#F43F5E}
.medium-text{color:#FB923C}
.strong-text{color:#00E5A0}

.grid-bg{position:fixed;inset:0;pointer-events:none;z-index:0;
  background-image:
    linear-gradient(rgba(0,229,160,.012) 1px,transparent 1px),
    linear-gradient(90deg,rgba(0,229,160,.012) 1px,transparent 1px);
  background-size:42px 42px}

.footer{flex-shrink:0;border-top:1px solid var(--border);
  padding:.6rem 1.4rem;display:flex;align-items:center;justify-content:space-between;
  font-size:.66rem;color:var(--muted);
  font-family:'JetBrains Mono',monospace;background:var(--bg2)}

@media(max-width:1100px){
  .stats-row{grid-template-columns:repeat(3,1fr)}
}
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
    <?php if(($police_resolved ?? 0) > 0 || ($hospital_treated ?? 0) > 0): ?>
        <span class="sb-badge grn"><?= ($police_resolved ?? 0) + ($hospital_treated ?? 0) ?></span>
    <?php endif; ?>
</a>

    <div class="sb-sect" style="margin-top:.4rem">Management</div>
    <a href="manage_police.php" class="sb-item <?= is_active('manage_police.php') ?>">
      <span class="sb-ic"><i class="fas fa-user-shield"></i></span> Police Stations
    </a>
    <a href="manage_hospital.php" class="sb-item <?= is_active('manage_hospital.php') ?>">
      <span class="sb-ic"><i class="fas fa-hospital"></i></span> Hospitals
    </a>
    <a href="users.php" class="sb-item active">
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
    <div class="tb-title"><i class="fas fa-users" style="color:var(--accent);margin-right:8px"></i>User Management</div>

    <div class="tb-search">
      <i class="fas fa-search"></i>
      <input type="text" placeholder="Search users, email…" oninput="filterTable(this.value)"/>
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

    <!-- STATS CARDS -->
    <div class="stats-row">
      <div class="sc c1">
        <div class="sc-ico"><i class="fas fa-users"></i></div>
        <div class="sc-num"><?= number_format($stats['total']) ?></div>
        <div class="sc-lbl">Total Users</div>
      </div>
      <div class="sc c2">
        <div class="sc-ico"><i class="fas fa-shield-alt"></i></div>
        <div class="sc-num" style="color:var(--red)"><?= number_format($stats['admin']) ?></div>
        <div class="sc-lbl">Admins</div>
      </div>
      <div class="sc c3">
        <div class="sc-ico"><i class="fas fa-user-shield"></i></div>
        <div class="sc-num" style="color:var(--sky)"><?= number_format($stats['police']) ?></div>
        <div class="sc-lbl">Police</div>
      </div>
      <div class="sc c4">
        <div class="sc-ico"><i class="fas fa-hospital"></i></div>
        <div class="sc-num" style="color:var(--amber)"><?= number_format($stats['hospital']) ?></div>
        <div class="sc-lbl">Hospital</div>
      </div>
      <div class="sc c6">
        <div class="sc-ico"><i class="fas fa-clock"></i></div>
        <div class="sc-num" style="color:var(--yellow)"><?= number_format($stats['pending']) ?></div>
        <div class="sc-lbl">Pending Approval</div>
      </div>
    </div>

    <!-- USERS TABLE -->
    <div class="panel">
      <div class="ph">
        <div>
          <div class="ph-t"><i class="fas fa-list"></i>All Registered Users</div>
          <div class="ph-s"><?= $total_users ?> users total</div>
        </div>
        <div style="display:flex;gap:.6rem;align-items:center">
          <button onclick="openAddModal()" class="tb-btn primary" style="display:flex;align-items:center;gap:.4rem;border:none;">
            <i class="fas fa-plus"></i> Add User
          </button>
        </div>
      </div>

      <div class="tbl-wrap">
        <table>
          <thead>
            <tr>
              <th>#ID</th>
              <th>Full Name</th>
              <th>Email</th>
              <th>Phone</th>
              <th>Role</th>
              <th>Station</th>
              <th>Approval</th>
              <th>Joined</th>
              <th style="text-align:right">Actions</th>
            </tr>
          </thead>
          <tbody id="tableBody">
          <?php if($users && $users->num_rows > 0):
            while($user = $users->fetch_assoc()):
              $is_self = ($user['id'] == $_SESSION['user_id']);
              $approval_status = isset($user['approval_status']) ? $user['approval_status'] : 'approved';
              
              // Get station display
              $station_display = '';
              if($user['role'] == 'police' && !empty($user['police_station_name'])) {
                  $station_display = '<span class="station-badge police">🚔 ' . htmlspecialchars($user['police_station_name']) . '</span>';
              } elseif($user['role'] == 'hospital' && !empty($user['hospital_station_name'])) {
                  $station_display = '<span class="station-badge hospital">🏥 ' . htmlspecialchars($user['hospital_station_name']) . '</span>';
              } else {
                  $station_display = '<span class="station-badge none">—</span>';
              }
          ?>
            <tr id="row-<?= $user['id'] ?>">
              <td class="td-id">#<?= $user['id'] ?></td>
              <td>
                <span class="td-name">
                  <i class="fas fa-user-circle" style="color:var(--muted2)"></i>
                  <?= htmlspecialchars($user['full_name'] ?? 'Unknown') ?>
                  <?php if($is_self): ?>
                    <span class="self-badge">YOU</span>
                  <?php endif; ?>
                </span>
              </td>
              <td style="color:var(--muted2)"><?= htmlspecialchars($user['email'] ?? '—') ?></td>
              <td style="color:var(--muted2)"><?= htmlspecialchars($user['phone'] ?? '—') ?></td>
              <td>
                <span class="role-badge <?= $user['role'] ?>">
                  <?= ucfirst($user['role']) ?>
                </span>
              </td>
              <td>
                <?= $station_display ?>
              </td>
              <td>
                <span class="approval-badge <?= $approval_status ?>">
                  <?= ucfirst($approval_status) ?>
                </span>
                <?php if($approval_status == 'pending' && !$is_self && $has_approval): ?>
                  <div style="margin-top:3px;display:flex;gap:3px">
                    <a href="?approve=<?= $user['id'] ?>" class="btn-approve">✓ Approve</a>
                    <a href="?reject=<?= $user['id'] ?>" class="btn-reject">✕ Reject</a>
                  </div>
                <?php endif; ?>
              </td>
              <td style="color:var(--muted);font-size:.65rem">
                <?= date('d M Y', strtotime($user['created_at'])) ?>
              </td>
              <td style="text-align:right">
                <div style="display:flex;gap:2px;justify-content:flex-end">
                  <button onclick="editUser(<?= $user['id'] ?>)" class="action-btn" title="Edit">
                    <i class="fas fa-edit"></i>
                  </button>
                  <?php if(!$is_self): ?>
                  <a href="?reset_password=<?= $user['id'] ?>" 
                     onclick="return confirm('Reset password for <?= addslashes($user['full_name'] ?? 'user') ?>?')" 
                     class="action-btn warning" title="Reset Password">
                    <i class="fas fa-key"></i>
                  </a>
                  <a href="?delete=<?= $user['id'] ?>" 
                     onclick="return confirm('Delete user <?= addslashes($user['full_name'] ?? 'user') ?>?')" 
                     class="action-btn danger" title="Delete">
                    <i class="fas fa-trash-alt"></i>
                  </a>
                  <?php else: ?>
                  <span class="action-btn" style="color:var(--muted);cursor:not-allowed" title="Cannot delete yourself">
                    <i class="fas fa-lock"></i>
                  </span>
                  <?php endif; ?>
                </div>
              </td>
            </tr>
          <?php endwhile; else: ?>
            <tr>
              <td colspan="9" style="text-align:center;padding:3rem;color:var(--muted)">
                <i class="fas fa-users" style="font-size:2.5rem;display:block;margin-bottom:.8rem;opacity:.3"></i>
                No users found
              </td>
            </tr>
          <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

  </div><!-- /content -->

  <div class="footer">
    <span>© 2026 RoadAlert TZ &nbsp;·&nbsp; System: Operational &nbsp;·&nbsp; Users Management</span>
    <span id="clock"></span>
  </div>
</div><!-- /main -->
</div><!-- /shell -->

<!-- ═══════════════════ ADD USER MODAL ═══════════════════ -->
<div class="modal-bg" id="addModal">
    <div class="modal">
        <button class="modal-close" onclick="closeModal('addModal')"><i class="fas fa-times"></i></button>
        <div class="modal-title"><i class="fas fa-user-plus"></i>Add New User</div>
        <div class="modal-sub">Create a new user account with station assignment</div>
        <form method="POST" id="addUserForm">
            <div class="mf"><label><i class="fas fa-user"></i> Full Name *</label>
                <input type="text" name="full_name" placeholder="Enter full name" required/></div>
            <div class="mf"><label><i class="fas fa-envelope"></i> Email *</label>
                <input type="email" name="email" placeholder="user@example.com" required/></div>
            <div class="mf"><label><i class="fas fa-phone"></i> Phone</label>
                <input type="tel" name="phone" placeholder="+255 7XX XXX XXX"/></div>
            <div class="mf"><label><i class="fas fa-lock"></i> Password *</label>
                <input type="password" name="password" id="add_password" placeholder="At least 8 characters" required/>
                <div class="strength-meter">
                    <div class="strength-bar" id="addStrengthBar"></div>
                </div>
                <div class="strength-text" id="addStrengthText"></div>
            </div>
            
            <div class="mf">
                <label><i class="fas fa-briefcase"></i> Role *</label>
                <select name="role" id="add_role" onchange="toggleStationField('add')" required>
                    <option value="admin">Admin</option>
                    <option value="police" selected>Police</option>
                    <option value="hospital">Hospital</option>
                </select>
            </div>

            <!-- 🔴 POLICE STATION SELECTION -->
            <div class="station-select-group" id="add_police_div">
                <label><i class="fas fa-user-shield"></i> Assign Police Station *</label>
                <select name="police_station_id" id="add_police_station">
                    <option value="">-- Select Police Station --</option>
                    <?php 
                    $police_stations->data_seek(0);
                    while($station = $police_stations->fetch_assoc()): 
                    ?>
                        <option value="<?= $station['id'] ?>">🚔 <?= htmlspecialchars($station['name']) ?> - <?= htmlspecialchars($station['location']) ?></option>
                    <?php endwhile; ?>
                </select>
                <small><i class="fas fa-info-circle"></i> Select the police station this officer belongs to</small>
            </div>

            <!-- 🔴 HOSPITAL STATION SELECTION -->
            <div class="station-select-group" id="add_hospital_div">
                <label><i class="fas fa-hospital"></i> Assign Hospital *</label>
                <select name="hospital_station_id" id="add_hospital_station">
                    <option value="">-- Select Hospital --</option>
                    <?php 
                    $hospital_stations->data_seek(0);
                    while($station = $hospital_stations->fetch_assoc()): 
                    ?>
                        <option value="<?= $station['id'] ?>">🏥 <?= htmlspecialchars($station['name']) ?> - <?= htmlspecialchars($station['location']) ?></option>
                    <?php endwhile; ?>
                </select>
                <small><i class="fas fa-info-circle"></i> Select the hospital this staff member belongs to</small>
            </div>

            <div class="modal-acts">
                <button type="button" class="btn-cancel" onclick="closeModal('addModal')">Cancel</button>
                <button type="submit" name="add_user" class="btn-save"><i class="fas fa-save"></i> Add User</button>
            </div>
        </form>
    </div>
</div>

<!-- ═══════════════════ EDIT USER MODAL ═══════════════════ -->
<div class="modal-bg" id="editModal">
    <div class="modal">
        <button class="modal-close" onclick="closeModal('editModal')"><i class="fas fa-times"></i></button>
        <div class="modal-title"><i class="fas fa-user-edit"></i>Edit User</div>
        <div class="modal-sub">Update user information and station assignment</div>
        <form method="POST" id="editUserForm">
            <input type="hidden" name="user_id" id="edit_user_id">
            <div class="mf"><label><i class="fas fa-user"></i> Full Name *</label>
                <input type="text" name="full_name" id="edit_full_name" required/></div>
            <div class="mf"><label><i class="fas fa-envelope"></i> Email *</label>
                <input type="email" name="email" id="edit_email" required/></div>
            <div class="mf"><label><i class="fas fa-phone"></i> Phone</label>
                <input type="tel" name="phone" id="edit_phone"/></div>
            
            <div class="mf">
                <label><i class="fas fa-briefcase"></i> Role *</label>
                <select name="role" id="edit_role" onchange="toggleStationField('edit')" required>
                    <option value="admin">Admin</option>
                    <option value="police">Police</option>
                    <option value="hospital">Hospital</option>
                </select>
            </div>

            <!-- 🔴 POLICE STATION SELECTION -->
            <div class="station-select-group" id="edit_police_div">
                <label><i class="fas fa-user-shield"></i> Assign Police Station *</label>
                <select name="police_station_id" id="edit_police_station">
                    <option value="">-- Select Police Station --</option>
                    <?php 
                    $police_stations->data_seek(0);
                    while($station = $police_stations->fetch_assoc()): 
                    ?>
                        <option value="<?= $station['id'] ?>">🚔 <?= htmlspecialchars($station['name']) ?> - <?= htmlspecialchars($station['location']) ?></option>
                    <?php endwhile; ?>
                </select>
                <small><i class="fas fa-info-circle"></i> Select the police station this officer belongs to</small>
            </div>

            <!-- 🔴 HOSPITAL STATION SELECTION -->
            <div class="station-select-group" id="edit_hospital_div">
                <label><i class="fas fa-hospital"></i> Assign Hospital *</label>
                <select name="hospital_station_id" id="edit_hospital_station">
                    <option value="">-- Select Hospital --</option>
                    <?php 
                    $hospital_stations->data_seek(0);
                    while($station = $hospital_stations->fetch_assoc()): 
                    ?>
                        <option value="<?= $station['id'] ?>">🏥 <?= htmlspecialchars($station['name']) ?> - <?= htmlspecialchars($station['location']) ?></option>
                    <?php endwhile; ?>
                </select>
                <small><i class="fas fa-info-circle"></i> Select the hospital this staff member belongs to</small>
            </div>

            <?php if($has_approval): ?>
            <div class="mf"><label><i class="fas fa-check-circle"></i> Approval Status</label>
                <select name="approval_status" id="edit_approval_status" required>
                    <option value="pending">Pending</option>
                    <option value="approved">Approved</option>
                    <option value="rejected">Rejected</option>
                </select>
            </div>
            <?php endif; ?>
            
            <div class="modal-acts">
                <button type="button" class="btn-cancel" onclick="closeModal('editModal')">Cancel</button>
                <button type="submit" name="edit_user" class="btn-save"><i class="fas fa-save"></i> Update User</button>
            </div>
        </form>
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

/* ── PROFILE MODAL ───────────────────────────── */
function openProfileModal(){
    document.getElementById('profileModal').classList.add('show');
    closeProfileDrop();
}
function closeProfileModal(){
    document.getElementById('profileModal').classList.remove('show');
}

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

/* ── MODAL FUNCTIONS ──────────────────────────── */
function openAddModal(){
    document.getElementById('addModal').classList.add('show');
    document.getElementById('addUserForm').reset();
    document.getElementById('addStrengthBar').style.width = '0%';
    document.getElementById('addStrengthText').innerHTML = '';
    toggleStationField('add');
}

function closeModal(id){
    document.getElementById(id).classList.remove('show');
}

/* ── EDIT USER ─────────────────────────────────── */
function editUser(id){
    fetch('get_user.php?id=' + id)
        .then(response => response.json())
        .then(data => {
            if(data.error){ 
                alert('Error: ' + data.error); 
                return; 
            }
            
            document.getElementById('edit_user_id').value = data.id;
            document.getElementById('edit_full_name').value = data.full_name || '';
            document.getElementById('edit_email').value = data.email || '';
            document.getElementById('edit_phone').value = data.phone || '';
            document.getElementById('edit_role').value = data.role || 'citizen';
            
            // Set station values
            document.getElementById('edit_police_station').value = data.police_station_id || '';
            document.getElementById('edit_hospital_station').value = data.hospital_station_id || '';
            
            if(document.getElementById('edit_approval_status')){
                document.getElementById('edit_approval_status').value = data.approval_status || 'pending';
            }
            
            toggleStationField('edit');
            document.getElementById('editModal').classList.add('show');
        })
        .catch(error => {
            console.error('Error:', error);
            alert('Error loading user details. Please try again.');
        });
}

/* ── TOGGLE STATION FIELD ───────────────────────── */
function toggleStationField(type) {
    const prefix = type === 'add' ? 'add' : 'edit';
    const roleSelect = document.getElementById(prefix + '_role');
    const policeDiv = document.getElementById(prefix + '_police_div');
    const hospitalDiv = document.getElementById(prefix + '_hospital_div');
    const policeSelect = document.getElementById(prefix + '_police_station');
    const hospitalSelect = document.getElementById(prefix + '_hospital_station');
    
    const role = roleSelect ? roleSelect.value : 'citizen';
    
    if (role === 'police') {
        policeDiv.classList.add('show');
        hospitalDiv.classList.remove('show');
        if(policeSelect) policeSelect.required = true;
        if(hospitalSelect) hospitalSelect.required = false;
    } else if (role === 'hospital') {
        policeDiv.classList.remove('show');
        hospitalDiv.classList.add('show');
        if(policeSelect) policeSelect.required = false;
        if(hospitalSelect) hospitalSelect.required = true;
    } else {
        policeDiv.classList.remove('show');
        hospitalDiv.classList.remove('show');
        if(policeSelect) policeSelect.required = false;
        if(hospitalSelect) hospitalSelect.required = false;
    }
}

/* ── ADD USER PASSWORD STRENGTH ────────────────── */
const addPassword = document.getElementById('add_password');
const addStrengthBar = document.getElementById('addStrengthBar');
const addStrengthText = document.getElementById('addStrengthText');

function checkAddPasswordStrength(password) {
    let strength = 0;
    if (password.length >= 8) strength++;
    if (password.match(/[a-z]/)) strength++;
    if (password.match(/[A-Z]/)) strength++;
    if (password.match(/[0-9]/)) strength++;
    
    if (strength === 4) return { class: 'strong', text: '✅ Strong', level: 'strong' };
    else if (strength === 3) return { class: 'medium', text: '⚠️ Medium', level: 'medium' };
    else if (strength >= 1) return { class: 'weak', text: '❌ Weak', level: 'weak' };
    else return { class: '', text: '', level: 'none' };
}

addPassword.addEventListener('input', function(){
    const password = this.value;
    if (password.length === 0) {
        addStrengthBar.style.width = '0%';
        addStrengthBar.className = 'strength-bar';
        addStrengthText.innerHTML = '';
        return;
    }
    const result = checkAddPasswordStrength(password);
    addStrengthBar.className = 'strength-bar ' + result.class;
    addStrengthBar.style.width = result.level === 'strong' ? '100%' : result.level === 'medium' ? '66%' : '33%';
    addStrengthText.innerHTML = result.text;
});

/* ── CLOSE ON OUTSIDE CLICK ───────────────────── */
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
        document.querySelectorAll('.modal-bg.show').forEach(m => m.classList.remove('show'));
        closeProfileModal();
    }
});

/* ── SHOW TOAST FROM URL PARAMS ───────────────── */
<?php if($message): ?>
showToast('<?= $message_type === 'success' ? 'Success' : 'Error' ?>', '<?= addslashes($message) ?>', '<?= $message_type ?>');
<?php endif; ?>

function showToast(title,msg,type){
  const w=document.getElementById('toastWrap');
  const icon=type==='success'?'✅':type==='error'?'⚠️':'ℹ️';
  const el=document.createElement('div');el.className='toast';
  el.innerHTML=`<div class="t-ic">${icon}</div>
    <div class="t-body"><div class="t-title">${title}</div><div class="t-msg">${msg}</div></div>
    <button class="t-x" onclick="this.closest('.toast').remove()">✕</button>`;
  w.appendChild(el);setTimeout(()=>el.remove(),5000);
}
</script>

</body>
</html>