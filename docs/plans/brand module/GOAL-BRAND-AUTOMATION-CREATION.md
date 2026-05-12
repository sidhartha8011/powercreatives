# GOAL: Brand Automation Creation

**Datum:** 2026-04-06
**Status:** Ej implementerat
**Ägare:** Produktägare (användaren)

---

## Önskat flöde — steg för steg

### Steg 1: Ny brand — startvy
- Användaren klickar **"New Brand"**
- Får upp en dialog med:
  - **URL-input** — för att scrapa en webbplats automatiskt
  - **Knapp "Manuellt"** — för att hoppa över scraping och fylla i formuläret manuellt

### Steg 2: URL Fetch
- Användaren fyller i en URL och klickar **"Fetch"**
- Plattformen scrapar webbplatsen (hämtar text, bilder, färger)

### Steg 3: Logotypval
- Användaren får se **hittade logotypmatchningar/kandidater**
- Bilderna är **sorterade efter storlek** (störst först)
- Varje bild visar **synligt vilken storlek den har** (t.ex. "512×512")
- Användaren **väljer logotyp** genom att klicka på en bild
- Klickar **"Bekräfta"**

### Steg 4: Färgbekräftelse
- Plattformen **plockar ut primary och secondary colors från den valda logotypen**
- Användaren får **bekräfta eller ändra** primary/secondary-tilldelningen

### Steg 5: Fullständigt formulär
- Användaren ser den **stora fullständiga brand-formuläret** med:
  - ✅ Alla textfält ifyllda (name, niche, location, phone, language, summary)
  - ✅ Vald logotyp visas
  - ✅ Hittade bilder från sidan visas som referensbilder
  - ✅ Färger visas — inklusive **extra färger funna på sidan** (CSS-scrapade)

### Steg 6: Spara
- Användaren kan **klicka Spara** direkt (allt är redan ifyllt)
- Eller **ändra vilket fält som helst** innan sparning

---

## Sammanfattning

```
URL → Fetch → Logotypval → Färgbekräftelse → Komplett formulär → Spara
```

Hela flödet är **sekventiellt** — varje steg visas ett i taget, i ordning.
Användaren behöver aldrig manuellt fylla i något om scraping hittar data.
