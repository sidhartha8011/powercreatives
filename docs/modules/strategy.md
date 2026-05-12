# Module: Strategy

> Content Strategy — the orchestration layer that transforms keyword research into published content.

## Purpose

Strategy is the **bridge** between Keywords (research) and Writer (editing). It groups selected keywords into a named campaign, attaches brand context and prompt templates, then sequences the generation of content through a managed pipeline with scheduling, approval workflows, and queue management.

**Analogy:** If Keywords is "what to write about" and Writer is "the editor", then Strategy is the **production manager** — it decides *when*, *how*, and *in what order* content gets created.

---

## Modular Architecture (Lego Thinking)

Strategy consumes other modules as **internal APIs**. It does NOT duplicate their functionality. Each module exposes a contract that Strategy consumes.

```
┌─────────────────────────────────────────────────────────────────┐
│                      STRATEGY MODULE                             │
│                                                                   │
│  ┌──────────┐   ┌──────────┐   ┌──────────┐   ┌──────────┐     │
│  │ Keywords  │   │  Brands  │   │Templates │   │Workflows │     │
│  │   API     │   │   API    │   │   API    │   │   API    │     │
│  └────┬─────┘   └────┬─────┘   └────┬─────┘   └────┬─────┘     │
│       │              │              │              │             │
│       ▼              ▼              ▼              ▼             │
│  ┌──────────────────────────────────────────────────────────┐   │
│  │              Strategy Orchestrator                        │   │
│  │  ┌─────────┐  ┌─────────┐  ┌─────────┐  ┌─────────┐    │   │
│  │  │ Builder  │  │  Queue  │  │Schedule │  │Approval │    │   │
│  │  │ (input)  │  │(process)│  │(trigger)│  │(gateway)│    │   │
│  │  └─────────┘  └─────────┘  └─────────┘  └─────────┘    │   │
│  └──────────────────────────────────────────────────────────┘   │
│       │                                                          │
│       ▼                                                          │
│  ┌──────────┐                                                    │
│  │  Writer   │  ← Generated documents appear here                │
│  │   API     │                                                    │
│  └──────────┘                                                    │
└─────────────────────────────────────────────────────────────────┘
```

### Module Contracts (What Strategy Consumes)

| Module | Contract | What Strategy Gets |
|--------|----------|--------------------|
| **Keywords** | `getSelectedKeywords(): KeywordResult[]` | Array of keywords with volume, difficulty, questions |
| **Brands** | `getBrandById(id): Brand` | Tone of voice, USPs, name, URL, logo — injected into prompts |
| **Templates** | `getTemplateById(id): Template` | Prompt template with `{{variable}}` placeholders |
| **Workflows** | `getWorkflowById(id): Workflow` | Multi-step execution pipeline (research → generate → enrich) |
| **Writer** | `createDocument(content): Document` | Saves generated article as an editable document |

### What Strategy Exposes

| Contract | Who Calls It | What It Returns |
|----------|-------------|-----------------|
| `strategy.create(config)` | Keywords BulkAction, Projects UI | New ContentStrategy with pending items |
| `strategy.list()` | Projects dashboard | All strategies with progress stats |
| `strategy.processNext(id)` | Queue runner, Manual "Run" button | Processes next pending item |
| `strategy.getItems(id)` | Projects UI, Writer document picker | List of items with statuses |

---

## Data Model

```typescript
/**
 * ContentStrategy — A named content campaign built from keyword research.
 * 
 * Design principle: Strategy owns the PLAN (what + when + how).
 * It does NOT own the CONTENT (that lives in Writer/Documents).
 * It does NOT own the PROMPT (that lives in Templates/Workflows).
 */
interface ContentStrategy {
  id: string;
  name: string;
  
  // --- Module References (Foreign Keys) ---
  brandId: string;              // → Brands module
  templateId?: string;          // → Templates module (simple prompt)
  workflowId?: string;          // → Workflows module (multi-step pipeline)
  customPrompt?: string;        // Override: raw prompt text (takes precedence)
  
  // --- Items ---
  items: StrategyItem[];
  
  // --- Orchestration Config ---
  status: 'draft' | 'active' | 'paused' | 'completed';
  publishingMode: 'draft' | 'schedule' | 'publish';
  approvalMode: 'none' | 'internal' | 'client' | 'both';
  
  // --- Scheduling ---
  frequency?: 'all_once' | 'daily' | 'every_other_day' | 'weekly' | 'biweekly' | 'monthly';
  startDate?: string;           // ISO date string
  
  // --- Hierarchy (Topical Clusters) ---
  hierarchyMode: 'standalone' | 'parent_children';
  parentItemIndex?: number;     // Which item is the pillar page
  
  // --- Metadata ---
  createdAt: string;
  updatedAt: string;
  progress: number;             // 0-100, calculated from item statuses
}

/**
 * StrategyItem — A single keyword within a strategy.
 * 
 * Status state machine:
 *   pending → generating → draft → review → scheduled → published
 *                ↓                                          
 *              error (retryable → back to pending)
 */
interface StrategyItem {
  id: string;
  keyword: string;
  volume?: number;
  difficulty?: number;
  
  // --- Status ---
  status: 'pending' | 'generating' | 'draft' | 'internal_review' | 'client_review' | 'scheduled' | 'published' | 'error';
  errorMessage?: string;
  
  // --- Output References ---
  documentId?: string;          // → Writer document (set after generation)
  postUrl?: string;             // → WordPress post URL (set after publishing)
  postId?: string;              // → WordPress post ID
  
  // --- Overrides (per-item, optional) ---
  templateId?: string;          // Override strategy-level template
  workflowId?: string;          // Override strategy-level workflow
  
  // --- Scheduling ---
  scheduledDate?: string;       // Calculated from strategy frequency
  
  // --- Hierarchy ---
  isParent?: boolean;           // This item is the pillar page
  parentItemId?: string;        // Links to parent item for internal linking
}
```

---

## Generation Pipeline (What Happens When "Run" Is Clicked)

```
1. RESOLVE INPUTS
   ├── keyword:  StrategyItem.keyword
   ├── brand:    Brands.getBrandById(strategy.brandId)  
   ├── template: Templates.getById(item.templateId || strategy.templateId)
   └── workflow: Workflows.getById(item.workflowId || strategy.workflowId)

2. TEMPLATE RESOLUTION (Variable Injection)
   ├── {{keyword}}        → item.keyword
   ├── {{brand.name}}     → brand.name
   ├── {{brand.tone}}     → brand.toneOfVoice
   ├── {{brand.url}}      → brand.websiteUrl
   ├── {{questions}}      → Google Suggest questions for keyword
   └── {{supporting}}     → related keywords from keyword data

3. EXECUTE (via Workflow or direct LLM call)
   ├── If workflowId → Workflows.execute(workflowId, resolvedVariables)
   └── If templateId → AI.generate(resolvedPrompt)

4. POST-PROCESS
   ├── Markdown → HTML conversion
   ├── SEO metadata generation (title, description, schema)
   └── Parent link injection (if hierarchy mode)

5. SAVE
   ├── Writer.createDocument(content, metadata)
   ├── Update item.documentId → new document ID
   └── Update item.status → 'draft'

6. NEXT
   └── Queue.processNext() (repeat from step 1)
```

---

## UI Touchpoints

Strategy does NOT have its own full-screen module tab. It surfaces through:

1. **Keywords Module** — BulkAction: "Create Strategy" button when keywords are selected
2. **Projects Module** — Strategy list dashboard with progress bars, status per item
3. **Writer Module** — Document picker shows strategy-generated documents

---

## Backend (tRPC Routers)

```
strategy.create       → POST: Create new strategy from keyword selection
strategy.list         → GET:  All strategies with progress summary
strategy.getById      → GET:  Single strategy with all items
strategy.update       → PUT:  Update strategy config (name, brand, template)
strategy.delete       → DEL:  Delete strategy and optionally its documents
strategy.processNext  → POST: Generate content for next pending item
strategy.processAll   → POST: Queue all pending items for sequential processing
strategy.updateItemStatus → PUT: Manual status change (advance/reject)
```

---

## Phase Plan

| Phase | Scope | Depends On |
|-------|-------|------------|
| **1** | Data model + tRPC CRUD + "Generate single article" | Workflows Fas 1 |
| **2** | Queue system + batch processing + progress tracking | Phase 1 |
| **3** | Scheduling (WP Cron) + approval workflows | Phase 2 |
| **4** | Hierarchy (topical clusters) + parent link injection | Phase 3 |

---

## Reference: AutoPress Intelligence Mapping

| AutoPress Concept | Our Equivalent |
|-------------------|----------------|
| `ContentStrategy` (types.ts:249) | `ContentStrategy` (above) |
| `StrategyItem` (types.ts:223) | `StrategyItem` (above) |
| `ContentPrompt` (promptService.ts) | Templates module + Workflows module |
| `strategyService.processNextItem()` | `strategy.processNext` tRPC mutation |
| `generatorService.generateAndPublishArticle()` | Backend generation pipeline |
| `QueueContext` (React context) | React Query + tRPC mutations |
| `researchService.executeResearchStep()` | Workflows module research step |
