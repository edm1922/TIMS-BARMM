# Hosting Readiness Assessment — TIMS-BARMM

**Assessment date:** 2026-10-25
**Scope:** Read-only inspection of the central installation in `C:\xampp\htdocs\TIMS-BARMM` and the host environment. No application code, configuration, database, credentials, firewall rules, or router settings were changed.
**Question answered:** can this installation be exposed to the public Internet today?

## Executive summary

**Verdict: NOT READY for public Internet hosting.** The application itself is mature — 103 routes with role and mode middleware, 365 passing tests, parameterized queries, hashed tokens, authorization periods, idempotent synchronization, and audited destructive actions — but the installation is running as a **local development deployment**, not a production one.

Three findings block exposure outright:

1. **Debug/development mode is on** (`APP_ENV=local`, `APP_DEBUG=true`, PHP `display_errors=On`). Any error on a public page can disclose stack traces, file paths, configuration keys, and query fragments to an anonymous visitor.
2. **The application connects to MariaDB as `root` with an empty password**, and `root@localhost` / `root@127.0.0.1` have blank passwords. Any local foothold (or a web-level RCE) becomes full control of every database on the server, including beneficiary data and audit history. It contradicts the project's own security checklist and the existence of the least-privilege `tims_app` account.
3. **There is no usable HTTPS.** Apache listens on 443 with XAMPP's default 2013 self-signed certificate for `www.example.com`; the application is served over plain HTTP with no redirect and no `Secure` session cookie. Exposing it now would put credentials and personal data on the wire.

Additionally, several paths bypass the project's own hardening rules and are served with HTTP 200: XAMPP's dashboard and **`phpinfo()`**, the installer script, the Graphify graph, planning notes, and the modification specification. A stored-XSS defect in the beneficiary detail view (unescaped output of beneficiary fields) can compromise an administrator's session.

The security-first path is: fix the application/database/Windows issues in the repository and config, stand up a dedicated virtual host behind a valid certificate, put backups and monitoring in place, then — only after explicit approval — forward ports.

## Method and evidence

| Activity | Result |
|---|---|
| Graphify refresh | `python -m graphify update .` (from `graphify-out/.graphify_python`) on 2026-10-25 22:47: **12,993 nodes, 24,796 edges, 1,008 communities**; 447 changed code files re-extracted |
| Dependency graph review | `graphify-out/GRAPH_REPORT.md` — sync pipeline, beneficiary registration, reporting/audit hyperedges; `User`, `Beneficiary`, `OfflineServer` are the dominant domain nodes |
| Automated test baseline | `php artisan test` → **365 passed, 2,161 assertions** (493 s), exit code 0 |
| Route inventory | `php artisan route:list --json` → **103 routes**; middleware groups verified (see below) |
| HTTP exposure probes | `Invoke-WebRequest` against `http://localhost` and `http://<CENTRAL_LAN_IP>` for sensitive paths (results below) |
| Response headers | Inspected from `GET /TIMS-BARMM/public/login` |
| Apache/PHP/MariaDB configuration | `httpd.conf`, `httpd-vhosts.conf`, `httpd-ssl.conf`, `httpd-xampp.conf`, `httpd-info.conf`, `php.ini`, `my.ini` (read-only) |
| Database accounts/grants | `mysql -uroot -N -e ...` read-only queries (`@@bind_address`, `mysql.user`, `SHOW GRANTS`) |
| Dependency audit | `composer audit` → “No security vulnerability advisories found.” |
| TLS listener | TLS handshake succeeds on 443 with certificate verification disabled; certificate is the XAMPP default |
| Git exposure | Repository has a GitHub `origin`; `.env` has never been committed; `.git/` is HTTP-blocked |
| Windows controls | Denied by the confined agent session: firewall profile/rules, Defender status, service enumeration, OS build. Recorded as **unverified manual checks** |

**Environmental limitation.** The inspection session could not read Windows Firewall state, Defender state, Windows Update history, scheduled tasks, or service configuration (access denied / elevation required). Public reachability, router configuration, ISP policy, and DNS are also outside this machine. These are listed explicitly under “Unverified assumptions” and are not treated as pass/fail.

Graphify source of truth: `graphify-out/graph.json` and `graphify-out/GRAPH_REPORT.md`, refreshed before analysis. The repository's `docs/sync-implementation-tracker.md` was deliberately **not** used as status evidence.

## Verified environment

| Component | Verified value |
|---|---|
| Application | Laravel 12.70.0, PHP 8.2.12, Composer 2.10.2 |
| Web server | Apache 2.4.58 (Win64), OpenSSL 3.1.3, `Listen 80`, `Listen 443`, `DocumentRoot C:/xampp/htdocs` |
| Database | MariaDB 10.4.32, `bind-address=127.0.0.1`, `port=3306`, `skip-networking=0` |
| Instance mode | `INSTANCE_MODE=online` (central server), `APP_URL` currently an HTTP LAN address |
| Application root | `C:\xampp\htdocs\TIMS-BARMM` (served at `/TIMS-BARMM/public`; `/TIMS-BARMM/` redirects with HTTP 302) |
| Session storage | Database driver, `SESSION_ENCRYPT=false`, `SESSION_SECURE_COOKIE` unset |
| Queue/cache | Database |
| Logging | `stack/single`, `LOG_LEVEL=debug`, `storage/logs/laravel.log` (~3.3 MB, no rotation) |
| PHP hardening | `expose_php=On`, `display_errors=On`, `display_startup_errors=On`, `memory_limit=512M`, `upload_max_filesize=40M`, `post_max_size=40M`, `max_execution_time=300`, `session.use_strict_mode=0` |
| TLS | Default self-signed certificate (issued 2013, CN `www.example.com`), `SSLProtocol all -SSLv3`, `SSLCipherSuite HIGH:MEDIUM:!MD5:!RC4:!3DES` |
| XAMPP control | Apache/MySQL are **not installed as Windows services** (`sc query Apache2.4` → 1060; `sc query MySQL` → 1060) |
| Power | Active scheme “AMD Ryzen Balanced” (sleep possible) |

## Implemented functionality (verified in code and tests)

This inventory matters because public hosting must preserve these behaviours; the plan must not redesign them.

| Capability | Where it lives | Status |
|---|---|---|
| Beneficiary lifecycle (create, edit, archive, restore, hard delete) | `app/Http/Controllers/BeneficiaryController.php`, `BeneficiaryPolicy`, `BeneficiaryIdGenerator` | Implemented, tested |
| Release batches, imports, duplicate detection | `BeneficiaryBatchController`, `ImportController`, `DuplicateDetector` (5 rules) | Implemented, tested |
| Roles and server-side authorization | `EnsureRole` middleware, 5 policies (`Beneficiary`, `BeneficiaryBatch`, `ImportBatch`, `Export`, `AuditLog`), `role:` middleware on 57 routes, 20 `api/sync/v1` routes with server-token/authorization middleware | Implemented, tested |
| Central/offline identity and bootstrapping | `INSTANCE_MODE`, `SyncIdentity`, `EnsureInstallationConfigured`, onboarding | Implemented, tested |
| Sync enrollment and device tokens | `EnrollmentService`, `TokenService`, `AuthenticateSyncServer` | Implemented, tested |
| Authorization period, expiry, reauthorization | `EnsureServerAuthorization`, `EnsureOfflineAuthorization`, `OfflineAuthorization`, `ReauthorizationService`, token single-use/binding | Implemented, tested |
| Snapshot and delta synchronization, idempotent uploads, conflict resolution | `SnapshotDownloadService`, `SyncRunner`, `SyncOperationService`, `sync-conflicts` UI | Implemented, tested |
| Sync request review/approval workflow | `SyncRequestService`, `SyncRequestReviewService`, `sync-requests` UI, decision history | Implemented, tested |
| Audit trail with actor/origin and sync to central | `AuditLogger`, `AuditLog`, `AuditSyncService`, `AuditEventController` | Implemented, tested |
| Installation package generation and setup scripts | `InstallationPackageService`, `scripts/setup-offline.ps1`, `update-offline.ps1`, `backup-offline.ps1` | Implemented, tested |
| Encoder registry / User Monitoring | `AuditSyncService::registry`, `OfflineServerEncoder`, `sync-servers/show` | Implemented, tested |
| Reports and exports | `BeneficiaryReport`, `CsvExporter` (formula-injection guard), `BeneficiaryProfileXlsxExporter` (`TYPE_STRING`), `ExportPolicy` | Implemented, tested |

## Existing protections confirmed by inspection

| Control | Evidence |
|---|---|
| Authentication on application routes | `auth` middleware; no beneficiary/report/audit route is public |
| Role enforcement server-side | `role:admin`, `role:admin,staff`, `role:admin,staff,manager`, `instance.mode:online/offline`, `offline.authorized`; `Gate::authorize` in controllers |
| Login brute-force limit | `throttle:5,1` on `POST /login`; failed logins audit-logged |
| Session fixation | `$request->session()->regenerate()` after successful login |
| Generic login error | “Invalid credentials or inactive account.” (no user enumeration) |
| Inactive accounts blocked | Login attempt requires `is_active=true`; middleware logs out deactivated users |
| CSRF | Laravel `web` group; all forms use `@csrf` |
| Password hashing | `password => hashed` cast (`User` model line 106), `BCRYPT_ROUNDS=12` |
| SQL injection | All raw expressions are parameterised (`Beneficiary`, `Location`, `DashboardStats`); no string concatenation of user input found |
| Export formula injection | `CsvExporter` prefixes `= + - @ tab CR`; `BeneficiaryProfileXlsxExporter` writes every cell as `TYPE_STRING` |
| Upload validation | `.xlsx` only, content sniff (`application/vnd.openxmlformats…`), ZIP magic bytes, size/row/sheet caps, private disk storage |
| Sensitive paths denied | `.env` 403, `.git/config` 403, `storage/logs/laravel.log` 403, `database/…` 403, `docs/…` 403, `tests/…` 403, `vendor/autoload.php` 403, `composer.json` 403, `artisan` 403 |
| phpMyAdmin restricted | Apache alias uses `Require ip 127.0.0.1 ::1`; `/phpmyadmin/` from the LAN address returned **403** |
| Directory listing off | `Options -Indexes` in `public/.htaccess`, root `.htaccess`, and the TIMS `<Directory>` block |
| Tokens stored hashed | Enrollment/reauthorization tokens by SHA-256 hash (`SyncIdentity::hashToken`); server token hash only; device server token encrypted via `SyncSetting::setEncrypted` |
| Sync authorization enforced centrally | `EnsureServerAuthorization` reads the central row, not a device flag; rejects expired/revoked servers with 403 |
| Idempotent uploads | Receipt table keyed by operation UUID; reviewed operations replay their decision |
| Audit on destructive actions | `beneficiary.deleted` snapshot written before the row is removed; decisions recorded append-only |
| Backup tooling exists | `scripts/backup-offline.ps1`, `tims:backup`, documented restore procedure |
| Dependency advisories | `composer audit`: none found at assessment time |

## Security findings

### Critical

**C-1 Development mode is enabled.**
Evidence: `.env` line 2 `APP_ENV=local`, line 4 `APP_DEBUG=true`, line 30 `LOG_LEVEL=debug`; `php.ini` lines 506/515 `display_errors=On`, `display_startup_errors=On`; `artisan about` reports “Debug Mode … ENABLED”.
Impact under public exposure: detailed exception pages (Ignition), absolute paths, configuration keys, SQL fragments, and possibly session/credential material in logs. This alone is sufficient reason to refuse exposure.

**C-2 The application runs as MariaDB `root` with a blank password.**
Evidence: `.env` lines 39–40 (`DB_USERNAME=root`, `DB_PASSWORD=` empty); `mysql.user` shows blank authentication for `root@localhost`, `root@127.0.0.1`, `root@::1`, and `pma@localhost`; `SHOW GRANTS` gives `root@localhost` `ALL PRIVILEGES … WITH GRANT OPTION`. A working least-privilege account exists (`tims_app@127.0.0.1`, DML + DDL on `tims_barmm`) but is not used by this installation.
Impact: any local process, misconfigured script, or application-level code-execution defect becomes full database control. The blank-password `pma` control account weakens phpMyAdmin isolation too.

**C-3 No production HTTPS.**
Evidence: 443 listens, but the certificate is XAMPP's default self-signed `www.example.com` (2013); `GET http://…/TIMS-BARMM/public/login` returns **200** (no HTTPS redirect); the login response sets `tims-barmm-session` **without `Secure`** (only `HttpOnly; SameSite=Lax`); `SESSION_SECURE_COOKIE` is unset.
Impact: exposing over port 80 would transmit passwords, session cookies, beneficiary data, and sync tokens in clear text; the default certificate would train users to click through TLS warnings.

### High

**H-1 XAMPP administrative pages are reachable.**
Evidence: `GET /dashboard/` → 200 (from the host); **`GET /dashboard/phpinfo.php` → 200 (87 KB `phpinfo`)**; the `dashboard` directory has no `Require` restriction in `httpd-xampp.conf`. `/server-status` and `/server-info` returned 200 when requested through the machine's own LAN address (`Require local` treats the machine's own addresses as local, so this does not prove remote access; it does prove the endpoints are enabled). `phpinfo` discloses PHP build/configuration, extensions, paths, and environment; on a forwarded port this is a direct aid to exploitation.

**H-2 Internal project material is web-served.**
Evidence (HTTP 200): `/TIMS-BARMM/scripts/setup-offline.ps1` (38 KB), `/TIMS-BARMM/graphify-out/graph.json` (12.7 MB, full code graph), `/…/graphify-out/` report, `/TIMS-BARMM/.planning/codebase/ARCHITECTURE.md`, `/TIMS-BARMM/.opencode/gsd-core/bin/gsd-tools.cjs`, `/TIMS-BARMM/TIMS_BARMM_modification_specification.md`. A scan for literal secret values (`APP_KEY=base64:…`, `DB_PASSWORD=<value>`, `tims_sync_<token>`) found none in these paths, but they disclose architecture, file inventory, and security design.

**H-3 Stored XSS in the beneficiary detail view.**
Evidence: `resources/views/beneficiaries/show.blade.php` line 135–138 renders `{!! $value !!}` for a `$fields` array that includes raw model attributes (address, names, notes, contact numbers, etc.). Input validation only bounds length (`StoreBeneficiaryRequest`), so an authenticated encoder/staff account can store markup that executes in an administrator's browser.
Impact: session theft/actions as an administrator. Note that `docs/security-checklist.md` claims “no `{!! !!}` of user data in beneficiary/import views” — the claim is stale.

**H-4 No browser security headers and version disclosure.**
Evidence: login response headers contain only `Server: Apache/2.4.58 (Win64) OpenSSL/3.1.3 PHP/8.2.12`, `X-Powered-By: PHP/8.2.12`, `Cache-Control`, and cookies. No `Strict-Transport-Security`, `Content-Security-Policy`, `X-Frame-Options`, `X-Content-Type-Options`, `Referrer-Policy`, or `Permissions-Policy`. No middleware adds them (`grep` for header/HSTS/CSP found none).

**H-5 Backup and restart posture is not production-grade.**
Evidence: no central scheduled backup was found (`tims:backup` exists but no schedule in `routes/console.php`); the documented backup uses `mysqldump -u root` with an empty password, writes unencrypted dumps, and has no verified off-machine copy or restore drill; `sc query Apache2.4/MySQL` shows **XAMPP is not installed as a service**, so nothing restarts Apache/MySQL after reboot or crash; the power plan permits sleep.
Impact: data loss and prolonged outage on a public service.

**H-6 No monitoring or alerting.**
Evidence: no uptime/disk/certificate/backup/auth-anomaly checks in the repository or host configuration; mail is `MAIL_MAILER=log`; logs are a single `laravel.log` at `debug` level with no rotation. The app has no sync-health monitoring screen beyond per-server timestamps.

**H-7 Windows host controls are unverified.**
Evidence: the confined inspection session was denied Windows Firewall state, Defender status (`Get-MpComputerStatus` access denied), Windows Update history, scheduled tasks, and service enumeration. Whether inbound 80/443 are open, whether Defender real-time protection and updates are healthy, and whether backups are scheduled remain **unproven**.

**H-8 Internet path is unverified.**
The machine's egress public address was observed (and differs from its LAN address), but whether the ISP provides a dedicated public IPv4, whether CGNAT is in use, whether inbound 80/443 are permitted, whether the IP is static or dynamic, whether a domain/DDNS exists, and whether the router supports port forwarding are all **outside this session** and are hard blockers to any exposure.

**H-9 Filesystem permissions are too broad for a server.**
Evidence: `icacls .env` shows `NT AUTHORITY\Authenticated Users:(M)` (modify) and `BUILTIN\Users:(RX)` inherited from `htdocs`; the whole web tree is writable by Authenticated Users. (The `S-1-4-…` grant and `Low Mandatory Level` label are artefacts of the agent sandbox used for this review, not project configuration; re-check with an administrator.) On a shared Windows host this allows tampering with application code or `.env` by any logged-in local user.

### Medium

| ID | Finding | Evidence |
|---|---|---|
| M-1 | phpMyAdmin is available to local sessions with a blank `pma`/`root` password | `Require ip 127.0.0.1 ::1`, LAN probe 403, local 200; blank hashes in `mysql.user` |
| M-2 | Access to `/server-status` and `/server-info` relies on `Require local` | `httpd-info.conf`; returned 200 from the machine's own LAN address, so an external test is mandatory |
| M-3 | No MFA, no self-service password reset/change UI | No routes/controllers; documented acceptance in `docs/security-checklist.md` |
| M-4 | Session hardening incomplete for TLS deployment | `SESSION_SECURE_COOKIE` unset, `SESSION_ENCRYPT=false`, PHP `session.use_strict_mode=0`, `session.cookie_httponly/samesite` blank in `php.ini` (Laravel sets its own cookie flags) |
| M-5 | Rate limits are per-IP and proxy-unaware | `throttle:5,1` login, `throttle:10,1` enroll, `throttle:120,1` sync; no `TrustProxies` configuration, so behind Cloudflare/Nginx all clients share the proxy IP (throttling and audit IPs wrong) |
| M-6 | `.env.example` ships insecure defaults | `APP_ENV=local`, `APP_DEBUG=true`, `DB_USERNAME=root`, `LOG_LEVEL=debug` |
| M-7 | Application logs may contain personal data and never rotate | `LOG_LEVEL=debug`, single `laravel.log` (3.3 MB), no retention |
| M-8 | Audit immutability is by convention only | No update/delete routes for audits, but `tims_app` has `UPDATE/DELETE` on all tables and DBAs can edit rows |
| M-9 | Sync endpoints assume direct connections | Server tokens in headers over plain HTTP today; 120 req/min shared per IP; no IP allowlist or mutual TLS |
| M-10 | `public/robots.txt` allows indexing | `User-agent: *` / `Disallow:` (empty) |

### Low

| ID | Finding |
|---|---|
| L-1 | Apache `ServerTokens`/`ServerSignature` are default (verbose) |
| L-2 | Legacy TLS cipher allowance includes `MEDIUM`; TLS 1.0/1.1 policy should be explicit |
| L-3 | Graphify/planning artefacts are untracked but present in the web root |
| L-4 | No security.txt / vulnerability disclosure contact |
| L-5 | Some documentation is stale (security checklist references ID cards and a 70-test baseline) |

## Manual checks still required (not verifiable from this machine)

1. **ISP and public IP:** compare the router's WAN address with an external “what is my IP” result; determine CGNAT (`100.64.0.0/10`, private WAN address, or ISP statement); confirm whether inbound 80/443 are permitted and whether the address is static.
2. **Router:** model/firmware, port-forwarding capability, NAT loopback, and whether it exposes its own admin UI to the WAN.
3. **Domain/DNS:** registrar, DNS host, ability to create `A`/`AAAA`/TXT records, and whether a dynamic-DNS provider is needed.
4. **Certificate method:** HTTP-01 requires public port 80; DNS-01 does not; Cloudflare Tunnel avoids forwarding entirely. Choose after items 1–3.
5. **Windows Firewall:** profiles (Domain/Private/Public), inbound rules for 80/443, outbound policy, and whether `mysqld` (3306) is blocked inbound.
6. **Defender/Update state:** real-time protection, signature age, reboot policy, update history.
7. **Service/auto-start:** install Apache/MySQL as services (or a supervisor) and confirm restart-on-failure.
8. **Backups:** destination, encryption, retention, and a real restore drill.
9. **External reachability:** from a mobile network (not the office LAN), test 80/443, TLS chain, redirect, and that `/dashboard/phpinfo.php`, `/phpmyadmin/`, `/server-status`, `/scripts/`, `/graphify-out/` are **not** reachable.
10. **Load/capacity:** expected concurrent users, sync fleet size, bandwidth, and disk headroom.

## Blocking issues before any public exposure

1. C-1 production configuration and error handling.
2. C-2 database least privilege and credential rotation.
3. C-3 valid TLS, HTTPS-only, secure cookies.
4. H-1 remove/deny XAMPP tools (dashboard, phpinfo, server-status/info).
5. H-2 deny or relocate internal material (`scripts/`, `graphify-out/`, `.planning/`, `.opencode/`, spec markdown).
6. H-3 fix stored XSS and add a regression test.
7. H-4 security headers and version disclosure.
8. H-5 backups, restore drill, and service auto-start/power policy.
9. H-6 monitoring/alerting and log rotation.
10. H-7/H-8 Windows firewall/Defender/Update verification and ISP/router/domain/TLS feasibility.
11. H-9 restrict filesystem ACLs (`.env`, application tree).
12. Privacy/legal review (see below), since the system stores beneficiary personal data.

## Privacy and operational notes

The system stores names, birthdates, government ID numbers, contacts, income, and location data of beneficiaries, plus an audit trail that can include beneficiary identifiers. Public hosting raises obligations under the Philippine Data Privacy Act (RA 10173) and NPC guidance: lawful basis and notice, data minimisation, retention, access control, breach notification, a designated Data Protection Officer, and vendor/processor arrangements. None of these were verifiable in the repository. The assessment does not assert compliance; it flags the absence of a documented privacy notice, retention schedule, DPO designation, and incident process as **manual prerequisites**.

## Assessment conclusion

The codebase demonstrates substantial security engineering, but the **deployment** is a development environment: debug on, database superuser with a blank password, HTTP-only local serving, XAMPP tooling exposed, no certificate, no backups/monitoring, and no verified Internet path. Public exposure must not be attempted until the Critical and High items are resolved and the manual checks pass. Proceed with `docs/public-hosting-implementation-plan.md`, which sequences the work and defines the approval gates.
