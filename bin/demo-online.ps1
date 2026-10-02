<#
.SYNOPSIS
    Puts this computer's copy of the system online for a presentation.

.DESCRIPTION
    1. Starts the local MySQL 8.
    2. Opens a Cloudflare quick tunnel and reads the public https://....trycloudflare.com
       address it is given.
    3. Starts the PHP server on a dedicated port, for this run only, with:
         TRUSTED_PROXIES  so each visitor is seen by their own address and the session
                          cookie is marked Secure;
         APP_BASE_URL     set to the public address, so SMS booking links and the
                          customer's secure-link page work through the tunnel.
       Your .env file is not changed.
    4. Once a minute, runs the two jobs a real server would have scheduled: cancelling
       reservations whose hold has run out (which frees their vehicles) and queueing
       pickup and return reminders.
    5. If a Telegram bot is configured in .env (TELEGRAM_BOT_TOKEN and TELEGRAM_BOT_USERNAME),
       also starts the two background workers that make Telegram work: one that listens for
       customers pressing Start, and one that sends queued messages every few seconds.

    The site is online only while this window stays open. Press Ctrl+C to take it
    offline; the tunnel, the PHP server and the workers started here are all stopped.

    Needs cloudflared. Install it once with:
        winget install --id Cloudflare.cloudflared
    then open a new PowerShell window.

.EXAMPLE
    .\bin\demo-online.ps1
#>
param(
    [int]$Port = 8088
)

$ErrorActionPreference = 'Stop'
$root = Split-Path -Parent $PSScriptRoot

$cloudflaredCommand = Get-Command cloudflared -ErrorAction SilentlyContinue
$cloudflared = if ($cloudflaredCommand) { $cloudflaredCommand.Source } else { Join-Path ${env:ProgramFiles(x86)} 'cloudflared\cloudflared.exe' }
if (-not (Test-Path -LiteralPath $cloudflared)) {
    throw "cloudflared is not installed. Run 'winget install --id Cloudflare.cloudflared', open a new PowerShell window, and run this script again."
}

$phpCommand = Get-Command php -ErrorAction SilentlyContinue
$php = if ($phpCommand) { $phpCommand.Source } else { 'C:\xampp\php\php.exe' }
if (-not (Test-Path -LiteralPath $php)) {
    throw "PHP was not found on PATH or at C:\xampp\php\php.exe."
}

if (Get-NetTCPConnection -LocalPort $Port -State Listen -ErrorAction SilentlyContinue) {
    throw "Port $Port is already in use. Close the program using it, or choose another port: .\bin\demo-online.ps1 -Port 8089"
}

Write-Host 'Starting the local database...'
& (Join-Path $PSScriptRoot 'local-mysql8-start.ps1')

$logDirectory = Join-Path $root 'storage'
if (-not (Test-Path -LiteralPath $logDirectory)) { New-Item -ItemType Directory -Path $logDirectory | Out-Null }
$serverLog = Join-Path $logDirectory 'demo-server.log'
$tunnelLog = Join-Path $logDirectory 'demo-tunnel.log'
$tunnelOut = Join-Path $logDirectory 'demo-tunnel.out.log'
Remove-Item -LiteralPath $tunnelLog, $tunnelOut -ErrorAction SilentlyContinue

# Telegram is switched on by .env alone; the token is only checked for presence here, never shown.
$telegramReady = $false
$envFile = Join-Path $root '.env'
if (Test-Path -LiteralPath $envFile) {
    $envLines = Get-Content -LiteralPath $envFile
    $hasToken = [bool]($envLines | Where-Object { $_ -match '^\s*TELEGRAM_BOT_TOKEN\s*=\s*\S' })
    $hasName = [bool]($envLines | Where-Object { $_ -match '^\s*TELEGRAM_BOT_USERNAME\s*=\s*\S' })
    $telegramReady = $hasToken -and $hasName
}

$tunnel = $null
$server = $null
$listener = $null
$sender = $null
try {
    Write-Host 'Opening the tunnel...'
    $tunnel = Start-Process -FilePath $cloudflared `
        -ArgumentList @('tunnel', '--no-autoupdate', '--url', "http://127.0.0.1:$Port") `
        -WindowStyle Hidden -PassThru `
        -RedirectStandardError $tunnelLog -RedirectStandardOutput $tunnelOut

    $publicUrl = $null
    for ($i = 0; $i -lt 60 -and -not $publicUrl; $i++) {
        Start-Sleep -Milliseconds 500
        if ($tunnel.HasExited) { throw "The tunnel stopped while starting. See $tunnelLog" }
        if (Test-Path -LiteralPath $tunnelLog) {
            $match = Select-String -LiteralPath $tunnelLog -Pattern 'https://[a-z0-9-]+\.trycloudflare\.com' | Select-Object -First 1
            if ($match) { $publicUrl = $match.Matches[0].Value }
        }
    }
    if (-not $publicUrl) {
        throw "The tunnel did not report a public address within 30 seconds. Check your internet connection, then see $tunnelLog"
    }

    Write-Host "Starting the web server on 127.0.0.1:$Port..."
    # These apply to the PHP server started below only; .env is left as it is.
    # The tunnel connects from this machine, so forwarded headers are trusted only from loopback.
    $env:TRUSTED_PROXIES = '127.0.0.1,::1'
    $env:APP_BASE_URL = $publicUrl
    try {
        $server = Start-Process -FilePath $php `
            -ArgumentList @('-S', "127.0.0.1:$Port", '-t', 'public', 'public/router.php') `
            -WorkingDirectory $root -WindowStyle Hidden -PassThru `
            -RedirectStandardError $serverLog
    }
    finally {
        Remove-Item Env:TRUSTED_PROXIES, Env:APP_BASE_URL -ErrorAction SilentlyContinue
    }

    $ready = $false
    for ($i = 0; $i -lt 20; $i++) {
        Start-Sleep -Milliseconds 500
        try {
            $response = Invoke-WebRequest -Uri "http://127.0.0.1:$Port/staff/login" -UseBasicParsing -TimeoutSec 3
            if ($response.StatusCode -eq 200) { $ready = $true; break }
        } catch { }
    }
    if (-not $ready) {
        throw "The web server did not answer on port $Port. See $serverLog"
    }

    Write-Host ''
    Write-Host '  The system is online at:' -ForegroundColor Green
    Write-Host "      $publicUrl" -ForegroundColor Green
    Write-Host ''
    Write-Host "  Staff sign-in:   $publicUrl/staff/login"
    Write-Host "  On this laptop:  http://127.0.0.1:$Port  (works without internet)"
    Write-Host ''
    Write-Host '  Keep this window open. Press Ctrl+C to take the site offline.'
    Write-Host '  A new address is issued each time this script starts.'
    Write-Host ''

    if ($telegramReady) {
        $listenerLog = Join-Path $logDirectory 'demo-telegram-listener.log'
        $senderLog = Join-Path $logDirectory 'demo-telegram-sender.log'
        $listener = Start-Process -FilePath $php -ArgumentList @('bin/telegram-updates.php') `
            -WorkingDirectory $root -WindowStyle Hidden -PassThru `
            -RedirectStandardOutput $listenerLog -RedirectStandardError "$listenerLog.err"
        $sender = Start-Process -FilePath $php -ArgumentList @('bin/notifications-worker.php', '--watch=3') `
            -WorkingDirectory $root -WindowStyle Hidden -PassThru `
            -RedirectStandardOutput $senderLog -RedirectStandardError "$senderLog.err"
        Start-Sleep -Seconds 3
        if ($listener.HasExited) {
            Write-Warning "Telegram is OFF: the listener could not start. Reason:"
            Get-Content -LiteralPath "$listenerLog.err" -ErrorAction SilentlyContinue | Select-Object -First 3 | ForEach-Object { Write-Warning "  $_" }
        } else {
            Write-Host '  Telegram is ON: listening for customers pressing Start, and sending queued messages.' -ForegroundColor Green
            Write-Host ''
        }
    } else {
        Write-Host '  Telegram is off (no bot in .env). See docs/TELEGRAM_NOTIFICATIONS_PLAN.md to turn it on.'
        Write-Host ''
    }

    Write-Host '  Every minute: reservations whose hold has run out are cancelled, and reminders are queued.'
    Write-Host ''

    $telegramWarned = $false
    $jobsWarned = $false
    $jobsLog = Join-Path $logDirectory 'demo-jobs.log'
    $lastJobs = [datetime]::MinValue
    while (-not $tunnel.HasExited -and -not $server.HasExited) {
        Start-Sleep -Seconds 2
        if (((Get-Date) - $lastJobs).TotalSeconds -ge 60) {
            $lastJobs = Get-Date
            foreach ($job in @('bin/rentals-expire.php', 'bin/rentals-reminders.php')) {
                $run = Start-Process -FilePath $php -ArgumentList @($job) -WorkingDirectory $root -WindowStyle Hidden -Wait -PassThru `
                    -RedirectStandardOutput $jobsLog -RedirectStandardError "$jobsLog.err"
                if ($run.ExitCode -ne 0 -and -not $jobsWarned) {
                    Write-Warning "$job did not finish cleanly. The site is still online; see $jobsLog.err"
                    $jobsWarned = $true
                }
            }
        }
        if ($telegramReady -and -not $telegramWarned -and (($listener -and $listener.HasExited) -or ($sender -and $sender.HasExited))) {
            Write-Warning "A Telegram worker stopped. The site is still online; see the demo-telegram-*.log files in $logDirectory"
            $telegramWarned = $true
        }
    }
    if ($tunnel.HasExited) { Write-Warning "The tunnel stopped. See $tunnelLog" }
    if ($server.HasExited) { Write-Warning "The web server stopped. See $serverLog" }
}
finally {
    foreach ($process in @($tunnel, $server, $listener, $sender)) {
        if ($process -and -not $process.HasExited) {
            Stop-Process -Id $process.Id -Force -ErrorAction SilentlyContinue
        }
    }
    Write-Host 'Tunnel closed, web server and workers stopped. The site is offline.'
}
