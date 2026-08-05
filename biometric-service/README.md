# High-Q Solid Academy — Biometric Service & Installer

This directory contains the source code, WPF UI application, and Inno Setup installer for the High-Q Biometric Service.

---

## 🖥️ Desktop Service UI & Architecture

The **High-Q Biometric Service** provides hardware integration and local API endpoints for the High-Q Attendance system:

- **WPF Application:** Built with C# and .NET. Features side navigation layout with live hardware status, DigitalPersona U.are.U scanner indicator, local API server binding (`http://localhost:8080`), and real-time logs.
- **Desktop Icon:** Uses high-resolution icon assets (`app.ico` decoded in code-behind to prevent WPF BAML markup converter errors).
- **HTTP Server (`http://localhost:8080/`):**
  - `/status` — Reader connection & health check
  - `/enroll` & `/start_enrollment` — 4-finger template capture for Admin (`admin/enroll_fingerprint.php`)
  - `/terminal_scan_event` — Real-time touch scan event listener for Public Kiosk (`terminal.php` & `index.php`)
  - `/verify` — 1:1 Biometric verification test

---

## 🚀 Build & Packaging Instructions

### 1. Compile C# WPF Application
```powershell
cd biometric-service
dotnet publish -c Release -r win-x64 --self-contained true /p:PublishSingleFile=true
```

### 2. Compile Windows Installer
Open `HighQ_Biometric_Service_Setup.iss` in Inno Setup Compiler and click **Compile** (`Ctrl + F9`).
The compiled installer will be saved to:
```text
biometric-service/Output/HighQ_Biometric_Service_Setup_v1.0.exe
```
