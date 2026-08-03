<?php
$pageTitle = 'Register Student';
$activePage = 'students';
require_once __DIR__ . '/includes/header.php';

$studentId = (int)($_GET['id'] ?? 0);
?>

<div class="mb-6 flex items-center justify-between">
    <div>
        <h2 class="font-headline-lg text-2xl font-bold text-on-surface"><?= $studentId > 0 ? 'Edit Student Profile' : 'Register New Student' ?></h2>
        <p class="font-body-md text-on-surface-variant text-sm">Enter complete student details for High-Q Solid Academy records</p>
    </div>
    <a href="students.php" class="px-4 py-2 border border-border-subtle text-primary font-semibold rounded-lg text-sm flex items-center gap-2 hover:bg-surface-container transition-colors">
        <span class="material-symbols-outlined text-sm">arrow_back</span> Back to Directory
    </a>
</div>

<div class="bg-surface-container-lowest p-6 md:p-8 rounded-xl border border-border-subtle shadow-sm max-w-4xl mx-auto">
    <form id="studentForm" class="space-y-6" enctype="multipart/form-data">
        <input type="hidden" name="id" id="studentId" value="<?= $studentId ?>">

        <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
            <!-- Admission Number -->
            <div class="space-y-2">
                <label class="block text-xs font-semibold uppercase text-on-surface-variant">Admission Number *</label>
                <input type="text" name="admission_number" id="admission_number" required placeholder="e.g. HQ/2026/001" class="w-full px-4 py-2.5 bg-surface-gray border border-border-subtle rounded-lg text-sm focus:outline-none focus:border-primary">
            </div>

            <!-- Surname -->
            <div class="space-y-2">
                <label class="block text-xs font-semibold uppercase text-on-surface-variant">Surname *</label>
                <input type="text" name="surname" id="surname" required placeholder="Surname" class="w-full px-4 py-2.5 bg-surface-gray border border-border-subtle rounded-lg text-sm focus:outline-none focus:border-primary">
            </div>

            <!-- First Name -->
            <div class="space-y-2">
                <label class="block text-xs font-semibold uppercase text-on-surface-variant">First Name *</label>
                <input type="text" name="firstname" id="firstname" required placeholder="First Name" class="w-full px-4 py-2.5 bg-surface-gray border border-border-subtle rounded-lg text-sm focus:outline-none focus:border-primary">
            </div>

            <!-- Middle Name -->
            <div class="space-y-2">
                <label class="block text-xs font-semibold uppercase text-on-surface-variant">Middle Name</label>
                <input type="text" name="middlename" id="middlename" placeholder="Middle Name" class="w-full px-4 py-2.5 bg-surface-gray border border-border-subtle rounded-lg text-sm focus:outline-none focus:border-primary">
            </div>

            <!-- Gender -->
            <div class="space-y-2">
                <label class="block text-xs font-semibold uppercase text-on-surface-variant">Gender *</label>
                <select name="gender" id="gender" required class="w-full px-4 py-2.5 bg-surface-gray border border-border-subtle rounded-lg text-sm focus:outline-none focus:border-primary">
                    <option value="Male">Male</option>
                    <option value="Female">Female</option>
                    <option value="Other">Other</option>
                </select>
            </div>

            <!-- Class -->
            <div class="space-y-2">
                <label class="block text-xs font-semibold uppercase text-on-surface-variant">Class *</label>
                <select name="class" id="class" required class="w-full px-4 py-2.5 bg-surface-gray border border-border-subtle rounded-lg text-sm focus:outline-none focus:border-primary">
                    <option value="Basic 1">Basic 1</option>
                    <option value="Basic 2">Basic 2</option>
                    <option value="Basic 3">Basic 3</option>
                    <option value="Basic 4">Basic 4</option>
                    <option value="Basic 5">Basic 5</option>
                    <option value="JSS 1">JSS 1</option>
                    <option value="JSS 2">JSS 2</option>
                    <option value="JSS 3">JSS 3</option>
                    <option value="SSS 1">SSS 1</option>
                    <option value="SSS 2">SSS 2</option>
                    <option value="SSS 3">SSS 3</option>
                </select>
            </div>

            <!-- Date of Birth -->
            <div class="space-y-2">
                <label class="block text-xs font-semibold uppercase text-on-surface-variant">Date of Birth</label>
                <input type="date" name="dob" id="dob" class="w-full px-4 py-2.5 bg-surface-gray border border-border-subtle rounded-lg text-sm focus:outline-none focus:border-primary">
            </div>

            <!-- Status -->
            <div class="space-y-2">
                <label class="block text-xs font-semibold uppercase text-on-surface-variant">Status</label>
                <select name="status" id="status" class="w-full px-4 py-2.5 bg-surface-gray border border-border-subtle rounded-lg text-sm focus:outline-none focus:border-primary">
                    <option value="Awaiting Fingerprint">Awaiting Fingerprint</option>
                    <option value="Fingerprint Linked">Fingerprint Linked</option>
                    <option value="Registered">Registered</option>
                    <option value="Inactive">Inactive</option>
                </select>
            </div>
        </div>

        <hr class="border-border-subtle my-6">

        <!-- Parent & Guardian Info -->
        <h3 class="font-bold text-base text-on-surface mb-4">Parent / Guardian Contact Information</h3>
        <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
            <div class="space-y-2">
                <label class="block text-xs font-semibold uppercase text-on-surface-variant">Parent / Guardian Name</label>
                <input type="text" name="parent_name" id="parent_name" placeholder="Full Name" class="w-full px-4 py-2.5 bg-surface-gray border border-border-subtle rounded-lg text-sm focus:outline-none focus:border-primary">
            </div>

            <div class="space-y-2">
                <label class="block text-xs font-semibold uppercase text-on-surface-variant">Parent Phone Number</label>
                <input type="tel" name="parent_phone" id="parent_phone" placeholder="08012345678" class="w-full px-4 py-2.5 bg-surface-gray border border-border-subtle rounded-lg text-sm focus:outline-none focus:border-primary">
            </div>

            <div class="space-y-2">
                <label class="block text-xs font-semibold uppercase text-on-surface-variant">Parent Email Address</label>
                <input type="email" name="parent_email" id="parent_email" placeholder="parent@example.com" class="w-full px-4 py-2.5 bg-surface-gray border border-border-subtle rounded-lg text-sm focus:outline-none focus:border-primary">
            </div>

            <div class="md:col-span-3 space-y-2">
                <label class="block text-xs font-semibold uppercase text-on-surface-variant">Home Address</label>
                <textarea name="address" id="address" rows="2" placeholder="Residential Address" class="w-full px-4 py-2.5 bg-surface-gray border border-border-subtle rounded-lg text-sm focus:outline-none focus:border-primary"></textarea>
            </div>
        </div>

        <div class="flex justify-end gap-3 pt-4 border-t border-border-subtle">
            <a href="students.php" class="px-6 py-2.5 border border-border-subtle rounded-lg text-sm font-semibold hover:bg-surface-container">Cancel</a>
            <button type="submit" id="saveBtn" class="px-6 py-2.5 bg-primary text-white rounded-lg text-sm font-semibold hover:bg-navy-muted shadow flex items-center gap-2">
                <span class="material-symbols-outlined text-sm">save</span> Save Student Profile
            </button>
        </div>
    </form>
</div>

<script>
    const studentId = <?= $studentId ?>;

    async function loadStudentData() {
        if (studentId <= 0) return;
        try {
            const resp = await fetch(`api/students.php?action=get&id=${studentId}`);
            const data = await resp.json();
            if (data.success && data.student) {
                const s = data.student;
                document.getElementById('admission_number').value = s.admission_number || '';
                document.getElementById('surname').value = s.surname || '';
                document.getElementById('firstname').value = s.firstname || '';
                document.getElementById('middlename').value = s.middlename || '';
                document.getElementById('gender').value = s.gender || 'Male';
                document.getElementById('class').value = s.class || 'Basic 1';
                document.getElementById('dob').value = s.dob || '';
                document.getElementById('status').value = s.status || 'Awaiting Fingerprint';
                document.getElementById('parent_name').value = s.parent_name || '';
                document.getElementById('parent_phone').value = s.parent_phone || '';
                document.getElementById('parent_email').value = s.parent_email || '';
                document.getElementById('address').value = s.address || '';
            }
        } catch (e) {
            console.error(e);
        }
    }

    document.getElementById('studentForm').addEventListener('submit', async function(e) {
        e.preventDefault();
        const btn = document.getElementById('saveBtn');
        btn.disabled = true;
        btn.innerHTML = '<span class="material-symbols-outlined animate-spin text-sm">progress_activity</span> Saving...';

        const formData = new FormData(this);
        formData.append('action', 'save');

        try {
            const resp = await fetch('api/students.php', {
                method: 'POST',
                body: formData
            });
            const data = await resp.json();
            btn.disabled = false;
            btn.innerHTML = '<span class="material-symbols-outlined text-sm">save</span> Save Student Profile';

            if (data.success) {
                alert(data.message);
                if (studentId <= 0 && confirm('Would you like to enroll a fingerprint for this student now?')) {
                    window.location.href = `enroll_fingerprint.php?student_id=${data.id}`;
                } else {
                    window.location.href = 'students.php';
                }
            } else {
                alert(data.message || 'Error saving student');
            }
        } catch (err) {
            btn.disabled = false;
            btn.innerHTML = '<span class="material-symbols-outlined text-sm">save</span> Save Student Profile';
            alert('Server error saving student.');
        }
    });

    loadStudentData();
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
