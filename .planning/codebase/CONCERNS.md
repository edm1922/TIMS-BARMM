---
last_mapped_commit: 4a2a41257534aaecfd2249163155617f6d9a4bc1
last_mapped_at: 2026-10-23
---
<!-- refreshed: 2026-10-23 -->

# Codebase Concerns

**Analysis Date:** 2026-10-23

## Tech Debt

### Large Service Classes (God Objects)

**SyncOperationService.php** (`app/Services/Sync/SyncOperationService.php:1-840`)
- 840 lines, 25+ private methods
- Handles beneficiary and batch sync operations, conflict resolution, auto-merge, duplicate detection
- Violates Single Responsibility Principle — should be split into `BeneficiarySyncHandler`, `BatchSyncHandler`, `ConflictResolver`, `AutoMerger`
- High cyclomatic complexity in `applyBeneficiary()` (lines 283-434) and `conflictOrMerge()` (lines 611-674)

**ImportService.php** (`app/Services/Import/ImportService.php:1-508`)
- 508 lines, handles preview, apply, classification, lookup loading, location resolution
- `classify()` method (lines 324-428) is 100+ lines with nested conditionals
- Should separate `ImportPreviewService`, `ImportApplyService`, `ImportClassifier`

**BeneficiaryController.php** (`app/Http/Controllers/BeneficiaryController.php:1-341`)
- 341 lines, mixes CRUD, duplicate checking, archiving, import-in-batch logic
- Should delegate to dedicated actions/services

**Beneficiary.php model** (`app/Models/Beneficiary.php:1-387`)
- 387 lines, 58 fillable fields, 8 scopes, 9 accessors
- Booted callbacks create tight coupling to `DuplicateDetector`, `BeneficiaryIdGenerator`, `SyncIdentity`
- Consider extracting duplicate detection logic to observer or service

### Return Null Anti-Pattern

Widespread use of `return null` instead of proper null handling or exceptions:

| File | Lines | Context |
|------|-------|---------|
| `PreparesBeneficiaryInput.php` | 24, 40, 57, 78, 98 | Validation trait returns null for missing fields |
| `AuthenticateSyncServer.php` | 74 | Middleware returns null token |
| `TokenService.php` | 45 | Token lookup returns null |
| `RecordsSyncChanges.php` | 72 | Trait method returns null |
| `SyncPayload.php` | 76 | Returns empty array |
| `SyncOperationRecorder.php` | 43, 49 | Suppressed recording returns null |
| `Beneficiary.php` | 345, 361 | Accessors return null |
| `SnapshotDownloadService.php` | 184, 192, 200 | Progress loading returns null |
| `WorkbookInspector.php` | 197 | Sheet lookup returns null |
| `RowParser.php` | 34, 171, 253 | Parsing returns null |
| `BeneficiaryReport.php` | 209 | Report generation returns null |
| `DuplicateDetector.php` | 54, 75, 92, 105 | Detection returns null |
| `DashboardStats.php` | 155 | Stats query returns null |
| `AgeService.php` | 15, 19 | Age calculation returns null |
| `AuditLogController.php` | 69 | Controller returns null |
| `BeneficiaryOptions.php` | 154 | Options lookup returns null |

**Impact:** Callers must defensively check for null, leading to scattered `??` and `if ($x !== null)` checks. Silent failures possible when null propagates.

**Fix:** Use `Optional` pattern, throw typed exceptions, or return empty collections/DTOs.

### Silent Exception Swallowing

**SyncOperationService.php:836-838** (`app/Services/Sync/SyncOperationService.php`)

```php
} catch (Throwable) {
    // A concurrent retry may have written the same idempotency receipt.
}
```

Catches all exceptions and discards them. At minimum should log the exception.

**SyncRunner.php:224-229** (`app/Services/Sync/SyncRunner.php`)

```php
private function retryLater(SyncOperation $operation, string $message): void
{
    $seconds = min(3600, 60 * (2 ** min(6, $operation->attempts)));
    $operation->markFailed($message, $seconds);
}
```

Exponential backoff but no max retry limit — operations can retry indefinitely.

**LocalChangeApplier.php:155-191** marks conflicts but doesn't record which specific fields caused the overlap for local pending operations.

### Hardcoded Configuration Values

| File | Line | Hardcoded Value |
|------|------|-----------------|
| `SyncOperationService.php` | 51 | `config('sync.upload_batch_size', 50)` default |
| `ImportService.php` | 23 | `MAX_ROWS = 5000` |
| `ImportService.php` | 25 | `EMPTY_ROW_GRACE = 5` |
| `ScopeService.php` | 49, 102 | `whereRaw('1 = 0')` for empty scopes |
| `SnapshotDownloadService.php` | 197 | 2-hour snapshot expiry |
| `SnapshotDownloadService.php` | 33 | Max 2 retry attempts |
| `TimsBackup.php` | 59 | 300-second mysqldump timeout |

These should be configurable via `config/sync.php` and `config/import.php`.

### Duplicate Detection Logic Duplication

Duplicate detection runs in three places with subtle differences:
1. `Beneficiary::booted()` creating event (lines 108-122) — `app/Models/Beneficiary.php`
2. `SyncOperationService::applyBeneficiary()` lines 320-332, 409-422
3. `ImportService::classify()` lines 356-416
4. `DuplicateDetector::find()` and `findForRegistration()`

**Risk:** Inconsistent duplicate rules between sync, import, and manual entry.

### Inconsistent Naming: `source_id_number` vs `source_id_no`

Throughout codebase both fields exist:
- `source_id_number` — DOLE ID NUMBER (primary duplicate key)
- `source_id_no` — Government ID No. (advisory, repeats in source)

Used interchangeably in some queries. `DuplicateDetector.php` checks both at different priority levels (lines 34-50). Confusion risk for operators and developers.

### Magic Strings for Sync Statuses

Status values scattered as string literals:
- `'synced'`, `'conflict'`, `'pending'`, `'rejected'`, `'applied'`, `'duplicate'`
- `'active'`, `'suspended'`, `'revoked'` for server status
- `'open'`, `'resolved'` for conflicts

Should be centralized in enums or constants (PHP 8.1+ backed enums available).

## Known Bugs

### Snapshot Verification False Positives

**SnapshotDownloadService.php:126-134** (`app/Services/Sync/SnapshotDownloadService.php`)

```php
if ($expected !== null && (int) $expected !== $beneficiaries) {
    $this->clearProgress();
    throw new RuntimeException(sprintf(
        'Snapshot verification failed: expected %s beneficiaries but imported %s.',
        number_format((int) $expected),
        number_format($beneficiaries),
    ));
}
```

Compares central manifest count against imported count. Fails if:
- Central server has records outside offline server's scope (filtered out during import)
- Records deleted between manifest creation and page download
- Network partition causes partial download

**Fix:** Track expected vs actual per-page, allow scope-filtered variance.

### Sync Conflict Auto-Merge Edge Cases

**SyncOperationService.php:659-661** (`app/Services/Sync/SyncOperationService.php`)

```php
if ($overlapping === [] && $changedFields !== []) {
    return $this->autoMerge($operation, $localPayload, $model, $remoteRevision, $entityType);
}
```

Auto-merge triggers when `$changedFields` is non-empty but `$overlapping` is empty. However:
- If `changed_fields` not sent by client (line 623-625), defaults to `array_keys($localPayload)`
- This treats ALL payload fields as "changed", causing false conflicts when central has values
- No distinction between "field not sent" vs "field sent with same value"

### Race Condition in Beneficiary Code Generation

**Beneficiary.php:138-174** (`app/Models/Beneficiary.php`)

```php
public static function createWithCode(array $attributes, int $attempts = 5): static
{
    for ($attempt = 0; ; $attempt++) {
        try {
            return static::create($attributes);
        } catch (QueryException $e) {
            // ... retry with new code
        }
    }
}
```

Retry loop catches `23000` (integrity constraint) on `beneficiary_code`. However:
- No `SELECT ... FOR UPDATE` on code generator — concurrent requests can generate same code
- Max 5 attempts may be insufficient under high concurrency
- Generator uses time-based suffix; same-second collisions possible

### Offline Server Scope Check Bypass

**SyncOperationService.php:354-356** (`app/Services/Sync/SyncOperationService.php`)

```php
if (! $this->scopes->allowsLocationCode($server, $beneficiary->location_code)) {
    return $this->result($operation, 'rejected', 'out_of_scope', 'The beneficiary is outside this server scope.');
}
```

Check runs AFTER revision conflict detection (line 358). An offline server could:
1. Send update for entity outside their scope
2. If revision matches, conflict check passes
3. Scope check runs but operation already validated

Order should be: scope check first, then revision check.

### Empty Scope Returns Impossible Query

**ScopeService.php:48-50** and **101-103** (`app/Services/Sync/ScopeService.php`)

```php
if ($prefixes === []) {
    return $query->whereRaw('1 = 0');
}
```

Returns query that matches zero rows. Callers don't check `hasScope()` first. Better to throw `ScopeNotConfiguredException` to fail fast and surface misconfiguration.

## Security Considerations

### Plaintext Database Password in Backup Command

**TimsBackup.php:35, 55** (`app/Console/Commands/TimsBackup.php`)

```php
$password = $dbConfig['password'] ?? '';
$env = ['MYSQL_PWD' => $password];
```

Password passed via `MYSQL_PWD` environment variable to `mysqldump` process.
- Visible in process table (`ps aux`) during dump
- Written to `.env` in plaintext
- No encryption at rest for backups

**Fix:** Use `--defaults-extra-file` with restricted permissions, or Laravel's built-in database backup packages.

### Raw SQL in Queries (SQL Injection Surface)

Multiple `whereRaw` calls with user-controlled or interpolated values:

| File | Line | Query |
|------|------|-------|
| `ScopeService.php` | 49, 102 | `whereRaw('1 = 0')` — safe but fragile |
| `DashboardStats.php` | 42-46, 54-55 | `selectRaw` with interpolated `$duplicateStatuses` |
| `ImportService.php` | 457 | `whereRaw('UPPER(full_address) = ?', [mb_strtoupper($needle)])` — parameterized, safe |
| `Location.php` | 45 | `whereRaw('UPPER(barangay) LIKE ?', [$needle])` — parameterized, safe |
| `BeneficiaryBatchController.php` | 84 | `whereRaw('LOWER(name) = ?', [mb_strtolower($name)])` — parameterized, safe |
| `ImportController.php` | 255 | `selectRaw('outcome, COUNT(*) as total')` — safe |

**Risk:** `DashboardStats.php` interpolates `$duplicateStatuses` directly into SQL string (line 46). If `$duplicateStatuses` comes from user input, SQL injection possible.

### Token Exposure in Logs

**SyncOperationRecorder.php** (`app/Services/Sync/SyncOperationRecorder.php`) uses static `$suppressed` counter to disable recording. If recording fails mid-request, subsequent operations in same process may not be recorded.

**TokenService.php** stores `api_token_hash` (line 38 in `OfflineServer.php` hidden) but token generation uses `Str::random(40)` — adequate entropy but no rotation policy enforced.

### Missing Rate Limiting on Critical Endpoints

**routes/api.php** (lines 18, 21):
- `/enroll` has `throttle:10,1` — OK
- Sync endpoints have `throttle:120,1` — generous for automated sync
- No rate limiting on `/ack`, `/conflicts`, `/snapshot` endpoints

### Insecure Default Configuration

**config/sync.php** (lines 17-21):

```php
'instance_mode' => env('INSTANCE_MODE', 'online'),
'central_server_uuid' => env('CENTRAL_SERVER_UUID', '00000000-0000-4000-8000-000000000001'),
'local_server_uuid' => env('LOCAL_SERVER_UUID'),
```

Default `CENTRAL_SERVER_UUID` is a known constant. Production deployments MUST override. No validation on startup.

## Performance Bottlenecks

### N+1 Queries in Sync Operations

**SyncOperationService.php:75** processes operations with `array_map` calling `processOne` per operation:

```php
return array_map(fn (array $operation): array => $this->processOne($server, $operation), $operations);
```

Each `processOne` runs separate DB transactions (line 238). For batch of 50 operations = 50 transactions.

**Fix:** Batch operations in single transaction where possible, or use pipeline pattern.

### DuplicateDetector Loads Full Collections

**DuplicateDetector.php:27, 35, 45-49, 69** (`app/Services/DuplicateDetector.php`)

```php
$m = $base(Beneficiary::query())->where('beneficiary_code', $beneficiaryCode)->get();
```

Returns full `Collection<Beneficiary>` for each rule check. Only needs `count()` and `pluck('id')` for candidate IDs.

**Fix:** Use `exists()`, `count()`, `pluck('id')` instead of `get()`.

### Snapshot Download Imports Row-by-Row

**SnapshotDownloadService.php:312-338** (`app/Services/Sync/SnapshotDownloadService.php`)

```php
foreach ($rows as $row) {
    Beneficiary::query()->updateOrCreate(
        ['global_uuid' => $fields['global_uuid']],
        $fields,
    );
}
```

For 50,000 beneficiaries = 50,000 individual `updateOrCreate` queries inside transaction.

**Fix:** Use `upsert()` with chunked batches (MySQL 8.0+/MariaDB 10.3+), or `INSERT ... ON DUPLICATE KEY UPDATE` via raw query.

### ScopeService Prefix Computation on Every Check

**ScopeService.php:67-76** (`app/Services/Sync/ScopeService.php`)

```php
public function prefixes(OfflineServer $server): array
{
    return $server->scopes()
        ->whereNotNull('location_code')
        ->get()
        ->map(fn (OfflineServerScope $scope): string => $this->prefixFor($scope))
        ->filter()
        ->unique()
        ->values()
        ->all();
}
```

Called multiple times per request (lines 23, 46, 99). Computes prefixes from DB each call.

**Fix:** Cache prefixes on server model, invalidate on scope change.

### Import Preview Loads All Existing Records

**ImportService.php:294-314** (`app/Services/Import/ImportService.php`)

```php
'by_code' => collect(array_unique($codes))
    ->chunk(1000)
    ->flatMap(fn ($chunk) => $base->clone()->whereIn('beneficiary_code', $chunk->all())->get())
    ->groupBy('beneficiary_code')
    ->all(),
```

Loads ALL matching beneficiaries into memory for `by_code`, `by_source_id_number`, `by_name_key`. For large imports (5000 rows) against large database (100k+ beneficiaries), memory intensive.

**Fix:** Stream/chunk processing, or use temporary tables.

### Missing Database Indexes

**Migration 2026_10_22_000011** adds search indexes but missing:
- Composite index on `beneficiaries(origin_server_uuid, global_uuid)` for sync lookups
- Index on `beneficiaries(location_code, archived_at)` for scoped queries
- Index on `sync_changes(offline_server_id, change_uuid)` for receipt lookups
- Index on `sync_operations(status, entity_type, entity_uuid)` for pending queries

## Fragile Areas

### Sync Protocol Version Compatibility

**SyncIdentity.php** (`app/Support/SyncIdentity.php`) and **AuthenticateSyncServer.php** (lines 32-48)
- Protocol and schema version checked via headers
- `SyncIdentity::supports()` and `compatibility()` logic not visible but critical
- No automated compatibility testing between versions
- Breaking changes require coordinated deployment

### Complex Conflict Resolution State Machine

**SyncOperationService.php** conflict flow:

```
applyBeneficiary → conflictOrMerge → (autoMerge | conflict)
                                         ↓
                              SyncConflict created
                                         ↓
                              Manual resolution via API
                                         ↓
                              resolveConflict() → applyResolvedConflictPayload
```

State transitions: `pending → conflict → open → resolved` (or `rejected`)
- No formal state machine validation
- `SyncConflict::status` can be manually set to invalid values
- Race condition: local operation marked conflict (line 173 in `LocalChangeApplier.php`) while central also creates conflict

### Date/Time Handling Inconsistencies

- `birthdate` cast to `date` (line 82 `Beneficiary.php`)
- `archived_at` cast to `datetime` (line 83)
- `SyncSetting::get('last_sync_at')` stored as ISO8601 string (line 38 `SyncRunner.php`)
- `Carbon::parse()` used in `SnapshotDownloadService.php:195` for progress expiry
- `now()` used throughout — timezone depends on `APP_TIMEZONE`

No explicit UTC enforcement for sync timestamps. Cross-server sync may have timezone drift.

### Queue Job Failure Handling

**composer.json** shows `QUEUE_CONNECTION=database` (line 30 in `.env.example`).
- No `failed_jobs` table migration visible
- No retry/backoff configuration for sync jobs
- `TimsSync` command runs inline (not queued) — blocks scheduler

### Test Coverage Gaps

| Area | Test File | Coverage Notes |
|------|-----------|----------------|
| Sync conflict resolution | `SyncOperationTest.php` | Good coverage for API flow |
| Local sync (offline→central) | `LocalSyncTest.php` | 15KB test file — comprehensive |
| Import workflow | `ImportWorkflowTest.php` | 18KB — comprehensive |
| Snapshot download | `SyncSnapshotTest.php` | 7KB — basic |
| Sync runner | `SyncRunnerTest.php` | 8KB — basic |
| Duplicate detection | `DuplicateDetectionTest.php` | 5KB — basic |
| **Age calculation** | `AgeStatusTest.php` | Only 3.5KB — edge cases? |
| **BeneficiaryIdGenerator** | `BeneficiaryIdTest.php` | Only 4KB — collision handling? |
| **ScopeService** | None found | **Missing unit tests** |
| **TokenService** | None found | **Missing unit tests** |
| **SnapshotDownloadService** | None found | **Missing unit tests** |
| **LocalChangeApplier** | None found | **Missing unit tests** |

**Critical gaps:** ScopeService (security boundary), TokenService (auth), SnapshotDownloadService (data integrity), LocalChangeApplier (conflict detection).

### Configuration Drift Risk

**config/sync.php** values used throughout but no validation on boot:
- `instance_mode` must be `'online'` or `'offline'`
- `central_server_uuid` required for offline mode
- `protocol_version`/`schema_version` must match central
- No `config:sync:validate` command

## Scaling Limits

### Sync Batch Size

**config/sync.php:65** `upload_batch_size = 50`
- Hardcoded limit per sync cycle
- 50 operations × ~50ms each = 2.5s minimum cycle
- Cannot scale to high-frequency sync without increasing

### Snapshot Page Size

**config/sync.php:61** `snapshot_page_size = 500`
- 500 beneficiaries per HTTP response
- For 100k beneficiaries = 200 HTTP requests
- No parallel download support

### Database Connection Pool

Default Laravel/MySQL: no persistent connections configured.
- Each sync cycle opens new connections
- Under load, connection exhaustion possible

### File Storage

**TimsBackup.php** writes to `storage/app/backups/` (local disk).
- No S3/cloud backup configured
- Single point of failure
- No backup verification/test restore process

## Dependencies at Risk

| Package | Version | Risk |
|---------|---------|------|
| `laravel/framework` | `^12.0` | Major version — breaking changes likely on upgrade |
| `phpoffice/phpspreadsheet` | `^5.10` | Memory intensive for large imports; 5000-row limit mitigates |
| `phpunit/phpunit` | `^11.5` | Test runner — dev dependency only |

**phpspreadsheet** known issues:
- Loads entire spreadsheet into memory
- `ImportService::MAX_ROWS = 5000` prevents OOM but limits legitimate imports
- No streaming reader used

## Missing Critical Features

### No Sync Health Monitoring

- No `/health` endpoint exposing sync lag, conflict count, last success
- `SyncRunner.php` catches exceptions but only stores error in `SyncSetting`
- No alerting on consecutive sync failures

### No Automated Conflict Resolution

- All conflicts require manual admin review (`SyncConflictController`)
- No "last write wins" or "central wins" policy automation
- Conflicts block sync for affected entities indefinitely

### No Data Retention Policy

- `SyncChange`, `SyncEvent`, `SyncSnapshot` tables grow unbounded
- No cleanup jobs for old sync metadata
- `TimsBackup` only backs up database, doesn't purge old sync data

### No Multi-Tenancy Support

- Single `central_server_uuid` per installation
- Cannot serve multiple independent BARMM regions from one deployment
- `OfflineServer` scopes are per-server, not per-tenant

## Test Coverage Gaps (Priority)

| Priority | Area | Missing Tests |
|----------|------|---------------|
| **High** | `ScopeService` | Scope prefix computation, query application, boundary conditions |
| **High** | `TokenService` | Token generation, hashing, rotation, expiry, revocation |
| **High** | `SnapshotDownloadService` | Resume after expiry, partial download, verification mismatch |
| **High** | `LocalChangeApplier` | Concurrent local+central edits, conflict marking accuracy |
| **Medium** | `BeneficiaryIdGenerator` | Collision handling, suffix exhaustion, Unicode names |
| **Medium** | `AgeService` | Leap years, timezone edge cases, null birthdates |
| **Medium** | `NameNormalizer` | Hyphenated names, diacritics, empty segments |
| **Low** | `SyncOperationRecorder` | Suppression nesting, concurrent recording |

---

*Concerns audit: 2026-10-23*
