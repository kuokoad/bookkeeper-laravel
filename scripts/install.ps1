<#
.SYNOPSIS
    Install NunaBooks on the shop PC: MySQL and Apache (mod_fcgid, PHP NTS)
    as Windows services that start at boot, the database and its users,
    .env, Composer packages, migrations, the baseline data, the firewall
    rule, the hosts entry and a desktop shortcut. PLAN.md Q1 and 6.1.

.DESCRIPTION
    Run from the cloned app folder in PowerShell opened with "Run as
    Administrator":

        git clone <repository> C:\NunaBooks\app
        cd C:\NunaBooks\app
        .\scripts\install.ps1 -Firewall

    It does not download PHP, MySQL or Apache. Point it at where they are
    (the defaults are Laragon's folders) with -PhpDir, -MysqlDir and
    -ApacheDir. Apache needs mod_fcgid.so in its modules folder.

    Running it again is safe: services, the database, the MySQL users and
    .env that already exist are left as they are, and an existing database
    is backed up (nuna:backup) before migrations run.

    A new MySQL gets a random root password, shown ONCE on screen for the
    owner to write down. It is not saved in .env or any other file; the app
    signs in as its own MySQL user, whose password is in .env.

    The built CSS and JS ship with each release (public\build), so the shop
    PC needs neither Node nor the internet to trade.

.PARAMETER Firewall
    Allow other PCs on the PRIVATE network to reach the app (a second till).
#>

param(
    [string]$AppDir = (Split-Path -Parent $PSScriptRoot),
    [string]$PhpDir = "C:\laragon\bin\php\php-8.4.19-nts-Win32-vs17-x64",
    [string]$MysqlDir = "C:\laragon\bin\mysql\mysql-8.4.0-winx64",
    [string]$ApacheDir = "C:\laragon\bin\apache\httpd-2.4.62-240904-win64-VS17",
    [string]$DataDir = "C:\NunaBooks\mysql-data",
    [string]$DeployKeyDir = "C:\NunaBooks\update-key",
    [string]$Database = "nunabooks",
    [string]$HostName = "nunabooks.local",
    [int]$Port = 80,
    [string]$MysqlService = "NunaBooksMySQL",
    [string]$ApacheService = "NunaBooksApache",
    [switch]$Firewall
)

$ErrorActionPreference = "Stop"

$principal = New-Object Security.Principal.WindowsPrincipal([Security.Principal.WindowsIdentity]::GetCurrent())
if (-not $principal.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) {
    throw "Open PowerShell with 'Run as Administrator' and run this again. Installing services and opening the firewall both need it."
}

$php = Join-Path $PhpDir "php.exe"
# Anything that looks php up by name (Composer's scripts) finds this one.
$env:Path = "$PhpDir;$env:Path"
$mysql = Join-Path $MysqlDir "bin\mysql.exe"
$mysqld = Join-Path $MysqlDir "bin\mysqld.exe"
$httpd = Join-Path $ApacheDir "bin\httpd.exe"
foreach ($required in @($php, $mysql, $mysqld, $httpd, (Join-Path $ApacheDir "modules\mod_fcgid.so"))) {
    if (-not (Test-Path $required)) { throw "Not found: $required. Pass the right -PhpDir, -MysqlDir or -ApacheDir." }
}

function Invoke-Checked([string]$Title, [string]$Exe, [string[]]$Arguments) {
    Write-Host "==> $Title"
    & $Exe @Arguments | Out-Host
    if ($LASTEXITCODE -ne 0) { throw "$Title failed (exit code $LASTEXITCODE)." }
}

function New-Secret {
    $bytes = New-Object byte[] 24
    [Security.Cryptography.RandomNumberGenerator]::Create().GetBytes($bytes)
    return ([Convert]::ToBase64String($bytes) -replace '[+/=]', 'x')
}

function Set-EnvValue([string]$Path, [string]$Key, [string]$Value) {
    $lines = @(Get-Content $Path)
    $found = $false
    for ($i = 0; $i -lt $lines.Count; $i++) {
        if ($lines[$i] -match "^#?\s*$Key=") { $lines[$i] = "$Key=$Value"; $found = $true }
    }
    if (-not $found) { $lines += "$Key=$Value" }
    [IO.File]::WriteAllLines($Path, $lines)
}

# Root's password goes to the client through the environment, never the command line or a file.
function Invoke-Root([string]$Sql, [string[]]$Extra = @()) {
    $env:MYSQL_PWD = $rootPassword
    try {
        $ErrorActionPreference = "Continue"
        $out = $Sql | & $mysql -uroot @Extra 2>&1 | ForEach-Object { "$_" }
        return [pscustomobject]@{ Ok = ($LASTEXITCODE -eq 0); Output = ($out -join "`n") }
    }
    finally { Remove-Item Env:MYSQL_PWD -ErrorAction SilentlyContinue }
}

function Set-RestartOnFailure([string]$Name) {
    & sc.exe failure $Name reset= 86400 actions= restart/5000/restart/5000/restart/30000 | Out-Null
}

# --- 1. PHP: the extensions this app needs, and opcache with the JIT -------------------

$phpIni = Join-Path $PhpDir "php.ini"
if (-not (Test-Path $phpIni)) { Copy-Item (Join-Path $PhpDir "php.ini-production") $phpIni }
$ini = Get-Content $phpIni -Raw
# The JIT takes a first sign-in with an old-app password from about 6 s to under 1 s (LegacyScrypt).
$wanted = @(
    # zip: without it Composer cannot unpack a package and clones each one from source instead, slowly.
    "extension=intl", "extension=mbstring", "extension=pdo_mysql", "extension=fileinfo", "extension=openssl", "extension=zip",
    "zend_extension=opcache", "opcache.enable=1", "opcache.enable_cli=1", "opcache.jit=tracing", "opcache.jit_buffer_size=64M",
    "memory_limit=512M", "max_execution_time=300"
)
$missing = $wanted | Where-Object { $ini -notmatch ("(?m)^" + [regex]::Escape($_) + "\s*$") }
if ($missing) {
    Add-Content $phpIni ("`r`n; NunaBooks`r`n" + ($missing -join "`r`n"))
    Write-Host "==> Added to php.ini: $($missing -join ', ')"
}

# --- 2. MySQL as a service, listening on this PC only ----------------------------------

$myIni = Join-Path (Split-Path $DataDir -Parent) "my.ini"
if (-not (Get-Service $MysqlService -ErrorAction SilentlyContinue)) {
    New-Item -ItemType Directory -Force (Split-Path $DataDir -Parent) | Out-Null
    $dataPath = $DataDir -replace '\\', '/'
    $basePath = $MysqlDir -replace '\\', '/'
    [IO.File]::WriteAllLines($myIni, @(
        "[mysqld]",
        "basedir=$basePath",
        "datadir=$dataPath",
        "bind-address=127.0.0.1",
        "port=3306",
        "character-set-server=utf8mb4",
        "collation-server=utf8mb4_0900_bin",
        "default-time-zone=+00:00",
        "transaction-isolation=READ-COMMITTED",
        "# The migrations create triggers; with the binary log on, MySQL needs this to let the app user do so.",
        "log_bin_trust_function_creators=1",
        "innodb_buffer_pool_size=256M"
    ))
    if (-not (Test-Path $DataDir)) {
        # --initialize gives root a one-time password, expired, printed in the console log.
        Write-Host "==> Creating the MySQL data folder"
        $ErrorActionPreference = "Continue"
        $initLog = (& $mysqld "--defaults-file=$myIni" "--initialize" "--console" 2>&1 | ForEach-Object { "$_" }) -join "`n"
        $initCode = $LASTEXITCODE
        $ErrorActionPreference = "Stop"
        if ($initCode -ne 0 -or $initLog -notmatch "temporary password is generated for root@localhost: (\S+)") {
            throw "Creating the MySQL data folder failed (exit code $initCode)."
        }
        $temporaryPassword = $Matches[1]
    }
    Invoke-Checked "Installing the MySQL service" $mysqld @("--install", $MysqlService, "--defaults-file=$myIni")
}
Set-Service $MysqlService -StartupType Automatic
Set-RestartOnFailure $MysqlService
Start-Service $MysqlService

$rootPassword = ""
if ($temporaryPassword) {
    $newRoot = New-Secret
    $rootPassword = $temporaryPassword
    for ($i = 0; $i -lt 30; $i++) {
        if ((Invoke-Root "ALTER USER 'root'@'localhost' IDENTIFIED BY '$newRoot';" @("--connect-expired-password")).Ok) { $temporaryPassword = $null; break }
        Start-Sleep -Seconds 1
    }
    if ($temporaryPassword) { throw "Could not set the MySQL root password." }
    $rootPassword = $newRoot
    Write-Host ""
    Write-Host "  MySQL root password (shown once, write it down and keep it safe):"
    Write-Host ""
    Write-Host "      $rootPassword"
    Write-Host ""
    Write-Host "  It is not saved anywhere. The app does not need it; you will, to repair MySQL by hand."
    Write-Host ""
}

# --- 3. The database and its two users ------------------------------------------------

$envFile = Join-Path $AppDir ".env"
if (-not (Test-Path $envFile)) {
    if ($rootPassword -eq "") {
        $secure = Read-Host "MySQL root password (to create the app's own MySQL user)" -AsSecureString
        $rootPassword = [Runtime.InteropServices.Marshal]::PtrToStringAuto([Runtime.InteropServices.Marshal]::SecureStringToBSTR($secure))
    }
    for ($i = 0; $i -lt 30; $i++) {
        if ((Invoke-Root "SELECT 1;").Ok) { break }
        Start-Sleep -Seconds 1
    }
    # The migrations create triggers; with the binary log on and this off, MySQL refuses the app user.
    $binlog = (Invoke-Root "SELECT @@log_bin, @@log_bin_trust_function_creators;" @("-N", "-B")).Output.Trim()
    if ($binlog -match "^1\s+0$") {
        Write-Warning "MySQL has the binary log ON and log_bin_trust_function_creators OFF: the migrations will be refused CREATE TRIGGER. Add log_bin_trust_function_creators=1 to my.ini under [mysqld] and restart MySQL."
    }
    Copy-Item (Join-Path $AppDir ".env.example") $envFile
    $appPassword = New-Secret
    $backupPassword = New-Secret
    $sql = @"
CREATE DATABASE IF NOT EXISTS ``$Database`` CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin;
CREATE USER IF NOT EXISTS 'nunabooks'@'localhost' IDENTIFIED BY '$appPassword';
ALTER USER 'nunabooks'@'localhost' IDENTIFIED BY '$appPassword';
GRANT ALL PRIVILEGES ON ``$Database``.* TO 'nunabooks'@'localhost';
GRANT ALL PRIVILEGES ON ``${Database}\_scratch\_%``.* TO 'nunabooks'@'localhost';
CREATE USER IF NOT EXISTS 'nunabooks_backup'@'localhost' IDENTIFIED BY '$backupPassword';
ALTER USER 'nunabooks_backup'@'localhost' IDENTIFIED BY '$backupPassword';
GRANT SELECT, SHOW VIEW, TRIGGER, LOCK TABLES, EVENT ON ``$Database``.* TO 'nunabooks_backup'@'localhost';
FLUSH PRIVILEGES;
"@
    Write-Host "==> Creating the database and its MySQL users"
    $created = Invoke-Root $sql
    $rootPassword = ""
    if (-not $created.Ok) {
        Remove-Item $envFile
        throw "Could not create the database: $($created.Output). Check the MySQL root password, or create the users by hand (see README)."
    }

    Set-EnvValue $envFile "APP_ENV" "production"
    Set-EnvValue $envFile "APP_DEBUG" "false"
    Set-EnvValue $envFile "DB_DATABASE" $Database
    Set-EnvValue $envFile "DB_USERNAME" "nunabooks"
    Set-EnvValue $envFile "DB_PASSWORD" $appPassword
    Set-EnvValue $envFile "NUNA_BACKUP_DB_USERNAME" "nunabooks_backup"
    Set-EnvValue $envFile "NUNA_BACKUP_DB_PASSWORD" $backupPassword
    Set-EnvValue $envFile "NUNA_APACHE_SERVICE" $ApacheService
    Set-EnvValue $envFile "SEED_DEMO_DATA" "false"
}
# Every run, not only a new .env: without it the backup picks the highest mysqldump.exe under
# C:\laragon\bin\mysql, a folder any Windows account can add to, and runs it as SYSTEM with the password.
Set-EnvValue $envFile "NUNA_MYSQL_BIN" (Join-Path $MysqlDir "bin")
# Every run as well, and the same address the desktop shortcut opens (section 6), port and all:
# the logo, the Dashboard link and the error pages link to APP_URL.
$appUrl = if ($Port -eq 80) { "http://$HostName" } else { "http://${HostName}:$Port" }
Set-EnvValue $envFile "APP_URL" $appUrl

# --- 4. The app: packages, key, backup, migrations, caches ----------------------------

Set-Location $AppDir
# Composer runs as composer.phar under this PHP, from the PHP folder (restricted below to Administrators
# and SYSTEM): composer.bat needs php on the PATH, which SYSTEM's update task does not have, and it sits
# in a folder any Windows account can change, so whoever edits it would have their code run as SYSTEM.
$found = (Get-Command composer -ErrorAction SilentlyContinue).Source
$sourcePhar = if ($found) { Join-Path (Split-Path $found) "composer.phar" } else { "C:\laragon\bin\composer\composer.phar" }
$composer = Join-Path $PhpDir "composer.phar"
# Copied once only: the source folder is not locked, so a later copy could carry an edited composer.phar
# into the locked PHP folder. To update Composer, delete $composer and run this again.
if (-not (Test-Path $composer) -and (Test-Path $sourcePhar)) { Copy-Item $sourcePhar $composer }
if (-not (Test-Path $composer)) { throw "composer.phar not found next to $found or in C:\laragon\bin\composer. Install Composer, then run this again." }
Invoke-Checked "Installing PHP packages" $php @($composer, "install", "--no-dev", "--optimize-autoloader", "--no-interaction")
if ((Get-Content $envFile -Raw) -match "(?m)^APP_KEY=\s*$") {
    Invoke-Checked "Generating the app key" $php @("artisan", "key:generate", "--force")
}

# Skip the backup only when the database answers that it has no active owner: a new database (no
# users table) or one where the owner has not signed up, so nothing can have been traded (every sale,
# purchase and import carries a user), and nuna:backup would refuse anyway. Any other answer, a probe
# that errors included, backs up first, and a backup that cannot run stops the install here, before
# the live books are migrated.
$probe = 'echo Illuminate\Support\Facades\Schema::hasTable(''users'') && Illuminate\Support\Facades\DB::table(''users'')->where(''role'', ''OWNER'')->where(''is_active'', true)->exists() ? ''has-owner'' : ''no-owner'';'
# Continue around it: under "Stop", Windows PowerShell 5.1 turns any stderr from the probe into a
# terminating error, and a probe that errors must back up, not end the install.
$ErrorActionPreference = "Continue"
try { $noOwner = ((& $php artisan tinker --execute=$probe 2>$null) -join "`n") -match "(?m)^no-owner\s*$" }
finally { $ErrorActionPreference = "Stop" }
if (-not $noOwner) {
    Invoke-Checked "Backing up the existing books before migrating" $php @("artisan", "nuna:backup")
} else {
    Write-Host "==> No owner account yet: nothing has been traded, so there is nothing to back up"
}
Invoke-Checked "Migrating the database" $php @("artisan", "migrate", "--force")
# The baseline every shop needs (settings row, numbering, chart of accounts, taxes).
# CoreSeeder only adds what is missing, so a re-run never touches the shop's own settings.
Invoke-Checked "Loading the baseline data" $php @("artisan", "db:seed", "--class=Database\Seeders\CoreSeeder", "--force")
foreach ($cache in @("config:cache", "route:cache", "view:cache")) {
    Invoke-Checked "php artisan $cache" $php @("artisan", $cache)
}

# --- 5. Apache with mod_fcgid as a service --------------------------------------------

$public = (Join-Path $AppDir "public") -replace '\\', '/'
$phpCgi = (Join-Path $PhpDir "php-cgi.exe") -replace '\\', '/'
$phpRc = $PhpDir -replace '\\', '/'
$vhost = Join-Path $ApacheDir "conf\extra\nunabooks.conf"
[IO.File]::WriteAllLines($vhost, @(
    "LoadModule fcgid_module modules/mod_fcgid.so",
    "LoadModule rewrite_module modules/mod_rewrite.so",
    "Listen $Port",
    "FcgidInitialEnv PHPRC `"$phpRc`"",
    "FcgidIOTimeout 300",
    "FcgidMaxRequestLen 20971520",
    "FcgidMaxRequestsPerProcess 1000",
    "<VirtualHost *:$Port>",
    "    ServerName $HostName",
    "    DocumentRoot `"$public`"",
    "    <Directory `"$public`">",
    "        Options -Indexes +FollowSymLinks +ExecCGI",
    "        AllowOverride All",
    "        Require all granted",
    "        AddHandler fcgid-script .php",
    "        FcgidWrapper `"$phpCgi`" .php",
    "        DirectoryIndex index.php",
    "    </Directory>",
    "</VirtualHost>"
))
$httpdConf = Join-Path $ApacheDir "conf\httpd.conf"
$conf = Get-Content $httpdConf -Raw
# Listen and the fcgid/rewrite modules come from nunabooks.conf; the stock ones would clash.
$conf = $conf -replace "(?m)^Listen ", "#Listen " -replace "(?m)^LoadModule rewrite_module", "#LoadModule rewrite_module"
# A Laragon that has never been started still has Apache's stock SRVROOT ("c:/Apache24"), which is not
# there, so httpd -t says "ServerRoot must be a valid directory". Point both lines at this Apache.
$serverRoot = $ApacheDir -replace '\\', '/'
$conf = [regex]::Replace($conf, '(?m)^Define SRVROOT [^\r\n]*', { "Define SRVROOT `"$serverRoot`"" })
$conf = [regex]::Replace($conf, '(?m)^ServerRoot [^\r\n]*', { "ServerRoot `"$serverRoot`"" })
if ($conf -notmatch "nunabooks\.conf") { $conf += "`r`nInclude conf/extra/nunabooks.conf`r`n" }
[IO.File]::WriteAllText($httpdConf, $conf)
Invoke-Checked "Checking the Apache configuration" $httpd @("-t")
if (-not (Get-Service $ApacheService -ErrorAction SilentlyContinue)) {
    Invoke-Checked "Installing the Apache service" $httpd @("-k", "install", "-n", $ApacheService)
}
Set-Service $ApacheService -StartupType Automatic
Set-RestartOnFailure $ApacheService
Restart-Service $ApacheService

# --- 6. Reaching it: firewall, hosts entry, shortcut, the update task -----------------

if ($Firewall -and -not (Get-NetFirewallRule -DisplayName "NunaBooks" -ErrorAction SilentlyContinue)) {
    New-NetFirewallRule -DisplayName "NunaBooks" -Direction Inbound -Protocol TCP -LocalPort $Port -Profile Private -Action Allow | Out-Null
    Write-Host "==> Firewall: port $Port open on PRIVATE networks only"
}
$hosts = "$env:SystemRoot\System32\drivers\etc\hosts"
if ((Get-Content $hosts -Raw) -notmatch [regex]::Escape($HostName)) {
    Add-Content $hosts "`r`n127.0.0.1 $HostName"
}
[IO.File]::WriteAllLines((Join-Path ([Environment]::GetFolderPath("CommonDesktopDirectory")) "NunaBooks.url"), @("[InternetShortcut]", "URL=$appUrl/"))

# The Health screen's "Update now" runs this task: a child of Apache would die when the update stops it.
$update = Join-Path $AppDir "scripts\update.ps1"
$action = New-ScheduledTaskAction -Execute "powershell.exe" -Argument "-NoProfile -ExecutionPolicy Bypass -File `"$update`"" -WorkingDirectory $AppDir
Register-ScheduledTask -TaskName "NunaBooksUpdate" -Action $action -User "SYSTEM" -RunLevel Highest -Force | Out-Null

# A backup every evening, without anyone remembering to take one. -Force replaces the task on a re-run;
# StartWhenAvailable runs a missed one once the PC is back on.
$backup = New-ScheduledTaskAction -Execute $php -Argument "artisan nuna:backup" -WorkingDirectory $AppDir
$daily = New-ScheduledTaskTrigger -Daily -At "21:00"
$settings = New-ScheduledTaskSettingsSet -StartWhenAvailable
Register-ScheduledTask -TaskName "NunaBooksBackup" -Action $backup -Trigger $daily -Settings $settings -User "SYSTEM" -RunLevel Highest -Force | Out-Null

# The tasks, MySQL and Apache all run as SYSTEM from these folders (the app, the data, and the PHP,
# Apache and MySQL programs with their config), and .env holds the database password: only
# Administrators and SYSTEM may read or change them, or a cashier's Windows account could edit
# artisan or php.ini and have it run as SYSTEM. SIDs, so a French or German Windows works too.
function Lock-Folder([string]$Dir) {
    $full = [IO.Path]::GetFullPath($Dir).TrimEnd('\')
    # Never a whole drive: /reset /T would rewrite every file on it.
    if ([IO.Path]::GetPathRoot("$full\") -eq "$full\") { Write-Warning "Not restricting $full`: it is a whole drive. Move it into a folder."; return }
    # A USB stick (FAT32/exFAT) or a network share has no NTFS rights to set.
    $fs = (Get-Volume -FilePath $full -ErrorAction SilentlyContinue).FileSystemType
    if ($fs -ne 'NTFS') { Write-Warning "Not restricting $full`: it is not on a local NTFS drive. Keep that drive somewhere only the owner can reach."; return }
    Invoke-Checked "Restricting $full to Administrators and SYSTEM" "icacls.exe" @($full, "/inheritance:r", "/grant:r", "*S-1-5-32-544:(OI)(CI)F", "*S-1-5-18:(OI)(CI)F", "/Q")
    # Everything inside takes the folder's rules, dropping any rights a file was given of its own.
    # A file it cannot reset is a warning, not a reason to stop half-installed.
    Write-Host "==> Applying them to everything in $full"
    & icacls.exe "$full\*" /reset /T /C /Q | Out-Host
    if ($LASTEXITCODE -ne 0) { Write-Warning "Some files in $full kept their own rights (icacls exit code $LASTEXITCODE). Check with: icacls `"$full`" /T /C" }
}
foreach ($dir in @($AppDir, (Split-Path $DataDir -Parent), $PhpDir, $ApacheDir, $MysqlDir)) { Lock-Folder $dir }
$backupDir = [regex]::Match((Get-Content $envFile -Raw), "(?m)^NUNA_BACKUP_DIR=(.+?)\s*$").Groups[1].Value.Trim('"')
if ($backupDir) {
    if (Test-Path $backupDir) { Lock-Folder $backupDir }
    else { Write-Warning "The backup folder $backupDir is not there (drive unplugged?), so its rights were not restricted. Run this again once it is." }
}

# --- 6b. Updates from a private GitHub repository: a read-only deploy key --------------
# "Update now" and the Health page's update check run git as SYSTEM, which has no GitHub sign-in.
# A deploy key reads this one repository and nothing else. core.sshCommand in the app's own
# .git/config points git at it, so update.ps1 and UpdateService use it with no change of their own.
# Windows' OpenSSH, not Git's: it accepts a key only Administrators and SYSTEM can read, and
# refuses one owned by another user, so the key is owned by Administrators. GitHub's host keys
# are pinned (from https://api.github.com/meta): a first connection is never trusted blindly.
$git = (Get-Command git -ErrorAction SilentlyContinue).Source
$env:GIT_TERMINAL_PROMPT = "0"
function Invoke-Git([string[]]$Arguments) {
    $ErrorActionPreference = "Continue"
    $out = & $git -c "safe.directory=$($AppDir -replace '\\', '/')" -C $AppDir @Arguments 2>&1 | ForEach-Object { "$_" }
    return [pscustomobject]@{ Ok = ($LASTEXITCODE -eq 0); Output = ($out -join "`n").Trim() }
}
$origin = if ($git) { (Invoke-Git @("remote", "get-url", "origin")).Output } else { "" }
$ssh = "$env:SystemRoot\System32\OpenSSH\ssh.exe"
$sshKeygen = "$env:SystemRoot\System32\OpenSSH\ssh-keygen.exe"
if ($origin -notmatch '^(?:https://github\.com/|git@github\.com:|ssh://git@github\.com/)([^/]+)/([^/]+?)(?:\.git)?/?$') {
    Write-Host "==> Updates: origin is not a GitHub repository ($origin), so no update key was set up"
}
elseif (-not ((Test-Path $ssh) -and (Test-Path $sshKeygen))) {
    Write-Warning "Windows' OpenSSH Client is missing, so Update now cannot download from GitHub. Add it in Settings > System > Optional features > OpenSSH Client, then run this again."
}
else {
    $repo = "$($Matches[1])/$($Matches[2])"
    # core.sshCommand is set with no quotes in it: PowerShell 5.1 mangles a double quote inside an
    # argument to git, and 7 escapes it differently, so no quoting works in both. Hence no spaces.
    if ("$DeployKeyDir$env:SystemRoot" -match '\s') { throw "-DeployKeyDir must have no spaces in its path ($DeployKeyDir)." }
    $key = Join-Path $DeployKeyDir "id_ed25519"
    $knownHosts = Join-Path $DeployKeyDir "known_hosts"
    New-Item -ItemType Directory -Force $DeployKeyDir | Out-Null
    if (-not (Test-Path $key)) {
        # One argument string, so the empty passphrase reaches ssh-keygen under PowerShell 5.1 and 7 alike.
        $made = Start-Process -FilePath $sshKeygen -ArgumentList "-q -t ed25519 -N `"`" -C nunabooks-update@$env:COMPUTERNAME -f `"$key`"" -Wait -NoNewWindow -PassThru
        if ($made.ExitCode -ne 0) { throw "Creating the update key failed (exit code $($made.ExitCode))." }
    }
    [IO.File]::WriteAllLines($knownHosts, @(
        "github.com ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIOMqqnkVzrm0SdG6UOoqKLsabgH5C9okWi0dh2l9GKJl",
        "github.com ecdsa-sha2-nistp256 AAAAE2VjZHNhLXNoYTItbmlzdHAyNTYAAAAIbmlzdHAyNTYAAABBBEmKSENjQEezOmxkZMy7opKgwFB9nkt5YRrYMjNuG5N87uRgg6CLrbo5wAdT/y6v0mKV0U2w0WZ2YB/++Tpockg="
    ))
    Lock-Folder $DeployKeyDir
    Invoke-Checked "Making Administrators the owner of the update key" "icacls.exe" @($key, "/setowner", "*S-1-5-32-544", "/Q")

    $slash = { param($p) $p -replace '\\', '/' }
    $sshCommand = "$(& $slash $ssh) -i $(& $slash $key) -o IdentitiesOnly=yes -o BatchMode=yes -o StrictHostKeyChecking=yes -o UserKnownHostsFile=$(& $slash $knownHosts)"
    foreach ($setting in @(@("remote", "set-url", "origin", "git@github.com:$repo.git"), @("config", "core.sshCommand", $sshCommand))) {
        $done = Invoke-Git $setting
        if (-not $done.Ok) { throw "git $($setting -join ' ') failed: $($done.Output)" }
    }

    $keyNote = Join-Path ([Environment]::GetFolderPath("CommonDesktopDirectory")) "NunaBooks update key.txt"
    $check = Invoke-Git @("ls-remote", "--heads", "origin")
    if ($check.Ok) {
        Write-Host "==> Updates: this PC can download new versions from GitHub ($repo)"
        Remove-Item $keyNote -ErrorAction SilentlyContinue
    }
    else {
        $public = (Get-Content "$key.pub" -Raw).Trim()
        # The public half is not a secret; a copy on the desktop is easy to email to whoever adds it.
        [IO.File]::WriteAllLines($keyNote, @($public))
        Write-Host ""
        Write-Host "  Update now cannot download yet: GitHub does not know this PC's update key."
        Write-Host "  Add it ONCE at https://github.com/$repo/settings/keys > Add deploy key,"
        Write-Host "  title: shop PC, key: the line below (also in 'NunaBooks update key.txt' on the desktop)."
        Write-Host "  Leave 'Allow write access' UNTICKED. Then run this installer again to check."
        Write-Host ""
        Write-Host "      $public"
        Write-Host ""
        Write-Warning "GitHub said: $($check.Output)"
    }
}

[IO.File]::WriteAllText((Join-Path $AppDir "storage\app\install.json"), (@{
    php = $php; composer = $composer; mysqlBin = (Join-Path $MysqlDir "bin"); apacheService = $ApacheService; port = $Port
} | ConvertTo-Json))

# --- 7. Is it ready? -------------------------------------------------------------------

& $php artisan nuna:preflight
Write-Host ""
Write-Host "NunaBooks is installed: $appUrl/"
Write-Host "Open it and create the owner account. Then take the first backup: php artisan nuna:backup"
Write-Host "After that a backup is taken every day at 21:00 (scheduled task NunaBooksBackup)."
