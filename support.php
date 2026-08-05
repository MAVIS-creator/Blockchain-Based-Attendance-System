<?php
/**
 * Public Support & Technical Assistance Page
 * High-Q Solid Academy Biometric Attendance System
 */
require_once __DIR__ . '/includes/public_header.php';

$main_site_url = (isset($_SERVER['HTTP_HOST']) && strpos($_SERVER['HTTP_HOST'], 'localhost') !== false) 
    ? '/HIGH-Q/public/' 
    : 'https://highqsolidacademy.com';
?>

<main class="w-full min-h-[calc(100vh-80px)] bg-background text-on-background py-10 px-4 md:px-8">
    <div class="max-w-[1200px] mx-auto space-y-10">

        <!-- Hero Header -->
        <div class="bg-surface-container-lowest p-8 md:p-12 rounded-3xl border border-outline-variant/20 shadow-sm relative overflow-hidden">
            <div class="absolute -right-10 -bottom-10 w-72 h-72 bg-primary/5 rounded-full blur-3xl pointer-events-none"></div>
            
            <div class="max-w-3xl space-y-4">
                <span class="px-3 py-1 bg-primary/10 text-primary font-bold text-xs uppercase tracking-wider rounded-full inline-block">Support & Technical Helpdesk</span>
                <h1 class="text-3xl md:text-5xl font-extrabold text-primary tracking-tight leading-tight">High-Q Biometric System Help Center</h1>
                <p class="text-on-surface-variant text-base md:text-lg leading-relaxed">
                    Need assistance setting up your DigitalPersona fingerprint scanner, launching the desktop companion service, or managing student attendance records? Our technical support team is here to help.
                </p>
                
                <div class="flex flex-wrap items-center gap-4 pt-4">
                    <a href="mailto:admin@highqsoldacademy.com" class="px-6 py-3 bg-primary text-white font-bold rounded-xl hover:bg-secondary transition-all shadow-md flex items-center gap-2">
                        <span class="material-symbols-outlined">mail</span> Email Support (admin@highqsoldacademy.com)
                    </a>
                    <a href="<?= $main_site_url ?>" target="_blank" class="px-6 py-3 border border-outline-variant text-on-surface font-semibold rounded-xl hover:bg-surface-container-high transition-all flex items-center gap-2">
                        <span class="material-symbols-outlined">open_in_new</span> Visit Main Academy Website
                    </a>
                </div>
            </div>
        </div>

        <!-- Contact Cards Grid -->
        <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
            <!-- Email Support Card -->
            <div class="bg-surface-container-lowest p-6 rounded-2xl border border-outline-variant/20 shadow-sm flex flex-col justify-between space-y-4">
                <div class="space-y-3">
                    <div class="w-12 h-12 rounded-xl bg-primary/10 text-primary flex items-center justify-center">
                        <span class="material-symbols-outlined text-2xl">mark_email_read</span>
                    </div>
                    <h3 class="font-bold text-xl text-on-surface">Direct Administrator Email</h3>
                    <p class="text-xs text-on-surface-variant leading-relaxed">
                        For hardware inquiries, system access requests, database exports, or technical support, contact the High-Q Solid Academy administrator directly.
                    </p>
                </div>
                <div class="pt-4 border-t border-outline-variant/10">
                    <a href="mailto:admin@highqsoldacademy.com" class="text-sm font-bold text-primary hover:underline flex items-center gap-1">
                        admin@highqsoldacademy.com &rarr;
                    </a>
                </div>
            </div>

            <!-- Biometric Desktop Service -->
            <div class="bg-surface-container-lowest p-6 rounded-2xl border border-outline-variant/20 shadow-sm flex flex-col justify-between space-y-4">
                <div class="space-y-3">
                    <div class="w-12 h-12 rounded-xl bg-green-500/10 text-green-700 flex items-center justify-center">
                        <span class="material-symbols-outlined text-2xl">fingerprint</span>
                    </div>
                    <h3 class="font-bold text-xl text-on-surface">Biometric Desktop Companion</h3>
                    <p class="text-xs text-on-surface-variant leading-relaxed">
                        The Windows Companion app runs locally on <code class="bg-surface-container px-1 py-0.5 rounded text-[11px]">http://localhost:8080/</code> to bridge DigitalPersona U.are.U readers with the web browser.
                    </p>
                </div>
                <div class="pt-4 border-t border-outline-variant/10">
                    <a href="HighQ_Biometric_Service_Setup_v1.0.exe" download class="text-sm font-bold text-green-700 hover:underline flex items-center gap-1">
                        Download Windows Setup (.exe) &rarr;
                    </a>
                </div>
            </div>

            <!-- Admin Portal Access -->
            <div class="bg-surface-container-lowest p-6 rounded-2xl border border-outline-variant/20 shadow-sm flex flex-col justify-between space-y-4">
                <div class="space-y-3">
                    <div class="w-12 h-12 rounded-xl bg-secondary/10 text-secondary flex items-center justify-center">
                        <span class="material-symbols-outlined text-2xl">admin_panel_settings</span>
                    </div>
                    <h3 class="font-bold text-xl text-on-surface">Admin Management Portal</h3>
                    <p class="text-xs text-on-surface-variant leading-relaxed">
                        Access student registration, fingerprint enrollment, lesson type configuration (JAMB, WAEC, GCE), and attendance reporting dashboard.
                    </p>
                </div>
                <div class="pt-4 border-t border-outline-variant/10">
                    <a href="admin/login.php" class="text-sm font-bold text-primary hover:underline flex items-center gap-1">
                        Admin Login Portal &rarr;
                    </a>
                </div>
            </div>
        </div>

        <!-- System Architecture & FAQ Guide -->
        <div class="bg-surface-container-lowest p-8 md:p-10 rounded-3xl border border-outline-variant/20 shadow-sm space-y-8">
            <h2 class="text-2xl font-bold text-primary flex items-center gap-2 border-b border-outline-variant/10 pb-4">
                <span class="material-symbols-outlined">help</span> Frequently Asked Questions & Troubleshooting
            </h2>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-6 text-sm">
                <div class="p-5 bg-surface-container-low rounded-2xl space-y-2 border border-outline-variant/10">
                    <h4 class="font-bold text-on-surface flex items-center gap-2">
                        <span class="material-symbols-outlined text-primary text-base">dns</span> Why does it say "Biometric Service Offline"?
                    </h4>
                    <p class="text-on-surface-variant text-xs leading-relaxed">
                        Ensure that the High-Q Biometric Service application is running on your Windows computer. If installed via <code class="bg-surface-container px-1 rounded">HighQ_Biometric_Service_Setup_v1.0.exe</code>, search for "High-Q Biometric Service" in your Start Menu and open it. It listens on port 8080.
                    </p>
                </div>

                <div class="p-5 bg-surface-container-low rounded-2xl space-y-2 border border-outline-variant/10">
                    <h4 class="font-bold text-on-surface flex items-center gap-2">
                        <span class="material-symbols-outlined text-primary text-base">usb</span> Fingerprint reader glass isn't responding?
                    </h4>
                    <p class="text-on-surface-variant text-xs leading-relaxed">
                        Verify that your DigitalPersona U.are.U 5160 USB reader is plugged into a USB port directly. Re-open the High-Q Biometric Service desktop window and click <strong>"Reconnect Scanner"</strong> to re-bind the USB driver controller.
                    </p>
                </div>

                <div class="p-5 bg-surface-container-low rounded-2xl space-y-2 border border-outline-variant/10">
                    <h4 class="font-bold text-on-surface flex items-center gap-2">
                        <span class="material-symbols-outlined text-primary text-base">collections_bookmark</span> How do I assign multiple Lesson Types?
                    </h4>
                    <p class="text-on-surface-variant text-xs leading-relaxed">
                        When registering or editing a student profile in the Admin Portal, you can select multiple checkable pills (e.g. <strong>WAEC</strong> and <strong>GCE</strong>). You can also add or remove custom lesson types in System Settings.
                    </p>
                </div>

                <div class="p-5 bg-surface-container-low rounded-2xl space-y-2 border border-outline-variant/10">
                    <h4 class="font-bold text-on-surface flex items-center gap-2">
                        <span class="material-symbols-outlined text-primary text-base">upload_file</span> How do bulk student CSV imports work?
                    </h4>
                    <p class="text-on-surface-variant text-xs leading-relaxed">
                        In the Admin Students page, click "Bulk CSV Import". Download the sample CSV template which includes headers for admission numbers, names, lesson types, parent phones, and passport photo filenames.
                    </p>
                </div>
            </div>
        </div>

    </div>
</main>

<?php require_once __DIR__ . '/includes/public_footer.php'; ?>
