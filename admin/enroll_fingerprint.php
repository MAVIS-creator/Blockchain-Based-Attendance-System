<?php
$pageTitle = 'Fingerprint Enrollment';
$activePage = 'enrollment';
require_once __DIR__ . '/includes/header.php';

$presetStudentId = (int)($_GET['student_id'] ?? 0);
?>

<div class="mb-6 flex justify-between items-center">
    <div>
        <h2 class="font-headline-lg text-2xl font-bold text-on-surface">Fingerprint Enrollment</h2>
        <p class="font-body-md text-on-surface-variant text-sm">Link student profiles with DigitalPersona biometric fingerprint templates</p>
    </div>
    <div class="flex items-center gap-2 px-3 py-1.5 bg-surface-container-lowest border border-border-subtle rounded-lg text-xs font-semibold" id="serviceStatusBadge">
        <span class="w-2.5 h-2.5 rounded-full bg-yellow-500" id="serviceDot"></span>
        <span id="serviceText">Checking Biometric Service...</span>
    </div>
</div>

<div class="grid grid-cols-1 lg:grid-cols-12 gap-8 items-start">
    <!-- Student Selector -->
    <div class="lg:col-span-5 bg-surface-container-lowest p-6 rounded-xl border border-border-subtle shadow-sm space-y-6">
        <h3 class="font-bold text-base text-on-surface">1. Select Student</h3>

        <div class="space-y-4">
            <div class="relative">
                <span class="material-symbols-outlined absolute left-3 top-1/2 -translate-y-1/2 text-on-surface-variant">search</span>
                <input type="text" id="studentSearchInput" placeholder="Type name or admission number..." class="w-full pl-10 pr-4 py-2.5 bg-surface-gray border border-border-subtle rounded-lg text-sm focus:outline-none focus:border-primary" oninput="searchStudents(this.value)">
            </div>

            <div id="searchResults" class="max-h-60 overflow-y-auto border border-border-subtle rounded-lg divide-y divide-border-subtle/50 text-xs bg-white">
                <p class="p-4 text-center text-on-surface-variant">Search student to select</p>
            </div>
        </div>

        <div id="selectedStudentCard" class="hidden p-4 bg-surface-container-low rounded-xl border border-border-subtle space-y-2">
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 rounded-full bg-primary text-white flex items-center justify-center font-bold text-sm" id="selectedStudentAvatar">
                    --
                </div>
                <div>
                    <h4 class="font-bold text-sm text-on-surface" id="selectedStudentName">Selected Student</h4>
                    <p class="text-xs text-on-surface-variant" id="selectedStudentSub">Class &bull; Adm No</p>
                </div>
            </div>
            <div class="pt-2 flex justify-between items-center text-xs">
                <span class="text-on-surface-variant">Current Status:</span>
                <span id="selectedStudentStatus" class="font-bold">--</span>
            </div>
        </div>
    </div>

    <!-- Scanner Interface -->
    <div class="lg:col-span-7 bg-surface-container-lowest p-6 md:p-8 rounded-xl border border-border-subtle shadow-sm flex flex-col items-center justify-center text-center space-y-6">
        <h3 class="font-bold text-base text-on-surface self-start">2. Scanner Interaction</h3>

        <div class="relative w-40 h-40 rounded-full bg-surface-container-low border-4 border-border-subtle flex items-center justify-center transition-all" id="scannerCircle">
            <span class="material-symbols-outlined text-6xl text-on-surface-variant" id="scannerIcon">fingerprint</span>
            <div class="absolute inset-0 rounded-full border-4 border-secondary border-t-transparent animate-spin hidden" id="scannerSpinner"></div>
        </div>

        <div class="space-y-1">
            <h4 class="font-bold text-lg text-on-surface" id="scanTitle">Ready to Scan</h4>
            <p class="text-xs text-on-surface-variant max-w-sm" id="scanSub">Select a student on the left and click "Start Capture" to initiate the DigitalPersona scanner.</p>
        </div>

        <!-- Quality Indicator -->
        <div class="hidden flex items-center gap-2 bg-surface-container-low px-4 py-2 rounded-full border border-border-subtle text-xs font-semibold" id="qualityBox">
            <span>Template Quality:</span>
            <span id="qualityVal" class="text-green-600 font-bold">Excellent</span>
        </div>

        <div class="flex gap-3 pt-4 w-full max-w-xs">
            <button id="enrollBtn" onclick="startEnrollment()" disabled class="w-full py-3 bg-primary text-white rounded-lg font-semibold hover:bg-navy-muted disabled:opacity-50 transition-all shadow flex items-center justify-center gap-2">
                <span class="material-symbols-outlined">fingerprint</span> Start Enrollment
            </button>
            <button id="deleteFpBtn" onclick="deleteFingerprint()" class="hidden px-4 py-3 border border-error text-error rounded-lg font-semibold hover:bg-error-container/20 transition-all">
                <span class="material-symbols-outlined">delete</span>
            </button>
        </div>
    </div>
</div>

<script>
    let selectedStudent = null;
    let biometricServiceUrl = 'http://localhost:8080';

    async function checkServiceStatus() {
        const badge = document.getElementById('serviceStatusBadge');
        const dot = document.getElementById('serviceDot');
        const text = document.getElementById('serviceText');

        try {
            const controller = new AbortController();
            const timeoutId = setTimeout(() => controller.abort(), 2000);
            const resp = await fetch(`${biometricServiceUrl}/status`, { signal: controller.signal });
            clearTimeout(timeoutId);
            const data = await resp.json();

            if (data.status === 'ok' || data.connected) {
                dot.className = 'w-2.5 h-2.5 rounded-full bg-green-500';
                text.innerText = 'High-Q Biometric Service Connected (' + (data.reader || 'DigitalPersona Scanner Ready') + ')';
            } else {
                dot.className = 'w-2.5 h-2.5 rounded-full bg-yellow-500';
                text.innerText = 'Biometric Service Running (No Scanner Detected)';
            }
        } catch (e) {
            dot.className = 'w-2.5 h-2.5 rounded-full bg-red-500';
            text.innerText = 'Biometric Service Offline (Check desktop service)';
        }
    }

    async function searchStudents(query) {
        if (!query || query.length < 2) return;
        const resDiv = document.getElementById('searchResults');
        try {
            const resp = await fetch(`../api/students.php?action=list&limit=10&search=${encodeURIComponent(query)}`);
            const data = await resp.json();
            if (data.success && data.data) {
                if (data.data.length === 0) {
                    resDiv.innerHTML = '<p class="p-4 text-center text-on-surface-variant">No matching students found</p>';
                } else {
                    resDiv.innerHTML = data.data.map(s => `
                        <div onclick='selectStudent(${JSON.stringify(s)})' class="p-3 hover:bg-surface-container cursor-pointer flex items-center justify-between">
                            <div>
                                <p class="font-bold text-on-surface">${s.surname} ${s.firstname}</p>
                                <p class="text-[11px] text-on-surface-variant">${s.class} &bull; ${s.admission_number}</p>
                            </div>
                            <span class="text-[10px] font-bold px-2 py-0.5 rounded ${s.fingerprint_count > 0 ? 'bg-green-100 text-green-800' : 'bg-yellow-100 text-yellow-800'}">
                                ${s.fingerprint_count > 0 ? 'Linked' : 'Pending'}
                            </span>
                        </div>
                    `).join('');
                }
            }
        } catch (e) {
            console.error(e);
        }
    }

    function selectStudent(student) {
        selectedStudent = student;
        document.getElementById('selectedStudentCard').classList.remove('hidden');
        document.getElementById('selectedStudentAvatar').innerText = `${student.firstname.charAt(0)}${student.surname.charAt(0)}`;
        document.getElementById('selectedStudentName').innerText = `${student.surname} ${student.firstname}`;
        document.getElementById('selectedStudentSub').innerText = `${student.class} \u2022 ${student.admission_number}`;
        document.getElementById('selectedStudentStatus').innerText = student.status || 'Awaiting Fingerprint';

        document.getElementById('enrollBtn').disabled = false;

        if (student.fingerprint_count > 0) {
            document.getElementById('deleteFpBtn').classList.remove('hidden');
            document.getElementById('scanTitle').innerText = 'Fingerprint Already Enrolled';
            document.getElementById('scanSub').innerText = 'Click "Start Enrollment" to overwrite or click delete icon to unlink.';
        } else {
            document.getElementById('deleteFpBtn').classList.add('hidden');
            document.getElementById('scanTitle').innerText = 'Ready to Scan';
            document.getElementById('scanSub').innerText = 'Place student finger on the DigitalPersona scanner when prompted.';
        }
    }

    async function startEnrollment() {
        if (!selectedStudent) {
            alert('Please select a student first.');
            return;
        }

        const circle = document.getElementById('scannerCircle');
        const icon = document.getElementById('scannerIcon');
        const spinner = document.getElementById('scannerSpinner');
        const title = document.getElementById('scanTitle');
        const sub = document.getElementById('scanSub');
        const btn = document.getElementById('enrollBtn');

        btn.disabled = true;
        title.innerText = 'Waiting for Finger...';
        sub.innerText = 'Ask student to place their finger firmly on the scanner glass (4 touch verification).';
        circle.className = 'relative w-40 h-40 rounded-full bg-secondary-container/20 border-4 border-secondary flex items-center justify-center transition-all animate-pulse';
        spinner.classList.remove('hidden');

        try {
            const res = await fetch(`${biometricServiceUrl}/enroll`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ student_id: selectedStudent.id, admission_number: selectedStudent.admission_number })
            });
            const data = await res.json();

            spinner.classList.add('hidden');
            circle.className = 'relative w-40 h-40 rounded-full bg-surface-container-low border-4 border-border-subtle flex items-center justify-center transition-all';

            if (data.success && data.template) {
                await saveFingerprintToBackend(selectedStudent.id, data.template, data.quality || 'Good');
            } else {
                title.innerText = 'Capture Failed or Simulated Mode';
                sub.innerText = data.message || 'If hardware is absent, you can use simulated template generation.';
                if (confirm('Scanner hardware not active. Would you like to generate a simulated demo fingerprint template for testing?')) {
                    const mockTemplate = 'DP_DEMO_TEMPLATE_' + Math.random().toString(36).substring(2) + '_' + Date.now();
                    await saveFingerprintToBackend(selectedStudent.id, mockTemplate, 'Good (Simulated)');
                }
            }
        } catch (err) {
            spinner.classList.add('hidden');
            circle.className = 'relative w-40 h-40 rounded-full bg-surface-container-low border-4 border-border-subtle flex items-center justify-center transition-all';
            
            if (confirm('Biometric Desktop Service not reachable on localhost:8080. Would you like to register a simulated fingerprint template for this student for testing?')) {
                const mockTemplate = 'DP_SIMULATED_TEMPLATE_' + Math.random().toString(36).substring(2) + '_' + Date.now();
                await saveFingerprintToBackend(selectedStudent.id, mockTemplate, 'Good (Simulated)');
            } else {
                title.innerText = 'Service Unreachable';
                sub.innerText = 'Make sure High-Q Biometric Service is running on the computer.';
            }
        } finally {
            btn.disabled = false;
        }
    }

    async function saveFingerprintToBackend(studentId, template, quality) {
        const formData = new FormData();
        formData.append('student_id', studentId);
        formData.append('template', template);
        formData.append('quality', quality);

        try {
            const resp = await fetch('../api/fingerprints.php?action=save', {
                method: 'POST',
                body: formData
            });
            const result = await resp.json();

            if (result.success) {
                document.getElementById('scanTitle').innerText = 'Enrollment Successful!';
                document.getElementById('scanSub').innerText = 'Fingerprint template linked successfully with ' + selectedStudent.surname + ' ' + selectedStudent.firstname;
                document.getElementById('qualityBox').classList.remove('hidden');
                document.getElementById('qualityVal').innerText = quality;

                selectedStudent.fingerprint_count = 1;
                selectedStudent.status = 'Fingerprint Linked';
                selectStudent(selectedStudent);
            } else {
                alert(result.message || 'Failed to save template to database.');
            }
        } catch (e) {
            alert('Database connection error.');
        }
    }

    async function deleteFingerprint() {
        if (!selectedStudent || !confirm('Are you sure you want to unlink the fingerprint template for this student?')) return;

        const formData = new FormData();
        formData.append('student_id', selectedStudent.id);

        try {
            const resp = await fetch('../api/fingerprints.php?action=delete', {
                method: 'POST',
                body: formData
            });
            const data = await resp.json();
            if (data.success) {
                alert('Fingerprint unlinked successfully.');
                selectedStudent.fingerprint_count = 0;
                selectedStudent.status = 'Awaiting Fingerprint';
                selectStudent(selectedStudent);
            }
        } catch (e) {
            alert('Server error.');
        }
    }

    if (<?= $presetStudentId ?> > 0) {
        fetch(`../api/students.php?action=get&id=<?= $presetStudentId ?>`)
            .then(res => res.json())
            .then(data => { if (data.success && data.student) selectStudent(data.student); });
    }

    checkServiceStatus();
    setInterval(checkServiceStatus, 5000);
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
