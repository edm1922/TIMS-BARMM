# TIMS-BARMM — Deployment Plan: Central Master / Offline Replica Upgrade
**Prepared: 2026-10-30 · Deploys the sync upgrade (assessment rounds 1–7) to the central and offline servers**

---

## 0. What ships in this package

| Area | Change | Files (summary) |
|---|---|---|
| Monitoring | Sync Now heartbeat; richer Offline Servers queue columns; per-server open conflicts | `SyncNowService`, `SyncRunner`, `HeartbeatController`, `SyncServerController` + views |
| Reconciliation | Per-batch Central-vs-Installation report on the server page | `SyncReconciliationService`, `SyncServerController`, view |
| Deletion approval | Offline deletions of known records held for approval; Keep/Approve decisions; archive tombstone propagation | `SyncOperationService`, `SyncConflictController`, views |
| Batch mapping | Unknown-batch creations held as mapping conflicts; map-to-new / map-to-existing / discard | `SyncOperationService`, views |
| Backfill | `sync:queue-batch-backfill` command (South Upi first) | new command |
| Conflict history | Immutable `sync_conflict_decisions` trail + show-page section | migration, model, views |
| Sync Now UX | Single Sync Now, result panel, double-submit guard, recovery rehousing | `beneficiary-dup-modal` era JS + views |
| Bug fixes shipped en route | `catch ()` syntax error (age/draft), missing `RuntimeException` import (import 500s), heartbeat `awaiting_review` dropped, 419 conflict-view form, unknown-batch silent nulling | various |

**Migrations included (additive only, no destructive changes):**
`2026_10_30_111500_add_operation_to_sync_conflicts_table`, `2026_10_30_121500_create_sync_conflict_decisions_table`.

**Compatibility rule:** both servers receive the same package. The offline applies it through the existing **Check for Updates → Apply Update** flow (maintenance mode, migrations, automatic backup, rollback on failure — all built into the updater).

---

## 1. Pre-deployment (offline server)

1. **Stop encoding sessions** — no encoders should be entering data during the update window.
2. **Database backup** (on the offline server):
   ```powershell
   cd C:\xampp\htdocs\TIMS-BARMM
   C:\xampp\php\php.exe artisan tims:backup
   ```
   Verify a new backup file exists under the backup location before continuing.
3. **Confirm the queue is drained or acceptable**: Dashboard → note the "Pending uploads" figure. Any pending operations are preserved by the update; they upload after reconnect.
4. **Note the current app version** shown on the Enroll Server page (rollback reference).

## 2. Pre-deployment (central server)

1. **Database backup** (this machine):
   ```powershell
   cd C:\xampp\htdocs\TIMS-BARMM
   C:\xampp\php\php.exe artisan tims:backup
   ```
2. The central side needs the same code. Deploy by the same update mechanism or by copying the changed files listed in `SYNC_UPGRADE_ASSESSMENT.md` §0 (central-only files: controllers/services/views under `app/Services/Sync`, `app/Http/Controllers`, `resources/views/sync-servers`, `resources/views/sync-conflicts`, `resources/views/sync/local`, `routes/web.php`, migrations) and running:
   ```powershell
   C:\xampp\php\php.exe artisan migrate --force
   C:\xampp\php\php.exe artisan view:clear
   ```
   > The application DB user lacks DDL rights on this host; run the two migrations as the MySQL root user if `migrate` is denied (the statements are additive: one table + two columns). Mark them in `migrations` afterwards.
3. **Sanity check the central side**: open Offline Servers → open the active installation → the **Reconciliation** card renders (roster arrives after the offline's first post-update sync).

## 3. Deployment (offline server)

1. Enroll Server page → **Check for Updates** → the package `Sync upgrade 2026-10-30 …` appears as *Available*.
2. **Apply Update** — the updater enables maintenance mode, runs the two additive migrations, and restarts the service. Do not close the page; the progress bar reports completion.
3. After completion the page reloads automatically. Verify:
   - Enroll Server page renders with the single **Sync Now** button and the recovery section tucked away.
   - **Sync Now** → result panel shows all steps OK; the central Offline Servers page shows a **fresh "Reported" timestamp**.
   - **Reconciliation** card on the central server now lists the installation's batches with verdicts.

## 4. Post-deployment tasks (operator, one time)

1. **South Upi backfill** (per the agreed scope):
   ```powershell
   C:\xampp\php\php.exe artisan sync:queue-batch-backfill "South Upi" --dry-run   # preview
   C:\xampp\php\php.exe artisan sync:queue-batch-backfill "South Upi"             # queue
   ```
2. **Sync Now ×3** (50 operations per run) → the batch + its 100 members arrive on central. If they surface under the mapping conflict (batch unknown to central), resolve it via **Create this batch on the central server**.
3. **Deletion approvals**: from now on, offline deletions appear in the central **Conflict Resolution** page as *Deletion request* entries. Decide each: **Keep the beneficiary** or **Approve deletion** (archive tombstone).

## 5. Rollback

- **Offline**: the updater keeps its own pre-update backup and restores automatically on failure. Manual rollback: restore the pre-deploy database backup from step 1.2, then reinstall the previous package (or copy the previous code + `migrate:rollback --step` for the two additive migrations — both are safe to reverse; the decisions table would lose history recorded since).
- **Central**: restore the pre-deploy database backup, restore the code files from Git (`git checkout <pre-deploy-commit> -- app/ resources/ routes/ database/migrations/`), `php artisan view:clear`. The two additive structures are harmless if left in place, but the rollback backup restores their absence exactly.
- **Protocol safety during mixed versions**: the additions are backward-tolerant by design (new receipt status falls back to retryable failure on old offlines; heartbeat roster and mapping fields are additive). Still, plan the central + offline updates within the same window.

## 6. Verification checklist (both servers, post-deploy)

- [ ] Offline: Sync Now completes with all steps OK
- [ ] Central: Offline Servers → fresh reported timestamp, queue numbers
- [ ] Central: Reconciliation card lists batches with verdicts
- [ ] Offline: South Upi backfill run, members visible on central (batch mapped)
- [ ] First deletion-approval cycle exercised (hold → decide → propagation)
- [ ] No pending operations left unintentionally; conflicts list matches expectations

---

**Deployment itself is an explicit operator action.** Nothing in this package deploys automatically: the offline server updates only when its administrator presses Apply Update, and the central files are copied by the administrator per §2.
