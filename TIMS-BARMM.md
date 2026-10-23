# TUPAD Beneficiary LOB System — OpenCode Development Brief

> **Purpose:** This file is the project brief and implementation guide for OpenCode. Read it before modifying or generating code. Treat the provided Excel workbook and `lkjl.pdf` ID-card sample as the primary source materials. Do not invent fields or silently change the intended workflow.

## 1. Project goal

Build a lightweight, reliable **Line of Business (LOB) web application for managing TUPAD beneficiaries** locally using:

- **PHP**
- **Laravel**
- **MySQL or MariaDB**
- **XAMPP** for local development/runtime
- **Blade templates + Bootstrap** for the UI
- **PhpSpreadsheet or Laravel Excel** for Excel import/export, choosing versions compatible with the installed PHP and Laravel versions

Do not introduce React, Node.js as a required frontend runtime, a separate frontend server, or an unrelated stack unless the project owner explicitly approves it.

The system must manage beneficiary records from the provided Excel source, safely import later Excel updates, flag underage beneficiaries and possible duplicates, generate beneficiary IDs, and print ID cards based on `lkjl.pdf`.

## 2. Source files and source-of-truth rules

Expected source files (use the actual uploaded filenames/locations available in the workspace):

- `PROFILE OF TUPAD BENEFICIARIES-PAHAMUDDIN, SGA, BARMM (1,000).xlsx`
- `SAMPLE ID.pdf` — sample TUPAD beneficiary ID card

### Required first action

Before implementing features:

1. Inspect the repository and identify the existing framework, PHP version, Composer version, and current application structure.
2. Locate and inspect the Excel workbook, including every worksheet, header row, merged cells, formulas, date formats, blank rows, and sample records. Do not assume the workbook layout based only on this brief.
3. Inspect `lkjl.pdf` visually and extract its dimensions, orientation, front/back layout, typography, labels, borders, photo/signature areas, and print proportions.
4. Write a short `docs/source-data-mapping.md` documenting the exact Excel worksheet/header mapping and the ID-card layout findings.
5. If a source file is unavailable, report that clearly and continue only with tasks that do not depend on it. Do not fabricate the source structure or claim the template has been inspected.

**Source-of-truth hierarchy:** the provided Excel workbook is authoritative for beneficiary field names and source data; `lkjl.pdf` is authoritative for ID-card appearance and printed labels. This brief adds the application behavior and safety requirements. Where the files do not answer a question, document the uncertainty instead of inventing a fact.

## 3. Beneficiary data and field mapping

Represent the beneficiary fields found in the workbook. Previously identified candidate headers include:

- ID NUMBER
- No.
- First Name
- Middle Name
- Last Name
- Extension Name
- Birthdate
- Brgy.
- City/Municipality
- Province
- District
- Type of ID
- ID No.
- Contact No.
- E-payment/Account No.
- Type of Beneficiary
- Occupation
- Sex
- Civil Status
- Age
- Average monthly income
- Dependent
- Employment interest
- Skills Training Needed

These are **candidate headers, not a substitute for inspection**. Confirm the actual sheet, header rows, spelling, punctuation, and all additional columns directly from the workbook. Preserve source terminology. Also inspect the `List of Trainings` worksheet if it exists and determine whether it is a lookup/reference list.

Requirements:
- Preserve source Excel values and map them to clearly named database columns.
- Keep the source workbook's `No.` sequence separate from the database primary key.
- Store birthdates as proper dates where valid; retain invalid/missing source values in import diagnostics rather than silently guessing.
- Treat `Age` from Excel as a source value only. Calculate age from birthdate for current underage checks.
- Do not drop columns just because they appear unused in the first screen.
- Document any normalization or field type conversion.

## 4. Beneficiary ID rules

Generate an internal, human-readable beneficiary ID using **First Name + Middle Name + Last Name + a unique suffix**.

Example format:

`JUAN-DELA-CRUZ-0001`

Rules:
1. Build the name portion from first, middle, and last name only.
2. Do **not** include birthdate, address, barangay, or other personal data in the ID string.
3. Normalize whitespace and separators consistently. Avoid malformed repeated hyphens. Define and test how punctuation, accents, missing middle names, and extension names are handled.
4. Append a unique suffix so two beneficiaries with the same name can both be saved.
5. The ID must be unique at the database level.
6. Generate it once on record creation and keep it stable when a name is edited. If a correction/regeneration workflow is needed, make it explicit and audited; never silently change IDs.
7. Handle concurrent creation safely. Do not rely only on checking whether an ID exists before insert; use a database unique constraint and retry safely on a collision.
8. Keep the Excel `ID NUMBER` field distinct from the application's generated ID unless source inspection proves they are the same concept. Name them clearly to prevent overwriting source identifiers.

Do not ask the end user to pick between ID formats during implementation; use the format above unless the project owner explicitly changes the requirement.

## 5. Underage and duplicate checks

### Underage detection
- Underage means **under 18 years old**, calculated from the valid birthdate and the current date.
- Show a clear flag/status in beneficiary listings, details, dashboard counts, and import preview.
- Do not rely on the Excel `Age` column as the authoritative current age.
- If birthdate is missing or invalid, mark age status as **Unknown / Needs Review**, not underage and not adult.
- A flag should not silently delete or reject a beneficiary. Allow authorized users to review the record and document the decision.
- Test the age boundary: one day before the 18th birthday, on the 18th birthday, and one day after.

### Duplicate detection
Use a layered approach:
1. Exact match on an existing application-generated ID or another stable, verified identifier where appropriate.
2. Use a verified government/source ID only if the workbook provides it and the project owner confirms it is reliable and suitable as a matching key.
3. Flag possible duplicate candidates using normalized name + valid birthdate.
4. Name-only matches are weaker candidates and must not be automatically merged.
5. During import, present duplicate candidates to the operator with a clear choice: update, skip, import as a separate person, or mark for review, where appropriate.
6. Never silently merge two beneficiaries or overwrite a record solely because their names match.
7. Record the match rule and the operator's decision in the import/audit history.

## 6. Excel import and update workflow

Support `.xlsx` import first. Add `.xls` or `.xlsb` only if the selected library/version supports it reliably and the project owner needs it.

Expected workflow:

1. User uploads a workbook.
2. Validate file type, size, and worksheet selection.
3. Detect likely header row(s) and display the selected worksheet/header mapping for confirmation.
4. Preview rows before any database writes.
5. Validate required fields and date values.
6. Identify each row as one of:
   - New record
   - Likely update
   - Possible duplicate
   - Invalid / needs review
   - Unchanged
7. Show row-level errors and warnings, including underage status and duplicate candidates.
8. Let the operator choose insert/update/skip/review for conflicts; never silently overwrite conflict records.
9. Apply confirmed changes in a database transaction or safe row-level transactions with clear failure handling.
10. Display a summary: rows read, inserted, updated, skipped, flagged, failed.
11. Save import batch metadata and row outcomes for audit/review.
12. Provide an error report downloadable as CSV or Excel.

Safety rules:
- Do not apply an import merely by uploading it; require a review/confirmation step.
- Validate and authorize every write on the server, not only in JavaScript.
- Use safe file storage outside the public web root where possible; remove temporary uploads according to a documented policy.
- Use transactions and do not leave a partially corrupted batch without reporting exactly what was applied.
- Provide a dry-run/preview mode.
- Protect against formula injection when exporting CSV/XLSX, especially values beginning with `=`, `+`, `-`, or `@`.
- Never expose beneficiary records in public URLs or unauthenticated pages.

### Import matching defaults
- First prefer the application-generated beneficiary ID if it is present in an approved update file and is valid.
- Otherwise, use a stable verified source identifier only if configured and confirmed by the operator.
- Otherwise, flag name + birthdate matches for review.
- If no reliable match exists, propose a new record rather than guessing.
- Document the final matching strategy in `docs/import-workflow.md`.

## 7. ID-card printing

Use `lkjl.pdf` as the visual reference. Reproduce the template as closely as practical, based on actual inspection rather than assumptions.

Required card content:
- Front: use the exact labels and placement supported by the PDF, including beneficiary ID, name, photo area, and signature area where shown.
- Back: include birthdate and address in the positions and style supported by the PDF.
- **Emergency contact fields must remain blank on the printed card**: emergency contact name, address, and contact number should appear only as blank placeholders/lines if the sample shows them. Do not populate them from the database.
- Do not invent missing card labels. If the PDF is unavailable or unclear, record that in `docs/source-data-mapping.md` and ask before changing the design.
- Print one card or batch cards.
- Provide a print stylesheet that hides navigation, buttons, tables, and other application chrome.
- Ensure the front/back print dimensions, page breaks, scale, and margins are consistent. Avoid splitting a single card across pages.
- Include a preview before printing.
- Support browser print; explain any required print-dialog settings in the UI/docs.
- Store uploaded photos/signatures securely and authorize access.

## 8. Core modules

### Dashboard
- Total beneficiaries
- Underage records
- Missing/invalid birthdate records
- Possible duplicate candidates
- Recent import batches and outcomes
- Keep the interface clean and practical; prioritize key counts and actions over decorative icons or oversized cards.

### Beneficiary management
- Paginated list with search and filters
- View, create, edit, archive/deactivate (prefer archive over destructive deletion)
- Search by beneficiary ID, name, source ID, barangay, municipality, or other useful mapped fields
- Clearly display underage/unknown-age and duplicate-review flags
- Validate server-side

### Excel imports
- Upload, worksheet/header selection, mapping, preview, row-level validation, conflict resolution, confirmation, result summary, and error report

### ID cards
- Preview, print single card, batch selection/printing, photo/signature placeholders or upload where supported by the sample

### Reports and export
- Filtered beneficiary lists and flagged-record reports
- CSV/XLSX export with formula-injection protection
- Respect user permissions and audit sensitive exports

### Users and permissions
- Authentication required for all beneficiary data
- At minimum, administrator and staff roles if practical
- Only authorized users may import, export, edit, or print sensitive records
- Apply Laravel authorization policies/middleware on the server

### Audit trail
Track who performed sensitive operations and when, including:
- Create/edit/archive beneficiary
- Import batch confirmation and per-row result
- Duplicate decision
- ID generation or explicitly approved ID correction
- Export and print actions where practical

Do not log passwords, session tokens, or unnecessary sensitive data. Keep audit details proportional and access-controlled.

## 9. Suggested data model

Use this as a starting point, not a rigid schema. Adjust only after inspecting the workbook and implementing a documented mapping.

- `users`: Laravel authentication users; add role/permissions appropriately.
- `beneficiaries`: database primary key, application-generated beneficiary ID, mapped source Excel fields, normalized/search fields if needed, photo/signature paths if supported, archive fields, timestamps.
- `import_batches`: uploader, original filename, worksheet, status, counts, start/finish times, and confirmation metadata.
- `import_rows` or `import_row_results`: batch ID, Excel row number, outcome, validation messages, candidate match ID, and operator decision.
- `audit_logs`: actor, action, entity type/ID, timestamp, and minimal structured metadata.

Avoid adding tables without a concrete need. Do not create a separate ID-history table unless there is a real requirement for multiple issued ID versions. Use foreign keys, indexes, unique constraints, and sensible column types.

## 10. Privacy, security, and reliability

Beneficiary data is personal information. Implement privacy by default.

- Require login before displaying any beneficiary information.
- Validate all inputs server-side and escape rendered output.
- Use CSRF protection, secure session settings, Laravel password hashing, and rate limiting where appropriate.
- Use authorization policies for record operations and file downloads.
- Restrict uploaded file types and sizes; do not trust client-supplied MIME types alone.
- Store uploads outside public storage unless access is protected.
- Do not put real beneficiary records, credentials, `.env`, uploaded workbooks, or ID-card scans in Git.
- Add `.env.example` with placeholders only.
- Provide a clear backup/restore guide for the MySQL/MariaDB database and uploaded files.
- Use audit logs for sensitive changes.
- Do not transmit beneficiary data to external services unless explicitly approved.
- Keep dependencies compatible with the installed PHP version; do not upgrade the machine's PHP just to satisfy an unexamined dependency.
- Do not use production personal data in automated tests. Use fabricated test beneficiaries.

## 11. UI and UX expectations

- Clean, modern, professional, neutral interface; practical rather than “AI-generated” or overly decorative.
- Clear hierarchy: emphasize important data and primary actions; minimize unnecessary icons.
- Responsive enough for normal desktop/laptop use.
- Use familiar tables, filters, forms, status badges, and confirmation dialogs.
- Use consistent date formats and clear validation messages.
- Do not show emergency contact data on the printed ID card even if such fields are later added to the database.
- Keep source terminology recognizable to TUPAD staff.

## 12. Development workflow for OpenCode

Follow this sequence. Do not generate the entire application in one giant, unverified pass.

### Phase 1 — Inspect and document
- Inspect the existing repository and source files.
- Verify PHP, Composer, Laravel, MySQL/MariaDB, and XAMPP versions/configuration.
- Inspect the workbook and PDF.
- Create:
  - `docs/source-data-mapping.md`
  - `docs/architecture.md`
  - `docs/import-workflow.md`
  - `docs/id-card-layout.md`
- Report findings, unknowns, and implementation risks before proceeding.

### Phase 2 — Foundation
- Configure Laravel for local XAMPP.
- Set up `.env.example`, database connection instructions, authentication, authorization, base layout, and error handling.
- Create migrations and seeders using fake/demo data only.
- Add setup instructions for Windows/XAMPP.
- Verify migrations and tests before moving on.

### Phase 3 — Beneficiary management
- Implement list, search, filter, view, create, edit, archive, server-side validation, generated IDs, and age status.
- Add tests for ID uniqueness/stability and age boundaries.
- Add duplicate-candidate detection and review status.

### Phase 4 — Excel import/export
- Implement upload, worksheet selection, header mapping, preview, validation, conflict resolution, transactional confirmation, import history, and error report.
- Test with a copy of the workbook; never modify the original source file.
- Include tests for repeated import, unchanged records, duplicate names, missing dates, malformed dates, and failed rows.

### Phase 5 — ID cards
- Match `lkjl.pdf` after visual inspection.
- Implement preview and single/batch print.
- Verify birthdate/address display and blank emergency contact placeholders.
- Test browser print layout at actual size and document printer settings.

### Phase 6 — Reports, audit, hardening
- Add reports and safe exports.
- Complete audit trail, permissions, backup/restore documentation, and security checks.
- Test with a fabricated dataset and then a controlled copy of the 1,000-row workbook.

### Phase 7 — Final handoff
Provide:
- Working application code
- Database migrations and demo seeder
- `.env.example`
- Windows/XAMPP installation guide
- Import/export guide
- ID-card printing guide
- Backup/restore guide
- Automated tests and test results
- A concise list of limitations and any unresolved source-file questions

## 13. Non-negotiable development rules

1. **Audit before coding.** Inspect existing code and the actual source files first.
2. **No mock data in real workflows.** Demo/test data must be clearly separated from imported beneficiary records.
3. **No silent assumptions.** Document unknowns and ask only when a decision blocks safe implementation.
4. **No destructive import behavior.** Preview and confirmation are mandatory; no silent merges or overwrites.
5. **No unnecessary refactoring.** Preserve existing conventions and avoid rewriting unrelated code.
6. **No hardcoded secrets or real personal data in source control.**
7. **No claim of completion without verification.** Run migrations, tests, and relevant manual checks and report what passed or failed.
8. **Work incrementally.** After each phase, summarize files changed, commands run, test outcomes, and remaining risks.
9. **Do not stop at a plan.** After Phase 1 documentation and reporting, proceed to implementation unless a real blocker or a decision requiring the owner's approval is encountered.
10. **Preserve the requested stack:** PHP/Laravel/MySQL or MariaDB/XAMPP, Blade, Bootstrap.

## 14. Definition of done

The application is acceptable when:

- It runs locally under XAMPP using the documented setup.
- The workbook's real fields are mapped and no source columns are silently lost.
- Beneficiaries can be searched, reviewed, edited, archived, imported, and exported with permissions.
- Underage and unknown-age statuses are calculated correctly.
- Duplicate candidates are flagged and resolved explicitly by an operator.
- Generated IDs use first + middle + last name and a unique suffix, contain no birthdate/address, and remain stable after edits.
- Repeated imports do not create avoidable duplicate records or silently overwrite conflicts.
- The ID card visually follows `lkjl.pdf`; birthdate and address are printed; emergency contact fields are blank.
- Single and batch print layouts are checked.
- Sensitive operations have an audit trail.
- Backups and restoration are documented.
- Automated tests run, and results are reported honestly.

---

## 15. Instructions for the first OpenCode session

Start by reading this entire file. Then:

1. Inspect the project directory and identify the actual source files.
2. Inspect the Excel workbook and `lkjl.pdf` directly; do not rely solely on this brief.
3. Write the four Phase 1 documentation files.
4. Show a concise audit report: current repository state, source-data findings, PHP/Composer compatibility, planned schema, unresolved questions, and risks.
5. Continue into implementation in small, verifiable steps. Do not ask for confirmation on decisions already specified here.
6. If the repository is empty, scaffold a compatible Laravel project only after checking the installed PHP/Composer versions and available environment. If dependencies cannot be installed in the environment, explain the blocker and still create the source files that can be safely prepared.
7. Run the available automated tests and report exact commands and outcomes. Never state that the application is fully working unless it has been run and verified.
