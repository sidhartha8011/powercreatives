# GAP ANALYSIS — INSERT PLACEMENT: the content-region primitive (2026-07-12) — AWAITING GO

**Owner order:** the timeless fix for insert placement — no tech debt, no
ad-hoc patches, every line of code earns its place. Fingerprinting the anchor
was examined and REJECTED (it binds an insert's survival to text it never
touches — a client typo would kill an unrelated added section). The chosen
model: WordPress's own `the_content` pipeline defines the content region;
the engine learns its exact boundaries instead of guessing them.

## PART 1 — FACTS (each one proven, evidence attached)

| # | Fact | Evidence |
|---|------|----------|
| I1 | **The fallback bug, proven offline against the real extracted connector:** with trailing headingless paragraphs after the anchor section (exactly what themes append — comment forms), an 'after' insert lands after the furniture: `…furniture-2</p><h2>ONE</h2>` → "YES (bug confirmed)". The next-heading branch is sound; ONLY the last-section fallback guesses. | proof-insert-order.php run 2026-07-12 |
| I2 | **The ordering bug, proven in the same run:** three same-anchor 'after' inserts created ONE,TWO,THREE serve as THREE,TWO,ONE (each next insert lands before the previous one's heading — the previous insert became "the next heading"). 'before' inserts are correct in forward order (each lands at the anchor's start, above the previous — matching creation flow). | same proof: `X,THREE,TWO,ONE,End` |
| I3 | Live confirmation of I1: the owner's 4 pasted sections on the disposable page (rules #39–42, `{"level":2,"position":"after"}` — NO extent data) rendered below the post navigation on the full page, while the content-only snapshot view showed them correctly — the divergence is exactly the unverified extent scan meeting the full render's furniture. | probe-insertpos.php + owner screenshots |
| I4 | `sectionInsert` is the ONLY rule family whose serve-time reach is unverified (candidates match heading text+level only). Replaces/removes verify fingerprints; heading/paragraph/image rules match exact identities. | connector apply functions |
| I5 | The connector already stands inside the `the_content` pipeline (tier-2 snapshot render, line ~2290) but has NO filter registered on it — a recorder is a new, single-purpose primitive, not a duplicate mechanism. | grep the_content in seohub/service.php |
| I6 | The OB serving callback receives the ORIGINAL buffer (rules apply only inside it), and themes echo `apply_filters('the_content', …)` output verbatim into their container — so the recorded final-filter string appears as an exact substring of the buffer. An exact `strpos` locates the content region with zero heuristics. | template_redirect OB design; WP core contract |
| I7 | Rule schema is UNTOUCHED: rules #39–42 carry `{level, position}` only and stay valid — every existing insert rule on the fleet gets correct placement the moment the connector updates. No migration, no hub change, no schema bump (wire stays 5). | rule rows; wire contract |
| I8 | Tier-2/3 fallback renders (drafts, WAF'd sites) are the_content-only documents — there the whole buffer IS the content region and the existing fallback is already correct; the recorder isn't in play there (no loop), and the legacy fallback remains for it. Nothing regresses. | snapshot tiers; I1 scope |

## PART 2 — THE DESIGN (engine v2.4.1, connector 3.0.4 — serving only)

Two single-purpose primitives + one law change + one ordering law:

1. **Recorder** — `pcm_conn_record_content($pid, $html)`, registered as a
   `the_content` filter at `PHP_INT_MAX`, recording ONLY when
   `is_singular() && in_the_loop() && is_main_query()` and the post is the
   queried one; first non-empty win per request (static, per pid). Returns
   `$html` unmodified — a pure observer.
2. **Locator** — `pcm_conn_content_span($buffer, $pid)`: exact `strpos` of
   the recorded string → `[start, end)` or `null` (not recorded / not found
   — e.g. an optimizer rewrote markup between filter and output). `null` =
   the legacy fallback, honestly — never worse than today.
3. **Placement law (the fix):** in `pcm_conn_apply_section_insert`, the
   'after' fallback (no following heading) becomes: anchor inside the
   content span → insert at the span's END; span unavailable or anchor
   outside it (chrome/comment-area anchors keep their semantics) → legacy
   after-last-block. The next-heading branch is UNCHANGED (skeleton law,
   proven sound).
4. **Ordering law (the second fix):** within the sectionInsert phase,
   'before' inserts apply in rule order (correct today, I2), 'after' inserts
   apply in REVERSE rule order — each earlier rule then lands above the
   later ones and the page reads in creation order. Pure iteration-order
   change, no state.

Threading: the OB callback computes the span once and passes it down —
`pcm_conn_apply_rules($html, $rules, $pid, $content_span = null)` →
`…apply_section_insert(…, $content_end = null)`. Defaulted parameters keep
every existing call site and all 61 fixtures valid — no signature debt.

**Why this is the timeless shape:** the region comes from WordPress's own
rendering contract (every theme, every builder, forever), not from pattern
matching; it costs one strpos per render; it adds zero rule data; it fails
closed to current behavior; and it is the natural future home for stronger
content-scoping if ever ordered (explicitly NOT done now — identity spaces
are frozen contracts).

## PART 3 — CHECKLIST (one pair, full ritual)

- [ ] BEFORE commit.
- [ ] Connector 3.0.4: recorder + locator + placement law + ordering law
      (+ version header; wire schema untouched at 5).
- [ ] Harness fixtures (extend the committed corpus): recorder→locator round
      trip · locator returns null on unrecorded/unfound · 'after' fallback
      clamps to span end with trailing furniture present (I1 case turned
      green) · anchor OUTSIDE the span keeps legacy fallback · same-anchor
      'after' ×3 serves in CREATION order (I2 turned green) · 'before' ×2
      order locked · existing 61 stay green untouched.
- [ ] Architecture doc: v2.4.1 contract note under v2.4.
- [ ] Verify: php -l + harness ALL GREEN + tsc 59 + build (frontend
      untouched — expected no-op) — then LIVE on powerleads 3.0.4: the
      disposable page's existing rules #39–42 (unchanged data!) must render
      IN ORDER and INSIDE the content area on the full page; then that page
      finally deleted.
- [ ] Changelog line + AFTER commit.

## OUT OF SCOPE (named, so nothing sneaks in)

Scoping OTHER passes to the content span (identity spaces are frozen; a
future major-version discussion) · rendering theme furniture in the editor
(parked) · editable furniture texts (parked, owner's list) · any hub or
schema change (none is needed — that's the point).
