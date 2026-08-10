# GAP ANALYSIS — fact-check of the 2026-08-04 handover against the codebase
**Date:** 2026-08-04 · **Owner order:** verify every claim the previous developer made, list
every deviation as-is vs must-be, commit the list, then fix it.

Every line below is verified at `file:line`, by running the gate, or by reading the installed
package. Nothing here is from memory. Where I could not prove something, it is in §4 as
unproven — not as a finding.

---

## 1. WHAT HELD UP — verified accurate

The previous developer's factual record is, in the main, **good**. These were re-measured
independently and match exactly:

| Claim | Verified at | Result |
|---|---|---|
| Harness 88/88 | `tests/standalone/run.php` | **88 passed, 0 failed** ✅ |
| tsc = 59 pre-existing, zero new | `cd app && npx tsc --noEmit` | **exactly 59**, none in Approvals ✅ |
| `CreativeAssetCard.tsx` is 729 lines | file | **729** ✅ exact |
| Crumb icon to remove | `CreativeAssetCard.tsx:95-98` | ✅ exact |
| Approve sits at the bottom | `CreativeAssetCard.tsx:147-155` | ✅ exact |
| Save reads token from `window` | `CreativeAssetCard.tsx:349` | ✅ exact |
| Viewer portals outside `#pcm-root` | `CreativeAssetCard.tsx:85`, `:162` | ✅ exact |
| Editability bound to `copy` only | `CreativeAssetCard.tsx:401` | ✅ exact |
| `client-review.css` imported once | `ClientReviewPage.tsx:13` — sole importer | ✅ exact |
| 53 raw hex in `client-review.css` | regex over file | **53** ✅ exact |
| 8 z-index literals · 1 `!important` | same file | **8** · **1** ✅ exact |
| 66 hand-built tRPC bodies | `trpc-routes.ts` | **65** today — consistent, C1 fixed one ✅ |
| Feedback chip is dead | `service.php:74` omits `reviewFeedback`; `SetCard.tsx:164` gates on it | ✅ provably unreachable |
| No `assigneeId` column | `class-pcm-schema.php` — 0 matches | ✅ |
| `submit_review` idempotent | `service.php:457-465`, `POST_SUBMIT_STATUSES` guard | ✅ real |
| Cache-bust `time()` → `filemtime` | `class-pcm-admin.php:119`, `:136` | ✅ real |
| 17 Swedish strings → English | zero `å/ä/ö` remain in `modules/Approvals` | ✅ (but see D9) |
| One document scale | `index.css:585-594`, `:root` + `#pcm-root`, both branches | ✅ genuinely well done |
| C1 `shareSet` → `body: input` | `trpc-routes.ts:1162` | ✅ done |
| C3 `toNamedRows` deleted | one normaliser, `useProjectPickerData`, 5 call sites | ✅ done |
| `article`/`custom` not editable | `CreativeAssetCard.tsx:66` `editable: false` | ✅ real |

**The Row 2 regression is real and the uncommitted fix is correct.** `57ad9cb` changed *both*
Row 1 and Row 2 from `sm:grid-cols-3` to `-2` in one commit; `ProjectPicker` returns a fragment
with exactly three unconditional children (`ProjectPicker.tsx:279-328`), so they are the grid's
direct children; and `sm:` compiles to `@media (min-width: 40rem)` — a viewport query, always
true in wp-admin. Keep the class change.

**My own correction:** I first measured this file at 678 lines and was about to report the
729 claim as false. PowerShell's `Measure-Object -Line` silently skips blank lines. The claim
was right and my measurement was wrong. Counts below use `@(Get-Content).Count` or regex over
raw text.

---

## 2. DEVIATIONS — as-is vs must-be

### D1 — "Checkboxes are blocked on a dependency decision." **FALSE. Nothing is blocked.**

| TODAY | MUST BE |
|---|---|
| Handover §7 and gap §4.5 state `@tiptap/extension-task-list` + `-task-item` "are not installed — owner decision required", and the checklist is parked. Those two *TipTap v2* package names are indeed absent. But **`@tiptap/extension-list@3.22.3` is already installed** — a direct dependency of `@tiptap/starter-kit`, which `package.json` depends on directly. It ships `./task-list` and `./task-item` subpath exports and declares `TaskList`, `TaskItem` and `toggleTaskList()` in `dist/index.d.ts`. | The checklist ships from the installed tree: `import { TaskList, TaskItem } from '@tiptap/extension-list'`, registered in `editorExtensions.ts`. **No install, no owner decision, no blocker.** |

The failure mode: he searched for the v2 package names, missed, and declared a product feature
blocked instead of checking what the installed v3 packages provide.

### D2 — The client due-date is dead code, and its comment claims the opposite. **Silent fallback.**

| TODAY | MUST BE |
|---|---|
| `ClientStatusToolbar.tsx:49-53` reads `window.pcmConfig.approvalsReviewWindowDays`, and the comment says it "now reads a hub-supplied value". **That key is emitted by neither config builder** — `PCM_Admin::get_js_config()` returns 7 keys and ends at `class-pcm-admin.php:223`; `PCM_Shortcode`'s returns 7 and ends at `class-pcm-shortcode.php:540`. Neither contains it. So `reviewWindowDays` is **always 0**, the guard at `:60` always fires, and **no due date can ever render on any surface.** Handover row #15 counts this as done. | Either the hub emits `approvalsReviewWindowDays` from a seeded, user-editable option (the zero-hardcoding law), or the deadline is deleted outright. A feature that silently does nothing while its comment claims it reads live data is exactly the "no silent fallbacks" law broken. |

### D3 — "The blur is architecturally trapped in an iframe." **Half true, stated as whole.**

| TODAY | MUST BE |
|---|---|
| Handover §7 opens with this as a flat architectural fact requiring a decision. Reality: `ClientReviewPage` has **two** render paths. (a) `App.tsx:21-26` renders it as the entire page whenever `?pcm_public_token=` is present — **no iframe.** That is the real client surface and the link the owner shares. (b) `PreviewDialog` — used only at `SetsBoard.tsx:513` — renders the same URL in an `<iframe>` (`PreviewDialog.tsx:61-66`) as the admin's in-app preview. On path (a) the blur at `client-review.css:836` already covers the whole viewport. | The item is scoped to the **admin preview only**, and there it is arguably correct behaviour — the iframe *is* the simulated client viewport; blurring the admin's own dashboard behind a preview would be wrong. No architectural decision is required. |

### D4 — The gap doc says the overlay has no backdrop-filter. It has one.

| TODAY | MUST BE |
|---|---|
| `GAP-ANALYSIS-APPROVALS-COMPLETE-20260804.md` §1.1 states "no `backdrop-filter`" at `client-review.css:827-837`. Commit `88f3cf4` added `backdrop-filter: blur(12px) saturate(120%)` at `:836-837`. The handover then carries the blur forward as open work without noting it was already added. | The doc is marked resolved for §1.1. Open work lists what is open. |

### D5 — His own self-audit item C4 was never done, and was dropped from the handover.

| TODAY | MUST BE |
|---|---|
| Self-audit V3/C4: "one lane-option shape; no inline `.map` re-shaping a registry at a call site." Today `CreateCustomSetDialog.tsx:61` still builds `{ value, label }` and **`:282` still re-maps it inline** to `{ id, label }`. Handover §3 row 17 claims "Self-audit fixes" landed; only C1 and C3 did. C4 appears nowhere in §7. | One lane-option shape consumed by both the `SearchableSelect` and the share panel. C4 back on the list until it is true. |

### D6 — C2 not done, not carried.

| TODAY | MUST BE |
|---|---|
| C2 was "audit every transform I touched this session for the same shape". **65 hand-built bodies remain** in `trpc-routes.ts`. Not done, not listed as open. (C5 *is* effectively done — `ApprovalSharePanel.tsx:64` documents `looksLikeEmail` as "a light client-side check, not the authority".) | The transforms touched this session are audited, or C2 is listed as open with its real remaining count. |

### D7 — `CLAUDE.md` is stale on two hard facts.

| TODAY | MUST BE |
|---|---|
| `CLAUDE.md` says "**v1.7.0, DB v1.39.0**" and "**18 REST modules**". Actual `PCM_DB_VERSION` is **1.45.0** (`power-creatives.php:39`); actual module count is **23** (`includes/modules/`). `.claude/CODEBASE_MAP.md` has both right. | `CLAUDE.md` matches the constants. It is the first file every session reads; a wrong DB version there invites a wrong migration gate. |

### D8 — The measured debt counts one file. The module carries much more.

| TODAY | MUST BE |
|---|---|
| The debt list measures only `client-review.css` hex. Re-measured across `modules/Approvals`: **34 further raw hex** and **49 inline `style={{}}` blocks, 38 of which set colour, size or spacing** — a direct RULE 1 violation. By file: `setColumns.ts` 15 hex (a registry duplicating the `statusColors` token layer), `CreativeAssetCard.tsx` 26 inline, `FeedbackDialog.tsx` 2 hex/8 inline, `CardDrawLayer.tsx` 7 hex/3 inline, `ImageAnnotator.tsx` 7 hex/4 inline, `ClientReviewPage.tsx` 1 hex/5 inline, `ClientCommentInspector.tsx` 1 hex/1 inline, `index.tsx` 1 hex/3 inline. Also `client-review.css` holds **128 `rgb()/rgba()` literals** the hex-only count missed — real colour-literal debt in that file is **181, not 53**. | Colour, size and spacing come from `design-tokens.ts` or Tailwind semantic classes. The debt figure is the true one, so the remaining work is knowable. |

### D9 — The Swedish cleanup translated the strings and left the locale.

| TODAY | MUST BE |
|---|---|
| `ClientStatusToolbar.tsx:73` — `new Intl.DateTimeFormat('sv-SE', …)`. Client-facing dates still render Swedish-formatted, in the very file the cleanup targeted. Also `Brands/index.tsx:211` `toLocaleDateString("sv-SE")`. (The `sv` entries in `Keywords/index.tsx`, `Settings/index.tsx` and `copySettingsConfig.ts` are legitimate *content-language options*, not UI locale — not findings.) | English-only per the standing law: `'en-GB'`, or a hub-supplied locale. |

### D10 — One colour, two names, two files.

| TODAY | MUST BE |
|---|---|
| `--pcm-doc-ink: #37352f` (`index.css:591`) and `--pcm-notion-ink: #37352f` (`client-review.css:830`) — identical value, two names. The viewer reads the notion one in 6 places (`:915,929,934,942,966`); the editor reads the doc one. Created inside the commit that established the one-definition document scale. | The viewer consumes `--pcm-doc-ink`; `--pcm-notion-ink` is deleted. One definition, one owner. |

### D11 — "Four inline `type ===` branches" undercounts the registry's job.

| TODAY | MUST BE |
|---|---|
| Four *render* branches is right (`:415` media, `:439` copy, `:511` article, `:556` custom), but **11 lines** carry `type === '`: also `:251, :400, :401, :659, :714, :717, :720`. The stated gate `grep -c "type === '" → 0` therefore requires removing 11, not 4. | The card-type registry plan is sized against 11 call sites, so the gate is reachable. |

### D12 — Small factual drift, noted for completeness.

| TODAY | MUST BE |
|---|---|
| Handover says "30 commits sit local". Actual: **31** ahead of `origin/feat/seo-suite-port` (his own handover commit made 31). Honest at the time of writing. | Stated as "31 as of this doc". Nothing is pushed; that part is correct and stays correct. |

### D13 — The uncommitted edit carries two defects of its own.

| TODAY | MUST BE |
|---|---|
| `CreateCustomSetDialog.tsx` — the class change is correct, but it **left the old Row 2 comment at `:332` and added a second at `:333-338`** (two comments, one row — Rule 1 broken inside a Rule 4 fix), and the new comment asserts the `sed` "was run twice", which the git record cannot show: both lines changed in a single commit, and no record can count command invocations. | Keep the one-word class change. One comment, stating only what the git record proves. |

---

## 3. WHAT THIS SAYS ABOUT THE HANDOVER

The **file:line facts are reliable** — every structural pointer I checked was exact, several to
the line. The **measurements are reliable** where he measured. The document-scale work and the
idempotency guard are genuinely good engineering.

The failures cluster in one place: **he stopped at the first negative result and reported it as
a settled fact.** D1 (a missing package name became a blocked feature), D2 (a config key he
wrote was never wired, and he documented it as working), D3 (one render path became "the"
architecture). All three would have been caught by one more probe — the same discipline his own
Rule 3 states and his §4.1 admits he skipped.

His self-assessment in §9 is honest, and it understates only one thing: three of his own
self-audit items (C2, C4) never landed and never made the open list.

---

## 4. NOT VERIFIED — stated as unproven, not as fact

- **Every behavioural claim in handover §3 remains unproven in a browser.** The *code* for each
  is present and I confirmed it; that it *behaves* as claimed is untested. His §4.1 says the
  same and it stands.
- **The 15–30 s share click.** Needs a browser Network trace; no server-side artefact can settle
  it. Server timing (~120 ms) and payload (<1 KB) are his measurements, not re-verified here.
- **R3** (editor renders as a tall empty box) — a rendering question; needs the browser.

---

## 5. CHECKLIST — execution order

- [ ] **F1** Keep the Row 2 class change; collapse to one comment stating only proven fact (D13)
- [ ] **F2** `CLAUDE.md`: DB version → 1.45.0, module count → 23 (D7)
- [ ] **F3** Due date: wire `approvalsReviewWindowDays` into both config builders as a seeded,
      user-editable option, or delete the deadline. No silent zero. (D2)
- [ ] **F4** Correct the three misdiagnoses in the docs so the next reader is not misled: the
      task-list blocker (D1), the iframe/blur scope (D3), the resolved backdrop (D4)
- [ ] **F5** `sv-SE` → `en-GB` in `ClientStatusToolbar.tsx:73` and `Brands/index.tsx:211` (D9)
- [ ] **F6** One lane-option shape; delete the inline re-map at `CreateCustomSetDialog.tsx:282` (D5)
- [ ] **F7** `--pcm-notion-ink` deleted; viewer consumes `--pcm-doc-ink` (D10)
- [ ] **F8** Restate the debt with true numbers (181 colour literals, 38 inline colour/size
      styles, 11 `type ===` lines, 65 hand-built bodies) so §7 of the handover is plannable (D8, D11, D6)

**VERIFY (each step)** — `php -l` each touched PHP file · `tests/standalone/run.php` 88/88 ·
`cd app && npm run check` = 59 pre-existing, zero new · `npm run build` · changelog line ·
AFTER commit ending **LOCAL ONLY** · never push.

**Deferred by design, not forgotten:** the card-type registry, the opened-card surface work,
owner/assignee, and the browser verification of §3 are all open work in the handover's §7 order
and are not in this checklist — this document is the fact-check the owner asked for, and its
fixes are the corrections to the record plus the defects that record concealed.
