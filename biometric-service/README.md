# High-Q Solid Academy - Biometric Service & Installer

This directory contains the desktop service application and installer builder for High-Q Solid Academy.

## Desktop Service UI & Architecture

The **High-Q Biometric Service** has been updated to use the new official UI design (`stitch_high_q_biometric_attendance_system (1)`):

- **WPF App UI:** Features the sleek Navy & Slate side navigation layout with live hardware status, DigitalPersona U.are.U 5160 reader indicator, local API server binding (`http://localhost:8080`), and real-time logs terminal.
- **Desktop Icon:** Uses `biometric_service desktop icon.png`.
- **Installer Wizard UI (`HighQ_Biometric_Service_Setup.iss`):** Configured with installer screens (Splash, Installer, System Check, Installing, Complete) and custom desktop shortcut icons.

## Build & Package Instructions

1. Compile the C# WPF Application:
```bash
cd biometric-service
dotnet publish -c Release -r win-x64 --self-contained true /p:PublishSingleFile=true
```

2. Compile the `.exe` Installer:
Open `HighQ_Biometric_Service_Setup.iss` in Inno Setup and click **Compile** (`Ctrl + F9`). The output installer will be produced in `biometric-service/Output/`.
