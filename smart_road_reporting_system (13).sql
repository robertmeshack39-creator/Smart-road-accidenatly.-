-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Aug 04, 2026 at 09:14 AM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `smart_road_reporting_system`
--

-- --------------------------------------------------------

--
-- Table structure for table `accidents`
--

CREATE TABLE `accidents` (
  `id` int(11) NOT NULL,
  `reporter_name` varchar(100) NOT NULL,
  `reporter_phone` varchar(20) NOT NULL,
  `reporter_email` varchar(100) DEFAULT NULL,
  `accident_type` varchar(100) NOT NULL,
  `injuries` int(11) DEFAULT 0,
  `hazards` text DEFAULT NULL,
  `description` text DEFAULT NULL,
  `photo_path` varchar(255) DEFAULT NULL,
  `images` text DEFAULT NULL,
  `latitude` decimal(10,8) NOT NULL,
  `longitude` decimal(11,8) NOT NULL,
  `location_description` text DEFAULT NULL,
  `old_status` enum('pending','assigned','dispatched','resolved','false_alarm') DEFAULT 'pending',
  `police_status` enum('pending','assigned','dispatched','arrived','resolved','false_alarm') DEFAULT 'pending',
  `hospital_status` enum('pending','assigned','preparing','ambulance_dispatched','patient_received','treated','closed') DEFAULT 'pending',
  `assigned_police_id` int(11) DEFAULT NULL,
  `assigned_hospital_id` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `accidents`
--

INSERT INTO `accidents` (`id`, `reporter_name`, `reporter_phone`, `reporter_email`, `accident_type`, `injuries`, `hazards`, `description`, `photo_path`, `images`, `latitude`, `longitude`, `location_description`, `old_status`, `police_status`, `hospital_status`, `assigned_police_id`, `assigned_hospital_id`, `created_at`) VALUES
(50, 'Musoma nyajirari karabai', '+255663771367', NULL, 'Pedestrian Hit', 1, 'Fire', 'help', NULL, '[\"uploads\\/accidents\\/accident_20260723_134058_6a61fdca34738.png\"]', -3.41364287, 36.71220031, 'Njiro Road, Corridor, Themi, Arusha, Arusha Municipal, Arusha, Northern Zone, 23114, Tanzania', 'pending', 'assigned', 'assigned', 10, 10, '2026-07-23 11:40:58');

-- --------------------------------------------------------

--
-- Table structure for table `ambulances`
--

CREATE TABLE `ambulances` (
  `id` int(11) NOT NULL,
  `hospital_station_id` int(11) NOT NULL,
  `ambulance_name` varchar(100) NOT NULL,
  `plate_number` varchar(50) NOT NULL,
  `driver_name` varchar(100) NOT NULL,
  `driver_phone` varchar(20) NOT NULL,
  `driver_license` varchar(50) DEFAULT NULL,
  `model` varchar(100) DEFAULT NULL,
  `type` enum('basic','advanced','icu') DEFAULT 'basic',
  `capacity` int(11) DEFAULT 2,
  `status` enum('active','inactive','maintenance') DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `ambulances`
--

INSERT INTO `ambulances` (`id`, `hospital_station_id`, `ambulance_name`, `plate_number`, `driver_name`, `driver_phone`, `driver_license`, `model`, `type`, `capacity`, `status`, `created_at`) VALUES
(2, 10, 'ambulence-1', 'T444', 'samweli', '09765233131', 'D29481d', 'toyota', 'advanced', 9, 'active', '2026-07-02 10:21:55');

-- --------------------------------------------------------

--
-- Table structure for table `ambulance_assignments`
--

CREATE TABLE `ambulance_assignments` (
  `id` int(11) NOT NULL,
  `ambulance_id` int(11) NOT NULL,
  `accident_id` int(11) NOT NULL,
  `hospital_station_id` int(11) NOT NULL,
  `assigned_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `status` enum('on_mission','arrived','returned','completed') DEFAULT 'on_mission',
  `departure_time` timestamp NULL DEFAULT NULL,
  `arrival_time` timestamp NULL DEFAULT NULL,
  `return_time` timestamp NULL DEFAULT NULL,
  `notes` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `audit_logs`
--

CREATE TABLE `audit_logs` (
  `id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `username` varchar(100) DEFAULT NULL,
  `role` varchar(50) DEFAULT NULL,
  `action` varchar(255) DEFAULT NULL,
  `page` varchar(100) DEFAULT NULL,
  `ip_address` varchar(50) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `audit_logs`
--

INSERT INTO `audit_logs` (`id`, `user_id`, `username`, `role`, `action`, `page`, `ip_address`, `user_agent`, `created_at`) VALUES
(1, 1, 'System Administrator', 'admin', 'User logged out', 'logout.php', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36', '2026-07-08 11:05:49'),
(2, 4, 'monica siwa', 'hospital', 'User logged in successfully | Role: hospital', 'login.php', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36', '2026-07-08 11:06:00'),
(3, 4, 'monica siwa', 'hospital', 'User logged out', 'logout.php', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36', '2026-07-08 11:06:20'),
(4, 1, 'System Administrator', 'admin', 'User logged in successfully | Role: admin', 'login.php', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36', '2026-07-08 11:06:31'),
(5, 1, 'System Administrator', 'admin', 'User logged out', 'logout.php', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36', '2026-07-08 11:25:53'),
(6, 1, 'System Administrator', 'admin', 'User logged in successfully | Role: admin', 'login.php', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36', '2026-07-09 07:52:55'),
(7, 1, 'System Administrator', 'admin', 'Auto-assigned to nearest responders | ACC-0043 | Police: Njiro Police Station | Hospital: St. Elizabeth Hospital', 'accident_report.php', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36', '2026-07-09 07:54:09'),
(8, 1, 'System Administrator', 'admin', 'User logged out', 'logout.php', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36', '2026-07-09 07:54:22'),
(9, 1, 'System Administrator', 'admin', 'User logged in successfully | Role: admin', 'login.php', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-13 12:21:59'),
(10, 1, 'System Administrator', 'admin', 'Deleted accident report | ACC-0040 | Type: Rollover', 'accident_report.php', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-13 12:56:50'),
(11, 1, 'System Administrator', 'admin', 'Deleted accident report | ACC-0041 | Type: Pedestrian Hit', 'accident_report.php', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-13 12:56:53'),
(12, 1, 'System Administrator', 'admin', 'Deleted accident report | ACC-0042 | Type: Pedestrian Hit', 'accident_report.php', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-13 12:56:59'),
(13, 1, 'System Administrator', 'admin', 'Deleted accident report | ACC-0043 | Type: Multi-Vehicle', 'accident_report.php', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-13 12:57:01'),
(14, 1, 'System Administrator', 'admin', 'User logged out', 'logout.php', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-13 12:57:53'),
(15, NULL, 'System', 'Guest', 'Auto-assigned to nearest responders | ACC-0044 | Police: Njiro Police Station (4.4 km) | Hospital: St. Elizabeth Hospital (4.4 km)', 'report.php', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-13 12:58:29'),
(16, 2, 'Meshack Lazier', 'police', 'User logged in successfully | Role: police', 'login.php', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-13 12:58:49'),
(17, 2, 'Meshack Lazier', 'police', 'User logged out', 'logout.php', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-13 12:58:59'),
(18, 1, 'System Administrator', 'admin', 'User logged in successfully | Role: admin', 'login.php', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-13 12:59:07'),
(19, 1, 'System Administrator', 'admin', 'User logged out', 'logout.php', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-13 13:19:24'),
(20, 4, 'monica siwa', 'hospital', 'User logged in successfully | Role: hospital', 'login.php', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-13 13:19:31'),
(21, 4, 'monica siwa', 'hospital', 'User logged out', 'logout.php', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-13 13:19:39'),
(22, 2, 'Meshack Lazier', 'police', 'User logged in successfully | Role: police', 'login.php', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-13 13:19:49'),
(23, 2, 'Meshack Lazier', 'police', 'User logged out', 'logout.php', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-13 13:19:53'),
(24, 1, 'System Administrator', 'admin', 'User logged in successfully | Role: admin', 'login.php', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-13 13:19:58'),
(25, 1, 'System Administrator', 'admin', 'User logged out', 'logout.php', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-13 13:35:07'),
(26, 2, 'Meshack Lazier', 'police', 'User logged in successfully | Role: police', 'login.php', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-13 13:35:15'),
(27, 2, 'Meshack Lazier', 'police', 'Dispatched police to accident | ACC-0044 | Type: Rollover | Location: Stionaries, Njiro Road, Corridor, Themi, Arusha, Arusha Municipal, Arusha, Northern Zone, 23114, Tanzania', 'dispatch.php', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-13 13:35:38'),
(28, 2, 'Meshack Lazier', 'police', 'Resolved accident | ACC-0044 | Type: Rollover (was dispatched)', 'dispatch.php', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-13 13:35:44'),
(29, 2, 'Meshack Lazier', 'police', 'User logged out', 'logout.php', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-13 13:35:45'),
(30, 1, 'System Administrator', 'admin', 'User logged in successfully | Role: admin', 'login.php', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-13 13:35:57'),
(31, 1, 'System Administrator', 'admin', 'User logged out', 'logout.php', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-13 13:36:54'),
(32, 4, 'monica siwa', 'hospital', 'User logged in successfully | Role: hospital', 'login.php', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-13 13:37:01'),
(33, 4, 'monica siwa', 'hospital', 'Assigned ambulance to accident | Ambulance: ghvhjkj | Driver: samweli | ACC-0044 | Type: Rollover', 'ambulance.php', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-13 13:37:14'),
(34, 4, 'monica siwa', 'hospital', 'Updated ambulance mission status | Ambulance: ghvhjkj | ACC-0044 | Status: on_mission → returned', 'ambulance.php', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-13 13:37:23'),
(35, 4, 'monica siwa', 'hospital', 'Updated treatment | ACC-0044 | Type: Rollover | Patient: Musoma nyajirari karabai | Status: none → completed | Doctor: emmanuel', 'treatment.php', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-13 13:37:35'),
(36, 4, 'monica siwa', 'hospital', 'User logged out', 'logout.php', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-13 13:37:39'),
(37, 1, 'System Administrator', 'admin', 'User logged in successfully | Role: admin', 'login.php', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-13 13:37:45'),
(38, 1, 'System Administrator', 'admin', 'Auto-assigned to nearest responders | ACC-0045 | Police: Njiro Police Station (470.81 km) | Hospital: St. Elizabeth Hospital (470.81 km)', 'admin_report.php', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-13 13:57:42'),
(39, 1, 'System Administrator', 'admin', 'Created accident report | ACC-0045 | Type: Rollover | Injuries: 2', 'admin_report.php', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-13 13:57:42'),
(40, 1, 'System Administrator', 'admin', 'User logged out', 'logout.php', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-13 15:31:24'),
(41, 4, 'monica siwa', 'hospital', 'User logged in successfully | Role: hospital', 'login.php', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-13 15:31:29'),
(42, 4, 'monica siwa', 'hospital', 'Updated hospital status from assigned to treated | ACC-0045 | Type: Rollover', 'emergencies.php', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-13 15:31:39'),
(43, 4, 'monica siwa', 'hospital', 'Updated treatment | ACC-0045 | Type: Rollover | Patient: Robert Meshack | Status: none → completed | Doctor: munila', 'treatment.php', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-13 15:31:53'),
(44, 4, 'monica siwa', 'hospital', 'User logged out', 'logout.php', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-13 15:31:56'),
(45, 2, 'Meshack Lazier', 'police', 'User logged in successfully | Role: police', 'login.php', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-13 15:32:10'),
(46, 2, 'Meshack Lazier', 'police', 'Dispatched police to accident | ACC-0045 | Type: Rollover | Location: Morogoro Road, Kibo, Ubungo, Ubungo Municipal, Dar es Salaam, Coastal Zone, 21493, Tanzania', 'dispatch.php', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-13 15:32:18'),
(47, 2, 'Meshack Lazier', 'police', 'Resolved accident | ACC-0045 | Type: Rollover (was dispatched)', 'dispatch.php', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-13 15:32:22'),
(48, 2, 'Meshack Lazier', 'police', 'User logged out', 'logout.php', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-13 15:32:23'),
(49, 1, 'System Administrator', 'admin', 'User logged in successfully | Role: admin', 'login.php', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-13 15:32:28'),
(50, 1, 'System Administrator', 'admin', 'User logged in successfully | Role: admin', 'login.php', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-14 05:28:29'),
(51, 1, 'System Administrator', 'admin', 'User logged out', 'logout.php', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-14 05:39:28'),
(52, NULL, 'System', 'Guest', 'Auto-assigned to nearest responders | ACC-0046 | Police: Njiro Police Station (4.4 km) | Hospital: St. Elizabeth Hospital (4.4 km)', 'report.php', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-14 05:43:06'),
(53, NULL, 'System', 'Guest', 'Failed login attempt - User not found | Email: damary@gmail.com', 'login.php', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-14 05:43:21'),
(54, 4, 'monica siwa', 'hospital', 'User logged in successfully | Role: hospital', 'login.php', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-14 05:43:30'),
(55, 4, 'monica siwa', 'hospital', 'User logged out', 'logout.php', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-14 05:45:08'),
(56, 2, 'Meshack Lazier', 'police', 'User logged in successfully | Role: police', 'login.php', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-14 05:45:14'),
(57, 2, 'Meshack Lazier', 'police', 'User logged out', 'logout.php', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-14 05:46:39'),
(58, 1, 'System Administrator', 'admin', 'User logged in successfully | Role: admin', 'login.php', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-14 05:46:52'),
(59, 1, 'System Administrator', 'admin', 'User logged out', 'logout.php', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-14 06:22:54'),
(60, 4, 'monica siwa', 'hospital', 'User logged in successfully | Role: hospital', 'login.php', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-14 06:23:00'),
(61, 4, 'monica siwa', 'hospital', 'Assigned ambulance to accident | Ambulance: ghvhjkj | Driver: samweli | ACC-0046 | Type: Multi-Vehicle', 'ambulance.php', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-14 06:23:11'),
(62, 4, 'monica siwa', 'hospital', 'Updated ambulance mission status | Ambulance: ghvhjkj | ACC-0046 | Status: on_mission → arrived', 'ambulance.php', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-14 06:23:19'),
(63, 4, 'monica siwa', 'hospital', 'Updated ambulance mission status | Ambulance: ghvhjkj | ACC-0046 | Status: arrived → returned', 'ambulance.php', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-14 06:23:26'),
(64, 4, 'monica siwa', 'hospital', 'Updated treatment | ACC-0046 | Type: Multi-Vehicle | Patient: Emmanuel | Status: none → completed | Doctor: emmanuel', 'treatment.php', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-14 06:23:38'),
(65, 4, 'monica siwa', 'hospital', 'User logged out', 'logout.php', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-14 06:23:40'),
(66, 1, 'System Administrator', 'admin', 'User logged in successfully | Role: admin', 'login.php', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-14 06:23:47'),
(67, 1, 'System Administrator', 'admin', 'User logged out', 'logout.php', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-14 06:26:20'),
(68, 1, 'System Administrator', 'admin', 'User logged in successfully | Role: admin', 'login.php', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-14 06:34:22'),
(69, 1, 'System Administrator', 'admin', 'User logged out', 'logout.php', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-14 06:40:39'),
(70, 4, 'monica siwa', 'hospital', 'User logged in successfully | Role: hospital', 'login.php', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-14 06:40:48'),
(71, 4, 'monica siwa', 'hospital', 'Added patient to treatment | ACC-0046 | Patient: emmanel', 'save_patient.php', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-14 08:01:35'),
(72, 4, 'monica siwa', 'hospital', 'Updated accident hospital status | ACC-0046 | Status: patient_received', 'update_accident_status.php', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-14 08:02:36'),
(73, 4, 'monica siwa', 'hospital', 'Added patient to treatment | ACC-0046 | Patient: mwajuma ', 'save_patient.php', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-14 08:03:37'),
(74, 4, 'monica siwa', 'hospital', 'Updated accident hospital status | ACC-0046 | Status: treated', 'update_accident_status.php', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-14 08:04:25'),
(75, 4, 'monica siwa', 'hospital', 'Updated patient treatment | ACC-0046 | Patient: mwajuma  | Status: completed', 'save_patient.php', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-14 08:05:05'),
(76, 4, 'monica siwa', 'hospital', 'Updated patient treatment | ACC-0046 | Patient: emmanel | Status: completed', 'save_patient.php', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-14 08:05:18'),
(77, 4, 'monica siwa', 'hospital', 'Updated patient treatment | ACC-0046 | Patient: mwajuma  | Status: pending', 'save_patient.php', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-14 08:07:01'),
(78, 4, 'monica siwa', 'hospital', 'Updated hospital status from treated to assigned | ACC-0045 | Type: Rollover', 'emergencies.php', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-14 08:07:57'),
(79, 4, 'monica siwa', 'hospital', 'Updated accident hospital status | ACC-0045 | Status: treated', 'update_accident_status.php', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-14 08:08:27'),
(80, 4, 'monica siwa', 'hospital', 'Updated hospital status from treated to assigned | ACC-0045 | Type: Rollover', 'emergencies.php', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-14 08:12:54'),
(81, 4, 'monica siwa', 'hospital', 'Assigned ambulance to accident | Ambulance: ambulence-1 | Driver: samweli | ACC-0045 | Type: Rollover', 'ambulance.php', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-14 08:13:16'),
(82, 4, 'monica siwa', 'hospital', 'Updated ambulance mission status | Ambulance: ambulence-1 | ACC-0045 | Status: on_mission → arrived', 'ambulance.php', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-14 08:13:23'),
(83, 4, 'monica siwa', 'hospital', 'Updated ambulance mission status | Ambulance: ambulence-1 | ACC-0045 | Status: arrived → returned', 'ambulance.php', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-14 08:13:26'),
(84, 4, 'monica siwa', 'hospital', 'User logged out', 'logout.php', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-14 08:14:17'),
(85, 1, 'System Administrator', 'admin', 'User logged in successfully | Role: admin', 'login.php', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-17 05:38:32'),
(86, 1, 'System Administrator', 'admin', 'User logged out', 'logout.php', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-17 06:12:00'),
(87, 1, 'System Administrator', 'admin', 'User logged in successfully | Role: admin', 'login.php', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-20 04:16:39'),
(88, 1, 'System Administrator', 'admin', 'Deleted accident report | ACC-0044 | Type: Rollover', 'accident_report.php', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-20 04:16:57'),
(89, 1, 'System Administrator', 'admin', 'Deleted accident report | ACC-0045 | Type: Rollover', 'accident_report.php', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-20 04:17:00'),
(90, 1, 'System Administrator', 'admin', 'Deleted accident report | ACC-0046 | Type: Multi-Vehicle', 'accident_report.php', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-20 04:17:03'),
(91, 1, 'System Administrator', 'admin', 'Updated Hospital | Hospital: Arusha Regional Hospital | Location: Arusha City | Status: Inactive', 'manage_hospital.php', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-20 04:17:33'),
(92, 1, 'System Administrator', 'admin', 'Updated Police station | Police: Arusha Traffic Police | Location: Sokoine Road, Arusha | Status: Inactive', 'manage_police.php', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-20 04:17:50'),
(93, 1, 'System Administrator', 'admin', 'User logged out', 'logout.php', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-20 04:23:34'),
(94, 2, 'Meshack Lazier', 'police', 'User logged in successfully | Role: police', 'login.php', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-20 04:23:42'),
(95, 2, 'Meshack Lazier', 'police', 'User logged out', 'logout.php', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-20 04:24:16'),
(96, 4, 'monica siwa', 'hospital', 'User logged in successfully | Role: hospital', 'login.php', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-20 04:24:25'),
(97, 4, 'monica siwa', 'hospital', 'Updated ambulance status | Ambulance: ambulence-1 | Status: active → active', 'ambulance.php', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-20 04:25:04'),
(98, 4, 'monica siwa', 'hospital', 'User logged out', 'logout.php', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-20 04:31:25'),
(99, NULL, 'System', 'Guest', 'Auto-assigned to nearest responders | ACC-0047 | Police: Njiro Police Station (4.4 km) | Hospital: St. Elizabeth Hospital (4.4 km)', 'report.php', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-20 04:34:06'),
(100, 1, 'System Administrator', 'admin', 'User logged in successfully | Role: admin', 'login.php', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-20 04:34:20'),
(101, 1, 'System Administrator', 'admin', 'User logged out', 'logout.php', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-20 04:35:24'),
(102, 4, 'monica siwa', 'hospital', 'User logged in successfully | Role: hospital', 'login.php', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-20 04:35:28'),
(103, 4, 'monica siwa', 'hospital', 'Assigned ambulance to accident | Ambulance: ambulence-1 | Driver: samweli | ACC-0047 | Type: Pedestrian Hit', 'ambulance.php', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-20 04:36:30'),
(104, 4, 'monica siwa', 'hospital', 'Updated ambulance mission status | Ambulance: ambulence-1 | ACC-0047 | Status: on_mission → arrived', 'ambulance.php', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-20 04:36:47'),
(105, 4, 'monica siwa', 'hospital', 'Updated ambulance mission status | Ambulance: ambulence-1 | ACC-0047 | Status: arrived → returned', 'ambulance.php', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-20 04:37:06'),
(106, 4, 'monica siwa', 'hospital', 'Added patient to treatment | ACC-0047 | Patient: mwajuma ', 'save_patient.php', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-20 04:38:06'),
(107, 4, 'monica siwa', 'hospital', 'Updated accident hospital status | ACC-0047 | Status: treated', 'update_accident_status.php', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-20 04:38:24'),
(108, 4, 'monica siwa', 'hospital', 'User logged out', 'logout.php', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-20 04:38:39'),
(109, 1, 'System Administrator', 'admin', 'User logged in successfully | Role: admin', 'login.php', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-20 04:38:45'),
(110, 1, 'System Administrator', 'admin', 'User logged out', 'logout.php', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-20 04:39:16'),
(111, 2, 'Meshack Lazier', 'police', 'User logged in successfully | Role: police', 'login.php', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-20 04:39:25'),
(112, 2, 'Meshack Lazier', 'police', 'Dispatched police to accident | ACC-0047 | Type: Pedestrian Hit | Location: ', 'dispatch.php', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-20 04:40:25'),
(113, 2, 'Meshack Lazier', 'police', 'Resolved accident | ACC-0047 | Type: Pedestrian Hit (was dispatched)', 'dispatch.php', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-20 04:40:45'),
(114, 2, 'Meshack Lazier', 'police', 'User logged out', 'logout.php', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-20 04:41:07'),
(115, 1, 'System Administrator', 'admin', 'User logged in successfully | Role: admin', 'login.php', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-20 04:41:12'),
(116, 1, 'System Administrator', 'admin', 'User logged out', 'logout.php', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-20 04:42:21'),
(117, 1, 'System Administrator', 'admin', 'User logged in successfully | Role: admin', 'login.php', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-20 17:05:34'),
(118, 1, 'System Administrator', 'admin', 'User logged in successfully | Role: admin', 'login.php', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-21 04:26:50'),
(119, 1, 'System Administrator', 'admin', 'User logged out', 'logout.php', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-21 04:30:15'),
(120, 2, 'Meshack Lazier', 'police', 'User logged in successfully | Role: police', 'login.php', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-21 04:30:23'),
(121, 2, 'Meshack Lazier', 'police', 'User logged out', 'logout.php', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-21 04:32:22'),
(122, 4, 'monica siwa', 'hospital', 'User logged in successfully | Role: hospital', 'login.php', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-21 04:32:29'),
(123, 4, 'monica siwa', 'hospital', 'User logged out', 'logout.php', '::1', 'Mozilla/5.0 (Linux; Android 15; Pixel 9) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Mobile Safari/537.36', '2026-07-21 04:34:05'),
(124, 4, 'monica siwa', 'hospital', 'User logged in successfully | Role: hospital', 'login.php', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-21 04:34:13'),
(125, NULL, 'System', 'Guest', 'Auto-assigned to nearest responders | ACC-0048 | Police: Njiro Police Station (4.4 km) | Hospital: St. Elizabeth Hospital (4.4 km)', 'report.php', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-22 17:20:48'),
(126, 1, 'System Administrator', 'admin', 'User logged in successfully | Role: admin', 'login.php', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-22 17:22:20'),
(127, 1, 'System Administrator', 'admin', 'User logged out', 'logout.php', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-22 17:28:18'),
(128, 4, 'monica siwa', 'hospital', 'User logged in successfully | Role: hospital', 'login.php', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-22 17:28:23'),
(129, 4, 'monica siwa', 'hospital', 'Assigned ambulance to accident | Ambulance: ambulence-1 | Driver: samweli | ACC-0048 | Type: Multi-Vehicle', 'ambulance.php', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-22 17:30:11'),
(130, 4, 'monica siwa', 'hospital', 'Updated ambulance mission status | Ambulance: ambulence-1 | ACC-0048 | Status: on_mission → arrived', 'ambulance.php', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-22 17:30:46'),
(131, 4, 'monica siwa', 'hospital', 'Updated ambulance mission status | Ambulance: ambulence-1 | ACC-0048 | Status: arrived → returned', 'ambulance.php', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-22 17:30:50'),
(132, 4, 'monica siwa', 'hospital', 'Updated accident hospital status | ACC-0048 | Status: treated', 'update_accident_status.php', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-22 17:32:42'),
(133, 4, 'monica siwa', 'hospital', 'User logged out', 'logout.php', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-22 17:33:36'),
(134, 2, 'Meshack Lazier', 'police', 'User logged in successfully | Role: police', 'login.php', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-22 17:33:47'),
(135, 2, 'Meshack Lazier', 'police', 'Dispatched police to accident | ACC-0048 | Type: Multi-Vehicle | Location: ', 'dispatch.php', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-22 17:37:36'),
(136, 2, 'Meshack Lazier', 'police', 'Resolved accident | ACC-0048 | Type: Multi-Vehicle (was dispatched)', 'dispatch.php', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-22 17:37:47'),
(137, 2, 'Meshack Lazier', 'police', 'User logged out', 'logout.php', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-22 17:38:03'),
(138, 1, 'System Administrator', 'admin', 'User logged in successfully | Role: admin', 'login.php', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-23 08:24:56'),
(139, 1, 'System Administrator', 'admin', 'Deleted accident report | ACC-0048 | Type: Multi-Vehicle', 'accident_report.php', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-23 08:25:07'),
(140, 1, 'System Administrator', 'admin', 'User logged out', 'logout.php', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-23 08:25:56'),
(141, NULL, 'System', 'Guest', 'Auto-assigned to nearest responders | ACC-0049 | Police: Njiro Police Station (4.27 km) | Hospital: St. Elizabeth Hospital (4.27 km)', 'report.php', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-23 08:26:53'),
(142, 1, 'System Administrator', 'admin', 'User logged in successfully | Role: admin', 'login.php', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-23 08:27:23'),
(143, 1, 'System Administrator', 'admin', 'Deleted accident report | ACC-0047 | Type: Pedestrian Hit', 'accident_report.php', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-23 08:27:51'),
(144, 1, 'System Administrator', 'admin', 'User logged out', 'logout.php', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-23 08:28:03'),
(145, 2, 'Meshack Lazier', 'police', 'User logged in successfully | Role: police', 'login.php', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-23 08:28:11'),
(146, 2, 'Meshack Lazier', 'police', 'Dispatched police to accident | ACC-0049 | Type: Pedestrian Hit | Location: cafteria, Njiro Road, Corridor, Themi, Arusha, Arusha Municipal, Arusha, Northern Zone, 23114, Tanzania', 'dispatch.php', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-23 08:31:41'),
(147, 2, 'Meshack Lazier', 'police', 'Resolved accident | ACC-0049 | Type: Pedestrian Hit (was dispatched)', 'dispatch.php', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-23 08:31:50'),
(148, 2, 'Meshack Lazier', 'police', 'User logged out', 'logout.php', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-23 08:31:55'),
(149, 4, 'monica siwa', 'hospital', 'User logged in successfully | Role: hospital', 'login.php', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-23 08:32:02'),
(150, 4, 'monica siwa', 'hospital', 'Assigned ambulance to accident | Ambulance: ambulence-1 | Driver: samweli | ACC-0049 | Type: Pedestrian Hit', 'ambulance.php', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-23 08:33:51'),
(151, 4, 'monica siwa', 'hospital', 'Updated ambulance mission status | Ambulance: ambulence-1 | ACC-0049 | Status: on_mission → returned', 'ambulance.php', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-23 08:34:04'),
(152, 4, 'monica siwa', 'hospital', 'Added patient to treatment | ACC-0049 | Patient: emmanel laizer', 'save_patient.php', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-23 08:39:28'),
(153, 4, 'monica siwa', 'hospital', 'Updated patient treatment | ACC-0049 | Patient: emmanel laizer | Status: completed', 'save_patient.php', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-23 08:39:45'),
(154, 4, 'monica siwa', 'hospital', 'Updated accident hospital status | ACC-0049 | Status: treated', 'update_accident_status.php', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-23 08:39:54'),
(155, 4, 'monica siwa', 'hospital', 'Updated patient treatment | ACC-0049 | Patient: emmanel laizer | Status: completed', 'save_patient.php', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-23 08:42:13'),
(156, 4, 'monica siwa', 'hospital', 'User logged out', 'logout.php', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-23 08:42:17'),
(157, 2, 'Meshack Lazier', 'police', 'User logged in successfully | Role: police', 'login.php', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-23 08:42:23'),
(158, 2, 'Meshack Lazier', 'police', 'User logged out', 'logout.php', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-23 08:42:31'),
(159, 1, 'System Administrator', 'admin', 'User logged in successfully | Role: admin', 'login.php', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-23 08:42:37'),
(160, 1, 'System Administrator', 'admin', 'Deleted accident report | ACC-0049 | Type: Pedestrian Hit', 'accident_report.php', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-23 08:42:48'),
(161, 1, 'System Administrator', 'admin', 'User logged out', 'logout.php', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-23 08:43:14'),
(162, 1, 'System Administrator', 'admin', 'User logged in successfully | Role: admin', 'login.php', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-23 10:56:03'),
(163, 1, 'System Administrator', 'admin', 'User logged out', 'logout.php', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-23 10:56:13'),
(164, 4, 'monica siwa', 'hospital', 'User logged in successfully | Role: hospital', 'login.php', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-23 10:56:20'),
(165, 4, 'monica siwa', 'hospital', 'User logged out', 'logout.php', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-23 10:56:23'),
(166, 2, 'Meshack Lazier', 'police', 'User logged in successfully | Role: police', 'login.php', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-23 10:56:32'),
(167, 2, 'Meshack Lazier', 'police', 'User logged out', 'logout.php', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-23 10:56:35'),
(168, NULL, 'System', 'Guest', 'Auto-assigned to nearest responders | ACC-0050 | Police: Njiro Police Station (4.48 km) | Hospital: St. Elizabeth Hospital (4.48 km)', 'report.php', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-23 11:40:58'),
(169, 2, 'Meshack Lazier', 'police', 'User logged in successfully | Role: police', 'login.php', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-23 11:41:50'),
(170, 2, 'Meshack Lazier', 'police', 'User logged out', 'logout.php', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-23 11:41:56'),
(171, 1, 'System Administrator', 'admin', 'User logged in successfully | Role: admin', 'login.php', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-23 11:42:07'),
(172, 1, 'System Administrator', 'admin', 'User logged out', 'logout.php', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-23 11:43:27'),
(173, 2, 'Meshack Lazier', 'police', 'User logged in successfully | Role: police', 'login.php', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-23 11:43:42');

-- --------------------------------------------------------

--
-- Table structure for table `hospital_stations`
--

CREATE TABLE `hospital_stations` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `location` varchar(200) DEFAULT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `email` varchar(100) DEFAULT NULL,
  `region` varchar(100) DEFAULT NULL,
  `latitude` decimal(10,8) DEFAULT NULL,
  `longitude` decimal(11,8) DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `hospital_stations`
--

INSERT INTO `hospital_stations` (`id`, `name`, `location`, `phone`, `email`, `region`, `latitude`, `longitude`, `is_active`, `created_at`, `updated_at`) VALUES
(9, 'Arusha Regional Hospital', 'Arusha City', '+255712345681', 'arusha@moh.go.tz', 'Arusha', -3.36670000, 36.68330000, 0, '2026-06-24 08:33:05', '2026-07-20 04:17:33'),
(10, 'St. Elizabeth Hospital', 'Njiro, Arusha', '+255712345682', 'info@stelizabeth.or.tz', 'Arusha', -3.38690000, 36.68200000, 1, '2026-06-24 08:33:05', '2026-06-24 08:33:05'),
(11, 'Arusha Lutheran Medical Centre', 'Arusha', '+255712345683', 'info@almc.or.tz', 'Arusha', -3.37670000, 36.68530000, 1, '2026-06-24 08:33:05', '2026-06-24 08:33:05');

-- --------------------------------------------------------

--
-- Table structure for table `police_stations`
--

CREATE TABLE `police_stations` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `location` varchar(200) DEFAULT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `email` varchar(100) DEFAULT NULL,
  `region` varchar(100) DEFAULT NULL,
  `latitude` decimal(10,8) DEFAULT NULL,
  `longitude` decimal(11,8) DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `police_stations`
--

INSERT INTO `police_stations` (`id`, `name`, `location`, `phone`, `email`, `region`, `latitude`, `longitude`, `is_active`, `created_at`, `updated_at`) VALUES
(9, 'Arusha Central Police', 'Arusha City Center', '+255712345678', 'arusha@police.go.tz', 'Arusha', -3.36670000, 36.68330000, 1, '2026-06-24 08:32:47', '2026-06-25 13:44:08'),
(10, 'Njiro Police Station', 'Njiro, Arusha', '+255712345679', 'njiro@police.go.tz', 'Arusha', -3.38690000, 36.68200000, 1, '2026-06-24 08:32:47', '2026-06-24 08:32:47'),
(11, 'Arusha Traffic Police', 'Sokoine Road, Arusha', '+255712345680', 'traffic.arusha@police.go.tz', 'Arusha', -3.37670000, 36.68530000, 0, '2026-06-24 08:32:47', '2026-07-20 04:17:50');

-- --------------------------------------------------------

--
-- Table structure for table `treatments`
--

CREATE TABLE `treatments` (
  `id` int(11) NOT NULL,
  `accident_id` int(11) NOT NULL,
  `hospital_station_id` int(11) NOT NULL,
  `patient_name` varchar(100) DEFAULT NULL,
  `patient_age` int(11) DEFAULT NULL,
  `patient_gender` enum('male','female','other') DEFAULT NULL,
  `blood_pressure` varchar(20) DEFAULT NULL COMMENT 'e.g., 120/80',
  `heart_rate` int(11) DEFAULT NULL COMMENT 'Beats per minute',
  `temperature` decimal(4,1) DEFAULT NULL COMMENT 'Celsius',
  `oxygen_saturation` int(11) DEFAULT NULL COMMENT 'Percentage',
  `respiratory_rate` int(11) DEFAULT NULL COMMENT 'Breaths per minute',
  `blood_sugar` decimal(5,2) DEFAULT NULL COMMENT 'mmol/L',
  `initial_diagnosis` text DEFAULT NULL,
  `final_diagnosis` text DEFAULT NULL,
  `symptoms` text DEFAULT NULL,
  `treatment_given` text DEFAULT NULL,
  `medications` text DEFAULT NULL,
  `procedures` text DEFAULT NULL,
  `allergies` text DEFAULT NULL,
  `doctor_name` varchar(100) DEFAULT NULL,
  `doctor_id` int(11) DEFAULT NULL,
  `nurse_name` varchar(100) DEFAULT NULL,
  `nurse_id` int(11) DEFAULT NULL,
  `treatment_status` enum('pending','in_progress','completed','discharged','referred','deceased') DEFAULT 'pending',
  `notes` text DEFAULT NULL,
  `follow_up_date` date DEFAULT NULL,
  `discharge_date` date DEFAULT NULL,
  `referral_hospital` varchar(200) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `patient_details` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'Multiple patients details' CHECK (json_valid(`patient_details`)),
  `total_patients` int(11) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `treatment_patients`
--

CREATE TABLE `treatment_patients` (
  `id` int(11) NOT NULL,
  `accident_id` int(11) NOT NULL,
  `hospital_station_id` int(11) NOT NULL,
  `patient_name` varchar(100) DEFAULT NULL,
  `age` int(11) DEFAULT NULL,
  `gender` enum('male','female','other') DEFAULT 'male',
  `blood_pressure` varchar(20) DEFAULT NULL,
  `temperature` decimal(4,1) DEFAULT NULL,
  `diagnosis` text DEFAULT NULL,
  `treatment_given` text DEFAULT NULL,
  `medications` text DEFAULT NULL,
  `doctor_name` varchar(100) DEFAULT NULL,
  `treatment_status` enum('pending','in_progress','completed','discharged','referred','deceased') DEFAULT 'pending',
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `full_name` varchar(100) DEFAULT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `email` varchar(100) DEFAULT NULL,
  `password` varchar(255) DEFAULT NULL,
  `role` enum('admin','police','hospital','citizen') DEFAULT 'citizen',
  `police_station_id` int(11) DEFAULT NULL,
  `hospital_station_id` int(11) DEFAULT NULL,
  `approval_status` enum('pending','approved','rejected') DEFAULT 'pending',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `full_name`, `phone`, `email`, `password`, `role`, `police_station_id`, `hospital_station_id`, `approval_status`, `created_at`) VALUES
(1, 'System Administrator', '0712345678', 'robertmeshack39@gmail.com', '$2a$12$SYm2eKaZFVzURxlfua/pjuj5zenJnZMQPFzDWT/ZrhzUXpcdywRbm', 'admin', NULL, NULL, 'approved', '2026-05-30 12:15:22'),
(2, 'Meshack Lazier', '0685636362', 'meshacklaizer@gmail.com', '$2y$10$1BrF0ieAeOL5F1Cj3W2A9eBLBZdGaU1Qt0wJFbYuMH9lawoefIUMm', 'police', 10, NULL, 'approved', '2026-05-30 14:10:04'),
(4, 'monica siwa', '0743311455', 'monicasiwa@gmail.com', '$2y$10$5CWzZpoM13Sh0.mn0k08xue9HFJ992BK248SeGbM.RMe4q2UrvmG2', 'hospital', NULL, 10, 'approved', '2026-06-30 04:43:19'),
(5, 'Musoma nyajirari karabai', '+255663771367', 'mpoma@gmail.com', '$2y$10$HCdmQ3aQRE7bmkG2UkVbweNlViOe1DltDsbH1x6Ck4HbWpYA8lKgy', 'police', 9, NULL, 'approved', '2026-07-04 05:08:11');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `accidents`
--
ALTER TABLE `accidents`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `ambulances`
--
ALTER TABLE `ambulances`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `plate_number` (`plate_number`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_hospital` (`hospital_station_id`);

--
-- Indexes for table `ambulance_assignments`
--
ALTER TABLE `ambulance_assignments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `hospital_station_id` (`hospital_station_id`),
  ADD KEY `idx_ambulance` (`ambulance_id`),
  ADD KEY `idx_accident` (`accident_id`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_ambulance_status` (`ambulance_id`,`status`);

--
-- Indexes for table `audit_logs`
--
ALTER TABLE `audit_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_user_id` (`user_id`),
  ADD KEY `idx_created_at` (`created_at`),
  ADD KEY `idx_action` (`action`),
  ADD KEY `idx_role` (`role`);

--
-- Indexes for table `hospital_stations`
--
ALTER TABLE `hospital_stations`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `police_stations`
--
ALTER TABLE `police_stations`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `treatments`
--
ALTER TABLE `treatments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_accident` (`accident_id`),
  ADD KEY `idx_status` (`treatment_status`),
  ADD KEY `idx_hospital` (`hospital_station_id`),
  ADD KEY `idx_doctor` (`doctor_id`),
  ADD KEY `idx_nurse` (`nurse_id`);

--
-- Indexes for table `treatment_patients`
--
ALTER TABLE `treatment_patients`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_accident` (`accident_id`),
  ADD KEY `idx_hospital` (`hospital_station_id`),
  ADD KEY `idx_status` (`treatment_status`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `email` (`email`),
  ADD KEY `idx_police_station` (`police_station_id`),
  ADD KEY `idx_hospital_station` (`hospital_station_id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `accidents`
--
ALTER TABLE `accidents`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=51;

--
-- AUTO_INCREMENT for table `ambulances`
--
ALTER TABLE `ambulances`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `ambulance_assignments`
--
ALTER TABLE `ambulance_assignments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=19;

--
-- AUTO_INCREMENT for table `audit_logs`
--
ALTER TABLE `audit_logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=174;

--
-- AUTO_INCREMENT for table `hospital_stations`
--
ALTER TABLE `hospital_stations`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=12;

--
-- AUTO_INCREMENT for table `police_stations`
--
ALTER TABLE `police_stations`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=12;

--
-- AUTO_INCREMENT for table `treatments`
--
ALTER TABLE `treatments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=13;

--
-- AUTO_INCREMENT for table `treatment_patients`
--
ALTER TABLE `treatment_patients`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `ambulances`
--
ALTER TABLE `ambulances`
  ADD CONSTRAINT `ambulances_ibfk_1` FOREIGN KEY (`hospital_station_id`) REFERENCES `hospital_stations` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `ambulance_assignments`
--
ALTER TABLE `ambulance_assignments`
  ADD CONSTRAINT `ambulance_assignments_ibfk_1` FOREIGN KEY (`ambulance_id`) REFERENCES `ambulances` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `ambulance_assignments_ibfk_2` FOREIGN KEY (`accident_id`) REFERENCES `accidents` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `ambulance_assignments_ibfk_3` FOREIGN KEY (`hospital_station_id`) REFERENCES `hospital_stations` (`id`);

--
-- Constraints for table `treatments`
--
ALTER TABLE `treatments`
  ADD CONSTRAINT `treatments_ibfk_1` FOREIGN KEY (`accident_id`) REFERENCES `accidents` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `treatments_ibfk_2` FOREIGN KEY (`hospital_station_id`) REFERENCES `hospital_stations` (`id`);

--
-- Constraints for table `treatment_patients`
--
ALTER TABLE `treatment_patients`
  ADD CONSTRAINT `treatment_patients_ibfk_1` FOREIGN KEY (`accident_id`) REFERENCES `accidents` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `treatment_patients_ibfk_2` FOREIGN KEY (`hospital_station_id`) REFERENCES `hospital_stations` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `users`
--
ALTER TABLE `users`
  ADD CONSTRAINT `fk_user_hospital_station` FOREIGN KEY (`hospital_station_id`) REFERENCES `hospital_stations` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_user_police_station` FOREIGN KEY (`police_station_id`) REFERENCES `police_stations` (`id`) ON DELETE SET NULL;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
