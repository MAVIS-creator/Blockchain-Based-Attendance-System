<?php
$pageTitle = 'Settings';
$activePage = 'settings';
require_once __DIR__ . '/includes/header.php';
?>

<div class="mb-6">
    <h2 class="font-headline-lg text-2xl font-bold text-on-surface">System Settings</h2>
    <p class="font-body-md text-on-surface-variant text-sm">Configure school branding, attendance hours, and biometric thresholds</p>
</div>

<div class="bg-surface-container-lowest p-6 md:p-8 rounded-xl border border-border-subtle shadow-sm max-w-3xl mx-auto">
    <form id="settingsForm" class="space-y-6" enctype="multipart/form-data">
        <div class="space-y-4">
            <h3 class="font-bold text-base text-on-surface border-b border-border-subtle pb-2">School Information</h3>

            <div class="space-y-2">
                <label class="block text-xs font-semibold uppercase text-on-surface-variant">School Name</label>
                <input type="text" name="school_name" id="school_name" class="w-full px-4 py-2.5 bg-surface-gray border border-border-subtle rounded-lg text-sm focus:outline-none focus:border-primary">
            </div>
        </div>

        <div class="space-y-4 pt-4">
            <h3 class="font-bold text-base text-on-surface border-b border-border-subtle pb-2">Attendance Time Thresholds</h3>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                <div class="space-y-2">
                    <label class="block text-xs font-semibold uppercase text-on-surface-variant">Attendance Start Time</label>
                    <input type="time" name="attendance_start_time" id="attendance_start_time" class="w-full px-4 py-2.5 bg-surface-gray border border-border-subtle rounded-lg text-sm focus:outline-none">
                </div>

                <div class="space-y-2">
                    <label class="block text-xs font-semibold uppercase text-on-surface-variant">Late Threshold Time</label>
                    <input type="time" name="late_threshold_time" id="late_threshold_time" class="w-full px-4 py-2.5 bg-surface-gray border border-border-subtle rounded-lg text-sm focus:outline-none">
                </div>

                <div class="space-y-2">
                    <label class="block text-xs font-semibold uppercase text-on-surface-variant">Attendance Closing Time</label>
                    <input type="time" name="attendance_closing_time" id="attendance_closing_time" class="w-full px-4 py-2.5 bg-surface-gray border border-border-subtle rounded-lg text-sm focus:outline-none">
                </div>
            </div>
        </div>

        <div class="flex justify-end pt-4 border-t border-border-subtle">
            <button type="submit" id="saveSetBtn" class="px-6 py-2.5 bg-primary text-white font-semibold rounded-lg text-sm hover:bg-navy-muted shadow flex items-center gap-2">
                <span class="material-symbols-outlined text-sm">save</span> Save Settings
            </button>
        </div>
    </form>
</div>

<script>
    async function loadSettings() {
        try {
            const resp = await fetch('api/settings.php?action=get');
            const data = await resp.json();
            if (data.success && data.settings) {
                const s = data.settings;
                document.getElementById('school_name').value = s.school_name || 'High-Q Solid Academy';
                document.getElementById('attendance_start_time').value = s.attendance_start_time || '07:30';
                document.getElementById('late_threshold_time').value = s.late_threshold_time || '08:00';
                document.getElementById('attendance_closing_time').value = s.attendance_closing_time || '15:30';
            }
        } catch (e) {
            console.error(e);
        }
    }

    document.getElementById('settingsForm').addEventListener('submit', async function(e) {
        e.preventDefault();
        const btn = document.getElementById('saveSetBtn');
        btn.disabled = true;
        btn.innerHTML = '<span class="material-symbols-outlined animate-spin text-sm">progress_activity</span> Saving...';

        const formData = new FormData(this);
        formData.append('action', 'save');

        try {
            const resp = await fetch('api/settings.php', {
                method: 'POST',
                body: formData
            });
            const data = await resp.json();
            btn.disabled = false;
            btn.innerHTML = '<span class="material-symbols-outlined text-sm">save</span> Save Settings';

            if (data.success) {
                alert(data.message);
            } else {
                alert(data.message || 'Error saving settings');
            }
        } catch (err) {
            btn.disabled = false;
            btn.innerHTML = '<span class="material-symbols-outlined text-sm">save</span> Save Settings';
            alert('Server error.');
        }
    });

    loadSettings();
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
