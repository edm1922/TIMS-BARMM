---
last_mapped_commit: 4a2a41257534aaecfd2249163155617f6d9a4bc1
last_mapped_at: 2026-10-23
---
<!-- refreshed: 2026-10-23 -->

# Architecture

**Analysis Date:** 2026-10-23

## System Overview

```text
┌─────────────────────────────────────────────────────────────────────────────┐
│                              PRESENTATION LAYER                              │
├──────────────────────────────┬──────────────────────────────────────────────┤
│         WEB UI (Blade)       │           SYNC API (JSON)                    │
│  `resources/views/**`        │  `routes/api.php` → `Api\Sync\*Controller`   │
│  `routes/web.php`            │  `app/Http/Middleware/AuthenticateSyncServer`│
│  `app/Http/Controllers/*`    │                                              │
└──────────────┬───────────────┴────────────────────┬─────────────────────────┘
               │                                     │
               ▼                                     ▼
┌─────────────────────────────────────────────────────────────────────────────┐
│                              APPLICATION LAYER                               │
├──────────────────────────────┬──────────────────────────────────────────────┤
│     WEB CONTROLLERS          │              SYNC SERVICES                    │
│  `app/Http/Controllers/`     │  `app/Services/Sync/`                        │
│  - BeneficiaryController     │  - SyncOperationService (core)               │
│  - BeneficiaryBatchController│  - SyncRunner, SyncClient                    │
│  - ImportController          │  - SnapshotDownloadService                   │
│  - SyncServerController      │  - LocalChangeApplier, LocalEnrollmentService│
│  - LocalSyncController       │  - ScopeService, TokenService                │
│                              │  - SyncDiagnostics, SyncOperationRecorder    │
└──────────────┬───────────────┴────────────────────┬─────────────────────────┘
               │                                     │
               ▼                                     ▼
┌─────────────────────────────────────────────────────────────────────────────┐
│                              DOMAIN LAYER                                    │
├──────────────────────────────────────────────────────────────────────────────┤
│  MODELS & ENTITIES (`app/Models/`)                                           │
│  - Beneficiary, BeneficiaryBatch          Core domain entities               │
│  - OfflineServer, OfflineServerScope      Sync topology                      │
│  - SyncChange, SyncConflict, SyncSnapshot Sync protocol                      │
│  - SyncEvent, SyncCheckpoint              Observability                      │
│  - ImportBatch, ImportRow, AuditLog       Operational                        │
│  - User, Location, Training               Reference                          │
│                                                                            │
│  TRAITS & CONCERNS                                                         │
│  - `RecordsSyncChanges` trait → auto-tracks create/update/delete           │
│  - `HasFactory` trait → test factories                                     │
└──────────────────────────────────────────────────────────────────────────────┘
               │
               ▼
┌─────────────────────────────────────────────────────────────────────────────┐
│                              DATA LAYER                                      │
├──────────────────────────────────────────────────────────────────────────────┤
│  Eloquent ORM → MySQL/SQLite                                               │
│  Migrations: `database/migrations/` (31 migrations)                         │
│  Seeders: `database/seeders/` + `database/seeders/data/barmm-locations.json`│
└─────────────────────────────────────────────────────────────────────────────┘
```

## Component Responsibilities

| Component | Responsibility | File |
|-----------|----------------|------|
| `Beneficiary` | Core domain entity with sync metadata, duplicate detection, ID generation | `app/Models/Beneficiary.php` |
| `BeneficiaryBatch` | Operational release/payout batch; distinct from import batches | `app/Models/BeneficiaryBatch.php` |
| `OfflineServer` | Represents an enrolled offline installation; owns scopes & tokens | `app/Models/OfflineServer.php` |
| `SyncOperationService` | Core sync logic: apply operations, detect conflicts, auto-merge, create receipts | `app/Services/Sync/SyncOperationService.php` |
| `SyncRunner` | Orchestrates full sync cycle: download snapshot → apply changes → upload operations | `app/Services/Sync/SyncRunner.php` |
| `SyncClient` | HTTP client for offline→central communication | `app/Services/Sync/SyncClient.php` |
| `SnapshotDownloadService` | Streams central data to offline server in pages | `app/Services/Sync/SnapshotDownloadService.php` |
| `LocalChangeApplier` | Applies downloaded central changes to local DB | `app/Services/Sync/LocalChangeApplier.php` |
| `ScopeService` | Enforces location-code scoping per offline server | `app/Services/Sync/ScopeService.php` |
| `TokenService` | Issues/validates enrollment tokens & server tokens | `app/Services/Sync/TokenService.php` |
| `AuthenticateSyncServer` | Middleware: validates server token, protocol version, status | `app/Http/Middleware/AuthenticateSyncServer.php` |
| `SyncIdentity` | Central vs offline mode; UUID resolution; compatibility matrix | `app/Support/SyncIdentity.php` |
| `RecordsSyncChanges` | Trait: auto-creates `SyncChange` records on model mutations | `app/Models/Concerns/RecordsSyncChanges.php` |

## Pattern Overview

**Overall:** Laravel MVC + Offline-First Sync Protocol (custom)

**Key Characteristics:**
- **Central/Online + Offline topology**: Single authoritative central server (`INSTANCE_MODE=online`) with multiple enrolled offline servers (`INSTANCE_MODE=offline`)
- **UUID + Revision-based sync**: Every entity has `global_uuid` + `revision` + `server_revision`; operations carry `base_revision` for optimistic concurrency
- **Change capture via trait**: `RecordsSyncChanges` trait on models auto-writes `SyncChange` rows on create/update/delete/archive
- **Three-phase sync protocol**: 
  1. **Enrollment** → offline server gets token + scope config
  2. **Snapshot** → full data download (paginated)
  3. **Delta** → bidirectional operation exchange with conflict detection
- **Conflict resolution**: Field-level auto-merge when non-overlapping; manual resolution UI for overlapping fields
- **Idempotent operations**: `SyncChange` receipts with `operation_uuid` prevent duplicate application
- **Scoping**: Each offline server limited to specific `location_code` values via `OfflineServerScope`

## Layers

**Presentation Layer:**
- Purpose: HTTP request handling, response rendering (HTML/JSON)
- Location: `app/Http/Controllers/`, `routes/web.php`, `routes/api.php`, `resources/views/`
- Contains: Web controllers (UI), API controllers (sync protocol), Blade views
- Depends on: Application layer (Services), Domain layer (Models)
- Used by: Web browsers, Offline server sync clients

**Application Layer (Services):**
- Purpose: Business logic, orchestration, sync protocol implementation
- Location: `app/Services/` (Sync, Import, Export, Reports)
- Contains: Service classes with single responsibilities
- Depends on: Domain layer (Models), Support utilities
- Used by: Controllers (web & API), Console commands, Jobs

**Domain Layer:**
- Purpose: Core business entities, rules, invariants
- Location: `app/Models/`, `app/Models/Concerns/`, `app/Support/`
- Contains: Eloquent models, traits, value objects, domain enums
- Depends on: Laravel Eloquent, PHP stdlib
- Used by: Application layer, Presentation layer

**Data Layer:**
- Purpose: Persistence, schema, seed data
- Location: `database/migrations/`, `database/seeders/`, `database/factories/`
- Contains: Migration files, seeder classes, test factories
- Depends on: Domain layer (models)
- Used by: Application layer via Eloquent

## Data Flow

### Primary Web Request Path (Beneficiary CRUD)

1. **Route** → `routes/web.php` (e.g., `PUT /beneficiaries/{beneficiary}`)
2. **Controller** → `BeneficiaryController@update` (`app/Http/Controllers/BeneficiaryController.php:110`)
3. **Service** → (direct model manipulation in controller for simple cases) or `DuplicateDetector`, `BeneficiaryIdGenerator`
4. **Model** → `Beneficiary::update()` → triggers `RecordsSyncChanges` trait
5. **Trait** → `RecordsSyncChanges::recordChange()` creates `SyncChange` row (`app/Models/Concerns/RecordsSyncChanges.php:45`)
6. **Database** → MySQL/SQLite via Eloquent

### Sync API Request Path (Offline → Central)

1. **Route** → `routes/api.php` → `POST /sync/v1/operations` (`OperationController@store`)
2. **Middleware** → `AuthenticateSyncServer` validates token, protocol, server status (`app/Http/Middleware/AuthenticateSyncServer.php:15`)
3. **Controller** → `OperationController@store` calls `SyncOperationService::process()` (`app/Http/Controllers/Api/Sync/OperationController.php:35`)
4. **Service** → `SyncOperationService::process()` → `apply()` → `applyBeneficiary()` / `applyBatch()` (`app/Services/Sync/SyncOperationService.php:75`)
5. **Conflict Detection** → `conflictOrMerge()` compares `changed_fields` vs remote payload (`app/Services/Sync/SyncOperationService.php:611`)
6. **Auto-Merge** → if no field overlap, applies non-overlapping fields directly (`autoMerge()` at line 685)
7. **Conflict Creation** → if overlap, creates `SyncConflict` + updates `conflict_status` (`conflict()` at line 730)
8. **Receipt** → `writeReceipt()` stores `SyncChange` with result for idempotency (`writeReceipt()` at line 820)
9. **Response** → JSON result per operation

### Local Sync Cycle (Offline Server Initiated)

1. **Entry** → `LocalSyncController@run` (`app/Http/Controllers/LocalSyncController.php:130`)
2. **Runner** → `SyncRunner::run()` orchestrates (`app/Services/Sync/SyncRunner.php:45`)
3. **Snapshot** → `SnapshotDownloadService::download()` fetches beneficiaries in pages (`app/Services/Sync/SnapshotDownloadService.php:65`)
4. **Apply Remote** → `LocalChangeApplier::apply()` upserts downloaded records (`app/Services/Sync/LocalChangeApplier.php:55`)
5. **Checkpoint** → `SyncCheckpoint` records progress for resumability
6. **Upload Local** → `SyncClient::pushOperations()` sends local `SyncChange` records (`app/Services/Sync/SyncClient.php:85`)
7. **Conflict Handling** → Server returns conflicts → `LocalEnrollmentService` surfaces for UI

### Conflict Resolution Flow

1. **Detection** → `SyncOperationService::conflictOrMerge()` finds overlapping changed fields
2. **Storage** → `SyncConflict` created with `local_payload`, `remote_payload`, `diff` fields
3. **UI** → `SyncConflictController@show` (`app/Http/Controllers/SyncConflictController.php:45`) or web `sync-conflicts/{id}`
4. **Resolution** → User chooses `keep_central`, `accept_local`, or `merge` with field selection
5. **Application** → `SyncOperationService::resolveConflict()` applies chosen payload, clears `conflict_status` (`app/Services/Sync/SyncOperationService.php:84`)

**State Management:**
- **Entity state**: `sync_status` (synced/pending/conflict), `conflict_status` (none/open/resolved), `revision` (optimistic lock)
- **Server state**: `OfflineServer.status` (active/suspended/revoked), `last_sync_at`, `last_seen_at`
- **Sync progress**: `SyncCheckpoint` tracks snapshot download position for resumability
- **Audit trail**: `AuditLog` for user actions; `SyncEvent` for sync protocol events

## Key Abstractions

**SyncIdentity:**
- Purpose: Centralized instance mode detection, UUID resolution, protocol compatibility
- Examples: `app/Support/SyncIdentity.php`
- Pattern: Static facade over config + database settings

**RecordsSyncChanges (Trait):**
- Purpose: Transparent change capture for sync protocol
- Examples: Used on `Beneficiary`, `BeneficiaryBatch`
- Pattern: Eloquent model events (`creating`, `updating`, `deleting`, `restored`) → `SyncChange::create()`

**SyncOperation (DTO-like array):**
- Purpose: Standardized operation payload between offline/central
- Structure: `operation_uuid`, `entity_type`, `entity_uuid`, `operation` (created/updated/deleted/archived/restored), `payload`, `base_revision`, `changed_fields`
- Examples: `SyncOperationService::process()` input, `SyncClient::pushOperations()` output

**ScopeService:**
- Purpose: Enforces data partitioning per offline server via `location_code`
- Examples: `app/Services/Sync/ScopeService.php` → `allowsLocationCode($server, $code)`

**DuplicateDetector:**
- Purpose: Finds potential duplicate beneficiaries across multiple matching rules
- Rules: `beneficiary_code`, `source_id_number`, `source_id_no`, `name_key` (normalized name)
- Examples: `app/Services/DuplicateDetector.php` → `find()` returns `{rule, matches}`

## Entry Points

**Web Application:**
- Location: `public/index.php` → `bootstrap/app.php` → `routes/web.php`
- Triggers: HTTP requests from browsers
- Responsibilities: Session auth, role middleware, Blade rendering, form handling

**Sync API:**
- Location: `routes/api.php` → `Api\Sync\*Controller`
- Triggers: HTTP requests from offline servers (enrollment, heartbeat, snapshot, operations)
- Responsibilities: Token auth, protocol negotiation, stateless JSON responses

**Local Sync UI:**
- Location: `routes/web.php` → `LocalSyncController@run` (admin only)
- Triggers: Admin clicks "Run Sync" in `/sync/setup`
- Responsibilities: Orchestrates full sync cycle via `SyncRunner`

**Console Commands:**
- Location: `app/Console/Commands/` (via `routes/console.php`)
- Triggers: `php artisan` scheduled or manual
- Responsibilities: Maintenance, queue workers, sync diagnostics

**Queue Workers:**
- Location: `php artisan queue:listen` (configured in `composer.json` dev script)
- Triggers: Queued jobs (not heavily used; sync is synchronous HTTP)

## Architectural Constraints

- **Threading:** Single-threaded PHP-FPM / Octane workers; no worker threads. Sync runs synchronously in HTTP request (may time out on large datasets — mitigated by pagination + checkpoints).
- **Global state:** `SyncIdentity::mode()` reads `config('sync.instance_mode')` — effectively a process-global setting. `SyncSetting` model provides DB-backed overrides for offline mode.
- **Circular imports:** None detected. Service classes inject dependencies via constructor; models use traits, not service injection.
- **Database:** SQLite for dev/testing (`database/database.sqlite`); MySQL/PostgreSQL for production. Migrations are the source of truth.
- **Frontend:** Vite + Tailwind CSS 4 (`vite.config.js`, `package.json`). Blade templates with minimal JS (Alpine.js via CDN in `welcome.blade.php`).

## Anti-Patterns

### Direct Model Mutation in Controllers (without Services)

**What happens:** `BeneficiaryController` directly calls `$beneficiary->fill($data)->save()` instead of delegating to a service.
**Why it's wrong:** Bypasses centralized business logic (duplicate detection, ID generation, sync change recording consistency). Makes testing harder and duplicates logic.
**Do this instead:** Move create/update logic to a `BeneficiaryService` (or use existing `DuplicateDetector`, `BeneficiaryIdGenerator` consistently). See `Beneficiary::createWithCode()` as a model-level factory pattern.

### Mixed Concerns in SyncOperationService

**What happens:** `SyncOperationService` handles validation, conflict detection, auto-merge, conflict creation, receipt writing, and event logging in one 840-line class.
**Why it's wrong:** Violates Single Responsibility Principle. Hard to test edge cases in isolation. Changes to conflict logic risk breaking receipt logic.
**Do this instead:** Extract `ConflictDetector`, `ConflictResolver`, `ReceiptWriter`, `OperationValidator` as separate classes injected into a thinner orchestrator.

### Configuration via Env + Config + DB Hybrid

**What happens:** Sync config split across `config/sync.php` (env-driven), `SyncSetting` model (DB), and `SyncIdentity` static methods.
**Why it's wrong:** Three sources of truth for same settings (e.g., `local_server_uuid`). Priority order unclear without reading `SyncIdentity::originUuid()`.
**Do this instead:** Consolidate to single source: DB-backed `SyncSetting` with cached config fallback. Remove `config/sync.php` env overrides for runtime settings.

## Error Handling

**Strategy:** Exceptions bubble to Laravel's global handler (`app/Exceptions/Handler.php`). API returns standardized JSON error envelopes. Web shows flash messages.

**Patterns:**
- **Sync API:** `AuthenticateSyncServer` returns `401/403/426` with `{error: {code, message}}` (`app/Http/Middleware/AuthenticateSyncServer.php:77`)
- **Operation Errors:** `SyncOperationService` catches `Throwable`, reports via `report()`, returns `rejected` result with `server_error` code (`app/Services/Sync/SyncOperationService.php:239`)
- **Validation:** Controller-level `FormRequest` classes (`app/Http/Requests/`) for web; manual validation in sync services
- **Idempotency:** `SyncChange` receipts prevent duplicate processing — safe to retry

## Cross-Cutting Concerns

**Logging:** Laravel `Log` facade; `SyncEvent` model for structured sync audit trail; `AuditLogger` service for user actions.

**Validation:** 
- Web: `FormRequest` classes (`StoreBeneficiaryRequest`, `UpdateBeneficiaryRequest`)
- Sync: Inline validation in `SyncOperationService::applyBeneficiary()` (required fields, scope checks)

**Authentication:**
- Web: Laravel session + `EnsureRole` middleware (`role:admin`, `role:admin,staff`)
- Sync: Custom `AuthenticateSyncServer` middleware → token header `X-TIMS-Server-Token` or `Authorization: Bearer`

**Authorization:**
- Role-based: `role:admin`, `role:admin,staff` middleware on routes
- Scope-based: `ScopeService::allowsLocationCode()` enforced in sync apply path

---

*Architecture analysis: 2026-10-23*
