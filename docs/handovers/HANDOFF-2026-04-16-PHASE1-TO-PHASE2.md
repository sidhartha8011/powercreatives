# Överlämning: Writer Module AI Engine (Phase 1 → Phase 2)

Agera som #1 Senior Developer. Du tar över ett pågående projekt.
Nedan finns exakt lägesbild.

## 1. VAD HAR GJORTS (senaste sessionen)
- **UniqueID Konfiguration:** Implementerat `@tiptap/extension-unique-id` för att stämpla unika ID:n på alla block-noder (heading, paragraph, list, image) för stabil AST-mappning.
- **AST Serializer:** Rak och typ-säker serialisering (`editorContextService.ts`) som omvandlar ProseMirror-noder till JSON-objekt för LLM-kontext.
- **Diff Engine & Offset Rescue:** Byggt `diffApplicator.ts` som tar emot AI-instruktioner (`replace`, `insert_after`, `delete`) och modifierar dokumentet via *atomära ProseMirror Transactions* (ej smutsig DOM-manipulation). Klarar av offset-förskjutningar vid multipla diffar.
- **AiSuggestionMark:** Skapat en custom Tiptap Mark för inline diff-rendering (`.ai-diff-deletion` röd, `.ai-diff-insertion` grön) synkad mot designtokens i `index.css`.
- **Prestanda & State:** Optimerat editorn med `shouldRerenderOnTransaction: false` för att undvika frysningar vid stora AI-diffar. Verifierat att BubbleMenu-stater överlever detta.
- **Scope Clarification & Backlog:** Rättat ett missförstånd kring UX. Planen korrigerades från "ändringar via befintlig BubbleMenu" till den originallika beställningen: "Floating AI Chat Bubble + IDE-stil Accept/Reject". Dokumenterade alltsammans i detalj i `HANDOFF-WRITER-PHASE2.md` och uppdaterade `backlog.md`.

## 2. NULÄGE
- **Commit:** `88504178 "2026-04-16 09:37 BEFORE IMPLEMENTATION OF Writer UI chat Bubble"`
- **Build:** PASS (inga TS eller ESLint errors införda).
- **Verifierat av användaren:** JA (infrastrukturen verifierades via manuella DOM/console-tester). Notera dock att funktionaliteten är "headless" fram till nästa steg.
- **Kända kvarvarande issues:**
  - `listItem`-noder saknar för närvarande UniqueIds (vissa list-operationer ignoreras av AI:n tills vidare). Kan uppdateras enkelt i `editorExtensions.ts`.
- **Pre-existing tech debt som berör detta arbete:**
  - FileHandler-extension finns registrerad i koden, men saknar reell filuppladdningslogik till WP Media Library (console.loggar bara).

## 3. ÄNDRADE FILER (referens)

| Fil | Ändring |
|-----|---------|
| `index.css` | Lade till CSS custom properties för `.ai-diff-insertion` och `.ai-diff-deletion`. |
| `AiSuggestionMark.ts` | Byggde extensionen för att rendera diff-marks clean. |
| `diffApplicator.ts` | Huvudmotorn för att omvandla JSON-diffar till ProseMirror dispatches. |
| `editorContextService.ts` | Skapade `serializeAst` för att skicka dokumentstruktur till backend. |
| `editorExtensions.ts` | Registrerade UniqueID och rensade bort gammal tech-debt. |
| `ReviewEditorCanvas.tsx` | Satte `shouldRerenderOnTransaction: false`. |
| `docs/backlog.md` | Uppdaterade backlogen med det kompletta "✍️ Writer Module — AI Track Changes"-blocket. |
| `docs/HANDOFF-WRITER-PHASE2.md` | Skapade en massiv, djupgående tech-spec över nästa steg. |

## 4. ARKITEKTURKRITISKA BESLUT
- **Atomära Transactions över DOM-hacks:** Alla AI-ändringar appliceras med Tiptaps `editor.state.tr` (ProseMirror transactions). Detta säkerställer att Undo-history inte korrumperas och håller React-state synkat.
- **Semantiska Diff Marks:** Färgkodning görs med klasser och CSS Variables, inga inline hex-koder lagras i dokumentets HTML.
- **Zero-rerender Editor:** För att klara snabba, iterativa renderingar av AI-text tog vi bort Reacts bindning till varje tangenttryck/transaction.
- **Floating Chat istället för BubbleMenu:** UX-beslut tagit med Product Owner. Ändringarna initieras från en flytande chattbubbla (vid textmarkering) istället för från ikoner i den vanliga editormenyn.

ngenjörsprinciper (gäller ALLA steg)
 100% ren kod — senior dev-lösning, inga hacks, inga quick-fixes
 Max 400 rader per fil — bryt upp i moduler vid behov
 Inga dupliceringar (DRY) — återanvänd befintliga funktioner, hooks och komponenter
 Globala värden — design tokens, konstanter, typografi-variabler från vår design system
 Alltid opt-in för befintliga libraries — Tiptap extensions, Radix UI, etc. framför egna lösningar
 Följ projektets brand/design system — colors, typography, spacing tokens genomgående
 Beskrivande kommentarer i all kod
 Backend-filer max 400 rader — controller + service-separation per arkitektur



## 5. NÄSTA STEG (prioritetsordning)
Steg 3: Bildhantering i Editorn
 Copy-paste bilder från webbläsare direkt in i editorn
 AI-genererade bilder — generera bilder direkt i dokumentet via generatorn
 Bilder renderas inline i Tiptap (via Image-extension)
Steg 4: Publicera Full Artikel till WordPress
 Pusha komplett artikel till WP (via WP REST API / wp_insert_post)
 Allt följer med: text, formatering, bilder, länkar, tabeller, punktlistor, numrerade listor
 Bilder laddas upp till WP Media Library och länkas korrekt
 HTML-output matchar WordPress-format
Steg 5. AI Writer Editor Chat Bubble:** (Steg 5 i överlämningsdokumentet). Kärnan i Phase 2. Bygg en floating chat (återanvänd `<AIChatBox>`), koppla backend-tRPC-route `writer.inlineAiEdit` (för att ta emot AST + promt), samt skapa Accept/Reject inline-UI för att validera Marks.

## 6. INSTRUKTIONER TILL DIG
- Läs `ARCHITECTURE.md` innan du börjar.
- **LÄS NOGA:** `docs/HANDOFF-WRITER-PHASE2.md` — All info om den nya UI Chat-bubblan, återanvändning och uppsättning finns beskriven i detalj där. Du behöver inte forska, bara koda.
- Läs befintliga workflows i `.agents/workflows/`.
- Följ användarens regler: inga snabbfixar, ingen död kod, dynamiska lösningar. Skapa inga nya chat-komponenter i onödan (återanvänd `AIChatBox`).
- Alla ändringar MÅSTE byggas (`npm run build`) innan de presenteras.
- Git commit FÖRE och EFTER implementation.
