# Brand Fetch Consolidation — CRITICAL TECH DEBT

**Skapad:** 2026-04-06
**Prioritet:** KRITISK
**Uppskattad tid redan spenderad:** 20+ timmar utan slutförd konsolidering
**Status:** OAVSLUTAD — ej konsoliderat

---

## Bakgrund / AI-skuld

AI HAR VARIT HELT FÖRVIRRAD. VI HAR KONSOLIDERAT BRAND FETCH 3 GÅNGER OCH AI HAR INTE FATTAT ATT DET ÄR DEN GEMENSAMMA FUNKTIONEN.

ANVÄNDAREN HAR TROTT ATT AI HAR FATTAT. NÄR ANVÄNDAREN TRODDE ATT AI HADE TAGIT BORT OCH KONSOLIDERAT LÖSNINGEN EN GÅNG, MEN TREDJE GÅNGEN SÅ TRODDE ANVÄNDAREN ATT DET BARA FANNS EN FUNKTION. SÅ NÄR DE BYGGDE DEN SLUTGILTIGA BRAND FETCH EFTER 20 TIMMAR SÅ UPPTÄCKTES DET ATT DET LÅG EN SEPARAT GAMMAL BRAND FETCH KVAR I DE ANDRA MODULERNA.

ÄVEN OM ANVÄNDAREN FLERA GÅNGER FRÅGAT OM DÖD KOD ELLER DUPLIKAT SÅ HAR DETTA ALDRIG LYFTS. AI HAR INTE FÖRSTÅTT ATT DEN HAR ETT ANSVAR SOM SENIOR DEV ATT ANSVARA FÖR KODEN.

### Historik
1. **Första konsolideringen:** Allt skulle samlas. Det gjordes just då men slutade fungera.
2. **Andra omgången:** När arbetet togs upp igen fortsatte arbetet ENBART i Brand-modulen, för AI trodde att det var där de skulle jobba.
3. **Tredje omgången:** AI fattade INTE att det var en modul som skulle användas för ALLA moduler (Copy, Image, Video).

---

## Nuläge (fakta 2026-04-06)

| Plats | Fetch-funktion | Backend endpoint |
|---|---|---|
| **BrandDialog** (Brand-modulen) | `useBrandFetch` → `trpc.brands.scrapeAndPrepare` | `scrapeAndPrepare` |
| **ContextPanel** (Copy/Image/Video) | `handleUrlSubmit` → `trpc.brands.scrapeUrl` | `scrapeUrl` |

Två separata fetch-flöden. Två separata backend-endpoints. Duplicerad logik.

`LogoSelectionContent` delas visuellt, men:
- BrandDialog skickar `extractedColors` + `onColorsAssigned` → färgsteg med pre-selection fungerar
- ContextPanel skickar INTE dessa props → färgsteget triggas aldrig i Copy/Image/Video

---

## Mål

EN gemensam fetch-funktion som används av ALLA moduler:
- Brand-modulen
- Copy-modulen
- Image-modulen
- Video-modulen

EN backend-endpoint. EN frontend-hook. Logo-selection + färg-pre-selection ska fungera ÖVERALLT.

---

## Åtgärd

- [ ] Konsolidera `scrapeAndPrepare` och `scrapeUrl` till EN endpoint
- [ ] Konsolidera `useBrandFetch` och ContextPanel's inline fetch till EN delad hook
- [ ] Säkerställ att `LogoSelectionContent` med `extractedColors` + `onColorsAssigned` används i ALLA moduler
- [ ] Ta bort all duplicerad fetch-kod
- [ ] Verifiera att ALLA moduler fungerar med den gemensamma funktionen
