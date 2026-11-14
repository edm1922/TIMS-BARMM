# Beneficiary Calendar-Year Expiration / Duplicate Reset — Implementation Plan

**Status:** Proposed — do not implement until approved.

## 1. Goal

From **January 1, 2027**, a beneficiary registered in **2026** must no longer count as a duplicate when the same person applies for the **2027** program year.

The same rule must repeat every calendar year:

- Duplicate detection is scoped to the beneficiary's **coverage year**.
- A 2026 record remains in the database and remains visible historically.
- A 2027 registration creates its own record (or is explicitly matched to a 2027 record only).
- Records from prior years are excluded from duplicate blocking for the new year.

## 2. Non-goals / clarification

This plan does **not** automatically archive, hide, or delete 2026 beneficiaries on January 1, 2027. The requirement is a **year-scoped duplicate reset**, not removal of historical records.

If the desired behavior is instead “beneficiaries become inactive after one year,” that is a separate feature. This plan treats “expiration” as **expiry of duplicate relevance for the prior calendar year**.

## 3. Current behavior (verified)

- `DuplicateDetector::find()` and `findForRegistration()` search **all** beneficiary records, with no date or year filter.
- Duplicate rules include `beneficiary_code`, `source_id_number`, `source_id_no`, `name_birthdate`, and weak `name_only_weak`.
- Manual registration, import preview, duplicate review, sync request review, and local sync apply all call the same detector.
- `beneficiaries` has no `coverage_year`/`valid_until` column.
- `profiled_on` exists and is used for reporting, but is not used for duplicate scoping.
- Current unique index `beneficiaries_origin_batch_source_unique` is on `(origin_server_uuid, beneficiary_batch_id, source_id_number)`, so a person can already exist in different batches.
- `beneficiary_code` remains globally unique, so each new yearly record needs a new code.

## 4. Proposed data model

### 4.1 Add `coverage_year`

Add to `beneficiaries`:

```text
coverage_year  unsignedSmallInteger  nullable initially, indexed
```

Meaning: the calendar/program year in which this beneficiary record participates for duplicate purposes.

### 4.2 Effective-year resolution

Use the following precedence when creating or backfilling a record:

1. Explicit coverage year supplied by the import/manual flow (if added later).
2. Year of `beneficiaries.profiled_on`.
3. Year of `beneficiary_batches.profiled_on`.
4. `beneficiary_batches.program_year`.
5. Year of `beneficiaries.created_at`.
6. Current year as the final fallback.

For a batch import, the batch's `profiled_on` / `program_year` is the strongest context; for manual registration without a batch, current year applies unless a profiling date is supplied.

### 4.3 Indexes

Add indexes to keep year-scoped duplicate queries fast:

```text
(coverage_year, name_key)
(coverage_year, source_id_no, origin_server_uuid)
(coverage_year, source_id_number, origin_server_uuid)
```

Do **not** add a unique constraint on government ID across years. The same person is expected to have one record per coverage year.

The existing `(origin_server_uuid, beneficiary_batch_id, source_id_number)` unique key remains valid.

## 5. Duplicate detection changes

### 5.1 Detector API

Extend both public detector methods with optional year scoping:

```php
find(..., ?int $coverageYear = null, ?int $ignoreId = null)
findForRegistration(..., ?int $coverageYear = null)
```

Rules:

- If `coverageYear` is supplied, every candidate query must be constrained to `coverage_year = :year`.
- The year filter applies to **all** rules, including exact `beneficiary_code`, `source_id_number`, `source_id_no`, name+birthdate, and weak name-only matches.
- Existing callers that omit the year continue to behave as before during the transition, but all registration/import/review paths must pass the effective year before release.

### 5.2 Registration context

Manual registration (`BeneficiaryController`):

- Determine the effective year from the selected batch (`profiled_on`/`program_year`) or current year.
- Pass it to `findForRegistration()`.
- Store it in `beneficiaries.coverage_year`.

### 5.3 Import context

`ImportService`:

- Determine the import batch's coverage year from `beneficiary_batches.profiled_on`, then `program_year`, then the import `profiling_date`, then current year.
- Apply it to both:
  - In-file duplicate detection keyed by government ID / name+birthdate.
  - Existing-database duplicate detection.
- Store `coverage_year` on every inserted record.
- Existing records matched for update should keep their own coverage year unless the import is explicitly crossing years.

`DuplicateReviewService`:

- Scope `governmentIdConflict()` to the duplicate row's import coverage year.
- Scope matched candidates to that same year.

### 5.4 Sync request review

`SyncRequestController` and `ReviewQueueService`:

- Include the incoming record's coverage year in the review context.
- Do not hold a 2027 registration merely because a 2026 record has the same name or government ID.
- Continue to hold same-year duplicates as before.

### 5.5 Local/offline sync apply

`LocalChangeApplier`:

- Add `coverage_year` to synchronized beneficiary payloads.
- Scope `findLocalDuplicate()` to the incoming record's coverage year.
- Existing local records without `coverage_year` are backfilled during the migration/update.

### 5.6 Backfill command

Add an idempotent command, e.g.:

```text
php artisan beneficiaries:backfill-coverage-year
```

Behavior:

- Dry-run by default.
- `--apply` updates rows in batches.
- Resolve year using the precedence in section 4.2.
- Report counts by resolved year and rows that required fallback to current year.

The command should be safe to run after deployment and before enabling year-scoped duplicate checks.

## 6. Migration / sync compatibility

1. Add nullable `coverage_year` column and indexes.
2. Backfill existing rows.
3. Make the application read/write the column.
4. Add `coverage_year` to `SyncPayload`, sync change payloads, and offline application.
5. Bump the sync schema version if the project’s compatibility policy requires it; older offline packages ignore unknown fields but must not be allowed to create year-ambiguous records.
6. Run a full package/publish cycle for offline servers after central deployment.

## 7. UI / reporting

Minimal UI changes:

- Show coverage year on the beneficiary detail page (read-only).
- Add an optional “Coverage year” filter on `/beneficiaries` and `/reports` if operators need to view a specific year.
- Keep annual dashboard reporting based on `profiled_on`/batch `program_year`; add `coverage_year` only if reporting needs the duplicate-scope year explicitly.

No new “Expired” status is required by this plan. A prior-year record is historical, not deleted or deactivated.

## 8. Test plan

### Year-boundary tests
- Freeze time at `2026-12-31`; register a beneficiary; freeze at `2027-01-01`; register same name, birthdate, government ID; expect **new registration**, no duplicate block.
- Within 2027, register two identical people; expect the existing same-year duplicate behavior.

### Detector tests
- `findForRegistration(..., 2027)` ignores a 2026 record with identical name/birthdate.
- `findForRegistration(..., 2026)` still finds the 2026 record.
- Exact government ID and beneficiary-code rules are also year-scoped.

### Import tests
- Import 2026 workbook; then import the same person in a 2027 batch; expect insert, not duplicate review.
- In-file duplicate rules still work within one year.
- Import without explicit profiling date uses current year.

### Sync tests
- Central 2027 record syncs to an offline server that already holds the 2026 record; local duplicate check does not conflict.
- Payload includes `coverage_year`; local record stores it.
- Review inbox shows the correct year context.

### Regression tests
- Existing duplicate detection tests remain valid for same-year records.
- Existing imports/manual registration tests that rely on lifetime duplicate blocking must be updated to use the same coverage year.
- Full suite and Pint must pass.

## 9. Rollout sequence

1. Approve this plan and resolve the open decisions below.
2. Add migration + backfill command; run on central.
3. Add detector year scoping and update all call sites.
4. Add registration/import year assignment.
5. Add sync payload/applier support.
6. Add UI year display/filter if approved.
7. Run targeted tests, full suite, Pint.
8. Build and publish an offline package; backfill offline servers after update.
9. Verify with a controlled 2027 test record before January 1, 2027.

## 10. Risks

- **Mixed-year imports:** an import file may contain multiple years. The plan must decide whether one import batch is one coverage year or rows can override it.
- **Existing records with null profiling dates:** fallback to `created_at` can misclassify historical records if created dates were migrated/restored.
- **Offline servers:** new records must carry coverage year consistently; otherwise a 2027 record may be treated as a 2026 duplicate.
- **Unique code generation:** each yearly record needs a new beneficiary code; confirm annual code series behavior.
- **Reporting:** duplicate candidate counts for prior years may change once year scoping is enabled.

## 11. Open decisions requiring approval

1. **Definition:** Is this only a duplicate reset, or should prior-year beneficiaries also be marked expired/inactive in listings?
2. **Import granularity:** Is one import batch always one coverage year, or can a single workbook contain multiple years?
3. **Manual registration:** should coverage year default to the current year, or follow the selected batch’s program year?
4. **Historical matching:** when editing a 2026 record in 2027, should it be compared only against 2026 records or against both years?
5. **UI:** do you want a visible Coverage Year column/filter now, or only the backend behavior?

## 12. Verification graph

After approval, re-run Graphify and use the resulting graph to verify the relationships between duplicate detection, registration, import, sync review, and local apply before coding.
