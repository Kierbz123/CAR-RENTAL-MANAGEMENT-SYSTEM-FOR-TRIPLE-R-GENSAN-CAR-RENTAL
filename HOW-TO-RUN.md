# How to run Triple R Gensan on this computer

Everything here is typed in **PowerShell**. This computer is already set up (database, `.env`, demo accounts), so running the system is three steps. Setting it up from nothing on another computer is in [README.md](README.md), "First run".

## 1. Go to the app folder

Every command below must be run from this folder. A new PowerShell window starts somewhere else, which is why `.\bin\...` "is not recognized".

```powershell
cd "C:\xampp\htdocs\SCHOOL SYSTEMS\TripleR-Gensan-Car-Rental\TripleR-Gensan-Car-Rental"
```

## 2. Start the database

The system uses its own MySQL 8 on port 3307, not XAMPP's MySQL. It stops when the computer restarts, so start it each time. Running this when it is already started does no harm.

```powershell
.\bin\local-mysql8-start.ps1
```

It should print `Started and verified` or `Already running and verified`.

## 3. Start the site

Pick one.

### A. On this computer only

```powershell
C:\xampp\php\php.exe -S 127.0.0.1:8000 -t public public/router.php
```

Open <http://127.0.0.1:8000>. Leave the window open; press `Ctrl+C` to stop.

- Use `127.0.0.1`, not `localhost`. The secure customer links only accept the first.
- Keep `public/router.php` at the end. Without it the pages load with no styling.

### B. Online, for phones and the defense

```powershell
.\bin\demo-online.ps1
```

It starts the database, opens a public tunnel and prints an address like `https://something.trycloudflare.com`. Use that address on any phone or computer. Leave the window open; `Ctrl+C` takes the site offline. The address is different every time.

This is the one to use for anything a phone must do: scanning the booking QR code, and the vehicle tracker (phones only share their location over `https`).

## Where things are

| What | Address |
|---|---|
| Public site | `/` |
| Customer books a vehicle | `/book` |
| Customer finds their booking | `/book/find` |
| Staff sign in | `/staff/login` |
| Staff workspace | `/staff` |
| Driver: my trips | `/driver` (a driver account lands here after signing in at `/staff/login`) |
| Payments (front desk) | `/payments` |
| Live map of rented vehicles | `/fleet/locations` |
| Vehicle tracker for a phone | `/track` (opened from the QR code staff make on an agreement) |

Staff sign-ins for every role are in `DEMO-ACCOUNTS.local.txt` in this folder.

## Stopping

- The site: `Ctrl+C` in its window.
- The database can be left running. It stops when the computer shuts down.

## When something goes wrong

| What you see | What to do |
|---|---|
| `.\bin\demo-online.ps1 is not recognized` | You are in the wrong folder. Do step 1 first. |
| `running scripts is disabled on this system` | Run it as `powershell -ExecutionPolicy Bypass -File .\bin\demo-online.ps1` |
| `cloudflared is not installed` | Run `winget install --id Cloudflare.cloudflared` once, open a new PowerShell window, start again from step 1. |
| "Something went wrong on our side" on every page | The database is not running. Do step 2. |
| Pages show plain text with no colours | The site was started without `public/router.php`. Stop it and use the command in 3A. |
| `Failed to listen on 127.0.0.1:8000`, or `Port 8088 is already in use` from the demo script | The site is already running in another window. Use that one, or close it first. |
| `php is not recognized` | Use the full path as in 3A: `C:\xampp\php\php.exe` |
| The tracker says location sharing needs a secure address | It was opened on `http://127.0.0.1`. Start with option B and make the tracker code again from the public address. |
| A reservation was not cancelled after 24 hours | The job that does it runs by itself only under option B. Under option A run `C:\xampp\php\php.exe bin\rentals-expire.php` |

## Only when you change something

| You changed | Run |
|---|---|
| `public/assets/css/app.source.css` | `npm run build:css` |
| Added a file in `database/migrations/` | See "First run", step 3, in the README. It needs the migration account. |
