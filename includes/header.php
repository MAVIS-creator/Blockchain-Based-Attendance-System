<?php
/**
 * Shared Header Template
 * High-Q Solid Academy Biometric Attendance System
 */

require_once __DIR__ . '/auth.php';
require_login();

$currentUser = get_logged_user();
$activePage = $activePage ?? 'dashboard';
?>
<!DOCTYPE html>
<html class="light" lang="en">
<head>
    <meta charset="utf-8"/>
    <meta content="width=device-width, initial-scale=1.0" name="viewport"/>
    <title><?= htmlspecialchars($pageTitle ?? 'Dashboard') ?> | High-Q Solid Academy</title>
    <script src="https://cdn.tailwindcss.com?plugins=forms,container-queries"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Hanken+Grotesk:wght@600;700;800&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet"/>
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet"/>
    <script>
        tailwind.config = {
            darkMode: "class",
            theme: {
                extend: {
                    colors: {
                        "on-surface": "#0b1c30",
                        "on-surface-variant": "#44474c",
                        "surface-bright": "#f8f9ff",
                        "on-background": "#0b1c30",
                        "outline": "#75777d",
                        "on-secondary-container": "#6c5000",
                        "primary": "#000000",
                        "secondary": "#795900",
                        "outline-variant": "#c5c6cd",
                        "error": "#ba1a1a",
                        "surface": "#f8f9ff",
                        "border-subtle": "#E2E8F0",
                        "surface-container": "#e5eeff",
                        "navy-muted": "#1E293B",
                        "surface-gray": "#F8FAFC",
                        "surface-container-low": "#eff4ff",
                        "primary-container": "#0d1c2e",
                        "secondary-container": "#fdc014",
                        "surface-container-lowest": "#ffffff"
                    },
                    borderRadius: {
                        "DEFAULT": "0.25rem",
                        "lg": "0.5rem",
                        "xl": "0.75rem",
                        "full": "9999px"
                    },
                    spacing: {
                        "gutter": "1.5rem",
                        "topbar-height": "64px",
                        "sidebar-width": "280px",
                        "container-max": "1440px"
                    },
                    fontFamily: {
                        "title-md": ["Hanken Grotesk"],
                        "code-snippet": ["JetBrains Mono"],
                        "body-md": ["Inter"],
                        "display-lg": ["Hanken Grotesk"],
                        "label-caps": ["JetBrains Mono"],
                        "headline-lg": ["Hanken Grotesk"],
                        "body-sm": ["Inter"]
                    }
                }
            }
        }
    </script>
    <style>
        body { font-family: 'Inter', sans-serif; background-color: #F8FAFC; color: #0b1c30; }
        .material-symbols-outlined { font-variation-settings: 'FILL' 0, 'wght' 400, 'GRAD' 0, 'opsz' 24; }
        .glass-card { background: rgba(255, 255, 255, 0.7); backdrop-filter: blur(12px); border: 1px solid rgba(255, 255, 255, 0.2); }
        .sidebar-item-active { background: #e5eeff; color: #000000; font-weight: 700; border-radius: 0.5rem; }
        .custom-scrollbar::-webkit-scrollbar { width: 4px; }
        .custom-scrollbar::-webkit-scrollbar-thumb { background: #E2E8F0; border-radius: 10px; }
    </style>
</head>
<body class="bg-surface-gray">
<div class="min-h-screen flex">
    <!-- Sidebar -->
    <aside class="fixed left-0 top-0 h-full w-sidebar-width bg-surface-container-lowest border-r border-border-subtle shadow-sm flex flex-col p-4 gap-2 z-50">
        <div class="mb-6 px-2 flex items-center gap-3">
            <div class="w-10 h-10 bg-primary text-white font-bold rounded-lg flex items-center justify-center text-lg">HQ</div>
            <div>
                <h1 class="font-title-md text-title-md font-bold text-primary leading-tight">High-Q Solid</h1>
                <p class="text-[12px] text-on-surface-variant leading-none">Biometric Attendance</p>
            </div>
        </div>

        <nav class="flex flex-col gap-1">
            <a class="flex items-center gap-3 px-4 py-3 text-on-surface-variant hover:bg-surface-container transition-colors rounded-lg <?= $activePage === 'dashboard' ? 'sidebar-item-active' : '' ?>" href="index.php">
                <span class="material-symbols-outlined">dashboard</span>
                <span class="font-body-md">Dashboard</span>
            </a>
            <a class="flex items-center gap-3 px-4 py-3 text-on-surface-variant hover:bg-surface-container transition-colors rounded-lg <?= $activePage === 'students' ? 'sidebar-item-active' : '' ?>" href="students.php">
                <span class="material-symbols-outlined">group</span>
                <span class="font-body-md">Students</span>
            </a>
            <a class="flex items-center gap-3 px-4 py-3 text-on-surface-variant hover:bg-surface-container transition-colors rounded-lg <?= $activePage === 'enrollment' ? 'sidebar-item-active' : '' ?>" href="enroll_fingerprint.php">
                <span class="material-symbols-outlined">fingerprint</span>
                <span class="font-body-md">Fingerprint Enroll</span>
            </a>
            <a class="flex items-center gap-3 px-4 py-3 text-on-surface-variant hover:bg-surface-container transition-colors rounded-lg <?= $activePage === 'records' ? 'sidebar-item-active' : '' ?>" href="attendance_records.php">
                <span class="material-symbols-outlined">history</span>
                <span class="font-body-md">Attendance Records</span>
            </a>
            <a class="flex items-center gap-3 px-4 py-3 text-on-surface-variant hover:bg-surface-container transition-colors rounded-lg <?= $activePage === 'reports' ? 'sidebar-item-active' : '' ?>" href="reports.php">
                <span class="material-symbols-outlined">analytics</span>
                <span class="font-body-md">Reports</span>
            </a>
            <a class="flex items-center gap-3 px-4 py-3 text-on-surface-variant hover:bg-surface-container transition-colors rounded-lg <?= $activePage === 'terminal' ? 'sidebar-item-active' : '' ?>" href="terminal.php" target="_blank">
                <span class="material-symbols-outlined">desktop_windows</span>
                <span class="font-body-md">Public Terminal</span>
            </a>
            <a class="flex items-center gap-3 px-4 py-3 text-on-surface-variant hover:bg-surface-container transition-colors rounded-lg <?= $activePage === 'settings' ? 'sidebar-item-active' : '' ?>" href="settings.php">
                <span class="material-symbols-outlined">settings</span>
                <span class="font-body-md">Settings</span>
            </a>
        </nav>

        <div class="mt-auto p-4 bg-surface-container-low rounded-xl border border-border-subtle">
            <p class="font-label-caps text-label-caps text-on-surface-variant mb-2">QUICK ACTIONS</p>
            <div class="flex flex-col gap-2">
                <a href="register_student.php" class="w-full py-2 bg-primary text-white rounded-lg font-body-sm flex items-center justify-center gap-2 hover:opacity-90 transition-opacity">
                    <span class="material-symbols-outlined text-[18px]">person_add</span> Register Student
                </a>
                <a href="enroll_fingerprint.php" class="w-full py-2 border border-primary text-primary rounded-lg font-body-sm flex items-center justify-center gap-2 hover:bg-surface-container transition-colors">
                    <span class="material-symbols-outlined text-[18px]">fingerprint</span> Enroll Fingerprint
                </a>
            </div>
        </div>
    </aside>

    <!-- Main Content wrapper -->
    <main class="ml-sidebar-width flex-grow flex flex-col min-h-screen">
        <!-- Top Nav Bar -->
        <header class="sticky top-0 right-0 w-full z-40 backdrop-blur-md border-b border-border-subtle bg-surface-container-lowest/80 flex justify-between items-center h-topbar-height px-gutter">
            <div class="flex items-center bg-surface-container-low px-3 py-1.5 rounded-full border border-border-subtle w-96">
                <span class="material-symbols-outlined text-on-surface-variant mr-2">search</span>
                <input class="bg-transparent border-none focus:ring-0 text-body-sm w-full placeholder:text-on-surface-variant/50" id="globalSearchInput" placeholder="Search students, admission #..." type="text"/>
            </div>

            <div class="flex items-center gap-4">
                <a href="terminal.php" target="_blank" class="flex items-center gap-2 px-3 py-1.5 bg-secondary-container/20 text-secondary font-semibold text-xs rounded-full border border-secondary-container/30 hover:bg-secondary-container/40 transition-colors">
                    <span class="w-2 h-2 rounded-full bg-secondary animate-pulse"></span> Terminal Mode
                </a>

                <div class="h-6 w-[1px] bg-border-subtle"></div>

                <div class="flex items-center gap-3">
                    <div class="text-right hidden sm:block">
                        <p class="font-body-sm font-semibold text-on-surface leading-tight"><?= htmlspecialchars($currentUser['fullname'] ?? 'Admin') ?></p>
                        <p class="text-[12px] text-on-surface-variant leading-none capitalize"><?= htmlspecialchars($currentUser['role'] ?? 'Administrator') ?></p>
                    </div>
                    <div class="w-10 h-10 rounded-full bg-primary text-white flex items-center justify-center font-bold">
                        <?= strtoupper(substr($currentUser['username'] ?? 'A', 0, 1)) ?>
                    </div>
                    <a href="logout.php" title="Logout" class="text-on-surface-variant hover:text-error transition-colors p-1">
                        <span class="material-symbols-outlined">logout</span>
                    </a>
                </div>
            </div>
        </header>

        <div class="p-gutter max-w-container-max mx-auto w-full flex-grow">
