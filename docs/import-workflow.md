# Excel Import / Update Workflow — TUPAD Beneficiary LOB System

> Phase 1 design. Based on the inspected workbook (`1,000` sheet: two-row header at rows 13–14, data rows 15–1014). See `docs/source-data-mapping.md` for columns.

## 1. Scope

- Primary format: `.xlsx` (PhpSpreadsheet). `.xls`/`.xlsb` only if later confirmed needed.
- Limits: max 10 MB, max 5,000 data rows, max 20 worksheets; deeper/huge files rejected before parsing.
- The source workbook is never modified. Uploads are copied to `storage/app/private/imports/{uuid}.xlsx`.
- Import is always: upload → preview/dry-run → explicit confirmation → apply → summary/audit. Upload alone never writes beneficiary data.

## 2. Upload & validation

Server-side checks (not only JS):
1. CSRF, authenticated user, role (staff may upload/preview; apply requires admin by default).
2. Extension `.xlsx`, real MIME (`application/vnd.openxmlformats-officedocument.spreadsheetml.sheet`) verified via `fileinfo`, size ≤ 10 MB, valid zip signature.
3. Reject password-protected or macro (`.xlsm`) files with a clear message.
4. Store privately; create `import_batches` row with status `uploaded`.

## 3. Worksheet & header selection

- List worksheets (marking hidden sheets, e.g. `List of Trainings`). Default suggestion = visible sheet with the most non-empty data rows (`1,000`).
- Header detection: scan rows 1–30 of the chosen sheet for a row whose normalized cells contain at least `Name of Beneficiary` or `First Name` plus `Birthdate`, or `No.` plus `First Name`. The inspected workbook resolves to **row 13 (group) + row 14 (sub-header)**.
- Merged-cell expansion: propagate merged ranges (e.g. `C13:F13`) so every column gets the group label; combine with the sub-header below (`Name of Beneficiary / First Name`). The exact combined labels from inspection are the mapping defaults.
- Show detected header row and per-column mapping (source header → DB field) for operator confirmation. Operator may remap columns or mark a column ignored; unrecognized/duplicate columns are shown explicitly, never dropped silently.
- Persist `mapping` JSON and `header_row` on `import_batches`.

## 4. Row parsing & validation

For each data row (from header+2 when a two-row header is used, i.e. row 15 onward):
- `first_name`, `last_name` required → else `invalid`.
- `birthdate`: accept Excel date cells and `YYYY-MM-DD` / `YYYY/MM/DD` strings; invalid/missing → row remains importable but flagged `review` with reason (`birthdate_unknown`), and `birthdate_raw` stores the original text. Never guess.
- Trim all strings; map literal `N/A`/`n/a` in extension to null. Collapse nothing else in stored values (SQL-safe as-is).
- Contact: digits-only normalization; if the value is 9 or 10 digits and starts with `9`, prepend `0` (restores a stripped leading zero); keep `contact_no_raw`; anything that is still not 10–11 digits is flagged as a warning (not invalid).
- `Age` column is read into `age_source` only; it is never used for status.
- Income parsed to decimal, reject negative/non-numeric with warning.
- Once per file: detect **in-file duplicates** (same `source_id_number`, or same normalized name+birthdate) and flag later occurrences.

Real-data expectations (from inspection): all 1,000 rows parse, 108 whitespace-only defects are auto-trimmed, no invalid birthdates today, no underage records today, 39 duplicated gov `ID No.` values → duplicate *candidates* (not updates).

## 5. Matching & classification (per row)

Matching priority (first hit wins; recorded on the row):

1. App-generated `beneficiary_code` present in the file (column mapped manually) → exact match.
2. `ID NUMBER` (`source_id_number`) → exact match. Enabled by default (`use_source_id_as_key`), operator can disable; only uniqueness-verified in the inspected source.
3. Normalized name + valid birthdate → **possible duplicate** (never auto-updates).
4. Name only → weak candidate; never auto-merge.
5. No match → propose `new`.

Classification precedence and outcomes:

| Condition | Outcome |
|---|---|
| Required fields missing / unparseable row | `invalid` |
| Matches exactly one record and all mapped fields equal (trimmed, normalized) | `unchanged` — skipped on apply |
| Matches exactly one record, fields differ | `update` (needs operator approval) |
| Name+birthdate (or gov ID No.) matches existing record(s) without a priority-1/2 match | `duplicate` (candidate list shown) |
| Multiple priority-1/2 matches (should never happen due to unique constraints) | `review` |
| No match | `new` |
| In-file duplicate of an earlier row | `review` (same-file conflict) |

Conflicts in a *source* field are never silently resolved. Updates never touch `beneficiary_code`, `created_at`, or archive fields.

## 6. Preview UI

- Summary tiles: rows read, new, update, unchanged, duplicate candidates, invalid, needs review.
- Paginated row table: Excel row number, key name fields, birthdate, age status (`adult|underage|unknown`), outcome badge, messages, matched record link, candidate list.
- Filters by outcome; search within preview.
- Dry-run only: **no writes** before confirmation. `import_batches.status = previewed`, `import_rows` populated with outcomes/messages.
- Downloadable pre-import validation report (CSV) with the same injection protection as exports.

## 7. Operator decisions & confirmation

Per row and bulk by outcome:
- `new` → insert / skip / review.
- `update` → update / insert as separate person / skip / review. Per-field diff displayed (old → new).
- `duplicate` → link to existing (skip) / import as separate person / update existing (admin) / mark for review.
- `invalid`/`review` → skip / import anyway only where safe (e.g. missing birthdate with explicit acknowledgment) / review.

Confirmation screen repeats the counts and requires an explicit "Apply confirmed changes" action. Uploading a second batch does not modify the first.

## 8. Apply (transactional)

- Single DB transaction for the whole confirmed batch (bounded at 5,000 rows): inserts, approved updates, `import_rows.applied`, batch counters and `audit_logs` in one commit.
- Row failure policy: validation was completed at preview; apply-time failures (e.g. unique collision on `source_id_number`, ID-generation retry exhaustion) abort the transaction and report the exact Excel rows affected. Status `failed` + diagnostics; nothing partially applied.
- On success: `import_batches.status = applied`, `finished_at`, counters persisted; result summary page: rows read / inserted / updated / skipped / flagged / failed.
- Audit: batch id, actor, filename, worksheet, mapping, counts, and per-row outcome/decision are retained in `import_rows` and `audit_logs` (no full personal-data dumps beyond what the row already stores).

## 9. Repeated imports

- Re-uploading the same workbook: every row classifies as `unchanged` and applying inserts/updates nothing.
- Edited copies: only changed matched rows become `update`; new rows become `new`; removed rows are **not** deleted or archived automatically (report them as `missing_from_import` in the summary for operator follow-up).
- Updates are diffed against the *matched* record, not field-by-field overwrite of unmatched identifiers.

## 10. Error report

CSV (UTF-8 with BOM for Excel) containing: `excel_row`, `outcome`, `severity`, `field`, `message`, `source_id_number`, `name`, `birthdate_raw`, `candidate_codes`, `operator_decision`. Cells beginning with `=`, `+`, `-`, `@`, tab, or CR are prefixed with `'` to prevent formula injection. Filename: `import-{batch_id}-errors-{timestamp}.csv`.

## 11. File lifecycle & retention

- Uploaded file: private storage, deleted immediately after successful apply; pending/cancelled batches deleted within 7 days (documented cleanup command `imports:prune`).
- No uploaded workbook contents are logged to application logs.

## 12. Documented defaults (brief §6)

- Match key order: generated code → `ID NUMBER` (operator-confirmable) → name+birthdate review → new.
- Weak name-only matches never auto-merge and are shown as advisory candidates.
- Dry-run is the default; apply requires explicit confirmation.

## 13. Test matrix

- Happy path: 1,000-row copy imports as 1,000 `new`.
- Repeat with same file → 1,000 `unchanged`, 0 writes.
- Modified copy (3 names, 2 birthdates, 1 removal) → 5 `update`, 1 `missing_from_import`, rest `unchanged`.
- Duplicate name+birthdate pair → `duplicate` + operator choices.
- Duplicate gov `ID No.` values → candidates, no auto-merge.
- Missing birthdate / `2008-13-45` string → `review` + raw retained.
- Leading/trailing whitespace and `N/A` extension → normalized (tested).
- Formula injection export fixture (`=cmd|...`, `+1`, `-1`, `@SUM`) → prefixed.
- Failed row mid-apply → full rollback, batch `failed`, exact row reported.
- Authorization: staff cannot apply/export; unauthenticated cannot upload.

## Registration-overhaul additions (2026-10-21)

- Plain-text fields (names, occupation, ID type, signify, mode of payment, address,
  legacy location parts) are **uppercased** on parse for consistency with the form.
- When the source omits a unified Address, it is derived from the legacy location
  parts; `location_code` is resolved by matching the PSGC `locations.full_address`.
- Values outside the controlled lists emit an advisory `legacy_value` **warning**
  (kept as-is, never remapped) and appear in the dry-run report.
- New optional target fields: `beneficiary_signify`, `mode_of_payment`,
  `occupation_other`, `id_type_other`, `address`.
- The dry-run report CSV columns are unchanged:
  `excel_row,outcome,severity,field,message,source_id_number,name,birthdate_raw,candidate_codes,operator_decision`.
