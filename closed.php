<?php
require_once __DIR__ . '/admin/session_bootstrap.php';
admin_configure_session();
require_once __DIR__ . '/admin/runtime_storage.php';

include __DIR__ . '/includes/public_header.php';
?>

<!-- Main Content Canvas -->
<main class="flex-grow flex items-center justify-center px-6 py-12 relative overflow-hidden security-mesh">
    <!-- Background Decorative Elements -->
    <div class="absolute top-1/4 left-1/4 w-96 h-96 bg-primary-fixed/30 blur-[120px] -z-10 rounded-full"></div>
    <div class="absolute bottom-1/4 right-1/4 w-64 h-64 bg-secondary-fixed/20 blur-[100px] -z-10 rounded-full"></div>
    
    <!-- Lockout Card -->
    <div class="max-w-xl w-full bg-surface-container-lowest rounded-xl shadow-[0_16px_36px_rgba(24,39,75,0.06)] overflow-hidden transition-all duration-500 border border-outline-variant/10">
        <!-- Card Visual Accent -->
        <div class="h-1.5 w-full bg-gradient-to-r from-primary to-primary-container"></div>
        <div class="p-10 md:p-14 flex flex-col items-center text-center">
            <!-- Security Icon Cluster -->
            <div class="relative mb-8">
                <div class="absolute inset-0 bg-primary-fixed blur-2xl opacity-40 scale-150"></div>
                <div class="relative w-24 h-24 bg-surface-container-low rounded-2xl flex items-center justify-center text-primary transform rotate-3">
                    <span class="material-symbols-outlined text-[48px]" style="font-variation-settings: 'FILL' 1;">enhanced_encryption</span>
                </div>
                <div class="absolute -bottom-2 -right-2 w-10 h-10 bg-error rounded-full flex items-center justify-center text-white border-4 border-surface-container-lowest">
                    <span class="material-symbols-outlined text-[18px]" style="font-variation-settings: 'FILL' 1;">warning</span>
                </div>
            </div>
            
            <!-- Headline -->
            <h1 class="text-[1.75rem] font-bold tracking-tight text-on-surface mb-4 font-headline leading-tight">
                Attendance Locked
            </h1>
            
            <!-- Status Badge -->
            <div class="mb-6 inline-flex items-center gap-2 px-3 py-1 bg-surface-container-high rounded-full">
                <span class="w-2 h-2 rounded-full bg-error animate-pulse"></span>
                <span class="text-[0.65rem] font-black uppercase tracking-widest text-on-surface-variant">Access Level: Restricted</span>
            </div>
            
            <!-- Explanation Text -->
            <p class="text-on-surface-variant text-sm leading-[1.6] mb-10 max-w-md">
                Your session was interrupted due to inactivity or tab changes. Attendance is now closed for fairness and exam integrity. This security measure ensures <span class="text-on-surface font-semibold">state integrity</span> and prevents unauthorized access.
            </p>
            
            <!-- Action Buttons -->
            <div class="flex flex-col sm:flex-row gap-4 w-full">
                <a class="flex-1 px-8 py-3.5 bg-gradient-to-br from-primary to-primary-container text-white font-semibold rounded-lg text-sm shadow-sm hover:shadow-[0_16px_36px_rgba(24,39,75,0.06)] transition-all duration-300 text-center active:scale-95 cursor-pointer" href="index.php">
                    Return Home
                </a>
                <a class="flex-1 px-8 py-3.5 bg-secondary-fixed text-on-secondary-fixed-variant font-semibold rounded-lg text-sm transition-all duration-300 text-center hover:bg-secondary-fixed-dim active:scale-95 cursor-pointer" href="support.php">
                    Contact Support
                </a>
            </div>
            
            <!-- Metadata/Trace ID -->
            <div class="mt-12 pt-8 border-t border-outline-variant/10 w-full flex flex-col items-center gap-2">
                <span class="text-[10px] font-mono uppercase tracking-[0.2em] text-outline-variant">System Log: <?php echo date('Y-md-Hi'); ?></span>
                <div class="flex items-center gap-4">
                    <div class="flex items-center gap-1.5">
                        <span class="w-1.5 h-1.5 rounded-full bg-outline-variant/50"></span>
                        <span class="text-[10px] text-outline font-medium tracking-tight">Status: Suspended</span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</main>

<style>
    .security-mesh {
        background-image: radial-gradient(#d2e4ff 1px, transparent 1px);
        background-size: 32px 32px;
    }
</style>

<?php include __DIR__ . '/includes/public_footer.php'; ?>

<script>
    async function autoReturnToAttendanceIfAllowed() {
      try {
        const statusRes = await fetch('status_api.php', { cache: 'no-store' });
        if (!statusRes.ok) return;
        const status = await statusRes.json();
        const isOpen = !!(status && (status.checkin || status.checkout));
        if (!isOpen) return;

        let tokenRevoked = false;
        const token = localStorage.getItem('attendance_token') || '';
        if (token) {
          try {
            const revokedRes = await fetch('admin/revoked_tokens.php', { cache: 'no-store' });
            if (revokedRes.ok) {
              const revokedData = await revokedRes.json();
              const revokedTokens = (revokedData && revokedData.revoked && revokedData.revoked.tokens) ? revokedData.revoked.tokens : {};
              tokenRevoked = !!(revokedTokens && revokedTokens[token]);
            }
          } catch (e) {}
        }

        localStorage.removeItem('attendanceBlocked');
        localStorage.removeItem('attendanceTabAwayStrikes');
        localStorage.removeItem('attendanceTabAwayLockUntil');

        if (tokenRevoked) {
          localStorage.removeItem('attendance_token');
        }

        window.location.href = 'index.php';
      } catch (e) {}
    }

    autoReturnToAttendanceIfAllowed();
    setInterval(autoReturnToAttendanceIfAllowed, 5000);
</script>
