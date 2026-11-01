# Public Hosting Runbook — TIMS-BARMM

**Status:** Planning artifact. Do not execute the exposure steps until the owner approves Phase 7 of `docs/public-hosting-implementation-plan.md`.
**Audience:** the administrator who operates the Windows/XAMPP host and the owner who controls the router, domain, and approvals.
**Secret policy:** every value in angle brackets (`<…>`) is a placeholder. Never paste real passwords, tokens, private keys, or the public IP into this file or any repository file.

---

## 0. Read this first

> **DANGER — exposure point.** Steps marked **EXPOSES SERVER** make the machine reachable from the Internet. They are the last steps, they require explicit owner approval, and they must be reversible within one minute by removing the router port forward.

- The server is currently a **local development installation**. Debug mode, a blank-password database superuser, HTTP-only serving, and exposed XAMPP tooling make it unsafe to expose.
- Never expose the server before HTTPS is verified.
- Never forward port 3306, RDP (3389), SMB (445), or the XAMPP administrative pages.
- The offline/online architecture must keep working: an offline device must continue to operate without the central server, and pending sync operations must never be discarded because of a public-host outage.

## 1. Roles and prerequisites

| Role | Responsibilities |
|---|---|
| Owner | Router, ISP, domain/DNS, certificate account, privacy decisions, approval to expose |
| Administrator | Windows, XAMPP, Apache, PHP, MariaDB, firewall, services, backups, monitoring |
| Operator (optional) | Day-to-day smoke checks, incident first response |

Prerequisites (all must be true before starting Phase 2 of the plan):

- [ ] Phase 1 baseline completed: reproducible revision, restore-tested database dump, configuration evidence.
- [ ] Windows Administrator access and router admin access available.
- [ ] Domain/DNS decision made (or DDNS chosen).
- [ ] ISP/NAT situation known (public IP vs CGNAT; inbound 80/443 allowed or not).
- [ ] Maintenance window agreed; users and offline servers notified.
- [ ] Out-of-band contact channel for emergencies.

## 2. Architecture and ports (target)

```
Internet ──443──▶ router ──▶ Windows host ──▶ Apache (TLS vhost, DocumentRoot …\public)
                                                        └─▶ PHP-FPM/mod_php ──▶ Laravel
                                                        └─▶ MariaDB 127.0.0.1:3306 (never forwarded)
Optional: Cloudflare Tunnel / reverse proxy terminates TLS instead of port forwarding.
```

| Port | Purpose | Public? |
|---|---|---|
| 443 | HTTPS application | Yes (after approval) |
| 80 | ACME HTTP-01 validation and HTTP→HTTPS redirect | Yes (after approval), redirect only |
| 3306 | MariaDB | **Never** |
| 8080 (example) | Optional loopback-only admin tools | No (bind 127.0.0.1) |

## 3. Production application configuration

Work from `C:\xampp\htdocs\TIMS-BARMM` (or the final app path). Back up `.env` first.

```powershell
Copy-Item .env "$env:USERPROFILE\ops\env-backup-$(Get-Date -Format yyyyMMdd-HHmmss).bak"
```

Set the production values in `.env` (edit with an Administrator-only editor):

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://<PUBLIC_DOMAIN>
LOG_LEVEL=warning
LOG_CHANNEL=daily
LOG_DAILY_DAYS=14

# After HTTPS is verified
SESSION_SECURE_COOKIE=true
SESSION_ENCRYPT=true
SESSION_SAME_SITE=lax

# Least-privilege database account (created in section 4)
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=tims_barmm
DB_USERNAME=tims_web
DB_PASSWORD=<STRONG_RANDOM_PASSWORD>
```

> **Warning:** do not change `APP_KEY` on a live central installation. It decrypts device server tokens and invalidates sessions; rotating it forces offline servers to re-enroll. Changing `APP_KEY` is only for a fresh deployment.

Rebuild caches and verify:

```powershell
C:\xampp\php\php.exe artisan config:clear
C:\xampp\php\php.exe artisan config:cache
C:\xampp\php\php.exe artisan route:cache
C:\xampp\php\php.exe artisan view:cache
C:\xampp\php\php.exe artisan about
```

Expected: `Environment production`, `Debug Mode DISABLED`, config/routes cached.

If the application uses queues (`QUEUE_CONNECTION=database`), start a supervised worker:

```powershell
C:\xampp\php\php.exe artisan queue:work --sleep=3 --tries=3 --timeout=120
```

(The sync/import paths are currently synchronous; queue workers matter for future background jobs and should be supervised if used.)

## 4. Database least privilege (Administrator)

> **Warning:** perform this in a maintenance window. Keep the MariaDB root console open until the application is verified.

```powershell
$mysql = 'C:\xampp\mysql\bin\mysql.exe'

# 1) Create the web account (no DDL, no GRANT)
& $mysql -u root -p -e "CREATE USER 'tims_web'@'127.0.0.1' IDENTIFIED BY '<STRONG_RANDOM_PASSWORD>';"
& $mysql -u root -p -e "GRANT SELECT, INSERT, UPDATE, DELETE ON tims_barmm.* TO 'tims_web'@'127.0.0.1'; FLUSH PRIVILEGES;"

# 2) Give root accounts strong passwords (record them in a password manager, not here)
& $mysql -u root -e "ALTER USER 'root'@'localhost' IDENTIFIED BY '<ROOT_PASSWORD>';"
& $mysql -u root -p -e "ALTER USER 'root'@'127.0.0.1' IDENTIFIED BY '<ROOT_PASSWORD>';"
& $mysql -u root -p -e "ALTER USER 'root'@'::1' IDENTIFIED BY '<ROOT_PASSWORD>';"
& $mysql -u root -p -e "ALTER USER 'pma'@'localhost' IDENTIFIED BY '<PMA_PASSWORD>';"

# 3) Remove anonymous/'%' accounts if present
& $mysql -u root -p -e "SELECT user, host FROM mysql.user;"
```

Run migrations with the administrative account, not `tims_web`:

```powershell
C:\xampp\php\php.exe artisan migrate --force
```

Verify the web account cannot do DDL:

```powershell
& $mysql -u tims_web -p -e "SHOW GRANTS; DROP TABLE tims_barmm.audit_logs;"   # DROP must be denied
```

Expected: `SHOW GRANTS` lists only DML on `tims_barmm`, and `DROP` fails with access denied.

## 5. Apache virtual host (Administrator)

Place the application outside the shared web root if possible (e.g., `C:\sites\TIMS-BARMM`); the document root must be its `public` folder.

Example HTTP vhost (`C:\xampp\apache\conf\extra\httpd-vhosts.conf`):

```apache
# Global hardening (httpd.conf)
ServerTokens Prod
ServerSignature Off
TraceEnable Off

<VirtualHost *:80>
    ServerName <PUBLIC_DOMAIN>
    DocumentRoot "C:/sites/TIMS-BARMM/public"

    <Directory "C:/sites/TIMS-BARMM/public">
        Options -Indexes -Includes +FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>

    # Never serve these, even if present
    <FilesMatch "^(\.env(\..*)?|\.git.*|composer\.(json|lock)|package\.json|phpunit\.xml|artisan|.*\.md|.*\.ps1)$">
        Require all denied
    </FilesMatch>

    ErrorLog "logs/tims-error.log"
    CustomLog "logs/tims-access.log" combined
</VirtualHost>
```

Keep XAMPP administration off this listener. If you need phpMyAdmin locally, use a loopback-only listener:

```apache
Listen 127.0.0.1:8080
```

and ensure `/dashboard`, `/phpinfo.php`, `/server-status`, `/server-info`, `/phpmyadmin`, `/licenses`, `/webalizer` are either absent or restricted to `127.0.0.1`.

Validate and reload:

```powershell
C:\xampp\apache\bin\httpd.exe -t
Restart-Service -Name Apache2.4    # or restart from the XAMPP control panel
```

## 6. PHP and Apache production settings

`C:\xampp\php\php.ini`:

```ini
expose_php = Off
display_errors = Off
display_startup_errors = Off
log_errors = On
error_log = "C:\xampp\apache\logs\php-error.log"
session.use_strict_mode = 1
session.cookie_httponly = 1
session.cookie_samesite = "Lax"
```

`httpd.conf` (if not already set): `ServerTokens Prod`, `ServerSignature Off`, `TraceEnable Off`.

TLS vhost (Phase 4 of the plan): modern protocols and a trusted certificate, then HTTP→HTTPS redirect:

```apache
SSLProtocol all -SSLv3 -TLSv1 -TLSv1.1
SSLCipherSuite ECDHE-ECDSA-AES128-GCM-SHA256:ECDHE-RSA-AES128-GCM-SHA256:ECDHE-ECDSA-AES256-GCM-SHA384:ECDHE-RSA-AES256-GCM-SHA384
SSLHonorCipherOrder on

<VirtualHost *:443>
    ServerName <PUBLIC_DOMAIN>
    DocumentRoot "C:/sites/TIMS-BARMM/public"
    SSLEngine on
    SSLCertificateFile    "<CERT_PATH>\fullchain.pem"
    SSLCertificateKeyFile "<CERT_PATH>\privkey.pem"
    Header always set Strict-Transport-Security "max-age=31536000; includeSubDomains"
    # Same <Directory>/<FilesMatch> rules as the HTTP vhost
</VirtualHost>
```

Add the permanent redirect from the HTTP vhost once TLS is verified:

```apache
Redirect permanent / https://<PUBLIC_DOMAIN>/
```

## 7. Windows host hardening (Administrator)

```powershell
# Services: install XAMPP Apache/MySQL as Windows services (example paths; verify before running)
C:\xampp\apache\bin\httpd.exe -k install -n "Apache2.4"
C:\xampp\mysql\bin\mysqld.exe --install MySQL --defaults-file="C:\xampp\mysql\bin\my.ini"
Set-Service Apache2.4 -StartupType Automatic
Set-Service MySQL -StartupType Automatic
sc.exe config Apache2.4 depend= MySQL
```

If services are not used, install a supervisor (NSSM) with restart-on-failure.

```powershell
# Power: never sleep/hibernate on the server
powercfg /change standby-timeout-ac 0
powercfg /change hibernate-timeout-ac 0
powercfg /change monitor-timeout-ac 15
powercfg /setactive SCHEME_MIN   # High performance, if available

# Firewall: block database from the network, allow web only at Phase 7
New-NetFirewallRule -DisplayName "TIMS-BARMM HTTPS" -Direction Inbound -Protocol TCP -LocalPort 443 -Action Allow
New-NetFirewallRule -DisplayName "TIMS-BARMM HTTP"  -Direction Inbound -Protocol TCP -LocalPort 80  -Action Allow
New-NetFirewallRule -DisplayName "Block MariaDB inbound" -Direction Inbound -Protocol TCP -LocalPort 3306 -Action Block

# Verify
Get-NetFirewallProfile | Select-Object Name, Enabled, DefaultInboundAction
Get-NetFirewallRule | Where-Object { $_.DisplayName -like '*TIMS*' } |
    Select-Object DisplayName, Direction, Action, Enabled, Profile
Get-NetFirewallPortFilter | Where-Object { $_.LocalPort -in 80,443,3306 } |
    Select-Object Protocol, LocalPort
```

Windows Update and Defender:

- Enable automatic updates with a maintenance window; reboot after updates.
- Keep Defender real-time protection on; exclude only `storage/` and backup staging, **not** the code tree.
- Verify: `Get-MpComputerStatus` (real-time protection and signature age) and Windows Update history.

File permissions (verify the Apache service account name first):

```powershell
icacls "C:\sites\TIMS-BARMM\.env" /inheritance:r
icacls "C:\sites\TIMS-BARMM\.env" /grant:r "Administrators:(F)" "SYSTEM:(F)" "<APACHE_SERVICE_ACCOUNT>:(R)"
icacls "C:\sites\TIMS-BARMM\storage" /grant "<APACHE_SERVICE_ACCOUNT>:(OI)(CI)(M)"
icacls "C:\sites\TIMS-BARMM\bootstrap\cache" /grant "<APACHE_SERVICE_ACCOUNT>:(OI)(CI)(M)"
```

## 8. DNS, DDNS, and certificate

1. Create an `A` record for `<PUBLIC_DOMAIN>` pointing to the public IP (or the DDNS hostname). If IPv6 is used, add `AAAA` and verify firewall rules.
2. If the IP is dynamic, configure DDNS on the router or a client; verify `nslookup <PUBLIC_DOMAIN> 8.8.8.8` matches the current WAN address.
3. Certificate options (owner decision):
   - **win-acme, HTTP-01 (webroot):** requires inbound 80 to `…\public\.well-known\acme-challenge`.
   - **DNS-01:** no inbound 80; needs a DNS API credential.
   - **Cloudflare Tunnel / reverse proxy:** no port forwarding; terminate TLS at the edge; configure trusted proxies in the app.
4. Test renewal before relying on it (staging/`--test`), then schedule renewal and an Apache reload hook.
5. Monitor certificate expiry (external check or a scheduled `openssl`/`curl` check).

> **If CGNAT or the ISP blocks inbound ports:** stop. Do not attempt port forwarding; choose DNS-01 or a tunnel with the owner.

## 9. Verification checklist (before exposure)

Run from the LAN first, then from a mobile network (outside the office LAN).

```powershell
# Application and redirect
curl.exe -I http://<PUBLIC_DOMAIN>/            # expect 301/308 to https
curl.exe -I https://<PUBLIC_DOMAIN>/           # expect 200

# Sensitive paths must be 403/404 (test both hosts)
foreach ($p in @('/.env','/.git/config','/scripts/setup-offline.ps1','/graphify-out/graph.json','/phpmyadmin/','/dashboard/phpinfo.php','/server-status','/server-info','/docs/')) {
    curl.exe -s -o NUL -w "$p %{http_code}`n" "https://<PUBLIC_DOMAIN>$p"
}

# Headers and cookies
curl.exe -sI https://<PUBLIC_DOMAIN>/login | Select-String "HTTP/|Strict-Transport|Content-Security|X-Frame|X-Content-Type|Referrer-Policy|Set-Cookie|Server|X-Powered-By"

# TLS/certificate (from Windows with OpenSSL available, or an external scanner)
# Expected: trusted chain, TLS 1.2/1.3, grade A- or better, HSTS present
```

Application-level checks:

- [ ] `php artisan about` shows production/debug off.
- [ ] Full test suite green (`php artisan test`).
- [ ] Login, beneficiary CRUD, import preview/apply, report export, audit log, sync pages, package generation.
- [ ] Offline server end-to-end sync over HTTPS: enroll → snapshot → upload → review/decision → outcomes → conflict resolution.
- [ ] Token expiry/reauthorization: expired device cannot upload or perform protected writes; reauthorization restores access; pending operations/audit preserved; re-login/restart does not extend validity.
- [ ] Rate limits: 6 failed logins in a minute → 429; enrollment endpoint limited.
- [ ] No stack traces on errors; no directory listing; no XAMPP tools.

## 10. Backup procedure (production)

What to back up:

| Item | Notes |
|---|---|
| Database `tims_barmm` | Beneficiaries, users, imports, batches, audit, sync state |
| `storage/app/private` | Uploaded workbooks (transient), installation packages, app updates |
| `.env` | Secrets; encrypt at rest |
| Code revision | Git branch/tag (not a runtime backup) |

Nightly script outline (run as a scheduled task under a dedicated service account):

```powershell
$stamp = Get-Date -Format 'yyyyMMdd-HHmmss'
$staging = "D:\backups\tims\$stamp"
New-Item -ItemType Directory -Path $staging -Force | Out-Null

# 1) Database (use the admin account; prompt-free via a protected defaults file)
cmd /c "`"C:\xampp\mysql\bin\mysqldump.exe`" --defaults-extra-file=`"C:\secure\mysql-backup.cnf`" --single-transaction --routines --triggers tims_barmm > `"$staging\db.sql`""

# 2) Private files
Copy-Item "C:\sites\TIMS-BARMM\storage\app\private" "$staging\private" -Recurse -Force

# 3) Settings (encrypt the archive; do not leave plaintext)
Copy-Item "C:\sites\TIMS-BARMM\.env" "$staging\env.bak" -Force

# 4) Encrypt and move off-machine (example: 7-Zip AES-256)
& "C:\Program Files\7-Zip\7z.exe" a -t7z -mhe=on -p"<ARCHIVE_PASSWORD>" "$staging.7z" "$staging\*"
Copy-Item "$staging.7z" "\\<NAS_OR_DRIVE>\tims-backups\" -Force
Remove-Item $staging -Recurse -Force

# 5) Retention: keep 14 daily, 8 weekly, 12 monthly (implement in the script)
# 6) Alert when the dump is missing or smaller than a sanity threshold
```

Restore drill (monthly, to a scratch database — never over production first):

```powershell
& C:\xampp\mysql\bin\mysql.exe -u root -p -e "DROP DATABASE IF EXISTS tims_barmm_restore; CREATE DATABASE tims_barmm_restore CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
cmd /c "`"C:\xampp\mysql\bin\mysql.exe`" -u root -p tims_barmm_restore < `"<RESTORE_DB_DUMP>`""
& C:\xampp\mysql\bin\mysql.exe -u root -p -N -e "SELECT COUNT(*) FROM tims_barmm_restore.beneficiaries;"
```

Record the time taken (RTO evidence) and delete the scratch database afterwards.

Full restore to production: see `docs/backup-restore.md`, but replace root/blank-password commands with the administrative account and take a fresh backup first.

## 11. Monitoring and maintenance

Minimum monitoring (all must reach the owner):

| Check | Method | Threshold/action |
|---|---|---|
| Application up | External HTTPS check every 1–5 min | 2 consecutive failures → alert |
| TLS certificate | Daily check of not-after date | < 21 days → alert |
| Disk free | Scheduled PowerShell (`Get-PSDrive`) | < 20% free → alert |
| Apache/MySQL service | Scheduled `Get-Service` | Any stopped → alert |
| Backup success | Scheduled task exit code + file age | No backup in 26 h → alert |
| Failed logins | Query `audit_logs` for `auth.login_failed` | Spike > 20/hour → alert |
| Sync errors | `sync_events`, `sync_servers.last_sync_at` | No successful sync in expected window → alert |
| 5xx/429 | Access log parse | Spike → alert |

Maintenance cadence:

- Daily: confirm backups and monitoring green.
- Weekly: review failed logins, 5xx, disk.
- Monthly: restore drill, dependency updates in staging, certificate/renewal check, account review.
- Quarterly: firewall/forwarding review, access review, privacy/retention review, incident drill.

Update procedure:

```powershell
C:\xampp\php\php.exe artisan down
# Back up, pull the reviewed revision, then:
composer install --no-dev --optimize-autoloader
C:\xampp\php\php.exe artisan migrate --force
C:\xampp\php\php.exe artisan config:cache
C:\xampp\php\php.exe artisan route:cache
C:\xampp\php\php.exe artisan view:cache
C:\xampp\php\php.exe artisan up
# Smoke test, then keep the previous revision for rollback
```

## 12. Incident response

**Suspected compromise (web shell, unexpected admin account, defaced page):**

1. **Disable public access immediately:** remove the router port forwards (or stop Apache). This is the fastest safe containment.
2. Preserve evidence: copy `storage/logs`, Apache access/error logs, `audit_logs`, database dump, and the current code state to an isolated location before changing anything.
3. Rotate credentials: database accounts, `.env` secrets/`APP_KEY` decision, admin passwords, enrollment/server tokens (revoke affected offline servers), certificate keys if exposed.
4. Identify scope via audit log, Apache access log, and file timestamps; check for scheduled tasks, services, and new Windows accounts.
5. Rebuild from a known-good revision if persistence is suspected; restore data from a pre-incident backup.
6. Notify per the organisation's breach procedure (Philippine Data Privacy Act timelines); involve the DPO.
7. Re-enter service only after the root cause is fixed and the Phase 6 checks pass again.

**Defacement / unauthorized change:** same as above, plus compare code against the repository and check file hashes from the installation manifest.

**DDoS / resource exhaustion:** remove forwarding; enable CDN/WAF if available; rate limits already exist for login/enrollment/sync; do not raise limits during an attack.

**Certificate expired:** renew immediately; users will see warnings until then; verify renewal automation after.

**Disk full:** stop non-essential jobs, prune logs/backups per retention, expand storage, then verify MySQL starts and the app serves.

**Database corruption:** stop Apache, back up the damaged files, restore the latest verified dump, run integrity checks, and reconcile offline servers carefully (pending operations are designed to retry; expired authorizations must not be bypassed).

## 13. Emergency disable (keep this short and memorize it)

**EXPOSES SERVER** — reversal:

1. Remove the router port forwards for 80/443 (or disable the DDNS/records).
2. Disable the Windows Firewall inbound rules for 80/443.
3. Optional: `C:\xampp\php\php.exe artisan down` (returns 503 to LAN users) and stop Apache/MySQL if the host itself is suspect.
4. Verify from a mobile network that the site is unreachable.
5. Record the time, reason, and who acted in the operations log.

## 14. Appendix — useful locations

| Item | Path |
|---|---|
| Application | `C:\sites\TIMS-BARMM` (or `C:\xampp\htdocs\TIMS-BARMM`) |
| Document root | `…\TIMS-BARMM\public` |
| Apache config | `C:\xampp\apache\conf\httpd.conf`, `conf\extra\httpd-vhosts.conf`, `httpd-ssl.conf` |
| Apache logs | `C:\xampp\apache\logs\` |
| PHP config | `C:\xampp\php\php.ini` |
| MariaDB config | `C:\xampp\mysql\bin\my.ini` |
| Application logs | `storage\logs\laravel.log` (rotate to daily) |
| Backups | `D:\backups\tims` + off-machine copy (encrypted) |
| Certificates | `<CERT_PATH>` |
| Operations evidence | `<OPS_DIR>` (outside the web root, encrypted if it contains data) |

**Final reminder:** the exposure steps are last. If any verification in section 9 fails, remove the forwarding and return to the plan's remediation phases instead of accepting the risk.
