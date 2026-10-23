# User guide — TUPAD Beneficiary LOB System

Plain-language guide for day-to-day use. Administrator-only steps are marked
**[admin]**.

## 1. Sign in and roles

Open the application and sign in with the account created by the seeder. Two
roles exist:

- **Staff** — browse/create/edit beneficiaries, upload and preview imports,
  view reports, print ID cards.
- **Admin** — everything staff can do, plus apply/cancel imports, archive
  records, resolve duplicates, manage release batches, export data, and read
  the audit log.

Inactive accounts cannot sign in. If you are locked out, ask an administrator.

### Navigation

Everything lives behind the **left sidebar**:

- On a desktop screen the sidebar is always visible and the current section is
  highlighted in blue.
- On a phone or a narrow window the sidebar collapses; tap the menu button in
  the top bar to slide it open, and tap the × or click outside to close it.
- The entries are **Dashboard**, **Beneficiaries**, **Batches**, **Excel
  Import**, **ID Cards**, **Reports**, and **Audit log** (admins only). The
  signed-in user and a **Log out** button sit at the bottom of the sidebar.

## 2. Beneficiary records

- **Search/filter** on *Beneficiaries*: free text (name, generated ID, source ID
  No., barangay, occupation), age status, sex, civil status, duplicate status,
  release batch, and active/archived/all.
- **New beneficiary** opens a blank form; the generated ID is assigned on save.
- **Age status** is always recalculated from the birthdate: *Adult* (18+),
  *Underage* (below 18), *Needs Review* (missing/invalid/future date). The Excel
  "Age" column is stored for traceability but never used for decisions.
- **Duplicate candidate** badges appear when a new/edited record matches an
  existing one by source ID number or by name + birthdate. Nothing is merged
  automatically; an admin resolves the flag on the record page.
- **Archive** hides a record from active lists without deleting it. Archived
  records remain printable and exportable when "Archived" or "All" is selected.

## 2a. Grouping beneficiaries per batch [admin]

A **release batch** groups beneficiaries for one round of release, distribution,
or verification work. It is unrelated to an Excel *import* batch — that one
records where a row came from, this one records who is in which round.

The workflow has two steps, and the batch page is where everything happens.

1. **Create the batch** — press **Create Batch** on the *Beneficiaries* page (or
   on the *Batches* page in the sidebar). Give it a name (for example
   "Batch 1 - SGA Pahamuddin") and an optional description, then confirm. You
   are taken straight to the batch's management view. The name is shown on every
   record in the batch.
   - Creating a batch twice with the same name is safe: the second attempt
     opens the batch that already exists instead of creating a duplicate.
2. **Add beneficiaries** — on the batch management view press **Add Beneficiary**.
   The dialog searches the existing beneficiary records and lists only the ones
   that are *not* in this batch yet. Tick the records to attach and press **Add
   to batch**.
   - Nothing new is created — existing records are reused, so no duplicate
     beneficiaries are produced.
   - A record can only be in one batch. Adding a record that already belongs to
     another batch **moves** it to this one; the message says how many records
     were moved.
   - Records that are already in this batch are skipped, never counted twice.

Other things you can do on the batch management view:

- **Members** — the table lists every record in the batch with a link to the
  record, a **Remove** button, and a running total.
- **Remove** — **Remove** takes a record out of the batch. The beneficiary
  record itself is never deleted; it simply becomes *Unassigned*.
- **Settings** — rename the batch, edit its description, or mark it *closed*
  (still usable, but flagged in the lists). **Delete batch** removes the batch;
  it can only be deleted while it is empty — remove its records first.
- The **Batch** dropdown on the *Beneficiaries* list filters by a specific batch
  or by *Unassigned*; each option shows how many records it holds, and the list
  also has a **Batch** column.
- The *Batches* page in the sidebar lists every batch with its status, member
  count, and creation date.
- Every batch action is recorded in the audit log.

Staff can see the batch a record belongs to and can filter by batch, but only
admins can create, add members to, rename, or delete batches.

## 3. Excel import (upload → mapping → preview → apply)

### Prepare the file

- `.xlsx` only, up to 10 MB, 5,000 rows, 20 sheets.
- The source workbook uses a two-row header; the importer detects it and skips
  title/blank rows. Sheet `1,000` is the beneficiary sheet.
- Keep the source `ID NUMBER` in its own column; it is never used as the printed
  card number and never overwrites the generated beneficiary ID.

### Steps

1. **Upload** — *Excel Import → Upload workbook*. The batch is created and you
   are taken to the mapping page.
2. **Worksheet + mapping** — choose the sheet, confirm/adjust which source
   column maps to each field. Mapping is auto-detected and validated on the
   server. Saving runs the **dry run**; nothing is written yet.
3. **Preview** — review the outcome counts and row detail. Outcomes:
   - `new` — will be inserted;
   - `update` — matched an existing record and has changed values;
   - `unchanged` — identical values, no write;
   - `duplicate` — possible duplicate (name + birthdate/source ID); skipped by
     default;
   - `review` — missing/invalid values that need an operator decision;
   - `invalid` — required data missing (e.g. no name); cannot be inserted.
4. **Decisions + confirm [admin]** — choose insert/update/skip per outcome,
   tick the confirmation, and apply. The whole batch is one transaction: on
   error it rolls back and reports the Excel row.
5. **Result** — the batch page shows inserted/updated/skipped/flagged counts and
   per-row results. Download the **error report CSV** (UTF-8 BOM, safe against
   formula injection) for a record of every row.
6. **Cancel [admin]** — an unreviewed batch can be cancelled; the uploaded file
   is deleted and the batch cannot be applied afterwards.

Uploaded workbooks are deleted immediately after a successful apply or cancel.
Re-importing the same file is safe: matching rows come back as `unchanged`
instead of duplicating records.

Note: the supplied workbook contains four trailing non-beneficiary rows
(Excel rows 1018–1024) with no name/birthdate. They are reported as `invalid`
and skipped — expected, not a data corruption.

## 4. Reports and exports

*Reports* provides the same filters as the beneficiary list plus created-date
range. Summary cards link straight to flagged views:

- Underage
- Needs review (missing/invalid age)
- Duplicate candidates
- Archived

**[admin] Export CSV** and **Export XLSX** download the currently filtered list
with the same records you see on screen. Exports are logged in the audit trail
(who, when, filters, row count). CSV cells beginning with `=`, `+`, `-`, `@`,
tab or carriage return are prefixed with `'`; XLSX cells are written as literal
text — neither format can execute a formula.

Staff can view reports on screen but cannot download personal-data exports.

## 5. ID cards

Cards print at **99.4 × 130.2 mm** (50% of the supplied FRONT/BACK artwork),
one card face per page:

- **Single card** — *Beneficiaries → open a record → Print ID card*.
- **Batch** — *ID Cards*, tick records (or select all), then **Print selected
  cards** (up to 500 per batch).

### Printer settings (important)

In the browser print dialog choose:

- Paper: **A4**, portrait
- Scale: **100% / actual size** (not "fit to page")
- Margins: **None**
- Headers and footers: **off**
- Background graphics: **on**
- Duplex: flip on **long edge** (front = page 1, back = page 2)

The on-screen photo placeholder is a guide only and is hidden when printing;
paste the beneficiary's photo in that frame after printing. The
emergency-contact name/address/contact lines are intentionally left blank.

Card data sources: ID NO. = generated beneficiary ID; name = first/middle/last +
extension; issued date = record creation date; birthdate and address from the
record; back signatures are fixed office titles.

## 6. Audit log [admin]

*Audit log* shows who did what and when, with filters for user, action, and date
range. It includes sign-ins, beneficiary changes, import lifecycle, duplicate
decisions, exports, and card printing. Entries contain minimal metadata — never
passwords or full row dumps.

## Registering a beneficiary (updated 2026-10-21)

- **Verify first (required).** Opening the registration form shows a
  **Verify beneficiary** modal that cannot be skipped. Enter the name and
  birthdate, then press **Verify**:
  - **No duplicate** → you see "No duplication — proceeding to registration",
    the modal closes, and the registration form unlocks automatically.
  - **Duplicate found** → the matching record(s) are listed and registration is
    **blocked**. Review the record before registering; nothing is merged.
  The **Create beneficiary** button stays disabled until a successful verify.
  The server enforces this too: a save without a completed verify is rejected.
- **Names auto-capitalize.** First, middle, last and other text fields are
  uppercased automatically — in the duplicate-check modal and the main form — and
  a **suffix** dropdown (NONE, JR., SR., II, III, IV) replaces free-text
  extensions.
- **Age is automatic.** Age is computed from the birthdate and shown read-only;
  you no longer type it. An imported "Age" value is retained for reference only.
- **Address is now one searchable field.** Type a barangay and pick it from the
  list; City/Municipality and Province fill in automatically (BARMM PSGC — see
  `docs/barmm-locations.md`). The formatted address and PSGC code are saved
  together.
- **Standard dropdowns** are used for Occupation, Type of ID, Beneficiary
  Signify, Type of Beneficiary and Mode of Payment. Choosing **OTHER(S)** asks you
  to specify the detail.
- **E-payment is no longer collected** in the UI.
- **Legacy values are preserved.** Records imported with values outside the
  standard lists keep their original text and are flagged for review on edit —
  they are never silently changed.
