# Writer Module — The Multi-Agent Orchestrator Pipeline

## 🎯 Scope & Vision
This architecture explicitly applies to the **Writer Module** and its document generation core. We are replacing the monolithic frontend-heavy approach (where images were injected via Tiptap DOM traversal *after* the text was fully placed) with a **Multi-Agent Orchestrator Pipeline** tied to the Writer's `generate` flow.

The Writer Module transitions from being a simple "text generator" to an **Event-Driven Assembly Line** for multimodal articles.

### Key Components of the Writer Module Pipeline

1. **The Order Placer (Frontend Sidebar / UI in Writer)**
   - The UI's only purpose is to construct an `Order Manifest` (JSON).
   - Sidebaren designas som en stapel av isolerade modul-boxar (ex. "Skribent", "Bildskapande").
   - **Skribent-boxen:** Innehåller en **Text Prompt/Brief** (textarea för användarens order) och en **AI Prompt** (enbart en select/dropdown som hämtar fördefinierade system-prompter från Settings). Inga råa systemprompter får vara synliga för användaren.
   - **Bild-boxen:** Får *endast* innehålla tre inputs: Antal bilder (t.ex. 1-10), Typ av bild (Realism, Tecknat etc via en dropdown), och AI-Modell (dropdown från aktiv integration).
   - Menyn kompilerar dessa val till en Payload och skickar iväg till `writer.generate` tRPC endpoint.

2. **The Writer Orchestrator (Backend Router / `PCM_Writer_Service`)**
   - Receives the manifest.
   - Deploys the **Text Agent** (e.g., Claude/Gemini) to write the article.
   - **Crucial step:** The Text Agent is instructed to identify the perfect spots for media and output placeholders in the text (e.g., `[MEDIA:IMAGE:01]`), while appending a structured JSON payload at the end of the text defining what that media should be.
   - The Writer Orchestrator receives the generated text, intercepts the JSON payload, and begins dispatching sub-tasks to specialized media modules.

3. **Specialized Agent Modules (Delegates)**
   - **Image Pipeline Module:** Takes the raw context from the text agent, sends it through its own sub-agent to get a precise stable-diffusion/midjourney/fal style prompt, generates the image via the active provider, uploads it to WordPress, and returns the `<figure>` HTML markup.
   - **Schema Module:** Concurrently analyzes the text to generate JSON-LD `Schema.org` markup.
   - **Video Module (Future Scope):** Will receive video placeholders and trigger a background Veo/Sora job just as seamlessly.

4. **Final Assembly & Tiptap Hydration**
   - The Writer Orchestrator streams or returns the content back to the client (`ReviewEditorCanvas` or `writer/service.php` logic).
   - The frontend immediately renders loading skeletons/blocks for any `[MEDIA:IMAGE:01]` tags it encounters.
   - As the Orchestrator's asynchronous sub-jobs finish, the `<figure>` markup is sent to the client and the DOM hydrates the placeholders into actual interactive elements inside the Tiptap editor.

## ⚖️ Senior Dev Design Decisions
* **Strict Decoupling:** The Writer’s text generation logic does not know *how* to make an image. It simply delegates. This isolates failures; if image generation goes down, the article text still completes successfully.
* **Open-Closed Principle:** Adding new capabilities (like generating interactive charts or audio TTS for the article) requires ZERO changes to the Text LLM prompt. We simply expand the Order Manifest and register a new Delegate Module.
* **Zero Hardcoding (Prompt & Model Freedom):** Absolutely NO prompts (system prompts, user prompts, or sub-agent media prompts) or models are hardcoded in the codebase. Every step of the Orchestrator Pipeline pulls its system instructions from the database (Settings → Prompt Editor or Templates). All providers and models are dynamically resolved via Integrations / Global Settings. This guarantees the user has 100% control over the AI's behavior without touching code.
* **Perceived Speed (UX):** By streaming the text layout immediately with placeholders, the user sees the article instantly. The heavy lifting (image generation, schema extraction) happens concurrently in the background and resolves naturally.
