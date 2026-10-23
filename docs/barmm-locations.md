# BARMM Location Reference (PSGC)

The beneficiary registration form uses a single searchable **Barangay** combobox backed by an
offline snapshot of the Philippine Standard Geographic Code (PSGC). Selecting a barangay
auto-fills **City / Municipality** and **Province** and stores both the formatted
`address` text and the 10-digit `location_code` (PSGC).

## Source

| Field | Value |
| --- | --- |
| Source | Philippine Statistics Authority (PSA) PSGC |
| Release | 2025 2nd Quarter |
| Dataset mirror | `https://github.com/jobuntux/psgc` (`data/2025-2Q/*.json`) |
| Region | BARMM (`150000000` / `19*`) |
| Retrieved | 2026-10-21 |

The official `classification.psa.gov.ph` API requires an access token that was not
available; `psgc.gitlab.io` is out of date (it predates the Maguindanao province split),
so the community mirror above was used and verified against known BARMM rows.

## Snapshot

- File: `database/seeders/data/barmm-locations.json` (~1.05 MB).
- Contents: **2,595 barangays** across the BARMM provinces and the Special Geographic
  Area (SGA).
- Meta block: source, release, source URL, dataset mirror, `retrieved_at`, region, counts.

### Labelling rules

- `provCode 999` → province label **`SPECIAL GEOGRAPHIC AREA`**.
- `"City of X"` → **`X CITY`**; all names are uppercased.
- Cotabato City is kept under **`MAGUINDANAO DEL NORTE`** (PSA hierarchy), even though it is
  geographically separate.
- Full address convention: **`"BARANGAY, CITY/MUNICIPALITY, PROVINCE"`**.

## Schema

`app/Models/Location` → table `locations`:

| Column | Type | Notes |
| --- | --- | --- |
| `psgc_code` | char(10) | unique |
| `barangay` | string | |
| `city_municipality` | string | |
| `province` | string | |
| `full_address` | string(500) | `BARANGAY, CITY/MUNICIPALITY, PROVINCE` |
| `is_city` | boolean | |
| `created_at` / `updated_at` | timestamps | |

`Location::scopeSearch($term, $limit = 20)` matches `barangay`, `city_municipality` and
`full_address` case-insensitively (`UPPER(...) LIKE`), ordering starts-with matches first.

## Seeding

`BarmmLocationSeeder` streams the JSON snapshot and `upsert`s in chunks of 1,000 rows. It is
chained from `DatabaseSeeder` after `AdminUserSeeder`, so `php artisan db:seed` (or
`migrate --seed`) populates locations.

```powershell
$env:PATH="C:\xampp\php;$env:PATH"; php artisan db:seed --class=BarmmLocationSeeder
```

## Beneficiary integration

- `beneficiaries.address` (varchar 255) — the formatted text shown on the profile and ID card.
- `beneficiaries.location_code` (char(10), indexed) — the chosen PSGC code.
- Legacy columns `barangay`, `city_municipality`, `province`, `district` are **retained**
  (hidden in the UI) for history/import and are kept in sync from the chosen location.
- Import derives `address` from legacy parts when no Address column is present, and resolves
  `location_code` by matching `full_address`.
- A POST to `/locations/search` (`locations.search`, auth-guarded) returns up to 20 JSON
  results for the combobox.

## Limitations

- The snapshot is a point-in-time copy; re-run the seeder after PSA releases to refresh.
- Non-BARMM barangays are intentionally excluded; legacy addresses outside the list are
  preserved and flagged (never remapped).
