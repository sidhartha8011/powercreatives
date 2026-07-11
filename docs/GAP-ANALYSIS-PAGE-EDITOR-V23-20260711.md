# GAP ANALYSIS — PAGE EDITOR: UX FIXES + PAGE VERSIONS + V2/V3 MERGED (2026-07-11) — AWAITING GO

**Owner order (2026-07-11, after testing V1):** spec the two UX fixes · spec a
full-page versions dropdown (like the section editor has) · full gap analysis
of V2 (AI + inline diff) and V3 (image metadata) · plan V2+V3 as ONE merged
arc · wait for approval. Every fact below verified in code or live this arc.

---

## PART 0 — OWNER UX FIXES (spec)

| # | Order | Spec |
|---|-------|------|
| U1 | "editor is too wide, make it 35% thinner" | Page-mode width 90vw → **58vw** (90 × 0.65 ≈ 58; height stays 85vh, still centered). Min-width floor 720px so narrow screens keep a usable editor. Section/insert mode untouched (440px). |
| U2 | "paragraphs are not divided well enough… all mushed together" | Page mode gets its OWN reading scale (section mode keeps the compact one, byte-identical): body text `text-sm` instead of `text-xs`, paragraph spacing `my-3` instead of `my-1`, headings step down visibly (h1 > h2 > h3) with real top margins (`mt-6/mt-5/mt-4`), and a wider content padding. Result: the document reads like the live page, not a wall. One conditional class string in SectionModal — no new component. |

## PART 1 — FULL-PAGE VERSIONS (spec, new owner order)

**What you get:** the same dropdown as the section editor, in the page
editor's header — `Original` + every previous page save with date/time and a
delete button. Picking one loads it into the editor; **Acceptera makes it
live** through the normal save (only sections that differ produce writes).

Code-verified facts that make this cheap:

| # | Fact | Evidence |
|---|------|----------|
| PV1 | The versions table already carries a `target` column (default `'section'`) — a page version is one row with `target='page'`, `matchText=''`, `occurrence=0`, `replacement` = the saved page document (contentHtml). **Zero schema change, no DB version bump.** | class-pcm-schema.php:741 |
| PV2 | Restore needs NO new save machinery: loading an old document and pressing Acceptera goes through `save_page_edits` — per-section diff, unchanged sections write nothing, sections edited back to original clean-revert their rules (proven live 2026-07-11). | service.php `save_page_edits` |
| PV3 | "Original" (the page with no optimizations at all) is the RULES-INPUT view, which the hub already fetches (`remote_fetch_snapshot mode=input`) — assembling it with the same `assemble_content_html` gives the true original document for free. | service.php:2828, 3097 |
| PV4 | Version delete is already target-agnostic (`delete_section_version` deletes by id+userId) — the existing delete endpoint works for page versions unchanged. | service.php:3842 |

The gaps (all small):

| # | Gap | Change |
|---|-----|--------|
| PV-G1 | Nothing records page versions | `save_page_edits` records one `target='page'` row after a save that changed anything (saved+inserted > 0), same laws as sections: consecutive-duplicate skip, cap 20 (constant reused) |
| PV-G2 | No page-versions read | `list_section_versions` gains a target parameter (or a thin `list_page_versions` wrapper) + the inventory/versions endpoint passes `target=page`; trpc route reuses the existing versions route with the flag |
| PV-G3 | No "Original" source | New service method: input-view snapshot → `assemble_content_html` (no attribution — original by definition); returned as `originalHtml` next to the versions list (one endpoint, one fetch) |
| PV-G4 | No dropdown in page mode | SectionModal page header gains the SAME versions dropdown UI as section mode (Original grey + dated rows + delete) — picking loads `editor.setContent`, Acceptera saves normally |

Honest edge (named): a very old page version may not align with today's page
(sections since removed) — `save_page_edits` already answers honestly
("removing whole sections isn't supported yet", nothing half-saved).

## PART 2 — V2 GAP ANALYSIS: AI OPTIMIZE + INLINE RED/GREEN DIFF

Current state (facts):

| # | Fact | Evidence |
|---|------|----------|
| A1 | Per-section AI exists and is the ONLY AI path we need: `remote_optimize_section($site, $post, $type, $html, $topic, $model, $user, $provider, $template)` — staged (returns the rewrite, saves nothing), `{{topic}}` instruction channel, kses'd HTML in/out | controller.php:549, service.php |
| A2 | No diff library in the app; none wanted (owner: senior grade, zero duplication → no dependency for ~80 lines) | package.json |
| A3 | TipTap v3 is in use (marks via `Mark.create`, decorations available); the page editor already composes its extension list conditionally (`LockedImage` proves the pattern) | SectionModal.tsx |
| A4 | The page document's sections are defined by headings — the SAME law as the server parser (`split_unit_sections`); a client-side split by heading nodes is deterministic and cheap | contracts v2.2 |
| A5 | Images are atomic locked nodes in the doc; a word-diff must treat them as opaque tokens (never inside a diff run) | V1 LockedImage |

The gaps:

| # | Gap | Change |
|---|-----|--------|
| A-G1 | No diff util | In-house word-level LCS diff (~80 lines TS, `lib/word-diff.ts`): tokenize on words+whitespace, LCS, emit equal/added/removed runs; atomic nodes (images) are opaque tokens |
| A-G2 | No diff rendering | Two TipTap marks: `diffAdded` (green background) + `diffRemoved` (red strikethrough) — presentation only, NEVER persisted: save strips both marks first (guard in the save path, not convention) |
| A-G3 | No page AI orchestration | "AI Optimize" in the page header: client splits the doc by headings (A4), fires the EXISTING `remoteOptimizeSection` per section in parallel (bounded: max 4 in flight), renders each returned rewrite as an inline diff against that section's current text. A slow/failed section fails ALONE with an honest per-section note — the rest of the page still shows its diff |
| A-G4 | No accept/reject | Per section: Accept (keep green, drop red, strip marks) / Reject (restore the section's pre-AI content) + **Accept all** in the header. Acceptera is BLOCKED while unresolved diffs remain (honest state — never save half-reviewed marks); accepted sections ride the V1 save path untouched |
| A-G5 | Ask-AI instruction channel hidden in page mode | The existing instruction input comes back in page mode and feeds `{{topic}}` on every per-section call (one instruction, whole page) |

Risks (named): heavy rewrites degrade a section's diff to "everything
changed" — per-section Accept/Reject keeps that usable (owner-accepted in the
V1 gap analysis) · AI cost/latency bounded by per-section prompts + the
4-in-flight cap.

## PART 3 — V3 GAP ANALYSIS: IMAGE METADATA (engine v2.3, connector 3.0.2)

Current state (facts):

| # | Fact | Evidence |
|---|------|----------|
| B1 | The engine has NO image target; serving passes are heading → section replace → insert → paragraph; images are untouched-by-construction (V1 strips them from every save — proven live: locked img landed in no rule) | seohub service, V1 live pass |
| B2 | The editor already holds every image WITH full attributes (src/alt/title/srcset — the powerleads avatar proved attrs survive assembly); click-to-select works on atomic nodes | V1 live pass |
| B3 | Rule pushes are capability-gated by ONE handle (`connector_rules_schema_version`, memoized); v3 gating pattern is established — a new target bumps the wire schema to 4 and gates on it | service.php:3286, 3311 |
| B4 | `rules_to_schema` already shapes per-target payloads (`section`/`heading` branches) — an `image` branch is additive | service.php:3554 |
| B5 | The harness runs the REAL extracted connector against fixtures — every engine change extends it (law #3/§6) | tests/standalone/run.php |

The gaps:

| # | Gap | Change |
|---|-----|--------|
| B-G1 | No rule target | New ADDITIVE target `image`: `matchText` = normalized `src` (entity-decoded, trimmed; query string KEPT — it is identity), `occurrence` among same-src imgs, `anchorContext` = `{alt, title}` ORIGINALS (captured from the snapshot at save — powers clean revert), `replacement` = JSON attr set `{alt, title}` |
| B-G2 | No serving pass | Connector 3.0.2: image pass runs LAST (after all content passes, so it also hits imgs inside rule output); rewrites ONLY the matched `<img>`'s alt/title attributes — never src/position/existence; src not found = rule inert + stale counted (verify-first, fail-to-original) |
| B-G3 | No hub save path | `save_image_rule` (UPSERT on src+occurrence; editing both attrs back to the stored originals = clean revert deletes the rule; capability check FIRST — connector < 3.0.2 fails honestly; push-fail rollback — the existing atomicity law verbatim) + endpoint + trpc route |
| B-G4 | No push shape | `rules_to_schema` image branch + `push_rules` schemaVersion 4 gating (`needs_v4` when image targets present) — posts without image rules keep pushing v3/v2/v1 byte-identical |
| B-G5 | No editor surface | Click a locked image in page mode → small side panel (src shown read-only, alt + title editable, Save/Revert) — the image stays locked in place; position/add/delete stays out of scope (F9 + builder wrappers, unchanged) |
| B-G6 | No fixtures | Harness: image-pass fixtures (attr swap keeps everything else, occurrence targeting, src-miss serves original, esc_attr on values, pass ORDER law) — extending the committed corpus |

## PART 4 — THE MERGED PLAN (V2+V3 as ONE arc, owner order)

One arc, four pairs, full ritual each (BEFORE commit → implement → harness +
php -l + tsc 59 + build + live pass → changelog line → AFTER commit). Order
chosen so every pair ships something testable and nothing blocks on the
connector until the last pair:

- **Pair 1 `[ux-pagemode-polish]`** — U1 + U2 (frontend only, minutes not
  hours). Ships: thinner editor, readable paragraphs.
- **Pair 2 `[page-versions]`** — PV-G1…G4 (hub + frontend, no connector).
  Ships: the versions dropdown with Original in page mode.
- **Pair 3 `[ai-inline-diff]`** — A-G1…G5 (frontend + existing AI endpoint,
  no connector). Ships: AI Optimize with red/green inline diff,
  Accept/Reject per section, Accept all. **The headline feature.**
- **Pair 4 `[image-metadata]`** — B-G1…G6 (engine v2.3, connector 3.0.2,
  schemaVersion 4, harness extended). Ships: click an image, edit alt/title,
  served dynamically. Fleet note: powerleads self-updates from the hub
  manifest (proven); powerstock stays on its manual ritual (unchanged,
  owner-gated).

Merge interactions (named, so nothing surprises us):
- The word-diff treats images as opaque tokens (A5) — AI never touches them;
  the V1 strip law stays for section saves, image rules live BESIDE it
  (different target, different pass) — no collision.
- Versions record the DOCUMENT; diff marks are stripped before any save, so
  a version can never contain review marks (A-G2 guard covers both).
- An AI-accepted save records a page version like any save (PV-G1) — undoing
  an AI run = pick the previous version → Acceptera.
- Image rules are NOT part of the page document (attrs live in their own
  rules) — page versions/restores never touch image metadata.

Owner test (after the arc): U1/U2 visually · page versions: save twice, flip
between versions + Original, delete one · AI: run on a page, reject one
section, accept the rest, verify live · images: edit an alt, verify in the
page source, edit it back → rule gone.

## OUT OF SCOPE (unchanged from V1)

Image add/move/delete (F9 + builder wrappers) · side-by-side diff (owner
chose inline) · page-level rollback of SECTION version history (page versions
are documents, section versions stay per-section — G8) · local hub posts
(no engine — boundary).
