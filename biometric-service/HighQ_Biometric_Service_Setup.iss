; High-Q Solid Academy Biometric Service - Custom Stitch UI Inno Setup Script

#define MyAppName "High-Q Biometric Service"
#define MyAppVersion "1.0.0"
#define MyAppPublisher "High-Q Solid Academy"
#define MyAppURL "https://highqsolidacademy.com"
#define MyAppExeName "HighQBiometricService.exe"

[Setup]
AppId={{D37E88A1-42C4-4B2D-A31F-95BD01FA95F1}
AppName={#MyAppName}
AppVersion={#MyAppVersion}
AppPublisher={#MyAppPublisher}
AppPublisherURL={#MyAppURL}
AppSupportURL={#MyAppURL}
AppUpdatesURL={#MyAppURL}
DefaultDirName={autopf}\High-Q Solid Academy\Biometric Service
DefaultGroupName={#MyAppPublisher}
DisableProgramGroupPage=yes
LicenseFile=..\LICENSE
OutputDir=Output
OutputBaseFilename=HighQ_Biometric_Service_Setup_v1.0
Compression=lzma2/ultra64
SolidCompression=yes
WizardStyle=modern
UninstallDisplayIcon={app}\{#MyAppExeName}

; Stitch UI Color & Font Styling
WizardResizable=no
WizardSizePercent=100
ShowComponentSizes=no

[Languages]
Name: "english"; MessagesFile: "compiler:Default.isl"

[Messages]
WelcomeLabel1=Welcome to High-Q Biometric Service
WelcomeLabel2=Install the biometric companion service required to connect your fingerprint scanner to the High-Q Attendance System.%n%nVersion: 1.0.0%nPublisher: High-Q Solid Academy%n%nYour biometric templates remain protected and are never displayed in this application.
ClickNext=Click Next to proceed with the High-Q Biometric Service installation, or Cancel to exit.
FinishedHeadingLabel=Installation Complete
FinishedLabel=High-Q Biometric Service has been successfully installed on your computer.%n%nThe biometric service is now running in the background listening on http://localhost:8080/ for fingerprint scanner events.

[Tasks]
Name: "desktopicon"; Description: "{cm:CreateDesktopIcon}"; GroupDescription: "{cm:AdditionalIcons}"
Name: "autostart"; Description: "Automatically launch High-Q Biometric Service on Windows boot"; GroupDescription: "System Integration:"

[Files]
Source: "bin\Release\net8.0-windows\win-x64\publish\{#MyAppExeName}"; DestDir: "{app}"; Flags: ignoreversion
Source: "bin\Release\net8.0-windows\win-x64\publish\*"; DestDir: "{app}"; Flags: ignoreversion recursesubdirs createallsubdirs
Source: "biometric_service desktop icon.png"; DestDir: "{app}"; Flags: ignoreversion

[Icons]
Name: "{group}\{#MyAppName}"; Filename: "{app}\{#MyAppExeName}"
Name: "{group}\{cm:UninstallProgram,{#MyAppName}}"; Filename: "{uninstallexe}"
Name: "{autodesktop}\{#MyAppName}"; Filename: "{app}\{#MyAppExeName}"; Tasks: desktopicon

[Registry]
Root: HKCU; Subkey: "Software\Microsoft\Windows\CurrentVersion\Run"; ValueType: string; ValueName: "HighQBiometricService"; ValueData: """{app}\{#MyAppExeName}"""; Tasks: autostart

[Run]
Filename: "{app}\{#MyAppExeName}"; Description: "{cm:LaunchProgram,{#StringChange(MyAppName, '&', '&&')}}"; Flags: nowait postinstall skipifsilent

[Code]
procedure InitializeWizard();
begin
  // Stitch UI Navy & Slate Custom Colors for Wizard Controls
  WizardForm.Color := $FBF9F8; // #f8f9fb
  WizardForm.WelcomePage.Color := $FFFFFF;
  WizardForm.FinishedPage.Color := $FFFFFF;
  WizardForm.Font.Name := 'Segoe UI';
  WizardForm.Font.Size := 9;
end;
