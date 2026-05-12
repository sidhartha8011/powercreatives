# Writer Module — Phase 2 Handover

**Datum:** 2026-04-16  
**Från:** Senior Developer  
**Till:** Nästa senior developer som tar över implementationen  
**Status:** Phase 1 (infrastruktur) VERIFIED ✅ — Phase 2 (användarfunktionalitet) INTE PÅBÖRJAD

---

## Bakgrund: Vad som är klart (Phase 1)

Du ärver en **ren Tiptap-editor** med fungerande AI-infrastruktur. Allt nedan är byggt, testat och committat:

| Komponent | Fil | Vad den gör |
|-----------|-----|-------------|
| UniqueID | `editorExtensions.ts:127-130` | Stämplar unikt `id`-attribut på varje heading, paragraph, blockquote, table, image |
| AST Serializer | `services/editorContextService.ts` (64 rader) | `serializeAst(editor)` → JSON-array med `{id, type, textContent}` per block |
| Diff Engine | `services/diffApplicator.ts` (162 rader) | `applyAiDiffs(editor, diffs)` — atomärt applicerar AI-ändringar via ProseMirror transactions |
| AI Suggestion Mark | `extensions/AiSuggestionMark.ts` (107 rader) | Tiptap Mark som renderar `.ai-diff-deletion` (röd) och `.ai-diff-insertion` (grön) |
| Diff CSS | `index.css:853-880` | CSS custom properties (`--ai-diff-deletion-bg`, etc.) med oklch-färger |
| Zero-rerender | `ReviewEditorCanvas.tsx:92` | `shouldRerenderOnTransaction: false` — verifierat att BubbleMenu-knappar fortfarande fungerar |
| FileHandler | `editorExtensions.ts:137-157` | Fångar paste/drop av bilder, men loggar bara — **ingen upload-pipeline** |

### Befintliga komponenter du kan återanvända

| Komponent | Sökväg | API |
|-----------|--------|-----|
| `<AIChatBox />` | `@/components/AIChatBox.tsx` (336 rader) | `messages: Message[]`, `onSendMessage`, `isLoading`, `suggestedPrompts`, `height` |
| `AiRevisionsPanel` | `Writer/components/AiRevisionsPanel.tsx` | Tom placeholder — redo att fyllas med chattinnehåll |
| `PCM_LLM::invoke_json()` | `includes/core/llm/class-pcm-llm.php` | Standard LLM-anrop med JSON schema |
| `writer.generate` tRPC | `controller.php:31` + `trpc.ts:258` | Befintlig endpoint-pattern att följa |
| Design tokens | `@/components/shared/design-tokens` | `colors`, `typography` — används i alla Writer-komponenter |

### Backlog-referens
Alla items finns i `docs/backlog.md` under rubriken **"✍️ Writer Module — AI Track Changes"**.

---

## Steg 3: Bildhantering i Editorn

### Mål
Användaren ska kunna paste:a bilder från webbläsaren in i editorn, och Image-modulens AI-genererade bilder ska kunna insertas direkt i dokumentet. Bilderna ska lagras i WP Media Library och länkas korrekt.

### Vad som finns idag
- ✅ `@tiptap/extension-image` är registrerad i `editorExtensions.ts`
- ✅ `@tiptap/extension-file-handler` fångar paste/drop med MIME-filter (`image/png`, `image/jpeg`, `image/gif`, `image/webp`, `image/svg+xml`)
- ✅ `FileHandler.configure({ onPaste, onDrop })` har callback-hookar — men de loggar bara till console
- ❌ **Ingen upload till WP Media Library** — det finns ingen PHP-endpoint som tar emot en bild-fil
- ❌ **Ingen `editor.commands.setImage()` anropas efter upload** — bilden visas aldrig i editorn

### Vad som behöver byggas

#### Backend (PHP)
1. **Ny route i `controller.php`:** `POST /articles/upload-image`
2. **Ny metod i `service.php`:** `upload_image(file, user_id)` — använd WordPress-native `media_handle_sideload()` eller `wp_handle_upload()`
3. **Returnera:** `{ url: "https://...", id: 123 }` (WP attachment URL + ID)
4. **Säkerhet:** Verifiera filtyp (MIME), max storlek, nonce

#### Frontend (TypeScript)
1. **Ny tRPC-route:** `writer.uploadImage` i `trpc.ts`
2. **Upload-funktion:** Ta `File`-objektet från FileHandler → skicka som `FormData` till REST-endpoint → invänta URL
3. **Insert i editor:** `editor.chain().focus().setImage({ src: url, alt: '' }).run()`
4. **Koppla till FileHandler:** Byt ut console.log i `editorExtensions.ts` `onPaste`/`onDrop` callbacks mot upload-funktionen
5. **Alternativ insert:** Knapp eller command-palette för att hämta bild från Image-modulen (befintligt Assets-system)

### Referens för WP Media upload
Kolla hur andra plugins gör: `wp_handle_upload()` → `wp_insert_attachment()` → `wp_generate_attachment_metadata()`. Ingen custom upload-logik — använd WordPress-native funktioner.

---

## Steg 4: Publicera Full Artikel till WordPress

### Mål
Användaren klickar "Publish to Queue" och hela artikeln pushas till WordPress som ett riktigt inlägg (post/page). Allt ska följa med: text, formatering, bilder, länkar, tabeller, listor.

### Vad som finns idag
- ✅ **"Publish to Queue"-knapp** finns redan i action bar (`index.tsx`)
- ✅ **`writer.update` tRPC** kan spara content, title, metaTitle, metaDescription till DB
- ✅ **Tiptap `editor.getHTML()`** ger ren semantisk HTML
- ❌ **Ingen `wp_insert_post()`** — artikeln sparas bara i pcm-databasen, inte som WP post
- ❌ **Bilder i content pekar på WP Media URL:er** (om Steg 3 är implementerat) — men featured image sätts inte

### Vad som behöver byggas

#### Backend (PHP)
1. **Ny route:** `POST /articles/{id}/publish`
2. **Service-metod:** `publish_to_wordpress(article_id, user_id)`
   - Hämta artikeln från pcm DB
   - Anropa `wp_insert_post()` med:
     - `post_title` → article title
     - `post_content` → article content (HTML från Tiptap)
     - `post_status` → `'draft'` (eller `'publish'` om användaren väljer)
     - `post_type` → `'post'` (konfigurerbart)
   - Sätt SEO-meta via `update_post_meta()` (kompatibelt med Yoast/RankMath om installerat)
   - Sätt featured image via `set_post_thumbnail()` om tillgänglig
   - Uppdatera artikel-status i pcm DB till `'published'`
   - Returnera `{ postId, postUrl, editUrl }`

#### Frontend (TypeScript)
1. **Ny tRPC-route:** `writer.publish` i `trpc.ts`
2. **Hook i "Publish to Queue"-knappen:** Anropa `writer.publish` → visa success toast med länk till WP-posten
3. **Status-badge:** Uppdatera dokumentets badge från "Draft" till "Published" (grönt)

### HTML-format-krav
Tiptap ger ren HTML (`<h2>`, `<p>`, `<ul>`, `<ol>`, `<strong>`, `<em>`, `<a>`, `<img>`, `<table>`, `<blockquote>`). WordPress Gutenberg-editorn vill ha block-kommentarer (`<!-- wp:paragraph -->`), men det är **valfritt** — ren HTML fungerar i Classic Editor-läge. Om Gutenberg-kompatibilitet krävs, wrappa varje block i block-kommentarer.

---

## Steg 5: AI Writer Editor Chat Bubble

### Mål
En AI-chatt som lever inne i editorn — precis som Cursor IDE:s inline chat. Användaren markerar text, chattar med AI:n, och AI:ns ändringar visas som röda/gröna diffs inline i dokumentet med accept/reject-knappar bredvid.

### UX-flöde (hela kedjan)

```
1. Användaren markerar text i editorn
       ↓
2. En floating AI-chattbubbla dyker upp centrerad under/vid markeringen
       ↓
3. Användaren skriver instruktion: "Gör detta stycke mer engagerande"
       ↓
4. Frontend: serializeAst(editor) packar dokumentet som JSON
   Frontend: skickar {ast, instruction, selectedBlockIds} till backend via tRPC
       ↓
5. Backend: writer.inlineAiEdit tar emot → bygger prompt → anropar LLM
   LLM returnerar: AiDiffInstruction[] (vilka block som ska ändras och hur)
       ↓
6. Frontend: applyAiDiffs(editor, diffs) applicerar ändringar
   → Gammal text markeras RÖD med genomstrykning (AiSuggestionMark type="deletion")
   → Ny text insertas GRÖN (AiSuggestionMark type="insertion")
       ↓
7. Bredvid varje ändring visas ✓ (Accept) och ✗ (Reject) knappar
   → Accept: behåll grön text, ta bort röd text, ta bort marks
   → Reject: ta bort grön text, återställ röd text (ta bort deletion mark)
       ↓
8. (Valfritt) Användaren kan docka chatten till Revisions-panelen (höger kolumn)
   för permanent synlighet istället för floating bubbla
```

### Vad som finns att återanvända

| Befintligt | Vad det ger dig |
|------------|----------------|
| `<AIChatBox />` (336 rader) | Komplett chattkomponent med messages, Streamdown-markdown, loading, Enter-to-send — **wrappa denna i en floating container** |
| `serializeAst()` | Redo att anropa — ger JSON-array med block-IDs |
| `applyAiDiffs()` | Redo att anropa — tar `AiDiffInstruction[]` → applicerar marks |
| `AiSuggestionMark` + CSS | Redo — `.ai-diff-deletion` (röd), `.ai-diff-insertion` (grön) |
| `PCM_LLM::invoke_json()` | Standard LLM-anropsmönster — följ samma pattern som `writer.generate` |
| `AiRevisionsPanel` | Tom placeholder i höger kolumn — redo att rendera dockad chatt |

### Vad som behöver byggas

#### 1. Floating Chat Bubble (Frontend)
**Ny fil:** `Writer/components/WriterAiChatBubble.tsx`

- Positioneras relativt till editorn, centrerad längst ned (eller vid textmarkeringen)
- Dyker upp vid textmarkering **ELLER** via en knapp/genväg
- Inuti: återanvänd `<AIChatBox />` med anpassad `height` och `suggestedPrompts` (t.ex. "Förbättra", "Förkorta", "Expandera")
- Toggle-knapp: floating ↔ dockad (renderas i `AiRevisionsPanel` istället)

#### 2. Backend Endpoint
**Modifiera:** `controller.php` + `service.php`

- Ny route: `POST /articles/inline-edit`
- Input: `{ ast: ASTContextObject[], instruction: string, selectedBlockIds?: string[], modelId?: string }`
- Service-metod: `inline_edit(ast, instruction, selectedBlockIds, user_id)`
  - Bygger system-prompt som instruerar LLM att returnera BARA diff-instruktioner (inte hela artikeln)
  - Inkluderar dokumentets AST som kontext
  - JSON-schema för output: `{ diffs: [{ id, action, content }] }`
- Ny tRPC-route: `writer.inlineEdit` i `trpc.ts`

#### 3. LLM Prompt Design
System-prompten måste instruera LLM att:
- Läsa AST-JSON som dokumentkontext
- Returnera **enbart** de block som ska ändras (inte hela dokumentet)
- Använda exakt formatet: `{ diffs: [{ id: "block-uuid", action: "replace"|"insert_after"|"delete", content: "<p>Ny text</p>" }] }`
- Respektera block-ID:n från AST:n

#### 4. Frontend Hook
**Ny fil:** `Writer/hooks/useWriterAiChat.ts`

- State: `messages`, `isLoading`, `pendingSuggestions`
- `sendMessage(instruction)`:
  1. Anropa `serializeAst(editor)` → hämta AST
  2. Hämta `selectedBlockIds` (de block som är markerade/har cursor)
  3. Mutate `writer.inlineEdit` → skicka `{ast, instruction, selectedBlockIds}`
  4. Parsea respons → anropa `applyAiDiffs(editor, diffs)`
  5. Lägg till AI-svar i `messages` ("Jag har gjort 3 ändringar i dokumentet")
- Exponerar: `{ messages, sendMessage, isLoading }`

#### 5. Accept/Reject UI
**Ny fil:** `Writer/components/AiSuggestionActions.tsx`

Tekniskt alternativ (välj ett):
- **Alt A: ProseMirror Decorations + React Portal** — Dekorations-plugin skapar DOM-element bredvid varje suggestion-mark. React renderar ✓/✗ via `createPortal()` till dessa DOM-element.
- **Alt B: Absolute-positioned overlays** — Scanna editorns DOM efter `.ai-suggestion`-element, beräkna positioner, rendera ✓/✗ som absolut-positionerade React-komponenter ovanpå editorn.
- **Rekommendation:** Alt A (ProseMirror Decorations) — ren separation, ingen DOM-scanning, följer ProseMirror-patterns.

Accept/Reject-logik:
```typescript
// Accept: behåll insertion-text, ta bort deletion-text
function acceptSuggestion(editor, suggestionId) {
  const tr = editor.state.tr;
  // 1. Hitta alla marks med id === suggestionId
  // 2. För type="deletion": ta bort texten (tr.delete)
  // 3. För type="insertion": ta bort marken men behåll texten (tr.removeMark)
  editor.view.dispatch(tr);
}

// Reject: behåll deletion-text (original), ta bort insertion-text
function rejectSuggestion(editor, suggestionId) {
  const tr = editor.state.tr;
  // 1. Hitta alla marks med id === suggestionId
  // 2. För type="insertion": ta bort texten (tr.delete)
  // 3. För type="deletion": ta bort marken men behåll texten (tr.removeMark)
  editor.view.dispatch(tr);
}
```

---

## Ingenjörsprinciper (gäller ALLA steg)

- ✅ **100% ren kod** — senior dev-lösning, inga hacks, inga quick-fixes
- ✅ **Max 400 rader per fil** — bryt upp i moduler vid behov
- ✅ **Inga dupliceringar (DRY)** — återanvänd befintliga funktioner, hooks och komponenter
- ✅ **Design tokens** — `colors`, `typography`, spacing från design system genomgående
- ✅ **Opt-in för libraries** — Tiptap extensions, Radix UI, shadcn framför egna lösningar
- ✅ **Backend max 400 rader** — controller + service-separation per arkitektur
- ✅ **Beskrivande kommentarer** i all kod
- ✅ **ProseMirror transactions** — alla editor-mutationer via `tr`, aldrig DOM-manipulation
- ✅ **Atomära transactions** — en `dispatch` = en Ctrl+Z ångring
- ✅ **CSS custom properties** — alla nya färger/tokens via CSS variables, inga hårdkodade hex

---

## Filstruktur efter alla steg

```
Writer/
├── index.tsx                          # Layout med 4 kolumner
├── writerConfig.ts                    # Konfiguration, defaults
├── types.ts                           # TypeScript-typer
├── components/
│   ├── ReviewEditorCanvas.tsx         # Editor + toolbar (befintlig)
│   ├── WriterBubbleMenu.tsx           # Formatting toolbar (befintlig)
│   ├── WriterDynamicSection.tsx       # Settings panel (befintlig)
│   ├── AiRevisionsPanel.tsx           # Dockad chatt + revisionshistorik (MODIFIERA)
│   ├── WriterAiChatBubble.tsx         # Floating AI-chatt (NY)
│   └── AiSuggestionActions.tsx        # Accept/Reject knappar (NY)
├── extensions/
│   ├── editorExtensions.ts            # Central extension registry (befintlig)
│   └── AiSuggestionMark.ts           # Diff-mark (befintlig)
├── services/
│   ├── editorContextService.ts        # serializeAst (befintlig)
│   ├── diffApplicator.ts             # applyAiDiffs (befintlig)
│   └── suggestionResolver.ts         # accept/reject logik (NY)
└── hooks/
    └── useWriterAiChat.ts            # Chat state + tRPC integration (NY)
```

**Backend:**
```
includes/modules/writer/
├── config.php          # Befintlig
├── controller.php      # MODIFIERA: +2 routes (upload-image, inline-edit)
└── service.php         # MODIFIERA: +2 metoder (upload_image, inline_edit)
```

---

## Prioriteringsordning

1. **Steg 5 (AI Chat Bubble)** — Högst prioritet. Detta är kärnfunktionaliteten. Utan detta har Phase 1-infrastrukturen inget syfte.
2. **Steg 3 (Bildhantering)** — Behövs för komplett artikelkvalitet.
3. **Steg 4 (Publicera till WP)** — Sista steget, kräver att Steg 3 är klart.
