<?php
// police/incident_details.php - View incident details
session_start();
include __DIR__ . '/../connection.php';

// Check if user is logged in and is police
if (empty($_SESSION['user_id']) || strtolower($_SESSION['role'] ?? '') !== 'police') {
    header("Location: ../login.php");
    exit();
}

$incident_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($incident_id <= 0) {
    header("Location: dashboard.php");
    exit();
}

$police_id = $_SESSION['user_id'];

// Get police station ID
$user_query = $conn->query("
    SELECT police_station_id 
    FROM users 
    WHERE id = $police_id
");
$user_data = $user_query->fetch_assoc();
$police_station_id = $user_data['police_station_id'] ?? null;

// Get incident details
$incident_query = $conn->query("
    SELECT 
        a.*,
        ps.name as police_station_name,
        hs.name as hospital_station_name
    FROM accidents a
    LEFT JOIN police_stations ps ON a.assigned_police_id = ps.id
    LEFT JOIN hospital_stations hs ON a.assigned_hospital_id = hs.id
    WHERE a.id = $incident_id 
    AND a.assigned_police_id = $police_station_id
");

if (!$incident_query || $incident_query->num_rows == 0) {
    header("Location: dashboard.php");
    exit();
}

$incident = $incident_query->fetch_assoc();
$police_name = htmlspecialchars($_SESSION['name'] ?? 'Police Officer');
$police_station = $user_data['station_name'] ?? 'Police Station';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width,initial-scale=1.0"/>
<title>Incident Details — #ACC-<?= str_pad($incident['id'],4,'0',STR_PAD_LEFT) ?></title>
<link href="https://fonts.googleapis.com/css2?family=Sora:wght@300;400;500;600;700;800&family=Space+Grotesk:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet"/>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"/>
<style>
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:'Space Grotesk',sans-serif;background:#0A0E17;color:#D6E8F5;min-height:100vh;padding:2rem}
.container{max-width:900px;margin:0 auto}
.back-link{color:#E8C547;text-decoration:none;font-size:.85rem;margin-bottom:1.5rem;display:inline-block}
.back-link:hover{text-decoration:underline}
.card{background:#1A237E;border:1px solid rgba(255,255,255,.08);border-radius:14px;padding:1.5rem;margin-bottom:1.5rem}
.card-title{font-family:'Sora',sans-serif;font-size:1.1rem;font-weight:700;color:#E8C547;margin-bottom:.5rem}
.card-title i{margin-right:.5rem}
.grid{display:grid;grid-template-columns:1fr 1fr;gap:.8rem}
.field{padding:.5rem .7rem;background:rgba(255,255,255,.04);border-radius:8px}
.field .label{font-size:.6rem;color:#7A9BB5;text-transform:uppercase;letter-spacing:.05em}
.field .value{font-size:.85rem;font-weight:500;color:#D6E8F5;margin-top:2px}
.field .value.badge{display:inline-block;padding:.15rem .7rem;border-radius:999px;font-size:.6rem;font-weight:700;font-family:'JetBrains Mono',monospace}
.field .value.badge.assigned{background:rgba(251,146,60,.15);color:#FB923C;border:1px solid rgba(251,146,60,.2)}
.field .value.badge.dispatched{background:rgba(34,211,238,.15);color:#22D3EE;border:1px solid rgba(34,211,238,.2)}
.field .value.badge.arrived{background:rgba(34,211,238,.15);color:#22D3EE;border:1px solid rgba(34,211,238,.2)}
.field .value.badge.resolved{background:rgba(74,222,128,.15);color:#4ADE80;border:1px solid rgba(74,222,128,.2)}
.field .value.badge.false_alarm{background:rgba(148,163,184,.15);color:#94A3B8;border:1px solid rgba(148,163,184,.2)}
.full{grid-column:1/-1}
.actions{display:flex;gap:.5rem;flex-wrap:wrap;margin-top:1rem}
.btn{padding:.5rem 1.2rem;border-radius:8px;font-size:.7rem;font-weight:700;border:none;cursor:pointer;text-decoration:none;font-family:'Space Grotesk',sans-serif;transition:all .15s}
.btn-primary{background:#E8C547;color:#0A0E17}
.btn-primary:hover{background:#D4AF37;transform:scale(1.02)}
.btn-success{background:#4ADE80;color:#0A0E17}
.btn-success:hover{background:#22c55e;transform:scale(1.02)}
.btn-danger{background:#F43F5E;color:#fff}
.btn-danger:hover{background:#DC2626;transform:scale(1.02)}
.btn-outline{background:transparent;border:1px solid rgba(255,255,255,.15);color:#D6E8F5}
.btn-outline:hover{background:rgba(255,255,255,.05)}
img{max-width:100%;border-radius:8px;max-height:300px}
.map-container{height:300px;border-radius:8px;overflow:hidden;background:#0A1825;margin-top:.5rem}
</style>
</head>
<body>
<div class="container">
    <a href="dashboard.php" class="back-link"><i class="fas fa-arrow-left"></i> Back to Dashboard</a>
    
    <h1 style="font-family:'Sora',sans-serif;font-size:1.5rem;margin-bottom:1rem;">
        <i class="fas fa-car-crash" style="color:#E8C547"></i> 
        Incident #ACC-<?= str_pad($incident['id'],4,'0',STR_PAD_LEFT) ?>
    </h1>
    
    <!-- Incident Details -->
    <div class="card">
        <div class="card-title"><i class="fas fa-info-circle"></i> Incident Information</div>
        <div class="grid">
            <div class="field"><div class="label">Type</div><div class="value"><?= htmlspecialchars($incident['accident_type'] ?? 'Unknown') ?></div></div>
            <div class="field"><div class="label">Status</div>
                <div class="value"><span class="badge <?= $incident['police_status'] ?>"><?= ucfirst($incident['police_status'] ?? 'Unknown') ?></span></div>
            </div>
            <div class="field"><div class="label">Injuries</div><div class="value"><?= intval($incident['injuries']) ?> person(s)</div></div>
            <div class="field"><div class="label">Reported</div><div class="value"><?= date('d M Y, H:i', strtotime($incident['created_at'])) ?></div></div>
            <div class="field full"><div class="label">Location</div><div class="value"><?= htmlspecialchars($incident['location_description'] ?? 'Unknown') ?></div></div>
            <?php if($incident['latitude'] && $incident['longitude']): ?>
            <div class="field"><div class="label">Latitude</div><div class="value"><?= $incident['latitude'] ?></div></div>
            <div class="field"><div class="label">Longitude</div><div class="value"><?= $incident['longitude'] ?></div></div>
            <?php endif; ?>
            <div class="field full"><div class="label">Hazards</div><div class="value"><?= htmlspecialchars($incident['hazards'] ?? 'None reported') ?></div></div>
            <?php if(!empty($incident['description'])): ?>
            <div class="field full"><div class="label">Description</div><div class="value"><?= nl2br(htmlspecialchars($incident['description'])) ?></div></div>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Reporter Details -->
    <div class="card">
        <div class="card-title"><i class="fas fa-user"></i> Reporter Information</div>
        <div class="grid">
            <div class="field"><div class="label">Name</div><div class="value"><?= htmlspecialchars($incident['reporter_name'] ?? 'Anonymous') ?></div></div>
            <div class="field"><div class="label">Phone</div><div class="value"><?= htmlspecialchars($incident['reporter_phone'] ?? 'N/A') ?></div></div>
            <div class="field"><div class="label">Email</div><div class="value"><?= htmlspecialchars($incident['reporter_email'] ?? 'N/A') ?></div></div>
        </div>
    </div>
    
    <!-- Response Details -->
    <div class="card">
        <div class="card-title"><i class="fas fa-shield-alt"></i> Response Information</div>
        <div class="grid">
            <div class="field"><div class="label">Police Station</div><div class="value"><?= htmlspecialchars($incident['police_station_name'] ?? 'Not assigned') ?></div></div>
            <div class="field"><div class="label">Hospital</div><div class="value"><?= htmlspecialchars($incident['hospital_station_name'] ?? 'Not assigned') ?></div></div>
            <div class="field"><div class="label">Hospital Status</div><div class="value"><?= ucfirst($incident['hospital_status'] ?? 'Not assigned') ?></div></div>
        </div>
    </div>
    
    <!-- Map -->
    <?php if($incident['latitude'] && $incident['longitude']): ?>
    <div class="card">
        <div class="card-title"><i class="fas fa-map"></i> Location Map</div>
        <div class="map-container" id="map"></div>
    </div>
    <?php endif; ?>
    
    <!-- Actions -->
    <div class="card">
        <div class="card-title"><i class="fas fa-tasks"></i> Actions</div>
        <div class="actions">
            <a href="dashboard.php" class="btn btn-outline"><i class="fas fa-arrow-left"></i> Back</a>
            <?php if($incident['police_status'] == 'assigned'): ?>
            <button class="btn btn-primary" onclick="dispatchIncident(<?= $incident['id'] ?>)"><i class="fas fa-bullhorn"></i> Dispatch</button>
            <?php endif; ?>
            <?php if(in_array($incident['police_status'], ['dispatched', 'arrived'])): ?>
            <button class="btn btn-success" onclick="resolveIncident(<?= $incident['id'] ?>)"><i class="fas fa-check"></i> Resolve</button>
            <?php endif; ?>
            <?php if($incident['latitude'] && $incident['longitude']): ?>
            <a href="https://www.google.com/maps/dir/<?= $incident['latitude'] ?>,<?= $incident['longitude'] ?>" target="_blank" class="btn btn-primary">
                <i class="fas fa-directions"></i> Navigate
            </a>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php if($incident['latitude'] && $incident['longitude']): ?>
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"/>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script>
    const map = L.map('map').setView([<?= $incident['latitude'] ?>, <?= $incident['longitude'] ?>], 15);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '&copy; OpenStreetMap'
    }).addTo(map);
    
    const marker = L.marker([<?= $incident['latitude'] ?>, <?= $incident['longitude'] ?>]).addTo(map);
    marker.bindPopup(`
        <strong>#ACC-<?= str_pad($incident['id'],4,'0',STR_PAD_LEFT) ?></strong><br>
        <?= addslashes(htmlspecialchars($incident['accident_type'] ?? 'Unknown')) ?>
    `).openPopup();
</script>
<?php endif; ?>

<script>
function dispatchIncident(id) {
    if(!confirm('Dispatch police to this incident?')) return;
    fetch('dispatch_incident.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'id=' + id
    })
    .then(r => r.json())
    .then(d => {
        if(d.success) {
            alert('✅ Police dispatched successfully!');
            location.reload();
        } else {
            alert('❌ ' + (d.message || d.error || 'Failed to dispatch'));
        }
    })
    .catch(() => alert('❌ Network error'));
}

function resolveIncident(id) {
    if(!confirm('Mark this incident as resolved?')) return;
    fetch('resolve_incident.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'id=' + id
    })
    .then(r => r.json())
    .then(d => {
        if(d.success) {
            alert('✅ Incident resolved successfully!');
            location.reload();
        } else {
            alert('❌ ' + (d.message || d.error || 'Failed to resolve'));
        }
    })
    .catch(() => alert('❌ Network error'));
}
</script>

</body>
</html>