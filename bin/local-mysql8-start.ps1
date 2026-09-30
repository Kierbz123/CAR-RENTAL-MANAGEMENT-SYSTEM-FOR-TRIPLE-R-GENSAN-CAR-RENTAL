$ErrorActionPreference = 'Stop'

$port = 3307
$base = Join-Path $env:LOCALAPPDATA 'TripleR-LocalMySQL8'
$install = Join-Path $base 'mysql-8.0.46-winx64'
$data = Join-Path $base 'data'
$mysqld = Join-Path $install 'bin\mysqld.exe'
$mysqladmin = Join-Path $install 'bin\mysqladmin.exe'
$mysql = Join-Path $install 'bin\mysql.exe'
$errorLog = Join-Path $base 'mysql-error.log'

$ping = & $mysqladmin --host=127.0.0.1 --port=$port --user=root ping 2>$null
if ($LASTEXITCODE -eq 0) {
    $result = & $mysql --host=127.0.0.1 --port=$port --user=root --batch --skip-column-names --execute='SELECT VERSION(), @@log_bin_trust_function_creators;'
    if ($LASTEXITCODE -ne 0 -or $result -notmatch '^8\.' -or $result -notmatch '\s1$') {
        throw "MySQL is listening on port $port, but version or log_bin_trust_function_creators verification failed: $result"
    }
    Write-Output "Already running and verified: $result"
    exit 0
}

$listener = Get-NetTCPConnection -LocalPort $port -State Listen -ErrorAction SilentlyContinue
if ($listener) {
    throw "Port $port is already listening, but the configured MySQL 8 admin ping failed. No process was started."
}

$running = Get-Process mysqld -ErrorAction SilentlyContinue | Where-Object {
    $_.Path -and [string]::Equals($_.Path, $mysqld, [StringComparison]::OrdinalIgnoreCase)
}
if ($running) {
    $paths = ($running | ForEach-Object { $_.Path }) -join ', '
    throw "A mysqld process already exists without a listener on port $port ($paths). Refusing to start a duplicate."
}

if (-not (Test-Path -LiteralPath $mysqld) -or -not (Test-Path -LiteralPath $data)) {
    throw "Portable MySQL files/data are missing under $base. This helper never downloads or initializes a data directory."
}

$arguments = @(
    '--no-defaults',
    "--basedir=$install",
    "--datadir=$data",
    "--port=$port",
    '--bind-address=127.0.0.1',
    '--mysqlx=0',
    '--log-bin-trust-function-creators=1',
    "--log-error=$errorLog"
)
Start-Process -FilePath $mysqld -ArgumentList $arguments -WindowStyle Hidden

$ready = $false
for ($i = 0; $i -lt 30; $i++) {
    Start-Sleep -Seconds 1
    & $mysqladmin --host=127.0.0.1 --port=$port --user=root ping 2>$null | Out-Null
    if ($LASTEXITCODE -eq 0) {
        $ready = $true
        break
    }
}
if (-not $ready) {
    throw "MySQL 8 did not become ready. Inspect $errorLog"
}

$result = & $mysql --host=127.0.0.1 --port=$port --user=root --batch --skip-column-names --execute='SELECT VERSION(), @@log_bin_trust_function_creators;'
if ($LASTEXITCODE -ne 0 -or $result -notmatch '^8\.' -or $result -notmatch '\s1$') {
    throw "MySQL 8 started but runtime verification failed: $result"
}
Write-Output "Started and verified: $result"
