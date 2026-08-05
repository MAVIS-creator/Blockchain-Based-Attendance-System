; High-Q Solid Academy Biometric Service & DigitalPersona Driver Smart All-In-One Setup

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
SetupIconFile=app.ico
UninstallDisplayIcon={app}\app.ico
PrivilegesRequired=admin

[Languages]
Name: "english"; MessagesFile: "compiler:Default.isl"

[Messages]
WelcomeLabel1=Welcome to High-Q Biometric Service Setup
WelcomeLabel2=This installer will automatically detect your system drivers and install the High-Q Biometric Companion Service along with any missing DigitalPersona Fingerprint Scanner Hardware Drivers.%n%nVersion: 1.0.0%nPublisher: High-Q Solid Academy%n%nYour biometric templates remain cryptographically protected.
ClickNext=Click Next to proceed with installing High-Q Biometric Service, or Cancel to exit.
FinishedHeadingLabel=Installation Complete
FinishedLabel=High-Q Biometric Service has been successfully installed on your computer.%n%nThe biometric service is now active in the background listening on http://localhost:8080/ for scanner events.

[Tasks]
Name: "driverinstall"; Description: "Install DigitalPersona Fingerprint Scanner Drivers (Skipped if already installed)"; GroupDescription: "Hardware Drivers:"; Check: IsDriverNeeded; Flags: checkedonce
Name: "desktopicon"; Description: "{cm:CreateDesktopIcon}"; GroupDescription: "{cm:AdditionalIcons}"
Name: "autostart"; Description: "Automatically launch High-Q Biometric Service on Windows boot"; GroupDescription: "System Integration:"

[Files]
Source: "bin\Release\net8.0-windows\win-x64\publish\{#MyAppExeName}"; DestDir: "{app}"; Flags: ignoreversion
Source: "bin\Release\net8.0-windows\win-x64\publish\*"; DestDir: "{app}"; Flags: ignoreversion recursesubdirs createallsubdirs
Source: "app.ico"; DestDir: "{app}"; Flags: ignoreversion
Source: "biometric_service desktop icon.png"; DestDir: "{app}"; Flags: ignoreversion
Source: "rte_x64\x64\*"; DestDir: "{tmp}\driver_rte"; Flags: deleteafterinstall recursesubdirs createallsubdirs; Check: IsDriverNeeded

[Icons]
Name: "{group}\{#MyAppName}"; Filename: "{app}\{#MyAppExeName}"; IconFilename: "{app}\app.ico"
Name: "{group}\{cm:UninstallProgram,{#MyAppName}}"; Filename: "{uninstallexe}"; IconFilename: "{app}\app.ico"
Name: "{autodesktop}\{#MyAppName}"; Filename: "{app}\{#MyAppExeName}"; IconFilename: "{app}\app.ico"; Tasks: desktopicon

[Registry]
Root: HKCU; Subkey: "Software\Microsoft\Windows\CurrentVersion\Run"; ValueType: string; ValueName: "HighQBiometricService"; ValueData: """{app}\{#MyAppExeName}"""; Tasks: autostart

[Run]
Filename: "msiexec.exe"; Parameters: "/i ""{tmp}\driver_rte\setup.msi"" /qn /norestart"; StatusMsg: "Installing DigitalPersona Fingerprint Reader Drivers..."; Tasks: driverinstall; Check: IsDriverNeeded; Flags: runhidden
Filename: "{app}\{#MyAppExeName}"; Description: "{cm:LaunchProgram,{#StringChange(MyAppName, '&', '&&')}}"; Flags: nowait postinstall skipifsilent runasoriginaluser

[Code]
function IsDriverNeeded(): Boolean;
begin
  if FileExists(ExpandConstant('{commonpf}\DigitalPersona\U.are.U RTE\Windows\Lib\.NET\DPUruNet.dll')) or
     FileExists(ExpandConstant('{commonpf64}\DigitalPersona\U.are.U RTE\Windows\Lib\.NET\DPUruNet.dll')) or
     FileExists(ExpandConstant('{sys}\dpfpdd.dll')) or
     RegKeyExists(HKLM, 'SOFTWARE\DigitalPersona') or
     RegKeyExists(HKLM, 'SOFTWARE\WOW6432Node\DigitalPersona') then
  begin
    Result := False; // Driver already installed on PC, skip driver installation
  end
  else
  begin
    Result := True; // Driver missing, proceed with installation
  end;
end;

procedure InitializeWizard();
begin
  WizardForm.Color := $FBF9F8;
  WizardForm.WelcomePage.Color := $FFFFFF;
  WizardForm.FinishedPage.Color := $FFFFFF;
  WizardForm.Font.Name := 'Segoe UI';
  WizardForm.Font.Size := 9;
end;
