# TIMS-BARMM — Synchronization Architecture Assessment
**Central Master / Offline Replica upgrade program — Phase 1 (read-only investigation)**
Prepared: 2026-10-30 · Status: assessment only; no code changed

---

## 1. Current architecture (verified against code, both live servers, and this week's incidents)

### 1.1 Topology and identity

| Concern | Implementation | Status |
|---|---|---|
| Modes | `INSTANCE_MODE=online/offline` (`SyncIdentity`, `RequireInstanceMode`) | ✅ solid |
| Enrollment | `POST api/sync/v1/enroll`, one-time tokens, recovery re-enroll keeps server UUID | ✅ solid |
| Authorization | `authorized_at` / `authorization_expires_at` per server, health check, reauthorize + prepare tokens, expiry blocks uploads (`EnsureOfflineAuthorization`, `SyncNowService::validateAuthorizationStep`) | ✅ solid |
| Record identity | `global_uuid` (sync identity), local `id`, `origin_server_uuid` (source of truth for ownership), `revision` + `server_revision` | ✅ solid |
| Operation identity | `operation_uuid` on `sync_operations`; idempotency receipts in `sync_changes` keyed by `change_uuid` (`writeReceipt`) | ✅ solid |

### 1.2 Upload path (offline → central)

1. Model observers → `SyncOperationRecorder` (mode-gated, suppressible via `withoutRecording` for snapshot/apply/restore paths) → durable `sync_operations` rows (`pending`, exponential-backoff `failed`, `awaiting_review`, `conflict`, `rejected`, `synced`, `superseded`).
2. `SyncRunner::upload()`: FIFO by id, **max 50/run** (`sync.upload_batch_size`), posts to `api/sync/v1/operations`.
3. Central `SyncOperationService::apply()`: revision check (`base_revision` vs current) → conflict on mismatch; duplicate detection → held (`awaiting_review` + `SyncRequest` item) when the server's review gate is on; otherwise applied and a **receipt** is written (`writeReceipt`, idempotent).
4. Per-operation results return to the offline, which marks each op `synced` / `awaiting_review` / `conflict` / `rejected` / `failed`. Lost responses re-queue safely (verified in tests and in production this week: the 200-deletion replay was idempotent).
5. Review: `SyncRequest`/`SyncRequestItem` hold duplicate/conflict/invalid records; decisions recorded in `SyncRequestDecision` (immutable history); outcomes pulled back cursor-based (`reconcileOutcomes` → `request-outcomes` endpoint).

### 1.3 Download path (central → offline)

1. **Delta**: `GET /changes?since=cursor` — central's `sync_changes` row-level change log, cursor-paged, `has_more`; applied by `LocalChangeApplier` inside a per-page transaction; checkpoint (`last_change_cursor`) persists **per page** (resumable — verified by test after mid-download interruption).
2. **Snapshot bootstrap**: `SnapshotDownloadService` — full in-scope beneficiaries + reference data (locations, **batches**, trainings), paged, progress checkpointed to disk, resumable.
3. **Ack**: `client->ack(downloadCursor, uploadRevision)` after successful processing.
4. Apply semantics: idempotent by `global_uuid`; revision-mismatch → local `sync_conflicts` row; central `created` matching a local record → duplicate conflict (no silent merge); `deleted` → local copy hard-deleted (idempotent); `archived/unarchived` → tombstone handling; unknown `beneficiary_batch_uuid` → **silently nulled** (see gap G2).

### 1.4 Conflict handling (current)

- `sync_conflicts`: `conflict_uuid`, payloads (local/remote), computed `diff`, `status` (open/resolved), `resolution`, `resolved_by/at`.
- Created on: upload revision collision (central side) and central-`created`-vs-local-match (offline side).
- Resolution: unified Conflict Resolution workspace (index + side-by-side show + resolve form) shared by central and local conflicts.
- Resolved values propagate: resolution may apply local values (merge/accept) and the next sync uploads the resolution to central.

### 1.5 UI (as of yesterday's work)

- **Offline Enroll Server page**: unified **Sync Now** (orchestrated by `SyncNowService`: connection → authorization → upload → review-outcome reconcile → audit push → delta download → ack) with per-step result panel, counts, warnings; double-submit guard + server-side cache lock. **Check for Updates / Apply Update remain independent.** Full-snapshot download rehoused as a recovery action.
- **Central sync-request review page**: per-record accept/reject with reasons, decision history.
- **Offline Servers page**: list + status, but monitoring depth is thin (see G5).

---

## 2. Verified gaps (each grounded in a production incident this week)

| # | Gap | Evidence | Spec phase |
|---|---|---|---|
| **G1** | **No reconciliation/backfill.** Records that predate enrollment or arrived via snapshot never get upload operations; batches the offline created before baseline are invisible to central. Sync is *operation-based only* — there is no "compare my state vs central's state" service. | PAHAMUDDIN 1,000 never queued (Oct 28-29); South Upi batch + members invisible to central (Oct 30) | 3 |
| **G2** | **Unknown `beneficiary_batch_uuid` silently nulled** when central applies offline creations → members land *unbatched* with no operator-visible error. | `SyncOperationService::beneficiaryFields()`; known user-raised gap | 3, 8 |
| **G3** | **Deletions are not approval-gated.** An offline `deleted` operation is applied by central immediately (idempotent no-op if unknown — safe — but destructive-without-review for known records). | Phase 4 core requirement; current `apply()` has no deletion gate | 4, 5 |
| **G4** | **Queue visibility/priority.** FIFO 50/run means a large cleanup flood delays newer work (South Upi 100 waited behind 200 deletions on Oct 30). Not incorrect — but invisible to the operator. | Oct 30 incident | 6, 7 |
| **G5** | **Monitoring is shallow and can go stale.** Heartbeat metrics land in `offline_servers.metadata` only from the legacy `SyncRunner::run()`; `SyncNowService` does **not** heartbeat (metadata was 28h stale during a live incident). No per-server sync-history view; connection status ≠ sync status. | Oct 29-30 incidents | 6 |
| **G6** | **Conflict center is not a decision center.** No deletion-approval conflicts, no batch-modification conflicts, no decision history beyond request items; conflicts of the same entity across retries can fragment (receipt always wins, but the conflict *list* doesn't coalesce). | Phase 5 | 5 |
| **G7** | **Ghost members.** Offline members of soft-deleted batches remain active and count in totals while vanishing from batch views (PAHAMUDDIN 1,000, Oct 29). No UI surfaces "members of deleted batches". | Oct 29 incident | 3, 6 |
| **G8** | **Count-based reasoning.** Admins infer state from member counts; the spec correctly demands identity/revision-based comparison. Nothing today produces a per-server "what differs" report. | All incidents | 3 |

### Capabilities that already exist and will be **reused** (no second engine)

`SyncClient` (all endpoints) · `SyncRunner` (upload/download/reconcile) · `SyncNowService` (orchestration + lock) · `SnapshotDownloadService` (resumable full refresh — the natural vehicle for Scenario A backfill) · `LocalChangeApplier` (idempotent apply + conflicts) · `IncomingBeneficiaryResolver` · `SyncRequestService`/`SyncRequestReviewService` (review unit + receipts) · `SyncOperationService` (central apply/resolve/recordDecision) · `sync_conflicts` + workspace · `SyncRequestDecision` (history pattern) · audit + `SyncEvent` · protocol/schema versioning (package updates ship both sides together).

---

## 3. Target design mapped to existing services (phases 2–8)

### Phase 2 — Central authority (refinement, no rearchitecture)
Already largely true: central validates everything, holds ambiguous records, owns revisions. Add: explicit statement in apply path docs + tests pinning "central never silently overwrites offline changes" (revision-conflict tests exist; extend to batches).

### Phase 3 — Intelligent reconciliation (new service, biggest win)
**`SyncReconciliationService` (central side, read-only report first):**
- For each enrolled server × its scope: compare beneficiary sets by `global_uuid` (+ `revision`, `origin_server_uuid`, tombstones from `sync_changes` receipts) against the server's **last acked download cursor** and **uploaded receipts** — not counts.
- Output per server: `missing_on_offline` (Scenario A), `missing_on_central` (B/G — list with origin + operation history), `identical` (C), `central_newer` (D), `offline_newer_pending` (E), `conflicted` (F), `deleted_central` (H — tombstoned, do not restore).
- **Scenario A delivery reuses `SnapshotDownloadService`** (it already pulls in-scope records with identity, revision, idempotency) — the offline either runs a full snapshot recovery or a new scope-limited "pull missing" that walks `/changes` from cursor 0 with skip-if-uuid-known logic. **No new engine.**
- **G1 backfill (offline side, explicit operator action):** `sync:queue-batch-backfill {batch}` artisan command — records durable `created` operations for batch members lacking any operation row (idempotent by `operation_uuid`; central review rules still apply). Solves PAHAMUDDIN 1,000 and South Upi properly.
- **G2 fix (needs a product decision):** when central receives a creation whose `beneficiary_batch_uuid` is unknown: (a) reject with clear per-record error [safest], (b) hold for review as a batch-mapping conflict, or (c) match an existing central batch by name+scope. Recommendation: (b) hold-for-review, consistent with duplicate handling.
- **G7:** "members of deleted batches" — reconciliation report lists them; optional UI filter on the beneficiaries page.

### Phase 4 — Deletion approval gate
- Central `SyncOperationService::apply()`: for `operation === 'deleted'` on a **known** record, instead of immediate apply → create `sync_conflicts` row (`operation: deleted`, local_payload = central current values, remote_payload = offline deletion context) + receipt status `pending_approval`; do **not** mutate the record.
- Resolution options in the conflict workspace: **Keep** (reject; record who/when; receipt rewritten to `kept` so the offline stops retrying and retains its local record) and **Approve** (apply existing archive/tombstone policy — prefer `archived` tombstone over hard delete; propagate via the normal `/changes` log to all applicable servers; audit preserved).
- Unknown-record deletions stay idempotent no-ops (already safe).
- Config flag per server (reuse the existing per-server review-flag pattern) so the gate can be enabled per installation after validation.

### Phase 5 — Conflict center as decision center
- Add to `sync_conflicts`: `operation` (created/updated/deleted/archived/batch), decision-history reuse via a `sync_conflict_decisions` table (mirror `SyncRequestDecision`), and list grouping by `entity_uuid` (coalesce retries).
- Index page: type filter chips, originating server column; show page: already has side-by-side diff — extend to batches and deletions.

### Phase 6 — Monitoring
- `SyncNowService` gains heartbeat reporting (reuse `SyncRunner::reportHeartbeat`) → fresh queue metrics on every sync.
- Offline Servers page: per-server last sync result, pending/failed/conflict counts, last upload op, last download cursor, open conflicts, **reconciliation deltas** (from Phase 3 report); distinguish Connected vs Synced explicitly. Per-server history view = filtered `sync_events` + receipts + requests.

### Phase 7 — Unified Sync Now
**Already delivered (Oct 29)**: single orchestration, per-step panel, counts, guard, independent app-update. Remaining: heartbeat + queue-depth display + show skipped counts (already in `downloadStep`).

### Phase 8 — Integrity
All existing safeguards preserved; the only schema additions are additive (conflict operation column, decisions table). No last-write-wins anywhere; deletions become reviewable; `withoutRecording` semantics unchanged.

---

## 4. Implementation order (lowest risk first, approval gates between)

| Step | Delivers | Risk | Est. | Status |
|---|---|---|---|---|
| 1 | Phase 6 heartbeat + monitoring columns | trivial | S | ✅ done (2026-10-30) |
| 2 | Phase 3 reconciliation **report** (read-only, central page + API) | low | M | ✅ done (2026-10-30) |
| 3 | Phase 5 conflict center upgrades (types, history, coalescing) | low-med | M | ✅ done (2026-10-30) — decision history + deletion/mapping panels |
| 4 | Phase 4 deletion approval gate (per-installation flag, default ON) | med — protocol touchpoint | M-L | ✅ done (2026-10-30) |
| 5 | Phase 3 G1 backfill command (offline, explicit operator action) | med | M | ✅ done (2026-10-30) — `sync:queue-batch-backfill` |
| 6 | Phase 3 G2 batch-uuid policy — DECIDED: hold for review as mapping conflict | med | S-M | ✅ done (2026-10-30) |
| 7 | Phase 9 test matrix (multi-server feature tests) | — | L, parallel | 🔶 core + multi-server propagation/scope tests done (2026-10-30); extended matrix continues with each feature |
| 8 | Deployment package + plan | low | S | ✅ package id 22 built & published (2026-10-30); plan in `DEPLOYMENT_PLAN.md` — awaiting operator execution |

Every step ships with its own tests; both sides of any protocol change move together via the existing package/`protocol_version` mechanism.

## 5. Key risks

1. **Two-sided deployment** for the deletion gate and batch-uuid policy — package versioning must gate features (`protocol_version`/`schema_version` already exist).
2. **Production data state**: central currently holds 539 curated records + known duplicate candidates; offline carries ghost members of trashed batches. The backfill must be operator-scoped per batch, never bulk-fire-and-forget.
3. **TUPAD once-per-year rule** — reconciliation must not resurrect/year-shift eligibility; tests exist (`AgeStatusTest`, registration) and will be extended.
4. **Idempotency of new flows** — every new endpoint/command must be safe under retry (operation_uuid/receipt discipline).
5. **The 50-op upload cap** — backfilling 1,000 records needs ~20 sync runs or an explicit "upload next N" affordance; make this visible in the UI.

## 6. Immediate next step

Step 1 (monitoring + heartbeat) — small, zero protocol risk, and it directly addresses the stale-metrics incident from Oct 29-30.
