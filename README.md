# High-Q Solid Academy — Biometric Attendance System

<p align="center">
  <strong>A production-grade, hardware-integrated Biometric Attendance System & Student Information Platform — powered by DigitalPersona U.are.U SDK, WPF C# Desktop Service, PHP & MySQL Backend, and SweetAlert2 UI.</strong>
</p>

---

## 📖 Overview

The **High-Q Solid Academy Biometric Attendance System** is an enterprise-ready biometric attendance and student registration platform built for modern educational institutions.

It seamlessly bridges hardware biometric scanners (DigitalPersona U.are.U 4500 / 5160) with a responsive web-based administration portal and a public touch kiosk terminal.

---

## ✨ System Features

### 👆 Biometric Hardware Integration
- **DigitalPersona U.are.U SDK Integration:** High-speed 1:1 and 1:N fingerprint matching and template enrollment.
- **Standalone C# WPF Service (`HighQBiometricService`):** Operates on `http://localhost:8080/` with a system tray icon, live activity log viewer, and hardware diagnostics.
- **Simulated Hardware Mode:** Full fallback support for testing without physical scanner hardware attached.

### 👤 Student Directory & Photo Uploads
- **Passport Photo Upload & Live Preview:** Upload student photos (JPG, PNG) during registration with instant image preview.
- **Photo Avatars:** Display circular photo avatars across the Student Directory, Fingerprint Enrollment cards, and Printable Registration Forms.
- **Auto-Generated Admission Numbers:** Sequential admission number generator per academic year (e.g. `HQ/2026/001`).
- **Dynamic Class Management:** Admin configurable classes (Basic 1, JSS 1, SSS 3, etc.) managed via Settings.
- **Bulk CSV Import with Photos:** Downloadable sample CSV template (`download_sample_csv.php`) supporting student photo filenames (`hq_2026_001.jpg`) for automated image linking.

### 🖥️ Public Kiosk Terminal (`index.php` & `terminal.php`)
- **Real-time Touch Scan Listener:** Automatically detects finger placements from the C# Desktop Service and marks attendance.
- **Live Clock & Date Header:** High-visibility kiosk interface with digital clock.
- **SweetAlert2 Feedback:** Modern, branded notification popups (`HighQSwal`) for instant attendance verification, error notices, and touch simulations.

### 📄 Offline Paperwork & Reports
- **Printable Registration Form (`admin/print_sample_registration_form.php`):** Printable PDF/Paper form template for offline student signups with passport photo placement.
- **Attendance & Student Exports:** Export attendance logs and student records to CSV or printable format.

---

## 🛠️ Architecture & Tech Stack

- **Frontend:** HTML5, TailwindCSS, Vanilla JavaScript, SweetAlert2 (`https://cdn.jsdelivr.net/npm/sweetalert2@11`), Material Symbols Icons.
- **Backend:** PHP 8.x, PDO MySQL (`highq_attendance`), REST APIs (`api/students.php`, `api/fingerprints.php`, `api/attendance.php`, `api/classes.php`).
- **Desktop Service:** C# .NET WPF (DigitalPersona SDK, `HttpListener` on `http://localhost:8080/`).

---

## 🚀 Quickstart Guide

### 1. Database Setup
1. Import `database/schema.sql` into MySQL (`highq_attendance`).
2. Default superadmin login: `admin` / `admin123`.

### 2. Web Portal (XAMPP / Apache)
Place project folder inside `htdocs`:
```text
c:\xampp\htdocs\highq-attendance\
```
Access the public terminal: `http://localhost/highq-attendance/`
Access the admin portal: `http://localhost/highq-attendance/admin/`

### 3. Biometric Desktop Service
Run the compiled executable or setup installer:
```text
biometric-service/HighQ_Biometric_Service_Setup_v1.0.exe
```
The service will start in the background and listen on `http://localhost:8080/`.

---

## 📄 License
High-Q Solid Academy — All Rights Reserved.
