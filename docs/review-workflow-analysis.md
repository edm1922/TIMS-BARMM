# Review Inbox, Sync Requests, and Conflict Resolution — Workflow Review

Status: analysis only (no code changed).
Scope: central-server review surfaces for records uploaded by offline installations.

Related code:
- `app/Http/Controllers/ReviewInboxController.php`
- `app/Http/Controllers/SyncRequestController.php`
- `app/Http/Controllers/SyncConflictController.php`
- `app/Services/Sync/SyncOperationService.php`
- `app/Services/Sync/SyncRequestService.php`
- `app/Services/Sync/SyncRequestReviewService.php`
- `app/Http/Controllers/Api/Sync/OutcomeController.php`
- `app/Services/Sync/SyncRunner.php`
- `resources/views/review-inbox/index.blade.php`, `sync-requests/*`, `sync-conflicts/*`
- Routes: `routes/web.php` (review-inbox / sync-requests / sync-conflicts), `routes/api.php` (sync v1 operations/outcomes)

---

## 1. The three surfaces at a glance

| Surface | What it is | Primary question it answers | Reads | Writes |
|---|---|---|---|---|
| **Review inbox** (`/review-inbox`) | A read-only aggregation queue | "What needs an administrator decision right now?" | Open `sync_conflicts` + `sync_request_items` in `awaiting_review` that are **not** linked to an open conflict | Nothing — every row links out to a resolver page |
| **Sync requests** (`/sync-requests`) | Per-upload review workspace, grouped by upload batch (`sync_requests`) | "What did this installation upload, and what was applied/held/rejected?" | `sync_requests` + all its `sync_request_items`, central values, duplicate matches, linked conflict diffs | Per-item approve/reject (`items/{item}/approve|reject`) |
| **Conflict resolution** (`/sync-conflicts`) | Per-conflict workspace with side-by-side diff and type-specific resolution forms | "How do I resolve this record-level conflict?" | `sync_conflicts` (+ decision history, held-record counts, batch roster names) | Resolve (`sync-conflicts/{conflict}/resolve`) |

There are effectively **three tables = three states**, plus one derived view:

```
sync_requests.status            (submitted / awaiting_review / accepted / partially_accepted / rejected / failed)
  └─ sync_request_items.status  (submitted / applied / awaiting_review / accepted / rejected / failed)
       ├─ conflict_uuid ───────► sync_conflicts.status (open / resolved)
       └─ duplicate_rule        (duplicate candidate, no conflict row)
```

---

## 2. End-to-end workflow (current)

### 2.1 Upload and first classification

```
Offline server (SyncRunner::upload)
  └─ POST /api/sync/v1/operations  { operations[] }
       └─ SyncOperationService::process()
            └─ apply() per operation, inside one transaction
                 ├─ clean result ─────────────► applied / rejected / duplicate(entity_already_exists)
                 ├─ duplicate match ──────────► duplicate result (held)
                 ├─ revision mismatch ────────► conflictOrMerge()
                 │      ├─ no overlapping fields ─► autoMerge() (applied, event only)
                 │      └─ overlapping fields ────► conflict() (SyncConflict open)
                 ├─ deletion needs approval ──► holdDeletionForApproval() (SyncConflict open)
                 └─ unknown batch UUID ───────► holdForBatchMapping() (SyncConflict open, coalesced per batch)
       └─ SyncRequestService::create() + recordResults()/applyResult()
            ├─ applied/rejected ──────────────► item status applied / rejected
            ├─ duplicate ─────────────────────► item awaiting_review, duplicate_rule set
            ├─ conflict ──────────────────────► item awaiting_review, conflict_uuid set
            └─ awaiting_review (deletion/mapping) ► item awaiting_review, conflict_uuid NOT set
       └─ writeReceipt(): the operation's latest result is stored on a "receipt"
```

The offline queue mirrors this in `SyncRunner::upload()`:
`applied → synced`, `duplicate/awaiting_review → awaiting_review`,
`conflict → conflict`, `rejected → rejected`.

### 2.2 Review inbox construction

```
Open SyncConflict rows (limit 200)
  + SyncRequestItem rows where status=awaiting_review
    AND (conflict_uuid IS NULL OR conflict_uuid NOT IN open conflicts)
  → merged entries, each with kind/category, server, detail, age, and a link:
       conflict row   → /sync-conflicts/{conflict}
       request item   → /sync-requests/{request}
```

Inbox categories are **derived from conflict operation + item flags**:

| Inbox category | Source |
|---|---|
| Deletion approval | `SyncConflict.operation = deleted` |
| Batch mapping | `SyncConflict.operation = mapping` |
| Field conflict | any other `SyncConflict.operation` |
| Duplicate candidate | `SyncRequestItem.duplicate_rule != null` |
| Other holds | any remaining `SyncRequestItem` (e.g. validation holds) |

### 2.3 Decision paths

```
(a) Revision conflict
    Request page: Accept (accept_local / merge / keep_central)
      └─ SyncRequestReviewService::approve()
           └─ SyncOperationService::resolveConflict()
           └─ item.status = accepted; receipt + request outcome written
    Conflict page: accept_local / merge / keep_central
      └─ SyncOperationService::resolveConflict()
           └─ conflict resolved + payload applied + conflict decision row
           └─ (no update of the linked SyncRequestItem, no request outcome)

(b) Deletion approval (SyncConflict operation=deleted)
    Conflict page only: keep_record / approve_deletion
      └─ resolveDeletionConflict()
           ├─ writes receipt (rejected deletion_rejected / applied deletion_approved)
           ├─ archives central record on approve
           ├─ updates the matching SyncRequestItem to rejected/accepted (if found by operation_uuid)
           └─ conflict resolved

(c) Batch mapping (SyncConflict operation=mapping)
    Conflict page only: map_batch (new or existing) / discard_batch
      └─ resolveMappingConflict()
           ├─ applies every held item for that batch through applyApproved()
           ├─ marks those items accepted + writes receipts
           └─ conflict resolved / held records rejected on discard

(d) Duplicate candidate (no conflict row)
    Request page: link (confirm same person) / update (apply submitted values)
      └─ SyncRequestReviewService::approveDuplicate()
           └─ item accepted + receipt (duplicate_confirmed or applied) + outcome

(e) Invalid / validation-held item
    Request page: Accept → applyApproved(); Reject → rejected receipt + outcome
```

### 2.4 Closing the loop with the offline server

```
Central decision
  └─ SyncRequestItem.status becomes accepted/rejected  (request path, mapping, deletion)
       └─ GET /api/sync/v1/outcomes?since=...  (OutcomeController)
            └─ SyncRunner::reconcileOutcomes()
                 ├─ accepted → local SyncOperation synced
                 └─ rejected → local SyncOperation rejected (+ message/code)
```

Revision-conflict resolutions **from the conflict workspace** do not write a
request outcome (see finding F1), so that path relies on the central delta/tombstone
rather than the review-outcome channel.

---

## 3. Case-by-case relationship matrix

| Case | Request item | Conflict row | Inbox entry | Where it can be decided | Outcome to offline |
|---|---|---|---|---|---|
| Clean apply | applied | – | – | – | upload result immediately |
| Validation rejection | rejected (`validation_failed`…) | – | not held | – | upload result (rejected) |
| Duplicate candidate | awaiting_review + `duplicate_rule` | – | Duplicate candidate | Sync Requests | request outcome |
| Revision conflict | awaiting_review + `conflict_uuid` | open, operation=updated/… | Field conflict | **Sync Requests** and **Conflict page** | only when decided via Sync Requests |
| Deletion approval | awaiting_review, **no `conflict_uuid`** | open, operation=deleted | Deletion approval **plus a second "Invalid record" row** (see F2) | Conflict page | conflict path writes item + receipt |
| Batch mapping hold | awaiting_review, **no `conflict_uuid`** | open, operation=mapping (one per batch) | Batch mapping **plus "Invalid record" row(s)** | Conflict page | mapping path applies items + receipts |

---

## 4. Findings

### F1 — Two ways to resolve a revision conflict, only one closes the request item

`SyncConflictController@resolve` → `SyncOperationService::resolveConflict()` resolves the
conflict and applies the chosen payload, but does **not** touch the linked
`SyncRequestItem`. The request item stays `awaiting_review` and no request outcome is
written. The request page path (`SyncRequestReviewService::approve()` →
`approveConflict()`) does update the item and write the receipt/outcome.

Consequences:
- Offline operation can remain `awaiting_review` after the conflict is closed (no outcome).
- The request page continues to show the item as pending.
- Once the conflict is resolved, the inbox's filter (`conflict_uuid NOT IN open conflicts`)
  starts including the still-awaiting item, but `itemEntry()` mislabels it
  ("Invalid record") because `duplicate_rule` is null — a resolved problem
  **reappears in the inbox under the wrong category**.

### F2 — Deletion/mapping holds are not linked to their request items

`SyncRequestService::applyResult()` sets `conflict_uuid` only for
`status = conflict`. Deletion and batch-mapping holds return `status = awaiting_review`
with a `conflict_uuid` in the result extras, but the item field is not populated.
So for one deletion request the data holds:
- one open conflict (Deletion approval), and
- one unlinked awaiting item (rendered by the inbox as "Invalid record").

The same admin decision therefore appears twice in the inbox, under two different
categories, and only one of the links works.

### F3 — The request page offers decision actions that are invalid for deletion/mapping items

`sync-requests/show` renders the revision-conflict action set
(`accept_local`, `merge`, `keep_central`) for every item with `conflict_uuid`.
Deletion and mapping conflicts require `keep_record` / `approve_deletion` and
`map_batch` / `discard_batch` (enforced in `SyncOperationService`), which the request
page never offers.

- "Accept record" on a deletion hold re-runs `applyApproved()` → `holdDeletionForApproval()`
  → still `awaiting_review` → request page throws an error.
- "Reject record" (`review->reject()`) can mark the item rejected without resolving the
  open conflict, leaving a stale conflict that still appears in the inbox.

### F4 — Three "pending" numbers that disagree

- `sync_requests.pending_count` (request aggregate),
- `sync_request_items` awaiting_review count,
- `sync_conflicts` open count,
- Review inbox total counts only open conflicts **plus** unlinked awaiting items.

Linked awaiting items are intentionally hidden from the inbox but still counted as
pending everywhere else. With the current live data there is no pending work
(1,490 applied items; 12 rejected items; conflicts resolved), so no visible
inconsistency, but the model guarantees one will appear whenever holds exist.

### F5 — Inbox is a router, not a decision surface

- It cannot accept/reject/resolve anything; the administrator has to jump into one of
  two different workspaces with different vocabularies.
- Both sources are capped at 200 rows each (`limit(200)`) and cannot paginate, so the
  counts can under-report on a busy central server.
- Filtering/search is client-side only; the browser does not receive the server-side
  filter state.
- Category names overlap: "Field conflict" (conflict) vs "Invalid record"/"Other holds"
  (item) are implementation vocabulary, not reviewer vocabulary.

### F6 — Naming/navigation exposes three concepts for one job

Sidebar exposes **Review inbox** only; inside it, buttons lead to **Sync Requests**
and **Conflict Resolution**. The data model names the same decision differently in
three places (`awaiting_review`, `conflict`, `pending`). "Sync request" is really an
upload submission record, not a review item; "conflict resolution" also contains
deletions and batch mappings, which are not field conflicts.

---

## 5. Simplification options

### Option A (recommended): one decision object, one queue

Make the review record the primary domain object and let conflicts/request items become
implementation details of it.

- Introduce a single **review item** model/view (could initially be a DB view or a
  dedicated table) with:
  - `type`: duplicate | field_conflict | deletion_approval | batch_mapping | validation_hold
  - `status`: pending | applied | rejected | superseded
  - links to the underlying conflict/request item, server, entity.
- `/review-inbox` becomes the **only** decision queue, with type-specific inline
  actions (or a single `/review/{item}` page).
- `sync_requests` becomes **Upload history** (read-only list of submissions and their
  per-record outcomes).
- `/sync-conflicts` becomes either a filtered view of the same queue or is retired as a
  standalone decision surface.

Benefits: one vocabulary, one set of actions, no duplicated rows, one pending count.
Costs: new abstraction + backfill; highest medium-term payoff.

### Option B: keep the two tables, unify the resolver

- Keep `SyncRequestItem` as the queue (it already models every uploaded operation).
- Attach conflict details as a child relation / JSON payload; the conflict page becomes
  a read-only detail view.
- Delete the resolution form from the conflict workspace, or make it navigate to the
  request item.
- Fix linking (`conflict_uuid` on deletion/mapping items) so every held item has exactly
  one representation.
- Inbox links always to the request item; type-specific action panel renders the right
  choices (`keep_record`/`approve_deletion`, `map_batch`/`discard_batch`,
  `accept_local`/`merge`/`keep_central`, `link`/`update`).

Benefits: smaller change, no new table. Costs: `sync_requests` still conflates upload
batches with review queues.

### Option C (quickest, lowest risk): unify the entry point now, refactor later

1. **Fix item linking**: in `applyResult()`, copy `conflict_uuid` for
   `awaiting_review` when the result carries one (deletion/mapping holds).
2. **One resolver**: make the inbox/detail always resolve through
   `SyncRequestReviewService` (so the request item + receipt + outcome are always
   written), and have conflict resolution call that same service; update the linked
   item and receipt in `resolveConflict()` for revision conflicts too.
3. **Remove invalid controls**: hide the approve/reject form on the request page for
   items whose conflict type is deletion or mapping, and link to the conflict detail
   instead (or better, to a single review detail).
4. **Single pending count**: derive inbox total and "pending" badges from one query
   (open conflicts ∪ awaiting items, deduplicated by linked conflict), with pagination.
5. **Rename for reviewers**: sidebar "Review inbox" + "Upload history"; keep
   "Conflicts" as a filtered insight, or label it "Conflicts & mappings".
6. **Add a type column/pill** to the queue so the reviewer sees "Deletion approval" /
   "Batch mapping" / "Duplicate" / "Field conflict" in one consistent vocabulary.

This closes the correctness issues (duplicate rows, dead-end actions, stale pending
states) without a schema rewrite, and prepares the ground for Option A/B.

### Suggested target state machine

| Current states | Simplified state |
|---|---|
| request submitted / awaiting_review; item submitted / awaiting_review; conflict open | **Pending** |
| item applied / accepted; conflict resolved (applied) | **Applied** |
| item rejected; conflict resolved (keep central / discard) | **Rejected** |
| receipt replay / superseded copy | **Superseded** (read-only history) |

---

## 6. Decision questions for the product owner

1. Should the Review Inbox itself be actionable (accept/reject inline), or remain a
   router to a single detail page?
2. Do administrators need a separate "Sync requests" page at all, or is a read-only
   upload history enough?
3. Should "Conflict Resolution" remain a standalone workspace for field-level diffs, or
   become a detail view inside the inbox?
4. Which pending number should appear in the sidebar badge (all pending items vs
   conflicts only)?
5. Keep the current historical rows untouched? (Recommended: yes; simplify the queue
   logic and labelling first, migrate only if Option A is chosen.)

---

## 7. Option C — implemented changes

The low-risk consolidation described as Option C has been applied:

1. **Held items are linked to their conflict.** `SyncRequestService::applyResult()`
   now persists `conflict_uuid` for `awaiting_review` results, so deletion approvals
   and batch mappings are represented by the conflict entry only. The inbox no longer
   shows a second "Invalid record" row for the same decision.
2. **Conflict-workspace resolutions settle the review item.**
   `SyncOperationService::resolveConflict()` now updates the linked awaiting
   `SyncRequestItem` (accepted unless `keep_central`), refreshes the request, and
   writes the operation receipt. The offline installation therefore receives the
   decision through the normal outcome channel on its next pull, and the inbox
   clears instead of resurrecting the item.
3. **Invalid request-page actions are blocked and hidden.** Deletion approvals and
   batch mappings can only be decided in the conflict workspace; the request page
   shows an explanatory link to the linked conflict instead of accept/reject forms,
   and `SyncRequestReviewService` refuses those actions server-side.
4. **The inbox is paginated and server-filtered.** Category cards link to
   `?kind=…`, search is a server-side `q` filter, the queue is paginated 25 per page,
   and the counts come from one merged source. The two old 200-row caps are gone.
5. **Reviewer vocabulary is consistent.** Inbox buttons now read **Upload history**
   and **Conflicts & mappings**, and every queue row carries a single kind pill
   (Deletion approval / Batch mapping / Duplicate candidate / Field conflict /
   Invalid record).
6. **One pending count.** The inbox header, summary card and queue badge all derive
   from the same deduplicated merged collection.

Tests added for: deletion hold linkage + single inbox entry, mapping hold linkage,
conflict-workspace settlement (item + receipt + empty inbox), request-page refusal,
and server-side pagination/filtering.

Option A (single review domain) and Option B (request item as sole queue) remain open
architecture decisions.

---

## 8. Option A — implemented changes

The review workflow has been consolidated into one review domain.

**One queue.** `ReviewQueueService` is now the single source of pending decisions.
It aggregates open conflicts and held upload items into one typed queue
(`deletion_approval`, `batch_mapping`, `field_conflict`, `duplicate_candidate`,
`invalid_record`) with one status vocabulary (`pending`). The Review inbox reads
only from this service; the sidebar badge, summary cards, category cards and queue
all derive from the same merged collection.

**One decision page.** `/review-inbox/{reviewItem}` renders the type-specific
context (central vs submitted record, deletion request, batch mapping roster, duplicate
comparison, validation failure) and the type-appropriate actions, then delegates to
`ReviewQueueService::decide()`:

| Type | Actions |
|---|---|
| Deletion approval | keep_record / approve_deletion |
| Batch mapping | map onto an existing batch / create a new batch / discard |
| Field conflict | accept local / merge selected fields / keep central |
| Duplicate candidate | confirm same person / update existing / reject |
| Invalid record | accept / reject (reason required) |

The page links back to the raw conflict and the upload batch for full history.

**History surfaces are read-only.**
- `/sync-conflicts` keeps the side-by-side comparison, conflict decision history and
  links open decisions to the unified page instead of showing a second resolution form.
- `/sync-requests` (Upload history) lists each uploaded batch and its per-record
  outcomes; records awaiting a decision link to the unified page instead of showing
  accept/reject forms.

**Compatibility.** The legacy `sync-conflicts.resolve` and
`sync-requests.items.approve|reject` endpoints remain functional for existing
automation and tests, but they are no longer exposed as decision surfaces in the UI.
All decisions made through the unified page settle the underlying conflict, review
item, receipt and offline outcome exactly as before.

Tests: `ReviewItemDecisionTest` covers every decision type, stale/unknown keys, the
admin-only rule, mapping batch options and inbox link generation; the Option C tests
continue to cover hold linkage and settlement.
