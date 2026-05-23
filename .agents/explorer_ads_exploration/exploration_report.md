# Codebase Exploration Report — Ads Module Integration Planning

## Core Findings Summary
The Ads Module is structured identically to the Image module, utilizing a Split-Panel layout (`AdsSidebar` on the left, `AdsResultsGrid` on the right) managed by a central orchestrator hook (`useAdsOrchestration`).
Key findings include:
1. **Ads Module Files**: There are five main files, including the index (`index.tsx`), sidebar, results grid, orchestrator hook, and a client sharing board (`CreateApprovalSetDialog.tsx`).
2. **Visual Patterns & AI Integration**: Visual components mimic the styling, layout, range sliders, and switch styles of the Image Module. "AI Enhance" utilizes a switch triggering brief optimization via `trpc.image.optimizeBrief`, while "Angles (Scenes)" uses a range slider to request creative variations via `trpc.image.generateConcepts`.
3. **tRPC Compatibility Proxy**: The React frontend uses a proxy adapter (`app/src/lib/trpc.ts`) translating standard tRPC calls (e.g. `trpc.image.generate.useMutation()`) to WordPress REST API endpoints (e.g. `POST /wp-json/pcm/v1/image/generate`), handled by PHP REST controllers (`includes/modules/*/controller.php`).

---

## Section 1: Detailed Ads Module Files Analysis

### 1. `app/src/modules/Ads/index.tsx`
* **Purpose**: Orchestrates all local state, context syncs, model overrides, and handlers for the Ads module, binding `AdsSidebar` and `AdsResultsGrid`.
* **Shared Context State**:
  ```typescript
  const [contextData, setContextData] = useState<ContextData>({
    brandId: undefined,
    brand: null,
    url: '',
    scrapedData: null,
    seasonEvent: '',
    campaignTheme: '',
  });
  const [sessionReferenceImages, setSessionReferenceImages] = useState<SessionReferenceImage[]>([]);
  const [formValues, setFormValues] = useState<Record<string, string | number | undefined>>({});
  const [brief, setBrief] = useState('');
  ```
* **Selection State (Client Board)**:
  ```typescript
  const [selectedVisualIds, setSelectedVisualIds] = useState<string[]>([]);
  const [selectedCopyIds, setSelectedCopyIds] = useState<string[]>([]);
  const [isShareDialogOpen, setIsShareDialogOpen] = useState(false);
  ```
* **Core Handlers**:
  * `handleGenerate`: Resets selected IDs and invokes `orchestration.generate(...)` with parameters:
    ```typescript
    orchestration.generate({
      brief,
      textModelId,
      imageModelIds: selectedImageModels,
      videoModelId,
      copyTypes: activeTypes,
      audiences: {
        mode: genSettings.audiencesMode,
        items: audiences,
        count: genSettings.audiencesCount,
      },
      angles: {
        mode: genSettings.anglesMode,
        items: angles,
        count: genSettings.anglesCount,
      },
      imageVariations,
      formValues,
      contextData,
      sessionReferenceImages,
    });
    ```
  * `handleGenerateAudiences`: Triggers copy suggestions mutation `trpc.copy.suggest.useMutation()` with type `'audiences'`.
  * `handleGenerateAngles`: Triggers `trpc.copy.suggest.useMutation()` with type `'angles'`, passing existing audiences to enable audience-aware suggestions.
  * `handleExport`: Triggers the async export pipeline `exportToMetaAdsZip(selectedTextSlots, selectedMediaSlots)` after confirming `selectionCount > 0`.

### 2. `app/src/modules/Ads/components/AdsSidebar.tsx`
* **Purpose**: Presentational component rendering all input settings.
* **Component Signature**:
  ```typescript
  interface AdsSidebarProps {
    contextData: ContextData;
    onContextChange: (data: ContextData) => void;
    onUrlFetched?: (data: ScrapedBusinessData) => void;
    formValues: Record<string, string | number | undefined>;
    onFormChange: (fieldId: string, value: string | number) => void;
    onBatchChange: (updates: Record<string, string | undefined>) => void;
    sessionReferenceImages: SessionReferenceImage[];
    onSessionReferenceImagesChange: (imgs: SessionReferenceImage[]) => void;
    brief: string;
    onBriefChange: (v: string) => void;
    selectedTypes: Record<string, boolean>;
    onSelectedTypesChange: (types: Record<string, boolean>) => void;
    audiences: ListItem[];
    onAudiencesChange: (items: ListItem[]) => void;
    angles: AngleItem[];
    onAnglesChange: (items: AngleItem[]) => void;
    genSettings: AdsGenSettings;
    onGenSettingsChange: (settings: AdsGenSettings) => void;
    textModelId: string;
    onTextModelChange: (id: string) => void;
    imageModelIds: string[];
    onImageModelsChange: (ids: string[]) => void;
    videoModelId: string;
    onVideoModelChange: (id: string) => void;
    imageVariations: number;
    onImageVariationsChange: (n: number) => void;
    isGenerating: boolean;
    onGenerate: () => void;
    onGenerateAudiences?: () => Promise<ListItem[]>;
    onGenerateAngles?: () => Promise<AngleItem[]>;
  }
  ```
* **Structural Layout Components**:
  * `ContextPanel`: URL entry & scraper.
  * `EnhancedBrandSection`: Core business description & colors.
  * `ThemeSelector`: Season and campaign context.
  * `GlobalEngineSelector`: Unified model registry selectors for Copy, Image, and Video.
  * `CopyTypeSelector`: Multi-select for copy targets (Ads / Organic).
  * `ModeListBox` & `GroupedAnglesList`: Dynamic lists for audiences and creative angles.
  * `GlobalProductionParameters`: Image variations count.

### 3. `app/src/modules/Ads/components/AdsResultsGrid.tsx`
* **Purpose**: Displays the results of the copy and visual generations.
* **Component Signature**:
  ```typescript
  interface AdsResultsGridProps {
    mediaSlots: MediaSlot[];
    textSlots: TextSlot[];
    phase: AdsPhase;
    progress: AdsProgress | null;
    isGenerating: boolean;
    error: string | null;
    selectedVisualIds: string[];
    selectedCopyIds: string[];
    onSelectVisual: (id: string) => void;
    onSelectCopy: (id: string) => void;
    onDownloadVisual?: (id: string) => void;
    onUpdateTextSlot?: (slotId: string, updates: { headline?: string; body?: string; cta?: string; description?: string; hashtags?: string[] }) => void;
    onRegenerateTextSlot?: (slotId: string, instruction?: string) => void;
    audiences?: { id: string; name: string }[];
  }
  ```
* **Core UX Patterns**:
  * **Audiences Sub-Tabs**: Group copies by audience dynamically:
    ```typescript
    const derivedAudiences = React.useMemo(() => {
      const fromProps = audiences.map(a => ({ id: a.id, name: a.name }));
      const fromResults = Array.from(new Set(textSlots.map(t => t.audienceName).filter(Boolean))) as string[];
      fromResults.forEach(name => {
        if (!fromProps.find(a => a.name === name)) {
          fromProps.push({ id: name, name });
        }
      });
      return fromProps;
    }, [audiences, textSlots]);
    ```
  * **Progress Bar & Skeleton Loading**: Rendered dynamically using functional calculations:
    ```typescript
    width: `${Math.max(2, (progress.current / progress.total) * 100)}%`
    ```
  * **AdVisualCard & AdCopyCard**: Component list mapping for the grid presentation.
  * **AssetDetailView Integration**: Provides high-resolution modal previews and refinement stubs for visuals.

### 4. `app/src/modules/Ads/hooks/useAdsOrchestration.ts`
* **Purpose**: Hook executing the sequential pipeline.
* **Return Interface (`UseAdsOrchestrationReturn`)**:
  ```typescript
  export interface UseAdsOrchestrationReturn {
    phase: AdsPhase;
    progress: AdsProgress | null;
    textSlots: TextSlot[];
    mediaSlots: MediaSlot[];
    isGenerating: boolean;
    error: string | null;
    clearError: () => void;
    generate: (params: AdsGenerateParams) => Promise<void>;
    updateTextSlot: (slotId: string, updates: { headline?: string; body?: string; cta?: string; description?: string; hashtags?: string[] }) => void;
    regenerateTextSlot: (slotId: string, instruction?: string) => void;
  }
  ```
* **Pipeline Sequencing**:
  1. **Phase 1: Copy Generation** (`runTextPhase`):
     * Directly calls `fetch()` to `/copy/generate` REST endpoint to bypass Tanstack Query caching during long SSE streams.
     * Uses `parseTextSSEStream` (from `utils/sseTextParser.ts`) to capture incremental chunks, updating `textSlots` dynamically.
  2. **Phase 2: Visual Generation** (`runImagePhase`):
     * Executes concurrently for all selected image models (`imageModelIds`) using `Promise.allSettled()`.
     * Variations per model are scheduled sequentially within each model task to prevent rate-limit exhaustion.
     * Triggers the `generateImageMutation.mutateAsync(...)` tRPC call for each visual variant.
  3. **Decoupled Assets State**: No hardcoded composition is made. Texts and images are held as separate parallel arrays (`textSlots` and `mediaSlots`), allowing clean modular grid editing.

### 5. `app/src/modules/Ads/components/CreateApprovalSetDialog.tsx`
* **Purpose**: Interactive modal allowing the marketer to pack generated creatives into a shareable mockup board for clients.
* **Interface Signature**:
  ```typescript
  interface CreateApprovalSetDialogProps {
    isOpen: boolean;
    onClose: () => void;
    selectedVisualIds: string[];
    selectedCopyIds: string[];
    mediaSlots: MediaSlot[];
    textSlots: TextSlot[];
    brandId?: number | null;
    projectId?: number | null;
    brandName?: string | null;
    brandLogoUrl?: string | null;
  }
  ```
* **Core Logic**:
  * Filters and maps the selected slots into a snapshot schema:
    ```typescript
    const selectedMedia = mediaSlots
      .filter((m) => selectedVisualIds.includes(m.id))
      .map((m) => ({
        id: m.id,
        type: m.type,
        url: m.url || '',
        prompt: m.prompt,
        provider: m.provider,
        modelId: m.modelId,
      }));
    const selectedCopy = textSlots
      .filter((t) => selectedCopyIds.includes(t.id))
      .map((t) => ({
        id: t.id,
        headline: t.headline,
        body: t.body,
        cta: t.cta || '',
        description: t.description || '',
        hashtags: t.hashtags || [],
        audienceName: t.audienceName || '',
        angleName: t.angleName || '',
        modelUsed: t.modelUsed,
      }));
    ```
  * Triggers tRPC mutation `trpc.approvals.createSet.useMutation()` with the payload:
    ```typescript
    createMutation.mutate({
      name: setName.trim(),
      brandId: brandId || null,
      projectId: projectId || null,
      snapshot: {
        media: selectedMedia,
        copy: selectedCopy,
        brandName: brandName || 'PowerCreatives',
        brandLogoUrl: brandLogoUrl || null,
      },
    });
    ```
  * On success, builds the shareable board link using WordPress shortcode page configuration:
    ```typescript
    const config = window.pcmConfig ?? { shortcodePageUrl: window.location.origin + '/' };
    const baseUrl = config.shortcodePageUrl || (window.location.origin + '/');
    const separator = baseUrl.includes('?') ? '&' : '?';
    const publicLink = `${baseUrl}${separator}pcm_public_token=${data.token}`;
    ```
  * Provides a fallback clipboard copy helper wrapping modern clipboard APIs and legacy document commands for safety on older client browsers.

---

## Section 2: "AI Enhance" and "Angles (Scenes)" Patterns

To design an identical user experience for the Ads Module, we analyze how "AI Enhance" and "Angles (Scenes)" are set up and integrated in the `Image` Module.

### Visual Components & Styling (`ImageSidebar.tsx`)
1. **AI Enhance (Switch & Sparkles Indicator)**:
   * Consists of an icon indicator, custom labels, and a standard Radix/shadcn Switch.
   * Rendered in the "Product Brief" header using a flexbox layout:
     ```tsx
     <div className="flex items-center justify-between mb-3">
         <div className="flex items-center gap-2">
             <Wand2 className="w-4 h-4 text-muted-foreground" />
             <h3 className="text-xs font-semibold uppercase tracking-wider text-muted-foreground">Product Brief</h3>
         </div>
         <div className="flex items-center gap-1.5">
             <Tooltip>
                 <TooltipTrigger asChild>
                     <label
                         htmlFor="auto-optimize"
                         className={`flex items-center gap-1 text-[11px] font-medium cursor-pointer select-none transition-colors ${autoOptimizeBrief ? 'text-primary' : 'text-muted-foreground hover:text-foreground'}`}
                     >
                         <Sparkles className={`w-3.5 h-3.5 transition-all ${autoOptimizeBrief ? 'text-primary drop-shadow-[0_0_4px_hsl(var(--primary)/0.4)]' : 'text-muted-foreground'}`} />
                         AI Enhance
                     </label>
                 </TooltipTrigger>
                 <TooltipContent side="left" className="max-w-[220px] text-xs">
                     When enabled, AI rewrites your brief into a more detailed, optimized image prompt before generation
                 </TooltipContent>
             </Tooltip>
             <Switch
                 id="auto-optimize"
                 checked={autoOptimizeBrief}
                 onCheckedChange={(checked) => gen.setAutoOptimizeBrief(checked)}
                 className="h-4 w-7 data-[state=checked]:bg-primary"
             />
         </div>
     </div>
     ```
2. **Angles (Scenes) Slider**:
   * Uses a custom label featuring a `Dices` icon, a numeric indicator element, and a full-width HTML range input:
     ```tsx
     <div>
         <div className="flex items-center justify-between mb-2">
             <label className="text-xs text-muted-foreground flex items-center gap-1.5">
                 <Dices className="w-3 h-3" />Angles (Scenes)
             </label>
             <span className="text-xs font-mono bg-muted px-2 py-0.5 rounded">{numVersions}</span>
         </div>
         <input
             type="range" min="1" max="8" value={numVersions}
             onChange={(e) => gen.setNumVersions(parseInt(e.target.value) || PRODUCTION_DEFAULTS.numVersions)}
             className="w-full h-1.5 bg-muted rounded-full appearance-none cursor-pointer accent-primary"
         />
         <div className="flex justify-between text-[10px] text-muted-foreground mt-1">
             <span>1</span><span>8</span>
         </div>
     </div>
     ```

### Hook Orchestration & Mutation Integrations (`useImageGeneration.ts`)
1. **AI Enhance Execution**:
   * State hook declaration: `const [autoOptimizeBrief, setAutoOptimizeBrief] = useState(PRODUCTION_DEFAULTS.autoOptimizeBrief);`
   * Executed during generation lifecycle prior to rendering:
     ```typescript
     let briefToUse = productBrief;
     if (autoOptimizeBrief) {
         setStatus((prev) => ({ ...prev, progress: 5, message: 'Optimizing your brief...' }));
         try {
             const optimized = await optimizeBriefMutation.mutateAsync({
                 brief: productBrief,
                 modelId: settings.defaultImageTextModel,
                 brandContext: hasBrandCtx ? brandCtx : undefined,
             });
             briefToUse = (optimized as any).optimizedBrief ?? productBrief;
         } catch {
             // Non-fatal fallback
             console.warn('[useImageGeneration] Brief optimization failed, using original.');
         }
     }
     ```
2. **Angles Concept Resolution**:
   * State hook declaration: `const [numVersions, setNumVersions] = useState(PRODUCTION_DEFAULTS.numVersions);`
   * Instantiates the first angle dynamically from user input:
     ```typescript
     const anchorVersion: AdVersion = {
         id: crypto.randomUUID(),
         name: 'Original',
         description: briefToUse,
     };
     let generatedVersions: AdVersion[] = [anchorVersion];
     ```
   * Fetches $N-1$ creative conceptual models using `generateConceptsMutation` if multi-angle mode is selected:
     ```typescript
     if (numVersions > 1) {
         setStatus((prev) => ({ ...prev, progress: 10, message: 'AI is conceptualizing creative angles...' }));
         const conceptsResult = await generateConceptsMutation.mutateAsync({
             prompt: briefToUse,
             count: numVersions - 1,
             modelId: settings.defaultImageTextModel,
             brandContext: hasBrandCtx ? brandCtx : undefined,
             referenceImages: toggles.useReferenceSubjects ? sessionReferenceImages.map((img) => ({ url: img.url, intent: img.intent })) : [],
         });
         const conceptsList = (conceptsResult as any).concepts ?? [];
         const aiVersions: AdVersion[] = conceptsList.map((concept: any) => ({
             id: crypto.randomUUID(),
             name: concept.name,
             description: concept.description,
         }));
         generatedVersions = [anchorVersion, ...aiVersions];
     }
     ```

---

## Section 3: tRPC Signatures and REST Schema Mapping

The frontend triggers backend endpoints via standard tRPC hooks proxy-mapped to WordPress REST controllers (`app/src/lib/trpc.ts`).

### 1. Backend REST Schema Definitions (`trpc.ts` Route Map)
* **`image.optimizeBrief`** $\rightarrow$ `POST /wp-json/pcm/v1/image/optimize-brief`
* **`image.generateConcepts`** $\rightarrow$ `POST /wp-json/pcm/v1/image/concepts`
* **`image.generate`** $\rightarrow$ `POST /wp-json/pcm/v1/image/generate`
* **`copy.suggest`** $\rightarrow$ `POST /wp-json/pcm/v1/copy/suggest`
* **`copy.generate`** $\rightarrow$ `POST /wp-json/pcm/v1/copy/generate`
* **`approvals.createSet`** $\rightarrow$ `POST /wp-json/pcm/v1/approvals/sets`

### 2. Detailed Method Signatures and Payloads (PHP Controller Mapping)

#### A. Optimize Brief (`POST /image/optimize-brief`)
* **PHP Controller Method**: `PCM_REST_Image::optimize_brief`
* **Expected JSON Input Payload**:
  ```json
  {
    "brief": "A blue coffee mug on a white desk",
    "modelId": "gpt-4o",
    "brandContext": {
      "brandId": 12,
      "brandName": "Brew Coffee",
      "colors": ["#0000ff", "#ffffff"],
      "industry": "Beverages"
    }
  }
  ```
* **Successful JSON Response**:
  ```json
  {
    "optimizedBrief": "A vibrant navy-blue ceramic coffee mug rests eleganty on a minimalist clean white lacquered desk..."
  }
  ```

#### B. Generate Concepts (`POST /image/concepts`)
* **PHP Controller Method**: `PCM_REST_Image::suggest_concepts`
* **Expected JSON Input Payload**:
  ```json
  {
    "prompt": "An outdoor shoe display",
    "count": 3,
    "modelId": "gpt-4o",
    "brandContext": { "brandId": 45 },
    "referenceImages": [
      { "url": "https://yoursite.com/uploads/reference.jpg", "intent": "style" }
    ]
  }
  ```
* **Successful JSON Response**:
  ```json
  {
    "concepts": [
      { "name": "Mountain Trail", "description": "A rugged boot perched on a mossy rock with heavy morning fog..." },
      { "name": "Urban Jungle", "description": "A slick shoe standing in front of high-contrast graffiti..." }
    ]
  }
  ```

#### C. Generate Image (`POST /image/generate`)
* **PHP Controller Method**: `PCM_REST_Image::generate_single`
* **Expected JSON Input Payload**:
  ```json
  {
    "prompt": "Vibrant trail running shoe on misty peak",
    "model": "flux-schnell",
    "provider": "fal",
    "brandContext": { "brandId": 45 },
    "inputUrls": ["https://yoursite.com/uploads/reference.jpg"],
    "referenceImageIntents": ["style"]
  }
  ```
* **Successful JSON Response**:
  ```json
  {
    "id": 1420,
    "url": "https://yoursite.com/wp-content/uploads/pcm/generated_123.jpg",
    "prompt": "Vibrant trail running shoe on misty peak...",
    "modelId": "flux-schnell",
    "provider": "fal"
  }
  ```

#### D. Pre-Generate Copy Suggestions (`POST /copy/suggest`)
* **PHP Controller Method**: `PCM_REST_Copy::suggest_items`
* **Expected JSON Input Payload**:
  ```json
  {
    "type": "angles",
    "count": 3,
    "formValues": { "creativeBrief": "Advertise our local organic grocery service" },
    "modelId": "gpt-4o",
    "audiences": [{ "id": "aud_1", "name": "Busy Parents" }]
  }
  ```
* **Successful JSON Response**:
  ```json
  {
    "items": [
      { "id": "angle_1", "name": "Time Saving convenience for organic grocery delivery" },
      { "id": "angle_2", "name": "Fresh healthy organic ingredients for young kids" }
    ]
  }
  ```

---

## Section 4: Handoff Protocol

### 1. Observation
We successfully examined the absolute files of the Ads Module and the Image Module:
* `app/src/modules/Ads/index.tsx` (317 lines)
* `app/src/modules/Ads/components/AdsSidebar.tsx` (328 lines)
* `app/src/modules/Ads/components/AdsResultsGrid.tsx` (392 lines)
* `app/src/modules/Ads/hooks/useAdsOrchestration.ts` (434 lines)
* `app/src/modules/Ads/components/CreateApprovalSetDialog.tsx` (265 lines)
* `app/src/modules/Image/components/ImageSidebar.tsx` (447 lines)
* `app/src/modules/Image/hooks/useImageGeneration.ts` (559 lines)
* `app/src/lib/trpc.ts` (737 lines)
* `includes/modules/image/controller.php` (481 lines)
* `includes/modules/copy/controller.php` (740 lines)

Key parameters and styles verified in `ImageSidebar.tsx`:
* Line 225: Sparkles icon label next to custom tooltips and Radix Switch:
  ```tsx
  <Switch id="auto-optimize" checked={autoOptimizeBrief} onCheckedChange={(checked) => gen.setAutoOptimizeBrief(checked)} />
  ```
* Line 412: Range slider for Angles (Scenes):
  ```tsx
  <input type="range" min="1" max="8" value={numVersions} onChange={(e) => gen.setNumVersions(parseInt(e.target.value) || PRODUCTION_DEFAULTS.numVersions)} ... />
  ```

Key paths verified in `trpc.ts`:
* Line 429: `"image.suggestConcepts": { endpoint: "image/concepts", method: "POST" }`
* Line 439: `"image.optimizeBrief": { endpoint: "image/optimize-brief", method: "POST" }`
* Line 539: `"approvals.createSet": { endpoint: "approvals/sets", method: "POST" }`

### 2. Logic Chain
1. *Observation*: `useImageGeneration.ts` handles "AI Enhance" and "Angles (Scenes)" by saving parameters into states (`autoOptimizeBrief`, `numVersions`) and then triggering tRPC async mutations (`optimizeBriefMutation` and `generateConceptsMutation`) when `handleGenerate` is launched.
2. *Observation*: The Ads module's orchestration hook (`useAdsOrchestration.ts`) currently uses a streamlined two-phase REST/tRPC execution pipeline to pull copy variants via SSE streams and visuals via parallel provider calls.
3. *Observation*: The `CreateApprovalSetDialog.tsx` file captures selected text and media IDs, serializes a state snapshot matching the requirements of the backend database schemas, and submits the payload to the WordPress approvals endpoint via `trpc.approvals.createSet`.
4. *Conclusion*: By implementing identical React states and Radix/HTML slider controls in `AdsSidebar.tsx`, we can wire the Ads Module to the same backend REST controllers, ensuring design consistency and features parity without adding code complexity.

### 3. Caveats
* **No Code Changes**: This was a read-only investigation, and no code files have been modified. All findings are verified by viewing the exact codebase paths.
* **Fishbone Stubs**: The video phase in `useAdsOrchestration.ts` is stubbed out (fishbone pattern), and can be safely activated in future iterations once video engines are defined.
* **WordPress Nonces**: The fetch endpoints require a valid `X-WP-Nonce` header, which is correctly managed by the dynamic frontend config.

### 5. Verification Method
* **Static Verification**: Inspect that `AdsSidebar.tsx` and `useAdsOrchestration.ts` can import the state setters from their respective configs.
* **Dynamic Verification**: Open the web application and check that the Ads Module loads without errors, and that the share dialog generates valid public tokens when a set is shared.
