# RESEARCH — CONTENT ANALYSIS (reality-based, page-type checklists) — 2026-07-13

**Owner premise (the law of this feature):** evaluate content against what
ACTUALLY ranks in reality — never against generic SEO-tool checklists
"based on nothing". This document answers the four owner questions: which
page types, evaluated against what, evaluated how, and how the GUI + the
optimization prompt consume it.

## 0. How this stays "reality" and not "best practices" (the mechanism)

Two layers, so the checklists can never rot into dogma:

1. **Seed checklists (below)** — drawn from observable patterns of pages
   that hold rankings in 2025–2026: direct answers early (featured
   snippet / AI-overview extraction favors pages that answer in the first
   screen), entity/subtopic coverage on head terms (sites structured as
   topic clusters outrank longer single pages), visible contact + review
   signals on local intent (local SERPs are dominated by pages mirroring
   the Local Pack's own trust data), and plain conversational language on
   service pages (pages written like people talk survive helpful-content
   classifiers; academic prose does not).
2. **Reality derivation (the platform's own edge)** — per niche, the
   checklist is VALIDATED against live winners: fetch the top-ranking
   pages for the row's primary keyword (SERP fetch; GSC tells us where WE
   rank), extract measurable facts (phone above the fold? review count
   visible? how many subtopics covered? reading level?), and store the
   observed pattern as the niche's expected values. A check the winners
   consistently ignore gets demoted; a pattern they all share gets
   promoted. The checklist FOLLOWS reality instead of asserting it.
   (Phase 2 — the seed checklists ship first and are already data.)

**Storage law:** checklists are hub-side DATA (same pattern as prompts /
tier thresholds — editable, seedable, never hardcoded in code paths).

## 1. Page types and their checklists

Keyed off the EXISTING Page-type dropdown (general / local / blog /
product / service / landing) + two additions: **category** (knowledge/head
term) and **brand**. Mapping: local+service → LOCAL list; category → HEAD
TERM list; blog → SUPPORTING list; product/landing → LOCAL list minus
geo-checks; general/brand as named.

### A. Category / knowledge page (head term, e.g. "dentist")
| Check | What passes |
|---|---|
| Direct definition early | The head term is answered/defined in the first screen, quotable in 1–2 sentences |
| Subtopic coverage (knowledge-graph completion) | The page covers the term's expected branches as sections — for "dentist": services (cleaning, implants, orthodontics…), costs, when-to-see, procedure expectations, aftercare. Winners cover the cluster; thin pages list nothing |
| Supporting-page links | Each major subtopic links DOWN to its dedicated supporting page with descriptive anchors (hub-and-spoke — this is what topical authority physically is) |
| Related entities present | Terms a real expert would use appear naturally (for dentist: hygienist, crown, enamel, anesthesia…) — coverage, not density |
| FAQ block | Real questions people ask the head term, answered concisely (feeds PAA/AI extraction; we already have the FAQ block + JSON-LD phase 2 parked) |
| Structure scannable | H2/H3 per subtopic, lists where enumerable — extractable by machines, skimmable by people |

### B. Local service page
| Check | What passes |
|---|---|
| Phone/contact above the fold | A phone number (click-to-call) or contact action visible without scrolling — winners make contact effortless |
| CTA above the fold | One clear action (call/book/quote) in the first screen |
| Service + place named early | H1/first paragraph name WHAT and WHERE ("Rörmokare i Västerås") — not clever, literal |
| USPs concrete | Differentiators stated as facts (24h, fast pris, X år, certifierad) — not adjectives |
| Reviews / social proof visible | Stars, counts, or quoted reviews on the page (mirrors the Local Pack's trust currency) |
| Trust signals | Years in business, certifications, guarantees, real staff/premises photos over stock |
| Human language, not academic | Short sentences, everyday words, direct address ("du"). A local plumber page written like a thesis fails — the owner's exact ruling |
| Service area stated | Cities/areas served, plainly |
| NAP consistency | Name/address/phone matching the GBP listing (we hold brand + GBP data — checkable) |

### C. Supporting / subtopic page
| Check | What passes |
|---|---|
| ONE question, answered immediately | The specific query is answered in the first paragraph; depth follows |
| Links UP to the pillar | Descriptive anchor to the category page (the spoke feeds the hub) |
| Long-tail focus, no cannibalization | Targets ITS phrase; does not compete with the pillar's head term |
| Depth over breadth | Covers its narrow topic exhaustively rather than re-summarizing the pillar |
| Natural language flow | Same human-language bar as B |

### D. Brand page
| Check | What passes |
|---|---|
| Brand identity early | Who the company is, what it does, for whom — first screen |
| People visible | Real staff with names/roles/faces — the E-E-A-T signal machines and humans both read |
| Differentiators | What is DIFFERENT about this brand, stated as facts |
| Credentials + proof | Certifications, memberships, awards, history |
| Contact + locations | Full NAP, easy to reach |

### E. Universal (every type, appended to all lists)
Answer intent at the top · no fluff intros · natural human flow (reads
aloud like speech) · headings state what sections contain · internal links
descriptive · no keyword stuffing.

## 2. How a check is evaluated

Each check row (data) = `{id, pageTypes[], label, testable question,
evaluator, instruction template}`. Two evaluator kinds:

- **Deterministic** (code, free, exact): phone pattern above the fold,
  H1 contains service+place tokens, FAQ block present, link count to
  supporting pages, NAP vs stored GBP data.
- **LLM-judged** (one call, whole checklist at once): language quality,
  intent answered early, subtopic coverage vs the expected branch list,
  USPs concrete vs vague. The LLM returns per-check `pass | warn | fail +
  a one-line EVIDENCE quote from the page` — evidence is what makes the
  result trustworthy, never a bare score.

## 3. GUI (consistent with the editor we have)

One **Analyze** button in the workbench row (blue AI family, beside
Optimize). Result = a right rail (the review rail's pattern): one row per
check — status dot (green/amber/red) + label + the evidence line. Failed/
warn checks come PRE-TICKED with a checkbox; passed checks render ticked-
off and quiet. Footer: **Optimize selected** → hands the ticked items to
the existing optimize flow → normal red/green review (no new write path,
review law untouched). Page type comes from the header dropdown the editor
already has.

## 4. Prompt integration

Each check carries an `instruction` template (data, editable): e.g.
above-fold-cta → "Place a clear call-to-action with the phone number in
the first section." Ticked items are appended to the optimize topic as a
short directive list — the SAME topic mechanism the instruction field and
Revise already use (zero new prompt plumbing). The AI output then lands as
red/green suggestions per section, exactly like today.

## 5. PART 2 — AI RECOMMENDATION OPTIMIZATION (owner spec 2026-07-13, confirmed)

Part 1 optimizes for search. Part 2 optimizes for being RECOMMENDED BY AI
assistants — by measuring what the AI actually recommends and why, then
translating that into honest changes on our own page.

### The workflow
1. **Simulate the real questions.** From the page + business context
   (restaurant, fish menu, New York), generate 3–5 questions people
   actually ask an AI: "best fish restaurant in NYC", "most
   recommended…", "which should I choose…", "…near me". Grounded in real
   demand: the page's primary/additional keywords (GSC drawer) feed the
   simulation.
2. **Run them for real.** Each question goes through the AI API **with
   web search enabled** — mimicking a genuine user asking an assistant.
   Each question runs MULTIPLE times (answers are non-deterministic;
   consistency only means something with repetition; results cached).
3. **Find the consistent winners.** Which businesses recur across answers
   and runs — the recurring top 3–5 are the winners.
4. **Weigh the citations — a GATE, not the driver.** The answers return
   their source URLs. Classify each: the competitor's OWN site vs
   third-party (listicles, aggregators, reviews). Compute a
   **controllability weight** per answer: heavily grounded in the
   winners' OWN pages = HIGH on-page opportunity (page copy demonstrably
   drives the recommendation — we control exactly that); mostly
   third-party = reported honestly as off-page reality with lower on-page
   leverage. From the cited sources extract only what we can act on
   ourselves: how the winners' brands are FRAMED and which
   services/products get NAMED.
5. **Read the winners' own pages.** Only what is written on their pages —
   the controllable surface (never backlinks): what they mention, how
   they phrase it, what they show.
6. **Extract commonalities and sanity-check.** The top ~5 things the
   winners share, then a reasoning pass: is it LOGICAL that this drives
   the AI's recommendation? Keep what holds up = what the AI likely
   prioritizes.
7. **The smart translation step — map to OUR brand.** Per winner-signal,
   against our brand data (brand registry, GBP):
   - Equivalent exists → use ours.
   - Missing the exact thing → substitute the UNDERLYING signal honestly
     (certification = recognized external validation of competence → use
     the validations we DO have).
   - Location mismatch → honest proximity phrasing ("guests from central
     New York travel to us").
   - Missing certification → truthful ambition phrasing ("working toward
     X") — NEVER claiming what we don't have.
   - HARD RULE: no dumb advice ("go get certified"), no lies — only what
     can go on the page TODAY, truthfully.
8. **Quotability recommendations always included.** AIs lift concise,
   factual, claim-shaped sentences — key facts stated as one-line
   quotable claims near the top.
9. **Deliver in the SAME checklist GUI as Part 1** (checkbox per
   recommendation → ticked items ride the optimize prompt → red/green
   review). Truth stays human-gated: every claim-type wording is approved
   by the user in the review before it serves.

### Baseline + tracking
Before optimizing: does the AI mention US at all for the question set?
That is the baseline. The same question set re-runs on schedule (the
automations engine) → a real KPI: brand mention rate, before vs after.

### Architecture note (build-once law)
Part 1 learns from SEARCH winners, Part 2 from AI-ANSWER winners — ONE
winner-analysis engine (fetch winners → extract signals → sanity-check →
map to our brand), two entry points. No duplicated pipeline.

## 6. Open validation points (owner)
- Page-type additions: confirm adding `category` + `brand` to the dropdown.
- Phase 1 reality-derivation needs a SERP fetch source (PRT integration
  exists per backlog #5) — confirm before that phase is specced.
- Checklist seeds above are the starting DATA — owner edits welcome; the
  derivation layer then tunes them per niche.
- Part 2 provider: web-search-enabled calls exist on our active providers;
  per-run cost is bounded (3–5 questions × N runs, cached) — pricing
  review before build.
