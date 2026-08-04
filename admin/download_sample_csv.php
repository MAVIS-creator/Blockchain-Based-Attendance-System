<?php
/**
 * Sample CSV/Excel Template Downloader for Student Import
 * High-Q Solid Academy Biometric Attendance System
 */
require_once __DIR__ . '/../includes/auth.php';
require_login();

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename=highq_student_import_template.csv');

$output = fopen('php://output', 'w');

// Header Column Names
fputcsv($output, [
    'admission_number',
    'surname',
    'firstname',
    'middlename',
    'gender',
    'class',
    'parent_name',
    'parent_phone',
    'parent_email'
]);

// Sample Rows for Demonstration
fputcsv($output, [
    'HQ/2026/001',
    'Adeboye',
    'Michael',
    'Oluwaseun',
    'Male',
    'Basic 1',
    'Mr. Adeboye',
    '08031234567',
    'adeboye.parent@gmail.com'
]);

fputcsv($output, [
    'HQ/2026/002',
    'Okonkwo',
    'Chidinma',
    'Grace',
    'Female',
    'Basic 2',
    'Mrs. Okonkwo',
    '08059876543',
    'okonkwo.parent@yahoo.com'
]);

fputcsv($output, [
    'HQ/2026/003',
    'Bello',
    'Ibrahim',
    'Kolawole',
    'Male',
    'JSS 1',
    'Alhaji Bello',
    '08021112233',
    'bello.parent@hotmail.com'
]);

fclose($output);
exit;
