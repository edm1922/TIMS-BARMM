# Public Hosting Implementation Plan — TIMS-BARMM

**Status:** Planning only. Nothing in this document has been executed.
**Companion documents:** `docs/hosting-readiness-assessment.md` (findings, evidence), `docs/public-hosting-runbook.md` (procedures).
**Target:** serve the central installation from this Windows PC through XAMPP, a domain (or dynamic DNS), HTTPS, and router port forwarding — without weakening the offline/online synchronization design.

> **Approval gate:** no step in Phase 7 may be performed until the owner explicitly approves after Phases 1–6 pass. Until then the server must not be reachable from the Internet. “Reachable” includes router port forwarding, a DNS/DDNS record pointing at the host, a tunnel/edge route, and any temporary ACME-only exposure — each requires the same written approval.

## Ground rules

- Security-first order: fix the application and host, then certificates, then operations, then exposure.
- **Do not redesign** enrollment, authorization periods, reauthorization, request review, conflict handling, snapshots, idempotency, or audit synchronization. They are implemented and covered by 365 passing tests; public hosting must preserve them.
- Every task is tagged:
  - **[Repo]** — change inside the repository (can be reviewed and tested locally).
  - **[Host]** — change to Windows, XAMPP, MariaDB, firewall, services (needs Administrator).
  - **[Owner]** — external/manual: ISP, router, domain, DNS, certificate account, privacy decisions.
- No credentials, tokens, private keys, or public IPs are written into the repository.
- Each phase ends at a gate. Do not start the next phase while a gate is open.

## Review revision (2026-10-25)

This plan was reviewed against the assessment and the source code. The eight phases, findings, and approval gates are unchanged. Material corrections made:

1. Added a **staging validation rule** and **traceability matrix** (every Critical/High finding now maps to a task and acceptance criterion; staging is mandatory before disruptive production changes).
2. Split production `.env` changes across phases: HTTPS-only values (`APP_URL=https://…`, `SESSION_SECURE_COOKIE=true`, HSTS) are applied only after TLS is verified, because Phase 3 tests over HTTP and `Secure` cookies/HTTPS URLs would break it.
3. Corrected the `APP_KEY` rationale: on the central server, rotation invalidates sessions and any central-stored encrypted values, **not** each offline device's server token (each device encrypts its own token with its own key). Rotation remains discouraged.
4. Noted that `SESSION_ENCRYPT=true` logs all users out once (existing sessions cannot be decrypted); it does not affect sync tokens or authorization periods.
5. Database least privilege now explicitly covers the **backup path**: the app's `tims:backup` uses `mysqldump --routines --triggers` and the application credentials, which a DML-only account cannot satisfy; a dedicated backup account (or protected admin defaults file) and an absolute `mysqldump` path are required. phpMyAdmin's `pma` password change also requires `config.inc.php` alignment.
6. Certificate issuance ordering corrected: HTTP-01 needs inbound port 80, which conflicts with the “no exposure before Phase 7” gate. Pre-exposure issuance must use **DNS-01 or a tunnel/edge**; HTTP-01 is only allowed if the owner explicitly approves a temporary public exposure.
7. CSP proposal revised to a mandatory **Report-Only** rollout, with the measured inline-script inventory (8 inline script blocks in 6 views, 31 inline event handlers, 26 inline `style=` attributes). Enforcement requires either nonce/hash + handler refactor or a documented, weaker policy; “`unsafe-inline`” is not presented as protection.
8. Added path-move preflight and post-move verification for installation-package generation, backups, scheduled tasks, and sync; `scripts/` must stay in place (it is packaged) while only non-packaged internal folders are relocated.
9. Firewall ordering clarified: LAN-scoped rules in Phase 3, Internet-permitting rules only in Phase 7. Added service-account/port-conflict checks for installing Apache/MySQL as services.
10. Phase 6 testing moved to a staging clone (production runs `--no-dev`, so the test suite cannot run there); external verification moved to Phase 7 where exposure actually occurs.
11. Added an explicit **proof-boundary** statement: HTTPS, green tests, and a clean external scan do not prove the system secure.
12. Added an explicit **preserved synchronization design** section reaffirming that token expiration/reauthorization are already implemented and must not be duplicated or bypassed.
13. Noted that the companion **runbook predates this revision** and must be reconciled before execution (it still creates firewall rules during host hardening, shows HSTS at initial TLS setup, lists HTTP-01 without the exposure caveat, and documents backups with the application/root credentials). The plan is authoritative; the runbook update is a Phase 1 deliverable.

## Staging validation rule

Every potentially disruptive change must be validated on a **staging clone** before it touches production:

- staging = a copy of the application on a non-public listener (for example `127.0.0.1:8080`) plus a **copy** of the database (`tims_barmm_staging`), same PHP/Apache versions, no production credentials, and a copy of `storage/` structures;
- applies to: database grants and credentials, `.env`/`APP_KEY`/session changes, configuration caching, security headers/CSP, Apache/PHP/vhost changes, Windows service installation, filesystem ACLs, file moves, certificate/HSTS changes;
- each phase's verification must include the staging result; production gets only the promotion plus a smoke test;
- the rollback procedure must be exercised on staging (restore `.env`, configs, ACLs, database grants; restart services) before promotion.

## Proof boundaries (what passing checks do **not** prove)

A trusted certificate, a green test suite, correct headers, and a clean external scan are **necessary but not sufficient**. They do not prove the absence of application logic flaws, insider misuse, dependency zero-days, social engineering, physical access, or DDoS resilience. Passing tests exercise the repository, not the production host configuration; header/scan results cover only the surface they probe. The service remains “security work in progress”: monitoring, patching, access review, incident drills, and periodic testing continue after Phase 8. Nothing in this plan should be read as a guarantee of complete security.

## Preserved synchronization design (do not redesign)

The following are implemented and covered by tests; public hosting must preserve them exactly:

- central/offline responsibilities, device enrollment, server-token authentication (`AuthenticateSyncServer`, `TokenService`);
- authorization period, expiry enforcement, reauthorization, one-time and server-bound tokens (`EnsureServerAuthorization`, `EnsureOfflineAuthorization`, `OfflineAuthorization`, `ReauthorizationService`);
- snapshot/delta sync, idempotent uploads and receipts, acknowledgments, conflict review, sync request review/decisions (`SnapshotDownloadService`, `SyncRunner`, `SyncOperationService`, `SyncRequestService`);
- audit trail and audit synchronization.

Rules:

- **No new or duplicate token mechanism.** Do not reimplement expiration, reauthorization, or request approval, and do not introduce a parallel token type.
- Do not silently extend expired authorization, bypass authorization during synchronization, or relax expiry to make hosting easier.
- None of the changes in this plan alters token lifetimes, authorization status, or sync payload formats; database, `APP_KEY`, session, TLS, and web-server work is host/configuration-only.
- Keep the relevant suites green, including `OfflineAuthorizationTest`, `SyncEnrollmentTest`, `SyncRequestReviewTest`, `SyncRequestOutcomeTest`, `SyncNowTest`, `SyncOperationTest`, `SyncConflictResolutionTest`, `SyncRunnerTest`, and `SyncSnapshotTest`.

## Traceability — Critical and High findings to tasks and acceptance

Each row's evidence is part of that phase's acceptance criteria and must be recorded in the evidence pack. Findings C-1…H-9 are defined in `docs/hosting-readiness-assessment.md`.

| Finding | Task(s) | Acceptance evidence |
|---|---|---|
| C-1 debug/development mode | Phase 2 tasks 5–6, 10 | `APP_ENV=production`, `APP_DEBUG=false`, PHP `display_errors=Off`; forced error shows no stack trace; `artisan about` confirms |
| C-2 database root/blank password | Phase 2 task 7 (+ Phase 5 backup account) | app uses DML-only `tims_web`; DDL/`GRANT` denied; root/pma rotated; backup still succeeds under its own account |
| C-3 no HTTPS/certificate | Phase 4 tasks 1–6 | trusted certificate, HTTP→HTTPS redirect, `Secure` cookies, HSTS after stability, renewal dry-run |
| H-1 XAMPP dashboard/phpinfo | Phase 2 task 8; Phase 3 task 3 | `/dashboard/`, `/phpinfo.php`, `/server-status`, `/server-info` 403/404 from a second host and externally |
| H-2 internal material served | Phase 2 task 3; Phase 3 task 2 | `/scripts/…`, `/graphify-out/…`, `/.planning/…`, `/.opencode/…`, spec markdown unreachable; files relocated/denied; probes recorded |
| H-3 stored XSS | Phase 2 task 1 | payload stored in beneficiary fields renders escaped; `{!! !!}` only in reviewed allowlist; regression test |
| H-4 headers/version disclosure | Phase 2 tasks 2, 10; Phase 4 task 6 | CSP/`X-Frame-Options`/`X-Content-Type-Options`/`Referrer-Policy` present; `Server`/`X-Powered-By` no versions; HSTS only after TLS; tests |
| H-5 backups/service auto-start | Phase 3 tasks 6–7; Phase 5 tasks 1–4, 10–11 | services auto-start and restart; encrypted off-machine backup; successful restore drill with recorded RTO; backup fails visibly on bad credentials |
| H-6 no monitoring | Phase 5 tasks 5–7 | simulated service/disk/backup/certificate/auth-alert reaches the owner; logs rotate |
| H-7 Windows controls unverified | Phase 1 task 5; Phase 3 tasks 6–8; Phase 6 verification | firewall/Defender/Update/service evidence captured; 3306 blocked; only 80/443 permitted at Phase 7 |
| H-8 Internet path unverified | Phase 4 owner questions; Phase 7 | ISP/CGNAT/static-IP/DNS/forwarding confirmed; external HTTPS verified; rollback tested |
| H-9 filesystem permissions | Phase 2 task 9 | `.env` restricted to Administrators/SYSTEM/service account; app tree read-only except `storage/`, `bootstrap/cache/` |


## Phase map and dependencies

```
1 Baseline ──▶ 2 Critical remediation ──▶ 3 Production web server ──▶ 4 Domain + HTTPS
                                                                          │
                    8 Post-deployment hardening ◀── 7 Controlled exposure ◀── 6 Regression + security validation ◀── 5 Operational readiness
```

Phase 7 additionally requires owner approval and every Phase 1–6 gate closed. Phase 8 starts only after observing the public service.

---

## Phase 1 — Baseline and inventory

**Objective / outcome.** Freeze a known-good state: current behaviour, data, configuration, and test baseline are recorded, so any regression is attributable and recoverable.

**Dependencies / prerequisites.** Read access to the repository and host; ability to run PHP CLI and the test suite.

**Tasks (in order).**

1. **[Repo]** Create a working branch and commit the pending tree (or tag it) so the pre-change state is reproducible; confirm no `.env`, dumps, keys, or beneficiary workbooks are tracked.
2. **[Repo]** Record the automated baseline: `php artisan test` (current: **365 passed, 2,161 assertions**).
3. **[Repo]** Record the route inventory: `php artisan route:list --json` (current: **103 routes**) and archive it with the assessment.
4. **[Repo]** Refresh and archive Graphify (`python -m graphify update .`) for architecture reference.
5. **[Host]** Capture current configuration evidence with Administrator rights: `httpd.conf`, `httpd-ssl.conf`, `httpd-xampp.conf`, `httpd-info.conf`, `php.ini`, `my.ini`, Windows Firewall profile/rules, Defender status, Windows Update state, service list, scheduled tasks, ACLs of `.env` and the application tree.
6. **[Host]** Record a full database dump and a copy of `.env` to an off-machine, encrypted location **before** any change; verify the dump loads into a scratch database.
7. **[Owner]** Record expected usage: concurrent users, offline server count, sync schedule, peak hours, and whether an office/VPN allowlist is acceptable.
8. **[Owner]** Answer the ISP/router/domain questionnaire (Phase 4 prerequisites) so it does not block later; no configuration yet.
9. **[Repo]** Reconcile `docs/public-hosting-runbook.md` with this plan before any execution (firewall ordering, HSTS timing, HTTP-01 exposure caveat, backup credentials/path), and record the runbook revision in the evidence pack.

**Files/config likely affected.** None. Evidence is stored outside the web root (e.g., a dated operations folder) and never committed with secrets.

**Commands/procedures.**

```powershell
php artisan test
php artisan route:list --json > <OPS_DIR>\routes-baseline.json
mysqldump -u <user> -p --single-transaction --routines --triggers tims_barmm > <OPS_DIR>\db-baseline.sql
icacls .env > <OPS_DIR>\acl-env.txt
```

**Risks and mitigations.** Branching is skipped and changes mix with existing uncommitted work → create a branch/tag before editing. Baseline dump contains personal data → store encrypted, never in the repo or web root.

**Verification.** Test baseline reproduces; dump restores into a scratch schema; evidence pack contains every configuration file requested above.

**Acceptance criteria.** Reproducible revision exists; baseline test and route counts recorded; restore-tested database dump and environment archive stored off-machine; ISP/router questionnaire returned.

**Rollback / recovery.** Restore the archived dump and `.env`; return the branch point.

**Privileges / owner action.** Administrator for configuration capture; owner for usage and ISP questionnaire.

**Exit criteria for next phase.** All baseline artefacts exist and are restorable.

---

## Phase 2 — Critical security remediation

**Objective / outcome.** Remove the issues that make public exposure unsafe even before a certificate exists: debug leakage, database superuser use, stored XSS, exposed internals, missing headers, weak ACLs, and insecure template defaults.

**Dependencies.** Phase 1.

**Tasks (in order).**

1. **[Repo]** Fix the stored XSS in `resources/views/beneficiaries/show.blade.php`: escape every model-derived value (`e($value)`), keeping the two intentional HTML fragments (the ID badge and the batch badge) explicitly built with `e()`/Blade partials. Audit the rest of `resources/views` for `{!! !!}` (only `partials/nav-icon.blade.php` should remain). Add a feature test that stores `<script>`/`onerror` payloads in beneficiary fields and asserts they are escaped on the detail page.
2. **[Repo]** Add a security-headers middleware (registered on the `web` group): `X-Content-Type-Options: nosniff`, `X-Frame-Options: DENY`, `Referrer-Policy: strict-origin-when-cross-origin`, `Permissions-Policy`, and `Content-Security-Policy-Report-Only` first. The current views were inventoried: **8 inline `<script>` blocks across 6 views, 31 inline event handlers (`onclick`/`onchange`/…), 26 inline `style=` attributes, and 7 `<script src>` tags**. A policy of `default-src 'self'` alone would break the UI. Start in Report-Only mode with a report endpoint (or log sink), collect violations, then choose: (a) move inline scripts to files and replace inline handlers with delegated listeners, then enforce a nonce/hash-based policy; or (b) enforce a documented weaker policy that permits the required inline styles/handlers (this is *not* real XSS protection and must be recorded as a residual risk). `Strict-Transport-Security` is added **only** in the HTTPS cut-over (Phase 4), not before. Add tests asserting the headers and that the policy is Report-Only until the enforcement decision is recorded. Add `mod_headers` to the Apache module checklist (needed for HSTS/header directives).
3. **[Repo]** Deny internal material at the web layer: update the root `.htaccess` and the Apache hardening block to include `scripts`, `graphify-out`, `.planning`, `.opencode`, `node_modules`, and `*.md`/`*.ps1` where appropriate. **Additionally**, relocate `graphify-out/`, `.planning/`, `.opencode/`, and the specification markdown outside the web root — defence in depth, since a virtual-host change must not re-expose them. **Do not relocate `scripts/`**: `InstallationPackageService` reads `base_path('scripts/setup-offline.ps1')` and packages the folder, so moving it breaks installation-package generation; keep it in place and deny it at the web layer. Web-layer denial is HTTP-only; the package builder reads the filesystem, so blocking (rather than moving) internal folders cannot break package generation. Before moving anything, record every path-dependent consumer (Apache vhost, scheduled tasks, operations scripts) — see Phase 3 task 1.
4. **[Repo]** Set `public/robots.txt` to disallow all crawling.
5. **[Repo]** Create a production environment template (e.g., `docs/` snippet or `.env.production.example`) with `APP_ENV=production`, `APP_DEBUG=false`, `LOG_LEVEL=warning`, `SESSION_ENCRYPT=true`, `SESSION_SAME_SITE=lax`, and a **non-root** database user placeholder. **Do not put `APP_URL=https://…` or `SESSION_SECURE_COOKIE=true` in this phase**: Phase 3 tests over HTTP, where these values break login and redirects. They are applied in Phase 4 after TLS is verified. Keep `.env.example` for local development but label it clearly.
6. **[Host]** Apply only the non-HTTPS production values in this phase (after backing up `.env`): `APP_ENV=production`, `APP_DEBUG=false`, `LOG_LEVEL=warning`, `SESSION_ENCRYPT=true` (scheduled at a low-traffic moment; it logs everyone out once), and the database credentials from task 7; run `php artisan config:clear` then `config:cache`, and verify `artisan about`. Leave `APP_URL` on the current HTTP address for now (Phase 3 sets the staging scheme, Phase 4 the HTTPS one) and leave `SESSION_SECURE_COOKIE` unset until Phase 4. Clear `route`/`view` caches before testing so the XSS/header changes are actually loaded.
   - **Sessions:** setting `SESSION_ENCRYPT=true` makes every existing session unreadable, so all users are logged out once. Schedule it for a low-traffic moment and announce it. It does not affect sync server tokens or authorization periods, because authorization is derived from server/setting records (`offline_servers`, `sync_settings`), not from session contents.
   - **`APP_KEY`:** do **not** rotate it on the live central. On the central, rotation invalidates sessions, encrypted cookies, and any values encrypted *on the central*; it does **not** invalidate each offline device's stored server token, because every device encrypts its own token with its own key. If a rotation is ever required, first check `sync_settings` for encrypted keys (assessment-time inspection of the central found no rows there, but re-check before acting), assume all users must sign in again, and document re-enrollment only for devices whose own key changes.
7. **[Host]** Database least privilege:
   - Create a dedicated account (e.g., `tims_web@127.0.0.1`) with a strong random password and `SELECT, INSERT, UPDATE, DELETE` on `tims_barmm.*` only.
   - Keep a separate migration/administrator account; the web account must not have `CREATE/DROP/ALTER`. Run migrations through a one-off shell environment (`DB_USERNAME`/`DB_PASSWORD` set only for that command) or a protected admin env file — never by permanently switching production to the admin account.
   - Set strong passwords for `root@localhost`, `root@127.0.0.1`, `root@::1`, and the `pma` control user; remove anonymous/`'%'` accounts. Update `phpMyAdmin/config.inc.php` to match the new `pma` password (or disable phpMyAdmin) — otherwise the local tool breaks.
   - **Verify the normal workflows under `tims_web`:** login/session, beneficiary CRUD/archive/delete, batch management, import upload → preview → apply, duplicate resolution, reports and CSV/XLSX export, audit log, sync pages, snapshot/delta download, operation upload, request review, conflict resolution, queue/cache/session writes. The code performs no runtime DDL (`DB::statement`/`Schema::`/`TRUNCATE` only appear in migrations), so DML-only should suffice — confirm this on staging.
   - **Foreign-key writes:** MariaDB can require extra privileges for writes that reference other tables (`REFERENCES`). If staging shows a privilege error on a normal workflow, grant the minimum specific privilege and re-test; never grant `CREATE`/`ALTER`/`DROP` to `tims_web` to make an error disappear.
   - **Backups are a separate path:** `tims:backup` uses the application credentials with `mysqldump --routines --triggers` (and resolves `mysqldump` from `PATH`). A DML-only account is not guaranteed to satisfy routine/trigger dumping, and Task Scheduler may not have `mysqldump` on `PATH`. Create a dedicated backup account with the minimum dump privileges, or use a protected defaults file with the administrative account; update `tims:backup` to call the absolute `mysqldump` path. Validate by running the backup under the new credentials on staging (see Phase 5).
8. **[Host]** Restrict XAMPP administration: deny `/dashboard`, `/dashboard/phpinfo.php`, `/server-status`, `/server-info`, `/phpmyadmin`, `/licenses`, `/webalizer`, `/xampp` on the public listener (or move them to a loopback-only listener). Remove the XAMPP welcome dashboard from the document root if possible.
9. **[Host]** Restrict filesystem ACLs **in two steps**, because the final Apache service account is not known until Phase 3:
   - **Interim (this phase):** restrict `.env` to Administrators, SYSTEM, and the **current** Apache process identity (the account running XAMPP now). Do not yet remove inherited `Authenticated Users` ACLs from `htdocs`; XAMPP is running as an interactive user and a wrong change locks the application out.
   - **Final (Phase 3 task 6, after service installation):** determine the actual service account (LocalSystem by default), grant the application tree read-only and `storage/`/`bootstrap/cache/` modify, and then remove the broad inherited ACLs. Test on a staging copy first; keep an Administrator rollback window.
10. **[Host]** Reduce verbosity: `ServerTokens Prod`, `ServerSignature Off`, `expose_php=Off`, `display_errors=Off`, `display_startup_errors=Off`, `session.use_strict_mode=1`, and explicit TLS protocol policy (`SSLProtocol all -SSLv3 -TLSv1 -TLSv1.1`).
11. **[Repo]** Update `docs/security-checklist.md` so it matches reality (remove the stale ID-card and `{!! !!}` claims, correct the test baseline), and add the new controls to it.
12. **[Repo]** Add regression tests for the added header middleware and the XSS fix; run the full suite.

**Files/config likely affected.** `resources/views/beneficiaries/show.blade.php`, `app/Http/Middleware/` (new), `bootstrap/app.php`, `routes/web.php` (only if a middleware alias is needed), `public/robots.txt`, `.htaccess`, `.env.production.example`/docs, `docs/security-checklist.md`, tests. Host: `.env`, `httpd*.conf`, `php.ini`, MariaDB grants, Windows ACLs.

**Risks and mitigations.**
- CSP breaks inline JavaScript or Bootstrap components → report-only first; measured inventory (8 inline blocks, 31 inline handlers, 26 inline styles); do not claim enforcement protection until the inline code is refactored or the weaker policy is recorded as residual risk.
- Database account switch breaks migrations/imports/backup → test every workflow on staging; keep the admin account for migrations; give backups their own account or protected defaults file. **Any existing backup schedule that uses the application credentials will start failing when the account changes — pause or repoint it before the switch.**
- `APP_KEY` rotation ends all sessions and any central-encrypted values → do not rotate; if unavoidable, plan re-login and inspect `sync_settings` first.
- `SESSION_ENCRYPT=true` logs everyone out once → schedule and announce it; it does not change authorization or tokens.
- ACL changes can lock Apache out of `storage/` → apply to a staging copy first, grant to the **actual** service account, keep an Administrator rollback window.

**Staging checkpoint.** Apply the entire phase to the staging clone and staging database first; run the full suite there, exercise the workflows above, and rehearse the rollback before touching production.

**Verification (automated and manual).**
- `php artisan test` green on staging, including new XSS/header tests.
- Error pages no longer reveal stack traces (`APP_DEBUG=false`); confirm by forcing a 500 in the staging copy.
- Repo scans: `{!! !!}` only in the reviewed allowlist; no `env(` usage outside `config/`.
- HTTP probes: `/TIMS-BARMM/public/.env` 403, `/scripts/…` 403/404, `/graphify-out/…` 403/404, `/dashboard/phpinfo.php` 403, `/phpmyadmin/` 403, `/server-status` 403 from a second host.
- `mysql -u tims_web` cannot `DROP`, `GRANT`, or read other schemas; the normal workflows and `tims:backup` succeed under the chosen accounts.
- `composer audit` reports no advisories (point-in-time only; not proof of absence).

**Acceptance criteria.** C-1, C-2, H-1, H-2, H-3, H-4, H-9 from the assessment are closed; the full test suite passes on staging; no page displays debug output; sensitive paths return 403/404; the application works end-to-end with the least-privilege DB user; backups succeed under their dedicated account; XAMPP tools are unreachable on the public listener.

**Rollback / recovery.** Restore `.env`, `httpd*.conf`, `php.ini`, and the database grants from the Phase 1 archive; revert the repository branch or cherry-pick the XSS/header commits; restart Apache/MySQL.

**Privileges / owner action.** Administrator for host changes; owner approval for the database credential change and for removing the XAMPP dashboard.

**Gate to Phase 3.** All above verified in a staging copy; owner signs off the change set.

---

## Phase 3 — Production web-server preparation

**Objective / outcome.** Serve the application from a dedicated virtual host whose document root is only Laravel's `public/`, with production PHP settings, and a host that restarts predictably.

**Dependencies.** Phase 2 (code and environment clean).

**Tasks (in order).**

1. **[Host]** Decide the app location. Recommended: move the project to a non-web path (e.g., `C:\sites\TIMS-BARMM`) with the document root at its `public/`. If it stays under `htdocs`, the virtual host must still point only at `public/`.
   - **Pre-move inventory (mandatory):** list every path consumer before moving — Apache vhost/DocumentRoot, Windows scheduled tasks (`tims:sync`, `tims:backup`), operations/backup scripts, the `.env` `APP_URL`, and anything under `scripts/`. `InstallationPackageService` uses `base_path()`/`storage_path()` and is path-portable, but the package contains `scripts/` and must be **built and inspected after the move**. Do not move `docs/`, `scripts/`, or `storage/` outside the app root.
2. **[Host]** Add a dedicated virtual host for `<PUBLIC_DOMAIN>` (HTTP first): `DocumentRoot` = `…\public`, `<Directory>` with `Options -Indexes -Includes +FollowSymLinks`, `AllowOverride All` **or** `AllowOverride None` plus an explicit rewrite/`FallbackResource /index.php`; deny `.env`, `.git`, `*.ps1`, `*.md`, and the paths from Phase 2. Ensure `mod_headers` is loaded (needed for HSTS/header directives) and `mod_rewrite` is available.
3. **[Host]** Ensure the default XAMPP site (`htdocs`) and administrative aliases are **not** reachable on the public listener; keep localhost-only administration on a separate listener (e.g., 8080 bound to 127.0.0.1) if needed.
4. **[Host]** Tune PHP for production: `display_errors=Off`, `log_errors=On`, `expose_php=Off`, `memory_limit` sized to imports (current 512M), `upload_max_filesize`/`post_max_size` consistent with the 10 MB import cap, `max_execution_time` appropriate to snapshot/export operations (300 s currently — keep, or move heavy work to queues later), `session.use_strict_mode=1`.
5. **[Repo]** Run production caches in the deployment procedure: `php artisan config:cache`, `route:cache`, `view:cache`; `composer install --no-dev --optimize-autoloader` (a build step; no code change). **`--no-dev` removes PHPUnit**, so the full test suite must run on staging (which keeps dev dependencies), not on the production host; production gets only smoke tests.
6. **[Host]** Install Apache and MariaDB as Windows services (e.g., `httpd -k install` with the XAMPP config, `mysqld --install`), set automatic start, restart-on-failure, and dependencies (Apache after MySQL). If service installation is undesirable, use a supervisor (NSSM) with the same guarantees. Configure restart-on-failure explicitly, for example
   `sc.exe failure Apache2.4 reset= 86400 actions= restart/5000/restart/10000/restart/30000` and the same for MySQL.
   - **Ordering:** stop the XAMPP control-panel instances first; verify ports 80/443/3306 are free (`netstat -ano | findstr ":80 :443 :3306"`); stop IIS/World Wide Web Publishing Service if it owns port 80; install MySQL first, then Apache with `sc.exe config Apache2.4 depend= MySQL`; identify the service account the service actually runs as (LocalSystem by default), **then finalize the Phase 2 ACLs for that account** (application read-only; `storage/`/`bootstrap/cache/` modify); start and verify, then reboot-test. Also verify the MariaDB service account can read its data directory (`C:\xampp\mysql\data`); if the service cannot start, grant that account the necessary access rather than running MySQL as an interactive user. Keep the previous XAMPP-mode ACLs in the Phase 1 archive for rollback.
7. **[Host]** Power and update policy: high-performance power plan, sleep/hibernate disabled (or wake timers), BIOS “restore on AC loss”, automatic Windows Update with a maintenance window, Defender real-time protection on with exclusions limited to `storage/` (not the code tree).
8. **[Host]** Windows Firewall baseline: create **LAN-scoped** rules for 80/443 during staging (`-RemoteAddress LocalSubnet` or Private profile only), explicitly block 3306 inbound, keep RDP/SMB closed, review existing rules, and remove stale 80/443/3306 rules. Internet-permitting rules are added only in Phase 7.
9. **[Repo]** Add a short deployment note to `docs/setup-xampp.md` describing the production vhost and service model (documentation only).

**Files/config likely affected.** `C:\xampp\apache\conf\extra\httpd-vhosts.conf`, `httpd.conf`, `php.ini`, Windows services/firewall/power settings, `docs/setup-xampp.md`.

**Risks and mitigations.**
- Moving the app breaks `APP_URL`, scheduled tasks, operations scripts, or the installation-package builder → complete the pre-move inventory; after the move rebuild a package and verify it contains `scripts/`, a valid `SHA256SUMS.txt`, and `INSTALL.txt`; run a backup; run a sync smoke test.
- Enabling `config:cache` while an `env()` call exists outside config would break — none were found, but run the full suite on staging and a smoke test.
- Apache service install can conflict with the XAMPP control panel or another listener on 80/443 → stop panel instances, check `netstat`, install MySQL before Apache, set the dependency, and identify the service account before ACLs.
- LAN testing with firewall rules scoped to the LAN can accidentally be broadened → keep the Phase 7 gate for Internet-permitting rules.

**Staging checkpoint.** Perform the move, vhost, PHP, and service changes on the staging copy/host profile first; promote only after the package/backup/sync checks below pass.

**Verification.**
- `httpd -t` passes; the dedicated vhost loads the app at `http://<PUBLIC_DOMAIN>/` (hosts-file test) and `http://<LAN_IP>/` serves nothing else.
- `php artisan about` shows production, debug off, caches warm.
- Apache/MySQL restart automatically after `Restart-Service` and after a reboot test in a maintenance window; service dependencies verified (`sc qc Apache2.4`).
- Ports 80/443/3306 listeners match expectations; `netstat -ano` shows nothing unexpected.
- Directory listing off; if HTTP-01 is the approved certificate path, `.well-known/acme-challenge` is reachable on the staging listener (DNS-01/tunnel needs no HTTP path).
- After the move: an installation package builds, downloads, and its archive contains `scripts/`, `SHA256SUMS.txt`, and `INSTALL.txt`; `tims:backup` runs; a staging sync (snapshot + upload) succeeds.

**Acceptance criteria.** Only `public/` is exposed for the app host; XAMPP tools unreachable externally; services auto-start; PHP/Apache production settings in effect; app smoke test passes over HTTP on the LAN; installation-package generation, backup, and sync still work after the move.

**Rollback / recovery.** Revert the vhost/PHP/service changes from the Phase 1 archive. If services were installed: stop them, `C:\xampp\apache\bin\httpd.exe -k uninstall -n "Apache2.4"` and `C:\xampp\mysql\bin\mysqld.exe --remove MySQL`, then start Apache/MySQL from the XAMPP control panel. Restore the previous ACLs. Keep the old configuration files until the new setup has run for a full observation window.

**Privileges / owner action.** Administrator; owner approval for moving the installation and installing services.

**Gate to Phase 4.** Vhost and service model accepted in a LAN test, including the post-move package/backup/sync smoke checks.

---

## Phase 4 — Domain and HTTPS preparation

**Objective / outcome.** A trusted certificate for a domain that resolves to this server, automatic renewal, HTTPS-only serving, and a verified redirect from HTTP.

**Dependencies.** Phase 3; **[Owner]** items below must be answered before any certificate order.

**Owner questions and decisions (blocking).**
1. Compare the router WAN address with the public egress address observed during this assessment. If they differ, CGNAT or a carrier NAT is in play and classic port forwarding will not work.
2. Confirm the ISP allows inbound 80/443 and whether the public IP is static; if dynamic, choose DDNS.
3. Register/choose the domain and its DNS host; decide who holds DNS credentials.
4. Choose the certificate path:
   - **HTTP-01 (win-acme with webroot):** requires inbound port 80 to the app's `.well-known/acme-challenge` directory. **This conflicts with the “no exposure before Phase 7” gate and is not part of the recommended path.** If it is used at all, it requires the same written approval as Phase 7, a time-boxed window that serves only the ACME path, and immediate reversal; record it as an exposure event.
   - **DNS-01:** works without inbound 80; the recommended pre-exposure default under CGNAT or closed ports. Requires a DNS provider API credential.
   - **Cloudflare Tunnel or reverse proxy:** terminates TLS and avoids port forwarding entirely; recommended if CGNAT or ISP restrictions block inbound ports. This changes the architecture from “forward 80/443” to “outbound tunnel”; it must be an explicit owner decision.
   - **Decision required before Phase 4 work:** DNS-01 or tunnel is the only path that satisfies “HTTPS verified before any exposure”; the HTTP-01 exception is explicitly a residual-risk choice, not the default.
5. Decide whether the service should be reachable worldwide or restricted (office IPs/VPN); an allowlist reduces exposure but breaks roaming encoders.

**Tasks (in order).**

1. **[Owner]** Resolve the domain to the public address (or DDNS hostname); verify propagation with multiple resolvers.
2. **[Repo]** Allow the ACME challenge path through the front controller: add a `RewriteCond %{REQUEST_URI} !^/\.well-known/` exception (or an explicit `Alias`/`<Directory>` for `.well-known`) in `public/.htaccess`/the vhost so challenge files are served instead of being routed to Laravel. Verify on staging with a dummy file.
3. **[Host]** Create the ACME challenge location (`public/.well-known/acme-challenge`) with write permission for the ACME client's account and confirm the file is served on the staging listener.
4. **[Host]** Install a supported ACME client (e.g., win-acme) and request a certificate for `<PUBLIC_DOMAIN>` (and `www` if desired) using the chosen **pre-exposure** method (DNS-01 or tunnel/edge). Configure renewal under a service account with the permissions the chosen method needs (DNS API credentials for DNS-01; challenge-directory write plus an Apache reload/restart for HTTP-01).
5. **[Host]** Configure the TLS virtual host: certificate chain, key, modern protocols/ciphers, `SSLHonorCipherOrder`, OCSP stapling (optional), `DocumentRoot` = `public/`, and a permanent redirect from HTTP to HTTPS. **If a tunnel/edge terminates TLS instead**, bind the origin vhost to `127.0.0.1` (or a private interface) and configure trusted proxies so the app sees `X-Forwarded-Proto=https`, the real client IP, and correct throttling; still enforce HTTPS at the edge and apply `APP_URL`/secure cookies below.
6. **[Host]** After HTTPS works and is verified **internally (hosts-file/SNI test) and at the edge/tunnel if used**, apply the remaining production values: `APP_URL=https://<PUBLIC_DOMAIN>`, `SESSION_SECURE_COOKIE=true`, re-run `config:clear` + `config:cache`, then add HSTS (`max-age=31536000; includeSubDomains`); preload only after a stable period. External verification still belongs to Phase 7.
7. **[Repo]** Document the certificate paths and renewal command in the runbook (no private key in the repository).
8. **[Owner]** Add renewal monitoring (calendar/alert) or a monitoring check for certificate expiry.

**Files/config likely affected.** `httpd-vhosts.conf`, `httpd-ssl.conf` (or the dedicated vhost file), `public/.htaccess`, ACME client configuration and scheduled renewal task, `.env` (`APP_URL`, `SESSION_SECURE_COOKIE=true`), DNS records.

**Risks and mitigations.**
- **Ordering trap:** HTTP-01 cannot work before port 80 is exposed, but exposure is forbidden before Phase 7 → use DNS-01 or a tunnel, or obtain explicit owner approval for a temporary ACME-only exposure (documented, time-boxed, reverted).
- CGNAT/ISP blocking → certificate issuance fails; decide DNS-01 or Tunnel **before** ordering.
- Renewal silently fails → monitor expiry and test renewal (`--test`/staging) before relying on it; ensure the renewal task account can write the challenge path and restart the Apache service.
- HSTS applied too early → rollback difficult; enable only after HTTPS is stable and redirects verified.
- Forcing HTTPS via `APP_URL`/proxy while behind Cloudflare without trusted proxies → wrong URLs and redirect loops; configure `TrustProxies` (repo change) if a proxy is used.
- Editing `.env` without rebuilding the config cache leaves stale HTTPS/cookie values → always `config:clear` + `config:cache` and verify with `artisan about`.

**Staging checkpoint.** Certificate, TLS vhost, redirect, HSTS, and secure-cookie changes are exercised on the staging listener first (a staging certificate or a self-signed certificate is acceptable for staging only); production is then promoted and verified externally.

**Verification.** TLS scan of the staging/edge endpoint (SSL Labs or equivalent) at least A-; chain trusted by a clean browser; HTTP→HTTPS redirect; HSTS present; secure cookies; no mixed content; renewal dry-run succeeds; certificate matches the domain; `artisan about` shows the HTTPS URL and production mode. The **final external scan belongs to Phase 7**.

**Acceptance criteria.** Valid trusted certificate obtained by a method compatible with the approval gate; HTTPS-only with redirect; renewal automated, account-permissioned and tested; secure cookie and HSTS in place; TLS scan acceptable.

**Rollback / recovery.** Revert to the HTTP vhost, remove HSTS from the vhost (browsers that already cached it may still force HTTPS for the max-age period), keep the certificate files, and remove any temporary DNS-01/Tunnel configuration. Certificates are additive and do not affect data. Do not roll back by disabling TLS verification in the application or the offline client.

**Privileges / owner action.** Administrator for Apache/ACME; owner for domain, DNS, certificate account, and the port-forwarding-versus-tunnel decision.

**Gate to Phase 5.** Certificate and renewal verified internally (and at the edge if a tunnel is used); owner confirms the domain/DNS arrangement. External verification remains a Phase 7 gate.

---

## Phase 5 — Operational readiness

**Objective / outcome.** The service can survive failure and be restored: automated encrypted backups, tested restore, monitoring/alerting, log retention, update procedure, and a written incident/disable procedure.

**Dependencies.** Phases 3–4 (backups belong on the final paths; mail settings for alerts).

**Tasks (in order).**

1. **[Host]** Choose an off-machine backup destination (external disk, NAS, or cloud bucket with encryption). Never inside the web root.
2. **[Repo]** Harden `tims:backup` for least privilege: it currently runs `mysqldump --routines --triggers` with the application credentials resolved from `mysqldump` on `PATH`, then buffers the whole dump in PHP memory (`$process->getOutput()`), which conflicts with `memory_limit=512M` on a large database. Change it to (a) call the **absolute** `mysqldump.exe` path (Task Scheduler has a minimal `PATH`), (b) use a dedicated backup account or a protected `--defaults-extra-file` containing the administrative credentials (a DML-only web account is not sufficient for routines/triggers), (c) write with `mysqldump --result-file` (or stream) instead of buffering, (d) write to the private backup path by default, (e) make retention semantics unambiguous (option text says “number of daily backups”; code prunes by days), and (f) fail loudly on a non-zero `mysqldump` exit. Add tests for the exit-code/retention behaviour.
3. **[Host]** Create the backup account (if used) with the minimum dump privileges and verify a complete dump; store any defaults file with Administrator/SYSTEM-only ACLs. Schedule: nightly database dump (or `tims:backup`), nightly copy of `storage/app/private` and `.env` (encrypted), weekly restore into a scratch database with an automated assertion (row counts), and off-machine replication. Use Task Scheduler with “run whether user is logged on or not”, an explicit working directory, and the service account.
4. **[Host]** Schedule certificate renewal and a renewal check; ensure the renewal task runs under an account that can write the ACME challenge path and restart the Apache service. Schedule disk-space and service-health checks.
5. **[Host]** Configure mail (SMTP) for alerts and set the application `MAIL_*` variables; if no SMTP is available, use a local alert file plus a desktop/Telegram/webhook sender — but monitoring must be able to reach the owner.
6. **[Repo]** Configure log rotation (`daily`, 14 days) and set `LOG_LEVEL=warning`; review which log lines can contain personal data and redact where feasible. Document the log location and retention.
7. **[Host]** Monitoring/alerting set: external uptime check (HTTP 200/HTTPS, certificate expiry), disk free threshold, Apache/MySQL service state, backup success/failure, `429/5xx` spikes, failed-login spikes, and sync errors per offline server. A lightweight script checking `storage/logs` and `sync_events` on a schedule is acceptable. The external uptime/certificate checks become meaningful only after Phase 7 exposure; before that, verify the checks run and alert correctly against the staging listener.
8. **[Owner]** Define RPO/RTO, retention of beneficiary data and audit logs, breach-notification contacts, and approval for the backup destination.
9. **[Repo]** Update `docs/backup-restore.md` to the production paths, encryption, restore drill, and RPO/RTO; replace the root/blank-password examples with the chosen administrative/backup account; remove stale ID-card references.
10. **[Host]** Test-restore: restore the latest dump into a scratch database and run a smoke test; record time taken (RTO evidence). Restore **with the administrative account**, then verify the application still reads/writes as `tims_web`.
11. **[Host]** Verify the backup actually fails when the credentials are wrong or `mysqldump` is missing (negative test), so a silent failure cannot masquerade as success.

**Files/config likely affected.** `app/Console/Commands/TimsBackup.php`, `routes/console.php` or Task Scheduler entries, `config/logging.php`, `.env` mail/log variables, `docs/backup-restore.md`, alert scripts under `scripts/`.

**Risks and mitigations.** Backups contain personal data → encrypt at rest and in transit, restrict ACLs, test deletion of old copies. Backup job fails silently → explicit exit-code checks, negative test, alert on absence. A DML-only web account cannot dump routines/triggers → use the backup account or admin defaults file. Restore overwrites production → always restore to a scratch schema first. Mail non-delivery → secondary alert channel.

**Staging checkpoint.** Run the hardened backup command and a full restore drill against the staging database first, including the negative (wrong-credential) test; then promote the schedule.

**Verification.** Backup runs on schedule and is off-machine; restore drill passes with recorded RTO; wrong credentials/missing `mysqldump` produce a visible failure; alerts fire on simulated failure (stop MySQL, fill disk on a test volume, expire a test certificate); log rotation confirmed; RPO/RTO documented.

**Acceptance criteria.** Automated encrypted backups with retention and a successful restore drill, executed under a least-privilege backup path; monitoring with at least availability, disk, service, backup, certificate, and authentication anomalies; documented incident and emergency-disable procedures.

**Rollback / recovery.** Backups are additive; if the new backup pipeline fails, keep the old manual procedure until the new one is proven.

**Privileges / owner action.** Administrator; owner for destination, budget, retention, and RPO/RTO.

**Gate to Phase 6.** A restore drill has actually passed, and a simulated alert has actually reached the owner.

---

## Phase 6 — Regression and security validation

**Objective / outcome.** Prove that public-hosting changes did not break the application, authorization, sync, or token lifecycle, and that the production posture holds under test.

**Dependencies.** Phases 2–5.

**Tasks (in order).**

1. **[Repo]** Full suite on the **staging clone** (`php artisan test`; production runs `--no-dev` and therefore has no PHPUnit): baseline 365/2,161, plus new tests added in Phase 2. Record the result verbatim.
2. **[Host]** Application smoke test in **production configuration** on the production host: login (admin/staff/manager as applicable), beneficiary list/detail/create/edit, import upload → preview → apply, reports/export, audit log, sync pages, package generation (admin), and a 403 check for staff on admin pages.
3. **[Host]** Sync end-to-end against a test offline server over HTTPS: enroll, snapshot, create/update/delete, upload, outcomes, conflict creation/resolution, request review accept/reject, authorization expiry and reauthorization, offline outage then recovery. Verify token expiry is **not** extended by re-login/restart and that pending operations and audit logs survive. Run this on staging and, for the HTTPS/proxy path, once against the staging vhost.
   - The offline client verifies TLS by default, so a self-signed staging certificate will fail the handshake by design. Use a publicly trusted certificate for the staging hostname (e.g., a real subdomain via DNS-01) or install the staging CA in the **test device's** trust store. Never disable certificate verification in application code to make the test pass.
4. **[Host]** Security validation from the LAN and a second machine: TLS configuration, header checks, cookie flags, error pages (debug off), sensitive-path probes, directory listing, XAMPP tools, rate limits (login 429 after 5/min; enrollment 10/min), and `composer audit` (point-in-time).
5. **[Host]** Optional but recommended on the staging listener: an authenticated scan (ZAP baseline) over the staging host override. **External scanning of the public address is a Phase 7 activity**, because before forwarding the address is not reachable; do not claim it as pre-exposure proof.
6. **[Owner]** Review the results; sign the go/no-go for Phase 7. Record the residual risks that remain even with a green result (see “Proof boundaries”).

**Files/config likely affected.** None except fixes discovered (return to Phase 2/3 tasks if a check fails).

**Risks and mitigations.** Testing only from the same host hides NAT/firewall issues → Phase 7 verifies from a mobile network; staging avoids touching real beneficiary data. Passing tests can create false confidence → record the proof boundaries and residual risks explicitly.

**Verification.** Every check above has a recorded result; failures are fixed and re-tested; test evidence states where each check ran (staging vs production).

**Acceptance criteria.** Full suite green on staging; smoke and sync end-to-end pass in production configuration; no sensitive path or XAMPP tool reachable; TLS/headers/cookies correct; owner approval recorded with residual risks acknowledged.

**Rollback / recovery.** Restore the Phase 1 state (code branch, `.env`, config) and re-run the baseline suite.

**Privileges / owner action.** Administrator; owner for external scans and go/no-go.

**Gate to Phase 7.** Explicit written owner approval. No approval → no port forwarding.

---

## Phase 7 — Controlled public deployment (gated)

**Objective / outcome.** Make the service reachable from the Internet with the smallest possible surface, then verify from outside and monitor closely.

**Dependencies.** All Phase 1–6 gates; owner approval; a change window; rollback rehearsed.

**Tasks (in order).**

1. **[Owner]** Approve the change window and the exact public hostname/IP; confirm the router admin (or tunnel/edge admin) is available. The approval covers **any** form of public reachability — port forwarding, DDNS pointing at the host, **or** publishing a tunnel/edge route.
2. **[Host]** Final pre-flight: backups current and restore-tested; monitoring live and tested; on-call contact available; emergency-disable procedure at hand.
3. **[Host]** Windows Firewall: broaden the 80/443 rules from LAN-only to the intended profile(s)/remote scope; keep 3306 blocked; confirm no other inbound ports.
4. **[Owner]** Expose the service with the method approved in Phase 4:
   - **Port forwarding:** TCP 443→<SERVER_LAN_IP>:443, and TCP 80 only if the HTTP→HTTPS redirect/ACME path requires it. Never forward 3306, RDP, SMB, or XAMPP administrative ports; enable NAT loopback/hairpin if available.
   - **Tunnel/edge:** publish only the application route; do not expose the host's other ports; ensure the edge passes `X-Forwarded-*` correctly and the app's `TrustProxies`/`APP_URL` are configured (Phase 4).
5. **[Owner]** Enable DDNS updates if the IP is dynamic; verify the hostname resolves to the current WAN address.
6. **[Host]** Verify from outside **immediately** after exposure: `<PUBLIC_DOMAIN>` loads over HTTPS; HTTP redirects; certificate valid; no XAMPP tools/sensitive paths; no directory listing; login works; rate limits behave. If any check fails, execute the rollback procedure below before proceeding.
7. **[Host]** Watch for 24–72 hours: availability, `5xx`/`429`, sync success, disk, CPU, failed logins, certificate renewal status; compare against baseline.
8. **[Host]** Record the public exposure state (forwarding/tunnel rules, firewall rules, DNS) in the operations log so it can be removed quickly; if the rollback was used, record its result and the time.

**Files/config likely affected.** Windows Firewall rules; router configuration (not in the repository); DNS/DDNS records; tunnel/edge configuration if chosen.

**Risks and mitigations.**
- Exposure begins the moment forwarding, DNS, or a tunnel route is saved → ensure Phases 1–6 are complete and monitoring is active first; the rollback must be at hand before saving.
- ISP blocking/CGNAT → forwarding may not work; use the agreed tunnel/DNS-01 architecture (owner decision); do not improvise a different exposure path during the change window.
- Bot traffic begins immediately → login throttling, headers, noindex, and rate limits must already be in place; consider a WAF/CDN if the owner accepts the proxy trade-off (configure `TrustProxies`).
- Wrong IP after DHCP change → static LAN lease for the server and DDNS for the WAN side.
- Tunnel/edge misconfiguration hides the real client IP or breaks throttling → configure trusted proxies and verify audit IPs and 429 behaviour.

**Verification (external).** From a mobile network: HTTPS 200, valid chain, redirect from HTTP, headers/HSTS, no `.env`/`scripts`/`graphify-out`/`phpmyadmin`/`phpinfo`/`server-status`, rate limit triggers, a successful login, and a sync enrollment handshake. This is the first point at which external exposure checks are meaningful; it is not proof of complete security.

**Acceptance criteria.** Only 443 (and optionally 80 for redirect) reachable; verified externally immediately; monitoring green for the observation window; rollback tested and recorded.

**Rollback / recovery.** Remove the exposure first: delete the router forwards, unpublish/stop the tunnel route, and remove any DDNS/public record pointing at the host; then disable the Internet-permitting firewall rules; then optionally stop Apache. Keep the service internally available and verify from a mobile network that it is unreachable. Record the time and the exact actions taken.

**Privileges / owner action.** Owner for router and DNS; Administrator for firewall and verification.

**Gate to Phase 8.** 72 hours of stable operation and no unresolved security finding.

---

## Phase 8 — Post-deployment hardening

**Objective / outcome.** Keep the service safe and operable: verify the external surface continuously, tighten remaining controls, drill recovery, and schedule reviews.

**Dependencies.** Phase 7 stable.

**Tasks (in order).**

1. **[Host]** External surface review from outside: port scan limited to the agreed ports; confirm no new services; confirm TLS configuration grade and renewal. Record that this scan covers only the probed surface and is not proof of complete security.
2. **[Host]** Enable HSTS preload only if the policy is stable for the long term.
3. **[Host]** Add a CDN/WAF or IP allowlist only if the owner accepts the trade-off; if a proxy is used, stage the `TrustProxies` change (repo) and verify audit IPs, throttling, and forwarded-scheme handling before enabling it in production.
4. **[Host]** Perform an incident drill: simulate a compromised admin account (disable, rotate credentials, inspect audit), a certificate expiry, a failed backup, and a disk-full event; record timings.
5. **[Owner]** Schedule quarterly reviews: account list, firewall/forwarding rules, certificate/DNS, dependency updates, backup restore, privacy/retention review, and access review.
6. **[Repo]** Keep dependencies patched (`composer update` in a staging copy, `composer audit`, full suite) and repeat the security validation after each update.
7. **[Host]** Consider MFA for administrators (Laravel Fortify or a reverse-proxy SSO) and a password-change flow; both are medium-term product decisions, not part of this deployment.
8. **[Repo]** Update the assessment/plan/runbook with the final state and residual risks.

**Acceptance criteria.** External surface matches the plan; incident drill completed; review calendar in place; residual risks accepted in writing by the owner.

**Rollback / recovery.** Each change is individually reversible; the emergency-disable procedure remains valid at all times.

**Privileges / owner action.** Administrator; owner for policy decisions and residual-risk acceptance.

---

## Cross-phase dependency and gate summary

| Phase | Depends on | Gate (must be true before next phase) |
|---|---|---|
| 1 Baseline | — | Reproducible revision, dump restore-tested, evidence captured |
| 2 Critical remediation | 1 | Debug off, least-privilege DB (incl. backup path), XSS fixed, sensitive paths denied, headers, ACLs; verified on staging |
| 3 Web server | 2 | Dedicated vhost, services auto-start, production PHP, package/backup/sync smoke after any move |
| 4 Domain + HTTPS | 3 + owner answers | Trusted cert via DNS-01/tunnel (or approved temporary exposure), redirect, renewal tested, secure cookies, HSTS |
| 5 Operations | 3–4 | Encrypted backup + successful restore under the backup account, monitoring + alert drill |
| 6 Validation | 2–5 | Staging suite + production smoke + sync E2E + security checks; owner go with residual risks recorded |
| 7 Public exposure | all + written approval | External verification green, 72 h stable, rollback recorded |
| 8 Hardening | 7 | External surface verified, drill done, review scheduled |

## What must never be sacrificed

- Offline/online modes continue to work; the central server is never required for an offline device's local operation.
- Enrollment, server-token authentication, authorization periods, reauthorization, and the review workflow are reused unchanged.
- **No duplicate token mechanism:** do not add a parallel token, bypass expiry, extend validity silently, or reimplement reauthorization/request approval.
- Snapshot/delta sync, idempotent retries, conflict review, and audit synchronization keep passing their tests.
- A public outage or expired certificate must not delete or corrupt local data; pending operations remain queued.
- Green tests, HTTPS, headers, or a clean external scan are evidence, not a security guarantee (see “Proof boundaries”). Residual risks remain and must be accepted in writing.

## Residual decisions for the owner (before Phase 4)

1. Cloudflare Tunnel/reverse proxy versus classic port forwarding (CGNAT/ISP dependent).
2. Domain name and DNS provider; who holds credentials.
3. Certificate validation method: **DNS-01 or tunnel for pre-exposure issuance**; HTTP-01 only with explicit approval of a temporary exposure.
4. Office/VPN allowlist versus open Internet.
5. MFA/password-reset roadmap.
6. Backup destination, RPO/RTO, encryption, and backup-account strategy.
7. Privacy basis, notice, retention, and DPO designation.
8. Whether the CSP ends in a nonce/hash-based policy (requires inline-script/handler refactoring) or a documented weaker policy accepted as residual risk.
9. Confirmation that `scripts/` stays in place for installation packaging and that no internal folder move breaks scheduled tasks or backups.
10. Owner approval for the final public-exposure method and change window (Phase 7).
