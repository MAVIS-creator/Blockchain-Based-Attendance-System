<?php
date_default_timezone_set('Africa/Lagos');
require_once __DIR__ . '/storage_helpers.php';
require_once __DIR__ . '/admin/runtime_storage.php';
require_once __DIR__ . '/admin/cache_helpers.php';
require_once __DIR__ . '/request_timing.php';
require_once __DIR__ . '/request_guard.php';
app_storage_init();
app_request_guard('rules.php', 'public');
request_timing_start('rules.php');

include __DIR__ . '/includes/public_header.php';
?>

<main class="flex-grow max-w-[1440px] mx-auto w-full px-8 py-12">
    <div class="max-w-5xl mx-auto space-y-10">
        <section class="bg-surface-container-lowest rounded-xl p-8 md:p-10 shadow-[0_16px_36px_rgba(24,39,75,0.06)] border border-outline-variant/10 overflow-hidden relative">
            <div class="absolute top-0 right-0 p-6 opacity-10 pointer-events-none">
                <span class="material-symbols-outlined text-[96px]" style="font-variation-settings: 'FILL' 1;">gavel</span>
            </div>
            <div class="relative z-10 space-y-4 max-w-3xl">
                <p class="text-xs font-bold uppercase tracking-[0.2em] text-primary">Attendance Rules</p>
                <h1 class="text-3xl md:text-4xl font-extrabold tracking-tight text-on-surface">Rules that keep attendance accurate and secure</h1>
                <p class="text-on-surface-variant leading-relaxed text-base md:text-lg">
                    These rules replace the old legal placeholders and focus on what students must do when using the attendance system.
                </p>
            </div>
        </section>

        <section class="grid grid-cols-1 lg:grid-cols-2 gap-6">
            <div class="bg-[#ecfdf5] border border-[#a7f3d0] rounded-xl p-8 shadow-sm">
                <div class="flex items-center gap-3 mb-5">
                    <span class="material-symbols-outlined text-[#059669]" style="font-variation-settings: 'FILL' 1;">check_circle</span>
                    <h2 class="text-xl font-bold text-[#065f46]">Do</h2>
                </div>
                <ul class="space-y-4 text-sm text-[#064e3b] leading-relaxed">
                    <li>Enter your name, matric number, and allow fingerprint generation.</li>
                    <li>Use only valid matric digits, between 6 and 20 digits.</li>
                    <li>Submit only when the active mode is open, either check-in or check-out.</li>
                    <li>Use your real network or location if geo-fence or VPN/proxy blocking is enabled.</li>
                    <li>Use the same device and browser fingerprint when fingerprint matching is required.</li>
                    <li>Check in before checking out on the same day and for the same course.</li>
                </ul>
            </div>

            <div class="bg-[#fef2f2] border border-[#fecaca] rounded-xl p-8 shadow-sm">
                <div class="flex items-center gap-3 mb-5">
                    <span class="material-symbols-outlined text-[#dc2626]" style="font-variation-settings: 'FILL' 1;">cancel</span>
                    <h2 class="text-xl font-bold text-[#991b1b]">Don’t</h2>
                </div>
                <ul class="space-y-4 text-sm text-[#7f1d1d] leading-relaxed">
                    <li>Don’t use VPN or proxy services if blocking is enabled by settings.</li>
                    <li>Don’t try to submit attendance for a mode that is not active.</li>
                    <li>Don’t check in or check out twice for the same course or day.</li>
                    <li>Don’t try to check out without a previous check-in.</li>
                    <li>Don’t swap device or browser identity if a one-device or UA lock is enabled.</li>
                    <li>Don’t submit from a non-whitelisted IP when whitelist mode is active.</li>
                </ul>
            </div>
        </section>

        <section class="bg-surface-container-low rounded-xl p-8 md:p-10 border border-outline-variant/10 shadow-sm">
            <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
                <div>
                    <h2 class="text-xl font-semibold text-on-surface">Need help with a rule?</h2>
                    <p class="text-on-surface-variant mt-2">Use support if you need a record corrected, a mode explained, or a submission checked.</p>
                </div>
                <a href="support.php" class="inline-flex items-center justify-center gap-2 px-5 py-3 rounded-lg bg-primary text-white font-semibold hover:opacity-95 transition-opacity">
                    <span class="material-symbols-outlined text-[18px]" style="font-variation-settings: 'FILL' 1;">help_center</span>
                    Open Support
                </a>
            </div>
        </section>
    </div>
</main>

<?php include __DIR__ . '/includes/public_footer.php'; ?>