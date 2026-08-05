<?php
/**
 * Single Unified Public Header Template
 * High-Q Solid Academy Biometric Attendance System
 */
require_once __DIR__ . '/db.php';

$currentPage = basename($_SERVER['SCRIPT_NAME'] ?? 'index.php');
$main_site_url = getenv('MAIN_SITE_URL') ?: 'https://highqsolidacademy.com';
if (isset($_SERVER['HTTP_HOST']) && strpos($_SERVER['HTTP_HOST'], 'localhost') !== false) {
    $main_site_url = '/HIGH-Q/public/';
}
?>
<!DOCTYPE html>
<html class="light" lang="en">
<head>
    <meta charset="utf-8"/>
    <meta content="width=device-width, initial-scale=1.0" name="viewport"/>
    <title>High-Q Solid Academy | Biometric Attendance System</title>
    <link rel="icon" type="image/png" href="icon.png">
    <link rel="apple-touch-icon" sizes="180x180" href="icon.png">
    <script src="https://cdn.tailwindcss.com?plugins=forms,container-queries"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet"/>
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet"/>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script>
        const HighQSwal = Swal.mixin({
            customClass: {
                confirmButton: 'px-5 py-2.5 bg-primary text-white font-semibold rounded-lg text-sm mx-1 shadow hover:opacity-90 transition-colors',
                cancelButton: 'px-5 py-2.5 border border-border-subtle text-on-surface font-semibold rounded-lg text-sm mx-1 hover:bg-surface-container transition-colors',
                popup: 'rounded-2xl border border-border-subtle font-body-md shadow-2xl p-6',
                title: 'font-headline-lg font-bold text-on-surface text-xl',
                htmlContainer: 'text-on-surface-variant text-sm'
            },
            buttonsStyling: false
        });
    </script>
    <script id="tailwind-config">
        tailwind.config = {
            darkMode: "class",
            theme: {
                extend: {
                    colors: {
                        "primary": "#00457b",
                        "secondary": "#15629a",
                        "background": "#f6faff",
                        "surface": "#f6faff",
                        "on-surface": "#171c20",
                        "on-surface-variant": "#424750",
                        "surface-container-low": "#eff4ff",
                        "surface-container-lowest": "#ffffff",
                        "border-subtle": "#E2E8F0"
                    }
                }
            }
        }
    </script>
</head>
<body class="bg-[#f6faff] text-[#171c20] font-inter min-h-screen flex flex-col justify-between">

<!-- Header Navigation Bar -->
<header id="publicTopBar" class="sticky w-full z-40 bg-[#f6faff]/95 dark:bg-[#171c20]/95 backdrop-blur-xl shadow-[0_16px_36px_rgba(24,39,75,0.06)]">
    <div class="flex justify-between items-center px-4 md:px-8 py-4 max-w-[1440px] mx-auto">
        <div class="flex items-center gap-3">
            <img class="h-8 w-8 object-contain rounded" src="logo.png" alt="High-Q Logo" onerror="this.src='icon.png'">
            <span class="text-xl font-bold tracking-tighter text-[#00457b] uppercase hidden sm:block">High-Q Attendance</span>
        </div>
        <nav class="hidden md:flex items-center gap-8 font-inter text-sm font-medium tracking-wide">
            <a class="text-[#00457b] font-bold hover:underline transition-all flex items-center gap-1" href="<?= $main_site_url ?>" target="_blank">
                <span class="material-symbols-outlined text-sm">open_in_new</span> Main Site
            </a>
            <a class="<?= ($currentPage == 'index.php' || $currentPage == 'terminal.php') ? 'text-[#00457b] font-bold border-b-2 border-[#00457b] pb-1' : 'text-[#424750] hover:text-[#00457b]'; ?> transition-all" href="index.php">Portal Kiosk</a>
            <a class="<?= ($currentPage == 'support.php') ? 'text-[#00457b] font-bold border-b-2 border-[#00457b] pb-1' : 'text-[#424750] hover:text-[#00457b]'; ?> transition-all" href="support.php">Support & Helpdesk</a>
        </nav>
        <div class="flex items-center gap-3 md:gap-4">
            <a href="admin/login.php" class="px-4 py-2 bg-primary text-white rounded-lg font-medium text-xs md:text-sm hover:opacity-90 transition-all flex items-center gap-1.5 shadow-sm">
                <span class="material-symbols-outlined text-sm">admin_panel_settings</span> Admin Portal
            </a>
        </div>
    </div>
    <div class="flex md:hidden items-center justify-center gap-6 py-2 border-t border-border-subtle font-inter text-sm font-medium">
        <a class="text-[#00457b] font-bold flex items-center gap-0.5" href="<?= $main_site_url ?>" target="_blank"><span class="material-symbols-outlined text-xs">open_in_new</span> Main Site</a>
        <a class="<?= ($currentPage == 'index.php' || $currentPage == 'terminal.php') ? 'text-[#00457b] font-bold' : 'text-[#424750]'; ?>" href="index.php">Portal Kiosk</a>
        <a class="<?= ($currentPage == 'support.php') ? 'text-[#00457b] font-bold' : 'text-[#424750]'; ?>" href="support.php">Support</a>
    </div>
</header>
