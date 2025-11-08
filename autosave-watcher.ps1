<#
autosave-watcher.ps1
Watches the current repository directory for changes and automatically stages & commits tracked changes
with a timestamped message. Designed to be launched from the repo root.

Usage: powershell -ExecutionPolicy Bypass -File .\autosave-watcher.ps1
#>

Param(
    [int]$DebounceMs = 2000
)

Set-Location -Path (Split-Path -Path $MyInvocation.MyCommand.Path -Parent)

function Ensure-Git {
    try {
        git --version > $null 2>&1
        return $true
    } catch {
        return $false
    }
}

if (-not (Ensure-Git)) {
    Write-Host "git not found in PATH. Please install Git or add it to PATH." -ForegroundColor Red
    exit 1
}

$path = Get-Location
Write-Host "Starting autosave watcher in: $path"

# debounce timer
$timer = New-Object System.Timers.Timer
$timer.Interval = $DebounceMs
$timer.AutoReset = $false

$pending = $false

function Do-Commit {
    try {
        # Stage only modifications/deletions of tracked files
        git add -u

        # Check if there's anything staged
        git diff --cached --quiet
        if ($LASTEXITCODE -eq 0) {
            # nothing to commit
            return
        }

        $ts = (Get-Date).ToString('yyyy-MM-dd_HH-mm-ss')
        $msg = "Live commit $ts"
        git commit -m "$msg"
        if ($LASTEXITCODE -eq 0) {
            Write-Host "Committed: $msg"
        } else {
            Write-Host "Commit failed" -ForegroundColor Yellow
        }

            # Attempt to push commits to the remote
            try {
                git push origin HEAD
                if ($LASTEXITCODE -eq 0) { Write-Host "Pushed to remote." }
                else { Write-Host "Push failed (exit code $LASTEXITCODE). Will retry on next commit." -ForegroundColor Yellow }
            } catch {
                Write-Host "Push error: $_" -ForegroundColor Yellow
            }
    } catch {
        Write-Host "Error during commit: $_" -ForegroundColor Red
    }
}

Register-ObjectEvent -InputObject $timer -EventName Elapsed -SourceIdentifier AutosaveTimerElapsed -Action {
    Do-Commit
    $global:pending = $false
}

$fsw = New-Object System.IO.FileSystemWatcher $path -Property @{ IncludeSubdirectories = $true; NotifyFilter = [System.IO.NotifyFilters]'FileName, LastWrite, DirectoryName' }

$onChange = Register-ObjectEvent $fsw Changed -SourceIdentifier AutosaveChanged -Action {
    # signal pending and restart debounce timer
    $global:pending = $true
    $timer.Stop()
    $timer.Start()
}

$onCreate = Register-ObjectEvent $fsw Created -SourceIdentifier AutosaveCreated -Action {
    $global:pending = $true
    $timer.Stop(); $timer.Start()
}

$onDelete = Register-ObjectEvent $fsw Deleted -SourceIdentifier AutosaveDeleted -Action {
    $global:pending = $true
    $timer.Stop(); $timer.Start()
}

$onRename = Register-ObjectEvent $fsw Renamed -SourceIdentifier AutosaveRenamed -Action {
    $global:pending = $true
    $timer.Stop(); $timer.Start()
}

Write-Host "Autosave watcher started. Debounce: $DebounceMs ms. Ctrl+C to stop."

try {
    while ($true) { Start-Sleep -Seconds 1 }
} finally {
    Unregister-Event -SourceIdentifier AutosaveChanged -ErrorAction SilentlyContinue
    Unregister-Event -SourceIdentifier AutosaveCreated -ErrorAction SilentlyContinue
    Unregister-Event -SourceIdentifier AutosaveDeleted -ErrorAction SilentlyContinue
    Unregister-Event -SourceIdentifier AutosaveRenamed -ErrorAction SilentlyContinue
    Unregister-Event -SourceIdentifier AutosaveTimerElapsed -ErrorAction SilentlyContinue
    $fsw.Dispose()
    $timer.Dispose()
}
