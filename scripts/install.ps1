<#
.SYNOPSIS
    Install NunaBooks on the shop PC: MySQL and Apache (mod_fcgid, PHP NTS)
    as Windows services that start at boot, the database and its users,
    .env, Composer packages, migrations, the firewall rule, the hosts entry
    and a desktop shortcut. PLAN.md Q1 and 6.1.

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
    "extension=intl", "extension=mbstring", "extension=pdo_mysql", "extension=fileinfo", "extension=openssl",
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
GRANT ALL PRIVILEGES ON ``$Database``.* TO 'nunabooks'@'localhost';
GRANT ALL PRIVILEGES ON ``${Database}\_scratch\_%``.* TO 'nunabooks'@'localhost';
CREATE USER IF NOT EXISTS 'nunabooks_backup'@'localhost' IDENTIFIED BY '$backupPassword';
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
    Set-EnvValue $envFile "APP_URL" "http://$HostName"
    Set-EnvValue $envFile "DB_DATABASE" $Database
    Set-EnvValue $envFile "DB_USERNAME" "nunabooks"
    Set-EnvValue $envFile "DB_PASSWORD" $appPassword
    Set-EnvValue $envFile "NUNA_BACKUP_DB_USERNAME" "nunabooks_backup"
    Set-EnvValue $envFile "NUNA_BACKUP_DB_PASSWORD" $backupPassword
    Set-EnvValue $envFile "NUNA_MYSQL_BIN" (Join-Path $MysqlDir "bin")
    Set-EnvValue $envFile "NUNA_APACHE_SERVICE" $ApacheService
    Set-EnvValue $envFile "SEED_DEMO_DATA" "false"
}

# --- 4. The app: packages, key, backup, migrations, caches ----------------------------

Set-Location $AppDir
$composer = (Get-Command composer -ErrorAction SilentlyContinue).Source
if (-not $composer) { $composer = "C:\laragon\bin\composer\composer.bat" }
Invoke-Checked "Installing PHP packages" $composer @("install", "--no-dev", "--optimize-autoloader", "--no-interaction")
if ((Get-Content $envFile -Raw) -match "(?m)^APP_KEY=\s*$") {
    Invoke-Checked "Generating the app key" $php @("artisan", "key:generate", "--force")
}

# migrate:status fails while there is no migration table: a new database has nothing to back up.
& $php artisan migrate:status 2>$null | Out-Null
if ($LASTEXITCODE -eq 0) {
    Invoke-Checked "Backing up the existing books before migrating" $php @("artisan", "nuna:backup")
}
Invoke-Checked "Migrating the database" $php @("artisan", "migrate", "--force")
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
$url = if ($Port -eq 80) { "http://$HostName/" } else { "http://${HostName}:$Port/" }
[IO.File]::WriteAllLines((Join-Path ([Environment]::GetFolderPath("CommonDesktopDirectory")) "NunaBooks.url"), @("[InternetShortcut]", "URL=$url"))

# The Health screen's "Update now" runs this task: a child of Apache would die when the update stops it.
$update = Join-Path $AppDir "scripts\update.ps1"
$action = New-ScheduledTaskAction -Execute "powershell.exe" -Argument "-NoProfile -ExecutionPolicy Bypass -File `"$update`"" -WorkingDirectory $AppDir
Register-ScheduledTask -TaskName "NunaBooksUpdate" -Action $action -User "SYSTEM" -RunLevel Highest -Force | Out-Null

[IO.File]::WriteAllText((Join-Path $AppDir "storage\app\install.json"), (@{
    php = $php; composer = $composer; mysqlBin = (Join-Path $MysqlDir "bin"); apacheService = $ApacheService; port = $Port
} | ConvertTo-Json))

# --- 7. Is it ready? -------------------------------------------------------------------

& $php artisan nuna:preflight
Write-Host ""
Write-Host "NunaBooks is installed: $url"
Write-Host "Open it and create the owner account. Then take the first backup: php artisan nuna:backup"
