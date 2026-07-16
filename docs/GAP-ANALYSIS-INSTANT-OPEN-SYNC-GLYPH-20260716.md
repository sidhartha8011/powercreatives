# GAP ANALYSIS — INSTANT OPEN + FEATHERWEIGHT STATE CHECK + SYNC GLYPH (owner GO 2026-07-16)

Owner design (the Ive/Woz ruling): the editor opens INSTANTLY from the
saved version; ONE tiny background question to the site ("what version +
fingerprint are you serving?"); one quiet header glyph with FOUR TRUE
STATES (checking → spins · in-sync → fades away · drift → amber, click =
load live view · unreachable → muted red, click = retry). The full page
fetch survives ONLY for first-ever opens (no saved versions) and for the
deliberate "load live view".

## FACTS (live-verified, HEAD c74db29)
1. THE HEAVY FETCH IS STILL THE GATE: pageReady = pageQuery(view==='served'
   && contentHtml!=='') (SectionModal.tsx:601-604) — the one-time content
   load (W0) gates on BOTH pageReady AND versionsSettled, so the open
   WAITS for the client site to render its whole page even when the
   content comes from versions[0]. This is the 10-15s.
2. THE QUEUE AMPLIFIES IT: hub pm.max_children = 2 (verified,
   conf/php/php-fpm.d/www.conf.hbs:4-5); ~16 modal queries run two at a
   time behind the slow fetch. (R4 pool decision remains the owner's.)
3. pageQuery consumers beyond content: pageState.drifted (:627, lane-C
   drift line), brandId/pageType init effect (:767-770 — non-blocking,
   fires whenever data arrives), loading UI (:2042). Nothing else NEEDS
   the inventory at open time.
4. The connector ALREADY stores pageState {version, fingerprint} beside
   the rules and echoes it in the heavy reply (W3, bede98e); hub keeps
   its own record in option pcm_page_state (W1). MISSING: a featherweight
   connector endpoint answering state WITHOUT rendering the page, and a
   hub route comparing the two cheaply.
5. Connector route pattern verified: register_rest_route('pcm-conn/v1',
   …) (seohub/service.php:578-1520) — a new read-only /page-state route
   follows the existing auth pattern of its read routes.
6. Timeout/tunables law: the check's timeout must be hub data, not code.

## DESIGN
- D1 **Connector /page-state** (seohub template + PATCH bump): GET,
  param postId → {version:int, fingerprint:string} from the stored
  pageState; absent → {version:0, fingerprint:''} (a true "never
  pushed", not an invention). No page render, no rule application.
- D2 **Hub route** GET /seo/sites/{id}/content/{post}/page-state:
  reads the local pcm_page_state record + calls D1 with a SHORT
  hub-tunable timeout → {local, remote|null, drifted:bool|null,
  error?:string}. drifted = fingerprint mismatch; remote null + error
  set when the site could not answer (never a fake verdict). tRPC line
  seo.pageState.
- D3 **Instant open** (SectionModal): when versions exist, the one-time
  content load gates on versionsSettled ONLY (pageReady gate removed on
  that path — W0's fallback path keeps it for no-versions opens). The
  INVENTORY query flips to enabled only when needed: no versions exist,
  OR the user clicks "load live view"/drift action. brandId/pageType
  init (:767) stays as-is (non-blocking, fills in when/if inventory
  arrives — and moves to the cheap route's payload if trivially
  available; NOT a blocker).
- D4 **THE GLYPH** (header, beside the status pill): one 12px icon,
  four true states from the D2 query — checking = spinning (query in
  flight) · in-sync = fades out (drifted===false) · drift = amber,
  title names it, click = load live view (existing pageHtml path,
  enables the inventory query) · unreachable = muted red, title names
  it, click = refetch. Lane C's drift LINE is superseded and DELETED in
  the same commit (one UI for one truth — honest deviation, named).
- D5 **Tunable**: the connector-check timeout joins pcm_optimizer_research?
  NO — wrong home; it joins a seo-side option constant read the same
  read-through way (small, named, hub-editable) — no hardcoded seconds.

## CHECKLIST
[ ] D1 connector /page-state + patch bump · [ ] D2 hub route + tRPC
line · [ ] D3 instant open + lazy inventory · [ ] D4 glyph (4 states) +
drift-line deletion · [ ] D5 timeout as data · [ ] php -l x2 ·
[ ] harness 88/88 · [ ] page_versioning 26/26 · [ ] research 34/34 ·
[ ] tsc 59/0 new · [ ] build "built in" · [ ] changelog · [ ] AFTER
commit LOCAL ONLY · [ ] owner browser check: open <2s, glyph states
