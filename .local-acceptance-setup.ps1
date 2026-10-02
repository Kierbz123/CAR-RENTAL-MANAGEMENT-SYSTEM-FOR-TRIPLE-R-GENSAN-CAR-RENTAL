$ErrorActionPreference = 'Stop'
Set-Location -LiteralPath $PSScriptRoot

function New-RandomHex([int]$length) {
    $bytes = New-Object byte[] $length
    $rng = [Security.Cryptography.RandomNumberGenerator]::Create()
    try { $rng.GetBytes($bytes) } finally { $rng.Dispose() }
    return [BitConverter]::ToString($bytes).Replace('-', '')
}

$base = Join-Path $env:LOCALAPPDATA 'TripleR-LocalMySQL8'
$mysql = Join-Path $base 'mysql-8.0.46-winx64\bin\mysql.exe'
if (-not (Test-Path -LiteralPath $mysql)) { throw "Portable MySQL client is missing: $mysql" }
$tag = Get-Date -Format 'yyyyMMddHHmmss'
$dbName = "triple_r_acceptance_$tag"
$schemaDbName = "triple_r_schema_acceptance_$tag"
$appUser = "trr_app_$tag"
$migrateUser = "trr_migrate_$tag"
$appPass = New-RandomHex 24
$migratePass = New-RandomHex 24

$sql = @"
CREATE DATABASE $dbName CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;
CREATE DATABASE $schemaDbName CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;
CREATE USER '$appUser'@'127.0.0.1' IDENTIFIED BY '$appPass';
GRANT SELECT, INSERT, UPDATE ON $dbName.* TO '$appUser'@'127.0.0.1';
CREATE USER '$migrateUser'@'127.0.0.1' IDENTIFIED BY '$migratePass';
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, DROP, INDEX, TRIGGER, REFERENCES ON $dbName.* TO '$migrateUser'@'127.0.0.1';
FLUSH PRIVILEGES;
"@
& $mysql --host=127.0.0.1 --port=3307 --user=root "--execute=$sql"
if ($LASTEXITCODE -ne 0) { throw 'Unable to create isolated MySQL 8 acceptance schema and accounts.' }

Get-Content -Raw -LiteralPath (Join-Path $PSScriptRoot 'database\schema.sql') | & $mysql --host=127.0.0.1 --port=3307 --user=root "--database=$schemaDbName"
if ($LASTEXITCODE -ne 0) { throw 'The consolidated clean-install schema failed to import into its isolated MySQL 8 database.' }
$schemaMetaQuery = "SELECT (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='$schemaDbName'),(SELECT COUNT(*) FROM information_schema.table_constraints WHERE constraint_schema='$schemaDbName' AND constraint_type='PRIMARY KEY'),(SELECT COUNT(*) FROM information_schema.table_constraints WHERE constraint_schema='$schemaDbName' AND constraint_type='FOREIGN KEY'),(SELECT checksum FROM $schemaDbName.schema_migrations WHERE migration='020_payments.sql');"
$schemaMeta = & $mysql --host=127.0.0.1 --port=3307 --user=root --batch --skip-column-names "--execute=$schemaMetaQuery"
if ($LASTEXITCODE -ne 0) { throw 'Unable to inspect consolidated schema metadata.' }
$schemaExpectedChecksum = (Get-FileHash -Algorithm SHA256 (Join-Path $PSScriptRoot 'database\migrations\020_payments.sql')).Hash.ToLowerInvariant()
$schemaMetaFields = (($schemaMeta -join "`n").Trim() -split "`t")
if ($schemaMetaFields.Count -ne 4 -or $schemaMetaFields[0] -ne '35' -or $schemaMetaFields[1] -ne '35' -or $schemaMetaFields[2] -ne '71' -or $schemaMetaFields[3] -ne $schemaExpectedChecksum) { throw "Consolidated schema metadata mismatch: $($schemaMeta -join ' ')" }
"Canonical schema import verified: $($schemaMetaFields[0]) tables, $($schemaMetaFields[1]) primary keys, $($schemaMetaFields[2]) foreign keys, migration 020 checksum matches."

# Process-local environment overrides the existing ignored .env without changing it.
$env:DB_HOST = '127.0.0.1'
$env:DB_PORT = '3307'
$env:DB_NAME = $dbName
$env:DB_USER = $appUser
$env:DB_PASSWORD = $appPass
$env:DB_MIGRATION_USER = $migrateUser
$env:DB_MIGRATION_PASSWORD = $migratePass
$env:MAINTENANCE_DUE_SOON_DAYS = '30'
$env:MAINTENANCE_DUE_SOON_KM = '500'
if ([string]::IsNullOrWhiteSpace($env:SEED_ADMIN_EMAIL)) { $env:SEED_ADMIN_EMAIL = 'admin@example.test' }
if ([string]::IsNullOrWhiteSpace($env:SEED_ADMIN_PASSWORD)) { $env:SEED_ADMIN_PASSWORD = "Local-Run-$(New-RandomHex 16)aA1!" }

$php = 'C:\xampp\php\php.exe'
& $php 'bin\migrate.php'
if ($LASTEXITCODE -ne 0) { throw "Migration failed with exit code $LASTEXITCODE; preserve the isolated DB for diagnosis." }
# Simulate a pre-checksum installation whose schema_migrations rows already exist.
$legacyLedger = "ALTER TABLE $dbName.schema_migrations DROP COLUMN checksum;"
& $mysql --host=127.0.0.1 --port=3307 --user=root "--execute=$legacyLedger"
if ($LASTEXITCODE -ne 0) { throw 'Unable to prepare the legacy migration-ledger compatibility check.' }
& $php 'bin\migrate.php'
if ($LASTEXITCODE -ne 0) { throw "Legacy migration-ledger checksum baseline failed with exit code $LASTEXITCODE." }
& $php 'bin\migrate.php'
if ($LASTEXITCODE -ne 0) { throw "Checksum-verified migration replay failed with exit code $LASTEXITCODE." }
& $php 'bin\seed.php'
if ($LASTEXITCODE -ne 0) { throw "Seed failed with exit code $LASTEXITCODE; preserve the isolated DB for diagnosis." }

& $php 'bin\test-m5-reconciliation.php'
if ($LASTEXITCODE -ne 0) { throw "M5 reconciliation preservation checks failed with exit code $LASTEXITCODE." }

& $php 'bin\test-m4.php'
if ($LASTEXITCODE -ne 0) { throw "M4 runtime checks failed with exit code $LASTEXITCODE." }
& $php 'bin\test-m6.php'
if ($LASTEXITCODE -ne 0) { throw "M6 acceptance checks failed with exit code $LASTEXITCODE." }
& $php 'bin\test-m7.php'
if ($LASTEXITCODE -ne 0) { throw "M7 acceptance checks failed with exit code $LASTEXITCODE." }
& $php 'bin\test-m8.php'
if ($LASTEXITCODE -ne 0) { throw "M8 database checks failed with exit code $LASTEXITCODE; preserve the isolated DB for diagnosis." }
& $php 'bin\maintenance-due.php'
if ($LASTEXITCODE -ne 0) { throw "M8 due-soon CLI report failed with exit code $LASTEXITCODE." }
& $php 'bin\maintenance-due.php' '--csv'
if ($LASTEXITCODE -ne 0) { throw "M8 due-soon CSV report failed with exit code $LASTEXITCODE." }
& $php 'bin\test-m6-db-guards.php'
if ($LASTEXITCODE -ne 0) { throw "Migration 009 raw-SQL checks failed with exit code $LASTEXITCODE." }
& $php 'bin\test-downpayment.php'
if ($LASTEXITCODE -ne 0) { throw "Downpayment checks failed with exit code $LASTEXITCODE." }
& $php 'bin\test-payments.php'
if ($LASTEXITCODE -ne 0) { throw "Payment checks failed with exit code $LASTEXITCODE." }

$env:M4_HTTP_TEST_PASSWORD = "M4-Http-$(New-RandomHex 20)Aa1!"
$serverLog = Join-Path $env:TEMP "TripleR-M4-http-$tag.log"
$serverError = Join-Path $env:TEMP "TripleR-M4-http-$tag.err.log"
$sessionDirectory = Join-Path $env:TEMP "TripleR-M4-php-sessions-$tag"
New-Item -ItemType Directory -Path $sessionDirectory | Out-Null
$oldServers = @(Get-CimInstance Win32_Process -Filter "Name='php.exe'" -ErrorAction SilentlyContinue | Where-Object { $_.CommandLine -like '* -S *' -and $_.CommandLine -like "*$PSScriptRoot*" })
foreach ($oldServer in $oldServers) {
    "Stopping leftover project PHP server PID $($oldServer.ProcessId)"
    Stop-Process -Id $oldServer.ProcessId -Force
}
do {
    $port = Get-Random -Minimum 18000 -Maximum 45000
    $portProbe = [System.Net.Sockets.TcpListener]::new([System.Net.IPAddress]::Loopback, $port)
    try { $portProbe.Start(); $portAvailable = $true }
    catch { $portAvailable = $false }
    finally { $portProbe.Stop() }
} while (-not $portAvailable)
$env:M4_HTTP_BASE_URL = "http://127.0.0.1:$port"
$serverInfo = New-Object System.Diagnostics.ProcessStartInfo
$serverInfo.FileName = $php
$serverInfo.Arguments = "-d `"session.save_path=$sessionDirectory`" -S 127.0.0.1:$port -t public public/index.php"
$serverInfo.WorkingDirectory = $PSScriptRoot
$serverInfo.UseShellExecute = $false
$serverInfo.CreateNoWindow = $true
$serverInfo.RedirectStandardOutput = $true
$serverInfo.RedirectStandardError = $true
$server = New-Object System.Diagnostics.Process
$server.StartInfo = $serverInfo
if (-not $server.Start()) { throw 'Unable to launch the local PHP acceptance server.' }
$serverOutputTask = $server.StandardOutput.ReadToEndAsync()
$serverErrorTask = $server.StandardError.ReadToEndAsync()
$httpExit = 1
try {
    $ready = $false
    for ($attempt = 0; $attempt -lt 30; $attempt++) {
        & curl.exe --silent --output NUL "http://127.0.0.1:$port/staff/login"
        if ($LASTEXITCODE -eq 0) { $ready = $true; break }
        Start-Sleep -Seconds 1
    }
    if (-not $ready) { throw "The local PHP server did not become ready; inspect $serverError" }
    & $php 'bin\test-m4-http.php'
    $httpExit = $LASTEXITCODE
    if ($httpExit -ne 0) { throw "M4 HTTP checks failed with exit code $httpExit; inspect $serverError" }
    & $php 'bin\test-m7-http.php'
    $httpExit = $LASTEXITCODE
    if ($httpExit -ne 0) { throw "M7 HTTP checks failed with exit code $httpExit; inspect $serverError" }
    & $php 'bin\test-m8-http.php'
    $httpExit = $LASTEXITCODE
    if ($httpExit -ne 0) { throw "M8 HTTP checks failed with exit code $httpExit; inspect $serverError" }
} finally {
    if (-not $server.HasExited) { $server.Kill(); $server.WaitForExit() }
    [System.IO.File]::WriteAllText($serverLog, $serverOutputTask.Result)
    [System.IO.File]::WriteAllText($serverError, $serverErrorTask.Result)
    Remove-Item Env:M4_HTTP_TEST_PASSWORD, Env:M4_HTTP_BASE_URL -ErrorAction SilentlyContinue
    if ($httpExit -eq 0) {
        Remove-Item -LiteralPath $serverLog, $serverError -ErrorAction SilentlyContinue
        $tempRoot = [System.IO.Path]::GetFullPath($env:TEMP).TrimEnd('\') + '\'
        $sessionFullPath = [System.IO.Path]::GetFullPath($sessionDirectory)
        if ($sessionFullPath.StartsWith($tempRoot, [System.StringComparison]::OrdinalIgnoreCase)) {
            Remove-Item -LiteralPath $sessionFullPath -Recurse -Force
        }
    }
}

"Acceptance DB: $dbName"
"MySQL 8 port: 3307"
"M4, M6, M7, M8 database/HTTP and migration 009 raw-SQL checks completed. Credentials were not written to .env or printed."
Remove-Item Env:DB_MIGRATION_USER, Env:DB_MIGRATION_PASSWORD, Env:MAINTENANCE_DUE_SOON_DAYS, Env:MAINTENANCE_DUE_SOON_KM -ErrorAction SilentlyContinue
