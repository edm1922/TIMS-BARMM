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
  On an offline installation the generated ID carries a short marker of that
  device (e.g. `DOLE XII-SCFO-BARMM-102026-00001-A1B2`), so a code issued on a
  field device can never be the same code the Central Server issued to another
  person. The Central Server keeps the plain format.
- **Age status** is always recalculated from the birthdate: *Adult* (18+),
  *Underage* (below 18), *Needs Review* (missing/invalid/future date). The Excel
  "Age" column is stored for traceability but never used for decisions.
- **Duplicate** column badges show *why* a record was flagged, not just that it
  was: "Same DOLE ID NUMBER", "Same government ID No.", "Same name and
  birthdate", or "Same name only (weak match)". Nothing is merged automatically;
  an admin resolves the flag on the record page. Once resolved, the badge turns
  red **Confirmed duplicate** (a real duplicate) or green **Cleared duplication**
  (not a duplicate after review).
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
  (still usable, but flagged in the lists). **Delete batch** removes the batch
  *and every beneficiary filed in it*: those records are deleted permanently, so
  the page shows how many will go and asks you to type the batch name to
  confirm. To keep the records, remove them from the batch first — they then
  stay in the register as *Unassigned*.
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
range. The **Batch** filter narrows the list to one release batch, or to
**Unassigned** for records that are not yet in any batch; each option shows the
batch name and its number of members, and each row shows its batch. Summary
cards link straight to flagged views:

- Underage
- Needs review (missing/invalid age)
- Duplicate candidates
- Archived

**[admin] Export CSV** and **Export XLSX** download the currently filtered list
with the same records you see on screen. Exports are logged in the audit trail
(who, when, filters, row count). CSV cells beginning with `=`, `+`, `-`, `@`,
tab or carriage return are prefixed with `'`; XLSX cells are written as literal
text — neither format can execute a formula.

**Export XLSX** reproduces the DOLE **Annex D — Profile of TUPAD Beneficiaries**
form (`Sample format.xlsx`): masthead rows 1–10, the two-row merged header on
rows 13–14, and one beneficiary per row from row 15. The Province,
Municipality, and Barangay lines are filled only when the filtered set covers a
single value for that field; a mixed selection leaves them blank. Columns are:
ID Number, COS Number, No. (`source_row_no`), Name of Beneficiary (first,
middle, last, extension), Birthdate (`YYYY/MM/DD`), Project Location (Brgy.,
City/Municipality, Province, District), Type of ID, ID No., Contact No.,
E-payment/Account No., Type of Beneficiary, Occupation, Sex, Civil Status, Age,
Average monthly income, Dependent, wage-employment interest, and Skills
Training Needed. **Export CSV** stays a flat, one-column-per-field data dump.

Exports are named in the DOLE workbook convention —
`TUPAD PROFILE OF BENEFICIARIES-DATU PAGLAS BARMM (83).xlsx`, where the
municipality comes from the filtered set and the number in parentheses is the
record count. If a batch is selected and its members span several towns, the
town in the batch name is used (for example batch
`DATU PAGLAS, MAGUINDANAO DEL SUR BARMM (100)` exports as
`TUPAD PROFILE OF BENEFICIARIES-DATU PAGLAS BARMM (87).xlsx`). Only when
neither applies is the location part dropped:
`TUPAD PROFILE OF BENEFICIARIES (1283).xlsx`.

Staff can view reports on screen but cannot download personal-data exports.

### When the reports page will not load

On an offline server the reports page can fail if the site's code was updated
without the database updates being applied (the batch tables are then missing).
The page then explains what is missing instead of showing a blank error, and
lists the pending updates with the commands to run:

```
php artisan migrate --force
php artisan config:clear
php artisan view:clear
```

Every failure is also written to the audit trail as `report.error` (with the
missing schema, pending migrations, PHP and database versions), so the cause
reaches the central server with the next synchronization. The same check
appears on the offline server's **Sync → Diagnostics** page under *Database
updates*.

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
passwords or full row dumps. The **Details** column lists that metadata as
labelled values (for example the batch name, the number of records affected, and
the affected beneficiaries by name — taken from their deletion entry once a
record is gone, falling back to their id), so an entry can be read without
decoding raw JSON. Long lists are collapsed behind an "N items" toggle that
reveals every value.

The **Action** filter is written in plain language and grouped by area (sign-in
activity, beneficiaries, batches, imports, exports and printing, accounts,
offline installations, sync requests). Each option shows how many entries it
has, for example "Registered a beneficiary (102)". Actions that were renamed
over time appear once and match every entry recorded under either name. The
Action column of the table uses the same wording; the original technical name
is available as a tooltip.

"Registered a beneficiary" and "Assigned a beneficiary to a batch" are different
events: the first records that a new beneficiary record was created, the second
that a record became a member of a batch. Registering a beneficiary from a
batch does both, so one registration produces two entries (one under
*Beneficiaries*, one under *Batches*) — that is why their counts usually match.

The **Actor** column shows the person's name with their role underneath
(Encoder, Manager, Administrator). Entries made on an offline installation are
named from the account registry that installation reports to the central
server; if no name is known the username is shown instead. The username is
available as a tooltip when hovering the name.

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
