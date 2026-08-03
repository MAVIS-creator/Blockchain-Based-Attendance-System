<?php
$pageTitle = 'Dashboard';
$activePage = 'dashboard';
require_once __DIR__ . '/includes/header.php';
?>

<div class="mb-8 flex justify-between items-end">
    <div>
        <h2 class="font-headline-lg text-3xl font-bold text-on-surface">Academy Overview</h2>
        <p class="font-body-md text-on-surface-variant">Real-time attendance metrics for <?= date('F j, Y') ?></p>
    </div>
    <div class="flex gap-2">
        <a href="register_student.php" class="px-4 py-2 bg-primary text-white rounded-lg text-sm font-semibold flex items-center gap-2 hover:bg-navy-muted shadow transition-all">
            <span class="material-symbols-outlined text-sm">person_add</span> Add Student
        </a>
        <a href="enroll_fingerprint.php" class="px-4 py-2 bg-secondary-container text-on-secondary-container rounded-lg text-sm font-semibold flex items-center gap-2 hover:opacity-90 transition-all">
            <span class="material-symbols-outlined text-sm">fingerprint</span> Enroll Fingerprint
        </a>
    </div>
</div>

<!-- Stats Grid -->
<div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-5 gap-6 mb-8">
    <div class="bg-surface-container-lowest p-5 rounded-xl border border-border-subtle shadow-sm">
        <div class="flex items-center justify-between mb-3">
            <div class="w-10 h-10 rounded-lg bg-surface-container flex items-center justify-center text-primary">
                <span class="material-symbols-outlined">group</span>
            </div>
            <span class="text-xs font-semibold text-on-surface-variant">Total</span>
        </div>
        <h3 class="text-on-surface-variant text-xs font-semibold uppercase tracking-wider mb-1">TOTAL STUDENTS</h3>
        <p class="text-3xl font-bold text-on-surface" id="statTotalStudents">0</p>
    </div>

    <div class="bg-surface-container-lowest p-5 rounded-xl border border-border-subtle shadow-sm">
        <div class="flex items-center justify-between mb-3">
            <div class="w-10 h-10 rounded-lg bg-green-50 flex items-center justify-center text-green-600">
                <span class="material-symbols-outlined">check_circle</span>
            </div>
            <span class="text-xs font-semibold text-green-600">Today</span>
        </div>
        <h3 class="text-on-surface-variant text-xs font-semibold uppercase tracking-wider mb-1">PRESENT TODAY</h3>
        <p class="text-3xl font-bold text-on-surface" id="statPresentToday">0</p>
    </div>

    <div class="bg-surface-container-lowest p-5 rounded-xl border border-border-subtle shadow-sm">
        <div class="flex items-center justify-between mb-3">
            <div class="w-10 h-10 rounded-lg bg-red-50 flex items-center justify-center text-red-600">
                <span class="material-symbols-outlined">cancel</span>
            </div>
            <span class="text-xs font-semibold text-red-600">Today</span>
        </div>
        <h3 class="text-on-surface-variant text-xs font-semibold uppercase tracking-wider mb-1">ABSENT</h3>
        <p class="text-3xl font-bold text-on-surface" id="statAbsentToday">0</p>
    </div>

    <div class="bg-primary-container p-5 rounded-xl border border-primary shadow-sm text-white">
        <div class="flex items-center justify-between mb-3">
            <div class="w-10 h-10 rounded-lg bg-white/10 flex items-center justify-center text-secondary-container">
                <span class="material-symbols-outlined">sensors</span>
            </div>
            <span class="flex h-2 w-2 rounded-full bg-secondary-container animate-pulse"></span>
        </div>
        <h3 class="text-on-primary-container text-xs font-semibold uppercase tracking-wider mb-1">CURRENTLY IN SCHOOL</h3>
        <p class="text-3xl font-bold" id="statCurrentlyIn">0</p>
    </div>

    <div class="bg-surface-container-lowest p-5 rounded-xl border border-border-subtle shadow-sm">
        <div class="flex items-center justify-between mb-3">
            <div class="w-10 h-10 rounded-lg bg-yellow-50 flex items-center justify-center text-yellow-600">
                <span class="material-symbols-outlined">fingerprint</span>
            </div>
            <span class="text-xs font-semibold text-yellow-600">Pending</span>
        </div>
        <h3 class="text-on-surface-variant text-xs font-semibold uppercase tracking-wider mb-1">AWAITING FINGERPRINT</h3>
        <p class="text-3xl font-bold text-on-surface" id="statAwaitingFp">0</p>
    </div>
</div>

<!-- Main Grid -->
<div class="grid grid-cols-1 lg:grid-cols-12 gap-8 items-start">
    <!-- Class Summary & Actions -->
    <div class="lg:col-span-7 space-y-6">
        <div class="bg-surface-container-lowest p-6 rounded-xl border border-border-subtle shadow-sm">
            <div class="flex justify-between items-center mb-6">
                <h3 class="font-bold text-lg text-on-surface">Quick Access Modules</h3>
            </div>
            <div class="grid grid-cols-2 md:grid-cols-3 gap-4">
                <a href="students.php" class="p-4 border border-border-subtle rounded-xl hover:bg-surface-container-low transition-all text-center block">
                    <span class="material-symbols-outlined text-3xl text-primary mb-2">groups</span>
                    <h4 class="font-semibold text-sm">Student Directory</h4>
                    <p class="text-xs text-on-surface-variant">View & Manage</p>
                </a>
                <a href="students.php?open_import=1" class="p-4 border border-border-subtle rounded-xl hover:bg-surface-container-low transition-all text-center block">
                    <span class="material-symbols-outlined text-3xl text-primary mb-2">upload_file</span>
                    <h4 class="font-semibold text-sm">CSV / Excel Import</h4>
                    <p class="text-xs text-on-surface-variant">Bulk Student Import</p>
                </a>
                <a href="enroll_fingerprint.php" class="p-4 border border-border-subtle rounded-xl hover:bg-surface-container-low transition-all text-center block">
                    <span class="material-symbols-outlined text-3xl text-primary mb-2">fingerprint</span>
                    <h4 class="font-semibold text-sm">Biometric Enroll</h4>
                    <p class="text-xs text-on-surface-variant">Link Templates</p>
                </a>
                <a href="attendance_records.php" class="p-4 border border-border-subtle rounded-xl hover:bg-surface-container-low transition-all text-center block">
                    <span class="material-symbols-outlined text-3xl text-primary mb-2">history</span>
                    <h4 class="font-semibold text-sm">Attendance Logs</h4>
                    <p class="text-xs text-on-surface-variant">Check-In / Out</p>
                </a>
                <a href="reports.php" class="p-4 border border-border-subtle rounded-xl hover:bg-surface-container-low transition-all text-center block">
                    <span class="material-symbols-outlined text-3xl text-primary mb-2">article</span>
                    <h4 class="font-semibold text-sm">Reports & PDF</h4>
                    <p class="text-xs text-on-surface-variant">Export & Print</p>
                </a>
                <a href="../index.php" target="_blank" class="p-4 border border-border-subtle rounded-xl hover:bg-surface-container-low transition-all text-center block">
                    <span class="material-symbols-outlined text-3xl text-primary mb-2">desktop_windows</span>
                    <h4 class="font-semibold text-sm">Public Kiosk</h4>
                    <p class="text-xs text-on-surface-variant">Scanner Mode</p>
                </a>
            </div>
        </div>
    </div>

    <!-- Live Activity Log Sidebar -->
    <div class="lg:col-span-5 bg-surface-container-lowest rounded-xl border border-border-subtle shadow-sm flex flex-col">
        <div class="p-4 border-b border-border-subtle flex justify-between items-center bg-surface-container-low/50 rounded-t-xl">
            <h3 class="font-bold text-base">Today's Attendance Activity</h3>
            <span class="flex items-center gap-1.5 text-secondary font-bold text-xs bg-secondary-container/20 px-2.5 py-1 rounded-full">
                <span class="w-2 h-2 rounded-full bg-secondary animate-pulse"></span> LIVE
            </span>
        </div>
        <div class="p-4 space-y-4 max-h-[500px] overflow-y-auto custom-scrollbar" id="recentActivityList">
            <p class="text-xs text-center text-on-surface-variant py-8">Loading activity...</p>
        </div>
        <div class="p-3 border-t border-border-subtle text-center">
            <a href="attendance_records.php" class="text-primary font-bold text-xs hover:underline">View All Records &rarr;</a>
        </div>
    </div>
</div>

<script>
    async function loadDashboardStats() {
        try {
            const resp = await fetch('../api/attendance.php?action=dashboard_stats');
            const data = await resp.json();
            if (data.success) {
                document.getElementById('statTotalStudents').innerText = data.stats.total_students.toLocaleString();
                document.getElementById('statPresentToday').innerText = data.stats.present_today.toLocaleString();
                document.getElementById('statAbsentToday').innerText = data.stats.absent_today.toLocaleString();
                document.getElementById('statCurrentlyIn').innerText = data.stats.currently_in_school.toLocaleString();
                document.getElementById('statAwaitingFp').innerText = data.stats.awaiting_fingerprint.toLocaleString();

                const listContainer = document.getElementById('recentActivityList');
                if (data.recent_activity.length === 0) {
                    listContainer.innerHTML = '<p class="text-xs text-center text-on-surface-variant py-8">No attendance records logged today yet.</p>';
                } else {
                    listContainer.innerHTML = data.recent_activity.map(act => `
                        <div class="flex items-center gap-3 p-2 border-b border-border-subtle/50 last:border-0">
                            <div class="w-10 h-10 rounded-full bg-surface-container flex items-center justify-center font-bold text-primary text-sm flex-shrink-0">
                                ${act.firstname.charAt(0)}${act.surname.charAt(0)}
                            </div>
                            <div class="flex-grow min-w-0">
                                <p class="text-sm font-semibold text-on-surface truncate">${act.surname} ${act.firstname}</p>
                                <p class="text-xs text-on-surface-variant">${act.class} &bull; ${act.admission_number}</p>
                            </div>
                            <div class="text-right flex-shrink-0">
                                <span class="text-xs font-bold px-2 py-0.5 rounded ${act.check_out ? 'bg-blue-100 text-blue-800' : 'bg-green-100 text-green-800'}">
                                    ${act.check_out ? 'Checked Out' : 'Checked In'}
                                </span>
                                <p class="text-[10px] text-on-surface-variant mt-1">${act.check_out || act.check_in || ''}</p>
                            </div>
                        </div>
                    `).join('');
                }
            }
        } catch (e) {
            console.error(e);
        }
    }

    loadDashboardStats();
    setInterval(loadDashboardStats, 10000);
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
