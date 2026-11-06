<#
.SYNOPSIS
    Update NunaBooks in place. Run by the "NunaBooksUpdate" scheduled task
    when the owner presses "Update now" on the Health screen; it can also be
    run by hand from an Administrator PowerShell. PLAN.md 6.1.

.DESCRIPTION
    1. Stops the Apache service, so no sale lands after the backup.
    2. Takes a verified backup (php artisan nuna:backup). If that fails it
       starts Apache again and stops here; nothing has changed.
    3. git pull --ff-only and composer install --no-dev. If either fails:
       puts the code back to the commit it started from and starts that.
    4. php artisan migrate --force, caches config, routes and views, starts
       Apache and checks the app answers on /up.
    5. If anything from the migration on fails: puts the code back AND
       restores the backup from step 2 (nuna:restore), then starts again.
       Once a migration has begun the books may be half changed, so the
       code is never put back on its own.

    The outcome is written as JSON to -StatusPath, which the Health page
    reads back. Output goes to storage\logs\update.log.
#>

param(
    [string]$StatusPath = (Join-Path (Split-Path -Parent $PSScriptRoot) "storage\app\update-status.json")
)

$ErrorActionPreference = "Stop"
$root = Split-Path -Parent $PSScriptRoot
$startedAt = (Get-Date).ToUniversalTime().ToString("o")
$from = ""
$service = ""
$backupFile = ""
$migrateStarted = $false

function Write-Status([string]$State, [string]$Message, [string]$To = $from) {
    $status = [ordered]@{
        state      = $State
        startedAt  = $startedAt
        finishedAt = (Get-Date).ToUniversalTime().ToString("o")
        from       = $from
        to         = $To
        message    = $Message
    }
    [IO.File]::WriteAllText($StatusPath, ($status | ConvertTo-Json))
}

# Runs a native command and returns its exit code. Under PS 5.1 with Stop, a line on stderr
# would end the script, so stderr is folded into the output as text and only the exit code counts.
function Invoke-Exe([string]$Exe, [string[]]$Arguments, [ref]$Output) {
    $ErrorActionPreference = "Continue"
    $lines = & $Exe @Arguments 2>&1 | ForEach-Object { "$_" }
    $code = $LASTEXITCODE
    if ($null -ne $Output) { $Output.Value = ($lines -join "`r`n") }
    return $code
}

function Invoke-Native([string]$Title, [string]$Exe, [string[]]$Arguments) {
    Write-Host "==> $Title"
    $text = ""
    $code = Invoke-Exe $Exe $Arguments ([ref]$text)
    Write-Host $text
    return $code -eq 0
}

function Stop-Web {
    try { Stop-Service $service -ErrorAction Stop } catch { Write-Host "Could not stop ${service}: $_" }
}

function Test-Serving {
    try { Start-Service $service -ErrorAction Stop } catch { Write-Host "Could not start ${service}: $_"; return $false }
    for ($i = 0; $i -lt 20; $i++) {
        try {
            if ((Invoke-WebRequest -Uri $healthUrl -UseBasicParsing -TimeoutSec 5).StatusCode -eq 200) { return $true }
        }
        catch { Start-Sleep -Seconds 2 }
    }
    return $false
}

function Install-Packages {
    return Invoke-Native "Installing PHP packages" $composer @("install", "--no-dev", "--optimize-autoloader", "--no-interaction")
}

function Install-Code {
    return (Install-Packages) -and (Update-Caches)
}

function Update-Caches {
    return (Invoke-Native "Caching configuration" $php @("artisan", "config:cache")) -and
           (Invoke-Native "Caching routes" $php @("artisan", "route:cache")) -and
           (Invoke-Native "Caching views" $php @("artisan", "view:cache"))
}

# Puts the code back and, once a migration has begun, the books too. Always ends the script.
function Undo-Update {
    Write-Host "==> Update failed; putting back $from"
    Stop-Web
    # --hard is safe here: .env, storage and the backups are not in git, so only code moves.
    $codeBack = (Invoke-Native "Restoring the previous code" "git" @("reset", "--hard", $from)) -and (Install-Code)
    if ($migrateStarted) {
        $booksBack = Invoke-Native "Restoring $backupFile" $php @("artisan", "nuna:restore", $backupFile, "--force")
        if ($codeBack -and $booksBack -and (Test-Serving)) {
            Write-Status "failed" "The update failed, so the app was put back on the version it was and the books restored from the backup taken just before ($backupFile). Details are in storage/logs/update.log."
        }
        else {
            Write-Status "failed" "The update failed and the app could not be started again. Nothing is lost: the books from just before are in $backupFile. Run scripts\install.ps1 on this computer. Details are in storage/logs/update.log."
        }
        exit 1
    }
    if ($codeBack -and (Test-Serving)) {
        Write-Status "failed" "The update could not be installed, so the app was put back on the version it was. Details are in storage/logs/update.log."
    }
    else {
        Write-Status "failed" "The update could not be installed and the previous version will not start. The books were not touched; the backup taken just before is $backupFile. Run scripts\install.ps1 on this computer. Details are in storage/logs/update.log."
    }
    exit 1
}

try {
    Set-Location $root
    Start-Transcript -Path (Join-Path $root "storage\logs\update.log") -Force | Out-Null

    $install = Get-Content (Join-Path $root "storage\app\install.json") -Raw | ConvertFrom-Json
    $php = $install.php
    $composer = $install.composer
    $service = $install.apacheService
    $healthUrl = "http://127.0.0.1:$($install.port)/up"

    # A git that wants a password must fail, not wait for a keyboard nobody is at; and trust this folder.
    $env:GIT_TERMINAL_PROMPT = "0"
    $env:GIT_CONFIG_COUNT = "1"
    $env:GIT_CONFIG_KEY_0 = "safe.directory"
    $env:GIT_CONFIG_VALUE_0 = $root -replace '\\', '/'

    $head = ""
    if ((Invoke-Exe "git" @("rev-parse", "HEAD") ([ref]$head)) -ne 0) { throw "git rev-parse failed: $head" }
    $from = $head.Trim()

    Write-Status "running" "Updating."

    # --- 1. Stop the web server, so nothing is sold after the backup -----------------------

    Write-Host "==> Stopping $service"
    Stop-Service $service

    # --- 2. Backup ---------------------------------------------------------------------------

    Write-Host "==> Taking a backup"
    $backupOutput = ""
    $code = Invoke-Exe $php @("artisan", "nuna:backup") ([ref]$backupOutput)
    Write-Host $backupOutput
    if ($code -ne 0 -or $backupOutput -notmatch "Backup written and verified: (.+)") {
        $started = Test-Serving
        Write-Status "failed" ("The backup before updating could not be taken, so nothing was changed. The app is still on the version it was" + $(if ($started) { "." } else { ", but it did not start again; start Apache from Laragon." }))
        exit 1
    }
    $backupFile = $Matches[1].Trim()

    # --- 3. Pull and install: code only, the books are untouched -----------------------------

    $ok = (Invoke-Native "Downloading the new version" "git" @("pull", "--ff-only")) -and
          (Install-Packages)
    if (-not $ok) { Undo-Update }

    # --- 4. Migrate: from here on a failure restores the books as well -----------------------

    $migrateStarted = $true
    $ok = (Invoke-Native "Migrating the database" $php @("artisan", "migrate", "--force")) -and (Update-Caches)
    $to = ""
    [void](Invoke-Exe "git" @("rev-parse", "HEAD") ([ref]$to))

    if ($ok -and (Test-Serving)) {
        Write-Status "done" "Updated." $to.Trim()
        exit 0
    }
    Undo-Update
}
catch {
    Write-Host "Update stopped: $_"
    try {
        if ($from -ne "" -and $backupFile -ne "") { Undo-Update }
        Write-Status "failed" "The update stopped before it changed anything: $_. Details are in storage/logs/update.log."
        if ($service -ne "") { [void](Test-Serving) }
    }
    catch {
        Write-Status "failed" "The update stopped: $_. Details are in storage/logs/update.log."
    }
    exit 1
}
