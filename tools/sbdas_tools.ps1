# SBDAS helper - run on YOUR PC (Windows PowerShell). The database password is typed here (hidden) and never saved or printed.
# Menu: 1) import the SBDAS database  2) set a password for one user (e.g. admin)  3) remove SWIFT/RTGS from an existing database
$ErrorActionPreference = 'Stop'
$here = $PSScriptRoot
function Read-Secret($label) {
    $s = Read-Host $label -AsSecureString
    $b = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($s)
    try { return [Runtime.InteropServices.Marshal]::PtrToStringBSTR($b) } finally { [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($b) }
}
function Stop-Here($msg) { Write-Host $msg -ForegroundColor Red; Read-Host 'Press Enter to close'; exit 1 }

$proj = Split-Path -Parent $here
$sql = Join-Path $proj 'database\database.sql'
$mig = Join-Path $proj 'database\migrate_remove_swift_rtgs.sql'
foreach ($p in @($sql, $mig, "$here\import_database.php", "$here\reset_password.php", "$here\migrate_remove_swift_rtgs.php")) { if (-not (Test-Path $p)) { Stop-Here "Missing file: $p" } }

$php = $null
$cmd = Get-Command php -ErrorAction SilentlyContinue
if ($cmd) { $php = $cmd.Source } elseif (Test-Path 'C:\xampp\php\php.exe') { $php = 'C:\xampp\php\php.exe' }
if (-not $php) { $php = (Read-Host 'PHP not found. Full path of php.exe').Trim('"').Trim() }
if (-not (Test-Path $php)) { Stop-Here "php.exe not found: $php" }
& $php -r "exit((extension_loaded('mysqli') && extension_loaded('openssl')) ? 0 : 1);"
if ($LASTEXITCODE -ne 0) { Stop-Here "This PHP lacks mysqli/openssl. Use XAMPP's PHP." }

Write-Host "`nDatabase connection (Aiven service page > Connection information)" -ForegroundColor Cyan
$dbHost = (Read-Host 'Host').Trim()
$dbPort = (Read-Host 'Port').Trim()
$dbUser = (Read-Host 'User [avnadmin]').Trim(); if (-not $dbUser) { $dbUser = 'avnadmin' }
$dbName = (Read-Host 'Database name for SBDAS [sbdas]  (a NEW database; do not use the SNTMP one)').Trim(); if (-not $dbName) { $dbName = 'sbdas' }
$caPath = (Read-Host 'Full path of the Aiven CA certificate (ca.pem)').Trim('"').Trim()
if (-not (Test-Path $caPath)) { Stop-Here "CA file not found: $caPath" }
$env:DB_HOST = $dbHost; $env:DB_PORT = $dbPort; $env:DB_USER = $dbUser; $env:DB_NAME = $dbName
$env:DB_SSL = '1'; $env:DB_SSL_CA = $caPath
$env:DB_PASS = Read-Secret "Database password for $dbUser (hidden)"

try {
    Write-Host "`nWhat do you want to do?"
    Write-Host "  1) Import the SBDAS database (first time only; creates the database if missing, refuses if it already has data)"
    Write-Host "  2) Set a password for one SBDAS user (e.g. admin)"
    Write-Host "  3) Remove SWIFT/RTGS from the existing database (one time; old rows become BBAN)"
    $c = (Read-Host 'Choose 1-3').Trim()
    switch ($c) {
        '1' { & $php "$here\import_database.php" $sql | Out-Host }
        '2' {
            $env:RESET_USERNAME = (Read-Host 'Username (e.g. admin)').Trim()
            $env:RESET_PASSWORD = Read-Secret 'NEW password (min 8 chars, hidden)'
            & $php "$here\reset_password.php" | Out-Host
        }
        '3' { & $php "$here\migrate_remove_swift_rtgs.php" $mig | Out-Host }
        default { Write-Host 'Nothing done.' }
    }
} finally {
    foreach ($v in 'DB_PASS','DB_SSL_CA','RESET_USERNAME','RESET_PASSWORD') { Remove-Item "Env:$v" -ErrorAction SilentlyContinue }
    Read-Host "`nDone. Passwords were cleared from this session. Press Enter to close"
}
