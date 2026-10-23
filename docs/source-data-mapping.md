# Source Data Mapping — TUPAD Beneficiary LOB System

> Phase 1 inspection record. Everything below was read directly from the provided files, not assumed.
> Inspection date: 2026-10-18.

## 1. Source files

| Reference in brief | Actual file in workspace | Status |
|---|---|---|
| Excel workbook | `PROFILE OF TUPAD BENEFICIARIES-PAHAMUDDIN, SGA, BARMM (1,000).xlsx` | Present (212,137 bytes) |
| `lkjl.pdf` | `FRONT.pdf`, `BACK.pdf` (owner-uploaded per-side card files), earlier `SAMPLE ID.pdf` (2-up sample) | Present; **filenames differ from the brief** — FRONT/BACK are the authoritative card references |

Notes:

- A `~$PROFILE OF ... .xlsx` lock file is present, meaning the workbook was open in Excel during inspection. The workbook must be closed (or a copy used) for import testing. The original file was **not modified**; inspection ran on a copy.
- The Excel workbook is the source of truth for field names and source data.
- `FRONT.pdf` and `BACK.pdf` are the source of truth for card appearance (see `docs/id-card-layout.md`); `SAMPLE ID.pdf` confirms the same artwork at half scale.

## 2. Workbook structure

Sheets found:

| Sheet | State | Size | Purpose |
|---|---|---|---|
| `Sheet1` | visible | empty (A1:A1) | unused |
| `1,000` | visible | A1:AX1025 | main beneficiary data |
| `List of Trainings` | **hidden** | A1:Z354 | TESDA training lookup/reference list |

### 2.1 `1,000` sheet layout

- Row 1: `Annex D` (merged B1:X2)
- Row 3: `Profile of TUPAD Beneficiaries` (merged B3:X3)
- Row 6: `Nature of Project:`
- Row 7: `DOLE Regional Office: XII`
- Row 8: `Province: MAGUINDANAO DEL SUR`
- Row 9: `Municipality:` (blank value)
- Row 10: `Barangay:` (blank value)
- **Rows 13–14: two-row header** (row 13 group headers, row 14 sub-headers), with these merged ranges:
  `B13:B14`, `C13:F13`, `H13:K13`, `L13:L14`, `M13:M14`, `N13:N14`, `O13:O14`, `P13:P14`, `Q13:Q14`, `R13:R14`, `S13:S14`, `T13:T14`, `U13:U14`, `V13:V14`, `W13:W14`, `X13:X14`
- **Data rows: 15–1014 (exactly 1,000 records).** No blank rows inside the data range.
- Footer rows: 1018 `Prepared and Certified true and Correct by:`, 1021 `MARNIE DAVE S. EBALLES`, 1022 `Supervising LEO/OIC-SCFO Head`, 1024 long `Notes:` block (birthdate format, project location, beneficiary types, occupation, civil status, dependent, trainings guidance).
- No Excel data validations/dropdowns present.

`Name of Beneficiary` is a merged group (C:F), split into four sub-columns. Any importer must expand merged cells and combine both header rows; the brief's flat candidate list does not reflect this.

## 3. Column mapping (authoritative)

Column letters are 1-based Excel columns; header text is reproduced verbatim (whitespace normalized for display). Recommended DB names follow the brief's "preserve source terminology" rule.

| Col | Header row 13 | Header row 14 | Proposed DB column | Type | Inspection findings |
|---|---|---|---|---|---|
| A | `ID NUMBER` | — | `source_id_number` | string(64), nullable, unique | DOLE identifier, format `DOLE XII -SCFO-BARMM-1507-102026-002627` (all 1,000 rows contain ` XII -`). All 1,000 unique. **Distinct from app-generated ID.** |
| B | `No.` | — | `source_row_no` | unsigned integer | 1–1000 sequence. Keep separate from DB primary key. |
| C | `Name of Beneficiary` | `First Name` | `first_name` | string(100) | Required in source. 95 rows have leading/trailing spaces. |
| D | (merged C:F) | `Middle Name` | `middle_name` | string(100), nullable | 95 rows have whitespace issues; no blanks. |
| E | (merged C:F) | `Last Name` | `last_name` | string(100) | Whitespace issues present (e.g. `' ADAM'`). |
| F | (merged C:F) | `Extension Name` | `extension_name` | string(50), nullable | Literal `N/A` in all 1,000 rows → normalize to `NULL`. |
| G | `Birthdate` | `(YYYY/MM/DD)` | `birthdate` + `birthdate_raw` | date, nullable + string(32) | All 1,000 are valid Excel dates, format `yyyy-mm-dd`. Range 1946-08-12 to 2008-04-14. |
| H | `Project Location` | `Brgy.` | `barangay` | string(100) | 11 distinct barangays (MATILAC 223, UPPER PANGANKALAN 142, BALACAYON 115, KADINGILAN 115, DATU MANTIL 101, DATU BINASING 99, PATOT 96, LOWER BAGUER 96, +3). |
| I | (merged H:K) | `City/Municipality` | `city_municipality` | string(100) | All `PAHAMUDDIN`. |
| J | (merged H:K) | `Province` | `province` | string(100) | All `SGA-BARMM`. |
| K | (merged H:K) | `District` | `district` | string(100) | All `LONE`. |
| L | `Type of ID` | — | `id_type` | string(50) | 15 distinct. Top: NATIONAL ID 712, VOTER'S ID 148, DRIVER'S LICENSE 65, VOTER'S CERTIFICATE 28, PASSPORT ID 10, BARANGAY ID 9, BIR ID 7, PHILHEALTH ID 6. |
| M | `ID No.` | — | `source_id_no` | string(64) | 961 distinct values; **39 values duplicated** across two rows each (e.g. `M04-06-014791`). Do not treat as unique. |
| N | `Contact No.` | — | `contact_no` + `contact_no_raw` | string(20) + string(32) | Mixed types: 979 integers, 21 strings. Integers lost leading zeros (`955995474` = 9 digits). String variants include `09554262460`, `0936-244-1170`, 8-digit and 16-digit anomalies. **Heavily shared**: `9756769639` appears 71×, `955995474` 61×, `9977814177` 48× → contact is not a reliable identity key. |
| O | `E-payment/Account No.` | — | `epayment_account_no` | string(100) | All 1,000 = `Money Remittance` (not actually an account number). |
| P | `Type of Beneficiary` | — | `beneficiary_type` | string(100) | `SELF-EMPLOYED/UNDEREMPLOYED` 996, `SELF-EMPLOYED/UNDEREMPLOYED/IP` 4. |
| Q | `Occupation` | — | `occupation` | string(100) | 7 distinct: FARMER 779, FISHERMAN 89, VENDOR 71, DRIVER 51, LABORER 7, ONLINE SELLER 2, ` DRIVER` 1 (leading space). |
| R | `Sex` | — | `sex` | string(10) | MALE 658, FEMALE 342. |
| S | `Civil Status` | — | `civil_status` | string(20) | MARRIED 885, SINGLE 81, WIDOW 26, SEPARATED 5, `MARRIED ` 3 (trailing space variant). |
| T | `Age` | — | `age_source` | unsigned tinyint, nullable | **Mixed**: 729 rows contain formula `=DATEDIF(Gn,TODAY(),"Y")`, 271 rows contain static numbers. Source-only; never used for policy. Age is computed from `birthdate` in the app. |
| U | `Average monthly income` | — | `avg_monthly_income` | decimal(10,2) | Integers 3,000–5,000. All numeric. |
| V | `Dependent` | — | `dependent_name` | string(150) | Free-text name of a person, no blanks. Not printed on card. |
| W | `Interested in wage employment or self-employment?\n(Yes/No, if Yes, please specify)` | — | `employment_interest` | string(255) | All 1,000 = `Yes` (no specifics). |
| X | `Skills Training Needed` | — | `skills_training_needed` | string(150) | All 1,000 = `AGRICULTURE CROPS PRODUCTION`. |

All 1,000 rows have every mapped field non-empty. There are no empty/blank rows inside 15–1014.

## 4. `List of Trainings` sheet

- Hidden sheet; 123 training entries grouped under 15 category headings in column B, items in column C:

  TESDA Trainings (under TWSP) — sheet title row 2; categories: Agriculture, Forestry, and Fishery; Automotive; Construction; Utilities; Heating, Ventilation, Airconditioning, and Refrigeration; Metals and Engineering; Information and Communication Technology; Electrical and Electronics; Furniture and Fixtures; Garments and Textiles; Health, Social, and Other Community Development Services; Land Transportation; Processed Food and Beverages; Wholesale and Retail Trading; Tourism (Hotel and Restaurant).

- It is a **lookup/reference list** (no beneficiary rows). Proposed use: seed a `trainings` table (category, name) and offer it as a dropdown for `skills_training_needed`.
- Match against data values case-insensitively: data has `AGRICULTURE CROPS PRODUCTION`; list has `Agriculture Crops Production`.

## 5. Data-quality findings (to handle in import)

1. 108 name/occupation/civil-status cells have leading/trailing whitespace; 95 rows affected. Trim on import; retain source values in audit metadata if needed.
2. Case variants: `WIDOW` (vs guidance text `Widowed`) — preserve as-is, only trim.
3. 3 name cells contain a replacement character `�` and 1 contains the digit `0` (data-entry artifacts). Do not silently "fix"; flag in diagnostics.
4. Contact numbers: 8/9/13/16-digit anomalies; many shared numbers (up to 71 rows). Restore a stripped leading zero when the value is 9–10 digits starting with `9`, keep the raw value, and flag anything still outside 10–11 digits.
5. 39 duplicated `ID No.` values — never use as a unique key; flag as possible duplicate candidates only (same gov ID on two rows).
6. `Age` column is unreliable (formula only in 729 rows, static otherwise).
7. No exact name+birthdate duplicates and no name-only duplicates in this file. No underage beneficiaries today (birthdate max 2008-04-14 → all ≥ 18 as of 2026-10-18). The underage workflow is still required for future imports.
8. Source `ID NUMBER` values all share the pattern `DOLE XII -SCFO-BARMM-1507-102026-######` and are unique; proposed as the primary update-matching key (operator-confirmable).

## 6. Deliberate mapping decisions

- **Two identifiers kept separate**: `source_id_number` (Excel `ID NUMBER`) vs app-generated `beneficiary_code` (`FIRST-MIDDLE-LAST-0001`). The generated code is unique, immutable after creation, and is what the ID card prints as `ID NO.`
- **`No.` preserved** as `source_row_no`; not a primary key.
- **Age never stored as authoritative**; computed from `birthdate` at display time. `age_source` retained for traceability only.
- **Extensions/whitespace**: extension `N/A` → `NULL`; all trimmed strings stored trimmed, with raw values available for import diagnostics.
- **Contact** stored normalized (digits only, leading `0` restored for 9–10-digit mobiles where unambiguous) plus a `_raw` column; never used for duplicate detection.

## 7. Unknowns / open questions

1. The brief refers to `lkjl.pdf`; only `SAMPLE ID.pdf` exists. Confirmed treated as the reference — no further action unless a different file was intended.
2. Whether `ID NUMBER` should remain the canonical update key indefinitely (assumed yes, operator-confirmable per import).
3. Province value `SGA-BARMM` differs from the sheet subtitle `MAGUINDANAO DEL SUR`; data rows are authoritative.
4. Intended physical card size is not stated; measured from the PDF (see `docs/id-card-layout.md`). Confirm before mass printing.
5. Contact-number semantics are inconsistent; no attempt will be made to "repair" numbers beyond safe normalization.

## Registration-overhaul mapping notes (2026-10-21)

- **Address.** The source workbook has separate `Brgy.`, `City/Municipality`,
  `Province` and `District` columns (no single Address column). On import, when no
  explicit Address is mapped, the importer derives `address` as
  `"BRGY, CITY/MUNICIPALITY, PROVINCE[, DISTRICT]"` (uppercased) and leaves the
  legacy columns intact. `location_code` is resolved by matching the derived
  address against the PSGC `locations.full_address`.
- **Controlled values.** Source values for Occupation, Type of ID, Beneficiary
  Signify, Type of Beneficiary and Mode of Payment are compared against
  `BeneficiaryOptions`. Values outside the standard lists (e.g. `FARMER`,
  `FISHERMAN`) are **kept as-is and flagged** with a `legacy_value` warning in the
  dry-run report; they are never silently remapped.
- **New optional columns.** `Beneficiary Signify`, `Mode of Payment`,
  `Occupation — specify (when OTHER)` and `Type of ID — specify (when OTHERS)` are
  recognized when present. `E-payment/Account No.` is still parsed for history but
  is no longer shown in the UI.
- **Age.** The source `Age` is a `TODAY()` formula and remains excluded from
  unchanged comparisons; it is stored in `age_source` for reference and hidden.
