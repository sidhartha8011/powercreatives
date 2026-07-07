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
