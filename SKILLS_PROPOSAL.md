# Skill Collection Proposal — APPROVED & INSTALLED

**Status:** all 7 skills installed 2026-10-30 · location `C:\Users\Edron Maguale\.dsh\skills\` · discovered by the DSH catalog and load-verified.

**Environment** — DSH desktop `0.2.1-rc.3` · Node `24.21.0` · `DSH_HOME=C:\Users\Edron Maguale\.dsh`
**Skill format** — Agent Skills standard: folder + `SKILL.md` with YAML frontmatter (`name`, `description`), progressive loading.
**Install location (proposed)** — `C:\Users\Edron Maguale\.dsh\skills\<skill-name>\` — DSH rank-400 user root (verified in DSH docs; `~/.agents/skills` rank 500 also supported).
**Existing skills (no overlap intended)** — find-skills, graphify, office/*, dsh-chat-import-*, diagnose-windows-sandbox-acl.

Source of discovery: [skills.sh](https://www.skills.sh/) registry, [LinklyAI/best-skills](https://github.com/LinklyAI/best-skills) (daily Top-100 cross-ecosystem ranking), [agentskills.io](https://agentskills.io) format.

## Proposed installs (6 capabilities → 7 skills)

| # | Capability | Skill | Source (stars · last push · license) | Body size | When loaded |
|---|---|---|---|---|---|
| 1 | UI/UX Design | `frontend-design` | anthropics/skills · 185k ★ · 2026-10-28 · Apache-2.0 | ~2.3k tok | interface work / redesign |
| 2 | Architecture & Decisions | `codebase-design` | mattpocock/skills · 307k ★ · 2026-10-29 · MIT | ~1.6k tok | new feature / module design |
| 3 | Code Review & Refactoring | `code-review` | mattpocock/skills · 307k ★ · 2026-10-29 · MIT | ~1.7k tok | reviewing changes |
| 4a | Testing | `tdd` | mattpocock/skills · 307k ★ · MIT | ~0.9k tok | test-first work |
| 4b | Debugging | `systematic-debugging` | obra/superpowers · 309k ★ · 2026-10-29 · MIT | ~2.4k tok | bug investigation |
| 5 | Context & Token Optimization | `token-optimization` **(custom)** | — | ~0.7k tok | long sessions / token pressure |
| 6 | Database Design | `database-design` **(custom)** | — | ~0.9k tok | schema / migration work |

### Why these

- **frontend-design** — #1 on the cross-registry Best-100 (1.05M installs); explicit anti-generic-design guidance (typography, motion, restraint) that directly serves your UI-redesign pattern (e.g. the Review Inbox task).
- **codebase-design** — deep-module vocabulary (module/interface/depth/seam/leverage/locality), deletion test, testability rules; standalone; referenced by `tdd` so the two reinforce each other.
- **code-review** — two-axis review (Standards + Spec) with a Fowler code-smell baseline; refactoring guidance is built in; degrades gracefully without an issue tracker.
- **tdd** — the leanest serious TDD skill found (0.9k vs 2.4k for alternatives): seams, anti-patterns (tautological tests, horizontal slicing), vertical-slice loop.
- **systematic-debugging** — canonical 4-phase root-cause process from the most-adopted skills methodology (309k ★, official marketplaces); references load on demand.
- **token-optimization (custom)** — no maintained generic skill fits DSH specifically; the 18k★ context-engineering collection's `context-optimization` is ~4k tok and harness-agnostic. A ~0.7k custom skill encodes **your** rules (progressive loading, Graphify-first, targeted retrieval, no redundant subagents) + DSH specifics (skill catalog, session_search).
- **database-design (custom)** — registry database skills are all platform-specific (Supabase/Postgres/Neon/Firebase/Mongo); this project is **Laravel 12 + MariaDB**. Custom content: migration discipline, indexing for the sync tables, DDL-rights caveat (app user lacks DDL → root path), backup-before-change, N+1/query-plan habits.

## Rejected candidates & reasons

| Candidate | Reason |
|---|---|
| `web-design-guidelines` (vercel-labs) | Overlaps frontend-design; React/Vercel-oriented; accessibility rules are already covered by frontend-design's quality floor. Optional later if you want a UI audit pass. |
| `vercel-react-best-practices`, `react-native`, view-transitions | React-specific; project is Laravel/Blade/Bootstrap — not applicable. |
| `supabase-postgres-best-practices` | Postgres-only; project runs MariaDB/MySQL — guidance would mislead. |
| `clouseryan/agentic-skills` (database, architect) | 0 ★, last push 2026-03-31 — unmaintained, unreviewed. |
| `VictorGjn/agent-skills` context-engineering | 1 ★ — unreviewed source. |
| `improve-codebase-architecture` (mattpocock) | Depends on GLOSSARY.md/ADR conventions this project doesn't use; generates heavy HTML reports; superseded by `codebase-design`. |
| `ui-ux-pro-max-skill`, `ui-taste` | Trending but less-audited; duplicate UI/UX capability — one best skill per capability. |
| superpowers full collection (worktrees, subagent-driven-dev, etc.) | Overlaps your DSH Agent Teams workflow; unnecessary token/methodological overhead. |
| **Minimum:** `handoff` (235 tok) and `grilling` (516 tok, requirements interrogation) | Useful but optional add-ons; not installed by default per "no unnecessary skills". |

## Token strategy

- Catalog overhead: only the ~25–45 tok `description` per skill is always present (~250 tok total).
- Bodies load **only on demand**; combined worst case for one task ≈ 2–2.5k tok.
- Custom skills are deliberately ≤1k tok each.
- No AGENTS.md changes; no duplicated overlapping skills.

## Install mechanics (after approval)

1. Create `~/.dsh/skills/<name>/` per skill; copy `SKILL.md` + sibling references + license file from the source repos (raw fetch via `Invoke-WebRequest`).
2. Verify each skill appears in the DSH catalog and loads via the `skill` tool (a load test per skill).
3. Write the two custom skills.
4. Report installed versions/locations + final catalog.

**Nothing is installed until you approve.**
