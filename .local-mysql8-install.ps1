$ErrorActionPreference = 'Stop'
$base = Join-Path $env:LOCALAPPDATA 'TripleR-LocalMySQL8'
$install = Join-Path $base 'mysql-8.0.46-winx64'
$data = Join-Path $base 'data'
$zip = Join-Path $base 'mysql-8.0.46-winx64.zip'
$temp = Join-Path $base 'extract'
if ((Test-Path -LiteralPath $install) -or (Test-Path -LiteralPath $data)) {
    throw "Refusing to overwrite existing portable MySQL paths under $base"
}
New-Item -ItemType Directory -Path $base -Force | Out-Null
$curl = Get-Command curl.exe -ErrorAction Stop
& $curl.Source --fail --location --show-error --output $zip 'https://cdn.mysql.com/Downloads/MySQL-8.0/mysql-8.0.46-winx64.zip'
if ($LASTEXITCODE -ne 0) { throw "Official MySQL archive download failed with curl exit code $LASTEXITCODE" }
$hash = (Get-FileHash -LiteralPath $zip -Algorithm MD5).Hash.ToLowerInvariant()
if ($hash -ne '003f527d5df61b663ff191038cd676bd') {
    throw "Official MySQL archive checksum mismatch: $hash"
}
Expand-Archive -LiteralPath $zip -DestinationPath $temp
$extracted = Join-Path $temp 'mysql-8.0.46-winx64'
if (-not (Test-Path -LiteralPath (Join-Path $extracted 'bin\mysqld.exe'))) {
    throw 'The official archive did not contain the expected MySQL server binary.'
}
Move-Item -LiteralPath $extracted -Destination $install
Remove-Item -LiteralPath $temp -Recurse -Force
Remove-Item -LiteralPath $zip -Force
New-Item -ItemType Directory -Path $data | Out-Null
$mysqld = Join-Path $install 'bin\mysqld.exe'
& $mysqld --no-defaults "--basedir=$install" "--datadir=$data" --port=3307 --bind-address=127.0.0.1 --mysqlx=0 --initialize-insecure --console
if ($LASTEXITCODE -ne 0) { throw "MySQL data-directory initialization failed with exit code $LASTEXITCODE" }
$errorLog = Join-Path $base 'mysql-error.log'
$arguments = @('--no-defaults', "--basedir=$install", "--datadir=$data", '--port=3307', '--bind-address=127.0.0.1', '--mysqlx=0', '--log-bin-trust-function-creators=1', "--log-error=$errorLog")
Start-Process -FilePath $mysqld -ArgumentList $arguments -WindowStyle Hidden
$mysqladmin = Join-Path $install 'bin\mysqladmin.exe'
$ready = $false
for ($i = 0; $i -lt 30; $i++) {
    Start-Sleep -Seconds 1
    & $mysqladmin --host=127.0.0.1 --port=3307 --user=root ping 2>$null | Out-Null
    if ($LASTEXITCODE -eq 0) { $ready = $true; break }
}
if (-not $ready) { throw "MySQL 8 did not become ready; inspect $errorLog" }
$client = Join-Path $install 'bin\mysql.exe'
& $client --host=127.0.0.1 --port=3307 --user=root --execute='SELECT VERSION() AS mysql8_version, @@port AS mysql8_port, @@collation_server AS default_collation;'
if ($LASTEXITCODE -ne 0) { throw 'MySQL 8 verification query failed.' }
"MYSQL8_HOME=$install"
"MYSQL8_DATADIR=$data"
"MYSQL8_PORT=3307"
