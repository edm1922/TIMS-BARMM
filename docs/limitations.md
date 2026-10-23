# Limitations and open questions

Honest list of what the system does not do and what still needs a decision.
Referenced from `README.md`.

## 1. Functional limitations

- **Photos/signatures** — the ID-card layout reserves a 1×1 photo frame and a
  signature line, but there is no photo/signature upload workflow yet. The
  photo area is a preview-only placeholder (hidden on print) and the emergency
  contact block is deliberately blank. Printing assumes staff paste/complete
  these manually.
- **Card size** — 99.4 × 130.2 mm is derived from `SAMPLE ID.pdf` at 50% of the
  FRONT/BACK artwork and was confirmed as the intended size for this build.
  Actual printer calibration (A4, 100%, no margins) should still be verified on
  the target printer before a mass run; small offsets are a printer setting, not
  app data.
- **Header band is raster** — the top band (logos + header text) is a 600 DPI
  crop of the supplied PDF. Fonts/logos are not vector and will not be
  re-typeset by the app. Print quality depends on the printer/PDF pipeline.
- **Synchronous processing** — import preview/apply and exports run inside one
  PHP request; the 10 MB / 5,000-row caps bound the runtime. There is no queue.
- **Matching/duplicates** — duplicate candidates are advisory and never merged
  automatically. 39 rows in the supplied workbook share government ID numbers;
  they are flagged for operator review. If two rows are genuinely different
  people with the same government ID, resolving them is a manual decision.
- **Contact normalization** — 9–10-digit values starting with `9` get a leading
  zero restored; other anomalies are preserved with the raw value. The app does
  not attempt to "repair" phone numbers.
- **Trailing non-data rows** — the supplied workbook has four rows after the
  beneficiary block (Excel rows 1018–1024) without names or birthdates. They are
  imported as `invalid` and skipped, and appear in the import error report.
  This is expected; do not delete or rewrite the source file to silence them.
- **Accounts** — admin and staff roles only; no per-barangay scoping. There is
  no self-service password reset or change-password screen, no MFA, and no user
  management UI (accounts are managed in the database/seeder).
- **Audit log** — append-only by application convention, not enforced by DB
  permissions; a database administrator can alter rows.
- **Backups** — manual and documented (`docs/backup-restore.md`); no scheduled
  backup job is installed.
- **Release batches** — a beneficiary belongs to at most one batch; membership
  is managed by hand from the batch management view (existing records are
  attached one search at a time, not by an automatic rule). There is no
  batch-level status workflow, no per-batch payout recording, and no batch in
  the reports/export output or on the ID card artwork — those are the natural
  next steps if batching is used operationally.
- **Print verification** — card content is asserted by automated tests, but
  physical print alignment cannot be unit-tested; it was verified only through
  browser print preview/settings.

## 2. Open source-file questions

From `docs/source-data-mapping.md` §7 and `docs/id-card-layout.md` §8:

1. The brief mentions `lkjl.pdf`; only `SAMPLE ID.pdf` exists and was treated as
   the reference. Confirm no different artwork was intended.
2. Should the source `ID NUMBER` remain the canonical update key indefinitely?
   The import supports it as a confirmable key (`use_source_id_as_key`); no
   automatic overwrite happens based on it.
3. Province values say `SGA-BARMM` while the sheet subtitle says
   `MAGUINDANAO DEL SUR`; data rows were kept verbatim.
4. Physical card size was inferred and then confirmed for this build (see
   above); re-confirm if the printer or card stock changes.
5. Contact-number semantics remain inconsistent in the source; only safe
   normalization is performed.

## Registration-overhaul limitations (2026-10-21)

6. The address combobox is limited to BARMM PSGC entries (2,595 barangays,
   2025 2Q snapshot). Legacy addresses outside that list are preserved and
   flagged, never remapped. Refresh the snapshot via `BarmmLocationSeeder` when
   PSA releases an update.
7. The pre-registration verification is **required and blocking**: the form is
   locked until **Verify** runs, and if a matching record is found registration is
   blocked entirely — the operator must review the existing record. Nothing is
   merged automatically. A blank middle name is treated as a wildcard, so a
   name-only weak match may block registration of a genuinely different person
   who shares the same first/last name and birthdate.
8. Values outside the standard controlled lists (imported legacy values) are kept
   as-is and flagged; the operator decides whether to update them on edit.
