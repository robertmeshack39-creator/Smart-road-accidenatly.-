<?php
// hospital/export_report.php - Export Accident Report as PDF (Hospital)
session_start();
include __DIR__ . '/../connection.php';

// Check if user is logged in and is hospital
if (empty($_SESSION['user_id']) || empty($_SESSION['role']) || strtolower($_SESSION['role']) !== 'hospital') {
    die("Unauthorized access.");
}

// Get hospital user details
$hospital_id = $_SESSION['user_id'];
$hospital_name = htmlspecialchars($_SESSION['name'] ?? 'Hospital Staff');

// Get user's hospital station
$user_query = $conn->query("
    SELECT u.*, hs.id as station_id, hs.name as station_name, hs.location
    FROM users u
    LEFT JOIN hospital_stations hs ON u.hospital_station_id = hs.id
    WHERE u.id = $hospital_id
");
$user = $user_query->fetch_assoc();
$hospital_station = $user['station_name'] ?? 'Hospital Station';
$hospital_station_id = $user['station_id'] ?? null;

// ============================================
// GET ACCIDENT ID
// ============================================
$accident_id = 0;
if (isset($_GET['id']) && !empty($_GET['id'])) {
    $accident_id = intval($_GET['id']);
}

$export_all = isset($_GET['all']) && $_GET['all'] === 'true';

// ============================================
// IF EXPORT ALL
// ============================================
if ($export_all) {
    $query = $conn->query("
        SELECT 
            a.*,
            ps.name as police_station_name,
            hs.name as hospital_station_name
        FROM accidents a
        LEFT JOIN police_stations ps ON a.assigned_police_id = ps.id
        LEFT JOIN hospital_stations hs ON a.assigned_hospital_id = hs.id
        WHERE a.assigned_hospital_id = $hospital_station_id
        AND a.hospital_status IN ('closed', 'treated')
        ORDER BY a.created_at DESC
    ");
    $accidents = [];
    while($row = $query->fetch_assoc()) {
        $accidents[] = $row;
    }
    
    if (empty($accidents)) {
        die("No resolved accidents found.");
    }
    
    generateCombinedReport($accidents, $hospital_station, $hospital_name);
    exit();
}

// ============================================
// IF SINGLE ACCIDENT
// ============================================
if ($accident_id <= 0) {
    ?>
    <!DOCTYPE html>
    <html>
    <head><title>Error</title></head>
    <body style="font-family:Arial,sans-serif;padding:40px;background:#f5f5f5;text-align:center;">
        <div style="max-width:500px;margin:0 auto;background:#fff;padding:30px;border-radius:10px;box-shadow:0 2px 10px rgba(0,0,0,0.1);">
            <h2 style="color:#F43F5E;">❌ Invalid Accident ID</h2>
            <p style="color:#666;">Please go back and select a resolved case.</p>
            <a href="resolved_cases.php" style="display:inline-block;margin-top:15px;padding:10px 20px;background:#2563EB;color:#fff;text-decoration:none;border-radius:6px;font-weight:700;">← Back to Resolved Cases</a>
        </div>
    </body>
    </html>
    <?php
    exit();
}

// ============================================
// CHECK IF ACCIDENT EXISTS AND IS RESOLVED
// ============================================
$check = $conn->query("
    SELECT 
        a.*,
        ps.name as police_station_name,
        hs.name as hospital_station_name
    FROM accidents a
    LEFT JOIN police_stations ps ON a.assigned_police_id = ps.id
    LEFT JOIN hospital_stations hs ON a.assigned_hospital_id = hs.id
    WHERE a.id = $accident_id 
    AND a.assigned_hospital_id = $hospital_station_id
    AND a.hospital_status IN ('closed', 'treated')
");

if (!$check || $check->num_rows == 0) {
    ?>
    <!DOCTYPE html>
    <html>
    <head><title>Error</title></head>
    <body style="font-family:Arial,sans-serif;padding:40px;background:#f5f5f5;text-align:center;">
        <div style="max-width:500px;margin:0 auto;background:#fff;padding:30px;border-radius:10px;box-shadow:0 2px 10px rgba(0,0,0,0.1);">
            <h2 style="color:#F43F5E;">❌ Accident Not Found</h2>
            <p style="color:#666;">The accident is not resolved or not assigned to your hospital.</p>
            <a href="resolved_cases.php" style="display:inline-block;margin-top:15px;padding:10px 20px;background:#2563EB;color:#fff;text-decoration:none;border-radius:6px;font-weight:700;">← Back to Resolved Cases</a>
        </div>
    </body>
    </html>
    <?php
    exit();
}

$accident = $check->fetch_assoc();

// ============================================
// GENERATE PDF
// ============================================
generatePDF($accident, $hospital_station, $hospital_name);

// ============================================
// FUNCTIONS
// ============================================

function generatePDF($accident, $hospital_station, $hospital_name) {
    header('Content-Type: text/html; charset=utf-8');
    echo generateHTMLReport($accident, $hospital_station, $hospital_name);
    exit();
}

function generateCombinedReport($accidents, $hospital_station, $hospital_name) {
    header('Content-Type: text/html; charset=utf-8');
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/>
<title>Combined Medical Report - <?= htmlspecialchars($hospital_station) ?></title>
<style>
    *{margin:0;padding:0;box-sizing:border-box}
    body{font-family:Arial,sans-serif;background:#fff;color:#222;padding:40px}
    .report-header{text-align:center;border-bottom:3px double #2563EB;padding-bottom:20px;margin-bottom:25px}
    .report-header h1{font-size:28px;color:#2563EB;text-transform:uppercase;letter-spacing:2px}
    .report-header h2{font-size:18px;color:#0EA5E9;margin-top:5px}
    .report-header .meta{font-size:13px;color:#666;margin-top:8px}
    .summary-box{background:#f5f5f5;border:1px solid #ddd;border-radius:8px;padding:15px;margin-bottom:25px}
    .summary-box h3{color:#2563EB;border-bottom:1px solid #ddd;padding-bottom:8px;margin-bottom:12px}
    .summary-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:10px}
    .summary-item{text-align:center;padding:10px;background:#fff;border-radius:6px;border:1px solid #eee}
    .summary-item .num{font-size:28px;font-weight:700;color:#2563EB}
    .summary-item .label{font-size:11px;color:#666;text-transform:uppercase}
    .case-card{background:#fff;border:1px solid #ddd;border-radius:8px;padding:15px;margin-bottom:15px}
    .case-card .case-header{display:flex;justify-content:space-between;align-items:center;border-bottom:1px solid #eee;padding-bottom:8px;margin-bottom:10px}
    .case-card .case-header .id{font-size:16px;font-weight:700;color:#2563EB}
    .case-card .case-header .status{background:#10B981;color:#fff;padding:2px 12px;border-radius:999px;font-size:12px;font-weight:700}
    .case-grid{display:grid;grid-template-columns:1fr 1fr;gap:8px}
    .case-grid .field{font-size:13px}
    .case-grid .field .lbl{color:#666;font-weight:600}
    .case-grid .field .val{color:#222}
    .case-footer{margin-top:10px;padding-top:8px;border-top:1px solid #eee;font-size:11px;color:#999}
    .report-footer{text-align:center;border-top:2px solid #2563EB;padding-top:15px;margin-top:25px;font-size:12px;color:#666}
    .print-btn{background:#2563EB;color:#fff;border:none;padding:10px 20px;border-radius:6px;cursor:pointer;font-size:14px;margin-bottom:20px}
    .print-btn:hover{background:#1D4ED8}
    @media print{.print-btn{display:none}}
</style>
</head>
<body>
<button class="print-btn" onclick="window.print()"><i class="fa fa-print"></i> Print / Save as PDF</button>

<div class="report-header">
    <h1>🏥 Medical Report - SRARS</h1>
    <h2>Combined Medical Reports</h2>
    <div class="meta">
        <strong><?= htmlspecialchars($hospital_station) ?></strong> &nbsp;·&nbsp;
        Generated: <?= date('d F Y, H:i:s') ?> &nbsp;·&nbsp;
        Total Reports: <?= count($accidents) ?>
    </div>
</div>

<div class="summary-box">
    <h3>📊 Summary Statistics</h3>
    <div class="summary-grid">
        <div class="summary-item"><div class="num"><?= count($accidents) ?></div><div class="label">Total Reports</div></div>
        <?php 
        $total_injuries = array_sum(array_column($accidents, 'injuries'));
        $total_critical = count(array_filter($accidents, function($a) { return $a['injuries'] >= 4; }));
        ?>
        <div class="summary-item"><div class="num"><?= $total_injuries ?></div><div class="label">Total Injuries</div></div>
        <div class="summary-item"><div class="num"><?= $total_critical ?></div><div class="label">Critical Cases</div></div>
        <div class="summary-item"><div class="num"><?= count($accidents) ?></div><div class="label">Resolved Cases</div></div>
    </div>
</div>

<?php foreach($accidents as $acc): ?>
<div class="case-card">
    <div class="case-header">
        <span class="id">🚨 #ACC-<?= str_pad($acc['id'], 4, '0', STR_PAD_LEFT) ?></span>
        <span class="status">✓ <?= strtoupper($acc['hospital_status'] ?? 'RESOLVED') ?></span>
    </div>
    <div class="case-grid">
        <div class="field"><span class="lbl">📝 Type:</span> <span class="val"><?= htmlspecialchars($acc['accident_type'] ?? 'Unknown') ?></span></div>
        <div class="field"><span class="lbl">👤 Reporter:</span> <span class="val"><?= htmlspecialchars($acc['reporter_name'] ?? 'Anonymous') ?></span></div>
        <div class="field"><span class="lbl">📱 Phone:</span> <span class="val"><?= htmlspecialchars($acc['reporter_phone'] ?? 'N/A') ?></span></div>
        <div class="field"><span class="lbl">🤕 Injuries:</span> <span class="val"><?= intval($acc['injuries']) ?> person(s)</span></div>
        <div class="field" style="grid-column:1/-1"><span class="lbl">📍 Location:</span> <span class="val"><?= htmlspecialchars($acc['location_description'] ?? 'Unknown') ?></span></div>
        <div class="field"><span class="lbl">🕐 Reported:</span> <span class="val"><?= date('d F Y, H:i', strtotime($acc['created_at'])) ?></span></div>
        <div class="field"><span class="lbl">🚔 Police:</span> <span class="val"><?= htmlspecialchars($acc['police_station_name'] ?? 'Not assigned') ?></span></div>
    </div>
    <?php if(!empty($acc['description'])): ?>
    <div style="margin-top:8px;padding:8px;background:#f8f9fa;border-radius:4px;font-size:13px;">
        <strong>📝 Description:</strong> <?= htmlspecialchars($acc['description']) ?>
    </div>
    <?php endif; ?>
    <div class="case-footer">✅ Resolved by: <?= htmlspecialchars($hospital_station) ?></div>
</div>
<?php endforeach; ?>

<div class="report-footer">
    <p>This is an official medical report from the Smart Response and Alert System (SRARS)</p>
    <p style="margin-top:3px;">Generated on <?= date('d F Y, H:i:s') ?> | <?= htmlspecialchars($hospital_station) ?></p>
</div>
</body>
</html>
    <?php
    exit();
}

function generateHTMLReport($accident, $hospital_station, $hospital_name) {
    $id = str_pad($accident['id'], 4, '0', STR_PAD_LEFT);
    $images = [];
    if (!empty($accident['images'])) {
        $images = json_decode($accident['images'], true);
        if (!is_array($images)) $images = [];
    }
    if (empty($images) && !empty($accident['photo_path'])) {
        $images = [$accident['photo_path']];
    }
    
    $image_html = '';
    if (!empty($images)) {
        $image_html = '<div style="margin:15px 0;text-align:center;">';
        $image_html .= '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:10px;">';
        foreach(array_slice($images, 0, 4) as $img) {
            $img_path = ltrim($img, '/');
            if (file_exists('../' . $img_path)) {
                $image_html .= '<div style="border:1px solid #ddd;border-radius:6px;overflow:hidden;background:#f5f5f5;">';
                $image_html .= '<img src="../' . htmlspecialchars($img_path) . '" alt="Medical photo" style="width:100%;height:150px;object-fit:cover;">';
                $image_html .= '</div>';
            }
        }
        $image_html .= '</div></div>';
    }
    
    ob_start();
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/>
<title>Medical Report #ACC-<?php echo $id; ?></title>
<style>
    *{margin:0;padding:0;box-sizing:border-box}
    body{font-family:Arial,sans-serif;background:#fff;color:#222;padding:40px}
    .report-container{max-width:900px;margin:0 auto}
    .report-header{text-align:center;border-bottom:3px double #2563EB;padding-bottom:20px;margin-bottom:25px}
    .report-header .logo{font-size:48px;margin-bottom:5px}
    .report-header h1{font-size:28px;color:#2563EB;text-transform:uppercase;letter-spacing:2px}
    .report-header h2{font-size:18px;color:#0EA5E9;margin-top:3px}
    .report-header .badge{display:inline-block;background:#10B981;color:#fff;padding:3px 16px;border-radius:999px;font-size:14px;font-weight:700;margin-top:8px}
    .report-header .meta{font-size:13px;color:#666;margin-top:8px}
    .section{background:#f8f9fa;border:1px solid #e9ecef;border-radius:8px;padding:18px;margin-bottom:15px}
    .section h3{color:#2563EB;border-bottom:2px solid #0EA5E9;padding-bottom:6px;margin-bottom:12px;font-size:16px;text-transform:uppercase;letter-spacing:1px}
    .grid{display:grid;grid-template-columns:1fr 1fr;gap:10px}
    .grid .field{font-size:14px;padding:4px 0;border-bottom:1px dashed #eee}
    .grid .field .lbl{color:#666;font-weight:600;display:inline-block;min-width:100px}
    .grid .field .val{color:#222}
    .full-width{grid-column:1/-1}
    .status-box{padding:12px 16px;border-radius:6px;margin:10px 0;font-weight:700}
    .status-box.resolved{background:#d4edda;color:#155724;border:1px solid #c3e6cb}
    .report-footer{text-align:center;border-top:2px solid #2563EB;padding-top:15px;margin-top:20px;font-size:12px;color:#666}
    .report-footer .signature{margin-top:8px;font-size:14px}
    .print-btn{background:#2563EB;color:#fff;border:none;padding:10px 20px;border-radius:6px;cursor:pointer;font-size:14px;margin-bottom:20px}
    .print-btn:hover{background:#1D4ED8}
    @media print{.print-btn{display:none} body{padding:20px}}
</style>
</head>
<body>
<div class="report-container">
<button class="print-btn" onclick="window.print()"><i class="fa fa-print"></i> Print / Save as PDF</button>

<div class="report-header">
    <div class="logo">🏥</div>
    <h1>Medical Incident Report</h1>
    <h2><?= htmlspecialchars($hospital_station) ?></h2>
    <div class="badge">✓ <?= strtoupper($accident['hospital_status'] ?? 'RESOLVED') ?></div>
    <div class="meta">
        <strong>Report ID:</strong> #ACC-<?php echo $id; ?> &nbsp;·&nbsp;
        <strong>Generated:</strong> <?php echo date('d F Y, H:i:s'); ?>
    </div>
</div>

<!-- Accident Details -->
<div class="section">
    <h3>📋 Accident Details</h3>
    <div class="grid">
        <div class="field"><span class="lbl">Accident Type:</span> <span class="val"><?php echo htmlspecialchars($accident['accident_type'] ?? 'Unknown'); ?></span></div>
        <div class="field"><span class="lbl">Number of Injuries:</span> <span class="val"><?php echo intval($accident['injuries']); ?> person(s)</span></div>
        <div class="field full-width"><span class="lbl">Location:</span> <span class="val"><?php echo htmlspecialchars($accident['location_description'] ?? 'Unknown'); ?></span></div>
        <div class="field"><span class="lbl">Latitude:</span> <span class="val"><?php echo $accident['latitude'] ?? 'N/A'; ?></span></div>
        <div class="field"><span class="lbl">Longitude:</span> <span class="val"><?php echo $accident['longitude'] ?? 'N/A'; ?></span></div>
        <div class="field full-width"><span class="lbl">Hazards:</span> <span class="val"><?php echo htmlspecialchars($accident['hazards'] ?? 'None reported'); ?></span></div>
    </div>
</div>

<!-- Reporter Details -->
<div class="section">
    <h3>👤 Reporter Information</h3>
    <div class="grid">
        <div class="field"><span class="lbl">Full Name:</span> <span class="val"><?php echo htmlspecialchars($accident['reporter_name'] ?? 'Anonymous'); ?></span></div>
        <div class="field"><span class="lbl">Phone:</span> <span class="val"><?php echo htmlspecialchars($accident['reporter_phone'] ?? 'N/A'); ?></span></div>
        <div class="field"><span class="lbl">Email:</span> <span class="val"><?php echo htmlspecialchars($accident['reporter_email'] ?? 'N/A'); ?></span></div>
        <div class="field"><span class="lbl">Reported Time:</span> <span class="val"><?php echo date('d F Y, H:i', strtotime($accident['created_at'])); ?></span></div>
    </div>
</div>

<!-- Description -->
<?php if(!empty($accident['description'])): ?>
<div class="section">
    <h3>📝 Description</h3>
    <p style="font-size:14px;line-height:1.6;color:#333;"><?php echo nl2br(htmlspecialchars($accident['description'])); ?></p>
</div>
<?php endif; ?>

<!-- Photos -->
<?php if(!empty($image_html)): ?>
<div class="section">
    <h3>📸 Scene Photos</h3>
    <?php echo $image_html; ?>
</div>
<?php endif; ?>

<!-- Medical Response -->
<div class="section">
    <h3>🏥 Medical Response Information</h3>
    <div class="grid">
        <div class="field"><span class="lbl">Hospital:</span> <span class="val"><?php echo htmlspecialchars($accident['hospital_station_name'] ?? $hospital_station); ?></span></div>
        <div class="field"><span class="lbl">Hospital Status:</span> <span class="val">✅ <?php echo strtoupper($accident['hospital_status'] ?? 'Resolved'); ?></span></div>
        <div class="field"><span class="lbl">Police Station:</span> <span class="val"><?php echo htmlspecialchars($accident['police_station_name'] ?? 'Not assigned'); ?></span></div>
        <div class="field"><span class="lbl">Police Status:</span> <span class="val"><?php echo htmlspecialchars($accident['police_status'] ?? 'Not assigned'); ?></span></div>
    </div>
</div>

<!-- Status -->
<div class="status-box resolved">
    ✅ This case has been successfully resolved by <?php echo htmlspecialchars($hospital_station); ?>
</div>

<div class="report-footer">
    <p>This is an official medical report from the Smart Response and Alert System (SRARS)</p>
    <p>Generated on <?php echo date('d F Y, H:i:s'); ?></p>
    <div class="signature">
        <strong>Officer:</strong> <?php echo htmlspecialchars($hospital_name); ?> &nbsp;·&nbsp;
        <strong>Hospital:</strong> <?php echo htmlspecialchars($hospital_station); ?>
    </div>
</div>

</div>
</body>
</html>
<?php
    return ob_get_clean();
}
?>