# Module: Workflows

> A visual "Workbench" and IDE for designing, testing, and saving multi-step AI prompts.

## Purpose

The Workflows module is an authoring environment (IDE) for creating complex, multi-step AI instructions. Instead of asking users to write massive, fragile text prompts in a single text box, Workflows breaks the process down into manageable, modular **Steps**.

**Analogy:** If Writer is Microsoft Word, Workflows is the **Automator / Macro Recorder**. It lets you define *how* an article should be researched, generated, and assembled, step-by-step, and save that definition as a reusable `ContentPrompt`.

---

## The "Workbench" Concept

The core UX of the Workflows module revolves around the **Workbench** pattern. Every step being built has three distinct module states that can be toggled:

1. **📥 Input Data:** Visualizes what variables and context are flowing *into* this step from previous steps.
2. **⚙️ Configuration:** The settings for the step itself (e.g., the prompt text, selected AI model, search queries).
3. **📤 Output Execution:** The result of running *this specific step* in isolation.

This allows the user to **run and test steps inline** with real data via a "Test Variables" drawer, before saving the prompt for production use.

---

## Modular Architecture (Lego Thinking)

Workflows is strictly an **Authoring Tool**. It does NOT execute content strategies on its own. 

It produces a data structure (`ContentPrompt`) which is then consumed by other modules (Strategy, Writer).

```
┌──────────────────────────────────────────────────────────────┐
│                      WORKFLOWS MODULE                         │
│                                                                │
│  ┌────────────────────────────────────────────────────────┐  │
│  │                    WORKBENCH                           │  │
│  │                                                        │  │
│  │  [ Step 1: Deep Search ]                               │  │
│  │  ├── 📥 Input: {{keyword}}                             │  │
│  │  ├── ⚙️ Config: Google Queries, AI Extraction Prompt   │  │
│  │  └── 📤 Output: Extracted insights JSON                │  │
│  │                                                        │  │
│  │  [ Step 2: Write ]                                     │  │
│  │  ├── 📥 Input: {{keyword}}, {{extracted_insights}}     │  │
│  │  ├── ⚙️ Config: LLM Model, Markdown Template           │  │
│  │  └── 📤 Output: Raw Article Text                       │  │
│  │                                                        │  │
│  └────────────────────────────────────────────────────────┘  │
│                               |                                │
│                               ▼                                │
│                     Saves as ContentPrompt                     │
└──────────────────────────────────────────────────────────────┘
                                |
          ┌─────────────────────┴─────────────────────┐
          ▼                                           ▼
 ┌─────────────────┐                        ┌─────────────────┐
 │Strategy Module  │                        │  Writer Module  │
 │(Uses prompt for │                        │(Uses prompt for │
 │batch content)   │                        │inline edits)    │
 └─────────────────┘                        └─────────────────┘
```

---

## Step Types (The Tools in the Workbench)

Each step represents a specific capability. Users can drag and drop these to build pipelines.

### 1. Site Context (`site_context`)
Extracts global variables. Usually the first step.
- **Config:** Select target site.
- **Output:** Site profile, NAP (Name, Address, Phone), global keywords.

### 2. Deep Search / Extraction (`extraction`)
Performs live research before writing.
- **Config:** Target URLs to scrape, Google SERP queries, AI Overview triggers, and the extraction instructions (e.g., "Find the pricing in this text").
- **Output:** Structured JSON context.

### 3. Write / Generation (`generation`)
The core LLM call.
- **Config:** The Prompt Template, AI Model override (e.g., force Claude 3.5 Sonnet for this specific step), and Media Placeholders configuration.
- **Output:** Generated markdown text and media insertion instructions.

### 4. Media (`media_creator`)
Reads instructions from the Write step and generates assets.
- **Config:** Image Provider (e.g., Gemini, DALL-E) and style overrides.
- **Output:** Generated images/videos/charts with URLs, alts, and titles.

### 5. Assembly (`assembly`)
Merges everything together.
- **Config:** Target format (Markdown, HTML, JSON) and which variables to merge.
- **Output:** Final, polished payload ready for saving.

---

## Data Model

```typescript
/**
 * ContentPrompt represents the saved output from the Workflows builder.
 * It contains the configuration of all steps.
 */
interface ContentPrompt {
  id: string;
  name: string;
  description: string;
  
  // The modular steps that make up this prompt sequence
  steps: PromptStep[];
  
  // Global AI override (if the prompt requires a specific model)
  aiConfig?: {
    provider?: 'claude' | 'gemini';
    model?: string;
  };
  
  // Legacy string template fallback
  template: string; 
  
  createdAt: string;
  updatedAt: string;
}

/**
 * A single step configuration inside the prompt.
 */
interface PromptStep {
  id: string;
  type: 'site_context' | 'extraction' | 'generation' | 'media_creator' | 'assembly' | 'search';
  name: string;
  config: Record<string, any>; // Specific to the step type
}
```

---

## Core Features & Functionality

### 1. Inline Test Runner
Users don't have to guess if a prompt works. They can click **"Test Variables"** (represented by a Flask icon 🧪), input a test keyword, and run the pipeline step-by-step. The output appears immediately in the Output module.

### 2. Output Visualization
The UI adapts based on the step type output:
- **Text:** Displays rendered markdown.
- **JSON:** Code block with syntax highlighting.
- **Media:** Grid visualization of generated images.

### 3. Variable System (`{{}}`)
Steps communicate by passing state forward. A variable generated in Step 1 (e.g., `{{extracted_data}}`) can be typed straight into the configuration of Step 2.

---

## Phase Plan (Implementation Strategy)

| Phase | Scope | Description |
|-------|-------|-------------|
| **1** | Core Data Model | Define `ContentPrompt` schema and tRPC CRUD operations. |
| **2** | Basic Workbench UI | Implement the List view and the accordion-based Step UI (Input/Config/Output). |
| **3** | Generation Config | Build the config editor for the "Write" step type (the absolute minimum required for a prompt). |
| **4** | Inline Testing Engine | Implement the runner hook to execute the generation step live with mock variables. |
| **5** | Advanced Steps | Add Extraction (DataForSEO), Media, and Assembly configs. |

---

## Relationship to Strategy Module

If **Workflows** makes the tool, **Strategy** uses the tool.

- **Workflows** says: *"First search Google for X, then extract pricing, then write an article."*
- **Strategy** says: *"Do this for these 50 keywords, starting on Tuesday, and require my approval before publishing."*

The Strategy module simply points to a saved `ContentPrompt` ID and passes the required variables into it when executing its queue.
