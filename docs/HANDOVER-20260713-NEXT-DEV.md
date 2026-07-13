# HANDOVER — 2026-07-13 → NEXT DEVELOPER

You are taking over mid-arc. Read this whole file before touching anything.
The owner is the product owner (PO): short, plain product language; sends
"lo" as an alive-check (answer with one-line status and keep working);
expects you to REITERATE his requests as a list before building so nothing
is lost.

## 0. YOUR PERSONA (non-negotiable)
Senior developer + senior engineer + senior architect on every task; senior
UX designer when the task is visual. Facts with evidence — line numbers,
live probes, never theory. Lean: only what was asked, nothing speculative.
When the owner asks a question, ANSWER IT and stop — do not build.

## 1. THE LAWS (owner's, violated at your peril — he notices)
1. **NEVER `git push`.** Local commits only until he explicitly says push.
2. **NEVER code without his explicit GO.** Ritual: gap analysis (code-based
   facts) → checklist → COMMIT the gap doc → ask approval → build. He has
   exploded twice over violations of this. Questions are not GOs.
3. **ALWAYS commit before starting anything** (BEFORE state). If the tree is
   clean, say so — that counts.
4. **Zero dead code, zero tech debt.** Revert unused APIs you created;
   delete absorbed code in the same commit.
5. **Full ritual per pair:** BEFORE commit → build → verify (php -l ·
   `php tests/standalone/run.php` = 88/88 · `cd app && npm run check` = 59
   pre-existing errors, ZERO new · `npm run build`) → changelog line in
   docs/CHANGELOG-20260709-2200.md → AFTER commit (message ends "LOCAL ONLY").
6. **No instructional UI text ever; English only; no confirmation dialogs
   for reversible actions** (versions are the undo).
7. **Shared components law:** ModelDropdown (components/shared) is THE
   dropdown; PillButton/PillSplitButton (variant 'success' = save-green) is
   THE button family; sidebar metrics. Never a bespoke/native control again.
8. **Every AI change goes through the red/green review.** No path may ever
   write AI text without Accept/Reject — he calls unreviewed writes a
   regression (one shipped once; it was an incident).
9. **Lane colors are fixed** (grey = site's own, amber = edited, sky =
   added). Only the 2s focus FLASH may change a lane's color.
10. **Data-first debugging:** instrument live before theorizing. His trust
    follows probe output, not reasoning.
11. Never splice UTF-8 with PowerShell; use the Edit tool or bash.
12. **Owner's real rules on powerleads must NEVER be consumed in tests:**
    post 1 → rules 5,9,10,12,13,19,23 · post 2 → 6 · post 3 → 2 ('-'
    paragraph rule) and 24 · site rule 8. Use disposable posts, clean up,
    report leftovers honestly (remote delete 405s — wp-admin removal).

## 2. ENVIRONMENT HARD FACTS (this exact machine)
- **Avast quarantines NEW `.php` files minutes after creation** (Temp AND
  project tree). Run live probes via STDIN heredoc into PHP — nothing on
  disk: see memory `project_probe_toolchain`.
- **Avast MITMs outbound HTTPS from CLI PHP** (cert literally issued by
  "Avast Web/Mail Shield") — CLI probes CANNOT reach api.anthropic.com /
  openrouter. **Web PHP works** (owner's browser AI runs succeed). Never
  again conclude "AI is broken" from a CLI TLS failure — that mistake was
  made and corrected.
- PHP: `/c/Users/dataadmin546/AppData/Local/Programs/Local/resources/extraResources/lightning-services/php-8.2.29+0/bin/win64/php.exe`
  with `-d extension_dir=<bin>/ext -d extension=mysqli -d extension=mbstring
  -d extension=openssl -d extension=curl -d mysqli.default_port=10017`.
- `PCM_Sites_Service::remote_rest()` GET/DELETE need `null` body (empty
  array fatals WP's curl transport).
- Connector update ritual: `PCM_SEOHub_Service::connector_artifact()` hub-
  side (sha-verify), backup, extract zip, **bash `cp -f`** over
  `/c/Users/dataadmin546/Desktop/PROJECTS/PowerLeads/app/public/wp-content/plugins/pcm-connector/pcm-connector.php`
  (PHP file_put_contents gets permission-denied there).
- Old "cold-window" wordpress.org stalls are probably Avast too.

## 3. STATE OF THE SYSTEM
- Branch `feat/seo-suite-port`, HEAD `2633740`, **~10 commits ahead of
  GitHub** (last push `d1a3a86`; repo github.com/profitmediaab/powerplatform;
  `master` is months behind — a PR to master is owner-gated).
- Engine v2.4.1 · connector **3.0.5** live on powerleads (redirects +
  url-usage) · hub DB **1.41.0** · harness **88/88** · tsc baseline **59**.
- Shipped today (changelog has full detail per tag): [add-images] fix+proof ·
  [slug-redirects] · [section-frames]→[section-blocks] · [faq-block] +
  edit-open/serve-collapsed split · [doc-look] + corrections · [ai-model-select]
  · [model-truth] (shared ModelDropdown, self-refreshing registry,
  generated-with per section) · [live-pricing] (OpenRouter feed, tier=math,
  thresholds=data) · [brand-context] (sites.brandId → business.* vars +
  page.type; header Business/Page-type pills) · [header-2rows]+[header-fixes]
  +[dropdown-aware] · [ai-scope-fix] (selection = scope of the SAME review;
  OK button) · [inline-review] (chips on sections, click-card scroll+flash)
  · [review-edit-revise] (SURGERY ENGINE: doc = single source of truth
  during review, editable suggestions, Revise per section + Revise all).

## 4. WHERE YOU COME IN (the in-flight task — do this FIRST)
**[review-integrity]** — gap analysis COMMITTED and AWAITING OWNER GO:
`docs/GAP-ANALYSIS-REVIEW-INTEGRITY-20260713.md` (commit 2633740). It fixes
distortions the owner found live (phantom bullets, blue-lane flips, stuck
hover) plus the widest one found in audit: accept-what-you-see flattens
formatting of UNTOUCHED sections. The design core: per-section post-surgery
BASELINE + ONE `effectiveContent(i)` oracle (untouched → clean `s.ai`;
edited → stripped live) consumed by Accept AND Revise. Four gaps G1-G4 with
a checklist — **ask the owner for GO, then build exactly that.**

## 5. NEXT AFTER THAT (owner-approved direction, each needs its own GO)
- Small open UI items from an earlier list: a bit more window padding; text
  column slightly narrower (820→~760px).
- Owner browser pass on [review-edit-revise] (expect styling correction
  rounds — normal pattern).
- Push to GitHub when he says push; PR to master owner-gated.

## 6. BACKLOG / PARKED (do NOT start unbidden)
Ask-AI document advisor (docs/backlog.md, spec'd) · FAQ JSON-LD rich results
(phase 2, injection point verified) · AI-generated FAQ · per-paragraph
version history (owner skipped — do not resurrect) · builder-wrapper cloning
· editable theme furniture · hub asset library · fleet convergence +
powerstock connector update · Avast exclusions (owner's machine, his call).

## 7. KEY FILES
`app/src/modules/SEO/SectionModal.tsx` (the page editor — review surgery,
chips, revise, header) · `app/src/modules/SEO/word-diff.ts` (diff + strip —
G1/G2 live here) · `app/src/components/shared/{ModelDropdown,PillButton}.tsx`
· `includes/modules/seo/{service,controller}.php` (hub brain) ·
`includes/modules/seohub/service.php` (connector template nowdoc) ·
`includes/modules/models/{service,controller,automations}.php` (registry
self-refresh + live pricing) · `tests/standalone/run.php` (harness 88) ·
docs/: CHANGELOG-20260709-2200.md, DYNAMIC-OPTIMIZATION-ARCHITECTURE.md, all
GAP-ANALYSIS-*.md, HANDOVER-20260712-NEXT-DEV.md (previous arc's depth).

## 8. KICKOFF PROMPT (owner: paste this into the new session)

---
You are taking over as the senior developer, senior engineer, senior
architect (and senior UX designer when needed) of the Power Creatives
WordPress hub plugin. Read
`docs/HANDOVER-20260713-NEXT-DEV.md` COMPLETELY first — it contains the
laws, the environment traps, the system state, and your first task. Confirm
you are up to speed by (1) verifying the repo state matches the handover
(branch, HEAD, harness 88/88, tsc 59, build), (2) reading
`docs/GAP-ANALYSIS-REVIEW-INTEGRITY-20260713.md` — that is the committed,
approval-pending first task. Then reiterate to me in a short list: the laws
you will obey, the state you found, and the checklist you await my GO on.
Do not write or change ANY code until I say GO. Never push. Commit before
every task. Facts with evidence, always.
---
