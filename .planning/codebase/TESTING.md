---
last_mapped_commit: 4a2a41257534aaecfd2249163155617f6d9a4bc1
last_mapped_at: 2026-10-23
---
# Testing Patterns

**Analysis Date:** 2026-10-23

## Test Framework

**Runner:**
- PHPUnit ^11.5.50 — Config: `phpunit.xml`
- Laravel Testbench — `Illuminate\Foundation\Testing\TestCase` base class
- `php artisan test` runs tests (defined in `composer.json` scripts)

**Assertion Library:**
- PHPUnit native assertions (`assertSame`, `assertTrue`, `assertDatabaseHas`, etc.)
- Laravel testing helpers: `assertOk()`, `assertRedirect()`, `assertJson()`, `assertJsonPath()`, `assertSessionHasErrors()`, `assertAuthenticatedAs()`, `assertGuest()`

**Run Commands:**

```bash
composer test              # Runs: php artisan config:clear --ansi && php artisan test
php artisan test           # Run all tests
php artisan test --filter=BeneficiaryRegistrationTest  # Filter by class/method
php artisan test --testsuite=Unit      # Unit tests only
php artisan test --testsuite=Feature   # Feature tests only
./vendor/bin/phpunit --coverage-html coverage  # Coverage report (requires xdebug/pcov)
```

## Test File Organization

**Location:**
- `tests/Unit/` — Isolated unit tests (no Laravel app boot, extend `PHPUnit\Framework\TestCase`)
- `tests/Feature/` — Integration/feature tests (boot Laravel, extend `Tests\TestCase`)

**Naming:**
- Test classes: `PascalCase` + `Test` suffix — `BeneficiaryRegistrationTest.php`, `SyncEnrollmentTest.php`, `AuthenticationTest.php`
- Test methods: `snake_case` with `test_` prefix — `test_create_form_contains_pre_registration_duplicate_modal()`, `test_a_beneficiary_can_only_be_registered_inside_a_batch()`

**Structure:**

```
tests/
├── TestCase.php                    # Base class with shared helpers (seededLocation, seededBatch, beneficiaryPayload)
├── Unit/
│   └── ExampleTest.php             # Placeholder
└── Feature/
    ├── ExampleTest.php             # Placeholder
    ├── Auth/
    │   └── AuthenticationTest.php  # Auth feature tests
    ├── BeneficiaryRegistrationTest.php
    ├── BeneficiaryCrudTest.php
    ├── BeneficiaryBatchTest.php
    ├── BeneficiaryIdTest.php
    ├── DuplicateDetectionTest.php
    ├── AgeStatusTest.php
    ├── DashboardStatsTest.php
    ├── ImportWorkflowTest.php
    ├── ReportExportTest.php
    ├── IdCardPrintTest.php
    ├── LocalSyncTest.php
    ├── SyncSnapshotTest.php
    ├── SyncServerAdminTest.php
    ├── SyncRunnerTest.php
    ├── SyncOperationTest.php
    ├── SyncFoundationTest.php
    ├── SyncEnrollmentTest.php
    ├── SyncConflictResolutionTest.php
    └── SidebarNavigationTest.php
```

## Test Structure

**Suite Organization:**

```php
<?php

namespace Tests\Feature;

use App\Models\Beneficiary;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BeneficiaryRegistrationTest extends TestCase
{
    use RefreshDatabase;  // Resets DB between tests (SQLite in-memory)

    private function staff(): User
    {
        return User::factory()->create(['role' => User::ROLE_STAFF]);
    }

    public function test_create_form_contains_pre_registration_duplicate_modal(): void
    {
        $batch = $this->seededBatch(['name' => 'Registration Batch']);

        $this->actingAs($this->staff())->get('/batches/'.$batch->id.'/beneficiaries/create')
            ->assertOk()
            ->assertSee('Verify beneficiary')
            ->assertSee('id="duplicateCheckVerify"', false)
            ->assertSee(route('beneficiaries.duplicate-check'), false)
            ->assertSee('id="beneficiaryForm"', false)
            ->assertSee('Registration Batch')
            ->assertDontSee('Continue registration');
    }
    // ...
}
```

**Patterns:**

- **`RefreshDatabase` trait** — Used on all Feature tests; resets SQLite in-memory DB before each test
- **`actingAs($user)`** — Authenticate for requests
- **`from($url)`** — Set referrer for redirect-back scenarios
- **`postJson()` / `getJson()`** — JSON API testing
- **Factory helpers** — `User::factory()->create([...])`, `Beneficiary::factory()->named(...)->withBirthdate(...)->create()`
- **Base TestCase helpers** (defined in `tests/TestCase.php`):
  - `seededLocation(array $attributes = []): Location` — Creates deterministic BARMM location
  - `seededBatch(array $attributes = []): BeneficiaryBatch` — Creates test batch
  - `beneficiaryPayload(Location $location, array $attributes = []): array` — Complete valid payload

**Setup/Teardown:**
- `setUp()` in `SyncRunnerTest` — Configures sync settings for offline mode
- `parent::setUp()` called first
- No `tearDown()` needed (RefreshDatabase handles cleanup)

## Mocking

**Framework:** Mockery ^1.6 (included in `require-dev`)

**Patterns:**

1. **HTTP Client Fake** — `Illuminate\Support\Facades\Http::fake()` for external API calls:
   ```php
   Http::fake([
       'https://central.test/api/sync/v1/operations' => Http::response([
           'data' => [[...]],
           'meta' => ['count' => 1, 'applied' => 1],
       ]),
       'https://central.test/api/sync/v1/changes*' => Http::response([
           'data' => [],
           'meta' => ['next_cursor' => null, 'has_more' => false, 'count' => 0],
       ]),
       'https://central.test/api/sync/v1/ack' => Http::response(['data' => []]),
   ]);
   
   app(SyncRunner::class)->run();
   
   Http::assertSent(fn ($request): bool => $request->url() === 'https://central.test/api/sync/v1/operations'
       && $request['operations'][0]['operation_uuid'] === $operation->operation_uuid);
   ```

2. **Storage Fake** — `Illuminate\Support\Facades\Storage::fake('local')` for file uploads:
   ```php
   Storage::fake('local');
   $file = UploadedFile::fake()->createWithContent('profile.xlsx', $content);
   $this->actingAs($user)->post('/imports', ['workbook' => $file]);
   Storage::disk('local')->assertMissing($batch->stored_path);
   ```

3. **Service Mocking** — `app()->instance()` or `Mockery::mock()` for service replacement (rare — prefer real services with faked externals):
   ```php
   // In SyncRunnerTest, real SyncRunner with faked Http client
   // Not mocking SyncRunner itself
   ```

4. **Config Override** — `config([...])` in `setUp()`:
   ```php
   config([
       'sync.instance_mode' => 'offline',
       'sync.local_server_uuid' => '11111111-1111-4111-8111-111111111111',
   ]);
   ```

**What to Mock:**
- External HTTP APIs (central sync server)
- File storage (local disk)
- Time (`now()`) — not explicitly mocked; tests use fixed dates in payloads
- Queue jobs — `Queue::fake()` not observed; tests use `sync` driver

**What NOT to Mock:**
- Eloquent models — Use factories + real DB (SQLite)
- Services — Test real implementations with faked dependencies
- Controllers — Test via HTTP requests (`$this->get()`, `$this->post()`)
- Database — Use `RefreshDatabase` with SQLite

## Fixtures and Factories

**Test Data:**
- **Factories** in `database/factories/`:
  - `UserFactory` — `definition()`, `admin()`, `encoder()`, `inactive()`, `unverified()`
  - `BeneficiaryFactory` — `definition()`, `underage()`, `withBirthdate()`, `named()`
  - `BeneficiaryBatchFactory`, `OfflineServerFactory`
- **State methods** — Fluent builders for common variations
- **Base TestCase helpers** — `seededLocation()`, `seededBatch()`, `beneficiaryPayload()` for complex multi-model setups

**Factory Example:**

```php
class BeneficiaryFactory extends Factory
{
    public function definition(): array
    {
        return [
            'first_name' => fake()->firstName(),
            'middle_name' => fake()->optional()->firstName(),
            'last_name' => fake()->lastName(),
            'birthdate' => fake()->dateTimeBetween('-70 years', '-10 years')->format('Y-m-d'),
            'sex' => fake()->randomElement(['MALE', 'FEMALE']),
            'duplicate_status' => 'none',
        ];
    }

    public function underage(): static
    {
        return $this->state(fn () => ['birthdate' => now()->subYears(16)->toDateString()]);
    }

    public function named(string $first, ?string $middle, string $last): static
    {
        return $this->state(fn () => [
            'first_name' => $first,
            'middle_name' => $middle,
            'last_name' => $last,
        ]);
    }
}
```

**Usage in Tests:**

```php
Beneficiary::factory()->named('SAME', 'NAME', 'HERE')->withBirthdate('1990-01-01')->create();

$staff = User::factory()->create(['role' => User::ROLE_STAFF]);
$admin = User::factory()->admin()->create();
```

**Location:** `database/factories/` — Auto-loaded via `composer.json` autoload-dev

## Coverage

**Requirements:** None enforced (no minimum coverage in CI config)

**View Coverage:**

```bash
./vendor/bin/phpunit --coverage-html coverage

# Or with pcov (faster):

./vendor/bin/phpunit --coverage-html coverage --coverage-driver=pcov
```

**Coverage Targets (Implicit):**
- Critical sync logic: `SyncRunnerTest`, `SyncEnrollmentTest`, `SyncConflictResolutionTest`
- Import pipeline: `ImportWorkflowTest`
- Beneficiary registration: `BeneficiaryRegistrationTest`
- Auth: `AuthenticationTest`

## Test Types

**Unit Tests (`tests/Unit/`):**
- Minimal — only `ExampleTest.php` placeholder
- Would test: pure logic classes (`NameNormalizer`, `BeneficiaryIdGenerator`, `DuplicateDetector`) in isolation
- Currently: not used for domain logic (tested via Feature tests)

**Feature Tests (`tests/Feature/`):**
- **Primary test type** — Full HTTP request/response cycle
- Boot Laravel app, use real database (SQLite), real services
- Test controllers, middleware, validation, policies, API endpoints
- Examples:
  - `BeneficiaryRegistrationTest` — 18 test methods covering create, store, duplicate check, edit, address resolution
  - `SyncRunnerTest` — 7 test methods covering upload, download, conflict, retry, echo-skip
  - `ImportWorkflowTest` — 12 test methods covering preview, apply, duplicate handling, invalid rows, permissions
  - `AuthenticationTest` — 8 test methods covering login, logout, role middleware

**E2E Tests:** Not used (no Laravel Dusk, Cypress, Playwright)

## Common Patterns

**Async Testing:** Not applicable (PHP is synchronous). Queue jobs tested via `sync` driver.

**Error Testing:**

```php
// Validation errors
$this->actingAs($staff)->post('/beneficiaries/duplicate-check', ['first_name' => 'ONLY'])
    ->assertStatus(422)
    ->assertJsonValidationErrors(['last_name', 'birthdate']);

// Auth errors
$this->post(route('login.attempt'), ['username' => 'staff_user', 'password' => 'wrong-password'])
    ->assertRedirect(route('login'))
    ->assertSessionHasErrors('username');
$this->assertGuest();

// Forbidden
$this->actingAs($staff)->get('/test-admin-only')->assertForbidden();

// Not found
$this->actingAs($staff)->get('/beneficiaries/create')->assertNotFound();
```

**Database Assertions:**

```php
$this->assertDatabaseHas('beneficiaries', ['beneficiary_code' => 'REMOTE-0001']);
$this->assertDatabaseCount('offline_servers', 1);
$this->assertDatabaseMissing('audit_logs', ['action' => 'import.applied']);
$this->assertSame(1, Beneficiary::query()->count());
```

**JSON API Assertions:**

```php
$response->assertOk()
    ->assertJson(['duplicate' => true, 'rule' => 'name_birthdate'])
    ->assertJsonPath('matches.0.full_name', 'SAME NAME HERE')
    ->assertJsonCount(1, 'results');
```

**Time-Sensitive Tests:**
- Fixed dates in payloads (`'1990-01-01'`, `'1996-07-22'`)
- `now()->subYears(16)` for relative ages
- No time-travel library used

**Permission Testing:**

```php
// In test: create users with specific roles via factory states
$staff = User::factory()->create(['role' => User::ROLE_STAFF]);
$admin = User::factory()->admin()->create();

// In controller test
$this->actingAs($staff)->post('/imports/...')->assertForbidden();
$this->actingAs($admin)->post('/imports/...')->assertRedirect();

// Policy tests via Gate
Gate::authorize('create', Beneficiary::class);  // in controller
// Test verifies 403 when policy denies
```

**Complex Workflow Tests (ImportWorkflowTest):**

```php
// 1. Build real .xlsx with PhpSpreadsheet (matches production workbook structure)
$path = $this->makeWorkbook([$this->sourceRow(1), $this->sourceRow(2)]);

// 2. Upload + preview (dry-run)
$batch = $this->uploadAndPreview($path);
$this->assertSame('previewed', $batch->status);
$this->assertSame(0, Beneficiary::query()->count());

// 3. Apply with decisions
$this->actingAs($admin)->post('/imports/'.$batch->id.'/apply', [
    'decisions' => ['new' => 'insert', 'update' => 'update', 'duplicate' => 'skip', 'review' => 'skip'],
    'confirm' => 1,
])->assertRedirect(route('imports.show', $batch));

// 4. Verify results
$this->assertSame(2, Beneficiary::query()->count());
```

---

*Testing analysis: 2026-10-23*
