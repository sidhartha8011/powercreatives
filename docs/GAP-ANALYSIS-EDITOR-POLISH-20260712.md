# GAP ANALYSIS — EDITOR POLISH: no confirmation, full wipe, English, buttons (2026-07-12) — OWNER GO

**Owner order (confirmed list):** (1) remove the confirmation dialog entirely ·
(2) an emptied editor removes every section on save · (3) delete the image
hint text + never ship instructional UI chrome again · (4) "Edit" rename +
new "Open" button (live page, new tab) · (5) footer order save-and-close →
save → undo · (6) English only — no hardcoded Swedish anywhere in the editor.

## FACTS

| # | Fact | Evidence |
|---|------|----------|
| E1 | The confirm gate's remaining protective value is near zero: a broken/empty editor load cannot reach a save since the load-once fix (pageReady requires a served, non-empty document). Its only live effect is an extra click the owner rejects. | SectionModal pageReady/pageLoadedRef; owner decision |
| E2 | A fully emptied document is rejected by `save_page_edits` (`pcm_seo_page_edit_removed` when zero sections remain) — the wipe path ends there, nothing saves. Beneath that guard the machinery already handles it: LCS with zero edited sections classifies every baseline section as removed, and image reconciliation hides every image (none left in the doc). | service.php save_page_edits |
| E3 | `save_section_remove` does NOT absorb paragraph rules covering the removed section — after a removal they can never serve again (their blocks are gone) and would sit as stale noise forever (one-owner leak, same family as the fixed absorb miss). | service.php save_section_remove |
| E4 | The hint text ("Click an image to edit…") is permanent instructional chrome: it labels a discoverable interaction and ages into noise. LESSON (owner law, saved to memory): features reveal themselves through interaction — never ship permanent UI hint text. | SectionModal header |
| E5 | The header link opens the WP editor only; there is no open-live-page affordance. `SeoRow.permalink` exists and already rides to the preview modal. | index.tsx, types.ts |
| E6 | Hardcoded Swedish labels: Acceptera / Spara / Ångra (+ their tooltips) — from the original section-editor sketch. All other editor copy is English. | SectionModal |

## GAPS → CHANGES

| # | Change |
|---|--------|
| P1 | DELETE the confirm protocol end-to-end: `$confirm` param + `needsConfirm` return (hub), `confirm` body field (controller/trpc), `pendingConfirm` state + dialog (modal). Zero dead code. |
| P2 | Full wipe works: drop the zero-sections rejection (keep the no-BASELINE-sections error — a page without headings still can't page-edit); guard the orphan-zone index for an empty section list. Wipe = every section removed + every image hidden, all reversible via versions. |
| P3 | `save_section_remove` ABSORBS covered paragraph rules (original identity OR served output — the tightened law) using its ctx paragraphs, same push. |
| P4 | Delete the hint text. Memory rule recorded: no instructional UI chrome, ever. |
| P5 | Header: **Edit** (→ WP editor) + **Open** (→ permalink, new tab); `page` prop gains `permalink`. |
| P6 | Footer order + English: **Save & close** → **Save** → **Undo** (page mode); section mode: **Save** / **Undo**. All tooltips English. |

## GATED (owner: "discuss later" — NOT in this pair)

Editable furniture texts (comment-form labels etc.) with clear element
attribution in the editor — needs its own identity model (they are not
content blocks); parked for a dedicated gap analysis when the owner calls it.

## VERIFY

php -l · harness 61/61 (P3 is hub-side; engine untouched) · tsc 59 · build ·
LIVE on powerleads on a DISPOSABLE page (never the owner's test rules — a
wipe ABSORBS paragraph rules permanently): create a draft with sections via
the existing remote-create path, seed one paragraph rule row, wipe with an
empty document → every section removed + the paragraph rule absorbed →
restore via the saved original → sections back, remove rules gone → delete
the disposable page. Owner data untouched throughout.
