<?php
$pageTitle = 'Register Student';
$activePage = 'students';
require_once __DIR__ . '/includes/header.php';

$studentId = (int)($_GET['id'] ?? 0);
?>

<div class="mb-6 flex flex-col md:flex-row md:items-center justify-between gap-4">
    <div>
        <h2 class="font-headline-lg text-2xl font-bold text-on-surface"><?= $studentId > 0 ? 'Edit Student Profile' : 'Register New Student' ?></h2>
        <p class="font-body-md text-on-surface-variant text-sm">Enter complete student details for High-Q Solid Academy records</p>
    </div>
    <div class="flex items-center gap-3">
        <a href="print_sample_registration_form.php<?= $studentId > 0 ? '?id='.$studentId : '' ?>" target="_blank" class="px-4 py-2 bg-surface-container border border-border-subtle text-primary font-semibold rounded-lg text-sm flex items-center gap-2 hover:bg-surface-container-high transition-colors">
            <span class="material-symbols-outlined text-sm">print</span> Export Sample PDF Form
        </a>
        <a href="students.php" class="px-4 py-2 border border-border-subtle text-primary font-semibold rounded-lg text-sm flex items-center gap-2 hover:bg-surface-container transition-colors">
            <span class="material-symbols-outlined text-sm">arrow_back</span> Back to Directory
        </a>
    </div>
</div>

<div class="bg-surface-container-lowest p-6 md:p-8 rounded-xl border border-border-subtle shadow-sm max-w-4xl mx-auto">
    <form id="studentForm" class="space-y-6" enctype="multipart/form-data">
        <input type="hidden" name="id" id="studentId" value="<?= $studentId ?>">

        <!-- Top Section: Photo & Basic Details -->
        <div class="flex flex-col md:flex-row gap-6 items-start border-b border-border-subtle pb-6">
            <!-- Passport Photo Upload Box -->
            <div class="flex flex-col items-center gap-2 w-full md:w-44 flex-shrink-0">
                <label class="block text-xs font-semibold uppercase text-on-surface-variant text-center">Passport Photo</label>
                <div class="relative w-36 h-44 rounded-xl border-2 border-dashed border-border-subtle bg-surface-gray overflow-hidden flex flex-col items-center justify-center text-center p-2 group hover:border-primary transition-all">
                    <img id="photoPreview" src="" class="absolute inset-0 w-full h-full object-cover hidden" alt="Student Photo">
                    <div id="photoPlaceholder" class="flex flex-col items-center gap-1 text-on-surface-variant">
                        <span class="material-symbols-outlined text-4xl">account_box</span>
                        <span class="text-[11px] font-semibold">Upload Photo</span>
                        <span class="text-[9px] text-outline">JPG, PNG (Max 2MB)</span>
                    </div>
                    <input type="file" name="photo" id="photoInput" accept="image/*" onchange="previewPhoto(this)" class="absolute inset-0 opacity-0 cursor-pointer w-full h-full">
                </div>
                <button type="button" onclick="document.getElementById('photoInput').click()" class="px-3 py-1 bg-surface-container text-primary text-xs font-semibold rounded-lg hover:bg-surface-container-high transition-colors flex items-center gap-1">
                    <span class="material-symbols-outlined text-xs">add_a_photo</span> Select Photo
                </button>
            </div>

            <!-- Basic Student Info -->
            <div class="flex-grow grid grid-cols-1 md:grid-cols-2 gap-4 w-full">
                <!-- Admission Number -->
                <div class="space-y-2">
                    <div class="flex justify-between items-center">
                        <label class="block text-xs font-semibold uppercase text-on-surface-variant">Admission Number *</label>
                        <?php if ($studentId <= 0): ?>
                            <button type="button" onclick="generateNextAdmissionNumber()" class="text-[11px] text-primary font-bold hover:underline flex items-center gap-0.5" title="Generate next sequential admission number">
                                ⚡ Auto-Generate
                            </button>
                        <?php endif; ?>
                    </div>
                    <input type="text" name="admission_number" id="admission_number" required placeholder="e.g. HQ/2026/001" class="w-full px-4 py-2.5 bg-surface-gray border border-border-subtle rounded-lg text-sm focus:outline-none focus:border-primary" readonly>
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
            </div>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
            <!-- Gender -->
            <div class="space-y-2">
                <label class="block text-xs font-semibold uppercase text-on-surface-variant">Gender *</label>
                <select name="gender" id="gender" required class="w-full px-4 py-2.5 bg-surface-gray border border-border-subtle rounded-lg text-sm focus:outline-none focus:border-primary">
                    <option value="Male">Male</option>
                    <option value="Female">Female</option>
                    <option value="Other">Other</option>
                </select>
            </div>

            <!-- Lesson Type (Multi-Select Checkboxes) -->
            <div class="space-y-2 md:col-span-2">
                <div class="flex justify-between items-center">
                    <label class="block text-xs font-semibold uppercase text-on-surface-variant">Lesson Type(s) * <span class="text-[10px] text-outline font-normal lowercase">(select one or more e.g. WAEC, GCE)</span></label>
                    <a href="settings.php" target="_blank" class="text-[11px] text-primary font-bold hover:underline" title="Manage lesson types in settings">+ Manage Lesson Types</a>
                </div>
                <div id="lessonTypesContainer" class="p-3 bg-surface-gray border border-border-subtle rounded-lg flex flex-wrap gap-2.5 min-h-[46px] items-center">
                    <span class="text-xs text-on-surface-variant">Loading lesson types...</span>
                </div>
                <input type="hidden" name="class" id="class" required>
            </div>

            <!-- Date of Birth -->
            <div class="space-y-2">
                <label class="block text-xs font-semibold uppercase text-on-surface-variant">Date of Birth</label>
                <input type="date" name="dob" id="dob" class="w-full px-4 py-2.5 bg-surface-gray border border-border-subtle rounded-lg text-sm focus:outline-none focus:border-primary">
            </div>

            <!-- Status -->
            <div class="space-y-2 md:col-span-3">
                <label class="block text-xs font-semibold uppercase text-on-surface-variant">Status</label>
                <?php if ($studentId <= 0): ?>
                    <!-- Hidden field to submit Awaiting Fingerprint -->
                    <input type="hidden" name="status" value="Awaiting Fingerprint">
                    <div class="w-full px-4 py-2.5 bg-surface-container-low border border-border-subtle rounded-lg text-sm font-semibold text-primary flex items-center justify-between">
                        <span>Awaiting Fingerprint</span>
                        <span class="text-[10px] bg-yellow-100 text-yellow-800 px-2 py-0.5 rounded font-bold">Auto-Assigned</span>
                    </div>
                    <p class="text-[11px] text-on-surface-variant italic">Default status for new student registration</p>
                <?php else: ?>
                    <select name="status" id="status" class="w-full px-4 py-2.5 bg-surface-gray border border-border-subtle rounded-lg text-sm focus:outline-none focus:border-primary">
                        <option value="Awaiting Fingerprint">Awaiting Fingerprint</option>
                        <option value="Fingerprint Linked">Fingerprint Linked</option>
                        <option value="Registered">Registered</option>
                        <option value="Inactive">Inactive</option>
                    </select>
                <?php endif; ?>
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

    function previewPhoto(input) {
        const preview = document.getElementById('photoPreview');
        const placeholder = document.getElementById('photoPlaceholder');
        if (input.files && input.files[0]) {
            const reader = new FileReader();
            reader.onload = function(e) {
                preview.src = e.target.result;
                preview.classList.remove('hidden');
                placeholder.classList.add('hidden');
            }
            reader.readAsDataURL(input.files[0]);
        }
    }

    async function loadClasses(selectedClassStr = '') {
        const selectedArray = selectedClassStr ? selectedClassStr.split(',').map(s => s.trim()) : [];
        try {
            const resp = await fetch('../api/classes.php?action=list');
            const data = await resp.json();
            let types = [];

            if (data.success && data.classes && data.classes.length > 0) {
                types = data.classes.map(c => c.name);
            } else {
                types = ['JAMB', 'WAEC', 'NECO', 'GCE', 'Post UTME', 'NABTEB', 'JUPEB', 'IJMB'];
            }
            renderLessonTypeCheckboxes(types, selectedArray);
        } catch (e) {
            console.error(e);
            renderLessonTypeCheckboxes(['JAMB', 'WAEC', 'NECO', 'GCE', 'Post UTME', 'NABTEB', 'JUPEB', 'IJMB'], selectedArray);
        }
    }

    function renderLessonTypeCheckboxes(types, selectedArray) {
        const container = document.getElementById('lessonTypesContainer');
        container.innerHTML = types.map(tName => {
            const isChecked = selectedArray.includes(tName) || (selectedArray.length === 0 && tName === 'JAMB');
            return `
                <label class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg border text-xs font-semibold cursor-pointer select-none transition-all ${isChecked ? 'bg-primary/10 border-primary text-primary' : 'bg-surface-container-low border-border-subtle text-on-surface-variant hover:border-primary/50'}">
                    <input type="checkbox" value="${tName}" ${isChecked ? 'checked' : ''} onchange="updateLessonTypesValue()" class="accent-primary rounded text-primary focus:ring-0">
                    <span>${tName}</span>
                </label>
            `;
        }).join('');
        updateLessonTypesValue();
    }

    function updateLessonTypesValue() {
        const checkboxes = document.querySelectorAll('#lessonTypesContainer input[type="checkbox"]:checked');
        const selected = Array.from(checkboxes).map(cb => cb.value);
        document.getElementById('class').value = selected.join(', ');

        document.querySelectorAll('#lessonTypesContainer label').forEach(lbl => {
            const cb = lbl.querySelector('input[type="checkbox"]');
            if (cb && cb.checked) {
                lbl.className = 'inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg border text-xs font-semibold cursor-pointer select-none transition-all bg-primary/10 border-primary text-primary';
            } else if (lbl) {
                lbl.className = 'inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg border text-xs font-semibold cursor-pointer select-none transition-all bg-surface-container-low border-border-subtle text-on-surface-variant hover:border-primary/50';
            }
        });
    }

    async function generateNextAdmissionNumber() {
        if (studentId > 0) return;
        const admInput = document.getElementById('admission_number');
        try {
            const resp = await fetch('../api/students.php?action=get_next_admission_number');
            const data = await resp.json();
            if (data.success && data.admission_number) {
                admInput.value = data.admission_number;
            }
        } catch (e) {
            console.error(e);
        }
    }

    async function loadStudentData() {
        await loadClasses();

        if (studentId <= 0) {
            await generateNextAdmissionNumber();
            return;
        }

        try {
            const resp = await fetch(`../api/students.php?action=get&id=${studentId}`);
            const data = await resp.json();
            if (data.success && data.student) {
                const s = data.student;
                document.getElementById('admission_number').value = s.admission_number || '';
                document.getElementById('surname').value = s.surname || '';
                document.getElementById('firstname').value = s.firstname || '';
                document.getElementById('middlename').value = s.middlename || '';
                document.getElementById('gender').value = s.gender || 'Male';
                await loadClasses(s.class);
                document.getElementById('dob').value = s.dob || '';
                if (document.getElementById('status')) {
                    document.getElementById('status').value = s.status || 'Awaiting Fingerprint';
                }
                document.getElementById('parent_name').value = s.parent_name || '';
                document.getElementById('parent_phone').value = s.parent_phone || '';
                document.getElementById('parent_email').value = s.parent_email || '';
                document.getElementById('address').value = s.address || '';

                if (s.photo) {
                    const preview = document.getElementById('photoPreview');
                    const placeholder = document.getElementById('photoPlaceholder');
                    preview.src = '../' + s.photo;
                    preview.classList.remove('hidden');
                    placeholder.classList.add('hidden');
                }
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
            const resp = await fetch('../api/students.php', {
                method: 'POST',
                body: formData
            });
            const data = await resp.json();
            btn.disabled = false;
            btn.innerHTML = '<span class="material-symbols-outlined text-sm">save</span> Save Student Profile';

            if (data.success) {
                if (studentId <= 0) {
                    const enrollRes = await HighQSwal.fire({
                        title: 'Student Saved!',
                        text: 'Student profile registered successfully. Would you like to enroll a fingerprint for this student now?',
                        icon: 'success',
                        showCancelButton: true,
                        confirmButtonText: 'Enroll Fingerprint Now',
                        cancelButtonText: 'Go to Directory'
                    });

                    if (enrollRes.isConfirmed) {
                        window.location.href = `enroll_fingerprint.php?student_id=${data.id}`;
                    } else {
                        window.location.href = 'students.php';
                    }
                } else {
                    await HighQSwal.fire('Saved!', data.message || 'Student profile updated successfully.', 'success');
                    window.location.href = 'students.php';
                }
            } else {
                HighQSwal.fire('Error', data.message || 'Error saving student', 'error');
            }
        } catch (err) {
            btn.disabled = false;
            btn.innerHTML = '<span class="material-symbols-outlined text-sm">save</span> Save Student Profile';
            HighQSwal.fire('Error', 'Server error saving student.', 'error');
        }
    });

    loadStudentData();
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
