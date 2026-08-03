<?php
$pageTitle = 'Attendance Records';
$activePage = 'records';
require_once __DIR__ . '/includes/header.php';
?>

<div class="mb-6 flex flex-col md:flex-row md:items-center justify-between gap-4">
    <div>
        <h2 class="font-headline-lg text-2xl font-bold text-on-surface">Attendance Records</h2>
        <p class="font-body-md text-on-surface-variant text-sm">Comprehensive attendance logs, hours calculation, and export</p>
    </div>
    <div class="flex gap-2">
        <button onclick="exportCSV()" class="px-4 py-2 bg-surface-container-lowest border border-border-subtle text-primary font-semibold rounded-lg text-sm flex items-center gap-2 hover:bg-surface-container transition-colors shadow-sm">
            <span class="material-symbols-outlined text-sm">download</span> Export CSV
        </button>
        <button onclick="window.print()" class="px-4 py-2 bg-primary text-white font-semibold rounded-lg text-sm flex items-center gap-2 hover:bg-navy-muted transition-colors shadow">
            <span class="material-symbols-outlined text-sm">print</span> Print Report
        </button>
    </div>
</div>

<!-- Filters Bar -->
<div class="bg-surface-container-lowest p-4 rounded-xl border border-border-subtle shadow-sm mb-6 flex flex-col md:flex-row items-center gap-4">
    <div class="flex items-center gap-2 w-full md:w-auto">
        <label class="text-xs font-semibold text-on-surface-variant uppercase">Date:</label>
        <input type="date" id="recordDate" value="<?= date('Y-m-d') ?>" class="bg-surface-gray border border-border-subtle text-sm rounded-lg px-3 py-2 focus:outline-none">
    </div>

    <div class="flex items-center gap-2 w-full md:w-auto">
        <label class="text-xs font-semibold text-on-surface-variant uppercase">Class:</label>
        <select id="recordClass" class="bg-surface-gray border border-border-subtle text-sm rounded-lg px-3 py-2 focus:outline-none">
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
    </div>

    <div class="flex items-center gap-2 w-full md:w-auto">
        <label class="text-xs font-semibold text-on-surface-variant uppercase">Status:</label>
        <select id="recordStatus" class="bg-surface-gray border border-border-subtle text-sm rounded-lg px-3 py-2 focus:outline-none">
            <option value="">All Statuses</option>
            <option value="Present">Present</option>
            <option value="Late">Late</option>
            <option value="Completed">Completed</option>
            <option value="Early Departure">Early Departure</option>
        </select>
    </div>

    <div class="flex-grow w-full md:w-auto">
        <input type="text" id="recordSearch" placeholder="Search student name or admission #..." class="w-full px-3 py-2 bg-surface-gray border border-border-subtle rounded-lg text-sm focus:outline-none">
    </div>

    <button onclick="loadAttendanceRecords()" class="px-5 py-2 bg-primary text-white text-sm font-semibold rounded-lg hover:opacity-90">Apply Filter</button>
</div>

<!-- Records Table -->
<div class="bg-surface-container-lowest rounded-xl border border-border-subtle shadow-sm overflow-hidden mb-6">
    <div class="overflow-x-auto">
        <table class="w-full text-left text-sm" id="recordsTable">
            <thead class="bg-surface-container-low border-b border-border-subtle font-semibold text-xs uppercase tracking-wider text-on-surface-variant">
                <tr>
                    <th class="py-3 px-4">Student</th>
                    <th class="py-3 px-4">Admission #</th>
                    <th class="py-3 px-4">Class</th>
                    <th class="py-3 px-4">Check-In</th>
                    <th class="py-3 px-4">Check-Out</th>
                    <th class="py-3 px-4">Hours Spent</th>
                    <th class="py-3 px-4">Status</th>
                </tr>
            </thead>
            <tbody id="recordsTbody" class="divide-y divide-border-subtle/60">
                <tr>
                    <td colspan="7" class="py-8 text-center text-on-surface-variant">Loading attendance logs...</td>
                </tr>
            </tbody>
        </table>
    </div>
</div>

<script>
    let currentRecordsData = [];

    async function loadAttendanceRecords() {
        const date = document.getElementById('recordDate').value;
        const cls = document.getElementById('recordClass').value;
        const status = document.getElementById('recordStatus').value;
        const search = document.getElementById('recordSearch').value;

        const tbody = document.getElementById('recordsTbody');
        tbody.innerHTML = '<tr><td colspan="7" class="py-8 text-center text-on-surface-variant">Loading records...</td></tr>';

        try {
            const url = `../api/attendance.php?action=list&date=${encodeURIComponent(date)}&class=${encodeURIComponent(cls)}&status=${encodeURIComponent(status)}&search=${encodeURIComponent(search)}`;
            const resp = await fetch(url);
            const data = await resp.json();

            if (data.success) {
                currentRecordsData = data.data;
                if (currentRecordsData.length === 0) {
                    tbody.innerHTML = '<tr><td colspan="7" class="py-8 text-center text-on-surface-variant">No attendance records found for selected filters.</td></tr>';
                } else {
                    tbody.innerHTML = currentRecordsData.map(r => {
                        let hours = '-';
                        if (r.check_in && r.check_out) {
                            const t1 = new Date(`1970-01-01T${r.check_in}`);
                            const t2 = new Date(`1970-01-01T${r.check_out}`);
                            const diffMs = t2 - t1;
                            const diffHrs = (diffMs / (1000 * 60 * 60)).toFixed(1);
                            hours = `${diffHrs} hrs`;
                        }

                        let statusBadge = 'bg-green-100 text-green-800';
                        if (r.status === 'Late') statusBadge = 'bg-yellow-100 text-yellow-800';
                        if (r.status === 'Early Departure') statusBadge = 'bg-orange-100 text-orange-800';

                        return `
                            <tr class="hover:bg-surface-container-low/40">
                                <td class="py-3 px-4 font-bold text-on-surface">${r.surname} ${r.firstname}</td>
                                <td class="py-3 px-4 font-mono text-xs font-semibold text-primary">${r.admission_number}</td>
                                <td class="py-3 px-4">${r.class}</td>
                                <td class="py-3 px-4 font-mono text-xs">${r.check_in || '-'}</td>
                                <td class="py-3 px-4 font-mono text-xs">${r.check_out || '-'}</td>
                                <td class="py-3 px-4 text-xs font-semibold">${hours}</td>
                                <td class="py-3 px-4">
                                    <span class="px-2.5 py-0.5 rounded-full text-xs font-bold ${statusBadge}">
                                        ${r.status}
                                    </span>
                                </td>
                            </tr>
                        `;
                    }).join('');
                }
            }
        } catch (e) {
            console.error(e);
        }
    }

    function exportCSV() {
        if (!currentRecordsData || currentRecordsData.length === 0) {
            alert('No records available to export.');
            return;
        }

        let csvContent = "data:text/csv;charset=utf-8,AdmissionNumber,Surname,Firstname,Class,Date,CheckIn,CheckOut,Status\n";
        currentRecordsData.forEach(r => {
            csvContent += `"${r.admission_number}","${r.surname}","${r.firstname}","${r.class}","${r.date}","${r.check_in || ''}","${r.check_out || ''}","${r.status}"\n`;
        });

        const encodedUri = encodeURI(csvContent);
        const link = document.createElement("a");
        link.setAttribute("href", encodedUri);
        link.setAttribute("download", `Attendance_Records_${document.getElementById('recordDate').value}.csv`);
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
    }

    loadAttendanceRecords();
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
