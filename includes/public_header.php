<?php
if (!function_exists('admin_storage_migrate_file')) {
    require_once __DIR__ . '/../storage_helpers.php';
    require_once __DIR__ . '/../admin/runtime_storage.php';
}

$headerStatusFile = admin_storage_migrate_file('status.json', app_storage_file('status.json'));
$headerStatusData = @file_get_contents($headerStatusFile);
$headerStatusJson = $headerStatusData ? json_decode($headerStatusData, true) : [];
$headerIsCheckin = !empty($headerStatusJson['checkin']);
$headerIsCheckout = !empty($headerStatusJson['checkout']);
$headerEndTime = isset($headerStatusJson['end_time']) && is_numeric($headerStatusJson['end_time']) ? (int)$headerStatusJson['end_time'] : null;

$headerTimerValid = $headerEndTime !== null && $headerEndTime > time();
$headerActiveModeConfigured = $headerIsCheckin || $headerIsCheckout;

if ($headerActiveModeConfigured && !$headerTimerValid) {
    $headerIsCheckin = false;
    $headerIsCheckout = false;
}

$statusLabel = "Status: Closed";
$statusBadgeClass = "bg-surface-container-high text-on-surface-variant font-semibold";

if ($headerIsCheckin) {
    $statusLabel = "Mode: Check-In";
    $statusBadgeClass = "bg-primary text-white"; 
} elseif ($headerIsCheckout) {
    $statusLabel = "Mode: Check-Out";
    $statusBadgeClass = "bg-primary text-white";
}

$currentPage = basename($_SERVER['SCRIPT_NAME']);
?>
<!DOCTYPE html>
<html class="light" lang="en">
<head>
<meta charset="utf-8"/>
<meta content="width=device-width, initial-scale=1.0" name="viewport"/>
<title>Smart Attendance</title>
<link rel="icon" type="image/png" href="asset/image.png">
<link rel="apple-touch-icon" sizes="180x180" href="asset/image.png">
<link rel="manifest" href="asset/site.webmanifest">
<script src="https://cdn.tailwindcss.com?plugins=forms,container-queries"></script>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet"/>
<link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet"/>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script id="tailwind-config">
      tailwind.config = {
        darkMode: "class",
        theme: {
          extend: {
            "colors": {
                    "outline": "#727781",
                    "on-primary": "#ffffff",
                    "surface-tint": "#24609d",
                    "secondary-fixed-dim": "#99cbff",
                    "primary-fixed-dim": "#a1c9ff",
                    "on-secondary-fixed": "#001d34",
                    "on-tertiary-fixed-variant": "#334863",
                    "on-background": "#171c20",
                    "inverse-surface": "#2c3135",
                    "surface-container-highest": "#dfe3e8",
                    "on-secondary-fixed-variant": "#004a78",
                    "background": "#f6faff",
                    "surface": "#f6faff",
                    "surface-variant": "#dfe3e8",
                    "primary": "#00457b",
                    "on-error-container": "#93000a",
                    "primary-fixed": "#d2e4ff",
                    "outline-variant": "#c2c7d1",
                    "inverse-on-surface": "#edf1f6",
                    "surface-bright": "#f6faff",
                    "on-tertiary-fixed": "#031c36",
                    "on-secondary-container": "#004e7f",
                    "on-surface": "#171c20",
                    "on-primary-fixed": "#001c37",
                    "on-surface-variant": "#424750",
                    "on-primary-fixed-variant": "#004880",
                    "on-primary-container": "#b8d5ff",
                    "surface-container-high": "#e4e9ed",
                    "secondary": "#15629a",
                    "on-tertiary-container": "#bed4f6",
                    "inverse-primary": "#a1c9ff",
                    "surface-container": "#eaeef3",
                    "surface-container-lowest": "#ffffff",
                    "on-tertiary": "#ffffff",
                    "error-container": "#ffdad6",
                    "surface-dim": "#d6dadf",
                    "secondary-fixed": "#cfe5ff",
                    "tertiary-container": "#475c79",
                    "surface-container-low": "#f0f4f9",
                    "on-error": "#ffffff",
                    "primary-container": "#1f5d99",
                    "tertiary-fixed": "#d3e4ff",
                    "secondary-container": "#83c1fe",
                    "tertiary-fixed-dim": "#b2c8e9",
                    "tertiary": "#2f4560",
                    "error": "#ba1a1a",
                    "on-secondary": "#ffffff"
            },
            "borderRadius": {
                    "DEFAULT": "0.25rem",
                    "lg": "0.5rem",
                    "xl": "0.75rem",
                    "full": "9999px"
            },
            "fontFamily": {
                    "headline": ["Inter"],
                    "body": ["Inter"],
                    "label": ["Inter"]
            }
          },
        },
      }
</script>
<style>
    .material-symbols-outlined {
        font-variation-settings: 'FILL' 0, 'wght' 400, 'GRAD' 0, 'opsz' 24;
    }
    .blockchain-gradient {
        background: linear-gradient(135deg, #00457b 0%, #1f5d99 100%);
    }
    
    .alert-bar {
      position: sticky;
      top: 0;
      width: 100%;
      display: none;
      justify-content: space-between;
      align-items: flex-start;
            z-index: 80;
      animation: slideDown 0.4s ease;
            box-shadow: 0 8px 22px rgba(24, 39, 75, 0.14);
    }

    @keyframes slideDown {
      from { transform: translateY(-100%); opacity: 0; }
      to { transform: translateY(0); opacity: 1; }
    }

    .announcement-toast {
      position: fixed;
      top: 18px;
      right: 18px;
      z-index: 9999;
      background: #1f5d99;
      color: #fff;
      border-radius: 10px;
      padding: 10px 12px;
      box-shadow: 0 10px 24px rgba(24, 39, 75, 0.25);
      font-size: 0.9rem;
      opacity: 0;
      transform: translateY(-6px);
      transition: all 0.2s ease;
      pointer-events: none;
    }

    .announcement-toast.show {
      opacity: 1;
      transform: translateY(0);
    }
</style>
</head>
<body class="bg-background text-on-surface font-body min-h-screen flex flex-col">

<!-- Hybrid Announcement Model: Static Top Alert + Toast on Updates -->
<div id="announcementBanner" class="alert-bar w-full max-w-full">
    <div class="bg-[#eef5ff] border-b border-[#cad8eb] px-6 py-3 flex items-start sm:items-center justify-between gap-4 w-full" id="announcementBannerBg">
        <div class="flex items-start sm:items-center gap-3">
            <span class="material-symbols-outlined text-primary" data-icon="info" id="announcementIcon">info</span>
            <div class="flex flex-col">
                <strong id="announcementBannerTitle" class="text-xs font-bold uppercase tracking-wider text-on-surface">ℹ️ INFORMATION</strong>
                <p id="announcementBannerText" class="text-on-surface-variant text-sm font-medium">An important announcement is currently active.</p>
                <small id="announcementBannerMeta" class="text-on-surface-variant/70 text-xs mt-1">System update • Just now</small>
            </div>
        </div>
        <button type="button" id="announcementBannerDismiss" aria-label="Dismiss announcement" class="text-on-surface-variant/50 hover:text-on-surface transition-colors">
            <span class="material-symbols-outlined">close</span>
        </button>
    </div>
</div>

<!-- TopAppBar -->
<header id="publicTopBar" class="sticky w-full z-50 bg-[#f6faff]/95 dark:bg-[#171c20]/95 backdrop-blur-xl shadow-[0_16px_36px_rgba(24,39,75,0.06)]" style="top: var(--announcement-offset, 0px);">
    <div class="flex justify-between items-center px-4 md:px-8 py-4 max-w-[1440px] mx-auto">
        <div class="flex items-center gap-3">
            <img class="h-8 w-8 object-contain" src="asset/image.png" alt="Smart Attendance Logo">
            <span class="text-xl font-bold tracking-tighter text-[#00457b] dark:text-[#f0f4f9] uppercase hidden sm:block">Smart Attendance</span>
        </div>
        <nav class="hidden md:flex items-center gap-8 font-inter text-sm font-medium tracking-wide">
            <a class="<?php echo ($currentPage == 'index.php') ? 'text-[#00457b] dark:text-[#cfe5ff] font-bold border-b-2 border-[#00457b] dark:border-[#cfe5ff] pb-1' : 'text-[#424750] dark:text-[#c2c7d1] hover:text-[#00457b] dark:hover:text-white'; ?> transition-all" href="index.php">Portal</a>
            <a class="<?php echo ($currentPage == 'rules.php') ? 'text-[#00457b] dark:text-[#cfe5ff] font-bold border-b-2 border-[#00457b] dark:border-[#cfe5ff] pb-1' : 'text-[#424750] dark:text-[#c2c7d1] hover:text-[#00457b] dark:hover:text-white'; ?> transition-all" href="rules.php">Rules</a>
            <a class="<?php echo ($currentPage == 'support.php') ? 'text-[#00457b] dark:text-[#cfe5ff] font-bold border-b-2 border-[#00457b] dark:border-[#cfe5ff] pb-1' : 'text-[#424750] dark:text-[#c2c7d1] hover:text-[#00457b] dark:hover:text-white'; ?> transition-all" href="support.php">Support</a>
        </nav>
        <div class="flex items-center gap-3 md:gap-4">
            <?php if ($headerTimerValid): ?>
            <div class="flex items-center gap-1.5 bg-surface-container-low border border-outline-variant/20 px-2 md:px-3 py-1.5 md:py-2 rounded-md shadow-sm">
                <span class="material-symbols-outlined text-[14px] md:text-[16px] text-primary animate-pulse" style="font-variation-settings: 'FILL' 1;">timer</span>
                <span id="headerCountdown" class="font-mono text-xs md:text-sm font-extrabold text-primary tracking-widest" data-endtime="<?php echo $headerEndTime; ?>">00:00</span>
            </div>
            <?php endif; ?>
            <button class="<?php echo $statusBadgeClass; ?> px-4 md:px-5 py-2 rounded-md font-medium text-xs md:text-sm cursor-default opacity-90 transition-transform duration-200" disabled>
                <?php echo htmlspecialchars($statusLabel); ?>
            </button>
        </div>
    </div>
    <div class="flex md:hidden items-center justify-center gap-8 py-2 border-t border-outline-variant/10 font-inter text-sm font-medium">
        <a class="<?php echo ($currentPage == 'index.php') ? 'text-[#00457b] font-bold' : 'text-[#424750]'; ?>" href="index.php">Portal</a>
        <a class="<?php echo ($currentPage == 'rules.php') ? 'text-[#00457b] font-bold' : 'text-[#424750]'; ?>" href="rules.php">Rules</a>
        <a class="<?php echo ($currentPage == 'support.php') ? 'text-[#00457b] font-bold' : 'text-[#424750]'; ?>" href="support.php">Support</a>
    </div>
</header>
