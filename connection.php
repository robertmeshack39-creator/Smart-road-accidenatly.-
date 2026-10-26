<?php
/**
 * SRARS - Smart Road Accident Reporting System
 * Database Connection & Global Functions
 */

// ============================================
// 🔌 DATABASE CONNECTION
// ============================================
$host = 'localhost';
$user = 'root';
$password = '';
$database = 'Smart_Road_Reporting_System';

$conn = mysqli_connect($host, $user, $password, $database);

if (!$conn) {
    die("Database connection failed: " . mysqli_connect_error());
}

// ─── Set charset to UTF-8 ───
mysqli_set_charset($conn, "utf8mb4");

// ============================================
// 📝 AUDIT LOG FUNCTION
// ============================================
function createAuditLog($conn, $action, $page = null) {
    
    // ─── Get user info from session ───
    $user_id = isset($_SESSION['user_id']) ? intval($_SESSION['user_id']) : null;
    $username = isset($_SESSION['name']) ? $_SESSION['name'] : (isset($_SESSION['full_name']) ? $_SESSION['full_name'] : 'System');
    $role = isset($_SESSION['role']) ? $_SESSION['role'] : 'Guest';
    
    // ─── Get page name ───
    if ($page === null) {
        $page = basename($_SERVER['PHP_SELF']);
    }
    
    // ─── Get IP and User Agent ───
    $ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '0.0.0.0';
    $user_agent = isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : 'Unknown';
    
    // ─── Check if table exists, if not create it ───
    $table_check = mysqli_query($conn, "SHOW TABLES LIKE 'audit_logs'");
    if (mysqli_num_rows($table_check) == 0) {
        mysqli_query($conn, "
            CREATE TABLE IF NOT EXISTS audit_logs (
                id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
                user_id INT(11) NULL,
                username VARCHAR(100),
                role VARCHAR(50),
                action VARCHAR(255),
                page VARCHAR(100),
                ip_address VARCHAR(50),
                user_agent TEXT,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_user_id (user_id),
                INDEX idx_created_at (created_at),
                INDEX idx_action (action),
                INDEX idx_role (role)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    }
    
    // ─── Insert into database ───
    $stmt = mysqli_prepare($conn, 
        "INSERT INTO audit_logs 
        (user_id, username, role, action, page, ip_address, user_agent)
        VALUES (?, ?, ?, ?, ?, ?, ?)"
    );
    
    mysqli_stmt_bind_param($stmt, "issssss", 
        $user_id,
        $username,
        $role,
        $action,
        $page,
        $ip,
        $user_agent
    );
    
    return mysqli_stmt_execute($stmt);
}

// ============================================
// 🔴 AUTO-ASSIGN TO NEAREST RESPONDERS
// ============================================
/**
 * Auto-assign accident to nearest police and hospital stations
 * 
 * @param mysqli $conn Database connection
 * @param int $accident_id Accident ID
 * @param float $latitude Accident latitude
 * @param float $longitude Accident longitude
 * @param int|null $manual_police_id Optional manual police assignment
 * @param int|null $manual_hospital_id Optional manual hospital assignment
 * @return array ['assigned' => bool, 'police' => string|null, 'hospital' => string|null, 'log' => string]
 */
function autoAssignResponders($conn, $accident_id, $latitude, $longitude, $manual_police_id = null, $manual_hospital_id = null) {
    
    $updates = [];
    $log_details = 'Assigned case | ACC-' . str_pad($accident_id, 4, '0', STR_PAD_LEFT);
    $assigned_police = null;
    $assigned_hospital = null;
    $is_manual = false;
    
    // ─── Check if manual assignment is provided ───
    if ($manual_police_id > 0 || $manual_hospital_id > 0) {
        $is_manual = true;
        
        if ($manual_police_id > 0) {
            $updates[] = "assigned_police_id = $manual_police_id";
            $updates[] = "police_status = 'assigned'";
            $police = mysqli_query($conn, "SELECT name FROM police_stations WHERE id = $manual_police_id");
            if ($police && mysqli_num_rows($police) > 0) {
                $police_data = mysqli_fetch_assoc($police);
                $assigned_police = $police_data['name'];
                $log_details .= ' | Police: ' . $police_data['name'];
            }
        }
        
        if ($manual_hospital_id > 0) {
            $updates[] = "assigned_hospital_id = $manual_hospital_id";
            $updates[] = "hospital_status = 'assigned'";
            $hospital = mysqli_query($conn, "SELECT name FROM hospital_stations WHERE id = $manual_hospital_id");
            if ($hospital && mysqli_num_rows($hospital) > 0) {
                $hospital_data = mysqli_fetch_assoc($hospital);
                $assigned_hospital = $hospital_data['name'];
                $log_details .= ' | Hospital: ' . $hospital_data['name'];
            }
        }
        
    } else {
        // ─── AUTO-ASSIGN: Find Nearest Police Station ───
        $nearest_police = mysqli_query($conn, "
            SELECT id, name, 
                   (6371 * acos(cos(radians($latitude)) * cos(radians(latitude)) * 
                   cos(radians(longitude) - radians($longitude)) + 
                   sin(radians($latitude)) * sin(radians(latitude)))) AS distance
            FROM police_stations 
            WHERE is_active = 1
            ORDER BY distance ASC 
            LIMIT 1
        ");
        
        // ─── Find Nearest Hospital ───
        $nearest_hospital = mysqli_query($conn, "
            SELECT id, name, 
                   (6371 * acos(cos(radians($latitude)) * cos(radians(latitude)) * 
                   cos(radians(longitude) - radians($longitude)) + 
                   sin(radians($latitude)) * sin(radians(latitude)))) AS distance
            FROM hospital_stations 
            WHERE is_active = 1
            ORDER BY distance ASC 
            LIMIT 1
        ");
        
        $log_details = 'Auto-assigned to nearest responders | ACC-' . str_pad($accident_id, 4, '0', STR_PAD_LEFT);
        
        if ($nearest_police && mysqli_num_rows($nearest_police) > 0) {
            $police = mysqli_fetch_assoc($nearest_police);
            $updates[] = "assigned_police_id = " . $police['id'];
            $updates[] = "police_status = 'assigned'";
            $assigned_police = $police['name'];
            $log_details .= ' | Police: ' . $police['name'] . ' (' . round($police['distance'], 2) . ' km)';
        }
        
        if ($nearest_hospital && mysqli_num_rows($nearest_hospital) > 0) {
            $hospital = mysqli_fetch_assoc($nearest_hospital);
            $updates[] = "assigned_hospital_id = " . $hospital['id'];
            $updates[] = "hospital_status = 'assigned'";
            $assigned_hospital = $hospital['name'];
            $log_details .= ' | Hospital: ' . $hospital['name'] . ' (' . round($hospital['distance'], 2) . ' km)';
        }
    }
    
    $assigned = false;
    
    if (!empty($updates)) {
        $sql = "UPDATE accidents SET " . implode(', ', $updates) . " WHERE id = $accident_id";
        $assigned = mysqli_query($conn, $sql);
        
        if ($assigned) {
            // ─── Create Audit Log ───
            createAuditLog($conn, $log_details);
        }
    }
    
    return [
        'assigned' => $assigned,
        'police' => $assigned_police,
        'hospital' => $assigned_hospital,
        'is_manual' => $is_manual,
        'log' => $log_details
    ];
}

// ============================================
// 🎯 SHORTCUT AUDIT FUNCTIONS
// ============================================

function auditLogin($conn) {
    return createAuditLog($conn, 'User logged in successfully');
}

function auditLogout($conn) {
    return createAuditLog($conn, 'User logged out');
}

function auditAccidentCreate($conn, $id, $type = null, $injuries = null) {
    $details = "Created accident report | ACC-" . str_pad($id, 4, '0', STR_PAD_LEFT);
    if ($type) $details .= " | Type: $type";
    if ($injuries) $details .= " | Injuries: $injuries";
    return createAuditLog($conn, $details);
}

function auditAccidentUpdate($conn, $id, $status, $type) {
    $details = "Updated $type status to $status | ACC-" . str_pad($id, 4, '0', STR_PAD_LEFT);
    return createAuditLog($conn, $details);
}

function auditAccidentAssign($conn, $id, $to, $name) {
    $details = "Assigned to $to | ACC-" . str_pad($id, 4, '0', STR_PAD_LEFT) . " | $to: $name";
    return createAuditLog($conn, $details);
}

function auditAccidentAutoAssign($conn, $id) {
    $details = "Auto-assigned to nearest responders | ACC-" . str_pad($id, 4, '0', STR_PAD_LEFT);
    return createAuditLog($conn, $details);
}

function auditAccidentDelete($conn, $id, $type = null) {
    $details = "Deleted accident report | ACC-" . str_pad($id, 4, '0', STR_PAD_LEFT);
    if ($type) $details .= " | Type: $type";
    return createAuditLog($conn, $details);
}

function auditUserApprove($conn, $user_id, $name, $role) {
    $details = "Approved user account | User: $name ($role)";
    return createAuditLog($conn, $details);
}

function auditUserReject($conn, $user_id, $name, $role) {
    $details = "Rejected user account | User: $name ($role)";
    return createAuditLog($conn, $details);
}

function auditUserDelete($conn, $user_id, $name) {
    $details = "Deleted user account | User: $name | ID: $user_id";
    return createAuditLog($conn, $details);
}

function auditStationCreate($conn, $name, $type, $location) {
    $details = "Created $type station | $type: $name | Location: $location";
    return createAuditLog($conn, $details);
}

function auditStationUpdate($conn, $name, $type, $location) {
    $details = "Updated $type station | $type: $name | Location: $location";
    return createAuditLog($conn, $details);
}

function auditStationDelete($conn, $id, $name, $type) {
    $details = "Deleted $type station | $type: $name | ID: $id";
    return createAuditLog($conn, $details);
}

function auditProfileUpdate($conn) {
    return createAuditLog($conn, 'Updated profile information');
}

function auditPasswordChange($conn) {
    return createAuditLog($conn, 'Changed password');
}

// ============================================
// 🛡️ SECURITY FUNCTIONS
// ============================================

function isLoggedIn() {
    return isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
}

function isAdmin() {
    return isset($_SESSION['role']) && strtolower($_SESSION['role']) === 'admin';
}

function isPolice() {
    return isset($_SESSION['role']) && strtolower($_SESSION['role']) === 'police';
}

function isHospital() {
    return isset($_SESSION['role']) && strtolower($_SESSION['role']) === 'hospital';
}

function isCitizen() {
    return isset($_SESSION['role']) && strtolower($_SESSION['role']) === 'citizen';
}

function requireLogin() {
    if (!isLoggedIn()) {
        header("Location: login.php");
        exit();
    }
}

function requireAdmin() {
    requireLogin();
    if (!isAdmin()) {
        header("Location: dashboard.php");
        exit();
    }
}

// ============================================
// 📊 HELPER FUNCTIONS
// ============================================

function sanitize($input) {
    global $conn;
    return mysqli_real_escape_string($conn, trim($input));
}

function generateAccidentId($id) {
    return 'ACC-' . str_pad($id, 4, '0', STR_PAD_LEFT);
}

function formatDate($date) {
    return date('d M Y, H:i', strtotime($date));
}

function timeAgo($datetime) {
    $time = strtotime($datetime);
    $diff = time() - $time;
    
    if ($diff < 60) return $diff . 's ago';
    if ($diff < 3600) return floor($diff / 60) . 'm ago';
    if ($diff < 86400) return floor($diff / 3600) . 'h ago';
    if ($diff < 604800) return floor($diff / 86400) . 'd ago';
    return date('d M Y', $time);
}

function getInitials($name) {
    $words = explode(' ', $name);
    $initials = '';
    foreach ($words as $word) {
        if (!empty($word)) {
            $initials .= strtoupper($word[0]);
        }
    }
    return substr($initials, 0, 2);
}

// ============================================
// 🔒 PASSWORD FUNCTIONS
// ============================================

function hashPassword($password) {
    return password_hash($password, PASSWORD_DEFAULT);
}

function verifyPassword($password, $hash) {
    return password_verify($password, $hash);
}