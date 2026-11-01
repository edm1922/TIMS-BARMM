# Architecture — TUPAD Beneficiary LOB System

> Phase 1 baseline. Adjust only after a documented mapping change. Inspection date: 2026-10-18.

## 1. Verified environment (this machine)

| Component | Version / status |
|---|---|
| XAMPP | `C:\xampp` |
| PHP | **8.2.12** (CLI: `C:\xampp\php\php.exe`, ZTS, VC++ 2019 x64) |
| PHP extensions present | `zip`, `mbstring`, `gd`, `pdo_mysql`, `mysqli`, `xml`/`libxml`/`SimpleXML`, `fileinfo`, `bcmath`, `curl`, `openssl`, `ctype`, `tokenizer` — all required extensions for Laravel + PhpSpreadsheet are enabled |
| Composer | **2.10.3** (`C:\xampp\composer.phar`) |
| MariaDB | **10.4.32** (`C:\xampp\mysql\bin\mysql.exe`) |
| Apache/MySQL services | not currently running (ports 80 and 3306 closed) — start via XAMPP Control Panel |
| Node.js | present (only used optionally for dev tooling; **not** a runtime dependency) |

`php` and `composer` are not on `PATH`; all commands in docs use full paths or invoke `C:\xampp\php\php.exe C:\xampp\composer.phar`.

## 2. Stack decisions

| Concern | Decision | Rationale |
|---|---|---|
| Framework | Laravel **12.x** (verify latest PHP 8.2-compatible release at scaffold time) | PHP 8.2 is within Laravel 12 requirements; newer Laravel 13 line likely requires a newer PHP, so it is avoided |
| DB | MariaDB 10.4 (`utf8mb4`, InnoDB) | matches XAMPP install |
| Views | Blade + **Bootstrap 5.3**, vendored in `public/assets` | no Node/Vite build step required; offline-safe |
| Auth | Laravel session auth, custom minimal login/logout controllers + Blade views | avoids Breeze/Tailwind (brief requires Bootstrap); no extra frontend runtime |
| Excel | `phpoffice/phpspreadsheet` (Composer) used directly | lighter than Laravel Excel; both suggested by brief; one library for import and export |
| PDF/cards | Print-ready Blade HTML + CSS (`mm` units) | brief requires browser print; no PDF server dependency |
| Tests | PHPUnit + Laravel feature tests, fabricated data only | brief forbids real personal data in tests |
| Queues | none (synchronous) | small local deployment; no worker needed |

Assistant/tooling note: `C:\xampp\php\php.exe C:\xampp\composer.phar create-project laravel/laravel .` will be used to scaffold into this directory once source files are moved/protected (the Laravel installer requires an empty-ish directory; source files will be preserved and the scaffold placed via a temp directory if needed).

## 3. Directory layout

```
TIMS-BARMM/
├─ app/
│  ├─ Http/Controllers/{Auth,Beneficiary,Import,IdCard,Report,Dashboard,User}Controller.php
│  ├─ Http/Middleware/EnsureRole.php
│  ├─ Http/Requests/...
│  ├─ Models/{User,Beneficiary,BeneficiaryBatch,ImportBatch,ImportRow,AuditLog,Training}.php
│  ├─ Policies/{Beneficiary,BeneficiaryBatch,ImportBatch,Export,AuditLog}Policy.php
│  ├─ Services/{BeneficiaryIdGenerator,AgeService,DuplicateDetector,Excel/Importer,Excel/Exporter,AuditLogger}.php
│  └─ Support/NameNormalizer.php
├─ database/migrations, database/seeders
├─ docs/                       (this folder)
├─ resources/views/
│  ├─ layouts/app.blade.php, auth/, dashboard/, beneficiaries/, batches/,
│  ├─ imports/, id-cards/ (card front/back partials), reports/,
│  ├─ partials/ (shared badges, flash messages, nav icons)
├─ public/assets/css|js|img   (vendored Bootstrap, app CSS, print CSS)
├─ routes/web.php
├─ storage/app/private/{imports,photos,signatures}
└─ tests/Feature, tests/Unit
```

## 4. Data model

### `users`
`id`, `name`, `email` (unique), `password` (hashed), `role` enum(`admin`,`staff`) default `staff`, `is_active` bool, timestamps. Seeded admin from env (`ADMIN_EMAIL`/`ADMIN_PASSWORD`, demo only).

### `beneficiaries`
| Column | Type | Notes |
|---|---|---|
| `id` | bigint PK | internal |
| `beneficiary_code` | varchar(48) unique | generated, immutable (see §5) |
| `source_id_number` | varchar(64) unique nullable | Excel `ID NUMBER`; unique to prevent silent overwrites |
| `source_row_no` | unsigned int nullable | Excel `No.` |
| `first_name`, `middle_name`, `last_name`, `extension_name` | varchar | trimmed; `N/A` → null |
| `birthdate` | date nullable | invalid/missing → null |
| `birthdate_raw` | varchar(32) nullable | original cell as text when not a valid date |
| `barangay`, `city_municipality`, `province`, `district` | varchar(100) | |
| `id_type`, `source_id_no` | varchar | source `Type of ID`, `ID No.` (not unique) |
| `contact_no`, `contact_no_raw` | varchar | normalized + raw |
| `epayment_account_no` | varchar(100) | |
| `beneficiary_type`, `occupation`, `civil_status` | varchar | trimmed source values |
| `sex` | varchar(10) | MALE/FEMALE |
| `age_source` | unsigned tinyint nullable | Excel `Age`; traceability only |
| `avg_monthly_income` | decimal(10,2) nullable | |
| `dependent_name` | varchar(150) | never printed on card |
| `employment_interest` | varchar(255) | |
| `skills_training_needed` | varchar(150) | nullable FK to `trainings.id` is **not** used; values are free text matched to reference list |
| `photo_path`, `signature_path` | varchar nullable | private disk paths |
| `duplicate_status` | enum(`none`,`candidate`,`confirmed_duplicate`,`cleared`) default `none` | review flag |
| `duplicate_note` | text nullable | operator rationale |
| `import_batch_id` | bigint nullable FK → `import_batches` | provenance of the Excel row; `null` = manual entry |
| `beneficiary_batch_id` | bigint nullable FK → `beneficiary_batches` | operational release/payout batch; `null` = unassigned |
| `archived_at`, `archive_reason` | timestamp/text nullable | soft deactivate without losing data |
| `notes` | text nullable | |
| timestamps | | |

Indexes: unique `beneficiary_code`, unique `source_id_number`, index `(last_name, first_name)`, index `barangay`, index `archived_at`, index `duplicate_status`.

### `beneficiary_batches`
`id`, `name` varchar(100) unique, `description` varchar nullable, `status` enum(`open`,`closed`) default `open`, `created_by` nullable FK → `users`, timestamps. Index `status`.

An operational release/payout batch — who is in which round of release,
distribution, or verification work. **Distinct from `import_batches`**, which
records where a row came from. Membership is exclusive: a beneficiary belongs to
at most one batch (`beneficiaries.beneficiary_batch_id`), and assigning a record
that already has a batch moves it. Batch actions are admin-only and audited
(`beneficiary_batch.created` / `.updated` / `.deleted` / `.assigned` /
`.unassigned`). Deleting a batch cascades: every beneficiary filed in it is
permanently deleted (audited individually as `beneficiary.deleted`) and never
left behind as an unassigned record.

### `trainings`
`id`, `category`, `name`, timestamps. Seeded from hidden `List of Trainings` sheet (15 categories / 123 items). Used only as a suggestion list.

### `import_batches`
`id`, `user_id` FK, `original_filename`, `worksheet`, `header_row`, `status` enum(`uploaded`,`previewed`,`applied`,`failed`,`cancelled`), `use_source_id_as_key` bool, `mapping` json, `rows_read/inserted/updated/skipped/flagged/failed` ints, `started_at`, `finished_at`, `stored_path`, timestamps.

### `import_rows`
`id`, `import_batch_id` FK, `excel_row` int, `outcome` enum(`new`,`update`,`unchanged`,`duplicate`,`invalid`,`review`,`skipped`), `matched_beneficiary_id` nullable FK, `candidate_ids` json, `messages` json, `operator_decision` enum(`insert`,`update`,`skip`,`review`) nullable, `applied` bool, timestamps. Unique `(import_batch_id, excel_row)`.

### `audit_logs`
`id`, `user_id`, actor identity (`actor_uuid`, `actor_name`, `actor_username`, `actor_role`) so an event that arrived from an offline installation stays attributable, `origin_server_uuid`, `occurred_at`, `synced_at`, `action` (e.g. `beneficiary.created`, `import.applied`, `id_card.printed`, `export.csv`, `duplicate.resolved`), `entity_type`, `entity_id`, `metadata` json (minimal, no secrets), `ip`, timestamps. Index `(entity_type, entity_id)`, `(action, created_at)`.

No ID-history table (single stable code per record per brief). No soft-deletes on beneficiaries — archive fields instead.

## 5. Beneficiary ID generation

Format: `FIRST-MIDDLE-LAST-NNNN` (middle omitted if blank), e.g. `JUAN-DELA-CRUZ-0001`.

- Normalize: trim, uppercase, transliterate accents (`Str::ascii`), replace any run of non-`[A-Z0-9]` with `-`, collapse repeated hyphens, trim hyphens.
- Extension names are excluded. `N/A` treated as absent.
- Name part capped at 60 chars (truncate at a hyphen boundary).
- Suffix: zero-padded 4-digit counter scoped to the name prefix; determined by querying `MAX` suffix for the prefix and checked against a **unique DB constraint**; on `QueryException` collision retry up to 5 times with the next suffix. Counter grows beyond 4 digits naturally at 10,000.
- Generated inside the creation transaction, once. **Never regenerated on edit**; there is no rename-triggered regeneration. An explicit admin-only "regenerate" is out of scope unless requested later.
- Source `ID NUMBER` never feeds or overwrites this code.

## 6. Age / underage

`AgeService`:
- valid birthdate → age via `Carbon::diffInYears` (date-only); future dates → `invalid`.
- `< 18` → `underage`; `>= 18` → `adult`; null/invalid/future → `unknown` (Needs Review), never underage.
- Displayed in list, detail, dashboard counts, and import preview. Never rejects or deletes a record.
- Tested at exactly day-before-18th, 18th birthday, and day-after.

## 7. Duplicate detection

`DuplicateDetector` (layered, per brief §5):
1. exact `beneficiary_code`;
2. exact `source_id_number` (when import uses it as key) and exact `source_id_no` (gov ID) → candidate only;
3. normalized `first|middle|last` + valid `birthdate` → candidate;
4. name-only (normalized) → weak candidate, never auto-merge.

Candidates are advisory. No automatic merge/overwrite anywhere. Decisions recorded on `import_rows` and `audit_logs`.

## 8. Authorization & security

- All routes behind `auth`; `verified` not used (local accounts).
- Roles: `admin` (users, imports apply, exports, archive, duplicate resolution, release-batch management) and `staff` (view/create/edit/import upload+preview/print; apply requires admin unless policy is relaxed).
- `BeneficiaryPolicy`, `ImportBatchPolicy`, `BeneficiaryBatchPolicy`, `ExportPolicy`; enforced server-side; Blade hides UI but never replaces authorization.
- CSRF on all forms; login rate-limited (5/min/IP); password hashing via bcrypt.
- Private disk (`storage/app/private`) for imports/photos/signatures; downloads via authorized controller routes, never public URLs.
- Upload validation: `.xlsx` extension + MIME sniffing + 10 MB cap + zip-bomb guard (row/sheet caps).
- CSV/XLSX export formula-injection guard: any cell starting with `= + - @` or tab/CR is prefixed with `'`.
- No real beneficiary data in Git/tests; `.env` ignored; `.env.example` placeholders only.
- Audit log on sensitive operations (create/edit/archive/import apply/export/print/duplicate decision).
- No external service transmission of beneficiary data.

## 9. Local runtime

- DB: `tims_barmm` (utf8mb4_unicode_ci), XAMPP default `root` with empty password locally; documented in `.env.example`/setup guide.
- Served either by Apache (`http://localhost/TIMS-BARMM/public`) or `php artisan serve`; both documented.
- Storage symlink not required (private files served via controller).
- Timezone `Asia/Manila`, locale `en`, date display `YYYY-MM-DD` (source-compatible) with formatted variants in UI.

## 10. Testing strategy

- Unit: `NameNormalizer`, `BeneficiaryIdGenerator` (accents, punctuation, missing middle, collisions, stability), `AgeService` boundaries.
- Feature: beneficiary CRUD + authorization matrix; duplicate review flow; import preview/classify/apply (repeated import → unchanged; duplicate names; missing/malformed dates; failed rows; dry-run changes nothing); export injection guard; ID-card render assertions (blank emergency block, birthdate/address present); audit entries.
- Import tests run against a **fabricated** workbook fixture generated in tests; a copy of the real workbook is used only for manual verification per brief Phase 4.
- Commands: `php artisan test` (full), `php artisan test --filter=...` (targeted).

## 11. Risks / constraints

1. MariaDB 10.4 is older but supported; avoid MySQL 8-only syntax.
2. Excel date cells are serial numbers; parse via PhpSpreadsheet date handling, not string casts.
3. The real workbook is open in Excel; import tests must use copies.
4. ID-card visual fidelity is limited by the rasterized PDF (no vector fonts/logos). Header band will be cropped from the supplied PDF as an image asset; see `docs/id-card-layout.md` for assumptions.
5. `source_id_number` uniqueness could reject a legitimately duplicated DOLE ID; importer will surface it as a conflict for operator decision rather than crash.
6. No background queue: a 1,000-row import is synchronous and bounded (single transaction).

## Registration overhaul (2026-10-21)

New and changed components backing the beneficiary-registration update:

- `App\Support\BeneficiaryOptions` — single source of truth for all controlled
  lists (occupations, ID types, beneficiary signify, beneficiary types, modes of
  payment, extensions). `isLegacy()` distinguishes standard vs. imported values.
- `App\Models\Location` + `locations` table — offline BARMM PSGC reference
  (`docs/barmm-locations.md`), seeded from `database/seeders/data/barmm-locations.json`.
- `App\Http\Requests\Concerns\PreparesBeneficiaryInput` — shared uppercasing,
  extension canonicalization, address resolution and OTHER-detail validation.
- `App\Services\DuplicateDetector::findForRegistration()` — pre-registration
  verification; a blank middle name is treated as a wildcard (name-only weak
  match). Verification is a **required, blocking gate**: the modal's single
  **Verify** button posts the check, and the create form posts
  `duplicate_check_done` (validated with `accepted`). A confirmed match leaves the
  flag unset so the store is rejected server-side; only a no-match verify unlocks
  and auto-proceeds.
- `beneficiaries.address` + `beneficiaries.location_code` (+ `<other>` fields for
  OTHER details). Legacy location columns and `epayment_account_no` are retained
  but hidden.
- Routes `POST /beneficiaries/duplicate-check` and `GET /locations/search`;
  assets under `public/assets/js/beneficiary-*.js`.

## Release batches (2026-10-22)

Components backing the per-batch grouping of beneficiaries:

- `App\Models\BeneficiaryBatch` + `beneficiary_batches` table — named,
  optional description, `open`/`closed` status, creator. Independent of the
  Excel import batches.
- `beneficiaries.beneficiary_batch_id` — nullable FK; exclusive membership
  (one batch per beneficiary). `Beneficiary::beneficiaryBatch()` is the
  relation; `scopeInBatch()` / `scopeUnassigned()` are the query helpers.
- `App\Http\Controllers\BeneficiaryBatchController` — index, show, create,
  update, delete, add members, remove members, and the candidate lookup.
  - **Create is idempotent**: the batch name is the identity. The name is
    matched case-insensitively inside a `DB::transaction`, and `createOrFirst()`
    swallows the unique-index violation raised by a concurrent double submit,
    so a repeated submission opens the existing batch instead of creating a
    second one.
  - **Add members** reuses existing beneficiary records. Ids that already point
    at this batch are filtered out first, so a record can never be added twice;
    the flash message reports how many were added, moved, and skipped.
  - **Remove members** only clears the batch column for records that are in this
    batch, and never deletes a beneficiary record.
  - Membership changes run inside a transaction; a batch can only be deleted
    while empty.
- `App\Policies\BeneficiaryBatchPolicy` — viewing is open to any active user;
  every mutation is admin-only, enforced both by the policy and by
  `->middleware('role:admin')` on the routes.
- Routes: `GET /batches` (`beneficiary-batches.index`), `GET /batches/{batch}`
  (`beneficiary-batches.show`), `GET /batches/{batch}/candidates`,
  `POST /batches`, `PUT|DELETE /batches/{batch}`,
  `POST|DELETE /batches/{batch}/members`.
- UI: the workflow lives on the batch pages, not on the beneficiary list.
  - `batches/index` lists every batch (status, member count, created date).
  - `batches/show` is the management view: member table, **Add Beneficiary**
    picker, settings (rename / close / delete).
  - `batches/partials/create-modal` — the **Create Batch** modal, reachable
    from both the beneficiary list and the batch index.
  - `batches/partials/member-modal` — the **Add Beneficiary** picker.
  - The beneficiary list keeps a read-only **Batch** column and **Batch**
    filter; it has no batch-assign toolbar.
  - Client script: `public/assets/js/beneficiary-batch.js` (no build step) —
    blocks a double submission on the create form and drives the picker.
- Audit actions: `beneficiary_batch.created`, `beneficiary_batch.updated`,
  `beneficiary_batch.deleted`, `beneficiary_batch.assigned`,
  `beneficiary_batch.unassigned`.

## Side navigation (2026-10-22)

The top navbar was replaced by a left sidebar.

- `resources/views/layouts/app.blade.php` renders
  `.app-shell` → `.navbar-expand-lg.app-sidebar-wrapper` →
  `aside.app-sidebar.offcanvas-lg.offcanvas-start` and a
  `.app-content` column. The `.navbar-expand-lg` wrapper is required:
  Bootstrap 5.3 only resets a responsive offcanvas to a static column inside
  that ancestor.
- Below the `lg` breakpoint the sidebar is a slide-in drawer opened by the
  top bar button (`data-bs-toggle="offcanvas"`); at `lg` and above it is a
  sticky 270 px column. No build step — the vendored Bootstrap 5.3.3 bundle
  already contains the Offcanvas component.
- The navy surface is painted on `.app-sidebar-inner`, not on the `<aside>`:
  from the `lg` breakpoint upwards Bootstrap forces
  `background-color: transparent !important` on `.offcanvas-lg`.
- `resources/views/partials/nav-icon.blade.php` holds the inline SVG icons;
  `resources/views/partials/flash-messages.blade.php` holds the flash/error
  block shared by the authenticated and guest layouts.
- The active entry is derived from `request()->routeIs(...)`; the `Route::has()`
  guards are kept so the sidebar stays valid if a section is removed.
- `public/assets/css/app.css` holds the sidebar styling and the print rules
  (the sidebar, the mobile top bar, buttons, forms, and alerts are hidden when
  printing).
- `resources/views/id-cards/print.blade.php` is a standalone page and does not
  use the layout, so the card print output is unchanged.

## Dashboard hierarchy (2026-10-22)

The dashboard was rebuilt around a single reading order instead of a grid of
equal stat cards.

- `app/Services/DashboardStats.php` owns every figure. It runs a fixed number
  of queries (one aggregate scan of the active beneficiaries, one underage
  count, one archived count, one batch count, one open-batch list, one recent
  import list), so the page never fans out into per-row lookups.
- The assignment split is read from one scan:
  `SUM(CASE WHEN beneficiary_batch_id IS NOT NULL ...)`. A beneficiary carries
  a single nullable batch foreign key, so `assigned + unassigned = total`
  holds by construction and no beneficiary can be counted twice.
- Definitions are unchanged from the previous dashboard: active records only,
  underage means a valid non-future birthdate inside the 18 year window
  (`AgeService` rule), `unknown_age` means a null birthdate, and
  `duplicate_candidates` counts `candidate` plus `confirmed_duplicate`.
- `resources/views/dashboard.blade.php` is laid out in levels: primary KPI
  (total beneficiaries) → assignment donut → batch overview → data quality →
  recent import batches + quick actions.
- The donut is a dependency-free inline SVG (circle with `stroke-dasharray`,
  rotated -90° so it starts at twelve o'clock). The circumference is computed
  in the view, the arc lengths from the counts; when there are no beneficiaries
  no segment is drawn and the legend shows `—` instead of a percentage.
- Links only point at filters that exist on the beneficiary list (`batch`,
  `age_status`, `status`); the duplicate metric is deliberately not linked
  because the list cannot filter candidates and confirmed duplicates together.
- `public/assets/css/app.css` holds the new dashboard styles; the old
  `.stat-card*` rules were removed with the view.

