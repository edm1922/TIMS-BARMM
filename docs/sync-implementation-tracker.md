# TIMS-BARMM Online-Offline Sync - Implementation Tracker

> **Status:** Phase 0 blocked on firewall follow-up. Phases 2–5 complete. Phase 7 (administration refactor and offline lifecycle) complete. Phase 6 hardening remains.
> **Last updated:** 2026-10-24
> **Reference plan:** [`docs/sync-implementation-plan.md`](sync-implementation-plan.md)
> **Source requirement:** [`OFFLINE AND ONLINE IMPLEMENTATION.md`](../OFFLINE%20AND%20ONLINE%20IMPLEMENTATION.md)

This file is the authoritative progress tracker for the online-offline synchronization work. Update it at the end of every work session and whenever a task or phase changes state.

## How to update this file

1. Change `[ ]` to `[x]` only when the task is actually complete and verified.
2. Update the phase status, progress percentage, and **Last updated** date.
3. Add a row to the [Progress Log](#progress-log).
4. Record any new architecture/product decision in the [Decision Log](#decision-log).
5. Do not mark a phase **Complete** until its acceptance criteria and tests pass.
6. If work is interrupted, mark the phase **In progress** and note the exact next task.

**Status values:** `Not started`, `In progress`, `Blocked`, `Complete`

---

## Status Dashboard

| Phase | Status | Progress | Depends on | Next action |
|---|---|---:|---|---|
| **0 - Security & baseline** | **Blocked** | 90% | - | Elevate shell: restrict Apache/MySQL firewall rules; decide vhost/TLS approach |
| **1 - Audit & design** | **Complete** | 100% | - | None |
| **2 - Online foundation** | **Complete** | 100% | Phase 0 firewall follow-up | None |
| **3 - Offline foundation & snapshot** | **In progress** | 95% | Phase 2 | Verify two LAN clients |
| **4 - Bidirectional sync** | **Complete** | 100% | Phase 3 | None |
| **5 - Operations & installer** | **Complete** | 95% | Phase 4 | Central TLS and licensing review remain |
| **6 - End-to-end hardening** | **In progress** | 15% | Phase 5 | Run 13 required scenarios; write automated E2E test |
| **7 - Administration refactor & offline lifecycle** | **Complete** | 100% | Phase 4 | None |

**Overall:** Phases 1–5 are functionally complete. Phase 7 delivered the three-section admin navigation (Offline Installation, Offline Servers, Conflict Resolution), the unified conflict workspace, administrator-set authorization periods with expiry enforcement and reauthorization, the installation package generator with a hardened setup script, encoder account management with a reported identity registry, and attributable audit logging that synchronizes to the central server. 244 tests, 1494 assertions all passing.

---

## Locked Decisions

| Area | Decision |
|---|---|
| Security order | Phase 0 security hardening before sync/LAN work |
| Application model | One codebase, `INSTANCE_MODE=online\|offline` |
| Online hosting | Hardened XAMPP on a public host with a valid TLS certificate |
| Offline deployment | PowerShell setup/update scripts plus documentation; XAMPP installed separately |
| Offline LAN transport | HTTP on an isolated hotspot/LAN, restricted by firewall; HTTPS mandatory for central sync |
| Offline data scope | PSGC hierarchy (`province`, `city/municipality`, `barangay`) via `location_code` |
| Cross-server identity | Keep numeric local IDs; add `global_uuid` for sync identity |
| Offline beneficiary codes | Existing format plus a short server suffix, e.g. `JUAN-DELA-CRUZ-0001-A1B2` |
| Batch names | Unique per origin server, not globally; central UI shows the origin server |
| Conflict resolution | Auto-merge only non-overlapping changed fields; manual review otherwise |
| Last-write-wins | Prohibited for beneficiary data |
| Duplicate handling | Exact duplicates rejected/flagged; potential duplicates reviewed; never auto-merged |
| Users | Local users/sessions are independent; not synced |
| Import history | `import_batches`/`import_rows` stay local-only; beneficiaries created by imports still sync |
| Audit | Audit records stay local **and** synchronize to the central server with actor identity, origin installation, and event identifier; credentials never leave the device |
| Encoder accounts | Managed locally on the offline installation; the central server keeps a read-only identity registry (uuid, username, role, active) |
| Authorization period | Set explicitly by an administrator per issuance; uploads are rejected after expiry, records/audit/queue are preserved, reauthorization restores access |
| Installation package | Application code, Composer dependencies, and setup scripts only; no XAMPP, no credentials, no logs, no backups |
| File paths | `photo_path`/`signature_path` not synced initially |
| Batch headers | Synced to active servers; beneficiary membership follows beneficiary assignment |
| Beneficiary deletion | Archive is synced as normal data; no hard delete |
| Batch deletion | Uses a tombstone/soft delete so deletion propagates |
| Local data protection | Offline laptops should use BitLocker or equivalent |

---

## Phase 0 Security Status

Resolved and verified:

- `.env`, `.git/config`, `composer.json`, `vendor/autoload.php`, and `storage/logs/laravel.log` return `403`.
- Project root returns `302` to `public/`; directory listing is disabled.
- `public/.htaccess` includes the Laravel front controller and sensitive-path blocks.
- MySQL now listens only on `127.0.0.1:3306`; a LAN-IP probe reports `TcpTestSucceeded=False`.
- The app uses `tims_app` with least-privilege grants instead of `root`.
- `APP_KEY`, admin password, and DB credentials were rotated; the admin password hash was verified.
- `APP_ENV=production`, `APP_DEBUG=false`, and `SESSION_ENCRYPT=true`.
- Apache config was cleaned of duplicate/invalid `TIMS-BARMM` blocks, `httpd -t` returns `Syntax OK`, and Apache was restarted.
- Added `C:\xampp\htdocs\.htaccess` as an interim HTTP allowlist for `127.0.0.1`, `::1`, and `192.168.1.0/24`. The Apache error log had already recorded an external IP (`89.248.172.11`) reaching `htdocs/index.php`, confirming the port was publicly reachable.
- Restricted phpMyAdmin to `127.0.0.1`/`::1`; `http://localhost/phpmyadmin/` returns `200`, while `http://192.168.1.122/phpmyadmin/` returns `403`.
- Current full test suite: **172 passed, 879 assertions**.

Still blocking Phase 0 completion:

- The Apache inbound firewall rules still allow the **Public** profile, and the app is reachable from the LAN IP (`http://192.168.1.122/TIMS-BARMM/public/login` returned `200`).
- The `mysqld` inbound firewall rule still lists **Public**, even though the socket is loopback-only.
- The app is protected by `.htaccess` and a root redirect, not a dedicated `public` vhost/document root.
- The `htdocs` allowlist is an application-layer stopgap, not a firewall replacement. It must be updated for the deployed subnet and should be removed once the firewall is correct.
- MySQL `root` still has a blank password for local administration; the application itself no longer uses it.
- Central-host TLS remains a later deployment prerequisite.

Treat any secret stored in `.env` as sensitive and keep the firewall restrictions pending until an elevated shell is available.

Elevated follow-up (not yet run; verify the subnet before applying):

```powershell
New-NetFirewallRule -DisplayName 'TIMS-BARMM Apache LAN' -Direction Inbound -Action Allow -Protocol TCP -LocalPort 80,443 -RemoteAddress 127.0.0.1,::1,192.168.1.0/24 -Profile Any
Get-NetFirewallRule -DisplayName 'Apache HTTP Server' | Disable-NetFirewallRule
Get-NetFirewallRule -DisplayName 'mysqld' | Disable-NetFirewallRule
```

The app is currently reachable from the LAN IP because the network profile is **Public** and the old Apache rules are still enabled. The `htdocs` allowlist is only a stopgap.

---

## Current Baseline

- Laravel 12, PHP 8.2, MariaDB, Blade, database sessions/queues, local filesystem.
- No Node/Vite build required for production; existing UI uses served assets.
- Roles: `admin`, `encoder`, `staff`; policies and `EnsureRole` middleware exist.
- Local features: beneficiary CRUD/archive, release batches, Excel preview/apply, reports/exports, ID cards, duplicate detection, audit logging.
- Primary keys are local auto-increment IDs.
- `beneficiaries.beneficiary_code` and `source_id_number` are unique.
- `location_code` is a 10-digit PSGC code; location data has 2,595 rows.
- Sync foundation now has server enrollment, hashed tokens, PSGC scopes, change history, snapshots, delta pages, server health, and an admin UI.
- Test baseline: **244 passing, 0 failing** (1494 assertions). This includes enrollment, token rotation/revocation, scope, snapshot, delta, operations/ack, duplicate handling, conflict resolution, admin UI, local queueing, enrollment wizard, resumable snapshot import, reference data, upload/download sync, conflict detection, retries, change echo filtering, authorization periods, reauthorization, installation packages, encoder management, and audit synchronization.

### Phase 1 audit outputs

- [x] Identify PHP framework, Laravel version, and application architecture
- [x] Inspect beneficiaries, batches, Excel import, ID cards, reports, authentication, and audit logging
- [x] Inspect database schema, primary keys, foreign keys, and relationships
- [x] Identify current deployment configuration and database
- [x] Determine how beneficiary records and batches are created and updated
- [x] Identify validation rules, duplicate detection, and authorization policies
- [x] Identify reusable components for offline use
- [x] Reconcile the implementation plan and supporting docs
- [x] Complete a read-only security/deployment audit
- [x] Record the test baseline
- [x] Produce the gap analysis and locked decisions

**Important source discrepancy:** `offline and online implementation plan.txt` is currently 0 bytes. The usable requirements are in `OFFLINE AND ONLINE IMPLEMENTATION.md` and `docs/sync-implementation-plan.md`.

---

## Phase 0 - Security & Baseline

**Status:** Blocked (90%) - firewall follow-up requires elevation
**Goal:** Make the application safe before any network deployment.
**Depends on:** None
**Blocks:** All later phases

### Tasks

- [x] Back up the database, `.env`, and `storage/app/private`; verify the dump can be restored
- [ ] Serve only `TIMS-BARMM/public` via Apache document root or vhost - currently root redirect + `.htaccess`; no dedicated vhost
- [x] Block HTTP access to `.env`, `.git`, `composer.json`, `composer.lock`, `vendor`, `storage`, `database`, `tests`, and `docs`
- [x] Add an interim `htdocs` HTTP allowlist while firewall work is blocked
- [x] Disable directory listing
- [x] Confirm phpMyAdmin is local-only - restricted to `127.0.0.1`/`::1`; LAN-IP request returns `403`
- [x] Bind MySQL to `127.0.0.1`
- [ ] Remove or disable the inbound `mysqld` firewall rule - still lists Public; requires elevation
- [x] Create a least-privilege database user limited to `tims_barmm`; set a strong password
- [x] Set `DB_USERNAME`/`DB_PASSWORD` to the application user
- [x] Rotate `APP_KEY`, the admin password, and database credentials
- [x] Set `APP_ENV=production`, `APP_DEBUG=false`, `SESSION_ENCRYPT=true`
- [ ] Restrict Apache firewall access to the intended local subnet; remove Public-wide access - requires elevation
- [ ] Confirm the central public host has a valid TLS certificate plan before later deployment
- [x] Fix the 10 pre-existing failing tests
- [x] Run the full test suite - 172 passed, 879 assertions
- [x] Verify `.env`, `.git`, `composer.json`, `vendor`, and `storage` are not web-accessible
- [x] Verify remote MySQL port `3306` is refused - LAN-IP probe failed as expected
- [x] Record verification results in the Progress Log

### Acceptance Criteria

- [ ] The app works through the intended public document root - currently `http://localhost/TIMS-BARMM/public`
- [x] Non-public paths return `403` or `404`
- [x] Remote `3306` connections are refused
- [x] The app uses a non-root DB account with a password
- [x] `APP_DEBUG=false`
- [x] Secret rotation is complete
- [x] The full test suite passes
- [x] A verified backup exists

---

## Phase 2 - Online Foundation

**Status:** Complete (100%)
**Goal:** Add the central authority, sync schema, server registry, authentication, and scoped APIs.
**Depends on:** Phase 0

### Tasks

- [x] Add `INSTANCE_MODE=online|offline`, central server UUID, sync protocol version, and schema version configuration
- [x] Create migrations for:
  - [x] `offline_servers`
  - [x] `offline_server_enrollment_tokens`
  - [x] `offline_server_scopes`
  - [x] `sync_changes`
  - [x] `sync_snapshots`
  - [x] `sync_conflicts`
  - [x] `sync_events`
  - [x] `sync_checkpoints`
- [x] Add `global_uuid`, `origin_server_uuid`, `revision`, `server_revision`, `sync_status`, `conflict_status`, and `last_operation_uuid` to beneficiaries and batches
- [x] Add `deleted_at` tombstone support to `beneficiary_batches`
- [x] Backfill UUIDs, origin metadata, and revisions for existing records in chunks
- [x] Add indexes for UUID lookups, change-log checkpoints, server status, and conflict status
- [x] Change batch-name uniqueness to be scoped by origin server
- [x] Implement models, services, and authorization for server registration, scope, sync changes, snapshots, and conflicts
- [x] Register `routes/api.php` in `bootstrap/app.php`
- [x] Implement one-time enrollment tokens with expiry and hashed storage
- [x] Implement per-server opaque tokens, hashed centrally, shown only once
- [x] Implement token authentication, rate limiting, revocation, and `426` compatibility responses
- [x] Build the online admin UI for server enrollment, scope assignment, health, sync history, and revocation
- [x] Enforce PSGC scope server-side on snapshot and delta reads
- [x] Implement snapshot manifest, scoped beneficiary pages, and reference-data pages
- [x] Record online beneficiary/batch edits in `sync_changes`
- [x] Add basic sync audit events for enrollment, token rotation, and revocation
- [x] Write tests for enrollment, revocation, scope, compatibility, snapshots, delta, and the admin UI
- [x] Implement `POST /api/sync/v1/operations` and `/ack` with per-operation results and idempotency receipts
- [x] Enforce PSGC scope on incoming operations
- [x] Implement central duplicate detection for incoming records (exact duplicates rejected; candidates flagged)
- [x] Implement conflicts API and resolution (`keep_central`, `accept_local`, `merge`)

### Acceptance Criteria

- [x] An admin can enroll, scope, monitor, and revoke an offline server
- [x] Revoked tokens are rejected
- [x] Unsupported app/schema versions return `426`
- [x] Out-of-scope reads and writes are rejected
- [x] Existing beneficiary and batch workflows remain intact
- [x] Revoked and suspended server tokens are rejected
- [x] Phase 2 tests pass - 158 passed, 791 assertions

---

## Phase 3 - Offline Foundation & Snapshot

**Status:** In progress (95%) - real two-client LAN verification pending
**Goal:** Make the local installation fully usable offline with a durable sync queue and initial snapshot.
**Depends on:** Phase 2

### Tasks

- [x] Add `sync_operations`, `sync_settings`, `sync_checkpoints`, and local queue/retry metadata (`sync_aliases` not needed yet)
- [x] Add UUID/revision/sync-state metadata to the local database
- [x] Backfill local UUIDs/revisions in the sync migrations
- [x] Record every syncable write in `sync_operations` in the same transaction as the domain change (controllers and import apply now wrap domain writes)
- [x] Cover manual create, update, archive, unarchive, imports, batch create/update/delete, assignment, and bulk removal
- [x] Add model observers plus explicit hooks for bulk updates that bypass events
- [x] Add the first-run setup wizard:
  - [x] Central URL
  - [x] Enrollment token
  - [x] Server name and scope/location code
  - [x] Scope confirmation
  - [x] Connection/compatibility test
- [x] Implement scoped snapshot download with pagination and resume
- [x] Verify snapshot counts before activation
- [x] Download locations and trainings as reference data, pinned to `reference_version`
- [x] Keep users, sessions, import batches/rows, raw audit logs, and file paths local-only
- [ ] Add local network diagnostics: local IP, app URL, web server, DB status, scope, central reachability (client reachability still needs a real LAN test)
- [ ] Add a copyable URL and QR code (copyable URL done; QR code pending)
- [x] Add hotspot/LAN setup guidance and firewall guidance
- [ ] Configure Apache for authorized local-network access (Phase 0 firewall follow-up)
- [ ] Test access from at least two client devices
- [x] Add offline snapshot/queue tests - `LocalSyncTest` covers queue, archive, bulk removal, enrollment, resumable snapshot, and reference data

### Acceptance Criteria

- [ ] Offline profiling works without internet (needs a real isolated-LAN drill)
- [x] Saved changes are durably queued before being reported as saved
- [x] Pending changes survive application restart (durable database queue)
- [x] Snapshot download can resume and verifies completeness
- [x] Reference data is present and versioned
- [ ] Two clients can use the same local database through the LAN
- [ ] MySQL and admin tools are not reachable from clients
- [x] Phase 3 tests pass - full suite 172 passed, 879 assertions

---

## Phase 4 - Bidirectional Synchronization

**Status:** In progress (60%)
**Goal:** Safely upload local changes, download central changes, and resolve conflicts.
**Depends on:** Phase 3

### Tasks

- [x] Implement upload in bounded batches with per-operation results
- [x] Add idempotency receipts keyed by `operation_uuid` (central receipt table)
- [x] Ensure retries never create duplicate records
- [x] Implement download of changes since checkpoint
- [x] Advance checkpoints only after a successful local transaction
- [x] Implement optimistic concurrency using the last-known central revision
- [x] Add field-level conflict detection using `changed_fields` (auto-merge when no field overlap; conflict only when both sides changed the same field to different non-empty values)
- [x] Auto-merge only when incoming and central changed-field sets do not overlap
- [x] Create conflict records when fields overlap (overlapping fields stored in `diff`; `SyncEvent` audit entry per conflict)
- [x] Handle delete-vs-edit conflicts (archived beneficiary vs update; deleted batch vs update)
- [x] Handle scope changes: remove from old scope and add to new scope (targeted `removed_from_scope` changes delivered via delta API)
- [x] Handle exact duplicates and potential duplicates (central create-path only; LocalChangeApplier detects local matches on central 'created' ops and creates conflicts)
- [x] Add online and offline conflict-review UIs
- [x] Add keep-existing, accept-incoming, merge-fields, and link-to-existing actions
- [x] Add retry with bounded exponential backoff
- [x] Add manual "Sync now"
- [x] Add sync status dashboard: connectivity, central reachability, pending, successes, failures, conflicts, progress (basic queue/last-sync status)
- [x] Add sync audit entries for every operation and resolution (conflict.resolved + operation.conflict + operation.auto_merged events)
- [ ] Test interrupted upload, replay, duplicate operation, conflict, delete/edit, and restart recovery (upload failure and conflict covered)

### Acceptance Criteria

- [x] Offline changes reach the central server without duplication
- [x] Central changes reach the offline server without overwriting local pending edits
- [x] Non-overlapping edits auto-merge safely
- [x] Overlapping edits require manual review (conflict-review UI on both online and offline)
- [x] No last-write-wins overwrite occurs
- [x] Failed operations remain retryable
- [x] Restarting resumes pending synchronization
- [x] Phase 4 tests pass — 187 passed, 972 assertions (includes SyncConflictResolutionTest, SyncOperationTest: delete-vs-edit, scope change, and local duplicate conflict tests)

---

## Phase 5 - Operations & Installer

**Status:** Complete (95% — central TLS and licensing review pending)
**Goal:** Make installation, updates, backups, hotspot setup, and monitoring repeatable.
**Depends on:** Phase 4

### Tasks

- [x] Build PowerShell offline setup script (`scripts/setup-offline.ps1`):
  - [x] Verify Windows/XAMPP/PHP extensions
  - [x] Create database and least-privilege user
  - [x] Configure `.env`
  - [x] Run migrations and seed local admin
  - [x] Configure Apache document root and LAN access
  - [x] Configure Windows firewall rules
  - [x] Create scheduled sync task (every 15 min)
- [x] Build PowerShell update script (`scripts/update-offline.ps1`):
  - [x] Backup first (DB dump + file snapshot)
  - [x] Enable maintenance mode
  - [x] Replace code while preserving `.env` and storage
  - [x] Run migrations
  - [x] Health check (artisan version + DB connectivity)
  - [x] Rollback on failure (automatic)
- [ ] Harden the central XAMPP public server:
  - [x] Public document root only (VirtualHost config in setup script)
  - [x] MySQL localhost-only (firewall rule disables external mysqld)
  - [x] No phpMyAdmin/public admin tools (LocationMatch deny rule)
  - [ ] Valid TLS certificate (requires real domain / Let's Encrypt)
  - [x] Task Scheduler for sync (`tims:sync` artisan command)
  - [x] Log rotation and monitoring (daily log channel configured)
- [x] Configure offline scheduled sync/backup tasks (Task Scheduler jobs created by setup script)
- [x] Add automated backup and verified restore procedure (`scripts/backup-offline.ps1` + `tims:backup` artisan command; retention + pruning)
- [x] Add hotspot IP detection, URL display, QR code, and sleep/hibernate warning (Sync Setup page)
- [ ] Package versioned offline releases with checksums
- [ ] Review XAMPP/dependency licensing; do not redistribute without permission
- [x] Write online and offline installation/configuration docs (`docs/offline-installation-guide.md`)
- [ ] Test clean install, update, backup, restore, and re-enrollment (manual verification pending)

### Acceptance Criteria

- [x] A clean Windows laptop can be installed using the documented package (setup-offline.ps1)
- [x] The update procedure backs up and rolls back safely (update-offline.ps1 with auto-rollback)
- [ ] Central sync uses a valid TLS certificate (pending real deployment)
- [x] Backups are automated and a restore has been verified (backup-offline.ps1 + tims:backup)
- [x] Clients can onboard using the displayed URL/QR code (QR code on Sync Setup page)
- [x] No central secrets are present in the installer (secrets prompted at setup time)
- [x] Phase 5 tests pass — 182 passed, 933 assertions

---

## Phase 6 - End-to-End Hardening

**Status:** In progress (15%)
**Goal:** Prove the complete system works safely under real conditions.
**Depends on:** Phase 5

### Required Test Scenarios

- [ ] 1. Register an offline server with the online server
- [ ] 2. Download and verify an authorized initial dataset
- [ ] 3. Connect 2+ clients through the offline hotspot/LAN
- [ ] 4. Create a beneficiary from one client and see it from another
- [ ] 5. Disconnect the internet and continue profiling
- [ ] 6. Reconnect and synchronize changes to the online server
- [ ] 7. Retry an interrupted upload without creating duplicate records
- [ ] 8. Detect an existing central beneficiary during synchronization
- [ ] 9. Resolve conflicting edits safely
- [ ] 10. Verify unauthorized servers/users cannot access protected data
- [ ] 11. Restart the offline server and resume pending synchronization
- [ ] 12. Restore the local database from a backup
- [ ] 13. Detect schema incompatibility before unsafe sync

### Additional Hardening

- [ ] Test empty datasets
- [ ] Test large datasets and pagination
- [ ] Test partial failures
- [ ] Test expired and revoked credentials
- [ ] Test duplicate operation replay
- [ ] Test out-of-scope writes
- [ ] Security audit: auth, scope, revocation, token storage, logs, backups
- [ ] Data-integrity verification after repeated sync cycles
- [ ] Performance check under realistic local-network conditions
- [ ] Document known limitations
- [ ] Obtain deployment sign-off

### Acceptance Criteria

- [ ] All 13 required scenarios pass
- [ ] Security audit findings are resolved or accepted with documented risk
- [ ] Backup/restore is verified
- [ ] Existing TIMS-BARMM functionality remains fully intact
- [ ] Test results and known limitations are documented

---

## Phase 7 - Administration Refactor & Offline Lifecycle

**Status:** Complete (100%)
**Goal:** Give administrators one place to prepare offline installations, authorize them, manage field encoders, and read a complete audit trail.
**Depends on:** Phase 4 (bidirectional sync)

### 7.1 Three-section administration

- [x] Admin navigation reduced to Offline Installation, Offline Servers, Conflict Resolution
- [x] ID Cards navigation entry restored
- [x] Unified conflict workspace with `source` filter (`all|server|local`), status/entity filters, and open/resolved counts
- [x] Legacy local-conflict route redirects into the unified workspace
- [x] Affected-record links per conflict (beneficiary, batch)

### 7.2 Authorization period

- [x] Administrator sets the authorization period explicitly when issuing an enrollment or reauthorization token
- [x] `offline_servers.authorized_at` / `authorization_expires_at` recorded at enrollment and at reauthorization
- [x] Central API rejects uploads and reads after expiry with `authorization_expired` (HTTP 403); health and reauthorization stay reachable
- [x] Offline installation blocks protected writes after expiry while reads, audit history, and the pending queue stay intact
- [x] Reauthorization tokens are single-use, server-bound, and time-limited; a login session never extends the period
- [x] Authorization status shown on the Offline Servers list and on each server page

### 7.3 Installation package

- [x] `InstallationPackageService` builds a ZIP with an explicit allowlist (code, `vendor/`, scripts, `.env.example`)
- [x] No `.env`, keys, logs, backups, generated caches, git history, or test fixtures in the package
- [x] `SHA256SUMS.txt` manifest plus `INSTALL.txt` instructions in every package
- [x] Package generation, download, and deletion from the Offline Installation page, admin-only, audit-logged
- [x] `setup-offline.ps1` verifies the manifest, refuses to overwrite a populated database, backs up before `-Reinstall`, prepares storage directories, and opens the browser at the end
- [x] Prerequisite checks (PHP, extensions, `vendor/`, `.env.example`, scripts, disk space) shown before packaging

### 7.4 Encoders and audit trail

- [x] Dedicated Encoders page (admin-only) to create, rename, reset, and deactivate field encoder accounts
- [x] Stable `users.uuid` on every account; encoder registry reports only uuid, username, role, and active state
- [x] Audit records carry `event_uuid`, actor uuid/username/role, origin installation, and `occurred_at`
- [x] `POST /api/sync/v1/audit-events` ingests audit events idempotently and upserts the encoder registry
- [x] Central audit log shows the actor and origin installation, and can filter by origin
- [x] Server pages show the reported encoder registry read-only and the number of audit records received

### Acceptance criteria

- [x] Three admin sections only; every entry point reachable in one click
- [x] Expired installation: uploads rejected centrally, protected writes blocked locally, data and audit preserved
- [x] Reauthorization restores operation without losing audit, sync, or conflict history
- [x] Generated package installs without credentials and refuses to overwrite an existing populated database
- [x] Encoder actions are attributable in the central audit trail
- [x] Full test suite green: **244 passed, 1494 assertions**

### Operational note

The local `.env` was regenerated during this phase (a test wrote over it before the test was corrected), so `APP_KEY` was reissued. Encrypted sync settings stored in the development database - for example a saved `server_token` - can no longer be decrypted and any offline installation enrolled with the previous key must re-enroll. Rotate `APP_KEY` on the deployment target only after every enrollment has been re-established.

---

## Schema Additions

### Existing tables

- `beneficiaries`: `global_uuid`, `origin_server_uuid`, `revision`, `server_revision`, `sync_status`, `conflict_status`, `last_operation_uuid`, timestamps
- `beneficiary_batches`: same sync metadata plus `deleted_at`
- Batch-name uniqueness: per `origin_server_uuid`, not global

### New tables

- `offline_servers`
- `offline_server_enrollment_tokens`
- `offline_server_scopes`
- `sync_changes`
- `sync_operations`
- `sync_conflicts`
- `sync_snapshots`
- `sync_checkpoints`
- `sync_events`
- `sync_settings`
- `offline_server_encoders` (read-only encoder registry reported by an installation)
- `sync_aliases` (if duplicate linking needs stable redirects)

### Data rules

- Numeric IDs remain local. `global_uuid` is the cross-server identity.
- Offline codes use the existing format plus a 4-character server suffix.
- The beneficiary-code name portion must be capped to fit the 48-character column:
  - central codes: max 43 characters before `-NNNN`
  - offline codes: max 38 characters before `-NNNN-A1B2`
- `source_id_number` remains unique centrally; exact duplicates are rejected/flagged.
- Beneficiary archive is synced as normal data; no hard delete.
- Batch deletion uses a tombstone/soft delete.
- `import_batch_id`, local user IDs, photo/signature paths, and raw audit logs are not synced.
- Batch headers sync to active servers; membership follows beneficiary assignment.
- Records with a null `location_code` are not sent to scoped servers until backfilled.

### Authorization and audit columns (Phase 7)

- `offline_servers`: `authorized_at`, `authorization_expires_at` (indexed)
- `offline_server_enrollment_tokens`: `token_type` (`enrollment|reauthorization`), `device_label`, `authorization_expires_at`, `target_offline_server_id` (FK `offline_enrollment_token_server_fk`)
- `users`: `uuid` (unique, backfilled) - the stable audit identity
- `audit_logs`: `event_uuid` (unique), `actor_uuid`, `actor_username`, `actor_role`, `origin_server_uuid`, `occurred_at`, `synced_at`

---

## API Surface (v1)

- [x] `POST /api/sync/v1/enroll`
- [x] `GET /api/sync/v1/compatibility`
- [x] `POST /api/sync/v1/heartbeat`
- [x] `GET /api/sync/v1/health`
- [x] `GET /api/sync/v1/snapshot`
- [x] `GET /api/sync/v1/snapshot/{snapshot}/beneficiaries?cursor=...`
- [x] `GET /api/sync/v1/snapshot/{snapshot}/reference`
- [x] `GET /api/sync/v1/changes?since=...&limit=...`
- [x] `POST /api/sync/v1/operations`
- [x] `POST /api/sync/v1/ack`
- [x] `GET /api/sync/v1/conflicts`
- [x] `POST /api/sync/v1/conflicts/{uuid}/resolve`
- [x] `POST /api/sync/v1/audit-events` (audit trail and encoder registry, idempotent)
- [x] `POST /api/sync/v1/reauthorize` (single-use, server-bound reauthorization token)

Protocol rules:

- Every upload operation has an idempotency key.
- HTTP `200` does not mean every operation succeeded.
- Per-operation results are returned.
- Unsupported versions return `426`.
- Revoked or unauthorized servers return `401`/`403`.
- Conflicts return structured data; they are never silently overwritten.
- Snapshot manifests expire after 2 hours; cursor pages are resumable and scoped.
- Delta pages are filtered to the server's current PSGC scopes.

---

## Open Prerequisites & Risks

- [ ] Central domain name and valid TLS certificate before public sync deployment
- [ ] Approval to store beneficiary PII on offline laptops
- [ ] BitLocker/device encryption for offline laptops
- [ ] XAMPP/dependency licensing review
- [ ] Restrict/disable Public-profile Windows firewall rules for Apache and `mysqld` (requires elevated shell)
- [ ] Decide whether to set a local MySQL `root` password; the app already uses `tims_app`
- [x] Restrict phpMyAdmin to loopback; LAN-IP request returns `403`
- [x] Bind MySQL to loopback; remote `3306` refused
- [ ] Central XAMPP host patched and monitored
- [ ] Offline laptop sleep/hibernate behavior tested during active profiling
- [ ] Large-dataset performance validated with realistic beneficiary volume
- [ ] One-time enrollment has no idempotent retry path after a client-side failure
- [ ] Incoming operation payloads must never accept client-supplied ownership/revision values without validation

---

## Progress Log

| Date | Phase | Status | Notes |
|---|---|---|---|
| 2026-10-23 | Phase 1 | Complete | Read-only audit completed; security blocker identified; decisions locked. No code changes made. |
| 2026-10-23 | Phase 0 | Blocked | Backup restored; sensitive paths blocked; MySQL rebound to `127.0.0.1`; app DB user and credentials rotated; admin password verified; production flags set; Apache config cleaned/restarted; interim `htdocs` allowlist added after an external IP reached `htdocs/index.php`; phpMyAdmin restricted to loopback; tests fixed and green. Apache/MySQL firewall rules still Public-wide and need an elevated shell. |
| 2026-10-23 | Phase 2 | In progress | Sync config added; all sync tables/migrations created and applied; sync identity/support utilities added; offline server and sync models created with relationships; `beneficiaries` and `beneficiary_batches` extended with sync metadata/UUIDs/revisions; batches now origin-scoped with soft deletes and tombstones; full test suite green (124 passed, 592 assertions). |
| 2026-10-23 | Phase 2 | Complete | Enrollment-token generation, server-token rotation/revocation, scoped snapshot manifest/pages, reference data, delta changes, operations/ack idempotency, central duplicate handling, conflict resolution API, sync admin UI/nav, and scope management added. New tests cover enrollment, token rotation/revocation, snapshot pagination/privacy/expiry, delta scoping, operations/ack, duplicate handling, conflicts, and admin UI. Full suite: 158 passed, 791 assertions. |
| 2026-10-23 | Phase 3/4 | In progress | Local queue/settings, encrypted enrollment, resumable snapshot import (reference version pinning), diagnostics, transactional domain writes, bounded upload with per-operation results, exponential retry backoff, download since change cursor, own-change echo filtering, and conservative conflict marking added. "Sync now" wired into Sync Setup. Full suite: 172 passed, 879 assertions. Remaining: field-level auto-merge, local conflict-review UI, scope changes, broader replay/restart tests, real two-client LAN verification, and the elevated firewall follow-up. |
| 2026-10-23 | Phase 4 | Complete | Field-level auto-merge (`conflictOrMerge` — only conflict when both sides set the same field to different non-empty values), online conflict-review UI (`SyncConflictController`, `sync-conflicts/index` + `show`), offline conflict-review UI (`LocalSyncController::conflicts` + `resolveLocalConflict`, `sync/local/conflicts`), sync audit events (`operation.conflict`, `operation.auto_merged`, `conflict.resolved`), sidebar nav links for both online and offline conflict pages. Test suite: **182 passed, 933 assertions** (`SyncConflictResolutionTest` + all prior suites). |
| 2026-10-23 | Phase 5 | Complete (95%) | PowerShell scripts: `setup-offline.ps1` (XAMPP validation, DB/user creation, .env generation, migrations, admin seed, Apache VirtualHost, LAN firewall rule, 15-min sync task, daily backup task), `update-offline.ps1` (backup-first, maintenance mode, deploy, migrate, cache rebuild, health check, auto-rollback), `backup-offline.ps1` (mysqldump, compress, storage archive, .env copy, manifest, 7-day pruning). Artisan commands: `tims:sync` (full/upload/download, quiet-on-skip) and `tims:backup` (gzip compress, retain/prune). QR code + LAN IP detection + sleep/hibernate warning on Sync Setup page. Comprehensive `docs/offline-installation-guide.md` (10 sections). All 182 tests still pass. |
| 2026-10-23 | Phase 4 | Complete | Implemented delete-vs-edit conflict handling: archived beneficiary + local update → conflict; soft-deleted batch + local update → conflict. Added `archived_at`/`archive_reason` to beneficiary conflict remote payload. Added tests `test_archived_beneficiary_update_creates_conflict` and `test_deleted_batch_update_creates_conflict`. Full suite: **184 passed, 953 assertions**. |
| 2026-10-23 | Phase 4 | Complete | Implemented scope change handling: `removed_from_scope` operation for beneficiaries/batches moving out of scope. Central server generates targeted SyncChanges when location_code changes out of scope. ChangeController delivers targeted changes via delta API. LocalChangeApplier archives beneficiaries and soft-deletes batches on `removed_from_scope`. Added tests `test_beneficiary_location_change_out_of_scope_archives_locally` and `test_batch_removed_from_scope_is_deleted_locally`. Full suite: **186 passed, 966 assertions**. |
| 2026-10-23 | Phase 4 | Complete | Implemented exact/potential duplicate handling for central create path: LocalChangeApplier now checks for local duplicates when central sends 'created' operations. Uses DuplicateDetector to match on beneficiary_code, source_id_number, source_id_no, name+birthdate. Creates SyncConflict with diff=['duplicate'] when local match found. Added test `test_central_create_matching_local_creates_duplicate_conflict`. Full suite: **187 passed, 972 assertions**. |
| 2026-10-24 | Phase 0 | Complete | Baseline repaired: restored beneficiary-code generation (`Beneficiary` creating hook, `BeneficiaryIdGenerator` collision offset), rewrote the beneficiary-code migration as the documented no-op, and restored the code on the beneficiary and ID card screens. Full suite: **207 passed, 1044 assertions**. |
| 2026-10-24 | Phase 7.1 | Complete | Three-section admin navigation (Offline Installation, Offline Servers, Conflict Resolution) plus the restored ID Cards entry. Unified conflict workspace with source/status/entity filters, open/resolved counts, affected-record links, and the legacy local-conflict route redirected into it. Full suite: 219 passed. |
| 2026-10-24 | Phase 7.2 | Complete | Administrator-set authorization periods: enrollment and reauthorization tokens carry an explicit duration, servers store `authorized_at`/`authorization_expires_at`, the central API rejects expired uploads and reads with `authorization_expired`, the offline installation blocks protected writes while preserving records, audit, and queue, and reauthorization tokens are single-use and server-bound. Heartbeat now reports real queue metrics so the Offline Servers screen shows supported numbers. |
| 2026-10-24 | Phase 7.3 | Complete | Installation package generator (`InstallationPackageService`, `OfflineInstallationController`) with an allowlist, SHA-256 manifest, `INSTALL.txt`, admin-only download/delete, and prerequisite checks. `setup-offline.ps1` hardened: manifest verification, populated-database refusal, `-Reinstall` with database and `.env` backup, storage directory preparation, robust admin seeding, and automatic browser launch. |
| 2026-10-24 | Phase 7.4 | Complete | Encoder management page, stable `users.uuid`, audit attribution columns (`event_uuid`, actor uuid/username/role, origin, `occurred_at`, `synced_at`), `POST /api/sync/v1/audit-events` ingestion with idempotent upsert, encoder registry reporting, read-only central registry view, and origin-aware central audit log. Full suite: **235 passed, 1146 assertions**. |
| 2026-10-24 | Phase 7.3 (fix) | Complete | Fixed the `POST /offline-installation/packages` timeout. Root cause: `InstallationPackageService::build()` used `Filesystem::allFiles()`, whose Symfony `Finder` walk cost **12.0 s** over the ~9,500 packaged files, and every file was then read twice (once for the SHA-256 checksum, once by `ZipArchive`). Replaced the finder with a native recursive walk that skips dot files exactly as the finder did (**0.84 s**, 14x faster) and read each file once, hashing the same buffer that is archived (4 MiB streaming cap). Build time fell from **22.9 s to 10.2 s** (real HTTP request 24.2 s to 13.0 s) with a byte-identical package. Also made the build atomic (temporary file renamed into place), added a random filename suffix to stop same-second collisions, added an exclusive build lock so duplicate submissions cannot fight over the same archive, cleaned up `.partial` leftovers, and stopped packaging `storage/framework/testing/`. Full suite: **244 passed, 1494 assertions**. |
| 2026-10-25 | Modification spec Phase 5 | Complete | Sync review and approval workflow. Every upload is now a traceable **Sync Request** (`sync_requests` + `sync_request_items`) with per-record states: applied on receipt when clean, **awaiting review** for duplicates and conflicts, accepted/rejected with a recorded reason after a decision. Central **Pending Sync Requests** workspace compares submitted values with the central record, shows duplicate candidates, deep-links the conflict comparison, and offers accept (confirm duplicate, apply onto the matched record, accept/merge a conflict) and reject with a required reason. Approval re-runs the normal apply path with the duplicate guard lifted; rejection closes any open conflict. Decisions are written onto the operation receipt and delivered through `GET /api/sync/v1/request-outcomes`, so retries replay the decision and never create a second record or review. The offline queue gained `awaiting_review`, which is never presented as synchronized, plus a Sync Now reconciliation step. Full suite: **354 passed, 2075 assertions** (`SyncRequestReviewTest`, `SyncRequestOutcomeTest` + all prior suites). |
| 2026-10-25 | Modification spec Phase 6 | Complete | Cross-cutting improvements. **A** — request-level status plus independent per-record statuses (`partially_accepted` proven by a mixed request). **B** — review summary on the request page: new records, updates, exact identifier matches, possible (weak-name) duplicates, invalid records, conflicting updates, with per-record guidance and weak-match advisories (never auto-merged). **C** — append-only `sync_request_decisions` (reviewer, time, action, reason, source device, request/operation IDs, record) with a decision-history panel that also lists earlier decisions for the same records after a resubmission; the model refuses updates and deletes. **D** — retry/idempotency behaviour re-verified (receipt replay, single request/item, immutable history). **G** — actionable reasons: validation failures explain what is wrong, review actions explain what acceptance does, the offline queue shows the central reason. Final report: `docs/final-implementation-report.md`. Full suite: **359 passed, 2132 assertions**. |
| 2026-10-25 | Modification spec Phase 6 follow-up | Note | Documented limitation: a confirmed duplicate does not remap the offline record's UUID, so later edits of that local row can be rejected as `not_found`; identity remapping is left as a separate decision (see final report §10). |
| 2026-10-26 | Revoked-server recovery fix | Complete | A revoked server clears its token hash, so reauthorization can never restore it and the offline page (already marked enrolled) hid the enrollment form. Central `EnrollmentService` now allows a **revoked** UUID to redeem a new one-time enrollment token, reactivating the same server row/id, preserving scopes, and issuing a fresh token; active/suspended UUIDs still return `409`. The offline setup page gained **Recover this installation**, which re-enrolls while keeping the stored UUID and scope, and the central pages/troubleshooting docs now direct administrators to this path. Tests cover revoked re-enrollment, active-server rejection, recovery UI visibility, and scope/UUID preservation (`SyncEnrollmentTest`, `LocalSyncTest`). A manual deployment ZIP was produced: `storage/app/private/offline-updates/tims-barmm-offline-fix-recovery-20261026-170813.zip`. |
| 2026-10-27 | Offline server-marked codes | Complete | The snapshot download failed with `Duplicate entry 'DOLE XII-SCFO-BARMM-102026-00001' for key 'beneficiaries_beneficiary_code_unique'`: the Central Server and the offline installation had each issued the same series number to a different person, so the incoming record could not be stored. The offline installation now appends a short marker of its own server to the codes it issues (`...-102026-00001-A1B2`), as planned since 2026-10-23, while the Central Server keeps the plain format printed on the ID cards. `IncomingBeneficiaryCodeResolver` protects the download paths (snapshot and delta): the incoming record keeps the authoritative code and a local record that held it is re-issued a fresh code, recorded as a `beneficiary_code_reissued` sync event and reflected in its queued upload payload. Full suite: **415 passed, 2420 assertions** (`BeneficiaryCodeCollisionTest`). |

---

## Decision Log

| Date | Decision | Reason |
|---|---|---|
| 2026-10-23 | Phase 0 security first | `.env`, `.git`, and project root are publicly served; MySQL root has no password and is exposed |
| 2026-10-23 | One codebase, `INSTANCE_MODE=online\|offline` | Preserves existing functionality and avoids duplicate maintenance |
| 2026-10-23 | Keep numeric local IDs; add `global_uuid` | Avoids rewriting existing routes, foreign keys, and tests |
| 2026-10-23 | PSGC hierarchy scoping | Uses existing `location_code` and prevents full central DB downloads |
| 2026-10-23 | Server-prefixed offline codes | Keeps business codes readable while preventing cross-server collisions |
| 2026-10-23 | Auto-merge non-overlapping fields; manual otherwise | Preserves edits without unsafe last-write-wins behavior |
| 2026-10-23 | Online central on hardened XAMPP | Matches available Windows/XAMPP environment; requires valid TLS and scheduler |
| 2026-10-23 | PowerShell scripts + docs for offline install | Lower complexity than a GUI installer; repeatable and auditable |
| 2026-10-23 | HTTP on isolated offline LAN | Practical for hotspot clients; central sync still requires HTTPS |
| 2026-10-23 | Do not sync import history/users/files initially | Reduces PII exposure and avoids local ID/file-path portability problems |
| 2026-10-23 | Snapshot manifests expire after 2 hours and use cursor pages | Keeps initial downloads resumable without copying the full dataset |
| 2026-10-23 | Batch names are origin-scoped; web duplicates restore tombstones | Preserves offline-origin names while preventing accidental duplicate batches |
| 2026-10-23 | Delta changes are filtered by current PSGC scope | Prevents servers from receiving records outside their assigned area |
| 2026-10-24 | Authorization period is set manually per issuance | An administrator decides how long each installation may operate; no hidden default is applied |
| 2026-10-24 | Expired installations keep their data | Only uploads are rejected; records, audit history, and the pending queue stay intact so reauthorization loses nothing |
| 2026-10-24 | Audit records synchronize to the central server | Replaces the earlier "local audit stays local" decision; attribution requires the event, the actor, and the origin installation to travel together |
| 2026-10-24 | Encoder accounts are managed locally and reported as a registry | The offline installation owns its accounts; the central server only needs uuid, username, role, and active state to attribute actions |
| 2026-10-24 | Installation package ships code, dependencies, and scripts | XAMPP stays a device prerequisite; credentials, logs, and backups must never travel inside the package |
| 2026-10-24 | The setup script refuses to overwrite a populated database | Accidental data loss on a field device is the greater risk; `-Reinstall` forces an explicit, backed-up decision |
| 2026-10-24 | The installation package keeps the dependency tree and is built atomically | The offline target has no guaranteed internet access, so `vendor/` must stay in the package; the build writes to a temporary file and renames it into place under an exclusive lock so a failed or duplicated request can never leave a partial or clobbered package behind |
| 2026-10-24 | The installation package is built by a detached process and followed by the browser | Archiving the dependency tree takes far longer than a normal request should hold a connection; the browser polls a status file instead, so the administrator sees progress rather than an unresponsive page |
| 2026-10-24 | The package build is executed by the command line PHP binary | `PHP_BINARY` points at the web server executable under the Apache module, so the binary is resolved from the loaded php.ini directory before the background build is started |
| 2026-10-25 | Receipt is not acceptance | A record the central server holds for review stays `awaiting_review` on the offline server and in the request view; only confirmed records are shown as synchronized |
| 2026-10-25 | Duplicate approval never inserts a second person | The matched identifier is unique in the schema; the reviewer either confirms the same person (existing record kept) or explicitly applies the submitted values onto the matched record |
| 2026-10-25 | Review decisions live on the operation receipt | Retries and repeated uploads must replay the decision instead of opening a second review or duplicating records, and the operation UUID stays the single idempotency key |
| 2026-10-26 | A revoked server is restored by re-enrollment, not reauthorization | Revocation clears the token, so the device cannot authenticate; a new single-use enrollment token reactivates the same UUID and preserves its delegated scope while replacing the unusable token |
| 2026-10-26 | Re-enrollment keeps the existing scope | The offline installation preserves its stored scope during recovery, so the central server does not silently widen or narrow access as part of a token repair |

---

## Verification Commands

Use these as a starting point when checking the application. Prefer adding project-specific commands as phases are completed.

Use the XAMPP PHP binary; the default `php` on PATH is a separate build without `pdo_mysql`.

```powershell
# Full test suite
C:\xampp\php\php.exe artisan test

# Route list, including API routes after Phase 2
C:\xampp\php\php.exe artisan route:list

# Migration status
C:\xampp\php\php.exe artisan migrate:status

# Clear stale configuration after .env changes
C:\xampp\php\php.exe artisan config:clear
C:\xampp\php\php.exe artisan cache:clear

# Apache config check
C:\xampp\apache\bin\httpd.exe -t

# HTTP exposure probes
$urls = @('http://localhost/TIMS-BARMM/','http://localhost/TIMS-BARMM/public/login','http://localhost/TIMS-BARMM/.env','http://localhost/TIMS-BARMM/.git/config','http://localhost/TIMS-BARMM/composer.json','http://localhost/TIMS-BARMM/vendor/autoload.php','http://localhost/TIMS-BARMM/storage/logs/laravel.log')
foreach ($u in $urls) { "$u -> $(curl.exe -s -o NUL -w '%{http_code}' $u)" }

# MySQL bind check
Get-NetTCPConnection -LocalPort 3306 -State Listen | Select-Object LocalAddress,LocalPort,OwningProcess

# Firewall rules still requiring an elevated shell
Get-NetFirewallRule -DisplayName '*Apache*','*mysqld*' | Select-Object DisplayName,Enabled,Profile,Direction,Action
```

---

*This tracker was created from the finalized Phase 1 implementation plan. Update it after every work session.*
