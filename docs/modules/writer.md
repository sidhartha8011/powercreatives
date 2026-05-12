# Writer Module

## Varför denna modul finns
Power Creatives är en plattform för att skapa kreativt innehåll (bilder, video, copy). Men det saknas en modul för att skriva SEO-optimerade artiklar och blogginlägg. Writer fyller det gapet — användaren kan skriva innehåll, optimera det för sökmotorer, och använda AI för att förbättra texten. Allt utan att lämna plattformen.

## Vad modulen gör
En fullständig content editor med 4 spalter som lever inuti Power Creatives, precis som Keywords, Image och Video. Användaren klickar på "Writer" i sidomenyn och får upp en vy där de kan:

1. Välja och hantera dokument (Queue)
2. Fylla i SEO-metadata (titel, beskrivning, slug)
3. Skriva och redigera artikeltext med en rik texteditor
4. Få AI-assistans och granska revisioner

## Designreferens
Den godkända designen finns i: `docs/modules/writer-mockup-v5.html`  
Öppna filen i en webbläsare för att se exakt hur modulen ska se ut.

## Arkitekturprinciper
- **Isolerad (lego):** Modulen lever i `app/src/modules/Writer/` och rör inga andra filer. Den kan tas bort utan att påverka resten av appen.
- **Bi-direktionell data:** Ska kunna ta emot keywords från Keywords-modulen OCH erbjuda sin data (artiklar) till andra moduler.
- **Konsumerar AI-integrationer:** Använder samma AI-providers som redan finns i Integrations-modulen.
- **Inget hårdkodat:** Alla listor, fält och paneler drivs av data. Inga fasta strängar, inga fasta listor.
- **Max 500 rader per fil.** Om en komponent blir större, bryt ut delar.

## Layout (4 kolumner, draggbara)

| Kolumn | Namn | Syfte |
|--------|------|-------|
| 1 | Queue | Dokumentlista. Dropdown för att filtrera per projekt. Max 20% bredd. |
| 2 | SEO & Metadata | Meta title (teckenmätare), description, slug, featured image, schema-typ. Panelen ska vara **hopfällbar**. |
| 3 | Editor | Tiptap-baserad texteditor med toolbar (B/I/U, headings, listor, AI Assist). |
| 4 | AI Chat / Revisions | Flikar: "AI Chat" för att prata med AI om texten, "Revisions" för att se ändringshistorik med diffar. |

## Design
- Appens befintliga design-tokens ska användas: `components/shared/design-tokens.ts`
- Minimalistisk typografi — tunna fonter, ljusgrå (`#888`) på inaktiva element
- Pastellfärger på statusdots i Queue-listan:
  - 🟡 Gul (`#fff9db` / `#ffe066`) = Draft
  - 🟢 Grön (`#ebfbee` / `#b2f2bb`) = Ready
  - 🔴 Röd (`#fff5f5` / `#ffc9c9`) = Review

## Datamodell (artikel)
Varje artikel innehåller minst:

| Fält | Typ | Beskrivning |
|------|-----|-------------|
| id | int | Primärnyckel |
| projectId | int | Vilket projekt artikeln tillhör |
| title | string | Artikelrubrik |
| content | text (HTML) | Artikelinnehåll i Tiptap-format |
| metaTitle | string | SEO meta title (max 60 tecken) |
| metaDescription | string | SEO meta description (max 160 tecken) |
| slug | string | URL-slug |
| status | enum | draft / review / ready / published |
| featuredImage | string | URL till omslagsbild |
| schemaType | string | Article / WebPage |
| createdAt | datetime | Skapad |
| updatedAt | datetime | Senast ändrad |

## Tekniska val
- **State:** Jotai (atoms) — isolerad state, ingen prop-drilling
- **Layout:** react-resizable-panels
- **Editor:** Tiptap — utöka befintlig `components/shared/TiptapBodyEditor.tsx` med headings/lists
- **API:** tRPC-adapter mot WordPress REST API (samma mönster som Keywords-modulen)

## Filstruktur
```
app/src/modules/Writer/
├── index.tsx                          # Huvudkomponent med ResizablePanelGroup
├── store.ts                           # Jotai atoms
└── components/
    ├── DocumentQueuePanel.tsx          # Kolumn 1
    ├── SeoMetadataPanel.tsx            # Kolumn 2
    ├── ReviewEditorCanvas.tsx          # Kolumn 3
    └── AiRevisionsPanel.tsx            # Kolumn 4
```

## Backend (ännu ej byggt)
```
includes/modules/writer/
├── config.php        # Modul-registrering (auto-discovered av PCM_Module_Loader)
├── controller.php    # REST-endpoints: CRUD för artiklar
└── service.php       # Affärslogik: spara, hämta, radera
```
- DB-tabell: `wp_pcm_articles`
- REST-namespace: `pcm/v1/writer`

## Status (2026-04-11)
- [x] Modul registrerad i Shell.tsx och Sidebar.tsx
- [x] Skelett med 4 resizable paneler synligt i appen
- [x] Jotai store med testdata (3 dokument, 2 projekt)
- [ ] Design consistency — headern matchar inte appens övriga design
- [ ] Fungerande Tiptap-editor i mittenpanelen
- [ ] Klickbar dokumentlista (byter aktivt dokument)
- [ ] SEO-fält med riktiga inputs och teckenmätare
- [ ] Projektfiltrering via dropdown i Queue
- [ ] Hopfällbar metadata-panel
- [ ] Backend: DB-tabell + REST-endpoints
- [ ] Koppling till Keywords-modulen (ta emot keywords)
- [ ] Koppling till Integrations-modulen (AI-textgenerering)
