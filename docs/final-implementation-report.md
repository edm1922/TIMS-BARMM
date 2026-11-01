# TIMS-BARMM — Final Implementation Report

**Date:** 2026-10-25
**Specification:** `TIMS_BARMM_modification_specification.md`
**Status:** Phases 0–6 implemented and verified. Full suite: **359 passed, 2132 assertions**.

This report follows the ten points required by §9 of the specification. It describes what was
built, where it lives, and what was actually executed. Anything not verified is called out in the
limitations section instead of being presented as complete.

---

## 1. Beneficiary archive/delete

Two distinct lifecycle actions replaced the previous single "delete" behaviour.

**Archive (reversible).** `BeneficiaryController::archive()` / `unarchive()` — routes
`beneficiaries.archive`, `beneficiaries.unarchive`. Managers and administrators may archive
(`BeneficiaryPolicy::archive`); an optional reason is stored on the record
(`archived_at`, `archive_reason`) and both actions are audit-logged. Archived records stay visible
for reporting and can be restored. The archive state is synchronized as an `archived` /
`unarchived` operation.

**Delete (irreversible).** `BeneficiaryController::destroy()` — route `beneficiaries.destroy`,
administrator only (`BeneficiaryPolicy::delete`). Safeguards, all enforced server-side:

- the operator must retype the beneficiary ID (`confirm_code`) — a mismatch cancels the deletion;
- a record with an open sync conflict or an in-flight upload is refused with an actionable message
  (`hasBlockingSyncState`); nothing is deleted;
- pending/failed non-delete uploads for the record are marked `superseded` with a reason rather
  than silently dropped;
- an immutable audit snapshot (code, name, birthdate, location, revision, origin server, reason) is
  written **before** the row is removed;
- the deletion travels to the central server as a `deleted` operation; the central apply path
  hard-deletes the record, writes its own audit entry, and closes any open review conflict for it.

Tests: `BeneficiaryDeleteTest` (6), `BeneficiaryCrudTest` (16), `SyncOperationTest` delete cases.

## 2. ID Card removal

**Removed:** `app/Http/Controllers/IdCardController.php`, `resources/views/id-cards/`
(`index`, `front`, `back`, `print`), the `public/images/id-cards/header-band.png` asset,
`docs/id-card-layout.md`, `tests/Feature/IdCardPrintTest.php`, the sidebar/dashboard entries, and
every route (`/id-cards`, `/id-cards/print`, `/beneficiaries/{beneficiary}/card`). The nav icon
partial no longer renders an ID-card entry.

**Intentionally retained:** the beneficiary record itself (including `beneficiary_code`), the
beneficiary ID generator, and historical audit rows (`action = "id_card.printed"`) — deleting
history would falsify the audit trail. No ID-card-specific database tables existed, so no schema or
data migration was required.

Tests: `IdCardRemovalTest` (4) — retired routes return 404, the sidebar and dashboard no longer
link or mention the feature.

## 3. User Monitoring (Manager and Encoder accounts)

The central server shows a **User Monitoring** section on each offline server page
(`sync-servers/show.blade.php`, loaded by `SyncServerController::show`).

- The offline installation builds a minimal registry in `AuditSyncService::registry()`: the Manager
  account and every Encoder account, carrying only `encoder_uuid`, `username`, `name`, `role`, and
  `is_active` — never passwords, hashes, or contact data. The name lets the central server show who
  performed each audit action instead of a bare username.
- The registry travels with the audit push (`POST /api/sync/v1/audit-events`), which
  `AuditEventController` validates and stores with `updateOrCreate` into `offline_server_encoders`
  (`OfflineServerEncoder`), so repeated pushes update rather than duplicate.
- The Manager is created either by first-browser onboarding or, for a silent install, by
  `AdminUserSeeder` (role `manager` on an offline installation). Encoders are created by the
  Manager on the offline installation (`/encoders`, manager-only).
- The central view is read-only and labels each account Manager or Encoder.

Tests: `UserMonitoringTest` (3), `EncoderManagementTest` (10), `AuditSyncTest` (8).

## 4. Simplified installation and first-browser onboarding

**Package generation.** `InstallationPackageService` builds an installation archive from an
allowlist (`config/sync.php` → `installation_package.directories`), including `vendor/`, and writes:

- `SHA256SUMS.txt` (every packaged file, excluding the two generated documents);
- `INSTALL.txt` (safe reinstall procedure);
- a generated `.env.example` carrying this Central Server's address as `SYNC_BOOTSTRAP_URL`.

Credentials, `.env`, logs, backups and dot files are never packaged. The build runs in a detached
process (`offline-package:build`) with a status file (`/enroll-server/packages/status`), an exclusive
lock so two builds cannot race, and atomic rename into place. Administrators download or delete
packages from **Offline Installation**.

**Setup script.** `scripts/setup-offline.ps1` validates XAMPP, creates the database and user,
extracts the package, verifies the checksum manifest, runs migrations and the seeder, prepares
storage, configures Apache, and launches the browser. It refuses to overwrite a populated database
unless `-Reinstall` is given, in which case it backs up the database and `.env` first. It no longer
prompts for application URL, database credentials, or a Central Server URL.

**First browser access.** `EnsureInstallationConfigured` redirects a fresh offline installation to
`/onboarding`. `OnboardingController` collects the enrollment token, the Manager account, and the
area (scope), validates the scope against BARMM PSGC data, creates the Manager, enrolls the device,
downloads the initial snapshot (best effort), and logs `onboarding.completed`. Onboarding never
restarts once the device has a server UUID or any account, and a failed enrollment deletes the
half-created Manager so no orphan account remains. Errors are returned in plain language and no
token or secret is echoed back.

Tests: `OnboardingTest` (10), `InstallationPackageTest` (27), `AdminUserSeederTest` (7),
`UserMonitoringTest`.

## 5. Enrollment token and the trusted Central Server endpoint

The enrollment token proves *authorization*; it cannot by itself say *which* server to trust. The
trusted endpoint is deployment-level:

1. The package's `.env.example` is generated with `SYNC_BOOTSTRAP_URL=` set to the Central Server's
   own address (`InstallationPackageService::packagedEnvExample`); `config/sync.php` reads it as
   `bootstrap_url`.
2. Onboarding uses **only** that address — the operator never types a Central Server URL. If it is
   missing, onboarding refuses with an actionable message and does not guess.
3. `LocalEnrollmentService::enroll()` checks protocol/schema compatibility against the bootstrap URL
   before sending anything, then posts the one-time enrollment token.
4. The central `EnrollmentService` looks the token up by hash (plaintext is never stored), under a
   row lock, and rejects unknown, already-used, expired, or wrong-type tokens. It creates the
   server with the token's authorization period, marks the token used, and returns a server token.
5. The server token is stored encrypted on the device (`SyncSetting::setEncrypted`). Enrollment
   tokens are single-use; reauthorization tokens are separate, single-use, and bound to one server.

Tests: `OnboardingTest`, `SyncEnrollmentTest` (11), `OfflineAuthorizationTest` (17),
`InstallationPackageTest` (bootstrap URL and no-credentials cases).

## 6. Sync Now → request → review → decision → offline reconciliation

This is the phase-5 workflow, implemented end to end.

| Step | Behaviour |
|---|---|
| **Sync Now uploads** | `SyncRunner::upload()` posts pending operations. Central `OperationController` records every upload as a `SyncRequest` with one `SyncRequestItem` per operation (`SyncRequestService`), then applies them. |
| **Outcome per record** | Clean records are applied on receipt (`applied`). Strong-identifier duplicates and revision conflicts are **held** (`awaiting_review`) and nothing is written. Invalid records are rejected with a reason. |
| **Request status** | Derived from its records: `submitted` → `awaiting_review` → `accepted` / `partially_accepted` / `rejected`, never one generic "success". Each record keeps its own status. |
| **Review** | Admin-only **Sync Requests** page (sidebar badge) lists pending requests with server, area, submitter, counts (records, awaiting, duplicates, conflicts, invalid). The request page shows a review summary, the submitted values beside the central record, strong matches, weak-name advisories, conflict comparison, and a per-record guidance sentence. |
| **Decision** | Accept or reject. Reject requires a reason. Duplicate acceptance either confirms the same person (nothing inserted) or applies the submitted values onto the matched record. Conflict acceptance applies the submitted values, merges selected fields, or keeps central (rejects). Decisions are written onto the operation receipt and to an append-only decision history. |
| **Reconciliation** | `GET /api/sync/v1/request-outcomes` returns decided records (`since` cursor, server-scoped). During Sync Now, `SyncRunner::reconcileOutcomes()` marks the local operation synced or rejected (with the reason) and stores the cursor, so repeated runs are idempotent. Records still awaiting review stay `awaiting_review` locally and are never shown as synchronized. |

Tests: `SyncRequestReviewTest` (19), `SyncRequestOutcomeTest` (6), `SyncConflictResolutionTest` (9),
`SyncRunnerTest` (5), `SyncNowTest` (14).

## 7. Duplicate-detection rules and uncertain matches

`App\Services\DuplicateDetector` is layered and never merges or overwrites:

| Rule | Meaning | Handling |
|---|---|---|
| `beneficiary_code` | Same application-generated beneficiary ID | Strong: blocks the create, held for review |
| `source_id_number` | Same DOLE ID NUMBER | Strong: held for review |
| `source_id_no` | Same government ID No. (advisory in the source data) | Strong: held for review |
| `name_birthdate` | Normalized name + same birthdate | Weak: recorded as a candidate, the record is still applied |
| `name_only_weak` | Normalized name only | Weak: advisory only |

A name-only match never blocks and never merges; the record is created with
`duplicate_status = candidate` so an operator can review it later. The review page additionally
computes weak matches for each incoming created record and shows them as an advisory with the
matching records linked, explicitly labelled "weak match only; it is never merged automatically".

Tests: `DuplicateDetectionTest` (8), `SyncRequestReviewTest` duplicate and summary cases.

## 8. Files, routes, services, migrations, permissions changed

**Migrations (this work):**

- `2026_10_25_120000_create_sync_requests_tables.php` — `sync_requests`, `sync_request_items`
- `2026_10_25_130000_create_sync_request_decisions_table.php` — `sync_request_decisions`

**Models:** `SyncRequest`, `SyncRequestItem`, `SyncRequestDecision` (append-only; updates and
deletes throw).

**Services:** `SyncRequestService` (request creation, result recording, counters),
`SyncRequestReviewService` (accept/reject, conflicts, duplicates, history), changes in
`SyncOperationService` (`applyApproved`, `recordDecision`, duplicate-bypass for approved records),
`SyncRunner` (`awaiting_review`, `reconcileOutcomes`), `SyncNowService` (review step and messages),
`SyncClient` (`requestOutcomes`), `InstallationPackageService` (final progress report, fail-safe
process probe).

**Controllers:** `SyncRequestController` (new), `OutcomeController` (new), `OperationController`
(request metadata in the upload response), `LocalSyncController` (queue `awaiting_review` count).

**Routes:**

- `GET /sync-requests`, `GET /sync-requests/{request}`,
  `POST /sync-requests/{request}/items/{item}/approve`,
  `POST /sync-requests/{request}/items/{item}/reject` — inside `role:admin` +
  `instance.mode:online`.
- `GET /api/sync/v1/request-outcomes` — `sync.server` + `sync.authorized:read`.

**Views:** `sync-requests/index.blade.php`, `sync-requests/show.blade.php`, changes in
`sync/local/index.blade.php` and `layouts/app.blade.php` (nav entry with pending badge).

**Permissions enforced server-side:**

- Central review is administrator-only **(backend routes, not just the UI)**; staff receive 403.
- Offline Sync Setup is manager-only; encoder management is manager-only.
- API calls require an enrolled server token (`sync.server`) and a live authorization period
  (`sync.authorized:read|write`).
- A reviewer identity is always taken from the authenticated session; the payload cannot set an
  approval status or reviewer.

**Docs:** `docs/offline-installation-guide.md` §8 (conflict resolution, pending sync requests,
troubleshooting), `docs/sync-implementation-tracker.md` (changelog and decision log).

## 9. Automated tests executed and actual results

Command: `C:\xampp\php\php.exe artisan test` (SQLite in-memory, `RefreshDatabase`).

**Result: 359 passed, 2132 assertions** (last full run, ~7 minutes; exit code 0).

| Area | Classes (tests) |
|---|---|
| Sync review/approval | `SyncRequestReviewTest` (19), `SyncRequestOutcomeTest` (6) |
| Sync foundation | `SyncOperationTest` (23), `SyncRunnerTest` (5), `SyncEnrollmentTest` (11), `SyncSnapshotTest` (5), `SyncNowTest` (14), `SyncConflictResolutionTest` (9), `SyncServerAdminTest` (10), `AuditSyncTest` (8), `SyncFoundationTest` (7) |
| Installation/onboarding | `InstallationPackageTest` (27), `OnboardingTest` (10), `AdminUserSeederTest` (7), `AppUpdateTest` (21) |
| Beneficiaries | `BeneficiaryCrudTest` (16), `BeneficiaryDeleteTest` (6), `BeneficiaryBatchTest` (17), `BeneficiaryIdTest` (8), `BeneficiaryRegistrationTest` (15), `DuplicateDetectionTest` (8) |
| Removal and monitoring | `IdCardRemovalTest` (4), `UserMonitoringTest` (3), `EncoderManagementTest` (10), `SidebarNavigationTest` (10) |
| Authorization/other | `OfflineAuthorizationTest` (17), `LocalSyncTest` (13), `SessionExpiryTest` (5), import/report/age/dashboard suites |

Behaviour covered by the new tests: request creation, held duplicates, duplicate confirmation
(nothing inserted) and apply-onto-matched-record, conflict acceptance, rejection with a required
reason, double-decision guard, empty-merge guard, partial acceptance, review summary classification,
actionable invalid-record reason, immutable decisions, resubmission preserving earlier decisions,
per-record outcomes and cursor-based reconciliation, and retries that do not duplicate records,
requests, or audit events.

## 10. Remaining limitations and decisions for a separate change

1. **No bulk decisions.** Every held record is decided individually. This is deliberate: bulk
   approval would risk bypassing the per-record duplicate/conflict judgement. A future bulk action
   should apply only to records already classified as safe.
2. **A confirmed duplicate does not remap the offline record's UUID.** After "confirm same person"
   or "apply values onto the matched record", the local record is marked synced but keeps its own
   `global_uuid`; the central record keeps a different one. Later edits of that local record are
   sent under the local UUID and will be rejected as `not_found`. Identity remapping on the device
   (merge the local row into the central identity) is a separate, higher-risk change.
3. **Decisions are pulled, not pushed.** The offline installation learns a decision on its next
   Sync Now; there is no push channel. Awaiting records are clearly labelled in the meantime.
4. **Weak name matches are advisory only.** They are never auto-merged (by design) and do not block
   a record; a record can therefore be created alongside a similar name until a reviewer acts.
5. **`keep_central` is a rejection of the submitted change.** The conflict is closed and the offline
   record is marked rejected with the reason; the offline data itself is preserved.
6. **Windows process-liveness probe fails safe.** If the OS process list cannot be read (restricted
   host), a build recorded as running is assumed alive. This prevents a duplicate build but means a
   genuinely dead build may read as running until its status file is replaced.
7. **§6 items E and F were not in the selected scope.** Enrollment and first-browser setup already
   avoid unnecessary manual configuration (E); deletion and duplicate handling already carry
   confirmation, permissions, audit, and before/after comparison (F). No additional code was added
   for them in this phase beyond what sections 1 and 7 describe.
8. **No git commits were made.** The working tree contains earlier phase work as tracked
   modifications and untracked files; committing is left to the project owner.
