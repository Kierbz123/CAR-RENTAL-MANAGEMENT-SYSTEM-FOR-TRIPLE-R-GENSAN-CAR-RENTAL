# Portable local MySQL 8

This runbook is for the optional Windows development instance on `127.0.0.1:3307`. It is a portable `mysqld.exe` process, not a Windows service. Its binaries and data live outside the repository under `%LOCALAPPDATA%\TripleR-LocalMySQL8`; it does not start automatically after a restart. MariaDB/XAMPP on port 3306 is a separate instance.

## Start and verify

From the repository root, open PowerShell and run:

```powershell
.\bin\local-mysql8-start.ps1
```

The helper is idempotent. It returns without starting a second server when MySQL 8 is already healthy. Otherwise it starts only the existing data directory; it never downloads binaries or initializes data. Startup uses `--no-defaults`, binds to loopback on port 3307, disables MySQL X, and persists `--log-bin-trust-function-creators=1` as a process argument.

The helper verifies an admin ping, MySQL 8 version, and `@@log_bin_trust_function_creators = 1`. If it refuses to start, inspect `%LOCALAPPDATA%\TripleR-LocalMySQL8\mysql-error.log`. It will not overwrite or reinitialize an existing data directory. For a missing or damaged installation, recover or reinstall it deliberately; do not point the installer at an existing data directory.

To stop only this portable server cleanly:

```powershell
$mysqladmin = Join-Path $env:LOCALAPPDATA 'TripleR-LocalMySQL8\mysql-8.0.46-winx64\bin\mysqladmin.exe'
& $mysqladmin --host=127.0.0.1 --port=3307 --user=root shutdown
```

The local root account is initialized without a password for this loopback-only development instance. Do not expose port 3307 beyond the local machine or reuse this account in production.

## Backup and restore

Use logical dumps; do not copy the live InnoDB data directory while `mysqld` is running. `--single-transaction` gives a consistent snapshot for InnoDB tables. Store backups outside the repository and protect them as application data.

```powershell
$base = Join-Path $env:LOCALAPPDATA 'TripleR-LocalMySQL8\mysql-8.0.46-winx64\bin'
$dump = Join-Path $env:USERPROFILE ("Backups\triple_r_rental-{0}.sql" -f (Get-Date -Format 'yyyyMMdd-HHmmss'))
New-Item -ItemType Directory -Path (Split-Path -Parent $dump) -Force | Out-Null
& (Join-Path $base 'mysqldump.exe') --host=127.0.0.1 --port=3307 --user=root --single-transaction --routines --triggers --events --databases triple_r_rental "--result-file=$dump"
if ($LASTEXITCODE -ne 0) { throw 'MySQL backup failed.' }
```

Restore into a disposable or intentionally selected database only. A dump made with `--databases` contains `CREATE DATABASE` and `USE` statements. In PowerShell, pass the file to the mysql client through `cmd.exe` redirection:

```powershell
$mysql = Join-Path $base 'mysql.exe'
$restoreCommand = '""{0}" --host=127.0.0.1 --port=3307 --user=root < "{1}""' -f $mysql, $dump
& $env:ComSpec /d /c $restoreCommand
if ($LASTEXITCODE -ne 0) { throw 'MySQL restore failed.' }
```

After restore, run the application's migration command and verify the schema and a representative application workflow before using the restored database. A filesystem backup of `%LOCALAPPDATA%\TripleR-LocalMySQL8\data` is useful only after a clean server shutdown and is not a substitute for tested logical backups.
