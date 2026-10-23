---
last_mapped_commit: 4a2a41257534aaecfd2249163155617f6d9a4bc1
last_mapped_at: 2026-10-23
---
<!-- refreshed: 2026-10-23 -->

# Coding Conventions

**Analysis Date:** 2026-10-23

## Languages

**Primary:**
- PHP 8.2+ — All backend code (`app/`, `database/`, `config/`, `tests/`)

**Secondary:**
- Blade — Server-side templates (`resources/views/`)
- JavaScript/TypeScript — Frontend assets (`resources/js/`, `vite.config.js`)

## Runtime

**Environment:**
- PHP 8.2+
- Laravel Framework ^12.0

**Package Manager:**
- Composer 2.x — Lockfile: `composer.lock` present
- NPM — `package.json` present for frontend assets

## Frameworks

**Core:**
- Laravel ^12.0 — MVC framework, routing, Eloquent ORM, service container, queues, auth

**Testing:**
- PHPUnit ^11.5 — Unit & feature testing
- Mockery ^1.6 — Mocking in tests
- Laravel Testbench — `Illuminate\Foundation\Testing\TestCase`, `RefreshDatabase` trait
- Pest plugin available (`pestphp/pest-plugin` in composer config) but not used — project uses raw PHPUnit

**Build/Dev:**
- Vite — Frontend bundling (`vite.config.js`)
- Laravel Pint ^1.24 — Code style enforcement (config not found, uses defaults)
- Laravel Sail ^1.41 — Docker dev environment

## Key Dependencies

**Critical:**
- `laravel/framework` ^12.0 — Application foundation
- `phpoffice/phpspreadsheet` ^5.10 — Excel import/export
- `laravel/tinker` ^2.10 — REPL

**Infrastructure:**
- `fakerphp/faker` ^1.23 — Test data generation
- `nunomaduro/collision` ^8.6 — Pretty error output
- `mockery/mockery` ^1.6 — Test mocking

## Configuration

**Environment:**
- `.env` file (not committed) — All secrets and environment-specific config
- `.env.example` — Template for required variables
- Config files in `config/` — Laravel convention: `config/app.php`, `config/database.php`, `config/auth.php`, `config/sync.php`, etc.
- Environment-specific values accessed via `env()` helper in config files, then `config('key')` in code

**Build:**
- `composer.json` — PHP dependencies & scripts (`composer test` runs `php artisan test`)
- `package.json` — Frontend dependencies
- `vite.config.js` — Vite configuration
- `phpunit.xml` — PHPUnit configuration (SQLite in-memory for tests)

## Platform Requirements

**Development:**
- PHP 8.2+
- Composer 2.x
- Node.js 18+ & NPM
- SQLite (for testing) or MySQL/PostgreSQL
- Optional: Docker (Laravel Sail)

**Production:**
- PHP 8.2+ with extensions: bcmath, ctype, fileinfo, json, mbstring, openssl, pdo, tokenizer, xml
- Web server: Nginx/Apache with PHP-FPM
- Database: MySQL 8+ or PostgreSQL 14+
- Redis (recommended for queues/cache/sessions)
- Supervisor for queue workers

---

## Naming Patterns

**Files:**
- Controllers: `PascalCase` + `Controller` suffix — `BeneficiaryController.php`, `SyncServerController.php`
- Models: `PascalCase` singular — `Beneficiary.php`, `OfflineServer.php`, `BeneficiaryBatch.php`
- Services: `PascalCase` + `Service` suffix — `DuplicateDetector.php`, `SyncRunner.php`, `AuditLogger.php`
- Requests: `PascalCase` + `Request` suffix — `StoreBeneficiaryRequest.php`, `DuplicateCheckRequest.php`
- Policies: `PascalCase` + `Policy` suffix — `BeneficiaryPolicy.php`, `ImportBatchPolicy.php`
- Exceptions: `PascalCase` + `Exception` suffix — `InvalidEnrollmentTokenException.php`, `SnapshotExpiredException.php`
- Factories: `PascalCase` + `Factory` suffix — `BeneficiaryFactory.php`, `UserFactory.php`
- Seeders: `PascalCase` + `Seeder` suffix — `DatabaseSeeder.php`, `TrainingsSeeder.php`
- Migrations: `snake_case` timestamped — `2026_10_21_000010_add_registration_fields_to_beneficiaries_table.php`
- Views: `snake_case` — `index.blade.php`, `create.blade.php`, `partials/duplicate-badge.blade.php`
- Support classes: `PascalCase` — `NameNormalizer.php`, `BeneficiaryOptions.php`, `SyncIdentity.php`
- Traits/Concerns: `PascalCase` + descriptive name — `RecordsSyncChanges.php`, `PreparesBeneficiaryInput.php`

**Classes/Interfaces:**
- Classes: `PascalCase` — `Beneficiary`, `DuplicateDetector`, `SyncRunner`
- Interfaces: `PascalCase` with `Interface` suffix (rare — Laravel uses contracts) — none observed
- Traits: `PascalCase` — `HasFactory`, `RecordsSyncChanges`
- Enums: `PascalCase` — not used (constants in classes instead)

**Methods:**
- Public methods: `camelCase` — `createWithCode()`, `findForRegistration()`, `beneficiaryPayload()`
- Protected/private methods: `camelCase` — `normalizeUpper()`, `resolveAddress()`, `retryLater()`
- Static factory methods: `camelCase` — `named()`, `withBirthdate()`, `admin()`, `underage()`
- Eloquent scopes: `scope` + `PascalCase` — `scopeActive()`, `scopeSearch()`, `scopeInBatch()`
- Accessors: `get` + `PascalCase` + `Attribute` — `getFullNameAttribute()`, `getDisplayAddressAttribute()`

**Variables:**
- Local variables: `camelCase` — `$beneficiary`, `$payload`, `$locationCode`
- Properties: `camelCase` — `$fillable`, `$casts`, `$duplicateStatus`
- Constants: `SCREAMING_SNAKE_CASE` — `ROLE_ADMIN`, `STATUS_SYNCED`, `OCCUPATION_OTHER`
- Collection variables: plural `camelCase` — `$beneficiaries`, `$operations`, `$matches`

**Database:**
- Tables: `snake_case` plural — `beneficiaries`, `beneficiary_batches`, `sync_operations`, `audit_logs`
- Columns: `snake_case` — `beneficiary_code`, `global_uuid`, `source_id_number`, `created_at`
- Foreign keys: `snake_case` singular + `_id` — `beneficiary_batch_id`, `import_batch_id`, `created_by`
- Pivot tables: `snake_case` singular joined — not used (Eloquent relationships)

**Routes:**
- Named routes: `kebab-case` plural/controller.action — `beneficiaries.index`, `beneficiaries.store`, `sync.enroll`, `imports.apply`
- URL paths: `kebab-case` — `/beneficiaries`, `/batches/{batch}/beneficiaries/create`, `/api/sync/v1/enroll`

**Config keys:**
- `snake_case` dot notation — `sync.instance_mode`, `sync.upload_batch_size`, `app.timezone`

## Code Style

**Formatting:**
- Laravel Pint (default Laravel preset) — Run via `./vendor/bin/pint`
- PSR-12 base with Laravel opinions:
  - 4-space indentation (no tabs)
  - Braces on same line for classes/methods/control structures
  - Single quotes for strings (unless interpolation needed)
  - Trailing commas in multiline arrays
  - Type declarations on all method signatures (PHP 8.1+ `readonly` properties)

**Linting:**
- PHPStan not configured (no `phpstan.neon`)
- Psalm not configured
- Laravel Pint handles style only

**PHP Version Features Used:**
- `readonly` properties — `class SyncRunner { public function __construct(private readonly SyncClient $client, ...) }`
- Constructor property promotion — same example
- `match` expressions — `match ($filters['age_status']) { ... }`
- `fn () =>` arrow functions — `$base = fn ($query) => $ignoreId ? $query->where('id', '!=', $ignoreId) : $query;`
- `::class` constant — `Beneficiary::class`, `User::ROLE_ADMIN`
- Nullsafe operator — `$request->user()?->is_active`
- Null coalescing assignment — `$beneficiary->global_uuid ??= (string) Str::uuid();`
- Enums not used — constants in classes (`User::ROLE_ADMIN`, `BeneficiaryBatch::STATUS_OPEN`)

## Import Organization

**Order (observed in `app/Models/Beneficiary.php`):**
1. `namespace` declaration
2. `use` statements — grouped by:
   - Same namespace (concerns, support) — `App\Models\Concerns\RecordsSyncChanges`
   - App services — `App\Services\AgeService`, `App\Services\DuplicateDetector`
   - Support classes — `App\Support\BeneficiaryOptions`, `App\Support\NameNormalizer`
   - Laravel/Illuminate — `Illuminate\Database\Eloquent\Builder`, `Illuminate\Support\Facades\DB`, `Illuminate\Support\Str`
   - PHP built-ins / SPL — `Illuminate\Database\QueryException` (last)
3. Class declaration

**Path Aliases:**
- `App\` → `app/` (PSR-4 in `composer.json`)
- `Database\Factories\` → `database/factories/`
- `Database\Seeders\` → `database/seeders/`
- `Tests\` → `tests/`
- No custom aliases beyond Laravel defaults

## Error Handling

**Patterns:**

1. **Exceptions for exceptional cases** — Custom exceptions in `app/Exceptions/Sync/`:
   - `InvalidEnrollmentTokenException` extends `RuntimeException`
   - `UnsupportedSyncVersionException`
   - `SnapshotExpiredException`
   - `ServerAlreadyEnrolledException`
   - Thrown from services, caught in controllers/commands

2. **Validation via FormRequest** — `StoreBeneficiaryRequest`, `UpdateBeneficiaryRequest`, `DuplicateCheckRequest`:
   - `rules()` returns array of validation rules
   - `messages()` for custom error messages
   - `withValidator()` for cross-field/conditional validation
   - `prepareForValidation()` for input normalization (uppercase, trim)
   - `beneficiaryPayload()` returns validated+transformed data for storage

3. **Transactional integrity** — `DB::transaction()` wrapper in controllers:
   ```php
   [$beneficiary, $candidates] = DB::transaction(function () use ($payload): array {
       $beneficiary = Beneficiary::createWithCode($payload);
       $candidates = $this->duplicates->findFor($beneficiary, $beneficiary->id);
       // ...
       return [$beneficiary, $candidates];
   });
   ```

4. **Retry with backoff** — `SyncRunner::retryLater()`:
   ```php
   $seconds = min(3600, 60 * (2 ** min(6, $operation->attempts)));
   $operation->markFailed($message, $seconds);
   ```

5. **Graceful degradation** — Sync errors stored in settings, not thrown:
   ```php
   SyncSetting::set('last_sync_error', $message);
   ```

6. **Audit logging for sensitive ops** — `AuditLogger::log()` called after mutations:
   ```php
   AuditLogger::log('beneficiary.created', 'beneficiary', $beneficiary->id, [
       'beneficiary_code' => $beneficiary->beneficiary_code,
       'duplicate_rule' => $flags['rule'] ?? null,
   ]);
   ```

## Logging

**Framework:** Laravel's built-in logging (`Illuminate\Support\Facades\Log`), configured in `config/logging.php`

**Patterns:**
- **Audit log** — Dedicated `audit_logs` table via `AuditLogger` service (not Monolog). Structured metadata, no PII bulk.
- **Sync errors** — Stored in `sync_settings` table (`last_sync_error` key) for UI display.
- **Exception logging** — Laravel default (to `storage/logs/laravel.log`) via `config/logging.php` stack.
- **No debug logging in production code** — Only audit trail for user actions.

**When to log:**
- Every create/update/archive/unarchive/resolveDuplicate on beneficiaries
- Import apply/cancel
- Sync enrollment, heartbeat, conflict resolution
- Auth login/logout/failed attempts
- Never log passwords, tokens, or bulk personal data

## Comments

**When to Comment:**
- **Class docblocks** — Purpose + key behavior (see `DuplicateDetector`, `SyncRunner`, `BeneficiaryOptions`)
- **Method docblocks** — `@return` with generics, `@param` for complex types, behavior notes
- **Complex algorithms** — Duplicate detection layering, name normalization, sync conflict logic
- **Business rule rationale** — "Never merges — only reports candidates for operator decision"
- **Legacy handling** — "Preserved on existing records... never silently remapped"

**Style:**
- PHPDoc blocks (`/** ... */`) for classes and public/protected methods
- Inline comments (`//`) for tricky lines within methods
- Type hints in docblocks where PHP native types insufficient: `@return array{rule: string, matches: Collection<int, Beneficiary>}|null`

**JSDoc/TSDoc:** Not applicable (PHP backend). Blade views have minimal comments.

## Function Design

**Size:**
- Small, single-purpose methods (15-30 lines typical)
- Controllers: thin, delegate to services/models
- Services: 50-200 lines, focused on one domain

**Parameters:**
- Typed: `private readonly DuplicateDetector $duplicates` (constructor promotion)
- Value objects rare — arrays with docblock `@return array<string, mixed>`
- Optional params with defaults: `int $attempts = 5`

**Return Values:**
- Explicit return types: `public function find(...): ?array`
- Nullable returns for "not found": `?array`, `?Beneficiary`, `?string`
- Collections typed in docblock: `Collection<int, Beneficiary>`
- Void for mutating methods: `public function archive(): void`

## Module Design

**Exports:**
- Services: instantiated via container (constructor injection)
- Models: Eloquent, used directly
- Support classes: static methods (`NameNormalizer::segment()`, `BeneficiaryOptions::occupations()`)
- Traits: `use HasFactory;`, `use RecordsSyncChanges;`

**Barrel Files:** Not used. Direct imports from namespace.

**Directory Structure Mapping:**

```
app/
├── Console/Commands/          # Artisan commands (TimsSync, TimsBackup)
├── Exceptions/Sync/           # Domain-specific exceptions
├── Http/
│   ├── Controllers/           # HTTP controllers (API + web)
│   ├── Controllers/Api/Sync/  # Sync API endpoints
│   ├── Middleware/            # Auth, role, sync auth
│   ├── Requests/              # FormRequests (validation)
│   │   └── Concerns/          # Shared validation traits
│   └── Resources/             # API resources
├── Models/                    # Eloquent models
│   └── Concerns/              # Model traits (RecordsSyncChanges)
├── Policies/                  # Authorization policies
├── Providers/                 # Service providers
├── Services/                  # Business logic services
│   ├── Export/                # Export strategies
│   ├── Import/                # Import pipeline
│   └── Sync/                  # Sync protocol implementation
└── Support/                   # Pure helpers (normalizers, options, identity)
```

---

*Convention analysis: 2026-10-23*
