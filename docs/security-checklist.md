# Security checklist — TUPAD Beneficiary LOB System

Status of the controls required by the brief (§10, §13), plus deployment notes.
"Implemented" means it is enforced server-side and covered by tests where listed.

## 1. Authentication and sessions

| Control | Status |
|---|---|
| Every beneficiary route requires authentication | Implemented (`auth` middleware on all app routes) |
| Login rate limit 5 attempts/minute/IP | Implemented (`throttle:5,1`), login failures audited |
| Passwords hashed with bcrypt | Implemented (Laravel `hashed` cast) |
| Session regeneration on login | Implemented (standard Laravel auth flow) |
| Generic login error (no user enumeration) | Implemented |
| No public registration / password-reset link | Intentionally absent; admins create accounts |

## 2. Authorization

| Area | Admin | Staff |
|---|---|---|
| View/create/edit beneficiaries | yes | yes |
| Archive / unarchive / resolve duplicates | yes | no |
| Create / add members to / rename / delete release batches | yes | no |
| Upload workbook, choose mapping, preview | yes | yes |
| Apply or cancel an import | yes | no |
| Print ID cards (single or batch) | yes | yes |
| View reports on screen | yes | yes |
| Export CSV/XLSX of personal data | yes | no (`export-data` gate) |
| Read the audit log | yes | no |

Policies/gates: `BeneficiaryPolicy`, `BeneficiaryBatchPolicy`, `ImportBatchPolicy`, `ExportPolicy`
(`export-data` gate), `AuditLogPolicy`. Inactive users are denied everywhere.
The UI hides unavailable actions, but authorization is always re-checked in the
controller (`Gate::authorize`), never only in Blade.

## 3. Input, uploads, and output safety

- Excel import: `.xlsx` only, MIME/extension checks, ≤10 MB, ≤5,000 rows,
  ≤20 sheets (zip-bomb guard), server-side mapping and row validation.
- Import apply is transactional; a failure rolls back the whole batch and
  reports the Excel row. Upload → preview → confirm is mandatory.
- CSV export: UTF-8 BOM, cells starting with `= + - @`, tab, or CR are prefixed
  with `'` (OWASP formula-injection guard).
- XLSX export: every data cell is written as an explicit string (`TYPE_STRING`),
  so values can never become formulas.
- ID-card printing renders only stored data; emergency-contact fields stay blank
  by design (brief/docs).
- All writes are validated and authorized on the server, not just in JavaScript.
- Blade escapes output; no `{!! !!}` of user data in beneficiary/import views.

## 4. Data protection and audit

- Beneficiary data is never served from a public file path; uploads live on the
  private `local` disk and downloads go through authorized controllers.
- Imported workbooks are deleted after apply/cancel; only batch metadata and
  per-row outcomes remain.
- Audit entries contain minimal metadata — no passwords, tokens, or full row
  dumps. Access to the audit log is admin-only.
- Every audit entry carries a stable event identifier, the actor identity
  (uuid, username, role), and the installation that produced it.
- Audit entries written on an offline installation stay on the device and are
  synchronized to the central server with the next sync. The central copy keeps
  the same actor and origin, and no credential or personal contact detail is
  ever included.
- Encoder accounts are reported to the central server as a read-only identity
  registry (uuid, username, display name, role, active). Passwords, email
  addresses, and contact details never leave the device.
- Installation packages exclude `.env`, keys, logs, backups, generated caches,
  and git history; each package ships a SHA-256 manifest that the setup script
  verifies before installing.
- Tests use fabricated data only; the real workbook is never modified and is
  excluded from version control where required.

Actions currently audited (`audit_logs.action`):

| Action | Trigger |
|---|---|
| `auth.login`, `auth.logout`, `auth.login_failed` | sign-in lifecycle |
| `beneficiary.created`, `beneficiary.updated` | manual create/edit |
| `beneficiary.archived`, `beneficiary.unarchived` | archive lifecycle |
| `beneficiary.duplicate_resolved` | operator duplicate decision |
| `beneficiary_batch.created`, `beneficiary_batch.updated`, `beneficiary_batch.deleted` | release-batch lifecycle |
| `beneficiary_batch.assigned`, `beneficiary_batch.unassigned` | batch membership changes |
| `import.uploaded`, `import.previewed` | import upload and dry run |
| `import.applied`, `import.failed`, `import.cancelled` | import outcome (per-row detail in `import_rows`) |
| `id_card.printed` | single/batch card printing |
| `export.csv`, `export.xlsx` | download of report data (format, filters, count) |
| `encoder.created`, `encoder.updated`, `encoder.deactivated` | field encoder account management |
| `offline_installation.package_generated`, `offline_installation.package_downloaded`, `offline_installation.package_deleted` | installation package handling |
| `offline_server.reauthorized` | reauthorization of an installation |

## 5. Deployment hardening (production)

This build targets a local XAMPP instance. Before exposing it beyond localhost:

1. `APP_ENV=production`, `APP_DEBUG=false`, unique `APP_KEY`, strong DB password
   (do not run the app as MariaDB `root` with an empty password).
2. Serve over HTTPS only; set `SESSION_SECURE_COOKIE=true` and trust the proxy if
   applicable.
3. Document root should be the `public/` folder, not the project root.
4. Restrict `/audit`, `/reports/export`, and `/imports` to the office network or
   VPN if possible; keep the login throttle enabled.
5. Schedule database + private-file backups off-machine (see
   `docs/backup-restore.md`). Encrypt backups containing personal data.
6. Review the admin list periodically; deactivate (`is_active=false`) accounts
   that no longer need access instead of deleting history.
7. Keep PHP/Composer dependencies patched (`composer audit`), and do not enable
   directory listing or expose `.env`, `storage/`, or `database/` dumps.

## 6. Known limitations / accepted risks

- No MFA and no self-service password reset or change-password UI; admins manage
  accounts manually.
- Audit rows are append-only by convention, not by database permissions — a
  database administrator could alter them.
- Import apply/cancel and export are synchronous; a very large workbook ties up
  one PHP request until it finishes (the 10 MB / 5,000-row caps bound this).
- The ID-card header band is a raster crop of the supplied PDF (fonts/logos are
  not vector); print fidelity depends on printer settings (A4, 100% scale, no
  margins).
- Backup/restore is manual and documented, not automated.
- The provided source workbook expects source identifiers to stay in
  `source_id_number`; generated beneficiary IDs are separate and never overwrite
  them.

## 7. Verification evidence

- `php artisan test`: 70 tests / 357 assertions passing at the time of writing
  (includes authorization, formula-injection, audit, import dry-run/apply,
  ID-card content and emergency-blank checks).
- Controlled 1,000-row copy import (2026-10-21): 1,004 rows read, 1,000 inserted,
  4 invalid rows reported and skipped; database restored from backup afterwards.
- Live smoke checks over Apache: login, beneficiary CRUD, import flow, ID-card
  single/batch print, reports, CSV/XLSX export, audit log, and asset delivery.
