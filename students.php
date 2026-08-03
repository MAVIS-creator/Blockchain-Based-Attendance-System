<?php
$pageTitle = 'Student Directory';
$activePage = 'students';
require_once __DIR__ . '/includes/header.php';
?>

<div class="mb-6 flex flex-col md:flex-row md:items-center justify-between gap-4">
    <div>
        <h2 class="font-headline-lg text-2xl font-bold text-on-surface">Student Directory</h2>
        <p class="font-body-md text-on-surface-variant text-sm">Manage student profiles, bulk imports, and fingerprint links</p>
    </div>
    <div class="flex flex-wrap gap-2">
        <button onclick="openImportModal()" class="px-4 py-2 bg-surface-container-lowest border border-border-subtle text-primary font-semibold rounded-lg text-sm flex items-center gap-2 hover:bg-surface-container transition-colors shadow-sm">
            <span class="material-symbols-outlined text-sm">upload_file</span> Import CSV / Excel
        </button>
        <a href="register_student.php" class="px-4 py-2 bg-primary text-white font-semibold rounded-lg text-sm flex items-center gap-2 hover:bg-navy-muted transition-colors shadow">
            <span class="material-symbols-outlined text-sm">person_add</span> Register New Student
        </a>
    </div>
</div>

<!-- Filters Bar -->
<div class="bg-surface-container-lowest p-4 rounded-xl border border-border-subtle shadow-sm mb-6 flex flex-col md:flex-row items-center gap-4">
    <div class="flex-grow w-full md:w-auto relative">
        <span class="material-symbols-outlined absolute left-3 top-1/2 -translate-y-1/2 text-on-surface-variant">search</span>
        <input type="text" id="filterSearch" placeholder="Search by Surname, First Name, Admission #..." class="w-full pl-10 pr-4 py-2 bg-surface-gray border border-border-subtle rounded-lg text-sm focus:outline-none focus:border-primary">
    </div>

    <div class="flex gap-3 w-full md:w-auto">
        <select id="filterClass" class="bg-surface-gray border border-border-subtle text-sm rounded-lg px-3 py-2 focus:outline-none">
            <option value="">All Classes</option>
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

        <select id="filterStatus" class="bg-surface-gray border border-border-subtle text-sm rounded-lg px-3 py-2 focus:outline-none">
            <option value="">All Statuses</option>
            <option value="Awaiting Fingerprint">Awaiting Fingerprint</option>
            <option value="Fingerprint Linked">Fingerprint Linked</option>
            <option value="Registered">Registered</option>
            <option value="Inactive">Inactive</option>
        </select>

        <button onclick="loadStudents(1)" class="px-4 py-2 bg-primary text-white text-sm font-semibold rounded-lg hover:opacity-90">Filter</button>
    </div>
</div>

<!-- Table -->
<div class="bg-surface-container-lowest rounded-xl border border-border-subtle shadow-sm overflow-hidden mb-6">
    <div class="overflow-x-auto">
        <table class="w-full text-left text-sm">
            <thead class="bg-surface-container-low border-b border-border-subtle font-semibold text-xs uppercase tracking-wider text-on-surface-variant">
                <tr>
                    <th class="py-3 px-4">Student</th>
                    <th class="py-3 px-4">Admission #</th>
                    <th class="py-3 px-4">Class</th>
                    <th class="py-3 px-4">Gender</th>
                    <th class="py-3 px-4">Parent Phone</th>
                    <th class="py-3 px-4">Fingerprint</th>
                    <th class="py-3 px-4 text-right">Actions</th>
                </tr>
            </thead>
            <tbody id="studentTableBody" class="divide-y divide-border-subtle/60">
                <tr>
                    <td colspan="7" class="py-8 text-center text-on-surface-variant">Loading students...</td>
                </tr>
            </tbody>
        </table>
    </div>
    <!-- Pagination -->
    <div class="p-4 border-t border-border-subtle flex justify-between items-center bg-surface-container-low/30 text-xs">
        <span id="paginationInfo" class="text-on-surface-variant">Showing 0 - 0 of 0</span>
        <div class="flex gap-2" id="paginationButtons">
            <!-- Dynamic Buttons -->
        </div>
    </div>
</div>

<!-- CSV / Excel Import Modal -->
<div id="importModal" class="fixed inset-0 bg-black/50 backdrop-blur-sm z-50 flex items-center justify-center hidden p-4">
    <div class="bg-surface-container-lowest rounded-xl border border-border-subtle shadow-2xl max-w-2xl w-full p-6 space-y-6">
        <div class="flex justify-between items-center border-b border-border-subtle pb-4">
            <div>
                <h3 class="font-bold text-lg text-on-surface">Bulk Student Import</h3>
                <p class="text-xs text-on-surface-variant">Upload a CSV file with student records</p>
            </div>
            <button onclick="closeImportModal()" class="text-on-surface-variant hover:text-primary">
                <span class="material-symbols-outlined">close</span>
            </button>
        </div>

        <div class="space-y-4">
            <div class="p-4 bg-surface-container-low rounded-lg text-xs space-y-2">
                <p class="font-bold text-primary">Instructions:</p>
                <p>Ensure your CSV file contains headers matching: <strong>AdmissionNumber, Surname, FirstName, MiddleName, Gender, Class, ParentName, ParentPhone, ParentEmail</strong>.</p>
                <a href="data:text/csv;charset=utf-8,AdmissionNumber,Surname,FirstName,MiddleName,Gender,Class,ParentName,ParentPhone,ParentEmail%0AHQ/2026/001,Doe,John,Alexander,Male,Basic 1,Mr. Doe,08012345678,parent@example.com" download="students_template.csv" class="text-secondary font-bold hover:underline inline-flex items-center gap-1">
                    <span class="material-symbols-outlined text-xs">download</span> Download Template CSV
                </a>
            </div>

            <form id="importForm" class="space-y-4">
                <div class="border-2 border-dashed border-border-subtle rounded-xl p-6 text-center hover:border-primary transition-colors cursor-pointer" onclick="document.getElementById('csvFile').click()">
                    <span class="material-symbols-outlined text-4xl text-on-surface-variant mb-2">cloud_upload</span>
                    <p class="text-sm font-semibold text-primary">Click to select CSV File</p>
                    <p class="text-xs text-on-surface-variant" id="fileNameDisplay">Supports .csv files</p>
                    <input type="file" id="csvFile" name="csv_file" accept=".csv" class="hidden" onchange="previewFile(this)">
                </div>

                <div id="importPreview" class="hidden border border-border-subtle rounded-lg max-h-48 overflow-y-auto p-3 text-xs space-y-2 bg-surface-gray">
                    <p id="previewSummary" class="font-bold"></p>
                    <div id="previewRows"></div>
                </div>

                <div class="flex justify-end gap-3 pt-2 border-t border-border-subtle">
                    <button type="button" onclick="closeImportModal()" class="px-4 py-2 border border-border-subtle rounded-lg text-xs font-semibold hover:bg-surface-container">Cancel</button>
                    <button type="button" onclick="validateImport()" id="validateBtn" class="px-4 py-2 bg-secondary-container text-on-secondary-container rounded-lg text-xs font-semibold hover:opacity-90">Validate File</button>
                    <button type="submit" id="commitImportBtn" class="px-4 py-2 bg-primary text-white rounded-lg text-xs font-semibold hover:opacity-90 hidden">Commit Import</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
    let currentPage = 1;

    async function loadStudents(page = 1) {
        currentPage = page;
        const search = document.getElementById('filterSearch').value;
        const classVal = document.getElementById('filterClass').value;
        const statusVal = document.getElementById('filterStatus').value;

        const tbody = document.getElementById('studentTableBody');
        tbody.innerHTML = '<tr><td colspan="7" class="py-8 text-center text-on-surface-variant">Loading students...</td></tr>';

        try {
            const url = `api/students.php?action=list&page=${page}&limit=15&search=${encodeURIComponent(search)}&class=${encodeURIComponent(classVal)}&status=${encodeURIComponent(statusVal)}`;
            const resp = await fetch(url);
            const data = await resp.json();

            if (data.success) {
                if (data.data.length === 0) {
                    tbody.innerHTML = '<tr><td colspan="7" class="py-8 text-center text-on-surface-variant">No students found.</td></tr>';
                } else {
                    tbody.innerHTML = data.data.map(s => `
                        <tr class="hover:bg-surface-container-low/40">
                            <td class="py-3 px-4 font-semibold text-on-surface flex items-center gap-3">
                                <div class="w-8 h-8 rounded-full bg-primary text-white flex items-center justify-center text-xs font-bold">
                                    ${s.firstname.charAt(0)}${s.surname.charAt(0)}
                                </div>
                                <div>
                                    <p class="font-bold">${s.surname} ${s.firstname} ${s.middlename || ''}</p>
                                    <p class="text-[11px] text-on-surface-variant">${s.parent_name || 'No Parent Listed'}</p>
                                </div>
                            </td>
                            <td class="py-3 px-4 font-mono text-xs font-semibold text-primary">${s.admission_number}</td>
                            <td class="py-3 px-4">${s.class}</td>
                            <td class="py-3 px-4">${s.gender}</td>
                            <td class="py-3 px-4 text-xs">${s.parent_phone || '-'}</td>
                            <td class="py-3 px-4">
                                ${s.fingerprint_count > 0 ? 
                                    '<span class="inline-flex items-center gap-1 text-xs font-bold text-green-700 bg-green-100 px-2 py-0.5 rounded-full"><span class="material-symbols-outlined text-xs">fingerprint</span> Linked</span>' : 
                                    '<span class="inline-flex items-center gap-1 text-xs font-bold text-yellow-700 bg-yellow-100 px-2 py-0.5 rounded-full"><span class="material-symbols-outlined text-xs">pending</span> Pending</span>'}
                            </td>
                            <td class="py-3 px-4 text-right space-x-2">
                                <a href="enroll_fingerprint.php?student_id=${s.id}" class="text-secondary font-bold hover:underline text-xs" title="Enroll Fingerprint">Enroll</a>
                                <a href="register_student.php?id=${s.id}" class="text-primary font-bold hover:underline text-xs">Edit</a>
                                <button onclick="deleteStudent(${s.id}, '${s.surname} ${s.firstname}')" class="text-error font-bold hover:underline text-xs">Delete</button>
                            </td>
                        </tr>
                    `).join('');
                }

                // Render pagination
                const p = data.pagination;
                document.getElementById('paginationInfo').innerText = `Showing page ${p.page} of ${p.pages} (${p.total} total students)`;
                let btnHtml = '';
                if (p.page > 1) {
                    btnHtml += `<button onclick="loadStudents(${p.page - 1})" class="px-3 py-1 border rounded hover:bg-surface-container">Prev</button>`;
                }
                if (p.page < p.pages) {
                    btnHtml += `<button onclick="loadStudents(${p.page + 1})" class="px-3 py-1 border rounded hover:bg-surface-container">Next</button>`;
                }
                document.getElementById('paginationButtons').innerHTML = btnHtml;
            }
        } catch (e) {
            console.error(e);
        }
    }

    async function deleteStudent(id, name) {
        if (!confirm(`Are you sure you want to delete ${name}? This will also delete their attendance and fingerprint records.`)) return;

        const formData = new FormData();
        formData.append('id', id);

        try {
            const resp = await fetch('api/students.php?action=delete', {
                method: 'POST',
                body: formData
            });
            const data = await resp.json();
            if (data.success) {
                loadStudents(currentPage);
            } else {
                alert(data.message || 'Error deleting student');
            }
        } catch (e) {
            alert('Server error occurred.');
        }
    }

    function openImportModal() { document.getElementById('importModal').classList.remove('hidden'); }
    function closeImportModal() { document.getElementById('importModal').classList.add('hidden'); }

    function previewFile(input) {
        if (input.files && input.files[0]) {
            document.getElementById('fileNameDisplay').innerText = input.files[0].name;
        }
    }

    async function validateImport() {
        const fileInput = document.getElementById('csvFile');
        if (!fileInput.files || !fileInput.files[0]) {
            alert('Please select a CSV file first.');
            return;
        }

        const formData = new FormData();
        formData.append('csv_file', fileInput.files[0]);

        const validateBtn = document.getElementById('validateBtn');
        validateBtn.disabled = true;
        validateBtn.innerText = 'Validating...';

        try {
            const resp = await fetch('api/students.php?action=validate_import', {
                method: 'POST',
                body: formData
            });
            const data = await resp.json();
            validateBtn.disabled = false;
            validateBtn.innerText = 'Validate File';

            if (data.success) {
                const preview = document.getElementById('importPreview');
                const summary = document.getElementById('previewSummary');
                const rowsDiv = document.getElementById('previewRows');

                preview.classList.remove('hidden');
                summary.innerHTML = `<span class="text-green-600">${data.validCount} Valid Records</span> | <span class="text-red-600">${data.invalidCount} Invalid/Skipped Records</span>`;

                rowsDiv.innerHTML = data.rows.map(r => `
                    <div class="p-2 bg-white border border-border-subtle rounded flex justify-between items-center text-[11px]">
                        <div>
                            <strong>${r.admission_number || 'N/A'}</strong> - ${r.surname} ${r.firstname} (${r.class})
                            ${r.errors ? `<p class="text-red-600">${r.errors}</p>` : ''}
                        </div>
                        <span class="font-bold ${r.status === 'Valid' ? 'text-green-600' : 'text-red-600'}">${r.status}</span>
                    </div>
                `).join('');

                if (data.validCount > 0) {
                    document.getElementById('commitImportBtn').classList.remove('hidden');
                }
            } else {
                alert(data.message || 'Validation failed');
            }
        } catch (e) {
            validateBtn.disabled = false;
            validateBtn.innerText = 'Validate File';
            alert('Error validating CSV.');
        }
    }

    document.getElementById('importForm').addEventListener('submit', async function(e) {
        e.preventDefault();
        const fileInput = document.getElementById('csvFile');
        const commitBtn = document.getElementById('commitImportBtn');

        commitBtn.disabled = true;
        commitBtn.innerText = 'Importing...';

        const formData = new FormData();
        formData.append('csv_file', fileInput.files[0]);

        try {
            const resp = await fetch('api/students.php?action=import', {
                method: 'POST',
                body: formData
            });
            const data = await resp.json();
            commitBtn.disabled = false;
            commitBtn.innerText = 'Commit Import';

            if (data.success) {
                alert(data.message);
                closeImportModal();
                loadStudents(1);
            } else {
                alert(data.message || 'Import failed.');
            }
        } catch (e) {
            commitBtn.disabled = false;
            commitBtn.innerText = 'Commit Import';
            alert('Server error importing file.');
        }
    });

    if (new URLSearchParams(window.location.search).get('open_import') === '1') {
        openImportModal();
    }

    loadStudents(1);
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
