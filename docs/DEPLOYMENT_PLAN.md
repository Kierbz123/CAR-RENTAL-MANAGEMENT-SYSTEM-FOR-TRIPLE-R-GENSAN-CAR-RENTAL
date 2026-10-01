# Plan: Putting Triple R Gensan Car Rental Online

**Written:** 2026-10-01
**Goal:** make the system reachable on the public internet at an `https://` address, with the landing page open to everyone and the staff workspace behind sign-in.
**Status (2026-10-01):** the owner chose **option B, a tunnel from their own computer, for the presentation defense**. The code change and the start script for that option are done, the tunnel client is installed on the owner's computer, and a full run through a live tunnel was tested on 2026-10-01; see section 6. What remains is the owner's rehearsal. Option A (a permanent server) is kept below for later and has not been started.

Prices and product details for outside services are approximate and from general knowledge, not checked today. Confirm them on each provider's site before paying.

---

## 1. What the system needs from a host

These come from the code and the README, and they rule out most free hosting.

| Need | Why | Consequence |
|---|---|---|
| PHP 8.2 or newer with `pdo_mysql`, `curl`, `mbstring`, `openssl` | README requirements | Most hosts qualify. |
| **MySQL 8.0, not MariaDB** | The schema uses the `utf8mb4_0900_ai_ci` collation, `CHECK` constraints, generated columns and `SKIP LOCKED` | Rules out hosts that only offer MariaDB, which includes many low-cost shared plans. |
| Two database accounts with different privileges | The app account has no `DELETE`, `CREATE`, `ALTER` or `TRIGGER`; migrations run under a separate account | The host must let you create users and choose their privileges. |
| Command-line access | `php bin/migrate.php` and `php bin/seed.php` are run from a shell | Rules out hosts with no SSH or terminal. |
| A scheduler that runs every minute | Four background jobs run each minute and one every five (section 5.5) | Rules out hosts with no cron, or cron limited to every 15 minutes or more. |
| Private, persistent file storage | Vehicle, damage and maintenance photos are saved under `storage/`, outside the web root | Rules out hosts whose disk is wiped on each deploy unless a volume is attached. |
| HTTPS | Session cookies are marked Secure only over HTTPS; SMS links must be `https://` | A certificate is required. |
| Document root set to `public/` | Everything outside `public/` must not be reachable from the web | The host must let you choose the document root. |

---

## 2. Hosting options

| Option | Fits the needs? | Cost | Verdict |
|---|---|---|---|
| **A. Small Linux VPS** (1 GB RAM) with Apache, PHP and MySQL 8 | Yes, with no code changes. It is the same Apache setup the README already documents. | About US$4 to $6 a month, plus a domain at about US$10 to $15 a year | **Recommended for a site that stays online.** |
| **B. Tunnel from your own computer** (Cloudflare Tunnel or ngrok in front of the local server) | Yes for a short demo. Needs one small code change (section 6). | Free | **Recommended for a class presentation or defense.** The site is online only while your computer is on. |
| C. Container platform (Railway, Render and similar) | Possible, but needs a Dockerfile, an attached volume, separate scheduled-job services and the same code change as option B. | About US$5 a month and up | More moving parts than a VPS for no gain here. |
| D. Free shared hosting | No. Typically MariaDB or old MySQL, no shell, no per-minute cron. | Free | Will not run this system. |
| E. Paid shared hosting (cPanel) | Only if the plan offers MySQL 8, SSH and one-minute cron. Many do not. | Varies | Check all three before buying; otherwise choose A. |

**Recommendation:** use option B now if you need the system online for a presentation, and option A when it has to stay up. The rest of this plan covers A in full and B in section 6.

---

## 3. Decisions to make first

1. **Who is the site for?** A class demo, or real use by the business. This decides items 2 to 5.
2. **Domain name.** For example `triplergensan.com`. A VPS works with a bare IP address for testing, but HTTPS needs a domain.
3. **Demo notice.** `config/site.php` has `'is_demo' => true`, which shows the "demonstration site" notice and tells search engines not to index the page. Leave it on unless the business has approved the site.
4. **SMS.** With no provider key set, nothing is sent, and customers cannot receive their booking links. Decide whether to register with Semaphore or PhilSMS or to run without SMS.
5. **Permission and content.** The landing page shows the real business's phone number and address beside placeholder vehicle classes, rates and photos taken from other websites. Before a public launch, the business should agree to the site, and the fleet list, rates and photos should be their own.
6. **Starting data.** Go online with an empty, freshly migrated database. Do not upload the local one: it is full of test records, including 16 driver rows whose licence numbers cannot be read.

---

## 4. Before deploying: changes to make in the project

| # | Change | Why | Size |
|---|---|---|---|
| 1 | Merge the `ui-redesign` branch into `main` | The server should deploy from `main` | Small |
| 2 | Add compression and long-lived caching for `/assets` in `public/.htaccess` | `three.min.js` is about 1.2 MB uncompressed; gzip cuts it to roughly a quarter | Small |
| 3 | Add a version number to stylesheet and script links | Once assets are cached, visitors must still get new versions after an update | Small |
| 4 | Send a `Strict-Transport-Security` header when the request is HTTPS | Tells browsers to always use HTTPS for the site | Small |
| 5 | Add `public/robots.txt` that disallows `/staff`, `/admin`, `/api` and the other workspace paths | Keeps the workspace out of search results | Small |
| 6 | Resize `chauffeur.jpg` (451 KB) and `fleet-suv.webp` (164 KB) | Faster first visit on mobile data | Small |
| 7 | Optional: self-host the Italiana and Outfit fonts | Planned in the design work, still waiting on a go-ahead to download them | Small |

None of these block a first deployment except item 1.

---

## 5. Option A step by step: a VPS

### 5.1 Create the server

1. Create an account with a VPS provider (DigitalOcean, Vultr, Linode, Hetzner or similar) and create the smallest Ubuntu LTS server, in a Singapore region for the shortest distance to the Philippines.
2. Sign in over SSH with a key, create a non-root user with `sudo`, and turn off password sign-in for SSH.
3. Turn on the firewall and allow only ports 22, 80 and 443.
4. Turn on automatic security updates.

### 5.2 Install the software

1. Apache with `mod_rewrite`, `mod_ssl`, `mod_headers`, `mod_deflate` and `mod_expires`.
2. PHP 8.2 or newer with `php-mysql`, `php-curl`, `php-mbstring` (current Ubuntu LTS ships a suitable version).
3. MySQL 8.0 server. Run `mysql_secure_installation` and keep it listening on `127.0.0.1` only.
4. In `php.ini`, set `upload_max_filesize` and `post_max_size` to at least `10M` (photos may be up to 8 MB), `display_errors = Off` and `log_errors = On`.

### 5.3 Put the application on the server

1. Clone the GitHub repository into `/var/www/triple-r`.
2. Create an Apache virtual host with `DocumentRoot /var/www/triple-r/public` and `AllowOverride All` for that directory. The included `public/.htaccess` sends every non-file request to `index.php`.
3. Make `storage/` writable by the web server user and nothing else.
4. Create the database and the two accounts exactly as in README "First run", step 1, with long random passwords.
5. Copy `.env.example` to `.env`, readable only by the web server user, and set:
   - `DB_*` to the runtime account.
   - `APP_BASE_URL` to the `https://` address.
   - `SEED_ADMIN_EMAIL` and `SEED_ADMIN_PASSWORD` to your own values.
   - Fresh values for `SMS_CIPHER_KEY`, `CUSTOMER_PII_KEY`, `DRIVER_PII_KEY` and `SMS_WEBHOOK_SECRET`, each generated with the command shown in `.env.example`.
6. Run the migrations with the migration account, then run `php bin/seed.php`, as in README step 3.
7. Sign in as the seeded administrator and create the real staff accounts.

**Keep a copy of the three keys somewhere safe and separate from the server.** If they are lost, every stored customer and driver detail becomes unreadable. That is the same failure the local driver records show today.

### 5.4 Domain and HTTPS

1. Buy the domain and point an `A` record at the server's IP address.
2. Install Certbot and run it for Apache. It obtains a free Let's Encrypt certificate, sets up automatic renewal and redirects HTTP to HTTPS.
3. Confirm the session cookie is marked Secure (browser developer tools, Application, Cookies).

### 5.5 Background jobs

Add these to the crontab of the web server user. The schedules are the ones in the README.

| Job | Schedule | What it does |
|---|---|---|
| `bin/notifications-worker.php` | every minute | Sends queued SMS |
| `bin/rentals-expire.php` | every minute | Cancels reservations whose hold has run out |
| `bin/rentals-reminders.php` | every minute | Queues rental reminders |
| `bin/consume-stop-events.php` | every minute | Applies customer STOP replies |
| `bin/sessions-sweep.php` | every five minutes | Clears expired staff sessions |

Each line follows the README pattern: `cd /var/www/triple-r && /usr/bin/php bin/<job>.php >> storage/<job>.log 2>&1`.

### 5.6 SMS (optional)

1. Register with the chosen provider and set its key and sender name in `.env`.
2. Give the provider the two webhook addresses, `https://<domain>/webhooks/sms/inbound` and `https://<domain>/webhooks/sms/delivery`, and the shared secret.
3. Send one test booking link to your own phone and open it.

### 5.7 Backups and upkeep

1. A nightly `mysqldump --single-transaction` and a nightly archive of `storage/`, both copied off the server.
2. Restore one backup into a scratch database once, to prove the backups work.
3. Log rotation for the files the jobs write under `storage/`.
4. A free uptime monitor pointed at the home page.
5. To update the site: `git pull`, run `php bin/migrate.php` with the migration account if there are new migrations, and reload Apache. The built stylesheet is in the repository, so the server does not need Node.

### 5.8 Go-live checks

- [ ] `https://<domain>/` loads with a valid certificate, and `http://` redirects to it.
- [ ] `https://<domain>/.env`, `/config/site.php` and `/storage/` are not reachable.
- [ ] Sign-in works; the seeded password has been changed.
- [ ] One account per role sees only its own menu.
- [ ] A full rental can be taken from reservation to completion, with a photo upload.
- [ ] `/maintenance` and `/fleet/drivers` load and a new driver can be added and revealed.
- [ ] Each job's log file under `storage/` shows a recent run.
- [ ] A mistyped address shows the styled 404 page.
- [ ] The site is usable on a phone.

---

## 6. Option B: online for the presentation (chosen)

This puts your own computer's copy of the system online for as long as one PowerShell window stays open.

### 6.1 What is already done

- **Trusted-proxy support.** Behind a tunnel, the app used to see every visitor as `127.0.0.1` over plain HTTP. One person's failed sign-ins could then lock everyone out, and the session cookie was not marked Secure. `app/Http/TrustedProxy.php` now reads the visitor's real address and the HTTPS flag from the tunnel's headers, but only when the connection comes from an address listed in `TRUSTED_PROXIES`. The setting is empty by default, so normal local use is unchanged. Tested: tunnelled requests get a Secure cookie and the visitor's own address; direct requests and spoofed headers do not.
- **Start script.** `bin/demo-online.ps1` starts the local MySQL 8, opens the tunnel, reads the public address it is given, and starts the web server on port 8088 with two settings for that run only: `TRUSTED_PROXIES`, and `APP_BASE_URL` set to the public address. `.env` is not changed. Pressing Ctrl+C stops both the tunnel and the web server.
- **Tested through a live tunnel (2026-10-01).** From the public address: the landing page and its images loaded; three.js arrived compressed (about 260 KB instead of 1.2 MB); the sign-in page set a Secure session cookie; sign-in worked and was recorded from the visitor's own address, not `127.0.0.1`; staff pages opened; and the customer secure-link endpoint accepted requests from the public address and refused ones claiming the old local address.

### 6.2 One-time setup

Done on the owner's computer on 2026-10-01 (version 2026.9.3). On another computer, install the tunnel client (a free download from Cloudflare, no account needed for a quick tunnel):

```powershell
winget install --id Cloudflare.cloudflared
```

Then open a new PowerShell window so the command is found.

### 6.3 On the day

1. Open PowerShell in the project folder and run:

   ```powershell
   .\bin\demo-online.ps1
   ```

2. Wait for "The system is online at:" followed by an `https://....trycloudflare.com` address. That is the public address; open it on any phone or computer.
3. Keep the window open for the whole presentation. Press Ctrl+C when finished; the site goes offline.

### 6.4 Rehearse before the defense

- [ ] Run the script once at home and open the address on a phone using mobile data, not your Wi-Fi.
- [ ] Sign in on the phone, open a few pages, and sign out.
- [ ] Set Windows to never sleep while plugged in, and bring the charger.
- [ ] Check the venue's Wi-Fi allows the tunnel. If it does not, use your phone's hotspot for the laptop.
- [ ] Decide which staff accounts you will show, and confirm their passwords work.
- [ ] Have a fallback: the same system at `http://127.0.0.1:8088` on the laptop works with no internet at all.

### 6.5 Limits to know

- The address is new every time the script starts. Share it after starting, not before.
- A free quick tunnel has no uptime guarantee. It is fine for a presentation, not for real customers.
- The built-in PHP server answers one request at a time. A handful of people browsing is fine; a whole class loading the landing page at once will feel slow.
- Customer booking links are built from the public address for that run, so a link issued during one run stops working when the script is restarted and the address changes. No SMS is actually sent unless an SMS provider key is configured in `.env`.
- The sign-in limiter allows 20 sign-in attempts a minute from one address and 5 a minute for one email. If several panel members share one Wi-Fi network, they count as one address.
- Anyone with the address can reach the sign-in page while the tunnel is open. Use strong passwords on the demo accounts, and close the tunnel afterwards.

---

## 7. Order of work

For the presentation:

| Step | What | Who | State |
|---|---|---|---|
| 1 | Trusted-proxy change and start script | done in the repository | Done |
| 2 | Install `cloudflared` (section 6.2) | done | Done |
| 3 | Rehearsal checklist (section 6.4) | you | To do |
| 4 | Commit the changes and merge `ui-redesign` into `main` | done | Done |

For a permanent site later: sections 3, 4 and 5, starting with a provider account and a domain.
