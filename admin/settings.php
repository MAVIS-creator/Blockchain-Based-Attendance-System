<?php
$pageTitle = 'System Settings';
$activePage = 'settings';
require_once __DIR__ . '/includes/header.php';
?>

<div class="mb-6">
    <h2 class="font-headline-lg text-2xl font-bold text-on-surface">System Settings</h2>
    <p class="font-body-md text-on-surface-variant text-sm">Configure High-Q Solid Academy operational parameters and lesson types</p>
</div>

<div class="grid grid-cols-1 gap-8 max-w-4xl">
    <!-- Main Settings Form -->
    <div class="bg-surface-container-lowest p-6 md:p-8 rounded-xl border border-border-subtle shadow-sm space-y-6">
        <h3 class="font-bold text-base text-on-surface border-b border-border-subtle pb-3">School & Operating Hours</h3>

        <form id="settingsForm" class="space-y-6">
            <div class="space-y-2">
                <label class="block text-xs font-semibold uppercase text-on-surface-variant">Academy Name</label>
                <input type="text" name="school_name" id="school_name" value="High-Q Solid Academy" required class="w-full px-4 py-2.5 bg-surface-gray border border-border-subtle rounded-lg text-sm focus:outline-none focus:border-primary">
            </div>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                <div class="space-y-2">
                    <label class="block text-xs font-semibold uppercase text-on-surface-variant">Attendance Start Time</label>
                    <input type="time" name="attendance_start_time" id="attendance_start_time" value="07:30" required class="w-full px-4 py-2.5 bg-surface-gray border border-border-subtle rounded-lg text-sm focus:outline-none focus:border-primary">
                </div>

                <div class="space-y-2">
                    <label class="block text-xs font-semibold uppercase text-on-surface-variant">Late Threshold Time</label>
                    <input type="time" name="late_threshold_time" id="late_threshold_time" value="08:00" required class="w-full px-4 py-2.5 bg-surface-gray border border-border-subtle rounded-lg text-sm focus:outline-none focus:border-primary">
                </div>

                <div class="space-y-2">
                    <label class="block text-xs font-semibold uppercase text-on-surface-variant">Closing Time</label>
                    <input type="time" name="attendance_closing_time" id="attendance_closing_time" value="15:30" required class="w-full px-4 py-2.5 bg-surface-gray border border-border-subtle rounded-lg text-sm focus:outline-none focus:border-primary">
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-6 pt-2 border-t border-border-subtle">
                <div class="space-y-2">
                    <label class="block text-xs font-semibold uppercase text-on-surface-variant flex items-center gap-1">
                        <span class="material-symbols-outlined text-sm text-primary">lock</span> Public Terminal Access PIN
                    </label>
                    <input type="password" name="terminal_pin" id="terminal_pin" placeholder="Enter 4-digit PIN (e.g. 1234)" maxlength="8" class="w-full px-4 py-2.5 bg-surface-gray border border-border-subtle rounded-lg text-sm focus:outline-none focus:border-primary font-mono tracking-widest">
                    <p class="text-[11px] text-on-surface-variant">Require a PIN to unlock the kiosk attendance terminal. Leave empty to disable.</p>
                </div>
            </div>

            <div class="flex justify-end pt-4 border-t border-border-subtle">
                <button type="submit" id="saveSetBtn" class="px-6 py-2.5 bg-primary text-white font-semibold rounded-lg text-sm hover:bg-navy-muted shadow flex items-center gap-2">
                    <span class="material-symbols-outlined text-sm">save</span> Save Settings
                </button>
            </div>
        </form>
    </div>

    <!-- Dynamic Lesson Type Management Card -->
    <div class="bg-surface-container-lowest p-6 md:p-8 rounded-xl border border-border-subtle shadow-sm space-y-6">
        <div class="flex justify-between items-center border-b border-border-subtle pb-3">
            <div>
                <h3 class="font-bold text-base text-on-surface flex items-center gap-2">
                    <span class="material-symbols-outlined text-primary">menu_book</span> Lesson Type Management
                </h3>
                <p class="text-xs text-on-surface-variant">Add or remove lesson types used across student registration and reports</p>
            </div>
            <span class="px-2.5 py-1 bg-surface-container text-xs font-bold rounded-full text-primary" id="classCountBadge">0 Lesson Types</span>
        </div>

        <!-- Add New Lesson Type Form -->
        <form id="addClassForm" class="flex items-center gap-3">
            <div class="flex-1">
                <input type="text" id="newClassName" required placeholder="Enter new lesson type (e.g. JAMB, WAEC, NECO, GCE, Post UTME)" class="w-full px-4 py-2.5 bg-surface-gray border border-border-subtle rounded-lg text-sm focus:outline-none focus:border-primary">
            </div>
            <button type="submit" id="addClassBtn" class="px-5 py-2.5 bg-primary text-white font-semibold rounded-lg text-sm hover:bg-navy-muted shadow flex items-center gap-1.5 whitespace-nowrap">
                <span class="material-symbols-outlined text-sm">add</span> Add Lesson Type
            </button>
        </form>

        <!-- Current Lesson Types Grid -->
        <div id="classesListContainer" class="grid grid-cols-2 md:grid-cols-4 gap-3 pt-2">
            <div class="col-span-full text-center py-6 text-xs text-on-surface-variant">Loading lesson types...</div>
        </div>
    </div>
</div>

<script>
    async function loadSettings() {
        try {
            const resp = await fetch('../api/settings.php?action=get');
            const data = await resp.json();
            if (data.success && data.settings) {
                const s = data.settings;
                document.getElementById('school_name').value = s.school_name || 'High-Q Solid Academy';
                document.getElementById('attendance_start_time').value = s.attendance_start_time || '07:30';
                document.getElementById('late_threshold_time').value = s.late_threshold_time || '08:00';
                document.getElementById('attendance_closing_time').value = s.attendance_closing_time || '15:30';
                if (document.getElementById('terminal_pin')) {
                    document.getElementById('terminal_pin').value = s.terminal_pin || '';
                }
            }
        } catch (e) {
            console.error(e);
        }
    }

    async function loadClasses() {
        const container = document.getElementById('classesListContainer');
        const badge = document.getElementById('classCountBadge');

        try {
            const resp = await fetch('../api/classes.php?action=list');
            const data = await resp.json();

            if (data.success && data.classes) {
                badge.innerText = `${data.classes.length} Lesson Types`;
                if (data.classes.length === 0) {
                    container.innerHTML = '<div class="col-span-full text-center py-6 text-xs text-on-surface-variant">No lesson types configured. Add one above!</div>';
                    return;
                }

                container.innerHTML = data.classes.map(c => `
                    <div class="p-3 bg-surface-container-low border border-border-subtle rounded-xl flex items-center justify-between group hover:border-primary transition-all">
                        <div class="flex items-center gap-2">
                            <span class="material-symbols-outlined text-sm text-primary">menu_book</span>
                            <span class="font-bold text-xs text-on-surface">${c.name}</span>
                        </div>
                        <button onclick="deleteClass(${c.id}, '${c.name}')" type="button" class="text-on-surface-variant hover:text-error transition-colors p-1 rounded hover:bg-error-container/20" title="Delete ${c.name}">
                            <span class="material-symbols-outlined text-sm">close</span>
                        </button>
                    </div>
                `).join('');
            }
        } catch (e) {
            console.error(e);
            container.innerHTML = '<div class="col-span-full text-center py-6 text-xs text-error">Failed to load lesson types</div>';
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
            const resp = await fetch('../api/settings.php', {
                method: 'POST',
                body: formData
            });
            const data = await resp.json();
            btn.disabled = false;
            btn.innerHTML = '<span class="material-symbols-outlined text-sm">save</span> Save Settings';

            if (data.success) {
                HighQSwal.fire('Settings Saved', data.message || 'System settings saved successfully.', 'success');
            } else {
                HighQSwal.fire('Error', data.message || 'Error saving settings', 'error');
            }
        } catch (err) {
            btn.disabled = false;
            btn.innerHTML = '<span class="material-symbols-outlined text-sm">save</span> Save Settings';
            HighQSwal.fire('Error', 'Server error saving settings.', 'error');
        }
    });

    document.getElementById('addClassForm').addEventListener('submit', async function(e) {
        e.preventDefault();
        const input = document.getElementById('newClassName');
        const className = input.value.trim();
        if (!className) return;

        const btn = document.getElementById('addClassBtn');
        btn.disabled = true;

        const formData = new FormData();
        formData.append('action', 'add');
        formData.append('name', className);

        try {
            const resp = await fetch('../api/classes.php', {
                method: 'POST',
                body: formData
            });
            const data = await resp.json();
            btn.disabled = false;

            if (data.success) {
                input.value = '';
                HighQSwal.fire('Lesson Type Added', `'${className}' has been added to available lesson types.`, 'success');
                loadClasses();
            } else {
                HighQSwal.fire('Error', data.message || 'Error adding lesson type', 'error');
            }
        } catch (err) {
            btn.disabled = false;
            HighQSwal.fire('Error', 'Server error occurred.', 'error');
        }
    });

    async function deleteClass(id, className) {
        const confirmRes = await HighQSwal.fire({
            title: 'Remove Lesson Type?',
            text: `Are you sure you want to remove '${className}'?`,
            icon: 'warning',
            showCancelButton: true,
            confirmButtonText: 'Yes, Remove',
            cancelButtonText: 'Cancel'
        });

        if (!confirmRes.isConfirmed) return;

        const formData = new FormData();
        formData.append('action', 'delete');
        formData.append('id', id);

        try {
            const resp = await fetch('../api/classes.php', {
                method: 'POST',
                body: formData
            });
            const data = await resp.json();
            if (data.success) {
                HighQSwal.fire('Removed', `'${className}' was removed.`, 'success');
                loadClasses();
            } else {
                HighQSwal.fire('Error', data.message || 'Error removing lesson type', 'error');
            }
        } catch (e) {
            HighQSwal.fire('Error', 'Server error occurred.', 'error');
        }
    }

    loadSettings();
    loadClasses();
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
