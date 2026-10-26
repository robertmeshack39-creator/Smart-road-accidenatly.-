<?php
// register.php - User Registration with Approval System
session_start();
include "connection.php";

$message = "";
$message_type = "";

if(isset($_POST['register'])){

    $full_name = mysqli_real_escape_string($conn, $_POST['full_name']);
    $phone = mysqli_real_escape_string($conn, $_POST['phone']);
    $email = mysqli_real_escape_string($conn, $_POST['email']);
    $password = $_POST['password'];
    $confirm_password = $_POST['confirm_password'];
    $role = mysqli_real_escape_string($conn, $_POST['role']);

    // basic validation
    if(empty($full_name) || empty($phone) || empty($email) || empty($password) || empty($confirm_password)){
        $message = "Fill all fields!";
        $message_type = "error";
    } elseif ($password !== $confirm_password) {
        $message = "Passwords do not match!";
        $message_type = "error";
    } else {
        // Password strength check
        if (!preg_match('/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d).{8,}$/', $password)) {
            $message = "Password must be at least 8 characters long and include uppercase, lowercase, and number!";
            $message_type = "error";
        } else {
            // check email
            $check = mysqli_query($conn, "SELECT * FROM users WHERE email='$email'");
            if(mysqli_num_rows($check) > 0){
                $message = "Email already exists! Please login instead.";
                $message_type = "error";
            } else {

                $hashed = password_hash($password, PASSWORD_DEFAULT);

                // 🔴 CHECK IF approval_status COLUMN EXISTS
                $col_check = mysqli_query($conn, "SHOW COLUMNS FROM users LIKE 'approval_status'");
                $has_approval = mysqli_num_rows($col_check) > 0;

                if ($has_approval) {
                    // Insert with PENDING status
                    $sql = "INSERT INTO users (full_name, phone, email, password, role, approval_status, created_at)
                            VALUES ('$full_name', '$phone', '$email', '$hashed', '$role', 'pending', NOW())";
                } else {
                    // Fallback if column doesn't exist
                    $sql = "INSERT INTO users (full_name, phone, email, password, role, created_at)
                            VALUES ('$full_name', '$phone', '$email', '$hashed', '$role', NOW())";
                }

                if(mysqli_query($conn, $sql)){
                    $message = "✅ Registration successful! Your account is pending approval by the administrator.";
                    $message_type = "success";
                    
                    // 🔴 SEND EMAIL NOTIFICATION TO ADMIN (Optional)
                    // notify_admin_new_user(mysqli_insert_id($conn));
                    
                    // Clear form data
                    $_POST = array();
                } else {
                    $message = "Error: " . mysqli_error($conn);
                    $message_type = "error";
                }
            }
        }
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Register - RoadAlert TZ</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 20px;
        }

        .container {
            background: white;
            border-radius: 20px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            overflow: hidden;
            width: 100%;
            max-width: 520px;
            animation: fadeIn 0.5s ease-in;
        }

        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: translateY(-20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 30px;
            text-align: center;
        }

        .header .logo-icon {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 60px;
            height: 60px;
            border-radius: 50%;
            background: rgba(255,255,255,0.2);
            font-size: 28px;
            margin-bottom: 10px;
        }

        .header h2 {
            font-size: 26px;
            margin-bottom: 4px;
            font-weight: 700;
        }

        .header h2 i {
            margin-right: 10px;
        }

        .header p {
            opacity: 0.9;
            font-size: 14px;
        }

        form {
            padding: 30px 35px 25px;
        }

        .input-group {
            margin-bottom: 18px;
        }

        label {
            display: block;
            margin-bottom: 6px;
            color: #333;
            font-weight: 600;
            font-size: 13px;
        }

        label i {
            margin-right: 8px;
            color: #667eea;
            width: 18px;
        }

        .input-wrapper {
            position: relative;
        }

        .input-wrapper i:first-child {
            position: absolute;
            left: 12px;
            top: 50%;
            transform: translateY(-50%);
            color: #999;
            font-size: 14px;
        }

        .input-wrapper:focus-within i:first-child {
            color: #667eea;
        }

        input, select {
            width: 100%;
            padding: 12px 15px 12px 40px;
            border: 2px solid #e0e0e0;
            border-radius: 10px;
            font-size: 14px;
            transition: all 0.3s ease;
            outline: none;
            font-family: inherit;
        }

        input:focus, select:focus {
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102,126,234,0.1);
        }

        select {
            appearance: none;
            cursor: pointer;
            background: white;
        }

        .password-wrapper {
            position: relative;
        }

        .password-wrapper input {
            padding-right: 40px;
        }

        .toggle-password {
            position: absolute;
            right: 12px;
            top: 50%;
            transform: translateY(-50%);
            cursor: pointer;
            color: #999;
            font-size: 16px;
            user-select: none;
            z-index: 2;
        }

        .toggle-password:hover {
            color: #667eea;
        }

        .strength-meter {
            margin-top: 8px;
            height: 5px;
            background-color: #e0e0e0;
            border-radius: 5px;
            overflow: hidden;
        }

        .strength-bar {
            height: 100%;
            width: 0%;
            transition: width 0.3s ease, background-color 0.3s ease;
            border-radius: 5px;
        }

        .strength-text {
            font-size: 12px;
            margin-top: 5px;
            color: #666;
        }

        .weak { background-color: #f44336; width: 33%; }
        .medium { background-color: #ff9800; width: 66%; }
        .strong { background-color: #4caf50; width: 100%; }

        .weak-text { color: #f44336; }
        .medium-text { color: #ff9800; }
        .strong-text { color: #4caf50; }

        .role-hint {
            font-size: 12px;
            color: #999;
            margin-top: 4px;
        }

        .role-hint i {
            margin-right: 4px;
            color: #667eea;
        }

        button {
            width: 100%;
            padding: 14px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            border-radius: 10px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }

        button i {
            margin-right: 8px;
        }

        button:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 20px rgba(0,0,0,0.2);
        }

        button:active {
            transform: translateY(0);
        }

        .message {
            padding: 12px 20px;
            border-radius: 10px;
            margin: 0 35px 15px 35px;
            text-align: center;
            font-size: 14px;
            animation: slideIn 0.3s ease;
        }

        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateY(-10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .message.error {
            background-color: #ffebee;
            color: #c62828;
            border-left: 4px solid #c62828;
        }

        .message.success {
            background-color: #e8f5e9;
            color: #2e7d32;
            border-left: 4px solid #2e7d32;
        }

        .message i {
            margin-right: 8px;
        }

        /* Pending Notice */
        .pending-notice {
            background: #fff3e0;
            border-left: 4px solid #ff9800;
            padding: 14px 18px;
            margin: 0 35px 15px 35px;
            border-radius: 8px;
            display: flex;
            align-items: flex-start;
            gap: 12px;
            animation: slideIn 0.3s ease;
        }

        .pending-notice i {
            color: #ff9800;
            font-size: 20px;
            margin-top: 2px;
        }

        .pending-notice .pn-title {
            font-weight: 700;
            color: #e65100;
            font-size: 14px;
        }

        .pending-notice .pn-text {
            font-size: 13px;
            color: #555;
            margin-top: 2px;
            line-height: 1.5;
        }

        .login-link {
            text-align: center;
            padding: 18px 35px 25px;
            border-top: 1px solid #e0e0e0;
            font-size: 14px;
            color: #666;
        }

        .login-link i {
            margin-right: 6px;
            color: #667eea;
        }

        .login-link a {
            color: #667eea;
            text-decoration: none;
            font-weight: 600;
        }

        .login-link a:hover {
            text-decoration: underline;
        }

        @media (max-width: 480px) {
            .header { padding: 20px; }
            form { padding: 20px; }
            .message { margin: 0 20px 12px 20px; }
            .pending-notice { margin: 0 20px 12px 20px; }
            .login-link { padding: 15px 20px; }
        }
    </style>
</head>
<body>

<div class="container">
    <div class="header">
        <div class="logo-icon">⚠️</div>
        <h2><i class="fas fa-user-plus"></i> Create Account</h2>
        <p>Join RoadAlert TZ - Report and track accidents</p>
    </div>

    <?php if($message): ?>
        <div class="message <?php echo $message_type; ?>">
            <i class="<?php echo $message_type === 'success' ? 'fas fa-check-circle' : 'fas fa-exclamation-triangle'; ?>"></i>
            <?php echo $message; ?>
        </div>
    <?php endif; ?>

    <?php if($message_type === 'success'): ?>
    <!-- Pending Approval Notice -->
    <div class="pending-notice">
        <i class="fas fa-clock"></i>
        <div>
            <div class="pn-title">⏳ Account Pending Approval</div>
            <div class="pn-text">
                Your account has been created but is <strong>pending approval</strong> from the administrator.
                You will be notified once your account is activated.
            </div>
        </div>
    </div>
    <?php endif; ?>

    <form method="POST" id="registerForm">
        <div class="input-group">
            <label><i class="fas fa-user"></i> Full Name</label>
            <div class="input-wrapper">
                <i class="fas fa-user"></i>
                <input type="text" name="full_name" placeholder="Enter your full name" 
                       value="<?php echo isset($_POST['full_name']) ? htmlspecialchars($_POST['full_name']) : ''; ?>" required>
            </div>
        </div>

        <div class="input-group">
            <label><i class="fas fa-phone"></i> Phone Number</label>
            <div class="input-wrapper">
                <i class="fas fa-phone"></i>
                <input type="tel" name="phone" placeholder="+255 7XX XXX XXX" 
                       value="<?php echo isset($_POST['phone']) ? htmlspecialchars($_POST['phone']) : ''; ?>" required>
            </div>
        </div>

        <div class="input-group">
            <label><i class="fas fa-envelope"></i> Email Address</label>
            <div class="input-wrapper">
                <i class="fas fa-envelope"></i>
                <input type="email" name="email" placeholder="Enter your email address" 
                       value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''; ?>" required>
            </div>
        </div>

        <div class="input-group">
            <label><i class="fas fa-lock"></i> Password</label>
            <div class="input-wrapper password-wrapper">
                <i class="fas fa-lock"></i>
                <input type="password" name="password" id="password" placeholder="Create a strong password" required>
                <i class="fas fa-eye toggle-password" onclick="togglePassword('password')"></i>
            </div>
            <div class="strength-meter">
                <div class="strength-bar" id="strengthBar"></div>
            </div>
            <div class="strength-text" id="strengthText"></div>
        </div>

        <div class="input-group">
            <label><i class="fas fa-check-circle"></i> Confirm Password</label>
            <div class="input-wrapper password-wrapper">
                <i class="fas fa-check-circle"></i>
                <input type="password" name="confirm_password" id="confirm_password" placeholder="Confirm your password" required>
                <i class="fas fa-eye toggle-password" onclick="togglePassword('confirm_password')"></i>
            </div>
            <div class="strength-text" id="matchText"></div>
        </div>

        <div class="input-group">
            <label><i class="fas fa-briefcase"></i> Register As</label>
            <div class="input-wrapper">
                <i class="fas fa-briefcase"></i>
                <select name="role" required>
                    <option value="police" <?php echo (isset($_POST['role']) && $_POST['role'] == 'police') ? 'selected' : ''; ?>>🚔 Police</option>
                    <option value="hospital" <?php echo (isset($_POST['role']) && $_POST['role'] == 'hospital') ? 'selected' : ''; ?>>🏥 Hospital</option>
                    
                </select>
            </div>
            <div class="role-hint">
                <i class="fas fa-info-circle"></i> Police and Hospital accounts require admin approval
            </div>
        </div>

        <button type="submit" name="register">
            <i class="fas fa-paper-plane"></i> Register Account
        </button>
    </form>

    <div class="login-link">
        <i class="fas fa-sign-in-alt"></i> Already have an account? <a href="login.php">Login here</a>
    </div>
</div>

<script>
    const passwordInput = document.getElementById('password');
    const confirmInput = document.getElementById('confirm_password');
    const strengthBar = document.getElementById('strengthBar');
    const strengthText = document.getElementById('strengthText');
    const matchText = document.getElementById('matchText');

    function checkPasswordStrength(password) {
        let strength = 0;
        
        if (password.length >= 8) strength++;
        if (password.match(/[a-z]/)) strength++;
        if (password.match(/[A-Z]/)) strength++;
        if (password.match(/[0-9]/)) strength++;
        
        if (strength === 4) {
            return { class: 'strong', text: '✅ Strong Password', level: 'strong' };
        } else if (strength === 3) {
            return { class: 'medium', text: '⚠️ Medium Password', level: 'medium' };
        } else if (strength >= 1) {
            return { class: 'weak', text: '❌ Weak Password', level: 'weak' };
        } else {
            return { class: '', text: '', level: 'none' };
        }
    }

    function updateStrengthMeter() {
        const password = passwordInput.value;
        
        if (password.length === 0) {
            strengthBar.className = 'strength-bar';
            strengthBar.style.width = '0%';
            strengthText.innerHTML = '';
            return;
        }
        
        const result = checkPasswordStrength(password);
        
        if (result.level === 'strong') {
            strengthBar.className = 'strength-bar strong';
            strengthText.innerHTML = '<span class="strong-text">' + result.text + '</span>';
        } else if (result.level === 'medium') {
            strengthBar.className = 'strength-bar medium';
            strengthText.innerHTML = '<span class="medium-text">' + result.text + '</span>';
        } else if (result.level === 'weak') {
            strengthBar.className = 'strength-bar weak';
            strengthText.innerHTML = '<span class="weak-text">' + result.text + '</span>';
        }
        
        checkPasswordMatch();
    }
    
    function checkPasswordMatch() {
        const password = passwordInput.value;
        const confirm = confirmInput.value;
        
        if (confirm.length === 0) {
            matchText.innerHTML = '';
            return;
        }
        
        if (password === confirm) {
            matchText.innerHTML = '<span style="color: #2e7d32;"><i class="fas fa-check-circle"></i> Passwords match</span>';
        } else {
            matchText.innerHTML = '<span style="color: #c62828;"><i class="fas fa-times-circle"></i> Passwords do not match</span>';
        }
    }
    
    function togglePassword(fieldId) {
        const field = document.getElementById(fieldId);
        const icon = field.nextElementSibling;
        const type = field.getAttribute('type') === 'password' ? 'text' : 'password';
        field.setAttribute('type', type);
        
        if (type === 'password') {
            icon.classList.remove('fa-eye-slash');
            icon.classList.add('fa-eye');
        } else {
            icon.classList.remove('fa-eye');
            icon.classList.add('fa-eye-slash');
        }
    }
    
    passwordInput.addEventListener('input', updateStrengthMeter);
    confirmInput.addEventListener('input', checkPasswordMatch);
    
    document.getElementById('registerForm').addEventListener('submit', function(e) {
        const password = passwordInput.value;
        const confirm = confirmInput.value;
        
        if (password !== confirm) {
            e.preventDefault();
            matchText.innerHTML = '<span style="color: #c62828;"><i class="fas fa-times-circle"></i> Passwords do not match!</span>';
            alert('Passwords do not match!');
            return false;
        }
        
        const strength = checkPasswordStrength(password);
        if (strength.level !== 'strong') {
            e.preventDefault();
            alert('Please use a stronger password!\n\nPassword must be:\n• At least 8 characters long\n• Include uppercase letter\n• Include lowercase letter\n• Include a number');
            return false;
        }
        
        return true;
    });
</script>

</body>
</html>