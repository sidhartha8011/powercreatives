# Implementation Roadmap: Content Strategy, Workflows & Writer

*Detta dokument är en Senior Dev-reiteration och teknisk ritning ("fiskben") över designen för PowerCreatives v2.*

## Vision & Kravuppfattning

Vi tar de kärnkoncept som fanns i AutoPress Intelligence (Content Strategy och Prompt Builder/Workflows) och bygger om dem från grunden. Målet är att massproducera skräddarsytt content baserat på ett specifikt *brand* och strikta *templates*, men med **mycket högre dynamik och väsentligt lägre teknisk komplexitet** än i den gamla appen.

I AutoPress var dessa system hårdkopplade, tröga att underhålla och svåra att skala.

Detta dokument beskriver det tekniska "fiskbenet" (infrastrukturen) för dessa moduler i PowerCreatives V2. Arkitekturen bygger på "Lego-principen": Tjänsterna agerar isolerat via interna API:er. Systemet har färre rörliga delar men möjliggör fler konfigurationer, är lättare att underhålla, snabbare att exekvera och smidigare att jobba med i UI:t.

Detta uppnår en "plug-and-play"-arkitektur där du kan addera nya AI-modeller, ny research-logik eller nya publiceringsflöden utan att riva upp hela appen.

---

## AutoPress Intelligence (Vad vi lämnar bakom oss)

För att säkerställa att vi inte tappar funktionalitet från AutoPress:

**Deras Workflows (Prompt Builder):**
- **In:** Hardcodade steg (`site_context`, `extraction`, `generation`, `media_creator`, `assembly`).
- **In (Config):** Skrapnings-URL:er, Google SERP limits, prompt-text (`{{variables}}`), bildantal, osv.
- **Ut:** JSON, raw markdown, eller objekt med mediefiler (`generatedMedia`).
- **Problemet:** Arkitekturen var hårt knuten till UI-staten. Tjänsterna hade enorma `if/else`-block för varje enskild AI-provider, vilket gjorde modulen omöjlig att skala.

**Deras Strategy:**
- **In:** Keywords (term, volume, difficulty), site ID (för publicering), Prompt ID, Frekvens (daily/weekly), Hierarchy mode.
- **Processteg:** Hämta next pending item → Resolva Prompt → Research → Generation → Spara → Länkinjicering.
- **Problemet:** Monolitiska funktioner (en 500-raders funktion som gjorde *allt*). Byggde på `localStorage` med extremt komplex hierarki. Om ett steg kraschade tappades hela exekverings-staten.

---

## PowerCreatives V2 Architecture (Design & Fiskben)

Här är hur vi bygger en snabbare och mer dynamisk lösning med minimal komplexitet genom en **Message Bus & Pipeline-modell**.

### 1. Grundbulten: Enhetlig Payload (Context Object)
Istället för att funktioner tar 15 olika parametrar (`keyword`, `niche`, `site`, `credentials`), skapar vi ett dynamiskt **Context Object** som följer med i hela exekveringskedjan. 

```typescript
interface ExecutionContext {
  brand: BrandProfile;                  // Tone, USPs, Colors, Logo
  keyword: string;                      // Nuvarande keyword som bearbetas
  globalVariables: Record<string, any>; // Andra custom fält
  accumulatedData: Record<string, any>; // Data genererad av steg i pipeline
}
```
*Varför?* Samma payload skickas överallt. Modulerna letar bara efter den data de behöver, vilket löser variabelflödet direkt.

### 2. Modul: WORKFLOWS (Den dynamiska motorn)
I PowerCreatives V2 är ett Workflow *exklusivt* en sekvens av funktioner. Inget UI-state läcker in i backenden.

* **Förenkling:** Vi bygger inte in AI-exekveringslogik direkt i flödet. Workflows är en isolerad tjänst som säger: "Ta denna input, kör pipeline X, och skicka tillbaka resultatet."
* **Inputs:** `ExecutionContext` + `WorkflowConfig`.
* **Flow:**
  1. **StepExecutor** tar emot en lista med steg (t.ex. `[ScrapeURL, ExtractData, TriggerLLM, CreateImage]`).
  2. Varje steg hämtar vad den behöver från `accumulatedData`, gör sin grej, och stoppar tillbaka sin output (t.ex. raw markdown eller bildlänkar) under en ny nyckel i `accumulatedData`.
* **Output:** Slutresultatet levereras snyggt paketerat för nästa instans.
* **Vinst:** Behöver du lägga till en ny AI-modell, eller en ny scrapingtjänst? Det är bara en ny "Step Type". Grundmotorn förändras aldrig.

### 3. Modul: STRATEGY (Operationschefen)
Strategy kör inga AI-funktioner själv. Den är enbart en **Queue Manager**. Den sköter logistik, metadata och status.

* **Inputs från UI:** Valda Keywords (från Keyword Explorer), Valt Brand, Vald Template/Workflow.
* **Flow:**
  1. Triggern aktiveras (Manuellt "Run" eller Cron-jobb).
  2. Strategy hämtar nästa keyword i kön: `status: 'pending'`.
  3. Låser keywordet: `status: 'processing'`.
  4. Kompilerar `ExecutionContext` (hämtar brand från DB, stoppar in keyword).
  5. Skickar `ExecutionContext` till **Workflows-modulen (Internt API)**.
  6. När Workflows returnerar innehållet, skickar Strategy det till **Writer-modulen (Internt API)** för sparning (`Writer.createDocument()`).
  7. Frisläpper låset med resultat: `status: 'draft'`.
* **Smarter Link Injection (Parent/Child):** Istället för komplexa inbäddade nästlingar, vet Strategy vilka artiklar som ska länka till varandra. Länkning hanteras via **Delayed Execution**: Låt alla artiklar byggas klart, och när klustret är färdigt körs en `PostGenerationTask` som isolerat sätter in länkarna i dokumenten utanför genereringssteget.

### 4. Modul: WRITER (Editor och Slutstation)
Fungerar exakt som Microsoft Word. 
* **Funktion:** Vet ingenting om SEO, AI eller prompts.
* **Åtgärd:** Tar bara emot det färdiga HTML/Markdown-innehållet och låter användaren godkänna, redigera och spara (publicera).

---

## Sammanfattning av Vinsterna med Lego-arkitekturen

1. **Separation of Concerns:** Om Writer-UIt ändras går Strategy inte sönder. Om Claude 3.5 byts ut ändras bara ett Workflow-steg, Strategy bryr sig inte.
2. **Robust Felsökning:** Eftersom Workflows bara uppdaterar `accumulatedData`, bevaras tillståndet mellan varje steg. Fallerar ett steg är felet isolerat.
3. **Extensibility:** Tjänsterna pratar med varandra via enkla gränssnitt. Strategymotorn bryr sig inte om content genererats genom att "skrapa kontext", "fråga Google Suggest" eller "Multi-LLM analys". Den beställer bara en artikel.
4. **Data Persistence:** Ingen mer opålitlig `localStorage`. Full integration med databaslagret säkerställer att stora strategier inte kraschar appen.
