<?php
$errorCode = isset($errorCode) ? (int)$errorCode : 500;
$errorTitle = isset($errorTitle) ? (string)$errorTitle : 'Server Error';
$errorMessage = isset($errorMessage) ? (string)$errorMessage : 'Something went wrong. Please try again later.';
$homeHref = isset($homeHref) ? (string)$homeHref : 'index.php';
$homeLabel = isset($homeLabel) ? (string)$homeLabel : 'Return to Portal';

http_response_code($errorCode);

include __DIR__ . '/includes/public_header.php';
?>
<!-- Main Content Canvas -->
<main class="flex-grow flex items-center justify-center p-8 relative overflow-hidden">
    <!-- Architectural Background Elements -->
    <div class="absolute inset-0 z-0 pointer-events-none">
        <div class="absolute top-[-10%] right-[-5%] w-[40%] h-[60%] bg-primary/5 blur-[120px] rounded-full"></div>
        <div class="absolute bottom-[-10%] left-[-5%] w-[30%] h-[50%] bg-secondary-container/10 blur-[100px] rounded-full"></div>
    </div>
    
    <div class="max-w-4xl w-full z-10 flex flex-col items-center text-center">
        <!-- Bento Style Error Card -->
        <div class="grid grid-cols-1 md:grid-cols-12 gap-8 w-full">
            <!-- Large Status Card -->
            <div class="md:col-span-12 bg-surface-container-lowest p-12 md:p-20 rounded-xl shadow-[0_16px_36px_rgba(24,39,75,0.06)] flex flex-col items-center">
                <!-- Decorative Icon -->
                <div class="mb-8 w-24 h-24 bg-surface-container-high rounded-full flex items-center justify-center text-primary-container">
                    <span class="material-symbols-outlined text-5xl" style="font-variation-settings: 'FILL' 0;">link_off</span>
                </div>
                
                <!-- Branded Code -->
                <h1 class="text-[6rem] md:text-[8rem] font-extrabold tracking-tighter text-primary leading-none mb-4 opacity-10 select-none">
                    <?= htmlspecialchars((string)$errorCode) ?>
                </h1>
                
                <div class="max-w-xl -mt-16 md:-mt-24 relative z-10">
                    <h2 class="text-3xl md:text-[2.75rem] font-bold text-on-surface mb-6 tracking-tight leading-tight pt-4">
                        <?= htmlspecialchars($errorTitle) ?>
                    </h2>
                    <p class="text-lg text-on-surface-variant font-body leading-relaxed mb-10">
                        <?= htmlspecialchars($errorMessage) ?>
                    </p>
                    
                    <div class="flex flex-col sm:flex-row gap-4 justify-center">
                        <a href="<?= htmlspecialchars($homeHref) ?>" class="inline-flex items-center justify-center gap-2 bg-gradient-to-br from-primary to-primary-container text-white px-8 py-4 rounded-lg font-bold text-lg hover:shadow-[0_16px_36px_rgba(24,39,75,0.15)] transition-all active:scale-95 decoration-none cursor-pointer">
                            <span class="material-symbols-outlined">home</span>
                            <?= htmlspecialchars($homeLabel) ?>
                        </a>
                        <a href="support.php" class="inline-flex items-center justify-center gap-2 bg-secondary-fixed text-on-secondary-fixed-variant px-8 py-4 rounded-lg font-bold text-lg hover:bg-surface-container-high transition-all active:scale-95 decoration-none cursor-pointer">
                            <span class="material-symbols-outlined">support_agent</span>
                            Technical Support
                        </a>
                    </div>
                </div>
            </div>
            
            <!-- Secondary Context Grid -->
            <div class="md:col-span-4 bg-surface-container-low p-8 rounded-xl flex flex-col gap-3">
                <span class="material-symbols-outlined text-primary" style="font-variation-settings: 'FILL' 1;">verified_user</span>
                <h3 class="font-bold text-lg text-primary">System Integrity</h3>
                <p class="text-sm text-on-surface-variant leading-relaxed text-left">Every record is logged. If you believe this is a system error, please report it to the administrator.</p>
            </div>
            
            <div class="md:col-span-8 overflow-hidden rounded-xl h-48 relative shadow-inner">
                <div class="absolute inset-0 bg-primary/5"></div>
                <div class="absolute bottom-6 left-8 z-10">
                    <div class="flex items-center gap-2 text-primary font-mono text-xs tracking-widest uppercase">
                        <span class="w-2 h-2 rounded-full bg-primary animate-pulse"></span>
                        System Watchdog Active
                    </div>
                </div>
            </div>
        </div>
    </div>
</main>

<?php include __DIR__ . '/includes/public_footer.php'; ?>
