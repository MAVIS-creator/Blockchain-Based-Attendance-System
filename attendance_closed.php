<?php
require_once __DIR__ . '/admin/runtime_storage.php';

include __DIR__ . '/includes/public_header.php';
?>

<!-- Main Content Canvas -->
<main class="min-h-[calc(100vh-160px)] flex flex-col items-center justify-center px-6 py-12 relative overflow-hidden">
    <!-- Tonal Background Decoration -->
    <div class="absolute top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2 w-[600px] h-[600px] bg-primary-fixed/20 rounded-full blur-[120px] -z-10"></div>
    
    <!-- Main Content Card -->
    <div class="w-full max-w-2xl bg-surface-container-low rounded-xl p-1 md:p-2 transition-all">
        <div class="bg-surface-container-lowest rounded-lg p-10 md:p-16 flex flex-col items-center text-center shadow-[0_16px_36px_rgba(24,39,75,0.06)]">
            <!-- Status Icon with Chain-Link Progress Logic -->
            <div class="relative mb-10">
                <div class="w-24 h-24 rounded-full bg-surface-container-high flex items-center justify-center text-on-surface-variant ring-4 ring-surface-container ring-offset-4">
                    <span class="material-symbols-outlined text-5xl" style="font-variation-settings: 'FILL' 0;">lock_clock</span>
                </div>
                <!-- Chain indicator styling -->
                <div class="absolute -right-12 top-1/2 -translate-y-1/2 flex items-center gap-2">
                    <div class="w-2 h-2 rounded-full bg-surface-container-highest"></div>
                    <div class="w-8 h-0.5 bg-outline-variant opacity-20"></div>
                    <div class="w-2 h-2 rounded-full bg-surface-container-highest"></div>
                </div>
                <div class="absolute -left-12 top-1/2 -translate-y-1/2 flex items-center gap-2">
                    <div class="w-2 h-2 rounded-full bg-surface-container-highest"></div>
                    <div class="w-8 h-0.5 bg-outline-variant opacity-20"></div>
                    <div class="w-2 h-2 rounded-full bg-surface-container-highest"></div>
                </div>
            </div>
            
            <!-- Messaging -->
            <h1 class="text-[1.75rem] font-bold text-on-surface mb-4 leading-tight">
                Attendance is currently closed
            </h1>
            <p class="text-on-surface-variant text-base max-w-md leading-relaxed mb-12">
                Records are not being accepted at this time. Attendance is not open at this time.
            </p>
            
            <!-- Actions -->
            <div class="flex flex-col sm:flex-row gap-4 w-full justify-center">
                <a href="index.php" class="px-8 py-3 bg-gradient-to-br from-primary to-primary-container text-white font-semibold rounded-lg shadow-none hover:shadow-[0_16px_36px_rgba(24,39,75,0.06)] transition-all flex items-center justify-center gap-2 decoration-none cursor-pointer">
                    <span class="material-symbols-outlined text-[20px]">home</span>
                    Return Home
                </a>
                <a href="support.php" class="px-8 py-3 bg-secondary-fixed text-on-secondary-fixed-variant font-semibold rounded-lg hover:bg-surface-container-high transition-colors flex items-center justify-center gap-2 decoration-none cursor-pointer max-w-full">
                    <span class="material-symbols-outlined text-[20px]">contact_support</span>
                    Contact Support
                </a>
            </div>
            
            <!-- Automatic Refresh Note -->
            <div class="mt-16 flex items-center gap-3 py-3 px-6 bg-tertiary-fixed text-on-tertiary-fixed-variant rounded-full text-xs font-label uppercase tracking-widest">
                <span class="relative flex h-2 w-2">
                    <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-tertiary opacity-75"></span>
                    <span class="relative inline-flex rounded-full h-2 w-2 bg-tertiary"></span>
                </span>
                This page will automatically refresh when a new session begins.
            </div>
        </div>
    </div>
    
    <!-- Decorative Metadata -->
    <div class="mt-12 grid grid-cols-2 gap-12 max-w-lg w-full">
        <div class="flex flex-col">
            <span class="text-[0.75rem] font-label text-outline uppercase tracking-widest mb-1">System Status</span>
            <span class="text-sm font-semibold text-on-surface">Smart Attendance</span>
        </div>
        <div class="flex flex-col text-right">
            <span class="text-[0.75rem] font-label text-outline uppercase tracking-widest mb-1">Session State</span>
            <span class="text-sm font-semibold text-on-surface">Closed / Locked</span>
        </div>
    </div>
</main>

<style>
    .glass-panel {
        backdrop-filter: blur(12px);
        -webkit-backdrop-filter: blur(12px);
    }
</style>

<?php include __DIR__ . '/includes/public_footer.php'; ?>

<script>
    async function autoReturnWhenAttendanceOpens() {
      try {
        const response = await fetch('status_api.php', { cache: 'no-store' });
        if (!response.ok) return;
        const status = await response.json();
        const isOpen = !!(status && (status.checkin || status.checkout));
        if (isOpen) {
          window.location.href = 'index.php';
        }
      } catch(e) {}
    }
    autoReturnWhenAttendanceOpens();
    setInterval(autoReturnWhenAttendanceOpens, 5000);
</script>
