# Proposal: Strategy Types — RSS Autopilot + Configurable Research

**For approval — 2026-07-14** · *(supersedes the earlier research-sources draft)*

Two separate ideas came out of the discussion:
1. **RSS Autopilot** — a new *type* of strategy that posts automatically from live sources.
2. **Research** — make the existing background research a per-strategy checklist you can tune.

---

## Big idea: strategies have a TYPE

When creating a strategy, you first pick its **type**. Same engine underneath, different trigger:

| Type | Trigger | Use |
|---|---|---|
| **Keyword** (today) | You pick keywords → generate | Manual, planned content |
| **Scheduled** (built) | A cadence + AI topic ideas → auto-create | Steady drip on a niche |
| **RSS Autopilot** *(new)* | A new item in a watched feed → write & publish | Ride news/trends automatically, no editor |

---

## Part A — RSS Autopilot (the main ask)

**What it does:** watch 1–many RSS feeds. When a new post appears, write a **better** article on that topic (tailored to a primary keyword) and **publish it live** — automatically. Jack onto news sites and trends so you're out the same hour, without a human editor.

**Setup (per strategy):**
- **Sources** — 1–5 feed URLs (competitors, news, industry blogs).
- **Primary keyword / angle** — so every generated post is tailored to what you want to rank for.
- **Cadence** — posts per week + minimum gap between posts.
- **Publish mode** — live (auto), or draft for review.

**The key rule — cadence backpressure:** if feeds bring items faster than your cadence, we **don't dump them all**. We publish at most your set rate and **wait for the next slot**; extra items queue (freshest first) or are skipped. So a busy news feed can't flood your site.

**How it works (reuses what's already built):** a background scan (same cron machinery as the Scheduled type) checks each feed → detects genuinely new items (tracks seen items so nothing is rewritten twice) → if a posting slot is due, generates one article from the freshest item, tailored to the keyword → auto-publishes via the existing publish pipeline.

**Why it's valuable:** near-real-time, hands-off news/trend coverage — the same-second publishing advantage of a big newsroom, automated.

---

## Part B — Research as a checklist (separate)

Background research (used by *any* strategy when writing) becomes **configurable per strategy** instead of the opaque Standard/Deep levels:

**Pick which passes to run** (the three we already have):
- [ ] **Search landscape** — what currently ranks
- [ ] **Questions & data** — real questions + statistics (chart-ready)
- [ ] **Competitor gaps** — what rivals miss

**Tune the depth** — how many results/sources each pass should pull.

So the user chooses "just landscape, shallow" or "all three, deep" — and sees exactly what research is doing.

---

## How they relate
- **RSS Autopilot = trigger + auto-publish** (where content comes from and when it goes live).
- **Research checklist = quality of writing** (what the writer reads before drafting).
- An RSS Autopilot strategy can *also* use the research checklist to make each rewrite richer.

---

## Decisions needed
1. **RSS Autopilot cadence when feeds are fast:** queue extras (freshest-first) for later slots, or just skip them? *(Rec: queue freshest, cap the queue.)*
2. **Default publish mode for Autopilot:** live, or draft-first until trusted? *(Rec: draft-first default, live opt-in.)*
3. **Strategy-type UI:** a type picker as the first step of "Create Strategy" — agree?
4. **Research:** the 3-pass checklist + depth control as described — agree?

---

## Scope notes
- Additive config on the existing strategy (type, feeds, cadence, research toggles) — no new tables expected.
- Reuses existing cron, generation, and publish machinery; the new part is feed-watching + backpressure.
- No change to *how* articles are written — only the trigger (Autopilot) and the research inputs (checklist).

*On approval, this becomes a routed build plan — RSS Autopilot as its own phase (feed watcher → backpressure → generate → publish), research checklist as a smaller separate phase.*
