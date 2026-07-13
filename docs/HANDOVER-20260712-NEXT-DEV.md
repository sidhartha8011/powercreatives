# HANDOVER — 2026-07-12 — full background, state, laws, and in-flight work

You are taking over the SEO suite of the Power Creatives hub plugin mid-arc.
Read this top to bottom. Supersedes `HANDOVER-20260710-NEXT-DEV.md` (still
valid for deep history). Companion docs are authoritative where they go deeper.

---

## 1. PURPOSE (unchanged)

The hub (powercreatives.local) manages SEO for a fleet of CLIENT WordPress
sites (powerleads.local, powerstock.local) via a generated single-file
connector. ALL content optimization is DYNAMIC: rules stored on the client
site, applied at render, verify-first, fail-to-original, reversible,
surviving hub outages. The hub is the ONE brain; the connector is dumb.

## 2. ENGINE STATE — v2.4.1 (connector 3.0.4 live on powerleads, wire schema 5)

Contracts frozen in `docs/DYNAMIC-OPTIMIZATION-ARCHITECTURE.md`. Version
ladder this arc (each ADDITIVE, each fixture-pinned):

- **v2.3** (3.0.2, schema 4): `image` target — alt/title rewrite by
  normalized src + occurrence among CONTENT-region images (chrome frame law);
  originals in anchorContext power clean revert.
- **v2.4** (3.0.3, schema 5): `sectionRemove` target (verify-first locate
  like a replace, blocks removed whole, between-content survives) + image
  `hidden:true` (tag removed at render — media/storage NEVER touched); image
  pass is ONE stable-index buffer scan; pass order: heading → section
  replaces → removes → inserts → paragraphs → images. The rule algebra
  {replace, insert, remove} is CLOSED over block-level transformations.
- **v2.4.1** (3.0.4): the CONTENT-REGION PRIMITIVE — a `the_content`
  observer records each singular main-query post's final content; exact
  strpos locates its span in the buffer; the serving callback injects a
  SENTINEL comment (`PCM_CONN_CE`) at the region end before passes run and
  strips it after (exception path returns the pristine original). Placement
  law: an insert anchored INSIDE the region never lands past its end.
  Ordering law: 'after' inserts apply in REVERSE rule order → pages read in
  creation order. Fingerprinting insert anchors was REJECTED on record.

**Harness: `php tests/standalone/run.php` — 70 fixtures ALL GREEN.** It
evals the REAL connector template from seohub/service.php. Extend it with
every engine change. tsc baseline **59** (zero new allowed). Build must pass.

## 3. HUB / EDITOR STATE (what shipped this arc, all live-verified)

- **Full-page editor** (SectionModal `mode:'page'`, 58vw, own reading
  scale): served `contentHtml` assembled hub-side (clean h/p blocks; rule
  slices VERBATIM; every original img extracted as locked context —
  `data-pcm-locked`; gap scanning covers the whole non-chrome document so
  the editor image set == connector counting set BY CONSTRUCTION).
- **save_page_edits** — the compiler from an edited document to rules:
  1:1-by-order else LCS alignment + positional RENAME pairing within gap
  segments; unchanged-skip law = heading norm + level + paragraph
  fingerprint + raw-unit visible text (**image-blind — see §7 in-flight
  fix**); whole-rule pairs route via save_section_rule on the RULE's stored
  identity (clean revert deletes); partial slices splice; originals by row
  identity (absorb law rewires heading-ruled rows); EXTRA sections checked
  against active sectionRemove rules FIRST (restore = rule deletion) else
  inserts anchored to the preceding served heading; FEWER sections =
  removals per kind (insert-born → rule dies; whole-rule groups collapse to
  ONE remove of the rule's ORIGINAL identity + absorb; partial → empty
  splice; original → sectionRemove); per-src IMAGE reconciliation (fewer
  visible → hide rules from the visible tail; more → hide rules die; added
  images excluded on both sides); EMPTY DOC = legal full wipe;
  empty-baseline = legal restore path (wipe is never a one-way door —
  live-caught + fixed); every routed save pushes with rollback; page version
  recorded per changing save.
- **Versions**: `seo_rule_versions.target` 'section'|'page' (generalized
  record/list, zero schema change). Page dropdown: newest row = "(Current)",
  Original row = page date + "(original)", no separate Current choice.
- **AI review** (V2): in-house word-LCS diff (`word-diff.ts`, zero deps),
  diffAdded/diffRemoved marks (presentation only — stripDiffHtml guard on
  EVERY save), bounded 4-parallel per-section calls to remote_optimize_section,
  review rail Accept/Reject/all, editor read-only until resolved.
- **Image panel** (V3): click an image → alt/title editor + Hide image;
  revert applies server-returned originals IN PLACE.
- **THE LOAD-ONCE LAW (incident fix 2026-07-11, sacred):** the page editor
  loads its document ONCE (ref-guard); content is NEVER auto-replaced by
  refetches (a degraded refetch once clobbered a full doc in front of the
  owner — data was safe, the guard-refusal saved it). Saves refetch the
  versions list only; version picks set content explicitly.
- **Consolidation**: paragraph-rule CREATION deleted end-to-end (endpoints,
  handlers, save_paragraph_rule — zero UI callers); absorb matches ORIGINAL
  identity OR served output (one-owner airtight, the live-observed #2+#21
  coexistence class dead); sectionRemove also absorbs covered paragraph
  rules. Read-side folding + connector Pass 2 stay until fleet convergence
  (deletions ledger).
- **Editor chrome**: Save & close → Save → Undo (page mode), Edit (WP
  editor) + Open (permalink) LEFT beside the name, English only, NO
  confirmation dialogs (deleted by owner order — reversibility via versions
  is the safety), NO instructional hint text ever (owner law, in memory).

## 4. OWNER LAWS (hard — the old nine PLUS this arc's)

1. **NEVER `git push`.** Local commits only until the owner explicitly says push.
2. **No coding without explicit GO.** Gap analysis → reiterate → confirm → build exactly that.
3. **Zero dead code / tech debt.** Delete, don't deprecate. Every line earns its place.
4. No hardcoded connector tunables (hub-pushed config).
5. No silent fallbacks — fail honest.
6. **Facts with evidence — instrument live before theorizing.** Guessing gets called out.
7. Owner talk: product language, SHORT. Technical depth only "as senior developer".
8. Full ritual per pair: BEFORE commit → implement → verify (harness + php -l + tsc 59 + build + LIVE proof) → changelog line (`docs/CHANGELOG-20260709-2200.md`) → AFTER commit.
9. Never splice UTF-8 with PowerShell Get/Set-Content.
10. **No instructional UI chrome, ever** — interactions reveal themselves (memory: feedback_no_instructional_ui_chrome).
11. **English-only UI** — no hardcoded Swedish (Acceptera/Spara/Ångra are dead).
12. **No confirmation dialogs for reversible actions** — versions are the undo.
13. Owner pings "lo" = alive-check → one-line status, keep working.

## 5. TOOLCHAIN (this box — hard-won facts)

- PHP CLI: `C:\Users\dataadmin546\AppData\Roaming\Local\lightning-services\php-8.2.29+0\bin\win64\php.exe`
  with `-d extension_dir=<dir>\ext -d extension=mysqli -d extension=mbstring
  -d extension=openssl -d extension=curl -d mysqli.default_port=10017`
  (**openssl is REQUIRED** — site-password decryption; 10041 = powerleads DB).
- **THE COLD-WINDOW TAX (root-caused):** this box is OFFLINE; whenever the
  hub's WP update-check transients expire, the FIRST process afterwards
  hangs minutes on wordpress.org timeouts (17.5s bootstraps, multi-minute
  REST calls) — then everything is fast until the next expiry. Probes:
  batch whole test cycles into ONE script (one bootstrap = one tax), run in
  background with 10-min budgets, and RETRY once on timeout (second run is
  warm). This caused the 2026-07-11 "everything deleted" illusion (degraded
  tier-2 snapshot) and several probe timeouts. Environmental, not plugin code.
- **Probes live in `C:\Users\dataadmin546\AppData\Local\Temp\pcm-probes\`**
  (the session scratchpad PURGES mid-session — memory: project_probe_toolchain).
  Run via Git Bash (PowerShell silently swallowed output on missing files).
  Existing probes there: probe-v24.php, probe-wipecycle.php,
  probe-placement*.php, probe-addimage.php, proof-*.php + connector zips +
  version backups (3.0.1/3.0.2/3.0.3 .backup.php files).
- **Connector update ritual** (self-update hangs on this offline box):
  download `http://powercreatives.local/wp-json/pcm/v1/seohub/connector-package`,
  verify sha256 against `/connector-manifest`, back up the current
  `PowerLeads/app/public/wp-content/plugins/pcm-connector/pcm-connector.php`,
  `cp -f` the new one over it (unzip-in-place fails: permission denied).
  Manifest version auto-derives from the template's `Version:` header.
- powerleads files: `C:\Users\dataadmin546\Desktop\PROJECTS\PowerLeads\app\public\`.
- Full authenticated render of a draft: `PCM_SEO_Service::remote_preview_html($site, $url)`.
- Media delivery to a client: `PCM_Sites_Service::remote_upload_media($site, $url)` (raw-binary /wp/v2/media, production-proven).

## 6. FLEET / DATA STATE

- Branch `feat/seo-suite-port`, ~72 commits ahead of origin, NEVER pushed.
- powerleads: connector **3.0.4**; owner's REAL test rules live there —
  post 1 (5, 9, 10, 12, 13, 19, 23), post 2 (6), post 3 (2 — the '-'
  paragraph rule — and 24), site rule 8. NEVER absorb/consume these in tests
  (a wipe PERMANENTLY absorbs paragraph rules — proven).
- **Disposable sandboxes on powerleads** (owner knows): draft pid 22
  ("PCM wipe test") — carries sectionRemove #38 (beta) + inserts #39–42
  relabeled INSONE..INSFOUR (the placement proof) + media attachment id 27
  (w-logo-blue.png, delivered during the in-flight image test); draft pid 23
  leftover. Remote delete returns 405 — wp-admin removal when owner wants.
- powerstock: OLD connector, manual ritual pending (owner-gated), version
  display + everything new needs it.

## 7. IN-FLIGHT WORK — finish this FIRST

**Pair `[add-images]` (BEFORE commit 5e2676d, gap doc
`GAP-ANALYSIS-IMAGES-REDIRECTS-20260712.md`): code complete & verified
(harness 70/70, tsc 59, build OK) but the live pass found ONE REAL GAP —
uncommitted code sits in the tree (service.php, controller.php,
trpc-routes.ts, SectionModal.tsx), committed as WIP alongside this handover.**

What's in the WIP: `remote_add_media` delegate + `POST /seo/sites/{id}/media`
+ trpc `seo.remoteUploadMedia`; Add-image button → hub `wp.media` picker →
client-native URL inserted with `data-pcm-added=<attachmentId>`; THE KEEP
LAW in save_page_edits (an <img> survives a save only with the added marker
AND server-verified client-host src — locked/pasted/AI images strip as
before); reconciliation excludes added images on both sides; assembly keeps
added images IN PLACE inside slice emissions (`extract_imgs($frag, $skip_added)`).

**THE FOUND GAP (live-proven):** delivery worked (attachment 27, client URL
returned) but the place-save returned `skipped:1` — the unchanged-skip law
(heading + fingerprint + rawtext) is IMAGE-BLIND, so an image-only edit
never marks its section changed and the image never saves.
**The fix:** extend the pair-loop comparison with the section's KEPT-image
identity — e.g. compute an added-img src list per side (edited units vs
baseline sliceHtml, `data-pcm-added` imgs only, normalized srcs joined) and
include it in the unchanged check beside `$rawtext`. Symmetric by
construction (assembly leaves added imgs in slices). Add a harness-adjacent
proof or live re-run.
**Then re-run the live proof** (`pcm-probes/probe-addimage.php` — note its
last run also hit a degraded 33-char contentHtml during a cold window;
re-run when warm, expect: place save saved:1 → full render serves the
client-host img → remove save → gone from serving → `/wp/v2/media/27` still
exists). Then changelog + AFTER commit. A stale background task
(b0d4ana6x) from the old session may still write to its output file — ignore it.

**Pair `[slug-redirects]` — next, fully specced in the same gap doc:**
seo_redirects table (DB 1.38.0 → 1.39.0) + save/list/delete with
push-rollback; connector **3.0.5**: `/pcm-conn/v1/redirects` store +
sanitizers + EARLY template_redirect handler (exact path match, query
passthrough, code whitelist 301/302/307/308, zero cost on empty store,
harness-testable match function) + `/url-usage` search (content + builder
meta LIKE) powering "also update N internal links" via the existing
builder-aware `/replace-url` (per-post; hub loops it server-side when the
popup checkbox is on); the popup fires after a slug save that actually
changed (connected + published): From/To/301-default dropdown prefilled and
editable + N-links checkbox; redirect list + delete in
RemoteSiteSettingsPanel (no create-only black boxes). HONESTY FACT for the
owner: WP core already 301s old published-post slugs invisibly — "No" in
the popup doesn't guarantee a 404. Live proof: disposable PUBLISHED post,
curl -I 301 proof, seeded internal link rewritten, delete → stops.

## 8. PARKED / GATED (owner explicitly deferred)

- Editable theme-furniture texts with element attribution (owner wants a
  dedicated discussion + gap analysis).
- Hub asset library with cross-site image placement (images phase 2).
- Rendering theme furniture in the editor preview (unnecessary since
  placement is exact — rejected unless owner reorders).
- Fleet convergence cleanup (powerstock → 3.0.x, then delete the legacy
  override layer + pre-3.0 fallbacks + connector paragraph pass — ledgered).
- Content-span scoping of OTHER passes (identity spaces frozen — major-version discussion).
- The "skip wordpress.org checks on this offline box" hub setting (offered, not ordered).

## 9. GAP-ANALYSIS DOC INDEX (this arc, newest last)

GAP-ANALYSIS-FULLPAGE-EDITOR-20260710 (V1, superseded by shipped state) ·
GAP-ANALYSIS-PAGE-EDITOR-V23-20260711 (versions/AI/images arc) ·
GAP-ANALYSIS-SECTION-REMOVAL-20260711 (superseded by PAGE-FREEDOM) ·
GAP-ANALYSIS-PAGE-FREEDOM-20260711 (remove/hide/wipe) ·
GAP-ANALYSIS-EDITOR-POLISH-20260712 (6 owner items) ·
GAP-ANALYSIS-INSERT-PLACEMENT-20260712 (content-region primitive) ·
GAP-ANALYSIS-IMAGES-REDIRECTS-20260712 (**the active one — §7**).

## 10. OWNER TEST CHECKLIST (once §7 completes)

1. Page editor → Add image → pick from hub library → it appears at the
   cursor → Save → live page shows it (served from the CLIENT's own
   /wp-content/uploads) → delete it in the editor → Save → gone from the
   page, still in the site's media library.
2. Change a slug on a published page → popup shows old URL → new URL, 301
   preselected, editable → Yes → old URL redirects (hard refresh) → the
   redirect is listed in site settings → delete it there → redirect stops.
3. Everything from the previous checklists still holds (wipe/restore,
   AI review, image hide, versions, placement order).
