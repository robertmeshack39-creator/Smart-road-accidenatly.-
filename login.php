<?php
/**
 * LOGIN PAGE
 * RoadAlert TZ - User Login with Approval Check & Audit Logging
 */

session_start();
include "connection.php";

$error = "";

if (isset($_POST['login'])) {
    $email = mysqli_real_escape_string($conn, trim($_POST['email']));
    $password = mysqli_real_escape_string($conn, trim($_POST['password']));

    if (empty($email) || empty($password)) {
        $error = "Please fill all fields!";
    } else {
        $sql = "SELECT * FROM users WHERE email='$email' LIMIT 1";
        $result = mysqli_query($conn, $sql);

        if (mysqli_num_rows($result) > 0) {
            $user = mysqli_fetch_assoc($result);
            
            if (password_verify($password, $user['password'])) {
                
                // 🔴 CHECK IF USER IS APPROVED
                $approval_status = isset($user['approval_status']) ? $user['approval_status'] : 'approved';
                
                if ($approval_status === 'pending') {
                    $error = "⚠️ Your account is pending approval. Please wait for admin to activate your account.";
                    
                    // 🔴 LOG FAILED LOGIN ATTEMPT (pending approval)
                    createAuditLog($conn, 'Failed login attempt - Account pending approval | Email: ' . $email);
                    
                } elseif ($approval_status === 'rejected') {
                    $error = "❌ Your account has been rejected. Please contact support for assistance.";
                    
                    // 🔴 LOG FAILED LOGIN ATTEMPT (account rejected)
                    createAuditLog($conn, 'Failed login attempt - Account rejected | Email: ' . $email);
                    
                } else {
                    // ✅ APPROVED - Allow login
                    $_SESSION['user_id'] = $user['id'];
                    $_SESSION['name'] = $user['full_name'];
                    $_SESSION['role'] = $user['role'];
                    $_SESSION['email'] = $user['email'];
                    $_SESSION['phone'] = $user['phone'] ?? '';
                    $_SESSION['approval_status'] = $user['approval_status'] ?? 'approved';

                    // 🔴 LOG SUCCESSFUL LOGIN
                    createAuditLog($conn, 'User logged in successfully | Role: ' . $user['role']);

                    // REDIRECT BASED ON ROLE
                    switch ($user['role']) {
                        case 'admin':
                            header("Location: admin/dashboard.php");
                            break;
                        case 'police':
                            header("Location: police/dashboard.php");
                            break;
                        case 'hospital':
                            header("Location: hospital/dashboard.php");
                            break;
                        default: // citizen
                            header("Location: reporter/dashboard.php");
                            break;
                    }
                    exit();
                }
            } else {
                $error = "Invalid password!";
                
                // 🔴 LOG FAILED LOGIN ATTEMPT (wrong password)
                createAuditLog($conn, 'Failed login attempt - Invalid password | Email: ' . $email);
            }
        } else {
            $error = "User not found!";
            
            // 🔴 LOG FAILED LOGIN ATTEMPT (user not found)
            createAuditLog($conn, 'Failed login attempt - User not found | Email: ' . $email);
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Login - RoadAlert TZ</title>

<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@600;700;800&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">

<style>

*{
    margin:0;
    padding:0;
    box-sizing:border-box;
}

:root{
    --navy:#1e293b;
    --navy-2:#0f172a;
    --teal:#0052cc;
    --teal-dark:#003d99;
    --amber:#ff8c42;
    --danger:#e63946;
    --danger-bg:#fee2e2;
    --ink:#1e293b;
    --slate:#64748b;
    --slate-light:#94a3b8;
    --light:#f8fafc;
    --white:#ffffff;
    --border:#e2e8f0;
}

html, body{
    height:100%;
}

body{
    font-family:'Inter',sans-serif;
    color:var(--ink);
    background:var(--light);
}

h1, h2{
    font-family:'Poppins',sans-serif;
}

/* ==================== SPLIT SCREEN LAYOUT ==================== */
.screen{
    min-height:100vh;
    display:grid;
    grid-template-columns:1.05fr 1fr;
}

/* ---------- LEFT: BRAND PANEL ---------- */
.brand-panel{
    background:
        radial-gradient(circle at 20% 15%, rgba(0,82,204,0.25), transparent 45%),
        linear-gradient(160deg, var(--navy) 0%, var(--navy-2) 100%);
    color:white;
    padding:3.5rem 3rem;
    display:flex;
    flex-direction:column;
    justify-content:space-between;
    position:relative;
    overflow:hidden;
}

.brand-panel::after{
    content:'';
    position:absolute;
    inset:0;
    background-image:
        linear-gradient(rgba(255,255,255,0.035) 1px, transparent 1px),
        linear-gradient(90deg, rgba(255,255,255,0.035) 1px, transparent 1px);
    background-size:36px 36px;
    pointer-events:none;
}

.brand-top{
    position:relative;
    z-index:1;
}

.brand-logo{
    display:flex;
    align-items:center;
    gap:12px;
    font-family:'Poppins',sans-serif;
    font-weight:700;
    font-size:1.25rem;
    margin-bottom:3.5rem;
}

.brand-logo-icon{
    width:38px;
    height:38px;
    border-radius:10px;
    background:linear-gradient(135deg,var(--danger),var(--amber));
    display:flex;
    align-items:center;
    justify-content:center;
    font-size:1rem;
}

.brand-headline{
    font-size:2.4rem;
    font-weight:800;
    line-height:1.25;
    margin-bottom:1rem;
}

.brand-headline span{
    color:var(--teal);
}

.brand-sub{
    color:var(--slate-light);
    font-size:1rem;
    max-width:380px;
    line-height:1.7;
}

/* Signature: live GPS pulse marker */
.gps-signature{
    position:relative;
    z-index:1;
    margin:2.8rem 0;
    height:150px;
    display:flex;
    align-items:center;
    justify-content:center;
}

.gps-pin-wrap{
    position:relative;
    width:64px;
    height:64px;
}

.gps-ring{
    position:absolute;
    inset:0;
    border-radius:50%;
    border:2px solid var(--teal);
    opacity:0;
    animation:gpsPing 2.4s ease-out infinite;
}

.gps-ring.d2{ animation-delay:0.8s; }
.gps-ring.d3{ animation-delay:1.6s; }

@keyframes gpsPing{
    0%{ transform:scale(0.4); opacity:0.9; }
    100%{ transform:scale(2.6); opacity:0; }
}

.gps-pin{
    position:absolute;
    inset:0;
    margin:auto;
    width:44px;
    height:44px;
    border-radius:50%;
    background:linear-gradient(135deg,var(--teal),var(--teal-dark));
    display:flex;
    align-items:center;
    justify-content:center;
    font-size:1.25rem;
    box-shadow:0 0 0 6px rgba(0,82,204,0.15);
}

.gps-caption{
    position:relative;
    z-index:1;
    text-align:center;
    font-size:0.8rem;
    color:var(--slate-light);
    letter-spacing:0.4px;
    margin-top:-1.6rem;
}

.gps-caption b{
    color:var(--teal);
}

/* Quick responder strip */
.brand-bottom{
    position:relative;
    z-index:1;
    display:flex;
    gap:1.6rem;
    flex-wrap:wrap;
    border-top:1px solid rgba(255,255,255,0.12);
    padding-top:1.6rem;
}

.brand-bottom-item{
    display:flex;
    align-items:center;
    gap:10px;
    font-size:0.85rem;
    color:var(--slate-light);
    font-weight:500;
}

.brand-bottom-item i{
    color:var(--teal);
}

/* ---------- RIGHT: FORM PANEL ---------- */
.form-panel{
    background:var(--white);
    display:flex;
    align-items:center;
    justify-content:center;
    padding:3rem 2rem;
}

.form-wrap{
    width:100%;
    max-width:400px;
    animation:slideUp 0.5s ease;
}

@keyframes slideUp{
    from{ opacity:0; transform:translateY(24px); }
    to{ opacity:1; transform:translateY(0); }
}

.form-eyebrow{
    display:inline-flex;
    align-items:center;
    gap:8px;
    color:var(--teal-dark);
    background:rgba(0,82,204,0.1);
    font-size:0.78rem;
    font-weight:700;
    letter-spacing:0.4px;
    padding:0.35rem 0.8rem;
    border-radius:999px;
    margin-bottom:1.1rem;
}

.form-wrap h1{
    font-size:1.9rem;
    font-weight:700;
    color:var(--ink);
    margin-bottom:0.4rem;
}

.form-wrap > p.form-lead{
    color:var(--slate);
    font-size:0.95rem;
    margin-bottom:2rem;
}

.form-group{
    margin-bottom:1.3rem;
}

.form-group label{
    display:block;
    margin-bottom:0.5rem;
    font-weight:600;
    color:var(--ink);
    font-size:0.9rem;
}

.input-group{
    position:relative;
}

.input-group .input-icon{
    position:absolute;
    left:15px;
    top:50%;
    transform:translateY(-50%);
    color:var(--slate-light);
    font-size:1rem;
    z-index:2;
}

.input-group input{
    width:100%;
    padding:14px 50px 14px 45px;
    border:2px solid var(--border);
    border-radius:12px;
    font-size:1rem;
    font-family:'Inter',sans-serif;
    transition: all 0.2s;
    background:var(--light);
    color:var(--ink);
}

.input-group input:focus{
    outline:none;
    border-color:var(--teal);
    background:white;
    box-shadow:0 0 0 4px rgba(0,82,204,0.12);
}

.input-group .toggle-password{
    position:absolute;
    right:8px;
    top:50%;
    transform:translateY(-50%);
    cursor:pointer;
    color:var(--slate);
    transition:all 0.2s;
    background:transparent;
    border:none;
    width:34px;
    height:34px;
    border-radius:50%;
    font-size:1rem;
    z-index:10;
}

.input-group .toggle-password:hover{
    color:var(--teal-dark);
    background:rgba(0,82,204,0.1);
}

/* ---------- BUTTONS ---------- */
.forgot-password{
    text-align:right;
    margin:-0.5rem 0 1.3rem;
}

.forgot-password a{
    color:var(--teal-dark);
    font-size:0.85rem;
    font-weight:600;
    text-decoration:none;
    cursor:pointer;
    border-bottom:1px solid transparent;
    transition:border-color 0.2s;
}

.forgot-password a:hover{
    border-bottom-color:var(--teal-dark);
}

.btn{
    display:flex;
    align-items:center;
    justify-content:center;
    gap:10px;
    width:100%;
    padding:15px;
    border-radius:12px;
    font-size:1rem;
    font-weight:700;
    font-family:'Inter',sans-serif;
    cursor:pointer;
    transition:all 0.2s ease;
    text-decoration:none;
    border:2px solid transparent;
}

.btn:focus-visible{
    outline:3px solid rgba(0,82,204,0.5);
    outline-offset:2px;
}

.btn-primary{
    background:linear-gradient(135deg,var(--teal),var(--teal-dark));
    color:white;
    box-shadow:0 8px 18px rgba(0,61,153,0.28);
}

.btn-primary:hover{
    transform:translateY(-2px);
    box-shadow:0 12px 22px rgba(0,61,153,0.35);
}

.btn-primary:active{
    transform:translateY(0);
    box-shadow:0 4px 10px rgba(0,61,153,0.3);
}

.btn-outline{
    background:white;
    border-color:var(--border);
    color:var(--ink);
    font-weight:600;
    padding:12px;
}

.btn-outline:hover{
    border-color:var(--teal);
    color:var(--teal-dark);
    background:rgba(0,82,204,0.05);
}

.btn-ghost{
    background:transparent;
    color:var(--slate);
    font-weight:600;
    padding:10px;
}

.btn-ghost:hover{
    color:var(--ink);
    background:var(--light);
}

/* Alert */
.alert{
    padding:14px 16px;
    border-radius:12px;
    margin-bottom:1.3rem;
    font-size:0.88rem;
    display:flex;
    align-items:center;
    gap:12px;
    animation: slideIn 0.3s ease;
}

@keyframes slideIn{
    from{ opacity:0; transform:translateY(-10px); }
    to{ opacity:1; transform:translateY(0); }
}

.alert i{
    font-size:1.1rem;
}

.alert-danger{
    background:var(--danger-bg);
    color:#991b1b;
    border:1px solid #fecaca;
}

/* Divider + secondary actions */
.divider{
    display:flex;
    align-items:center;
    gap:12px;
    margin:1.8rem 0 1.3rem;
    color:var(--slate-light);
    font-size:0.8rem;
}

.divider::before, .divider::after{
    content:'';
    flex:1;
    height:1px;
    background:var(--border);
}

.action-row{
    display:flex;
    gap:0.8rem;
}

.back-home-row{
    text-align:center;
    margin-top:1rem;
}

/* Reset password modal */
.modal-overlay{
    display:none;
    position:fixed;
    inset:0;
    background:rgba(11,18,32,0.6);
    z-index:2000;
    align-items:center;
    justify-content:center;
    padding:20px;
}

.modal-overlay.active{
    display:flex;
}

.modal-box{
    background:white;
    border-radius:20px;
    max-width:420px;
    width:100%;
    overflow:hidden;
    box-shadow:0 20px 50px rgba(0,0,0,0.3);
    animation: slideUp 0.35s ease;
}

.modal-header{
    background:linear-gradient(135deg,var(--navy),var(--teal-dark));
    color:white;
    padding:1.6rem;
    text-align:center;
    position:relative;
}

.modal-header .modal-icon{
    width:54px;
    height:54px;
    margin:0 auto 0.7rem;
    border-radius:50%;
    background:rgba(255,255,255,0.15);
    display:flex;
    align-items:center;
    justify-content:center;
    font-size:1.5rem;
}

.modal-header h2{
    font-size:1.25rem;
}

.modal-close{
    position:absolute;
    top:14px;
    right:16px;
    background:rgba(255,255,255,0.15);
    border:none;
    color:white;
    width:30px;
    height:30px;
    border-radius:50%;
    cursor:pointer;
    font-size:0.9rem;
}

.modal-close:hover{
    background:rgba(255,255,255,0.3);
}

.modal-body{
    padding:1.6rem;
}

.modal-body > p{
    color:var(--ink);
    font-size:0.95rem;
    line-height:1.6;
    margin-bottom:1rem;
}

.modal-contact{
    background:var(--light);
    border:1px solid var(--border);
    border-radius:14px;
    padding:1rem 1.2rem;
    margin-bottom:1rem;
}

.modal-contact div{
    display:flex;
    align-items:center;
    gap:10px;
    color:var(--ink);
    font-weight:600;
    font-size:0.95rem;
    margin-bottom:0.6rem;
}

.modal-contact div:last-child{
    margin-bottom:0;
}

.modal-contact i{
    color:var(--teal-dark);
    width:18px;
}

.modal-note{
    font-size:0.85rem;
    color:var(--slate);
    font-style:italic;
}

.modal-footer{
    padding:0 1.6rem 1.6rem;
}

/* ---------- RESPONSIVE ---------- */
@media (max-width: 900px){
    .screen{
        grid-template-columns:1fr;
    }
    .brand-panel{
        padding:2.4rem 1.6rem;
        min-height:280px;
    }
    .brand-headline{
        font-size:1.7rem;
    }
    .gps-signature{
        height:100px;
        margin:1.6rem 0;
    }
    .brand-bottom{
        display:none;
    }
    .form-panel{
        padding:2.4rem 1.5rem;
    }
}

</style>
</head>
<body>

<div class="screen">

    <!-- ==================== LEFT: BRAND PANEL ==================== -->
    <div class="brand-panel">
        <div class="brand-top">
            <div class="brand-logo">
                <div class="brand-logo-icon"><i class="fas fa-triangle-exclamation"></i></div>
                RoadAlert TZ
            </div>
            <h1 class="brand-headline">Every second<br><span>saves a life.</span></h1>
            <p class="brand-sub">Sign in to respond to live accident reports, dispatch help, and track incidents across Tanzania in real time.</p>
        </div>

        <div class="gps-signature">
            <div class="gps-pin-wrap">
                <div class="gps-ring"></div>
                <div class="gps-ring d2"></div>
                <div class="gps-ring d3"></div>
                <div class="gps-pin"><i class="fas fa-location-dot"></i></div>
            </div>
        </div>
        <p class="gps-caption"><b>●</b> Live location capture on every report</p>

        <div class="brand-bottom">
            <div class="brand-bottom-item"><i class="fas fa-shield-halved"></i> Police</div>
            <div class="brand-bottom-item"><i class="fas fa-hospital"></i> Hospitals</div>
            <div class="brand-bottom-item"><i class="fas fa-truck-medical"></i> Ambulance</div>
        </div>
    </div>

    <!-- ==================== RIGHT: FORM PANEL ==================== -->
    <div class="form-panel">
        <div class="form-wrap">

            <div class="form-eyebrow"><i class="fas fa-lock"></i> Secure sign in</div>
            <h1>Welcome back</h1>
            <p class="form-lead">Log in to your RoadAlert TZ account.</p>

            <!-- ERROR MESSAGES -->
            <?php if($error != ""){ ?>
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-circle"></i>
                    <?php echo $error; ?>
                </div>
            <?php } ?>

            <!-- LOGIN FORM -->
            <form method="POST" id="loginForm">

                <!-- EMAIL -->
                <div class="form-group">
                    <label>Email Address</label>

                    <div class="input-group">
                        <i class="fas fa-envelope input-icon"></i>
                        <input type="email" name="email" id="email" placeholder="Enter Email" required autofocus
                               value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''; ?>">
                    </div>
                </div>

                <!-- PASSWORD -->
                <div class="form-group">
                    <label>Password</label>

                    <div class="input-group">
                        <i class="fas fa-lock input-icon"></i>
                        <input type="password" name="password" id="password" placeholder="Enter Password" required>
                        <button type="button" class="toggle-password" onclick="togglePassword()">
                            <i class="fas fa-eye" id="toggleIcon"></i>
                        </button>
                    </div>
                </div>

                <div class="forgot-password">
                    <a onclick="openResetModal()">Forgot Password?</a>
                </div>

                <!-- BUTTON -->
                <button type="submit" name="login" class="btn btn-primary">
                    <i class="fas fa-sign-in-alt"></i>
                    Log In
                </button>

            </form>

            <div class="divider">or</div>

            <!-- SECONDARY ACTIONS -->
            <div class="action-row">
                <a href="register.php" class="btn btn-outline">
                    <i class="fas fa-user-plus"></i> Register
                </a>
            </div>

            <div class="back-home-row">
                <a href="index.php" class="btn btn-ghost" style="display:inline-flex;width:auto;padding:8px 14px;">
                    <i class="fas fa-arrow-left"></i> Back to Home
                </a>
            </div>

        </div>
    </div>

</div>

<!-- ==================== FORGOT PASSWORD MODAL ==================== -->
<div class="modal-overlay" id="resetModal">
    <div class="modal-box">
        <div class="modal-header">
            <button type="button" class="modal-close" onclick="closeResetModal()">
                <i class="fas fa-times"></i>
            </button>
            <div class="modal-icon">
                <i class="fas fa-key"></i>
            </div>
            <h2>Password Reset</h2>
        </div>
        <div class="modal-body">
            <p>If you have forgotten your password, please contact the system administrator to reset your account.</p>

            <div class="modal-contact">
                <div><i class="fas fa-phone-alt"></i> +255 650 772 66</div>
                <div><i class="fas fa-envelope"></i> robertmeshack39@gmail.com</div>
            </div>

            <p class="modal-note">After your identity is verified, a temporary password will be provided to you.</p>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-primary" onclick="closeResetModal()">
                <i class="fas fa-check"></i> Got it
            </button>
        </div>
    </div>
</div>

<script>
// Toggle password visibility
function togglePassword() {
    const password = document.getElementById('password');
    const icon = document.getElementById('toggleIcon');

    if(password.type === 'password') {
        password.type = 'text';
        icon.classList.remove('fa-eye');
        icon.classList.add('fa-eye-slash');
    } else {
        password.type = 'password';
        icon.classList.remove('fa-eye-slash');
        icon.classList.add('fa-eye');
    }
}

// Forgot password modal
function openResetModal() {
    document.getElementById('resetModal').classList.add('active');
}

function closeResetModal() {
    document.getElementById('resetModal').classList.remove('active');
}

document.getElementById('resetModal').addEventListener('click', function(e) {
    if (e.target === this) {
        closeResetModal();
    }
});

// Auto-hide error messages after 5 seconds
document.addEventListener('DOMContentLoaded', function() {
    const alerts = document.querySelectorAll('.alert');
    alerts.forEach(alert => {
        setTimeout(() => {
            alert.style.opacity = '0';
            alert.style.transition = 'opacity 0.5s';
            setTimeout(() => {
                alert.style.display = 'none';
            }, 500);
        }, 5000);
    });
});

// Prevent empty form submission
document.getElementById('loginForm').addEventListener('submit', function(e) {
    const email = document.getElementById('email').value.trim();
    const password = document.getElementById('password').value.trim();

    if (!email || !password) {
        e.preventDefault();
        alert('Please fill in all fields!');
        return false;
    }
    return true;
});
</script>

</body>
</html>