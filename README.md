# TIMS-BARMM — TUPAD Beneficiary Line-of-Business System

Web application for managing TUPAD beneficiaries (PAHAMUDDIN, SGA, BARMM):
beneficiary records, Excel import/update with a mandatory preview step, reports
and safe exports, and print-ready ID cards that follow the supplied artwork.

Built for the local XAMPP stack with PHP 8.2, Laravel 12, MariaDB 10.4, Blade
and Bootstrap 5 (vendored — no Node.js required).

## Features

- **Beneficiaries** — searchable/filterable list, manual create/edit, archive,
  computed age from birthdate (adult / underage / needs review), duplicate
  candidate flagging with explicit admin resolution.
- **Side navigation** — a persistent left sidebar on desktop that collapses into
  a slide-in drawer on small screens; every section is one click away.
- **Release batches** — group beneficiaries into named operational batches
  (admin only): create a batch, then attach existing beneficiary records to it
  from the batch management view; filter by batch, one batch per beneficiary.
- **Stable generated IDs** — `FIRST-MIDDLE-LAST-0001`, unique, never changed by
  edits; the source workbook `ID NUMBER` is kept separately as `source_id_number`.
- **Excel import** — upload `.xlsx` (≤10 MB, ≤5,000 rows), choose worksheet and
  mapping, dry-run preview with row-level outcomes (new, update, unchanged,
  duplicate, review, invalid), operator decisions, transactional apply, and a
  formula-safe CSV error report. Uploaded files are deleted after apply/cancel.
- **Reports and exports** — filtered lists plus flagged-record views (underage,
  needs review, duplicate candidates, archived); CSV and XLSX export with
  formula-injection protection; exports are admin-only and audited.
- **ID cards** — single-record and batch printing, front/back at 99.4 × 130.2 mm
  on A4 with crop-free margins; emergency-contact block left blank by design.
- **Audit trail** — logins, beneficiary changes, import lifecycle, duplicate
  decisions, exports, and card printing; audit log readable by admins only.

## Quick start (this machine)

`php` is not on `PATH`; use the full XAMPP path or add it:

```powershell
$env:PATH = "C:\xampp\php;$env:PATH"
```

1. Start **Apache** and **MySQL** from the XAMPP Control Panel.
2. Create the database:

   ```powershell
   & C:\xampp\mysql\bin\mysql.exe -uroot -e "CREATE DATABASE IF NOT EXISTS tims_barmm CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
   ```

3. Install dependencies and configure:

   ```powershell
   php C:\xampp\composer.phar install
   Copy-Item .env.example .env
   php artisan key:generate
   ```

   Set `ADMIN_PASSWORD` in `.env` before seeding if you want a known admin
   password; otherwise a random one is printed during seeding.

4. Migrate and seed (admin user, TESDA training reference list, clearly marked
   demo beneficiaries):

   ```powershell
   php artisan migrate --seed
   ```

   For a database with **no** beneficiary rows at all, seed the reference
   seeders individually — `db:seed` also inserts the fabricated demo records:

   ```powershell
   php artisan db:seed --class=AdminUserSeeder --force
   php artisan db:seed --class=BarmmLocationSeeder --force
   php artisan db:seed --class=TrainingsSeeder --force
   ```

5. Open `http://localhost/TIMS-BARMM/public` (or `php artisan serve`).

See `docs/setup-xampp.md` for the full environment guide.

## Tests

```powershell
php artisan test
```

Tests use fabricated data only and an in-memory SQLite database.

## Documentation

| Guide | Contents |
|---|---|
| `docs/user-guide.md` | Day-to-day use: records, import/export, reports, card printing |
| `docs/setup-xampp.md` | Environment, installation, serving |
| `docs/architecture.md` | Stack decisions, schema, authorization |
| `docs/source-data-mapping.md` | Workbook fields, data-quality findings |
| `docs/import-workflow.md` | Import design, outcomes, report columns |
| `docs/id-card-layout.md` | Card measurements, coordinates, print setup |
| `docs/backup-restore.md` | Backup and restore procedures |
| `docs/security-checklist.md` | Controls, role matrix, hardening notes |
| `docs/limitations.md` | Known limitations and open source-file questions |

## Source artifacts

`PROFILE OF TUPAD BENEFICIARIES-*.xlsx`, `FRONT.pdf`, `BACK.pdf` and
`SAMPLE ID.pdf` are source/reference artifacts, not application code. They are
excluded from version control and must never be modified by the application;
imports always copy and read the workbook, then delete the uploaded copy.
