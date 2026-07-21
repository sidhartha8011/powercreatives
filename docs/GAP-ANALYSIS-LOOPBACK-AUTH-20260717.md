# GAP ANALYSIS — SELF-AUTHORIZED CONNECTOR LOOPBACK (owner order 2026-07-17, full ownership)

Owner: content pulls fail around login state on the client site — a
shortcut in the pipeline the connector exists to own. Fix at the source,
senior-grade, no session dependence anywhere, ever.

## FACTS (live, HEAD 4b6e12f)
1. THE SHORTCUT (proven): pcm_conn_loopback_fetch (seohub/service.php:1658)
   does an ANONYMOUS wp_remote_get(get_permalink($pid)) with a non-200 →
   '' return. A DRAFT/private/future post has NO public render → the
   snapshot/served-view pull returns EMPTY for those posts, always. The
   editor then has nothing to assemble — the white/unreadable pulls the
   owner sees on the draft Privacy Policy.
2. The connector RUNS INSIDE the client site — it has full authority; the
   anonymous self-fetch throwing that authority away is the defect.
3. The hub already treats an empty snapshot honestly (view marker /
   loopback_blocked path) — hub changes are NOT needed for the fix.
4. The harness (tests/standalone/run.php) evals the REAL template — must
   stay 88/88; connector 3.0.7 → 3.0.8, sites reinstall (standing flow).

## DESIGN — the one-time self-auth token (no sessions, no app-password-on-frontend)
- D1 On every loopback fetch the connector mints a 32-char random token,
  stores it in a 60s transient keyed by the token, value = the pid, and
  appends ?pcm_snap_auth=<token> to the URL it fetches.
- D2 An early hook (pre_get_posts, main query only) validates the token:
  exists AND its pid matches the queried post → the transient is DELETED
  (single use) and the query's post_status widens to
  publish/draft/pending/private/future FOR THAT REQUEST ONLY. Invalid or
  reused tokens change nothing — anonymous rules apply.
- D3 Scope guarantees: token random + single-use + 60s TTL + pid-bound +
  read-only (grants one page render, nothing else). The rule-serving
  exclusions (pcm_snap etc.) already skip these requests.
- D4 Published posts ride the same path (one code path, the token is
  harmless there) — no status-based branching to rot.

## CHECKLIST
[ ] D1 token mint in loopback_fetch · [ ] D2 pre_get_posts validator ·
[ ] 3.0.8 bump · [ ] php -l · [ ] harness 88/88 · [ ] changelog ·
[ ] AFTER commit LOCAL ONLY · [ ] owner: update connector, log OUT of
the site, open the draft — content must pull
