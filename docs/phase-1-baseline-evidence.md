# Phase 1 — Baseline and Inventory Evidence

**Executed:** 2026-10-25 (Asia/Manila)
**Scope:** Phase 1 only of `docs/public-hosting-implementation-plan.md`. No Phase 2–8 work was started.
**Authoritative plan:** `docs/public-hosting-implementation-plan.md`
**Companion documents:** `docs/hosting-readiness-assessment.md`, `docs/public-hosting-runbook.md`

## Safety statement

No application code, `.env`, `APP_KEY`, database grants, passwords, firewall rules, Apache/PHP settings, Windows services, ACLs, DNS, certificates, router settings, or tunnel configuration were changed. No public access, port forwarding, or ACME exposure was enabled. Existing offline/online synchronization, token expiration/reauthorization, pending operations, conflict handling, and audit records were not modified.

The only database write performed for this phase was the creation and removal of a **scratch** database used to verify the baseline dump. The production database was never restored over.

## Result at a glance

| Check | Status |
|---|---|
| Git revision recorded; no reset/commit | Completed |
| Working-tree status captured | Completed (158 entries at first capture; 159 after this evidence report was added; not committed by design) |
| Sensitive-file Git audit | Completed (no tracked secrets found) |
| Full automated test suite | Completed — **365 passed, 2,161 assertions** |
| Route inventory | Completed — **103 routes** |
| Graphify refresh | Completed — exit 0; **13,046 nodes / 24,912 edges / 998 communities** |
| Host configuration inventory | Completed for requested areas — firewall/Defender/hotfixes/services/tasks/BitLocker now captured; OS patch gap found |
| Protected baseline dump + `.env` backup | Completed on this machine; **not yet off-machine/encrypted** (`E:\backup` is local; encryption unconfirmed) |
| Scratch restore verification | Completed — 31 tables, **0 row-count mismatches**, scratch dropped |
| Owner decisions/prerequisites | Partially answered — `E:\backup` supplied (local, encryption unconfirmed); admin evidence delegated; other decisions blocking |
| Runbook reconciliation | Proposed only; runbook **not** modified |

## 1. Repository revision and working-tree status

- Branch: `main`
- HEAD: `9887785319a7586b8c5b3b147551c3b2a07a818a`
- HEAD commit date/subject: `2026-10-23 20:26:26 +0800` — `docs: map existing codebase`
- Remote: a GitHub `origin` is configured (URL omitted here)
- Working tree: **158 entries** — 39 modified, 8 deleted, 111 untracked.
- No branch was created, nothing was staged, committed, reset, or discarded. The uncommitted work from the preceding sessions remains exactly where it was.

The modified set includes application, test, configuration, route, and documentation files; the deleted set includes the retired ID-card feature; the untracked set includes new synchronisation/review features, migrations, tests, planning docs, `graphify-out/`, and the three hosting documents. Full status is reproducible with `git status --porcelain`; it is not reproduced here because it names every path.

**Baseline caveat:** the plan asks for a reproducible branch/tag. That has **not** been created because the task forbids committing existing user changes without permission. This is an open item for the owner.

## 2. Sensitive-information audit (no contents displayed)

| Check | Result |
|---|---|
| `.env` tracked by Git | **No.** Only `.env.example` is tracked. `.gitignore` line 3 ignores `.env`. |
| `.env` ever committed in history | **No** (`git log --all -- .env` → 0 commits). |
| Database dumps tracked | **No** (0 tracked `.sql`; history 0). |
| Beneficiary workbooks or PDFs tracked | **No** (0 tracked `.xlsx`, `.xls`, `.pdf`; history 0). |
| Private keys / PEM / `id_rsa` / credentials tracked | **No** matches. |
| Tracked `storage/` content | Only 10 `.gitignore` placeholder files; no logs, dumps, or private uploads tracked. |
| Secret-like strings in tracked files | `git grep` for private-key headers, AWS-looking keys, and issued sync tokens found **no matching files**. |
| Local sensitive files present but ignored | `.env` and `storage/logs/laravel.log` exist locally; both are untracked/ignored. No `.env.backup`, `.env.production`, `storage/app/backups`, or private backup directory is present. |
| Workbooks/dumps/PDFs in the repository tree (outside vendor/graphify) | None found. |

No secret value was read into this report, and no hash of `.env` is included.

## 3. Automated test baseline (reproduced now)

Command: `C:\xampp\php\php.exe artisan test` from the project root.

```
Tests:    365 passed (2161 assertions)
Duration: 522.55s
Exit code: 0
```

This reproduces the historical 365/2,161 baseline on the current working tree. It is evidence about the repository's test suite, **not** evidence of host security.

## 4. Route inventory

`php artisan route:list --json` parsed successfully:

- **Total routes: 103**
- **API routes: 20** (`api/sync/v1/...`), including compatibility, enrollment, operations, snapshots, outcomes, conflicts, audit events, heartbeat/health, prepare/reauthorize, app updates.
- Middleware distribution (largest groups):
  - 28 `web, auth, role:admin, instance.mode:online`
  - 15 `web, auth, instance.mode:offline, role:manager`
  - 10 `web, auth`
  - 10 `api, sync.server, throttle:120,1, sync.authorized:read`
  - 6 `web, auth, role:admin,staff`
  - 5 `web, auth, role:admin,manager, offline.authorized`
  - 4 `web, auth, offline.authorized`
  - 4 `api, sync.server, throttle:120,1, sync.authorized:write`
  - Remaining routes are smaller auth/admin/report/enrollment groups plus public login/compatibility/enroll.

The count and groups above are the captured inventory; the JSON can be regenerated with the same command.

## 5. Graphify refresh

Command: `python -m graphify update .` using the interpreter recorded in `graphify-out/.graphify_python`.

- **Exit code: 0**
- Result: **13,046 nodes, 24,912 edges, 998 communities**
- Outputs refreshed: `graphify-out/graph.json`, `graphify-out/graph.html`, `graphify-out/GRAPH_REPORT.md`
- Report timestamp: 2026-10-25 23:24 local
- Report freshness label: built from commit `98877853` (matches HEAD short hash), but the refresh includes the current **uncommitted** working tree.
- Warnings/limitations:
  - Parallel extraction unavailable (`WinError 5: Access is denied`); sequential fallback used.
  - 27 files unclassified (unsupported extensions/shebangs).
  - 279 code files yielded no symbols (data/generated files such as `artisan`, `bootstrap/providers.php`, generated `.cjs` helpers).
  - Community labels are stale relative to the new clustering (112 saved labels; 998 communities; 54 renamed by hub). Labels do not affect the node/edge inventory used here.

Graphify did not fail; the limitations are recorded rather than hidden.

## 6. Host configuration inventory (read-only)

### Windows

- Registry reports **Windows 10 Pro**, version **21H2**, build **22000**, UBR **2538** (build 22000 is the Windows 11 21H2 build family; the registry ProductName still says Windows 10 Pro).
- Active power scheme: **AMD Ryzen Balanced** (sleep/hibernate behaviour not verified).
- XAMPP `Apache2.4` and `MySQL` are **not installed as Windows services** (`sc query` → error 1060); service enumeration is available and found no Apache/MySQL/IIS service. Apache and MariaDB are running as processes started outside the service manager.
- **Unavailable evidence (access denied / elevation required):** Windows Firewall profiles and rules (`Get-NetFirewallProfile`, `Get-NetFirewallRule`, `netsh advfirewall`), Defender status (`Get-MpComputerStatus`), installed hotfix list (`Get-HotFix`), and scheduled-task enumeration (`Get-ScheduledTask` access denied; `schtasks` failed with “system cannot find the path specified”). This was re-confirmed on 2026-10-25 23:36 from a Low-integrity, non-elevated session.
- Disk: **10,415,222,784 bytes free** on `C:` (≈9.7 GiB) at capture time.

### Apache

- Version **Apache/2.4.58 (Win64)**, built 2023-10-18.
- Config test `httpd -t`: **Syntax OK**.
- Listeners: `0.0.0.0:80` and `0.0.0.0:443` (PID shared by the running Apache), MariaDB on `127.0.0.1:3306`.
- `DocumentRoot "C:/xampp/htdocs"`; `ServerName localhost:80`; `AllowOverride All` for htdocs and the TIMS directory.
- Included: `httpd-mpm`, `httpd-autoindex`, `httpd-languages`, `httpd-userdir`, `httpd-info`, `httpd-vhosts`, `proxy-html`, `httpd-ssl`.
- `httpd-vhosts.conf` contains **no active virtual host** (the app is served from the shared `htdocs` tree).
- Loaded modules relevant to hosting: `headers`, `rewrite`, `ssl`, `status`, `info`, **`proxy`/`proxy_ajp`**, `mpm_winnt`.
- `ServerTokens`, `ServerSignature`, and `TraceEnable` are not set (Apache defaults apply).
- The TIMS hardening block denies `.env`, `.git`, composer/package files, `artisan`, README/OFFLINE markdown, and the source/storage/database/tests/docs/config/resources/routes/app/bootstrap/node_modules directories. It does **not** deny `scripts/`, `graphify-out/`, `.planning/`, or `.opencode/`.
- TLS listener exists but uses the bundled certificate:
  - Subject/issuer: `CN = localhost`
  - Validity: **notBefore 2009-11-10, notAfter 2019-11-08 (expired)**
  - This differs from the assessment’s description (“2013 self-signed `www.example.com`”); the assessment statement needs correction, but was not edited in this phase.

### PHP

- **PHP 8.2.12 (CLI, ZTS, VC++ 2019 x64)**, loaded ini: `C:\xampp\php\php.ini`.
- Required extensions present: `pdo_mysql`, `openssl`, `mbstring`, `tokenizer`, `ctype`, `json`, `fileinfo`, `xml`, `zip`, `zlib`, `curl`.
- Security-relevant ini values:
  - `expose_php=On`
  - `display_errors=On`, `display_startup_errors=On`
  - `log_errors=On`, `error_log="C:\xampp\php\logs\php_error_log"`
  - `memory_limit=512M`, `max_execution_time=300`
  - `upload_max_filesize=40M`, `post_max_size=40M`, `file_uploads=On`
  - `allow_url_fopen=On`, `allow_url_include=Off`
  - `session.use_strict_mode=0`, `session.use_only_cookies=1`, `session.cookie_httponly`/`session.cookie_samesite` blank (Laravel sets its own cookie flags).

### MariaDB

- Version **10.4.32-MariaDB**; `bind-address=127.0.0.1`; `port=3306`; `skip-networking=0` (network stack enabled but loopback-bound); `my.ini` has no `max_connections` or `local-infile` override captured.
- Database `tims_barmm`: **31 tables**, ≈**3.0 MB**.
- Accounts (authentication material not displayed):
  - `root@localhost`, `root@127.0.0.1`, `root@::1`: **blank passwords**
  - `pma@localhost`: **blank password**
  - `tims_app@127.0.0.1`: password set; grants are `SELECT, INSERT, UPDATE, DELETE, CREATE, DROP, REFERENCES, INDEX, ALTER` on `tims_barmm.*` (not least-privilege for a web account)
- Application `.env` uses `DB_USERNAME=root` with an empty password (see §6 application flags).
- No grants or passwords were changed.

### Filesystem and application flags

- `.env` ACLs: signed-in user full control; **Authenticated Users: Modify**; **Users: Read & Execute**; Administrators/SYSTEM full control (inherited broad ACLs).
- `storage/` and `storage/logs/` share the same broad inherited ACLs.
- Sensitive paths present: `.env`, `.git`, `storage/logs/laravel.log`, `storage/app/private`, `graphify-out/`, `.planning/`, `.opencode/`, `scripts/`, `vendor/`.
- `.env` flags (no secrets displayed): `APP_ENV=local`, `APP_DEBUG=true`, `APP_KEY` set, `DB_USERNAME=root`, `DB_PASSWORD` empty, `SESSION_DRIVER=database`, `SESSION_ENCRYPT=false`, `SESSION_SECURE_COOKIE` unset, `LOG_LEVEL=debug`, `INSTANCE_MODE=online`.
- Application URL in `artisan about` points at a **private LAN HTTP address**, not a public domain (exact value omitted).

### Baseline HTTP exposure (read-only probes, pre-remediation)

| Path | Result |
|---|---|
| `/TIMS-BARMM/.env` | 403 |
| `/TIMS-BARMM/.git/config` | 403 |
| `/TIMS-BARMM/scripts/setup-offline.ps1` | 200 |
| `/TIMS-BARMM/graphify-out/graph.json` | 200 |
| `/dashboard/phpinfo.php` | 200 (localhost and via this machine’s LAN address) |
| `/phpmyadmin/` | 200 on localhost; **403 via the LAN address** (Apache `Require ip 127.0.0.1 ::1`) |
| `/server-status` | 200 on localhost and via this machine’s LAN address (`Require local` treats the machine’s own addresses as local; remote behaviour not verified) |
| `/TIMS-BARMM/public/login` | 200 |

These are baseline observations only; no remediation was attempted.

## 7. Baseline dump and scratch-restore verification

- A protected evidence folder was created **outside the repository and outside the web root**, under the user profile (`Documents\TIMS-BARMM-Ops\phase1-<timestamp>`). Its inherited ACL grants full control only to the signed-in user, Administrators, and SYSTEM.
- Database dump: `mysqldump` 10.4.32 with `--single-transaction --routines --triggers --result-file`
  - **572,925 bytes**
  - SHA-256: `01F04A0C785C4C052EED12D31D2C85485EB8CE70D06386328E4AB85CB852FB16`
- `.env` backup copied to the same protected folder: **1,918 bytes**. Contents were never displayed.
- Scratch restore: created `tims_barmm_phase1_scratch`, restored the dump, then compared **31 tables** against production:
  - **31 tables restored**
  - **0 row-count mismatches**
  - Scratch database dropped successfully (`scratch_remaining=0`)
- Production `tims_barmm` was **not** dropped, overwritten, or restored to. No production row was changed.

**Gaps against the plan’s Phase 1 exit criteria:** the dump and `.env` backup are on the **same machine**, are **not encrypted**, and are **not off-machine**. A separate encrypted off-machine copy remains outstanding.

## 8. Failed, unavailable, or not-performed checks

| Item | Status |
|---|---|
| Windows Firewall profiles/rules | **Collected by owner (elevated), 2026-10-26** — Domain/Private/Public profiles enabled; all default actions `NotConfigured`; **zero enabled inbound rules matching 80/443/3306** |
| Windows Defender status | **Collected by owner (elevated)** — AM service enabled, real-time protection enabled, signatures `1.461.199.0` updated 2026-10-25 17:30 |
| Installed hotfix/update list | **Collected by owner (elevated)** — newest installed update is **2024-10-20**; OS is 21H2/build 22000 (see §12 for the support implication) |
| Scheduled tasks | **Collected by owner (elevated)** — **no TIMS/tims scheduled tasks exist** |
| Service enumeration | **Available** — no Apache/MySQL/IIS/cloudflared Windows services found; listeners confirm Apache (80/443) and MariaDB (127.0.0.1:3306) run outside the service manager |
| External/public reachability, ISP/NAT, router, DNS, public IP | **Not performed** — outside this host; owner action required |
| External TLS scan / remote-host exposure probes | **Not performed** — requires exposure or an external vantage point; not authorised |
| Off-machine encrypted copy of the dump/`.env` | **Not performed** — `E:` is a fixed local drive, BitLocker protection is **off**, and no copy was made beyond the ACL-protected local folder (see §11–§12) |
| Git branch/tag creation and commit of pending work | **Not performed** — no permission to commit; owner decision |
| Runbook modification | **Not performed** — proposals only (§9) |

## 9. Companion-document discrepancies (proposed changes only)

The plan is authoritative. The runbook was **not** modified. Proposed reconciliation items:

1. **Firewall timing/scope:** the runbook creates inbound allow rules for 80/443 in the host-hardening section. The plan requires LAN-scoped rules in Phase 3 and Internet-permitting rules only in Phase 7.
2. **HSTS timing:** the runbook’s TLS vhost snippet sets `Strict-Transport-Security` immediately. The plan requires HSTS only after HTTPS is stable and verified.
3. **HTTP-01 exposure caveat:** the runbook lists HTTP-01 as a normal option; the plan makes DNS-01 or a tunnel the pre-exposure default, with HTTP-01 requiring the same written approval as Phase 7 and a time-boxed ACME-only window.
4. **Backup/restore credentials:** the runbook’s restore drill uses `mysql -u root -p`; the plan requires the administrative/backup account and a scratch-first restore. The runbook’s backup snippet already uses a defaults file and absolute `mysqldump`, but the plan additionally requires streaming/`--result-file`, explicit retention semantics, and a visible failure on bad credentials.
5. **Port table:** the runbook presents port 80 as expected for ACME/redirect; the plan allows 443-only exposure when DNS-01/tunnel is used.

Assessment discrepancy found during inventory: the assessment’s TLS description (2013, `www.example.com`) does not match the observed certificate (`CN=localhost`, expired 2019-11-08). Recommended: correct the assessment in a later documentation pass; it was not edited now.

## 10. Owner decisions and prerequisites

**Responses received 2026-10-25 23:37:** backup destination `E:\backup` (inspected in §11; fixed local drive, encryption unconfirmed, no copy made); the owner will run the read-only Administrator evidence commands and return the output.

Still unanswered:

1. Public IP status: dedicated static vs dynamic; CGNAT or carrier NAT? Compare router WAN with external egress.
2. ISP permission/policy for inbound 80/443.
3. Domain name, DNS provider, and who controls DNS credentials.
4. Exposure method and certificate validation: DNS-01, tunnel/edge, or an explicitly approved temporary HTTP-01 exposure.
5. Reachability scope: worldwide vs office/VPN allowlist.
6. Backup destination **off-machine**, encryption method/password handling, RPO/RTO, and backup-account strategy. `E:\backup` is a fixed local drive, not off-machine; it cannot satisfy the exit criterion without an additional off-machine copy and a confirmed encryption method.
7. Privacy basis, notice, retention schedule, DPO designation, and breach contacts.
8. CSP enforcement path: nonce/hash + inline-script refactor, or a documented weaker policy accepted as residual risk.
9. Approval to commit the existing working tree or create a branch/tag for reproducibility.
10. Approval to implement Phase 2, and separately any Phase 7 public exposure.

## 11. Gap-closure addendum — 2026-10-25 23:36

### 11.1 Git re-verification

- HEAD remains `9887785319a7586b8c5b3b147551c3b2a07a818a` on `main`.
- Working tree now reports **159 entries**: 39 modified, 8 deleted, 112 untracked. The extra untracked entry versus the previous report is this Phase 1 evidence document.
- No branch, tag, staging, commit, reset, stash, or discard was performed. No application or configuration working-tree file was altered by this gap-closure review; only this evidence report was updated.

### 11.2 Administrator evidence re-check

- Current session: **Low Mandatory Level**; `net session` returns “System error 5” (access denied). No Administrator token is available.
- Re-confirmed unavailable from this session: Windows Firewall profiles/rules (`Get-NetFirewallProfile`), Defender status (`Get-MpComputerStatus`), installed hotfixes (`Get-HotFix`), scheduled tasks (`Get-ScheduledTask`/`schtasks`, task directory access denied).
- **Service enumeration is available:** no Apache/MySQL/IIS Windows services exist. Apache listens on 80/443 and MariaDB on `127.0.0.1:3306`, both as processes outside the service manager. This corrects the earlier “service enumeration unavailable” note.
- The owner chose to run the read-only commands in an elevated session and return the output. Commands provided (no changes, read-only):

```powershell
Get-NetFirewallProfile | Select-Object Name, Enabled, DefaultInboundAction, DefaultOutboundAction
Get-NetFirewallRule -Direction Inbound -Enabled True |
  ForEach-Object {
    $pf = $_ | Get-NetFirewallPortFilter
    if ($pf.LocalPort -match '^(80|443|3306)$') {
      [pscustomobject]@{
        Rule = $_.DisplayName; Action = $_.Action; Profile = $_.Profile
        LocalPort = ($pf.LocalPort -join ','); RemoteAddress = ((($_ | Get-NetFirewallAddressFilter).RemoteAddress) -join ',')
      }
    }
  }
Get-MpComputerStatus | Select-Object AMServiceEnabled, RealTimeProtectionEnabled, AntivirusSignatureVersion, AntivirusSignatureLastUpdated
Get-HotFix | Sort-Object InstalledOn -Descending | Select-Object -First 20 HotFixID, Description, InstalledOn
Get-Service | Where-Object { $_.Name -match 'apache|mysql|maria|w3svc' } | Select-Object Name, Status, StartType
Get-ScheduledTask | Where-Object { $_.TaskName -match 'TIMS|tims' } | Select-Object TaskName, TaskPath, State
manage-bde -status E:
```

Record the output outside the repository; it contains no application secrets.

### 11.3 Owner-supplied backup destination inspection

- `E:\` exists and `fsutil fsinfo drivetype E:` reports **Fixed Drive**. `E:\backup` exists (directory).
- BitLocker status could not be read without elevation (`manage-bde -status E:` denied). Encryption of this destination is therefore **unconfirmed**.
- `E:\backup` is **not off-machine** (fixed local volume), and no copy was made to it. No sensitive data was uploaded or copied to an unapproved destination.
- The existing dump and `.env` backup remain in the protected folder outside the repository and web root:
  - `db-baseline.sql` — 572,925 bytes; SHA-256 still matches `01F04A0C785C4C052EED12D31D2C85485EB8CE70D06386328E4AB85CB852FB16`.
  - `env-baseline.bak` — 1,918 bytes; contents not displayed.
  - Folder ACL remains restricted to the signed-in user, Administrators, and SYSTEM (full control only).
- These files remain **on-machine and unencrypted**; the off-machine encrypted copy is still outstanding.

### 11.4 Gap-closure status

| Item | Result |
|---|---|
| Git revision/status re-verified without changes | Completed |
| Administrator evidence collected | **Completed by owner** 2026-10-26 (firewall, Defender, hotfixes, services, tasks, BitLocker) |
| Service evidence | Completed — no Apache/MySQL/IIS/cloudflared services; listeners confirm running processes |
| Backup files still present and protected | Completed — presence, sizes, dump hash, and ACL verified |
| Off-machine encrypted backup | **Not met** — `E:` is a fixed local drive; BitLocker is fully decrypted/protection off; no copy made |
| Owner questionnaire | Partially answered (destination and admin evidence); other decisions outstanding |
| Runbook corrections | Proposed only; runbook unchanged |

## 12. Administrator evidence received — 2026-10-26

The owner ran the read-only elevated commands and returned the output. Results:

### 12.1 Firewall

- Domain, Private, and Public profiles are all **enabled**.
- `DefaultInboundAction` and `DefaultOutboundAction` are **`NotConfigured`** for all three profiles (Windows defaults apply).
- **No enabled inbound rules matched ports 80, 443, or 3306.** This means port forwarding would require an explicit inbound rule before it could work; it also means the current LAN reachability of Apache needs a separate check from another device (the app was reachable locally).
- Implication: a tunnel that makes outbound connections needs no inbound rule; port forwarding needs a deliberate 443 (and optionally 80) rule.

### 12.2 Defender and updates

- Defender AM service and **real-time protection are enabled**; signature `1.461.199.0`, updated 2026-10-25 17:30.
- **Update gap:** the newest installed hotfix is dated **2024-10-20**. The OS is **Windows 10 Pro / 21H2 / build 22000.2538**, which is an out-of-support 21H2 build. Public exposure on an unpatched, end-of-support OS is a material security risk and should be treated as a blocker unless the host is updated or the risk is explicitly accepted in writing.

### 12.3 Services and scheduled tasks

- **No** Apache, MySQL, IIS, or cloudflared Windows services exist.
- **No** TIMS/tims scheduled tasks exist.
- Apache/MariaDB remain process-managed (XAMPP), and cloudflared is not installed as a service.

### 12.4 BitLocker / backup destination

- `E:` is labeled `[STEAM]`, 237.84 GB, **fully decrypted**, **protection off**, no key protectors.
- Therefore the pre-change backup on `E:` is **not encrypted at rest**, and `E:` is not off-machine. The ACL restriction protects it from other local accounts, not from drive removal, theft, or drive failure.

### 12.5 New/changed blockers from this evidence

1. **OS patch level / support:** 21H2 build 22000 with updates last installed 2024-10-20. Update before public exposure or document an explicit risk acceptance.
2. **Backup:** `E:` is unencrypted, local, and labeled as a game drive. It is suitable only as a temporary local copy. An encrypted off-machine destination and a verified restore remain required.
3. **Firewall:** no inbound rules for 80/443/3306. Port forwarding is not currently permitted; a tunnel avoids this. Either way, firewall rules must be deliberate.
4. **Services/tasks:** Apache/MySQL/cloudflared are not services; no backup/sync tasks exist on the central host. Reliability and backup automation remain outstanding.
5. **Admin evidence is now complete** for the requested areas; the remaining Phase 1 gaps are owner decisions/permissions, OS patching, and an off-machine encrypted backup.

## Phase 1 exit criteria assessment

| Plan exit criterion | Result |
|---|---|
| Reproducible revision exists | **Not met** — HEAD recorded, but the working tree has 159 uncommitted entries and no branch/tag was created (commit requires permission) |
| Baseline test and route counts recorded | **Met** — 365/2,161; 103 routes |
| Restore-tested dump and environment archive stored off-machine | **Not met** — dump and `.env` backup are protected and restore-tested, but on-machine and unencrypted; `E:` is local with BitLocker off |
| ISP/router/domain questionnaire returned | **Not met** — owner input outstanding |
| Host configuration evidence | **Met for requested areas** — firewall/Defender/hotfixes/services/tasks/BitLocker now captured; OS patch gap is a new finding |
| Runbook reconciliation | **Proposed, not accepted** |

**Conclusion: Phase 1 is partially complete and its exit criteria are not fully satisfied.** The missing items are owner decisions/permissions and an off-machine encrypted backup destination, not failed application tests. No Phase 2 work was started. Awaiting review and explicit approval before proceeding.
