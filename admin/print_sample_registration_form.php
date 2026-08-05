<?php
/**
 * Printable Student Registration Form / Sample Template (PDF & Print ready)
 * High-Q Solid Academy Biometric Attendance System
 */
require_once __DIR__ . '/../includes/auth.php';
require_login();

$studentId = (int)($_GET['id'] ?? 0);
$student = null;

if ($studentId > 0) {
    try {
        $pdo = get_db_connection();
        $stmt = $pdo->prepare("SELECT * FROM students WHERE id = ?");
        $stmt->execute([$studentId]);
        $student = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) { }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8"/>
    <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
    <title>High-Q Solid Academy - Student Registration Form</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Hanken+Grotesk:wght@600;700;800&display=swap" rel="stylesheet"/>
    <style>
        * { box-sizing: border-box; font-family: 'Inter', sans-serif; }
        body { background: #f8f9fa; margin: 0; padding: 20px; color: #191c1e; }
        .form-container { max-width: 800px; margin: 0 auto; background: white; padding: 40px; border-radius: 12px; border: 1px solid #e1e2e4; box-shadow: 0 4px 12px rgba(0,0,0,0.05); }

        .header { display: flex; align-items: center; justify-content: space-between; border-bottom: 2px solid #004ac6; padding-bottom: 20px; margin-bottom: 24px; }
        .logo-box { display: flex; align-items: center; gap: 12px; }
        .logo-icon { width: 48px; height: 48px; background: #004ac6; color: white; font-size: 20px; font-weight: bold; display: flex; align-items: center; justify-content: center; border-radius: 10px; }
        .brand-title { font-family: 'Hanken Grotesk', sans-serif; font-size: 22px; font-weight: 800; color: #004ac6; margin: 0; }
        .brand-sub { font-size: 12px; color: #515f74; margin: 2px 0 0 0; text-transform: uppercase; letter-spacing: 1px; }

        .form-title { font-size: 18px; font-weight: 700; text-align: center; background: #f0f4ff; color: #004ac6; padding: 10px; border-radius: 8px; margin-bottom: 24px; text-transform: uppercase; letter-spacing: 1px; }

        .section-title { font-size: 14px; font-weight: 700; color: #004ac6; text-transform: uppercase; border-bottom: 1px solid #e1e2e4; padding-bottom: 6px; margin: 24px 0 16px 0; display: flex; align-items: center; gap: 8px; }

        .grid-3 { display: grid; grid-template-columns: repeat(3, 1fr); gap: 16px; }
        .grid-2 { display: grid; grid-template-columns: repeat(2, 1fr); gap: 16px; }

        .field-group { margin-bottom: 14px; }
        .field-label { font-size: 11px; font-weight: 700; text-transform: uppercase; color: #515f74; margin-bottom: 4px; display: block; }
        .field-line { width: 100%; height: 36px; border: 1px solid #c3c6d7; border-radius: 6px; background: #fafafa; padding: 8px 12px; font-size: 13px; color: #0b1c30; font-weight: 600; overflow: hidden; white-space: nowrap; text-overflow: ellipsis; }

        .checkbox-group { display: flex; gap: 20px; align-items: center; height: 36px; }
        .checkbox-item { display: flex; align-items: center; gap: 6px; font-size: 13px; }
        .box { width: 16px; height: 16px; border: 1.5px solid #004ac6; border-radius: 3px; display: inline-flex; align-items: center; justify-content: center; font-size: 11px; font-weight: bold; color: #004ac6; }

        .photo-box { width: 120px; height: 140px; border: 2px dashed #004ac6; border-radius: 8px; overflow: hidden; display: flex; align-items: center; justify-content: center; text-align: center; font-size: 11px; color: #515f74; background: #f8f9fb; float: right; }
        .photo-box img { width: 100%; height: 100%; object-fit: cover; }

        .office-box { background: #fafafa; border: 1px solid #d2e1fa; padding: 16px; border-radius: 8px; margin-top: 30px; }

        .actions { margin-bottom: 20px; text-align: right; max-width: 800px; margin-left: auto; margin-right: auto; }
        .btn-print { background: #004ac6; color: white; padding: 10px 20px; font-weight: 600; border-radius: 8px; text-decoration: none; border: none; cursor: pointer; display: inline-flex; align-items: center; gap: 8px; font-size: 14px; }
        .btn-print:hover { background: #003699; }

        @media print {
            body { background: white; padding: 0; }
            .actions { display: none; }
            .form-container { border: none; box-shadow: none; padding: 0; }
        }
    </style>
</head>
<body>

<div class="actions">
    <button onclick="window.print()" class="btn-print">
        🖨️ Print / Save as PDF Form
    </button>
</div>

<div class="form-container">
    <div class="header">
        <div class="logo-box">
            <div class="logo-icon">HQ</div>
            <div>
                <h1 class="brand-title">HIGH-Q SOLID ACADEMY</h1>
                <p class="brand-sub">Biometric Attendance & Academic Records System</p>
            </div>
        </div>
        <div class="photo-box">
            <?php if (!empty($student['photo'])): ?>
                <img src="../<?= htmlspecialchars($student['photo']) ?>" alt="Passport Photo">
            <?php else: ?>
                Affix Student<br/>Passport Photo<br/>Here
            <?php endif; ?>
        </div>
    </div>

    <div class="form-title">STUDENT REGISTRATION & BIOMETRIC ENROLLMENT FORM</div>

    <!-- Student Details -->
    <div class="section-title">1. Student Personal Information</div>

    <div class="grid-3">
        <div class="field-group">
            <span class="field-label">Admission Number</span>
            <div class="field-line"><?= htmlspecialchars($student['admission_number'] ?? 'HQ/2026/00X') ?></div>
        </div>
        <div class="field-group">
            <span class="field-label">Surname *</span>
            <div class="field-line"><?= htmlspecialchars($student['surname'] ?? '____________________') ?></div>
        </div>
        <div class="field-group">
            <span class="field-label">First Name *</span>
            <div class="field-line"><?= htmlspecialchars($student['firstname'] ?? '____________________') ?></div>
        </div>
    </div>

    <div class="grid-3">
        <div class="field-group">
            <span class="field-label">Middle Name</span>
            <div class="field-line"><?= htmlspecialchars($student['middlename'] ?? '____________________') ?></div>
        </div>
        <div class="field-group">
            <span class="field-label">Gender *</span>
            <div class="checkbox-group">
                <span class="checkbox-item"><span class="box"><?= ($student['gender'] ?? '') === 'Male' ? '✓' : '' ?></span> Male</span>
                <span class="checkbox-item"><span class="box"><?= ($student['gender'] ?? '') === 'Female' ? '✓' : '' ?></span> Female</span>
            </div>
        </div>
        <div class="field-group">
            <span class="field-label">Lesson Type *</span>
            <div class="field-line"><?= htmlspecialchars($student['class'] ?? '____________________') ?></div>
        </div>
    </div>

    <div class="grid-2">
        <div class="field-group">
            <span class="field-label">Date of Birth (DD/MM/YYYY)</span>
            <div class="field-line"><?= htmlspecialchars($student['dob'] ?? '___ / ___ / ______') ?></div>
        </div>
        <div class="field-group">
            <span class="field-label">Fingerprint Initial Status</span>
            <div class="field-line" style="font-weight: bold; color: #004ac6;"><?= htmlspecialchars($student['status'] ?? 'Awaiting Fingerprint') ?></div>
        </div>
    </div>

    <!-- Parent Details -->
    <div class="section-title">2. Parent / Guardian Contact Details</div>

    <div class="grid-3">
        <div class="field-group">
            <span class="field-label">Parent / Guardian Name</span>
            <div class="field-line"><?= htmlspecialchars($student['parent_name'] ?? '____________________') ?></div>
        </div>
        <div class="field-group">
            <span class="field-label">Parent Phone Number *</span>
            <div class="field-line"><?= htmlspecialchars($student['parent_phone'] ?? '____________________') ?></div>
        </div>
        <div class="field-group">
            <span class="field-label">Parent Email Address</span>
            <div class="field-line"><?= htmlspecialchars($student['parent_email'] ?? '____________________') ?></div>
        </div>
    </div>

    <div class="field-group">
        <span class="field-label">Residential Home Address</span>
        <div class="field-line" style="height: 48px;"><?= htmlspecialchars($student['address'] ?? '__________________________________________________________________________') ?></div>
    </div>

    <!-- Office Sign off -->
    <div class="office-box">
        <div style="font-weight: 700; font-size: 12px; text-transform: uppercase; color: #004ac6; margin-bottom: 10px;">For Official Office & Biometric Desk Use Only</div>
        <div class="grid-3">
            <div>
                <span class="field-label">DigitalPersona Biometric Status</span>
                <div class="checkbox-group">
                    <span class="checkbox-item"><span class="box"><?= ($student['status'] ?? '') === 'Fingerprint Linked' ? '✓' : '' ?></span> Captured</span>
                    <span class="checkbox-item"><span class="box"><?= ($student['status'] ?? '') !== 'Fingerprint Linked' ? '✓' : '' ?></span> Pending</span>
                </div>
            </div>
            <div>
                <span class="field-label">Admin Officer Signature</span>
                <div class="field-line">____________________</div>
            </div>
            <div>
                <span class="field-label">Date Completed</span>
                <div class="field-line"><?= date('Y-m-d') ?></div>
            </div>
        </div>
    </div>
</div>

</body>
</html>
