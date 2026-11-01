# TIMS-BARMM Offline Installation & Configuration Guide

> **Version:** Phase 5  
> **Audience:** IT administrators deploying TIMS-BARMM on offline field laptops  
> **Last updated:** 2026-10-23

---

## Table of Contents

1. [Prerequisites](#1-prerequisites)
2. [Initial Setup (New Laptop)](#2-initial-setup-new-laptop)
3. [Enrolling with the Central Server](#3-enrolling-with-the-central-server)
4. [Client Device Access (Hotspot / LAN)](#4-client-device-access-hotspot--lan)
5. [Routine Operations](#5-routine-operations)
6. [Updating the Application](#6-updating-the-application)
7. [Backup & Restore](#7-backup--restore)
8. [Conflict Resolution](#8-conflict-resolution)
9. [Troubleshooting](#9-troubleshooting)
10. [Security Checklist](#10-security-checklist)

---

## 1. Prerequisites

### Hardware

| Component | Minimum | Recommended |
|---|---|---|
| CPU | Intel i3 / AMD Ryzen 3 | Intel i5+ / Ryzen 5+ |
| RAM | 4 GB | 8 GB |
| Storage | 50 GB free | 128 GB SSD |
| Network | Wi-Fi adapter (for hotspot) | Wi-Fi + Ethernet |
| OS | Windows 10 (64-bit) | Windows 10/11 Pro |

### Software

| Software | Version | Notes |
|---|---|---|
| XAMPP | 8.2+ | Includes PHP 8.2+, MariaDB 10.4+, Apache 2.4 |
| PHP extensions | pdo_mysql, openssl, mbstring, tokenizer, ctype, json, fileinfo, xml, zip | Most enabled by default in XAMPP |
| PowerShell | 5.1+ | Built into Windows 10/11 |

### Before You Start

- [ ] Install XAMPP to `C:\xampp` (default path)
- [ ] Copy the TIMS-BARMM project to `C:\xampp\htdocs\TIMS-BARMM`
- [ ] Obtain a **one-time enrollment token** from the central server administrator
- [ ] Know the **central server URL** (e.g., `https://central.tims-barmm.gov.ph`)
- [ ] Determine the **LAN subnet** for the hotspot (e.g., `192.168.137.0/24`)

---

## 2. Initial Setup (New Laptop)

### Preparing the Installation Package (Central Server)

The central server builds the package that is copied to the field device:

1. Sign in to the central server as an administrator.
2. Open **Admin → Offline Installation**.
3. Check the **Server prerequisites** card. Resolve any error before continuing.
4. Enter a **Device label** (for example `Lanao del Sur Laptop 1`) and click **Build package**.
5. Watch the progress bar. The archive is written by a background process, so the
   page stays responsive and reports the files that have been packaged. The page
   reloads on its own when the package is ready.
6. Download the generated `.zip` and transfer it to the target device.

Only one package can be built at a time. A second request is refused while a
build is running, and a build that never starts is reported as failed so it can
be retried.

Each package contains the application code, the Composer dependencies, the setup
scripts, and `.env.example`. It never contains credentials, keys, logs, backups,
generated caches, or the git history. `SHA256SUMS.txt` lists the checksum of
every packaged file, and `INSTALL.txt` repeats the installation steps on the
device itself.

### Automated Setup (Recommended)

Extract the package to `C:\xampp\htdocs\TIMS-BARMM`, then open **PowerShell as
Administrator** and run:

```powershell
cd C:\xampp\htdocs\TIMS-BARMM
.\scripts\setup-offline.ps1 -Subnet "192.168.137.0/24"
```

The script will:
1. Validate XAMPP, PHP, and required extensions
2. Verify the package against `SHA256SUMS.txt` — hashes every packaged file (~9,500 in a standard package) and prints progress every 250 files; skipped with `-SkipChecksumVerification`
3. Refuse to continue when a populated database or a `.env` file is already present
4. Create the database and a least-privilege database user
5. Generate a production `.env` file (no interactive prompts)
6. Run database migrations
7. Create the initial admin account
8. Configure Apache with a secure VirtualHost
9. Add Windows Firewall rules (LAN-only access)
10. Create a scheduled task for automatic sync (every 15 minutes)
11. Create a scheduled task for daily backups (2:00 AM)
12. Open the application in the default browser

> **Slow verification step.** Verifying the package reads every packaged file, so on a slow
> disk or with real-time antivirus it can take a few minutes. The current script hashes with
> .NET and prints `verified N/M files (Ns)` every 250 files. A package built with an older
> script prints nothing until the step finishes: if it looks frozen, you can stop it with
> `Ctrl+C` — at that point nothing has been written yet — and re-run with
> `-SkipChecksumVerification` when the package came from a trusted transfer. Rebuild future
> packages so the installer shows progress.

#### Recovering or Reinstalling

The script never overwrites existing field data. When it detects a populated
database it stops with the recovery options.

After deploying updated code, apply pending migrations without reinstalling:

```powershell
.\scripts\setup-offline.ps1 -Repair
```

`-Repair` keeps the existing database, `.env`, Apache configuration, and
firewall rules. To deliberately reinstall instead:

```powershell
.\scripts\setup-offline.ps1 -Subnet "192.168.137.0/24" -Reinstall
```

`-Reinstall` writes a full database dump and a copy of the existing `.env` into
`storage\app\private\backups` before it touches anything. For a genuinely
clean device, drop the database yourself first:

```sql
DROP DATABASE tims_barmm;
```

### Manual Setup

If the automated script cannot be used:

1. **Start XAMPP** — open XAMPP Control Panel, start Apache and MySQL.

2. **Create the database:**
   ```sql
   CREATE DATABASE tims_barmm CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
   CREATE USER 'tims_app'@'127.0.0.1' IDENTIFIED BY '<strong-password>';
   GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, DROP, INDEX, ALTER, REFERENCES
     ON tims_barmm.* TO 'tims_app'@'127.0.0.1';
   FLUSH PRIVILEGES;
   ```

3. **Configure `.env`** — copy `.env.example` to `.env` and set:
   ```ini
   APP_ENV=production
   APP_DEBUG=false
   INSTANCE_MODE=offline
   DB_DATABASE=tims_barmm
   DB_USERNAME=tims_app
   DB_PASSWORD=<your-password>
   ```

4. **Generate an application key:**
   ```powershell
   C:\xampp\php\php.exe artisan key:generate
   ```

5. **Run migrations:**
   ```powershell
   C:\xampp\php\php.exe artisan migrate --force
   ```

6. **Create the admin account** via Tinker or the application seeder.

---

## 3. Enrolling with the Central Server

> **Prerequisite:** The central server admin must generate an enrollment token first (Admin → Offline Servers → Prepare an offline installation). The token is issued with an **authorization period** - the number of days this installation is allowed to operate. After that period the central server rejects uploads; records, audit history, and the pending queue stay on the device.

1. Open the application in a browser: `http://localhost/` (or `http://<this-PC-LAN-IP>/` from another device)
2. Log in as admin
3. Navigate to **Offline Installation** (sidebar)
4. Fill in:
   - **Server name** — a descriptive name (e.g., "Lanao del Sur Field Office")
   - **Enrollment token** — the one-time token from the central admin
5. Click **Enroll this server**
6. After enrollment, click **Download latest snapshot** to pull the initial dataset

The **Central Server address** is not typed on the device: it comes from the installation
package (`SYNC_BOOTSTRAP_URL`) and is shown read-only on the page. Installations cover the
**entire BARMM region**; there is no per-device area or PSGC selection to fill in.

### Field Encoder Accounts

Create the accounts the field staff will use on **Offline Installation → Encoders**
on the device. Each encoder receives a stable identifier that is reported to the
central server, so every action stays attributable. Passwords never leave the
device.

### Reauthorization

When the authorization period ends, uploads are rejected and protected writes
are blocked on the device, but nothing is deleted. Ask the central administrator
for a reauthorization token, enter it on the Offline Installation page, and the
device continues with its history intact. Signing in again does **not** extend
the period.

### Recovering a Revoked Installation or Lost Server Token

If the central administrator revokes the installation, or its stored server
token is lost, normal reauthorization cannot help because the device can no
longer authenticate. Restore it without losing local data:

1. On the central server, open **Offline Servers → Prepare an installation** and
   generate a new **enrollment token**.
2. On the offline device, open **Offline Installation** and expand
   **Recover this installation**.
3. Enter the server name and the new enrollment token, then click **Re-enroll**.

The same server UUID is reactivated on the central server and a new server token
is stored on the device. Local beneficiaries, batches, audit history, and the
pending queue are preserved. An enrollment token is required here; a
reauthorization token cannot restore a revoked or unauthenticated installation.

### After Enrollment

- The server UUID is generated automatically and stored in encrypted settings
- The server token is stored encrypted — never share it
- The 15-minute auto-sync schedule begins immediately (if the scheduled task was created)

---

## 4. Client Device Access (Hotspot / LAN)

### Setting Up a Hotspot

1. Open **Windows Settings → Network & internet → Mobile hotspot**
2. Enable the hotspot
3. Note the **IP address** (usually `192.168.137.1`)
4. Connect client devices (phones, tablets) to the hotspot

### Accessing the Application

1. On the offline server, go to **Offline Installation** in the web UI
2. The **Local network access** card shows:
   - The application URL (e.g., `http://192.168.137.1/`)
   - A **QR code** that clients can scan
   - The detected **LAN IP** status
3. On client devices, either:
   - Scan the QR code with the camera app
   - Type the URL into the browser

### Important Warnings

> **⚠ Sleep / Hibernate:** If the laptop sleeps or hibernates, all connected clients lose access. Disable sleep in **Windows Power Options** during active use.

> **⚠ Firewall:** The setup script restricts Apache access to the configured LAN subnet. If you change your hotspot settings, update the firewall rule:
> ```powershell
> # Remove old rule and create new one
> Remove-NetFirewallRule -DisplayName "TIMS-BARMM Apache LAN"
> New-NetFirewallRule -DisplayName "TIMS-BARMM Apache LAN" `
>     -Direction Inbound -Action Allow -Protocol TCP `
>     -LocalPort 80 -RemoteAddress "192.168.NEW.0/24" -Profile Any
> ```

---

## 5. Routine Operations

### Manual Sync

Go to **Offline Installation** and click **Sync now**. This:
1. Uploads all pending local operations to the central server
2. Downloads any new changes from the central server
3. Reports the count of applied, failed, and conflicting operations
4. Sends the local audit trail and the encoder registry to the central server

### Automatic Sync

The setup script creates a Windows Task Scheduler job named **TIMS-BARMM Offline Sync** that runs every 15 minutes. You can also trigger it via command line:

```powershell
C:\xampp\php\php.exe C:\xampp\htdocs\TIMS-BARMM\artisan tims:sync
```

Options:
- `--upload-only` — only push local changes
- `--download-only` — only pull central changes
- `--quiet-on-skip` — suppress output when nothing is pending

### Checking Sync Status

- **Offline Installation page** — shows pending uploads, failed operations, the audit trail status, and the sync history
- **Conflict Resolution page** — shows every conflict that needs manual review (sidebar → Conflict Resolution; local and central conflicts share one workspace)

### Audit Trail

Every sensitive action is written to the local audit log with the identity of the actor
(username, role, stable identifier) and the installation that produced it. The records
stay on the device and are sent to the central server with the next sync; the central
copy keeps the same actor and origin, so the trail remains attributable. The
**Audit log** page shows the events waiting to be reported and the last successful
report.

---

## 6. Updating the Application

### Using the Update Script

1. Obtain the new release ZIP from the central IT team
2. Open **PowerShell as Administrator**:

```powershell
cd C:\xampp\htdocs\TIMS-BARMM
.\scripts\update-offline.ps1 -SourceZip "C:\Updates\TIMS-BARMM-v2.0.zip"
```

The script will:
1. Create a full backup (database + files)
2. Enable maintenance mode
3. Deploy the new version (preserving `.env` and `storage/`)
4. Run database migrations
5. Rebuild caches
6. Run health checks
7. If anything fails, **automatically roll back** to the backup

### Manual Rollback

If the update fails and automatic rollback doesn't complete:

```powershell
# Find the backup directory
dir C:\TIMS-BARMM-Backups\update-*

# Restore files from the rollback snapshot
Copy-Item "C:\TIMS-BARMM-Backups\update-YYYYMMDD-HHMMSS\rollback-files\*" `
    "C:\xampp\htdocs\TIMS-BARMM" -Recurse -Force

# Restore the database from the dump
C:\xampp\mysql\bin\mysql.exe -u root tims_barmm < "C:\TIMS-BARMM-Backups\update-YYYYMMDD-HHMMSS\db-YYYYMMDD-HHMMSS.sql"

# Disable maintenance mode
C:\xampp\php\php.exe artisan up
```

---

## 7. Backup & Restore

### Automated Daily Backup

The setup script creates a task **TIMS-BARMM Daily Backup** that runs at 2:00 AM daily.

Each backup includes:
- **Database dump** (compressed `.sql.zip`)
- **Uploaded files** (`storage/app` archive)
- **Environment file** (`.env.bak`, admin-only ACL)
- **Manifest** (`manifest.json` with file list and sizes)

Backups are stored in `C:\TIMS-BARMM-Backups\daily-*` and pruned after 7 days.

### Manual Backup

```powershell
# Run backup immediately
.\scripts\backup-offline.ps1

# Custom backup location and retention
.\scripts\backup-offline.ps1 -BackupRoot "D:\Backups" -RetainDays 14
```

Or via artisan:
```powershell
C:\xampp\php\php.exe artisan tims:backup
C:\xampp\php\php.exe artisan tims:backup --retain=14
```

### Restoring from Backup

1. Stop Apache and MySQL
2. Restore the database:
   ```powershell
   # Decompress if needed
   Expand-Archive "C:\TIMS-BARMM-Backups\daily-YYYYMMDD-HHMMSS\database.sql.zip" -DestinationPath .

   # Import
   C:\xampp\mysql\bin\mysql.exe -u root tims_barmm < database.sql
   ```
3. Restore uploaded files:
   ```powershell
   Expand-Archive "C:\TIMS-BARMM-Backups\daily-YYYYMMDD-HHMMSS\storage-app.zip" `
       -DestinationPath "C:\xampp\htdocs\TIMS-BARMM\storage" -Force
   ```
4. Restore `.env` if needed:
   ```powershell
   Copy-Item "C:\TIMS-BARMM-Backups\daily-YYYYMMDD-HHMMSS\.env.bak" `
       "C:\xampp\htdocs\TIMS-BARMM\.env" -Force
   ```
5. Restart Apache and MySQL

---

## 8. Conflict Resolution

### When Conflicts Occur

A sync conflict happens when:
- The offline server edited a record field
- The central server (or another offline server) also edited the **same field** of the **same record**
- Both edits happened since the last successful sync

### Resolving Conflicts

1. Go to **Conflict Resolution** (sidebar). Local and central conflicts share one workspace; use the **Source** filter to narrow it down
2. For each open conflict, click **Resolve**
3. Review the side-by-side comparison showing local vs. central values
4. Choose a resolution strategy:

| Strategy | What happens |
|---|---|
| **Keep central** | Discard all incoming offline changes. Central record stays as-is. |
| **Accept local** | Overwrite the central record with the offline version. |
| **Merge fields** | Cherry-pick specific fields from the offline version. |

5. Click **Confirm resolution**
6. After resolving all conflicts, click **Sync now** to push resolutions to central

### Auto-Merge

If the offline server changed field A and the central server changed field B (no overlap), the system **automatically merges** both changes without creating a conflict. Only true field-level overlaps require manual review.

### Pending Sync Requests (review before acceptance)

A successful upload is not the same as acceptance. Every **Sync now** submission is recorded on the
central server as a **Sync Request**, and each submitted record inside it has its own state:

| Record outcome | Meaning |
|---|---|
| **Applied** | Validated cleanly and applied on receipt. |
| **Awaiting review** | A possible duplicate or a record that needs a decision; it is **not** applied yet. |
| **Accepted / Rejected** | A reviewer decided the record, with a recorded reason for rejections. |

On the **central server**, open **Sync Requests** in the sidebar (the badge shows how many requests
are waiting). Each request lists the submitted values next to the central record, the possible
duplicate matches, and the conflict comparison where one exists. A reviewer can then:

- **Accept a duplicate** — either confirm it is the same person (the existing central record is kept
  and nothing is inserted) or apply the submitted values onto the matched record.
- **Resolve a conflict** — accept the submitted values, merge selected fields, or keep central.
- **Reject a record** — a reason is required; the offline server receives it with the decision.

On the **offline server**, records held for review appear as *Awaiting review* in the queue and are
never shown as synchronized. They stay intact locally, and the next **Sync now** pulls the reviewer's
decision: accepted records become synced, rejected records keep their data and show the reason.
Repeated syncs never create a second record or a second review.

---

## 9. Troubleshooting

### Common Issues

| Problem | Cause | Solution |
|---|---|---|
| "Sync not configured" | Server not enrolled | Go to Offline Installation and enroll |
| "Authorization expired" | The authorization period ended | Ask the central admin for a reauthorization token and enter it on the Offline Installation page |
| Uploads rejected with 403 | Installation is not authorized | Reauthorization restores uploads; local records and audit history are untouched |
| Records stuck as "Awaiting review" | A central reviewer has not decided them yet | Open Sync Requests on the central server; the offline server picks the decision up on its next Sync now |
| Installer appears stuck at "Verifying package integrity" | It is hashing every packaged file (~9,500) and only prints the step banner; slow disks and antivirus stretch this to several minutes | Wait it out, or stop with `Ctrl+C` (nothing is written before this step finishes) and re-run with `-SkipChecksumVerification` for a package received over a trusted transfer |
| Enrollment fails with "The address … answered the compatibility check with HTTP 404" (or the page warns about a loopback address) | The package's `SYNC_BOOTSTRAP_URL` is a `localhost`/`127.0.0.1` address or the wrong path, so the device contacts itself instead of the Central Server | On the central server set `APP_URL` to its LAN address (e.g. `http://192.168.1.122/TIMS-BARMM/public`) and rebuild the package. On an already-installed device, set `SYNC_BOOTSTRAP_URL` in its `.env` and run `php artisan config:clear` |
| Enrollment fails with "Could not reach the Central Server at …" | The device cannot open a connection to that address | Confirm the Central Server is running and reachable from the device's browser, and that Windows Firewall on the central machine allows port 80 on the LAN |
| Clients can't connect | Firewall blocking | Check `Get-NetFirewallRule -DisplayName '*TIMS*'` |
| Clients can't connect | Wrong IP/subnet | Verify hotspot IP matches the URL |
| Sync fails with 401 | The server token is invalid, or the server was revoked | If it was revoked or the token is lost, generate a new enrollment token on the central server and use **Offline Installation → Recover this installation**; otherwise ask the central admin to rotate the token and update the device |
| Sync fails with 503 | Central server down | Retry later; operations are queued |
| Database connection error | MySQL not running | Start MySQL in XAMPP Control Panel |
| "Class not found" | Missing vendor/ | Run `php composer.phar install --no-dev` |

### Diagnostic Commands

```powershell
# Check PHP extensions
C:\xampp\php\php.exe -m

# Check database connectivity
C:\xampp\php\php.exe artisan tinker --execute="DB::select('SELECT 1');"

# Check sync settings
C:\xampp\php\php.exe artisan tinker --execute="echo App\Models\SyncSetting::get('central_url');"

# List pending sync operations
C:\xampp\php\php.exe artisan tinker --execute="echo App\Models\SyncOperation::where('status','pending')->count();"

# Clear all caches
C:\xampp\php\php.exe artisan config:clear
C:\xampp\php\php.exe artisan cache:clear
C:\xampp\php\php.exe artisan view:clear

# Test sync manually
C:\xampp\php\php.exe artisan tims:sync --upload-only
```

### Log Files

| Log | Location |
|---|---|
| Application log | `storage/logs/laravel-YYYY-MM-DD.log` |
| Apache error log | `C:\xampp\apache\logs\tims-barmm-error.log` |
| Apache access log | `C:\xampp\apache\logs\tims-barmm-access.log` |
| Backup log | `C:\TIMS-BARMM-Backups\backup.log` |

---

## 10. Security Checklist

Before deploying to the field, verify:

- [ ] **`.env` protection** — APP_DEBUG=false, APP_ENV=production
- [ ] **Database credentials** — unique, strong password (16+ chars)
- [ ] **Admin password** — strong password (12+ chars)
- [ ] **Windows Firewall** — Apache allowed only on LAN subnet, MySQL blocked for external
- [ ] **Disk encryption** — BitLocker enabled on the system drive
- [ ] **Windows updates** — current patches applied
- [ ] **XAMPP phpMyAdmin** — disabled or password-protected
- [ ] **No public internet exposure** — the laptop should never be directly accessible from the internet
- [ ] **Backup verification** — restore at least one backup to confirm it works
- [ ] **Physical security** — laptop stored securely when not in active use

> **⚠ Never store the enrollment token, server token, or database password in plaintext files, emails, or chat messages.** The `.env` file contains all secrets and must be protected with appropriate file permissions and disk encryption.

---

*End of guide. For central server setup and configuration, see the separate online deployment documentation.*
