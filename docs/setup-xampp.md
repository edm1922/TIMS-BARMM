# Windows / XAMPP Setup Guide — TUPAD Beneficiary LOB System

Local development and runtime instructions for this machine (Windows + XAMPP).

## 1. Requirements

| Component | Verified version |
|---|---|
| XAMPP | `C:\xampp` (Apache + MariaDB) |
| PHP | 8.2.12 (`C:\xampp\php\php.exe`) — extensions: zip, mbstring, gd, pdo_mysql, xml, fileinfo, bcmath, curl, openssl |
| MariaDB | 10.4.32 (`C:\xampp\mysql\bin\mysql.exe`) |
| Composer | `C:\xampp\composer.phar` (run via `C:\xampp\php\php.exe C:\xampp\composer.phar`) |

`php` and `composer` are not on `PATH`. Either use the full paths below or add `C:\xampp\php` to `PATH`.

PowerShell helper used in this guide:

```powershell
$PHP = "C:\xampp\php\php.exe"
$COMPOSER = "$PHP C:\xampp\composer.phar"
```

## 2. Start services

1. Open the **XAMPP Control Panel** and start **Apache** and **MySQL** (ports 80 and 3306).
2. Or from a shell: `C:\xampp\xampp_start.exe`.

Verify: `Test-NetConnection 127.0.0.1 -Port 3306` → `TcpTestSucceeded : True`.

## 3. Create the database

```powershell
& C:\xampp\mysql\bin\mysql.exe -uroot -e "CREATE DATABASE IF NOT EXISTS tims_barmm CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
```

XAMPP's default MySQL account is `root` with an empty password.

## 4. Configure the environment

```powershell
Copy-Item .env.example .env
& $PHP artisan key:generate
```

Then edit `.env`:

- `DB_DATABASE=tims_barmm`, `DB_USERNAME=root`, `DB_PASSWORD=` (XAMPP default).
- `ADMIN_EMAIL` / `ADMIN_PASSWORD` for the seeded administrator. Leave `ADMIN_PASSWORD` empty to have a random password printed during seeding.

`.env.example` contains placeholders only and is safe to commit. `.env` is git-ignored.

## 5. Install dependencies

```powershell
& $COMPOSER install
```

(Present already when the project was scaffolded: `phpoffice/phpspreadsheet` for Excel import/export.)

## 6. Migrate and seed

```powershell
& $PHP artisan migrate --force
& $PHP artisan db:seed --force
```

Seeds:

- administrator account (from `.env`),
- 123 TESDA training reference rows (extracted from the source workbook's hidden `List of Trainings` sheet),
- 8 **fabricated** demo beneficiaries (notes begin with `DEMO RECORD`; safe to delete with `php artisan migrate:fresh`).

Re-running `db:seed` is safe: admin and trainings are updated in place, demo records are skipped once present.

To reset everything (destroys local data):

```powershell
& $PHP artisan migrate:fresh --seed --force
```

### Clean reset without demo beneficiaries

`DatabaseSeeder` also calls `DemoBeneficiariesSeeder`, so a full seed always
re-inserts the fabricated demo records. For a clean slate with no beneficiary
rows at all, seed the three reference seeders individually:

```powershell
& $PHP artisan db:seed --class=AdminUserSeeder --force
& $PHP artisan db:seed --class=BarmmLocationSeeder --force
& $PHP artisan db:seed --class=TrainingsSeeder --force
```

This leaves `beneficiaries`, `beneficiary_batches`, `import_batches`,
`import_rows` and `audit_logs` empty while the admin account, the 2,595 BARMM
locations and the 123 training rows stay in place.

Note on `.env`: keep a single `ADMIN_USERNAME` / `ADMIN_EMAIL` /
`ADMIN_PASSWORD` block. phpdotenv lets the **last** duplicate key win, so two
blocks silently change the admin password.

## 7. Run the application

**Option A — Apache (recommended):** browse to

```
http://localhost/TIMS-BARMM/public
```

**Option B — PHP dev server:**

```powershell
& $PHP artisan serve
# http://127.0.0.1:8000
```

Log in with the administrator email/password from step 4 (the seeding console prints a generated password if none was set).

## 8. Run tests

```powershell
& $PHP artisan test
```

Tests run against an in-memory SQLite database (`phpunit.xml`) and never touch the MariaDB data. All test data is fabricated.

## 9. Sensitive file handling

Never commit (already git-ignored):

- the real workbook `PROFILE OF ... (1,000).xlsx`,
- `SAMPLE ID.pdf`, `FRONT.pdf`, `BACK.pdf`,
- `.env`, uploaded imports (`storage/app/private/imports/`), photos/signatures.

The workbook must be **closed in Excel** before running import tests (a `~$...xlsx` lock file means Excel has it open).

## 10. Troubleshooting

| Symptom | Fix |
|---|---|
| `Connection refused` on 3306/80 | Start Apache/MySQL in XAMPP Control Panel |
| `Access denied for user 'root'` | XAMPP default root has empty password; check `DB_*` in `.env` |
| Blank page / wrong paths under Apache | Site root must be `.../TIMS-BARMM/public`, not the project root |
| `php` not recognized | Use full path `C:\xampp\php\php.exe` or add it to `PATH` |
| Changes to `.env` not applied | `& $PHP artisan config:clear` |
| Import upload fails on a large file | Check `upload_max_filesize` / `post_max_size` in `C:\xampp\php\php.ini` (cap 10 MB enforced by the app) |
