<?php
// index.php - RoadAlert TZ Landing Page.
session_start();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>RoadAlert TZ - Smart Accident Reporting | Tanzania</title>
    <!-- Font Awesome Icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* ==================== RESET & BASE STYLES ==================== */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            line-height: 1.6;
            background: #f0f2f5;
            display: flex;
            flex-direction: column;
            min-height: 100vh;
        }

        /* ==================== NEW COLOR VARIABLES ==================== */
        :root {
            --primary: #0052cc;
            --primary-dark: #003d99;
            --primary-light: #e6f0ff;
            --secondary: #00a86b;
            --danger: #e63946;
            --danger-dark: #c1121f;
            --warning: #ff8c42;
            --dark: #1e293b;
            --gray: #64748b;
            --gray-light: #94a3b8;
            --light: #f8fafc;
            --white: #ffffff;
            --border: #e2e8f0;
            --shadow: 0 4px 6px -1px rgba(0,0,0,0.1);
            --shadow-lg: 0 10px 15px -3px rgba(0,0,0,0.1);
        }

        /* ==================== UTILITY CLASSES ==================== */
        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 2rem;
        }

        .btn {
            display: inline-block;
            padding: 0.875rem 1.75rem;
            border-radius: 12px;
            text-decoration: none;
            font-weight: 600;
            transition: all 0.3s ease;
            cursor: pointer;
            border: none;
            font-size: 1rem;
        }

        .btn-primary {
            background: var(--primary);
            color: white;
        }

        .btn-primary:hover {
            background: var(--primary-dark);
            transform: translateY(-2px);
            box-shadow: var(--shadow);
        }

        .btn-danger {
            background: var(--danger);
            color: white;
        }

        .btn-danger:hover {
            background: var(--danger-dark);
            transform: translateY(-2px);
            box-shadow: var(--shadow);
        }

        .btn-outline {
            background: transparent;
            border: 2px solid var(--primary);
            color: var(--primary);
        }

        .btn-outline:hover {
            background: var(--primary);
            color: white;
        }

        /* ==================== HEADER / NAVBAR ==================== */
        .header {
            background: rgba(255,255,255,0.95);
            backdrop-filter: blur(10px);
            box-shadow: 0 2px 20px rgba(0,0,0,0.08);
            position: fixed;
            width: 100%;
            top: 0;
            z-index: 1000;
        }

        .nav-container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 1rem 2rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
        }

        .logo {
            display: flex;
            align-items: center;
            gap: 12px;
            font-size: 1.5rem;
            font-weight: bold;
            text-decoration: none;
        }

        .logo-icon {
            width: 40px;
            height: 40px;
            background: linear-gradient(135deg, var(--danger), var(--warning));
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 1.3rem;
        }

        .logo-text {
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
        }

        .nav-links {
            display: flex;
            gap: 2rem;
            list-style: none;
        }

        .nav-links a {
            text-decoration: none;
            color: var(--dark);
            font-weight: 500;
            transition: color 0.3s;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .nav-links a:hover {
            color: var(--danger);
        }

        .nav-buttons {
            display: flex;
            gap: 1rem;
        }

        .menu-btn {
            display: none;
            font-size: 1.5rem;
            cursor: pointer;
            color: var(--dark);
        }

        /* ==================== HERO SECTION ==================== */
        .hero {
            min-height: 100vh;
            background:
                linear-gradient(135deg, rgba(0,82,204,0.92) 0%, rgba(0,168,107,0.90) 100%),
                url('https://images.unsplash.com/photo-1573225289860-e2a2f651e7b6?auto=format&fit=crop&w=1600&q=60') center/cover no-repeat;
            display: flex;
            align-items: center;
            text-align: center;
            padding-top: 80px;
            position: relative;
            overflow: hidden;
        }

        .hero::before {
            content: '';
            position: absolute;
            width: 100%;
            height: 100%;
            background: url('data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 200 200"><path fill="rgba(255,255,255,0.05)" d="M100 0L200 200H0L100 0z"/></svg>');
            background-size: 60px;
            opacity: 0.1;
        }

        .hero-status {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            background: rgba(255,255,255,0.15);
            border: 1px solid rgba(255,255,255,0.35);
            padding: 0.5rem 1.1rem;
            border-radius: 999px;
            font-size: 0.85rem;
            font-weight: 600;
            color: white;
            margin-bottom: 1.5rem;
            backdrop-filter: blur(6px);
        }

        .pulse-dot {
            width: 10px;
            height: 10px;
            border-radius: 50%;
            background: #4ade80;
            box-shadow: 0 0 0 rgba(74, 222, 128, 0.6);
            animation: pulse 1.8s infinite;
        }

        @keyframes pulse {
            0% { box-shadow: 0 0 0 0 rgba(74, 222, 128, 0.6); }
            70% { box-shadow: 0 0 0 10px rgba(74, 222, 128, 0); }
            100% { box-shadow: 0 0 0 0 rgba(74, 222, 128, 0.6); }
        }

        .hero-content {
            max-width: 800px;
            margin: 0 auto;
            color: white;
            position: relative;
            z-index: 1;
        }

        .hero-title {
            font-size: 4rem;
            margin-bottom: 1.5rem;
            animation: fadeInUp 0.8s ease;
        }

        .hero-title span {
            color: #fbbf24;
            text-shadow: 2px 2px 4px rgba(0,0,0,0.2);
        }

        .hero-subtitle {
            font-size: 1.2rem;
            margin-bottom: 2rem;
            opacity: 0.95;
            animation: fadeInUp 0.8s ease 0.2s forwards;
            opacity: 0;
            animation-fill-mode: forwards;
        }

        .hero-buttons {
            display: flex;
            gap: 1rem;
            justify-content: center;
            flex-wrap: wrap;
            animation: fadeInUp 0.8s ease 0.4s forwards;
            opacity: 0;
            animation-fill-mode: forwards;
        }

        /* ==================== TRUST STRIP ==================== */
        .trust-strip {
            background: var(--dark);
            padding: 1.1rem 0;
        }

        .trust-strip .container {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 2.5rem;
            flex-wrap: wrap;
        }

        .trust-strip span {
            color: var(--gray-light);
            font-size: 0.85rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 8px;
            letter-spacing: 0.3px;
        }

        .trust-strip i {
            color: var(--warning);
        }

        /* ==================== STATS SECTION ==================== */
        .stats {
            padding: 5rem 0;
            background: var(--white);
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 2rem;
            text-align: center;
        }

        .stat-card {
            padding: 2rem;
            background: var(--light);
            border-radius: 20px;
            transition: all 0.3s;
            border: 1px solid var(--border);
        }

        .stat-card:hover {
            transform: translateY(-8px);
            box-shadow: var(--shadow-lg);
            border-color: var(--primary);
        }

        .stat-icon {
            font-size: 3rem;
            color: var(--primary);
            margin-bottom: 1rem;
        }

        .stat-number {
            font-size: 2.5rem;
            font-weight: bold;
            color: var(--danger);
        }

        .stat-label {
            color: var(--gray);
            font-size: 0.9rem;
            font-weight: 500;
        }

        /* ==================== HOW IT WORKS SECTION ==================== */
        .how-it-works {
            padding: 5rem 0;
            background: linear-gradient(135deg, var(--light) 0%, #ffffff 100%);
        }

        .section-title {
            text-align: center;
            font-size: 2.5rem;
            margin-bottom: 1rem;
            color: var(--dark);
        }

        .section-title span {
            color: var(--danger);
            position: relative;
        }

        .section-title span::after {
            content: '';
            position: absolute;
            bottom: -5px;
            left: 0;
            width: 100%;
            height: 3px;
            background: linear-gradient(90deg, var(--danger), var(--warning));
            border-radius: 3px;
        }

        .section-subtitle {
            text-align: center;
            color: var(--gray);
            margin-bottom: 3rem;
            font-size: 1.1rem;
        }

        .steps-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 2rem;
        }

        .step-card {
            text-align: center;
            padding: 2rem;
            background: var(--white);
            border-radius: 20px;
            box-shadow: var(--shadow);
            transition: all 0.3s;
            position: relative;
            overflow: hidden;
        }

        .step-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 4px;
            background: linear-gradient(90deg, var(--danger), var(--warning));
            transform: scaleX(0);
            transition: transform 0.3s;
        }

        .step-card:hover::before {
            transform: scaleX(1);
        }

        .step-card:hover {
            transform: translateY(-8px);
            box-shadow: var(--shadow-lg);
        }

        .step-icon {
            width: 70px;
            height: 70px;
            background: linear-gradient(135deg, var(--primary-light), #dbeafe);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 1rem;
            font-size: 2rem;
            color: var(--primary);
            position: relative;
        }

        .step-number {
            position: absolute;
            top: -6px;
            right: -6px;
            width: 26px;
            height: 26px;
            border-radius: 50%;
            background: var(--danger);
            color: white;
            font-size: 0.8rem;
            font-weight: 700;
            display: flex;
            align-items: center;
            justify-content: center;
            border: 2px solid white;
        }

        .step-card h3 {
            margin-bottom: 0.75rem;
            color: var(--dark);
        }

        .step-card p {
            color: var(--gray);
            font-size: 0.9rem;
        }

        /* ==================== TESTIMONIALS ==================== */
        .testimonials {
            padding: 5rem 0;
            background: var(--white);
        }

        .testimonial-card {
            max-width: 780px;
            margin: 0 auto;
            background: var(--light);
            border-radius: 20px;
            padding: 2.5rem;
            box-shadow: var(--shadow-lg);
            text-align: center;
            border: 1px solid var(--border);
        }

        .testimonial-card i.fa-quote-left {
            font-size: 1.8rem;
            color: var(--primary-light);
        }

        .testimonial-text {
            font-size: 1.1rem;
            color: var(--dark);
            margin: 1rem 0 1.5rem;
            font-style: italic;
        }

        .testimonial-author {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 12px;
        }

        .testimonial-avatar {
            width: 46px;
            height: 46px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 700;
        }

        .testimonial-author strong {
            display: block;
            color: var(--dark);
            font-size: 0.95rem;
        }

        .testimonial-author span {
            color: var(--gray);
            font-size: 0.8rem;
        }

        /* ==================== FEATURES SECTION ==================== */
        .features {
            padding: 5rem 0;
            background: linear-gradient(135deg, var(--light) 0%, #ffffff 100%);
        }

        .features-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
            gap: 2rem;
        }

        .feature-card {
            display: flex;
            gap: 1.2rem;
            padding: 1.8rem;
            background: var(--white);
            border-radius: 16px;
            transition: all 0.3s;
            border: 1px solid var(--border);
        }

        .feature-card:hover {
            transform: translateX(8px);
            border-color: var(--primary);
            box-shadow: var(--shadow);
        }

        .feature-icon {
            width: 55px;
            height: 55px;
            background: linear-gradient(135deg, var(--danger), var(--warning));
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            color: white;
            flex-shrink: 0;
        }

        .feature-card h4 {
            margin-bottom: 0.5rem;
            color: var(--dark);
            font-size: 1.1rem;
        }

        .feature-card p {
            color: var(--gray);
            font-size: 0.85rem;
            line-height: 1.5;
        }

        /* ==================== CTA SECTION ==================== */
        .cta {
            padding: 5rem 0;
            background: linear-gradient(135deg, var(--dark) 0%, var(--primary) 100%);
            color: white;
            text-align: center;
            position: relative;
            overflow: hidden;
        }

        .cta::before {
            content: '🚨';
            position: absolute;
            font-size: 15rem;
            right: -50px;
            bottom: -80px;
            opacity: 0.05;
            pointer-events: none;
        }

        .cta h2 {
            font-size: 2.5rem;
            margin-bottom: 1rem;
        }

        .cta p {
            margin-bottom: 2rem;
            opacity: 0.9;
            font-size: 1.1rem;
        }

        .cta .btn-white {
            background: white;
            color: var(--primary);
        }

        .cta .btn-white:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow);
        }

        .cta .btn-outline-white {
            background: transparent;
            border: 2px solid white;
            color: white;
        }

        .cta .btn-outline-white:hover {
            background: white;
            color: var(--primary);
        }

        /* ================================================================ */
        /* ==================== FOOTER STYLES ============================ */
        /* ================================================================ */

        .footer {
            background: var(--dark);
            color: var(--gray-light);
            padding: 2.5rem 0 1.5rem;
            flex-shrink: 0;
            margin-top: auto;
        }

        .footer-container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 2rem;
        }

        .footer-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 2rem;
        }

        .footer-col h3,
        .footer-col h4 {
            font-size: 0.9rem;
            font-weight: 700;
            color: var(--white);
            margin-bottom: 0.8rem;
        }

        .footer-col h3 i,
        .footer-col h4 i {
            margin-right: 0.4rem;
            color: var(--danger);
        }

        .footer-brand {
            font-size: 1.1rem !important;
        }

        .footer-desc {
            font-size: 0.75rem;
            color: var(--gray-light);
            line-height: 1.6;
            margin-bottom: 0.6rem;
        }

        .footer-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.3rem;
            font-size: 0.55rem;
            font-weight: 600;
            font-family: monospace;
            color: #4ade80;
            background: rgba(74, 222, 128, 0.1);
            border: 1px solid rgba(74, 222, 128, 0.2);
            border-radius: 999px;
            padding: 0.15rem 0.6rem;
        }

        .footer-list {
            list-style: none;
            padding: 0;
            margin: 0;
        }

        .footer-list li {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 0.72rem;
            color: var(--gray-light);
            padding: 0.25rem 0;
            transition: all 0.2s;
        }

        .footer-list li i {
            width: 16px;
            font-size: 0.7rem;
            color: var(--danger);
            flex-shrink: 0;
        }

        .footer-list li strong {
            color: var(--white);
        }

        .footer-links {
            list-style: none;
            padding: 0;
            margin: 0;
        }

        .footer-links li a {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            color: var(--gray-light);
            text-decoration: none;
            font-size: 0.72rem;
            transition: all 0.2s;
            padding: 0.15rem 0;
        }

        .footer-links li a i {
            width: 16px;
            font-size: 0.7rem;
            color: var(--danger);
            flex-shrink: 0;
            transition: all 0.2s;
        }

        .footer-links li a:hover {
            color: var(--white);
            padding-left: 4px;
        }

        .footer-links li a:hover i {
            color: var(--warning);
        }

        .footer-features {
            list-style: none;
            padding: 0;
            margin: 0;
        }

        .footer-features li {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 0.72rem;
            color: var(--gray-light);
            padding: 0.25rem 0;
        }

        .footer-features li i {
            width: 16px;
            font-size: 0.7rem;
            color: var(--danger);
            flex-shrink: 0;
        }

        .footer-divider {
            border: none;
            border-top: 1px solid rgba(255,255,255,0.08);
            margin: 1.5rem 0 1rem;
        }

        .footer-bottom {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 0.3rem;
            text-align: center;
        }

        .footer-bottom p {
            font-size: 0.65rem;
            color: var(--gray-light);
            margin: 0;
        }

        .footer-bottom strong {
            color: var(--white);
        }

        .footer-heart {
            font-size: 0.7rem !important;
        }

        .footer-heart i {
            color: var(--danger);
        }

        .footer-tech {
            font-family: monospace;
            font-size: 0.55rem !important;
            color: var(--gray-light);
            opacity: 0.6;
        }

        /* ================================================================ */
        /* ==================== ANIMATIONS ================================ */
        /* ================================================================ */

        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        /* ================================================================ */
        /* ==================== RESPONSIVE ================================ */
        /* ================================================================ */

        @media (max-width: 768px) {
            .nav-links {
                display: none;
            }

            .menu-btn {
                display: block;
            }

            .hero-title {
                font-size: 2.2rem;
            }

            .hero-subtitle {
                font-size: 1rem;
            }

            .container {
                padding: 0 1.2rem;
            }

            .btn {
                width: 100%;
                text-align: center;
            }

            .hero-buttons {
                flex-direction: column;
            }

            .section-title {
                font-size: 1.8rem;
            }

            .footer-grid {
                grid-template-columns: 1fr 1fr;
                gap: 1rem;
            }
        }

        @media (max-width: 480px) {
            .stats-grid,
            .steps-grid,
            .features-grid,
            .footer-grid {
                grid-template-columns: 1fr;
            }

            .nav-buttons .btn {
                padding: 0.5rem 0.8rem;
                font-size: 0.8rem;
            }

            .hero-title {
                font-size: 1.8rem;
            }
        }
    </style>
</head>
<body>

<!-- ==================== HEADER / NAVIGATION ==================== -->
<header class="header">
    <div class="nav-container">
        <a href="index.php" class="logo">
            <div class="logo-icon">
                <i class="fas fa-triangle-exclamation"></i>
            </div>
            <span class="logo-text">RoadAlert TZ</span>
        </a>

        <ul class="nav-links">
            <li><a href="#home"><i class="fas fa-home"></i> Home</a></li>
            <li><a href="#how-it-works"><i class="fas fa-question-circle"></i> How It Works</a></li>
            <li><a href="#features"><i class="fas fa-star"></i> Features</a></li>
            <li><a href="#about"><i class="fas fa-info-circle"></i> About</a></li>
        </ul>

        <div class="nav-buttons">
            <a href="login.php" class="btn btn-outline"><i class="fas fa-sign-in-alt"></i> Login</a>
            <a href="register.php" class="btn btn-primary"><i class="fas fa-user-plus"></i> Register</a>
        </div>

        <div class="menu-btn" onclick="toggleMenu()">
            <i class="fas fa-bars"></i>
        </div>
    </div>
</header>

<!-- ==================== HERO SECTION ==================== -->
<section class="hero" id="home">
    <div class="container">
        <div class="hero-content">
            <div class="hero-status">
                <span class="pulse-dot"></span> SYSTEM LIVE — Dar es Salaam, Arusha, Mwanza & 15 other regions
            </div>
            <h1 class="hero-title">
                REPORT. <span>RESPOND.</span><br>
                <i class="fas fa-heartbeat"></i> SAVE LIVES.
            </h1>
            <p class="hero-subtitle">
                <i class="fas fa-location-dot"></i> Tanzania's fastest emergency reporting platform.<br>
                Connect with first responders instantly. Every second counts.
            </p>
            <div class="hero-buttons">
                <a href="report.php" class="btn btn-danger">
                    <i class="fas fa-exclamation-triangle"></i> Report Accident Now
                </a>
                <a href="login.php" class="btn btn-outline">
                    <i class="fas fa-chart-line"></i> View Dashboard →
                </a>
            </div>
        </div>
    </div>
</section>

<!-- ==================== TRUST STRIP ==================== -->
<div class="trust-strip">
    <div class="container">
        <span><i class="fas fa-shield-halved"></i> Tanzania Police Force</span>
        <span><i class="fas fa-hospital"></i> National Hospitals Network</span>
        <span><i class="fas fa-truck-medical"></i> Ambulance Services</span>
        <span><i class="fas fa-satellite-dish"></i> GPS Enabled</span>
    </div>
</div>

<!-- ==================== STATISTICS SECTION ==================== -->
<section class="stats">
    <div class="container">
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon"><i class="fas fa-file-alt"></i></div>
                <div class="stat-number">12,000+</div>
                <div class="stat-label">Reports Processed</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon"><i class="fas fa-clock"></i></div>
                <div class="stat-number">4.2 min</div>
                <div class="stat-label">Average Response Time</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon"><i class="fas fa-headset"></i></div>
                <div class="stat-number">24/7</div>
                <div class="stat-label">Emergency Support</div>
            </div>
        </div>
    </div>
</section>

<!-- ==================== HOW IT WORKS SECTION ==================== -->
<section class="how-it-works" id="how-it-works">
    <div class="container">
        <h2 class="section-title">How <span>It Works</span></h2>
        <p class="section-subtitle">Simple steps to report an accident and get help fast</p>
        <div class="steps-grid">
            <div class="step-card">
                <div class="step-icon">
                    <i class="fas fa-map-marker-alt"></i>
                    <span class="step-number">1</span>
                </div>
                <h3>Auto GPS Location</h3>
                <p>Your exact location is captured automatically. No need to explain where you are.</p>
            </div>
            <div class="step-card">
                <div class="step-icon">
                    <i class="fas fa-pen-alt"></i>
                    <span class="step-number">2</span>
                </div>
                <h3>Fill Simple Form</h3>
                <p>Describe accident, number injured. Takes only 30 seconds.</p>
            </div>
            <div class="step-card">
                <div class="step-icon">
                    <i class="fas fa-bell"></i>
                    <span class="step-number">3</span>
                </div>
                <h3>Instant Alert</h3>
                <p>Police & hospital notified immediately with GPS link.</p>
            </div>
            <div class="step-card">
                <div class="step-icon">
                    <i class="fas fa-truck-medical"></i>
                    <span class="step-number">4</span>
                </div>
                <h3>Help Arrives Fast</h3>
                <p>Emergency teams reach exact location. Hospital prepares.</p>
            </div>
        </div>
    </div>
</section>

<!-- ==================== FEATURES SECTION ==================== -->
<section class="features" id="features">
    <div class="container">
        <h2 class="section-title">Key <span>Features</span></h2>
        <p class="section-subtitle">Powerful tools designed for emergency response</p>
        <div class="features-grid">
            <div class="feature-card">
                <div class="feature-icon"><i class="fas fa-map-marked-alt"></i></div>
                <div>
                    <h4>GPS Location Capture</h4>
                    <p>Auto-detects exact coordinates with draggable pin for correction. Precision tracking for accurate dispatch.</p>
                </div>
            </div>
           
            <div class="feature-card">
                <div class="feature-icon"><i class="fas fa-chart-pie"></i></div>
                <div>
                    <h4>Live Dashboard</h4>
                    <p>Real-time map showing all active incidents and responses. Track emergency teams live.</p>
                </div>
            </div>
            <div class="feature-card">
                <div class="feature-icon"><i class="fas fa-mobile-alt"></i></div>
                <div>
                    <h4>Mobile Friendly</h4>
                    <p>Works on any phone browser. No app download required. Optimized for all devices.</p>
                </div>
            </div>
            <div class="feature-card">
                <div class="feature-icon"><i class="fas fa-shield-alt"></i></div>
                <div>
                    <h4>Secure Database</h4>
                    <p>All reports stored securely with role-based access control. GDPR compliant.</p>
                </div>
            </div>
            <div class="feature-card">
                <div class="feature-icon"><i class="fas fa-history"></i></div>
                <div>
                    <h4>Report History</h4>
                    <p>Track all past incidents and response times. Analytics for safety improvements.</p>
                </div>
            </div>
        </div>
    </div>
</section>



<!-- ==================== CTA SECTION ==================== -->
<section class="cta" id="about">
    <div class="container">
        <h2><i class="fas fa-hourglass-half"></i> Every Second Counts</h2>
        <p>The "golden hour" — first 60 minutes after an accident determine survival.<br>
        RoadAlert TZ cuts reporting time from minutes to seconds.</p>
        <div>
            <a href="register.php" class="btn btn-white">
                <i class="fas fa-rocket"></i> Get Started →
            </a>
            <a href="login.php" class="btn btn-outline-white">
                <i class="fas fa-sign-in-alt"></i> Sign In
            </a>
        </div>
    </div>
</section>

<!-- ================================================================ -->
<!-- ==================== FOOTER ==================================== -->
<!-- ================================================================ -->
<footer class="footer">
    <div class="footer-container">

        <div class="footer-grid">

            <!-- About Section -->
            <div class="footer-col">
                <h3 class="footer-brand">
                    <i class="fas fa-triangle-exclamation"></i>
                    RoadAlert TZ
                </h3>
                <p class="footer-desc">
                    Smart Road Reporting System enabling citizens to report road
                    accidents and connects emergency responders for faster assistance
                    across Tanzania.
                </p>
                <div class="footer-badge">
                    <i class="fas fa-shield-alt"></i> v2.0 · Operational
                </div>
            </div>

            <!-- Emergency Contacts -->
            <div class="footer-col">
                <h4><i class="fas fa-phone-alt"></i> Emergency Contacts</h4>
                <ul class="footer-list">
                    <li>
                        <i class="fas fa-phone"></i>
                        <span><strong>Emergency:</strong> 112</span>
                    </li>
                    <li>
                        <i class="fas fa-envelope"></i>
                        <span>support@roadalert.co.tz</span>
                    </li>
                    <li>
                        <i class="fas fa-map-marker-alt"></i>
                        <span>Arusha, Tanzania</span>
                    </li>
                    <li>
                        <i class="fas fa-clock"></i>
                        <span>24/7 Emergency Support</span>
                    </li>
                </ul>
            </div>

            <!-- Quick Links -->
            <div class="footer-col">
                <h4><i class="fas fa-link"></i> Quick Links</h4>
                <ul class="footer-links">
                    <li><a href="index.php"><i class="fas fa-home"></i> Home</a></li>
                    <li><a href="report.php"><i class="fas fa-car-crash"></i> Report Accident</a></li>
                    <li><a href="#"><i class="fas fa-info-circle"></i> About Us</a></li>
                    <li><a href="#"><i class="fas fa-user-shield"></i> Privacy Policy</a></li>
                    <li><a href="#"><i class="fas fa-file-contract"></i> Terms &amp; Conditions</a></li>
                </ul>
            </div>

            <!-- System Features -->
            <div class="footer-col">
                <h4><i class="fas fa-shield-alt"></i> System Features</h4>
                <ul class="footer-features">
                    <li><i class="fas fa-map-marked-alt"></i> GPS Location Tracking</li>
                    <li><i class="fas fa-hospital"></i> Hospital Dispatch</li>
                    <li><i class="fas fa-user-shield"></i> Secure Reporting</li>
                    <li><i class="fas fa-clock"></i> 24/7 Emergency Support</li>
                    <li><i class="fas fa-ambulance"></i> Auto-Assign Responders</li>
                </ul>
            </div>

        </div>

        <hr class="footer-divider">

        <!-- Bottom Bar -->
        <div class="footer-bottom">
            <p>
                <i class="far fa-copyright"></i>
                2026 <strong>RoadAlert TZ</strong>. All Rights Reserved.
            </p>
            <p class="footer-heart">
                <i class="fas fa-heart"></i>
                Making Tanzanian Roads Safer Together
            </p>
            <p class="footer-tech">
                Powered by PHP · MySQL · Leaflet Maps · GPS Technology
            </p>
        </div>

    </div>
</footer>

<!-- ================================================================ -->
<!-- ==================== JAVASCRIPT ================================ -->
<!-- ================================================================ -->
<script>
    // Toggle mobile menu function
    function toggleMenu() {
        const navLinks = document.querySelector('.nav-links');
        if (navLinks.style.display === 'flex') {
            navLinks.style.display = 'none';
        } else {
            navLinks.style.display = 'flex';
            navLinks.style.flexDirection = 'column';
            navLinks.style.position = 'absolute';
            navLinks.style.top = '70px';
            navLinks.style.left = '0';
            navLinks.style.right = '0';
            navLinks.style.background = 'white';
            navLinks.style.padding = '1.5rem';
            navLinks.style.boxShadow = '0 10px 20px rgba(0,0,0,0.1)';
            navLinks.style.zIndex = '999';
        }
    }

    // Close mobile menu when window is resized to desktop
    window.addEventListener('resize', function() {
        if (window.innerWidth > 768) {
            const navLinks = document.querySelector('.nav-links');
            navLinks.style.display = 'flex';
            navLinks.style.flexDirection = 'row';
            navLinks.style.position = 'relative';
            navLinks.style.top = 'auto';
            navLinks.style.padding = '0';
            navLinks.style.boxShadow = 'none';
        }
    });

    // Smooth scrolling for anchor links
    document.querySelectorAll('a[href^="#"]').forEach(anchor => {
        anchor.addEventListener('click', function (e) {
            e.preventDefault();
            const target = document.querySelector(this.getAttribute('href'));
            if (target) {
                target.scrollIntoView({
                    behavior: 'smooth',
                    block: 'start'
                });
            }
        });
    });

    // Add scroll effect to header
    window.addEventListener('scroll', function() {
        const header = document.querySelector('.header');
        if (window.scrollY > 50) {
            header.style.background = 'white';
            header.style.boxShadow = '0 4px 20px rgba(0,0,0,0.1)';
        } else {
            header.style.background = 'rgba(255,255,255,0.95)';
            header.style.boxShadow = '0 2px 20px rgba(0,0,0,0.08)';
        }
    });
</script>

</body>
</html>