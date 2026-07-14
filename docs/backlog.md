## Backlog

- [x] Refaktorering av trpc.ts: Bröt ut ROUTE_MAP (472 rader) till dedikerad `trpc-routes.ts` config-fil. Raderade 12 duplicerade routes (articles.* och prompts.* med 0 konsumenter). Fixade oanvänd `useAtomValue`-import i useWriterPersistence. trpc.ts gick från 788 → 219 rader. [2026-05-30]
- [x] Writer DB-persistens: Koppla ihop Jotai in-memory store med WordPress REST API via ny `useWriterPersistence`-hook (debounced autosave, load on mount, create/delete sync) + ny `POST /articles` CREATE-endpoint + `writer.create` tRPC-route. Artiklar sparas nu i databasen och överlever page refresh. [2026-05-30]
- [x] Approvals articles typsäkerhet: Lade till `articles?: SnapshotAsset[]` i snapshot-typ, `approvedArticleIds?: string[]` i reviewFeedback-typ, uppdaterade PHP `update_snapshot_asset()` att söka i articles-array, och lade till `approvedArticleIds`-sanitering i `submit_review()` + `save_review_draft()`. [2026-05-30]
- [x] Fixade dead "Copy Link"-knapp i Writer — lade till onClick-handler som kopierar URL till clipboard med toast-notifikation. [2026-05-30]
- [x] Ads-modul prompt-editor: Lade till egen "Ads"-flik i Settings → Default Prompts med 7 redigerbara prompts (Ad Copy System/User, Organic, Angle Generation, Audience Generation, Audience Research, Audience-Aware Angles). Eget `ads.*`-namespace i DB med fallback till Copy-prompts. Propagerad `module`-parameter genom hela Copy-servicekedjan (resolve_audiences, resolve_angles, build_copy_system_prompt, get_system_prompt). Seedning vid plugin-aktivering. [2026-05-31]
- [x] Separerad `{{creativeBrief}}` som eget placeholder: Creative brief var tidigare begravd i `{{brief}}` bland business-metadata. Nu har den en egen `CREATIVE BRIEF`-sektion med explicit instruktion att följa den. Tillagd i alla 6 prompt-templates (ads, organic, angle_generation, audience_generation, audience_research, angle_generation_with_audiences). Borttagen ur `extract_brief()`. Placeholder-beskrivningar uppdaterade i Settings UI. [2026-05-31]
- [x] Declarative Placeholder Registry & Idempotent Migration: `PCM_Prompt_Placeholders` registry (transient mutex, per-row try/catch, 3-anchor injection covering copy+ads, `[PCM_PROMPT_SYNC]` logging), `maybe_upgrade()` v1.12.0 step, secure `POST /prompts/sync-placeholders` endpoint (inherits manage_options+nonce), "Sync to latest" button in Settings → Prompt Editor (toasts patched/skipped/failed metrics + query invalidation), and conditional brief/tonality clear in Copy template flow — `lastAppliedBriefRef`/`lastAppliedTonalityRef` track template-applied values so manual edits survive template clear (`buildTemplateClearUpdates` skips fields when modified). [2026-05-31]
- [x] Copy Framework (mall-driven, dropdown-vald, egen placeholder): Nytt `framework` mall-kategori i `templateTypes.ts` (visas automatiskt i TemplateDialog + TemplateRow). Ny Framework-dropdown i Copy-sidebaren (efter Creative Brief) som visar **endast namn** — fylls av inbyggt bibliotek (`frameworks.ts`: Hook-Story-Offer, Epiphany Bridge, Emotion-Speed-Fire, AIDA, PAS, destillerat från docs/modules/copy/) + ev. mall-tillagda ramverk. Valt ramverks text injiceras via egen `{{copyFramework}}` placeholder i system_prompt_ads + system_prompt_organic (mellan CREATIVE BRIEF och OUTPUT FORMAT) — Creative Brief förblir ren. Speglar `creativeBrief`-mönstret: `$vars['copyFramework']` i `build_copy_system_prompt`, exkluderat från angle/audience-prompts. `copyFramework_system` INJECTIONS-entry + DB-bump 1.13.0 → `sync_all()` patchar befintliga prompts (idempotent). Villkorlig rensning: ramverk från mall rensas vid mall-clear om inte användaren ändrat det manuellt (`lastAppliedFrameworkRef` + `frameworkWasModified`). Ads konsumerar automatiskt via delad `/copy/generate` + `['copy','ads']`-injection. [2026-06-01]
- [ ] Heartbeat-baserad realtidsuppdatering av Approvals kanban-kort (planerat i HANDOVER-20260529 §2.1, BEFORE checkpoint 2578dbb).
- [ ] WordPress-publicering via `wp_insert_post()` (kräver Writer DB-persistens som nu är klar).
- [ ] Writer Phase 2 AI Chat Bubble (WriterAiChatBubble, useWriterAiChat, AiSuggestionActions, suggestionResolver).
- [x] Multi-Artikel urval och approvals-paketering i Content Writer — val av flera dokument i kön med checkboxar, Select All / Clear, och paketering i delad Client Review Board med fullständig typsäkerhet och useMemo-optimeringar. [2026-05-29]
- [x] Ändra copy-kort till 1-klick-editering (ta bort dubbelklick + expand-mellansteg), alltid fullt expanderade (visar all text), och oberoende korthöjder (grid align-items: start). [2026-05-28]
- [x] Fixa view/edit parity för titel och description i copy-kort — wrapper gap, margin, och browser input-defaults matchade inte view mode. [2026-05-28]
- [x] Fixa view/edit text parity bugg i copy-kort på klientgranskningssidan — texten ändrade utseende vid dubbelklick-editering p.g.a. CSS-diskrepanser (font-family saknade Inter, white-space och word-break saknades i edit mode). [2026-05-28]
- [x] Byt klientgranskningssidans bakgrund från kliniskt vit (#ffffff) till varm cream (#faf8f5) och ändra glow-blobbarna från kalla blå till varma amber/sand/honey-toner som harmoniserar med ink-paletten. [2026-05-27]
- [x] Flytta progress bar ("4 of 5 approved") och status-pille ("Awaiting your review") från sticky toolbar till hero-sektionen. Ta bort "Draft saved"-indikatorn. Resulterar i en renare, tunnare toolbar med bara flikar, deadline och approve-knapp. [2026-05-27]
- [x] Ersätt den synliga audience/angle-metadata-texten på copy-kort med en diskret ℹ️-ikon i övre högra hörnet med CSS-only tooltip vid hover. Renare annonsläsning utan att förlora metadata-åtkomst. [2026-05-27]
- [x] Isolera klientgranskningssidans designsystem från admin-SPA:n. Skapade fristående `client-review.css` (650+ rader) med komplett CSS-foundation, admin-reset, design-tokens (--ink-palett), namngiven type scale och alla komponentklasser. Migrerade 4 komponenter (ClientReviewPage, CreativeAssetCard, ClientStatusToolbar, ClientCommentInspector) från Tailwind-utilities + inline `<style>` (852 rader) till exklusivt pcm- klasser. Eliminerade 16+ typografi- och färgdiskrepanser orsakade av admin-arv. [2026-05-26]
- [x] Implementera urklipps-bildinklistring (Clipboard Screenshot Paste) i kommentarsfältet med automatisk frontend-JPEG-komprimering och skärmdums-bilagor (attachments) sparade säkert i JSON-kommentars-trådar, komplett med lightbox-förhandsvisning. [2026-05-26]
- [x] Putsat kommentarsfältets hierarki, typsnittsfärger, avstånd och storlekar till pixel-perfektion med softa tonade status-badges (transparenta RGBA-ytor) och minimalistiska inline-åtgärder. [2026-05-26]
- [x] Eliminera den separata kommentarsrutan ovanför åtgärdsraden och integrera kommentarstatistik samt pulserande notifikationsindikator (blå `#2563eb` prick + glödande sifferbubbla) direkt i "Comment"-knappen för en extremt ren och premium UX-design. [2026-05-26]
- [x] Polera typsnitt, färghierarki och kontrast (typografi-polering) på klientgranskningssidans kommentarer och tillgångar till en extremt sofistikerad, harmonisk och balanserad premiumdesign. [2026-05-26]
- [x] Konvertera "Ladda ner"-knappen på mediakort till en helt transparent cirkulär ikonknapp utan textetikett eller bakgrundsfärg, grupperad med kopieringsknappen för högsta kodkvalitet. [2026-05-26]
- [x] Konvertera "Kopiera text"-knappen på kopieringskort till en ren cirkulär ikonknapp utan textetikett för att förhindra textbrytning och förbättra den visuella harmonin i åtgärdsraden. [2026-05-26]
- [x] Uppgradera kommentarsystemet från en enda textsträng per tillgång till ett riktigt flertrådigt kommentarsystem med obegränsade kommentarer, författaridentitet, tidsstämplar, trådning via svar, och per-kommentar-status (New/Team reply/Done). Backend och frontend har migrerats med bakåtkompatibilitet för äldre data. [2026-05-26]
- [x] Implementera den minimalistiska, glassmorfiska sidopanels-kommentarsinspektorn (Glass-Sheet Inspector) i Light Mode, fri från profilbilder och med integrerade statuslägen (New, Team reply, Done) synkade i realtid mot databasen. [2026-05-26]
- [x] Testa klientgranskningssidan med samma rena vita bakgrund och tre flytande omgivande blå lystergradienter som inloggningsskärmen, samtidigt som de ursprungliga gradienterna bevaras inuti kommentarblock. [2026-05-25]
- [x] Ta bort det Notion-liknande brödsmulefältet (Studio / Clients / ...) helt från klientgranskningssidan för att bibehålla en minimalistisk, Apple-esque design utan onödiga layoutskiftningar. [2026-05-25]
- [x] Unifiera kopieringskortets typografi med granskningssidans globala klasser (pcm-copy-headline och pcm-copy-text) för rubrik och beskrivning för att eliminera alla hårdkodade Tailwind-stilar. [2026-05-24]
- [x] Uppdatera och polera inline-textredigeringen för kopieringskort till en pixel-perfekt Facebook Link Preview-overlay med stöd för beskrivning (description) och direktklick-fokus på enskilda textelement. [2026-05-24]
- [x] Lägg till inline-textredigering (rubrik via text-input, brödtext via TiptapBodyEditor) och automatisk sparning vid klick utanför för kopieringskort på klientgranskningssidan, med DB-synk och gränssnittsuppdatering för inloggade teammedlemmar. [2026-05-24]
- [ ] Lär upp AI URL Scraper i backend att identifiera och hämta ISO-certifikat och Trust Badges automatiskt när man fetchar en hemsida. Detta är Phase 2 av Certifikat-migrationen.
- [x] Fixa problem med klientdelningsflöde i Ads-modulen (åtgärda urklippskopiering och ogiltig delningslänk/WordPress 404). [2026-05-23]
- [x] Fixa bugg i Ads-modulen där varumärkets logotyp inte skickades med vid bildgenerering (synkronisera logotypen och referensbilder i Ads-sidofältets livscykel). [2026-05-23]
- [x] Fixa bugg i Ads-modulen där valda antal publiker och varianter inte respekterades av backend-generatorn (enforce och slica AI-responser till exakta valda antal). [2026-05-23]
- [x] Polera och förfina Ads-modulen: åtgärda TypeScript-typfelskontrollfel, rensa oanvända importer och argument, samt implementera en interaktiv avbrytningsfunktion för generering. [2026-05-23]
- [x] Aliniera och unifiera Ads-modulens copywriting och bildgenerering (AI Enhance, Angles-slider och orchestrator hook). [2026-05-23]
- [x] Konvertera SVG-logotyper till PNG vid lagring (Imagick rasterisering). AI-bildmodeller kan inte bearbeta SVG-vektorfiler. Automatisk konvertering vid uppladdning och URL-hämtning + v1.7.0 databas-migration för befintliga SVG-tillgångar. [2026-05-23]
- [x] Fixa saknad `role`-parameter i referensbilds-sparning. Fetch Brand och ReferenceImageSelector skickade inte `role: 'reference'` till backend, vilket gjorde att alla referensbilder tyst avvisades med 400 "Asset role is required." [2026-05-23]

## Autonomy roadmap (added 2026-07-07 — agreed with owner, ordered by agency leverage)
Goal: AI produces → client approves per change → platform executes and serves
autonomously → feedback returns machine-readable. Dependency order 1→4; each item
was specced in detail in the 2026-07-07 session (SESSION_LOG + chat).

- [ ] 1. **Approval-pipeline rails** (difficulty ~4–5/10, ~5 pairs). SEO "hold edits
  for approval" toggle → staged changes stored in a NEW `seo_staged_changes` table
  (site, post, column, before, after, status staged→pending→approved/rejected→
  applied/failed; reject = MARKED, never discarded). "Send for approval" hands over a
  generic envelope `{type, tags:{siteId,projectId,deliveryId,brandId}, items per page}`.
  Approvals module gains an ASSET-TYPE ADAPTER REGISTRY (no hardcoding either way —
  same registry pattern as automations): adapter = {type, toSnapshotItems, renderer,
  onItemApproved}. New Before/After card: one card per PAGE, one row per changed
  column. Apply-on-approve handler registered on the EXISTING
  `approvals.asset_approved` trigger (fires on the approve transition, runs under the
  set owner's identity — verified in service.php:1163); executes via existing
  save_cell/remote_save_cell; rows marked applied/failed; value-drift default =
  apply + keep recorded before-value as audit. Site resolves from project via
  PCM_Hierarchy::site_for_project.
- [ ] 2. **Per-change client comments.** Stable changeId per row inside a card;
  comments optionally carry assetId+changeId → feedback machine-mapped to the exact
  change (the AI-revision-loop data contract). Small; extends rails.
- [ ] 3. **Dynamic optimization layer + licensing (connector).** Render-time output
  buffer in the connector serving approved changes in final HTML — builder-agnostic
  (NO per-builder tailoring; that problem belongs only to source-edits). V1 routing:
  PARAGRAPHS dynamic; headings + reachable links keep the proven source-edit path
  (2.2.x) — engine designed heading/link-capable so migration later is a routing
  change; `editable:false` rendered-only links = first legitimate dynamic consumer
  (closes a real gap). Text-tolerant matching; failed match serves original + FLAGS
  (no silent fallback). On/off switches per change / per page / per site (kill
  switch); three-state indicator original/optimized/STALE (source drifted → serving
  original, flagged). Rules pushed to + stored ON the connector (site independent of
  platform uptime); daily license heartbeat, grace window (~14d) then rules
  auto-deactivate to originals (fail-closed-and-harmless); immediate deactivate on
  explicit revoke; hub-side license/revoke surface. AI-crawler rationale: AI bots
  (GPTBot/ClaudeBot/PerplexityBot) do NOT execute JS — server-side serving is the
  only dynamic optimization they can see (JS-pixel products can't match this).
- [ ] 4. **AI page optimizer.** Bulk action "Generate optimized page content": AI
  reads page + top-3 SERP competitors + structure/interlink gaps → per-paragraph +
  per-heading before/after (old = light gray, new = black) through the SAME envelope/
  adapter pipeline (second producer type `contentRevision`); send-time choice of one
  card per page or per change; review-only until a content-apply path exists (new
  interlinks ride paragraph rewrites). Needs 1 (and 3 for auto-apply).
- [ ] 5. **SEO SERP-check row action.** Opens live Google search for the row's
  primary keyword in a NEW TAB (Google cannot be iframed — X-Frame-Options; in-app
  SERPs would need a SERP API, e.g. the existing PRT integration). Minutes of work;
  was dropped from the 2026-07-07 batch per owner (no SEO table changes then).
- [ ] 6. **Delivery health fishbone.** Per-delivery health provider registry in the
  deliveries module (consumer modules register evaluators per delivery TYPE);
  `health:{status: green|yellow|red|unknown, reason}` on the list; UNKNOWN renders
  neutral "Not tracked" — never fake green. Gray-dot socket only; per-type evaluators
  (SEO/ads/web) are separate approvals. Deferred 2026-07-07.
- [ ] 7. **Email relay / comms-in-platform (ON HOLD per owner).** Brevo Inbound
  Parsing → webhook → conversations/messages tables linked to brand/delivery;
  replies out via Brevo from a shared address (threading via Message-ID/References);
  assignable conversations; notifications via automations engine. Helpdesk-scale
  build — buy-vs-build (Front/Missive) decision first; if built, start with a
  read-only inbound proof on the delivery card.
- [ ] 8. **Articles ↔ Projects linking decision** — until decided, the delivery
  card/table Articles column stays an honest "—" (articles link to sites only).

## Typography spaghetti cleanup (added 2026-07-07)
Fonts are set in 8 systems (~1,265 declarations): wp-admin CSS (81, neutralized by the
`@import "tailwindcss" important` flag), index.css (39 font-size rules + legacy
`font-family !important` hacks now redundant), 38 ui-primitive defaults, 1,096 inline
`text-*` classes, design-tokens.ts, CSS modules, cardTokens.ts. Cleanup, in order:
1. App-wide semantic type tokens in `@theme` (index.css) — names describe roles, values
   live in exactly one place (card tokens already do this pattern).
2. index.css: delete redundant font-family !important hacks; fold the 39 font-size rules
   into @theme tokens or delete.
3. ui primitives (Input/Select/Button/Badge/DropdownMenu/Table) consume theme tokens
   instead of hardcoded text-sm/text-xs.
4. The 1,096 inline classes: opportunistic only — convert when a file is touched anyway,
   NEVER as a big sweep.
5. Lock: lint rule banning raw px text classes outside token files + screenshot test on
   the delivery card + one Kanban board.
Safe stopping point after every step. Context: CHANGELOG-20260707-0645 (layer war),
SESSION_LOG 2026-07-07 entries.

## Content analysis — reality-based, page-type-aware checklists (owner order 2026-07-13, research first, TWO PARTS)
The page editor gains an ANALYZE step: the content is evaluated against
what ACTUALLY ranks — never generic SEO-tool "best practices based on
nothing". PART 1 (search): checklists PER PAGE TYPE (at least:
category/knowledge page e.g. "dentist" needing topical/knowledge-graph
completion with subtopics; local service page prioritizing above-the-fold
contact/phone/CTA/USP/reviews and simple human language — never academic;
supporting/subtopic page; brand page carrying brand info/people/staff/
differentiators; general as fallback). Checklists live as HUB-CONTROLLED
DATA (editable, never hardcoded), keyed off the EXISTING Page-type
dropdown. PART 2 (AI recommendations, owner spec confirmed 2026-07-13):
simulate the 3–5 questions a person would ask an AI assistant → run them
FOR REAL via web-search-enabled API calls, multiple runs each → find the
consistently recommended winners → CITATIONS AS A GATE not the driver
(classify sources: competitor's OWN site vs third-party; controllability
weight — answers grounded in winners' own pages = HIGH on-page
opportunity) → read the winners' own pages (the only controllable
surface) → extract + sanity-check commonalities → map to OUR brand
honestly (underlying-signal substitution, proximity/ambition phrasing,
never claims we can't back, no dumb "go get certified" advice) →
quotable one-line facts always recommended → baseline + scheduled
re-runs = brand mention rate KPI. ONE winner-analysis engine feeds both
parts. FLOW (both parts): Analyze button → per-check results (pass/fail +
evidence) as a checklist with CHECKBOXES → ticked items ride the existing
optimization prompt → the normal red/green review. RESEARCH delivered:
docs/RESEARCH-CONTENT-ANALYSIS-20260713.md (checklist seeds, evaluators,
reality-derivation, Part 2 workflow, GUI, prompt wiring).

## GSC keyword drawer in the page editor (owner order 2026-07-13)
A LEFT-side drawer in the page editor modal, driven by the existing GSC
integration. TOP: the page's PRIMARY keyword + SUPPORTING keywords (read
from the SEO table; settable right here when unset) + an ADDITIONAL
KEYWORDS bucket that fills as the user clicks + on rows below. CONTROLS:
searchable page dropdown listing every GSC-indexed page (default = the
CURRENT page when GSC knows it, else the primary domain) · days-back input
(default 30) · a "related only" checkbox that hides keywords unrelated to
the primary keyword. SCAN: scans the whole domain for keywords RELATED to
the primary keyword that the site ALREADY ranks for — surfacing proven
potential, not guesses. TABLE (reuse THE existing shared table component,
compacted — never reinvent): columns keyword | clicks | impressions |
position; scrollable; per-column sort + filter; DEFAULT sorted by
impressions desc with a noise filter (~impressions ≥ 100, user-removable).
Workflow the layout serves: sort by position, read impressions → a page-2
keyword with 1,000 impressions = the opportunity; click + → it joins the
additional-keywords bucket for this page/site and feeds optimization
prompts.

## THE ONE-CLICK OPTIMIZATION SPINE — MVP consolidation of the 2026-07-13 batch (owner session 2026-07-13)
The owner's goal sentence IS the architecture: ONE Analyze → tick what you
want → ONE Optimize click → red/green review. Four of the five features
are producers of TICKABLE ITEMS for that single flow — build the spine
once, each feature plugs in. Value driver + MVP cut per feature:
1. **Content Analysis P1 = THE SPINE (build first).** Value: the optimize
   prompt stops being generic — directed by page-type-specific gaps. MVP:
   checklists as data + one LLM analysis (pass/fail + evidence) +
   checkboxes → prompt. Defer: reality-derivation, deterministic
   evaluators.
2. **Content Analysis P2.** Value: what the AI actually recommends + the
   honest brand mapping. MVP: 3–5 web-search questions → winners →
   recommendations as ANOTHER check group in the same rail. Defer:
   multi-run sampling, citation weighting, mention-rate KPI.
3. **GSC drawer.** Value: proven-demand keywords riding EVERY optimize run
   automatically. MVP: this page's own GSC keywords + the + bucket +
   auto-inclusion. Defer: domain-wide related scan, page-switcher.
4. **Knowledge-graph insert.** The value is SUBTOPIC COVERAGE, not the
   button: MVP = a check in the Analyze rail ("missing subtopics X/Y/Z —
   tick to add a coverage section"). No separate insert machinery. Defer:
   link-mode (needs 5's machinery).
5. **Interlinking.** Value: in-context links FROM this page chosen by what
   targets actually rank for (GSC), each an accept/reject item. Defer:
   the reverse direction (links TO this page from other pages).
**Build order by value: 1 → 3 → 4 → 6 → 5 → 2.**

## Client approval card for page changes (owner order 2026-07-13 — feature 6)
Value driver: THE CLIENT SEES THE SAME RED/GREEN VIEW AND APPROVES. MVP: a
shareable READ-ONLY link rendering this page's pending changes exactly as
the editor shows them (red out, green in, per section) + Approve/Comment —
reusing the client-review-board primitives that already exist. BUILD LAW:
this is the FIRST SLICE of the already-specced approval-pipeline rails
(Autonomy roadmap #1: seo_staged_changes + asset-type adapter registry +
Before/After cards) — never a parallel machinery; nothing thrown away when
the full rails land.

## Knowledge-graph insert (owner-approved spec 2026-07-13)
A new Insert-menu item (beside Image and FAQ): insert a SUBTOPIC section
for the page's topic — e.g. a "dentist" page gains a section covering 3–5
subtopics of dentistry so the page covers the topic's important branches
(placeable at the bottom as "Interested in reading more about…?").
APPROVED IMPROVEMENTS: two modes chosen automatically PER SUBTOPIC — a
supporting page EXISTS → short teaser + real link ("read more"); it does
NOT exist → a short content block that actually SAYS something about the
subtopic (bare link lists rank nothing; links to nowhere are impossible —
NO dead links ever) and the missing subtopic is flagged as a
supporting-page IDEA (a free content plan). Subtopics derive from REALITY,
not invention: what winning pages for the term cover + what the site
already surfaces for in GSC — reuses the winner-analysis engine and the
keyword drawer (one machinery, three consumers). This insert is also the
FIX ACTION for Content Analysis Part 1's "subtopic coverage" check.
Generated content rides the red/green review like everything else.

## Interlinking — Add Interlinks (owner-approved spec 2026-07-13)
An "Add Interlinks" button beside Optimize. Owner core: scan the content
minding the primary keyword (+ the other keywords found in it), look at
all other site pages, find the relevant ones, build the linking strategy
from this page — primarily ranking THIS page. APPROVED IMPROVEMENTS —
the feature works BOTH DIRECTIONS (the honest SEO fact: outbound internal
links mainly lift their TARGETS; what raises THIS page is links TO it):
(1) FROM this page — natural phrases already in the text that match other
pages' topics get linked IN PLACE (in-context links, never a bolted-on
link list); (2) TO this page — scan the OTHER pages for mentions of this
page's primary keyword and propose links FROM them TO here (the half that
ranks this page; rides the same per-page rule machinery we already have).
Relevance decided by what each page ACTUALLY ranks for (GSC data — Google
already told us the association), not text-similarity guesses; flags
keyword cannibalization (two pages competing for one term) instead of
cross-linking blindly. Linking laws: descriptive anchors, one link per
target per page, a stuffing cap, never self-link. EVERY proposed link is
an accept/reject item in the red/green review; all reversible via
versions.

## Ask AI — the document advisor (owner-parked 2026-07-13)
Ask AI returns as its OWN feature (not the optimize instruction field): a
side panel where the user asks about the page ("why should this be
optimized?", "what's weak for local search?") and the AI ANSWERS with
analysis/advice — nothing touches the document. Each answer carries an
"Apply as optimize instruction" action handing off to the existing red/green
review. Mental model: Optimize = hands; Ask AI = advisor that can hand off
to the hands. Lean build: one endpoint reusing the existing prompt/LLM
plumbing (page content + business/page-type context already injected), one
panel component; review machinery untouched.
