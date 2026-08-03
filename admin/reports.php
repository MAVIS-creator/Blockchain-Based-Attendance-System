<?php
$pageTitle = 'Attendance Reports';
$activePage = 'reports';
require_once __DIR__ . '/includes/header.php';

$pdo = get_db_connection();
$schoolName = 'High-Q Solid Academy';

$stmt = $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'school_name'");
if ($val = $stmt->fetchColumn()) {
    $schoolName = $val;
}
?>

<style>
    @media print {
        body * { visibility: hidden; }
        #printableReportArea, #printableReportArea * { visibility: visible; }
        #printableReportArea { position: absolute; left: 0; top: 0; width: 100%; }
        .no-print { display: none !important; }
    }
</style>

<div class="mb-6 flex flex-col md:flex-row md:items-center justify-between gap-4 no-print">
    <div>
        <h2 class="font-headline-lg text-2xl font-bold text-on-surface">Official Attendance Reports</h2>
        <p class="font-body-md text-on-surface-variant text-sm">Generate formatted reports for school management and parents</p>
    </div>
    <div class="flex gap-2">
        <button onclick="window.print()" class="px-4 py-2 bg-primary text-white font-semibold rounded-lg text-sm flex items-center gap-2 hover:bg-navy-muted shadow">
            <span class="material-symbols-outlined text-sm">print</span> Print / Save PDF
        </button>
    </div>
</div>

<!-- Controls -->
<div class="bg-surface-container-lowest p-6 rounded-xl border border-border-subtle shadow-sm mb-6 no-print space-y-4">
    <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
        <div>
            <label class="block text-xs font-semibold text-on-surface-variant uppercase mb-1">Report Type</label>
            <select id="reportType" class="w-full bg-surface-gray border border-border-subtle text-sm rounded-lg px-3 py-2">
                <option value="daily">Daily Report</option>
                <option value="weekly">Weekly Summary</option>
                <option value="monthly">Monthly Summary</option>
            </select>
        </div>

        <div>
            <label class="block text-xs font-semibold text-on-surface-variant uppercase mb-1">Class Filter</label>
            <select id="reportClass" class="w-full bg-surface-gray border border-border-subtle text-sm rounded-lg px-3 py-2">
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

        <div>
            <label class="block text-xs font-semibold text-on-surface-variant uppercase mb-1">Date</label>
            <input type="date" id="reportDate" value="<?= date('Y-m-d') ?>" class="w-full bg-surface-gray border border-border-subtle text-sm rounded-lg px-3 py-2">
        </div>

        <div class="flex items-end">
            <button onclick="generateReport()" class="w-full py-2 bg-primary text-white font-semibold rounded-lg text-sm hover:opacity-90">Generate Report</button>
        </div>
    </div>
</div>

<!-- Printable Area -->
<div id="printableReportArea" class="bg-white p-8 rounded-xl border border-border-subtle shadow-md space-y-6">
    <!-- Header with School Logo -->
    <div class="flex justify-between items-center border-b-2 border-primary pb-6">
        <div class="flex items-center gap-4">
            <div class="w-16 h-16 rounded-xl bg-black text-white font-bold text-2xl flex items-center justify-center">
                HQ
            </div>
            <div>
                <h1 class="font-bold text-2xl text-primary leading-tight"><?= htmlspecialchars($schoolName) ?></h1>
                <p class="text-xs text-gray-500 uppercase tracking-widest font-semibold">Official Biometric Attendance Report</p>
                <p class="text-xs text-gray-500">Generated on <?= date('F j, Y \a\t g:i A') ?></p>
            </div>
        </div>
        <div class="text-right text-xs text-gray-600">
            <p><span class="font-bold">Report Type:</span> <span id="lblReportType">Daily Attendance</span></p>
            <p><span class="font-bold">Date:</span> <span id="lblReportDate"><?= date('F j, Y') ?></span></p>
            <p><span class="font-bold">Class:</span> <span id="lblReportClass">All Classes</span></p>
        </div>
    </div>

    <!-- Summary Statistics Bar -->
    <div class="grid grid-cols-4 gap-4 p-4 bg-gray-50 rounded-xl border border-gray-200 text-center text-xs">
        <div>
            <p class="text-gray-500 uppercase font-semibold">Total Logged</p>
            <p class="text-xl font-bold text-black" id="repTotal">0</p>
        </div>
        <div>
            <p class="text-gray-500 uppercase font-semibold">Present</p>
            <p class="text-xl font-bold text-green-700" id="repPresent">0</p>
        </div>
        <div>
            <p class="text-gray-500 uppercase font-semibold">Late</p>
            <p class="text-xl font-bold text-yellow-600" id="repLate">0</p>
        </div>
        <div>
            <p class="text-gray-500 uppercase font-semibold">Completed</p>
            <p class="text-xl font-bold text-blue-700" id="repCompleted">0</p>
        </div>
    </div>

    <!-- Data Table -->
    <table class="w-full text-left text-xs border-collapse">
        <thead>
            <tr class="border-b-2 border-gray-300 bg-gray-100 uppercase text-gray-700 font-bold">
                <th class="py-2.5 px-3">S/N</th>
                <th class="py-2.5 px-3">Adm #</th>
                <th class="py-2.5 px-3">Student Name</th>
                <th class="py-2.5 px-3">Class</th>
                <th class="py-2.5 px-3">Check-In</th>
                <th class="py-2.5 px-3">Check-Out</th>
                <th class="py-2.5 px-3">Status</th>
            </tr>
        </thead>
        <tbody id="reportTbody" class="divide-y divide-gray-200">
            <tr>
                <td colspan="7" class="py-6 text-center text-gray-500">Click "Generate Report" above to load report data.</td>
            </tr>
        </tbody>
    </table>

    <!-- Signature Footer Area -->
    <div class="pt-12 grid grid-cols-2 gap-12 text-xs text-gray-600">
        <div>
            <div class="border-b border-gray-400 w-48 mb-1"></div>
            <p class="font-bold">Prepared By (Attendance Officer)</p>
            <p class="text-[10px] text-gray-400">Signature & Date</p>
        </div>
        <div class="text-right flex flex-col items-end">
            <div class="border-b border-gray-400 w-48 mb-1"></div>
            <p class="font-bold">Approved By (Principal / Management)</p>
            <p class="text-[10px] text-gray-400">Stamp & Signature</p>
        </div>
    </div>
</div>

<script>
    async function generateReport() {
        const type = document.getElementById('reportType').value;
        const cls = document.getElementById('reportClass').value;
        const date = document.getElementById('reportDate').value;

        document.getElementById('lblReportType').innerText = type.toUpperCase() + ' SUMMARY';
        document.getElementById('lblReportClass').innerText = cls || 'All Classes';
        document.getElementById('lblReportDate').innerText = date;

        const tbody = document.getElementById('reportTbody');
        tbody.innerHTML = '<tr><td colspan="7" class="py-6 text-center text-gray-500">Generating report...</td></tr>';

        try {
            const resp = await fetch(`../api/attendance.php?action=list&date=${encodeURIComponent(date)}&class=${encodeURIComponent(cls)}`);
            const data = await resp.json();

            if (data.success) {
                const rows = data.data;
                document.getElementById('repTotal').innerText = rows.length;
                document.getElementById('repPresent').innerText = rows.filter(r => r.status === 'Present').length;
                document.getElementById('repLate').innerText = rows.filter(r => r.status === 'Late').length;
                document.getElementById('repCompleted').innerText = rows.filter(r => r.check_out).length;

                if (rows.length === 0) {
                    tbody.innerHTML = '<tr><td colspan="7" class="py-6 text-center text-gray-500">No attendance records found for this period.</td></tr>';
                } else {
                    tbody.innerHTML = rows.map((r, i) => `
                        <tr>
                            <td class="py-2 px-3 font-semibold">${i + 1}</td>
                            <td class="py-2 px-3 font-mono font-bold">${r.admission_number}</td>
                            <td class="py-2 px-3 font-bold">${r.surname} ${r.firstname}</td>
                            <td class="py-2 px-3">${r.class}</td>
                            <td class="py-2 px-3 font-mono">${r.check_in || '-'}</td>
                            <td class="py-2 px-3 font-mono">${r.check_out || '-'}</td>
                            <td class="py-2 px-3 font-bold">${r.status}</td>
                        </tr>
                    `).join('');
                }
            }
        } catch (e) {
            console.error(e);
        }
    }

    generateReport();
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
