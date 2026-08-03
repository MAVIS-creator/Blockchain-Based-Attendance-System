# High-Q Solid Academy - Biometric Service & Installer

This directory contains the desktop service and setup installer builder for the High-Q Solid Academy Biometric Attendance System.

## Architecture

The **High-Q Biometric Service** is a lightweight C# .NET WPF desktop application that runs in the background on attendance computers.

- **Hardware Layer:** Communicates with the DigitalPersona U.are.U 5160 fingerprint scanner via `DPUruNet.dll`.
- **Local API Listener:** Hosts a lightweight HTTP listener on `http://localhost:8080`.
- **UI:** Modern dark-mode WPF dashboard matching High-Q Solid branding (`#0F172A`, `#1E293B`, `#FDC014`), featuring live status indicators, system tray minimization, and real-time logs.

## Building the C# Desktop Service

Prerequisites:
- .NET 8.0 SDK (or Visual Studio 2022)
- DigitalPersona SDK runtime drivers installed on the attendance PC

To compile the application:

```bash
cd biometric-service
dotnet publish -c Release -r win-x64 --self-contained true /p:PublishSingleFile=true
```

## Creating the Modern `.exe` Installer Wizard

1. Install [Inno Setup 6](https://jrsoftware.org/isdl.php).
2. Open `HighQ_Biometric_Service_Setup.iss` in Inno Setup.
3. Click **Compile** (`Ctrl + F9`).
4. The output installer `HighQ_Biometric_Service_Setup_v1.0.exe` will be generated in `biometric-service/Output/`.

The installer provides:
- Custom wizard UI with High-Q branding.
- Desktop and Start Menu shortcuts.
- Auto-start registration on Windows boot.
