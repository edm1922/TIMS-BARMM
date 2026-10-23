# Backup and restore guide — TUPAD Beneficiary LOB System

Operational guide for this XAMPP deployment (Windows, `C:\xampp`, database
`tims_barmm`). Written to be followed step by step during a real restore.

## 1. What must be backed up

| Item | Location | Why |
|---|---|---|
| Database | MariaDB database `tims_barmm` | Beneficiaries, users, import batches/rows, release batches, audit log |
| Private files | `storage/app/private/` | Imported workbooks (deleted after apply/cancel), any future photos/signatures |
| Configuration | `.env` | DB credentials, `APP_KEY`, mail/session settings (contains secrets — store encrypted/offline) |
| Code + assets | project folder (git) | Application code, `public/images/id-cards/header-band.png` |

Imported `.xlsx` files are deleted from `storage/app/private/imports/` immediately
after a successful apply or a cancel, so this folder is usually empty during
normal operation. The database remains the system of record for import history
and row outcomes. Back up `storage/app/private` anyway — it may hold photos or
signatures added later.

Do **not** store backups inside the web root (`C:\xampp\htdocs\TIMS-BARMM\public`):
they would be downloadable. Use an encrypted/offline location.

## 2. Back up

Use `cmd` redirection (`cmd /c ... > file`) rather than PowerShell `>`: PowerShell
5.1 writes UTF-16, which corrupts the SQL dump. The commands below were run on
this machine.

```powershell
$t = 'D:\backups\tims'          # pick a secure, non-web location
New-Item -ItemType Directory -Force -Path $t | Out-Null
$stamp = Get-Date -Format 'yyyyMMdd-HHmmss'

# 1) Database (consistent snapshot, includes users + audit log)
cmd /c "`"C:\xampp\mysql\bin\mysqldump.exe`" -u root --single-transaction --routines --triggers tims_barmm > `"$t\db-$stamp.sql`""

# 2) Private files
Copy-Item -LiteralPath 'C:\xampp\htdocs\TIMS-BARMM\storage\app\private' -Destination "$t\private-$stamp" -Recurse -Force

# 3) Settings (contains secrets — protect or omit if you back up code separately)
Copy-Item -LiteralPath 'C:\xampp\htdocs\TIMS-BARMM\.env' -Destination "$t\env-$stamp" -Force

Get-ChildItem $t | Sort-Object LastWriteTime -Descending | Select-Object -First 5 Name, Length
```

Verify the dump is not empty (a healthy dump of the demo database is tens of KB):

```powershell
Get-Item "$t\db-$stamp.sql" | Select-Object Length
```

Take a backup **before** every real import, before schema changes, and on a fixed
schedule afterwards. Keep at least one copy off the machine.

## 3. Restore

1. Put the application in maintenance mode (optional, prevents writes):

   ```powershell
   $env:PATH = "C:\xampp\php;$env:PATH"
   php artisan down
   ```

2. Recreate the database and load the dump:

   ```powershell
   & C:\xampp\mysql\bin\mysql.exe -uroot -e "DROP DATABASE IF EXISTS tims_barmm; CREATE DATABASE tims_barmm CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
   cmd /c "`"C:\xampp\mysql\bin\mysql.exe`" -u root tims_barmm < `"D:\backups\tims\db-20261021-120000.sql`""
   ```

3. Restore private files (merge, do not nest a second `private` folder):

   ```powershell
   Copy-Item -LiteralPath 'D:\backups\tims\private-20261021-120000\*' -Destination 'C:\xampp\htdocs\TIMS-BARMM\storage\app\private' -Recurse -Force
   ```

4. Restore `.env` only if `APP_KEY` or credentials changed. **A different
   `APP_KEY` invalidates encrypted data and existing sessions.**

5. Clear caches and take the app back online:

   ```powershell
   php artisan config:clear
   php artisan cache:clear
   php artisan up
   ```

6. Verify:

   ```powershell
   & C:\xampp\mysql\bin\mysql.exe -uroot tims_barmm -N -e "select count(*) from beneficiaries; select count(*) from import_batches; select count(*) from beneficiary_batches; select count(*) from audit_logs; select count(*) from users;"
   ```

   Then sign in, open `/beneficiaries`, `/reports`, `/audit`, and print one ID
   card. All existing sessions from before the restore are invalidated because
   the session table is restored to its older state — users must sign in again.

## 4. Restore drill (verified)

Performed on 2026-10-21 against the development database:

1. Dumped the live DB (`mysqldump`, 68 KB) before a controlled 1,000-row import.
2. Imported a copy of the source workbook: 1,004 rows read, 1,000 inserted,
   4 invalid rows skipped; beneficiary count 11 → 1,011.
3. Restored the dump with the commands above.
4. Counts returned to the exact pre-import values (11 beneficiaries, 3 import
   batches, 27 audit entries, max batch id 3) and `storage/app/private/imports`
   was empty.

## 4.1 Full data wipe (verified)

Performed on 2026-10-22 by request — every data table was emptied, the schema
was left in place:

1. Backed up first to `D:\backups\tims\` (`db-20261022-130043.sql`, 2.27 MB,
   plus copies of `storage/app/private` and `.env`).
2. Verified the dump by restoring it into a scratch database
   (`tims_restore_check`) — every table count matched the live DB exactly
   (1,002 beneficiaries, 1,004 import rows, 2,595 locations, 123 trainings) —
   then dropped the scratch database.
3. Wiped with `SET FOREIGN_KEY_CHECKS=0` + `TRUNCATE` on `import_rows`,
   `beneficiaries`, `beneficiary_batches`, `import_batches`, `audit_logs`,
   `users`, `locations`, `trainings`, `sessions`, `cache`, `cache_locks`,
   `jobs`, `failed_jobs`, `job_batches`, `password_reset_tokens`.
   `TRUNCATE` also reset every `AUTO_INCREMENT` to 1. The `migrations` ledger
   was kept, so `migrate:status` stayed fully applied.
4. Re-seeded only the reference data:
   `db:seed --class=AdminUserSeeder`, `--class=BarmmLocationSeeder`,
   `--class=TrainingsSeeder`. `DemoBeneficiariesSeeder` was deliberately **not**
   run, so no beneficiary rows came back.

Final state: beneficiaries 0, release batches 0, import batches 0, import rows
0, audit log 0, users 1 (admin), locations 2,595, trainings 123.

Two side effects worth knowing:

- `users` was emptied, so every session was invalidated and the second admin
  account (`admin_1`) was removed. The `AdminUserSeeder` recreated the primary
  account from `.env`.
- `.env` had **two** `ADMIN_EMAIL`/`ADMIN_PASSWORD` blocks. phpdotenv lets the
  last duplicate win, so the seeded password came from the second block, not
  the intended `admin123` in the first. The duplicate keys were deleted; keep
  only one `ADMIN_*` block in `.env`.

## 5. Rules of thumb

- Back up **before** importing; imports cannot be undone from the UI once applied.
- Keep backups of personal data encrypted/offline; never in the web root or git.
- Test a restore at least once per deployment change, not only when data is lost.
- `php artisan migrate` is only needed when deploying new code, not after restoring
  a dump taken from the same schema version.
