---
last_mapped_commit: 4a2a41257534aaecfd2249163155617f6d9a4bc1
last_mapped_at: 2026-10-23
---
# Codebase Structure

**Analysis Date:** 2026-10-23

## Directory Layout

```
TIMS-BARMM/
├── app/                          # Application core (Laravel MVC + Domain + Services)
│   ├── Console/                  # Artisan commands
│   ├── Exceptions/               # Exception handler
│   ├── Http/                     # Presentation layer
│   │   ├── Controllers/          # Web + API controllers
│   │   │   ├── Api/              # Sync protocol API controllers
│   │   │   │   └── Sync/         # Sync sub-protocol controllers
│   │   │   ├── Auth/             # Login controller
│   │   │   └── *.php             # Web controllers (Beneficiary, Batch, Import, etc.)
│   │   ├── Middleware/           # HTTP middleware
│   │   ├── Requests/             # FormRequest validation classes
│   │   └── Resources/            # API resources (transformers)
│   ├── Models/                   # Domain layer — Eloquent models
│   │   └── Concerns/             # Model traits (RecordsSyncChanges)
│   ├── Policies/                 # Authorization policies
│   ├── Providers/                # Service providers (AppServiceProvider)
│   ├── Services/                 # Application layer — business logic
│   │   ├── Export/               # Export services
│   │   ├── Import/               # Import services
│   │   ├── Reports/              # Report generation services
│   │   └── Sync/                 # Sync protocol services (core)
│   └── Support/                  # Domain utilities / value objects
│       ├── BeneficiaryOptions.php
│       ├── NameNormalizer.php
│       └── SyncIdentity.php
├── bootstrap/                    # Framework bootstrap
│   └── app.php                   # Application factory
├── config/                       # Configuration files
│   ├── sync.php                  # Sync protocol configuration (critical)
│   ├── app.php, auth.php, database.php, etc.
├── database/                     # Data layer
│   ├── factories/                # Model factories for testing
│   ├── migrations/               # Schema migrations (31 files)
│   └── seeders/                  # Database seeders
│       └── data/                 # Static seed data (barmm-locations.json)
├── public/                       # Web server document root
│   ├── index.php                 # Front controller
│   └── .htaccess                 # Apache rewrite rules
├── resources/                    # Frontend assets & views
│   ├── css/                      # Stylesheets (Tailwind entry)
│   ├── js/                       # JavaScript entry (minimal)
│   └── views/                    # Blade templates
│       ├── audit/, auth/, batches/, beneficiaries/, id-cards/
│       ├── imports/, layouts/, partials/, reports/, sync/, sync-conflicts/
│       ├── sync-servers/, users/, dashboard.blade.php, welcome.blade.php
├── routes/                       # Route definitions
│   ├── api.php                   # Sync protocol API routes
│   ├── console.php               # Artisan command routes
│   └── web.php                   # Web UI routes (primary)
├── storage/                      # Runtime storage (logs, cache, framework)
├── tests/                        # Test suite (PHPUnit)
│   ├── Feature/                  # Integration/feature tests (19 files)
│   │   ├── Auth/, Sync*.php, Beneficiary*.php, Import*.php, etc.
│   └── Unit/                     # Unit tests (minimal)
├── vendor/                       # Composer dependencies (gitignored)
├── .env                          # Environment config (gitignored)
├── .env.example                  # Environment template
├── artisan                       # Artisan CLI entry point
├── composer.json                 # PHP dependencies (Laravel 12, phpoffice/phpspreadsheet)
├── composer.lock
├── package.json                  # Node dependencies (Vite, Tailwind 4)
├── vite.config.js                # Vite build config
├── phpunit.xml                   # PHPUnit config
├── DESIGN.md                     # Design documentation
├── OFFLINE AND ONLINE IMPLEMENTATION.md
└── README.md
```

## Directory Purposes

**app/Http/Controllers/:**
- Purpose: Request handling for web UI and sync API
- Contains: Web controllers (BeneficiaryController, ImportController, SyncServerController, etc.), API sync controllers (ChangeController, ConflictController, EnrollmentController, etc.)
- Key files: `BeneficiaryController.php`, `SyncOperationService.php` (used by API controllers)

**app/Http/Controllers/Api/Sync/:**
- Purpose: Sync protocol endpoints (enrollment, heartbeat, snapshot, operations, conflicts)
- Contains: 6 controllers mapping to `/sync/v1/*` routes
- Key files: `OperationController.php` (receives operations), `SnapshotController.php` (serves snapshots)

**app/Models/:**
- Purpose: Domain entities with Eloquent ORM
- Contains: 18 model files + `Concerns/` trait
- Key files: `Beneficiary.php` (core entity, 387 lines), `OfflineServer.php`, `SyncChange.php`, `SyncConflict.php`, `SyncOperation.php`, `BeneficiaryBatch.php`

**app/Models/Concerns/:**
- Purpose: Reusable model traits
- Contains: `RecordsSyncChanges.php` — auto-creates SyncChange records on model mutations

**app/Services/Sync/:**
- Purpose: Core sync protocol implementation (largest service directory)
- Contains: 11 service files
- Key files: 
  - `SyncOperationService.php` (840 lines — central orchestrator)
  - `SyncRunner.php` (orchestrates full sync cycle)
  - `SnapshotDownloadService.php` (paginated data download)
  - `LocalChangeApplier.php` (applies remote changes locally)
  - `ScopeService.php` (location scoping enforcement)
  - `SyncClient.php` (HTTP client for offline→central)

**app/Services/Import/, Export/, Reports/:**
- Purpose: Operational workflow services
- Contains: Import processing, Excel export, report generation

**app/Support/:**
- Purpose: Domain utilities, value objects, static helpers
- Contains: `SyncIdentity` (mode/UUID resolution), `NameNormalizer` (name matching keys), `BeneficiaryOptions` (enum-like constants)

**routes/web.php:**
- Purpose: All web UI routes (auth, dashboard, beneficiaries, batches, imports, sync UI, admin)
- Key sections: Auth (login/logout), Beneficiaries CRUD, Batches, Imports, ID Cards, Reports, Sync (local + server admin), Users, Audit

**routes/api.php:**
- Purpose: Sync protocol API (offline server ↔ central)
- Structure: `Route::prefix('sync/v1')` with middleware `sync.server` (AuthenticateSyncServer)

**resources/views/:**
- Purpose: Blade templates organized by feature
- Key directories: `beneficiaries/` (list, show, edit, card), `batches/`, `imports/` (mapping, report), `sync/` (local setup), `sync-conflicts/` (resolution UI), `sync-servers/` (admin)

**database/migrations/:**
- Purpose: Schema evolution (31 migrations)
- Key migrations: Core tables (users, beneficiaries), sync tables (offline_servers, sync_changes, sync_conflicts, sync_snapshots, sync_checkpoints, sync_events), scoping tables (offline_server_scopes)

**database/seeders/:**
- Purpose: Reference data & demo data
- Key files: `BarmmLocationSeeder.php` (loads 1M+ location records from JSON), `DemoBeneficiariesSeeder.php`, `AdminUserSeeder.php`

**tests/Feature/:**
- Purpose: Integration tests covering full workflows
- Key files: `SyncOperationTest.php`, `SyncConflictResolutionTest.php`, `LocalSyncTest.php`, `BeneficiaryCrudTest.php`, `ImportWorkflowTest.php`

## Key File Locations

**Entry Points:**
- `public/index.php` → HTTP entry (web + API)
- `artisan` → CLI entry
- `routes/web.php` → Web route definitions
- `routes/api.php` → API route definitions
- `bootstrap/app.php` → Application factory

**Configuration:**
- `config/sync.php` → Sync protocol config (instance_mode, UUIDs, protocol versions, limits)
- `config/database.php` → DB connections
- `config/auth.php` → Guards/providers
- `.env` / `.env.example` → Environment variables

**Core Logic:**
- `app/Models/Beneficiary.php` → Core domain entity with sync metadata
- `app/Services/Sync/SyncOperationService.php` → Sync protocol brain
- `app/Services/Sync/SyncRunner.php` → Local sync cycle orchestrator
- `app/Support/SyncIdentity.php` → Instance mode & UUID resolution
- `app/Models/Concerns/RecordsSyncChanges.php` → Change capture trait

**Testing:**
- `tests/Feature/SyncOperationTest.php` → Sync operation apply/conflict tests
- `tests/Feature/LocalSyncTest.php` → Full local sync cycle tests
- `tests/TestCase.php` → Base test class (RefreshDatabase, etc.)

## Naming Conventions

**Files:**
- Controllers: `*Controller.php` (PascalCase)
- Models: Singular PascalCase (`Beneficiary.php`, `OfflineServer.php`)
- Services: `*Service.php` (PascalCase)
- Traits/Concerns: PascalCase + `Trait` suffix or descriptive (`RecordsSyncChanges.php`)
- Migrations: `YYYY_MM_DD_HHMMSS_description.php` (snake_case description)
- Seeders: `*Seeder.php` (PascalCase)
- Views: `kebab-case.blade.php` in feature directories
- Requests: `*Request.php` (FormRequest classes)

**Directories:**
- Controllers grouped by context: `Http/Controllers/`, `Http/Controllers/Api/Sync/`
- Services grouped by domain: `Services/Sync/`, `Services/Import/`
- Views grouped by feature: `views/beneficiaries/`, `views/sync-conflicts/`

**Code (PHP):**
- Classes: PascalCase
- Methods: camelCase
- Properties: camelCase
- Constants: UPPER_SNAKE_CASE
- Database columns: snake_case (Eloquent default)
- Route names: kebab-case with dot notation (`beneficiaries.index`, `api.sync.v1.operations.store`)

## Where to Add New Code

**New Web Feature (e.g., new entity CRUD):**
- Model: `app/Models/NewEntity.php`
- Migration: `database/migrations/YYYY_MM_DD_create_new_entities_table.php`
- Controller: `app/Http/Controllers/NewEntityController.php`
- Routes: Add to `routes/web.php`
- Views: `resources/views/new-entity/` (index, create, edit, show)
- Requests: `app/Http/Requests/StoreNewEntityRequest.php`, `UpdateNewEntityRequest.php`
- Tests: `tests/Feature/NewEntityCrudTest.php`

**New Sync Entity (participates in offline sync):**
- Model: `app/Models/NewEntity.php` — add `global_uuid`, `revision`, `server_revision`, `sync_status`, `conflict_status`, `origin_server_uuid`, `last_operation_uuid`; use `RecordsSyncChanges` trait
- Migration: Add sync columns + indexes
- SyncOperationService: Add case in `apply()` match statement (`app/Services/Sync/SyncOperationService.php:271`)
- ScopeService: Add location scoping if needed
- API: Add to `routes/api.php` if new endpoints needed

**New Sync Service:**
- Implementation: `app/Services/Sync/NewService.php`
- Register in `AppServiceProvider` if needs binding, or inject directly

**New Import/Export Format:**
- Service: `app/Services/Import/NewFormatImporter.php` or `app/Services/Export/NewFormatExporter.php`
- Controller: Add actions to `ImportController` or new controller

**Utility / Value Object:**
- File: `app/Support/NewUtility.php`
- Namespace: `App\Support`

## Special Directories

**storage/:**
- Purpose: Runtime files (logs, framework cache, sessions, uploads)
- Generated: Yes (by framework)
- Committed: No (gitignored)

**vendor/:**
- Purpose: Composer dependencies
- Generated: Yes (`composer install`)
- Committed: No (gitignored)

**node_modules/:**
- Purpose: NPM dependencies (Vite, Tailwind)
- Generated: Yes (`npm install`)
- Committed: No (gitignored)

**bootstrap/cache/:**
- Purpose: Framework optimization cache (compiled config, routes, services)
- Generated: Yes
- Committed: No (gitignored except `.gitkeep`)

**public/build/:**
- Purpose: Vite-compiled assets (CSS/JS)
- Generated: Yes (`npm run build`)
- Committed: No (gitignored)

**graphify-out/:**
- Purpose: Knowledge graph output (from graphify skill)
- Generated: Yes
- Committed: Likely no (check `.gitignore`)

**.planning/:**
- Purpose: GSD planning artifacts (phase docs, codebase maps, etc.)
- Generated: Yes (by GSD tools)
- Committed: Yes (part of project planning)

---

*Structure analysis: 2026-10-23*
