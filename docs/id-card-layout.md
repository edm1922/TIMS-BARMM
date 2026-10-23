# ID Card Layout — measured from `FRONT.pdf` and `BACK.pdf`

> Phase 1 inspection, updated after the project owner uploaded dedicated per-side files.
>
> **Authoritative sources:** `FRONT.pdf` (front face) and `BACK.pdf` (back face). `SAMPLE ID.pdf` is the earlier historical sample showing both faces side by side on one sheet; its measurements are consistent (exactly ½ scale) and are retained only for provenance.
>
> All three files contain **no vector text or vector logos** — each face is a raster image. Text below was recovered by OCR (220–600 DPI) and coordinates verified against the PDF drawing layers. Redaction overlays present in the files are noted but are not part of the design.

## 1. Document & card dimensions

| Item | Measured |
|---|---|
| Page (`FRONT.pdf`, `BACK.pdf`, `SAMPLE ID.pdf`) | US Letter, 612 × 792 pt (215.9 × 279.4 mm), portrait, one face per file/page (SAMPLE: both faces on one page) |
| Front frame in `FRONT.pdf` (100%) | (24.1, 25.3) → (587.9, 765.4) pt = **563.8 × 740.1 pt = 198.9 × 261.1 mm** |
| Back frame in `BACK.pdf` (100%) | (27.8, 24.1) → (591.6, 761.2) pt = **563.8 × 737.1 pt = 198.9 × 260.1 mm** |
| Border | 1.56 pt at 100% (0.78 pt / ≈0.27 mm at print scale) solid black |
| Relationship | `FRONT/BACK.pdf` = the `SAMPLE ID.pdf` artwork at exactly **2×** (281.9×370.0 pt → 563.8×740.1 pt); all element coordinates below are verified identical after scaling |

**Confirmed print decisions (project owner, 2026-10-18):**

1. **Print size: 50%** of the `FRONT/BACK.pdf` frames → **99.4 × 130.5 mm** (front) and **99.4 × 130.0 mm** (back), standardised to **99.4 × 130.2 mm** for both faces (the 0.5 mm difference is a raster/rounding artifact).
2. **Arrangement: duplex** — front on page 1, back on page 2 at identical dimensions, so manual/duplex printing lines up; batch printing repeats (front, back) pairs.

Redaction overlays (front: **red bar** at 100% (154.0, 465.5)→(573.5, 494.0) covering the ID-number value; back: **5 white boxes** covering birthdate, address and emergency-contact values) are privacy redactions of sample data, **not design elements** — never reproduce them.

## 2. Front face (card-local coordinates, origin = top-left of card, points)

Coordinates below are at **print scale** (50% of `FRONT.pdf`, i.e. the 99.4 × 130.5 mm card); the same values ×2 give the 100% positions inside `FRONT.pdf` (verified by OCR of both files). Convert to mm by ×0.352778.

| Element | Text (verbatim) | Local x, y (pt) | Approx size/notes |
|---|---|---|---|
| Header logo band | TUPAD / DOLE / Bagong Pilipinas logos | 0 – 100 y | raster; recreate as cropped image from source PDF |
| Government header | `DEPARTMENT OF LABOR` / `AND EMPLOYMENT` / `BAGONG PILIPINAS` | 215.4, 52.4 / 218.4, 55.1 / 244.4, 53.9 | right aligned block, ~4–5 pt caps |
| Program label | `TRABAHO AGAD` | 150.4, 63.8 | ~7 pt |
| Program title | `Tulong Panghanapbuhay sa` / `Ating Disadvantaged Workers` | 40.8, 69.2 / 40.3, 79.8 | ~10–11 pt, bold |
| Agency short name | `DOLE` | 170.2, 84.4 | part of header graphic |
| Photo placeholder | `1x1 Picture` | text box 136.8→177.3 x, 154.7→177.5 y | 1×1 inch (25.4 × 25.4 mm) photo area centred at ≈ (157, 166) |
| ID number label | `ID NO.` (rendered `ID NO` by OCR) | 32.8, 224.5 | label only; value area to its right (redacted in `FRONT.pdf`) |
| Name line | caption `FIRST, MIDDLE, LAST NAME` | 115.0, 279.2 | caption marks where the beneficiary name is printed; sample has no real name shown |
| Signature line | `SIGNATURE` | 140.5, 325.9 | line above caption |
| Issue date | `Issued: 07/28/2026` | 41.9, 345.5 | ~7.5 pt, bottom-left |
| Expiry note | `Expires: 3 months after implementation` | 41.8, 354.0 | ~7.5 pt, static text |

## 3. Back face (card-local coordinates, origin = top-left of back card, points)

Print-scale coordinates as above (×2 for 100% positions in `BACK.pdf`).

| Element | Text (verbatim) | Local x, y (pt) | Notes |
|---|---|---|---|
| Birthdate label | `Birthdate:` | 10.3, 22.4 | value printed to the right; redacted white box in `BACK.pdf` (58.7→235.6, 17.2→31.5) |
| Address label | `Address:` | 10.0, 34.9 | value to the right; redacted (57.5→234.4, 30.1→44.4) |
| Emergency heading | `In case of emergency, please notify:` | 37.0, 90.7 | ~9 pt |
| Emergency name | `Name:` | 10.0, 106.4 | **value line left blank**; redacted (49.5→222.0, 102.1→118.9) |
| Emergency address | `Address:` | 10.2, 131.3 | **value line left blank**; redacted (54.4→230.1, 127.0→141.9) |
| Emergency contact | `Contact no.:` | 10.1, 155.8 | **value line left blank**; redacted (76.7→219.5, 149.9→165.5) |
| Reminders title | `REMINDERS` | 103.7, 212.0 | bold, ~8 pt |
| Reminder text | `This card is non-transferrable. In case of loss, please` / `notify the Department of Labor and Employment Regional` / `Office.` | 42.5, 225.0 / 32.8, 233.4 / 121.4, 241.9 | ~7 pt, centered |
| Signature 1 | `IMELDA GATINAO` / `Regional Director` | 73.6, 276.8 / 108.1, 287.9 | pre-printed official; name over title |
| Signature 2 | `FRANCIS S. TOLENTINO` / `Secretary` | 55.7, 328.3 / 116.5, 338.9 | pre-printed official; name over title |

The back face has no logo/header band; it begins with the birthdate block at the top-left.

## 4. Cards' printed content policy

- Front `ID NO.` prints the **application-generated beneficiary code** (e.g. `JUAN-DELA-CRUZ-0001`). The source DOLE `ID NUMBER` is too long for this box and is not printed.
- Front name prints the beneficiary's name in **first, middle, last** order with extension if present (matches the caption order).
- Back prints **birthdate** (`YYYY-MM-DD`) and **address** assembled from source project-location fields: `Barangay, City/Municipality, Province, District` (in that order; omit blanks).
- **Emergency contact name, address, and number are always blank on the printed card** — labels/lines only; never populated from the database, even though `dependent_name` is stored.
- `Issued:` uses the beneficiary record's creation/issue date (see open question 3). `Expires:` is static sample text.

## 5. Typography (approximate)

Because the sample is rasterized, exact font families are unrecoverable. Implementation:

- Family: `Arial, Helvetica, sans-serif` (matches the sample's sans-serif look).
- Sizes derived from OCR line heights: header ~4–5 pt, program title ~10–11 pt bold, captions/labels ~7–9 pt, reminders ~7 pt.
- Weights: program title and `REMINDERS` bold; everything else regular; labels kept alongside their values.
- All text black on white; no fills except the header band image.

## 6. Header band asset

Crop the top 200 pt of the front face from **`FRONT.pdf`** at 600 DPI — page coordinates `(24.1, 25.3) → (587.9, 225.3)` (= 0–100 pt local at 100%) — and save as the card header image. This preserves the TUPAD/DOLE/Bagong Pilipinas logos exactly without inventing artwork. The redaction bar (100% y 465–494) is far below the crop and is not included.

## 7. Printing implementation

- Blade partials `id-cards/front.blade.php` and `id-cards/back.blade.php`, sized in mm at **99.4 × 130.2 mm** (the confirmed 50% of the `FRONT/BACK.pdf` frames), `box-sizing: border-box`, 0.27 mm black border.
- Print CSS: `@page { size: A4 portrait; margin: 0 }`, card positioned at top-left, `print-color-adjust: exact`, all navigation/buttons/tables hidden.
- **Single card:** front on page 1, back on page 2, identical dimensions so manual duplex alignment works. The user prints at **100% / actual size** in the browser print dialog (the CSS already renders the card at 50% of the source artwork): margins none, headers/footers off, background graphics on.
- **Batch:** consecutive (front, back) pages per beneficiary, one card pair per sheet, so duplex printing produces correctly ordered cards. A 2-up cut layout (as in `SAMPLE ID.pdf`) is not offered unless later requested.
- Preview shows front and back above the print button; no card is split across pages (`page-break-after: always` between faces).
- Photo: 25.4 mm square frame at the measured position; uploaded photos are center-cropped to cover; when absent, show a light "1x1 Picture" placeholder only in preview (blank on final print unless the owner wants the placeholder printed).
- Photos/signatures are served through authorization-checked controller routes from the private disk.

## 8. Implementation assumptions / open questions

1. Reading order of the front is assumed: header band, photo, ID number, name, signature, issued/expires.
2. `Issued:` date source is unconfirmed. Default: the beneficiary record's creation date (`created_at`), admin-editable later if needed. This may justify an `id_issued_at` column in Phase 5.
3. Exact place/width of the photo frame and name line are approximated from the placeholder text positions (the raster has no vector frame). Adjust after the first print proof.
4. Official names/titles on the back are treated as fixed design text copied from the files; confirm whether they should track current officials.
5. Browser print at actual size must be verified with a physical proof; Chrome/Edge is the reference browser.

Resolved: card size (99.4 × 130.5 mm, confirmed 50%) and front/back arrangement (duplex front page → back page, confirmed).

## Address on the card (2026-10-21)

- The card prints the beneficiary's `display_address` — the unified
  `"BARANGAY, CITY/MUNICIPALITY, PROVINCE"` text (falls back to legacy parts when
  no unified address exists).
- The address is rendered as a **single line** and truncated with an ellipsis via
  `.val-addr` (`max-width: 210pt; overflow: hidden; text-overflow: ellipsis`).
