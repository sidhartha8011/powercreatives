---
description: Verify implementation end-to-end for production readiness. Run after every implementation before marking anything as done.
---

## Verifiera implementation — End-to-end (Production Ready)

AGERA SOM SENIOR DEV.

Du har just implementerat ändringar. INNAN du säger att något är klart, gå igenom detta. Allt gäller DINA ÄNDRINGAR — inte hela kodbasen.

FÖR VARJE STEG: Fyll i checklistan med KONKRETA VÄRDEN — filnamn, radnummer, faktiska värden. Tomma rutor eller generiska fraser = INTE GODKÄNT.

DU MÅSTE FÖLJA EXAKT ALLA STEG I DENNA LISTAN NEDAN OCH ALDRIG HOPPA ÖVER NÅGOT AV STEGEN.

---

### 1. Användarens perspektiv först
Beskriv exakt vad slutanvändaren gör, ser och upplever — steg för steg. Vad klickar de på? Vad visas? Vilken feedback får de? Vad är det förväntade slutresultatet? Om du inte kan beskriva detta — du förstår inte vad du bygger.

```
- [ ] Användaren gör: ___
- [ ] Användaren ser: ___
- [ ] Feedback som visas: ___
- [ ] Förväntat slutresultat: ___
```

---

### 2. Arkitektur och patterns
Läs projektets arkitekturdokument INNAN du granskar.

- Följer dina ändringar befintliga patterns i kodbasen? Om inte — varför inte, och är avvikelsen motiverad?
- Har du uppfunnit en ny lösning (ny helper, ny abstraktion, ny pattern)? Om ja: fanns det redan en befintlig lösning i kodbasen eller ett etablerat industry-standard-sätt? Om ja — använd det istället.
- Alla nya funktioner/komponenter du skapar: kan de återanvändas av andra moduler? Om en funktion bara löser ett lokalt problem och aldrig kommer användas igen — refaktorera till en generell lösning ELLER motivera explicit varför den är scoped.

```
- [ ] Läst arkitekturdokument: ___
- [ ] Följer befintliga patterns: [JA: vilka / NEJ: varför]
- [ ] Nya funktioner skapade: [lista eller INGA]
  - Befintlig lösning fanns? [JA → byt / NEJ → motivering]
  - Återanvändbar? [JA / NEJ → motivering]
```

---

### 3. Spåra med faktiska värden — VARJE lager
LISTA ALLA FILER SOM DU HAR ARBETAT MED OCH LISTA SEDAN DERAS DEPENDENCY FILER FÖR ATN HÖG OVERVIEW ÖVER HELA FLÖDET OCH ALLA DEPENDENCIES.

Spåra det genom VARJE fil i kedjan med KONKRETA VÄRDEN — inte variabelnamn. Skriv ut vad varje variabel innehåller på varje rad som om du vore en debugger. Om kedjan har 5 lager ska du visa alla 5 — du får INTE hoppa över mellanliggande lager och anta att de "bara passerar vidare".

```
- [ ] Scenario: ___
- [ ] Lager 1 [fil:rad]: variabel = ___
- [ ] Lager 2 [fil:rad]: variabel = ___
- [ ] Lager 3 [fil:rad]: variabel = ___
- [ ] ... (alla lager, inga hopp)
```

---

### 4. Param-namn sida vid sida vid VARJE gräns
Skriv en tabell: vad avsändaren skickar (nycklar) vs vad mottagaren läser (nycklar). Gör detta vid VARJE gräns — inte bara den första och sista. Om en enda nyckel inte matchar — det är en bugg.

```
Gräns: [avsändare] → [mottagare]
- [ ] [param]: skickar [nyckel] → läser [nyckel] → MATCH/MISMATCH

Gräns: [nästa] → [nästa]
- [ ] [param]: skickar [nyckel] → läser [nyckel] → MATCH/MISMATCH
```

---

### 5. Varje default är en potentiell dold bugg
Lista VARJE ställe som har `?? 'fallback'`, `|| default`, eller liknande. För varje: KAN detta triggas i normal användning? Om ja — döljer det ett fel istället för att exponera det? Om det döljer — det är en bugg.

```
- [ ] [fil:rad]: `?? 'X'` — Triggas normalt? [JA/NEJ] — Döljer fel? [JA=BUGG / NEJ]
```

---

### 6. Response-konsumtion
Visa exakt vilka fält backend returnerar. Visa exakt vilka fält frontend läser. Matchar de? Vad händer om ett fält är null eller saknas?

```
- [ ] Backend returnerar: { fält: typ, fält: typ }
- [ ] Frontend läser: result.fält, result.fält
- [ ] Match: [JA / lista mismatches]
- [ ] Null-hantering: ___
```

---

### 7. All UI-feedback: toasts, status, felmeddelanden
Lista VARJE toast, statustext och UI-element i berörda filer. I vilket scope kör det? Kan en success-toast visas när det failade? Kan en "processing"-status hänga kvar?

```
- [ ] [fil:rad]: [typ: success/error] — scope: [try/catch/utanför] — Kan visas felaktigt? [JA=BUGG / NEJ]
```

---

### 8. Edge cases
- Timeout eller nätverksfel?
- API returnerar success men utan data?
- Mellanliggande lager kastar exception — fångas det korrekt, och visar det rätt meddelande?
- Användaren triggar samma operation två gånger — dubbletter? Dubbel debitering?

```
- [ ] Timeout: hanteras av [fil:rad] — visar ___
- [ ] API success utan data: hanteras av [fil:rad] — visar ___
- [ ] Exception mellanliggande: fångas av [fil:rad]
- [ ] Dubbel-klick: skydd finns [JA: fil:rad / NEJ=RISK]
```

---

### 9. Säkerhet (scoped till ändringarna)
- Saneras all user input innan den når DB, filsystem eller extern API?
- Kan en obehörig användare trigga din endpoint?
- Loggar du känslig data (API-nycklar, lösenord) av misstag?

```
- [ ] Input saneras: [JA: var / NEJ=BUGG]
- [ ] Auth krävs: [JA: fil:rad / NEJ=BUGG]
- [ ] Känslig data loggas inte: [BEKRÄFTAT / BUGG: fil:rad]
```

---

### 10. Data-integritet (scoped till ändringarna)
- Påverkar dina ändringar befintlig data? Kan de korrumpera?
- Om din ändring skriver till DB/fil — vad händer vid halvfärdigt tillstånd (crash mitt i)?

```
- [ ] Påverkar befintlig data: [NEJ / JA: beskriv]
- [ ] Crash mitt i: [säkert / risk: beskriv]
```

---

### 11. Breaking changes och cache
Har du ändrat vad backend kräver (nya required params, borttagna defaults, ändrade response-shapes)? Skickar frontend GARANTERAT det — inte bara i ny kod, utan i cachad JS?

```
- [ ] Kontrakt ändrat: [NEJ / JA: lista]
- [ ] Frontend skickar nya params: [BEKRÄFTAT i build / OBEKRÄFTAT]
- [ ] Cache-risk: [NEJ / JA: åtgärd]
```

---

### 12. Regression
Fungerar allt som fungerade INNAN dina ändringar fortfarande? Har du brutit något existerande flöde?

```
- [ ] Existerande flöden: [lista testade]
- [ ] Något brutet: [NEJ / JA: fixat i fil:rad]
```

---

### 13. Hitta ALLA problem — och FIXA dem
Lista varje fynd. Upprepa tills noll 🔴 kvarstår.

```
- [✅/⚠️/🔴] [fil:rad]: ___ — Åtgärd: ___
```

---

### 14. Runtime-bevis
Om du kan köra koden — gör det. Om du inte kan — konstruera ett manuellt testanrop (curl eller liknande) och visa exakt vad backend returnerar. Om du varken kan exekvera eller testa — säg det EXPLICIT och markera som **OFULLSTÄNDIG**. "Build succeeded" är INTE verifiering.

```
- [ ] Metod: [exekverat / curl / manuellt spårat / KAN INTE]
- [ ] Resultat: ___ ELLER **OFULLSTÄNDIG**
```

---

### 15. Slutrapport till PO
Fyll i med konkreta värden — INTE generiska fraser:

```
EXAKT VERIFIERING AV IMPLEMENTATION: [feature-namn]

Användaren upplever:
1. [steg] → [resultat]
2. [steg] → [resultat]

Arkitektur:      [OK / avvikelser]
Återanvändbarhet: [OK / nya funktioner + motivering]
Param-match:     [OK / N fixade]
Defaults:        [OK / N borttagna]
UI-feedback:     [OK / N fixade]
Säkerhet:        [OK / N findings]
Data-integritet: [OK / N findings]
Regression:      [OK / N fixade]
Runtime:         [Bekräftat / OFULLSTÄNDIGT]

Kvarvarande risker: [inga / lista]
```

---

### REGLER
- Du får INTE skriva "verifierat" eller "allt fungerar" utan ifyllda checklistor med konkreta värden
- Du får INTE stanna vid första fyndet — hitta ALLA
- Du får INTE rapportera buggar utan att fixa dem
- Du får INTE uppfinna nya lösningar om befintliga patterns/standards finns
- Tomma checklistor eller generiska fraser = INTE GODKÄNT
- Om du inte kan bevisa ett steg — säg det explicit, ljug inte
- Du får INTE Generera en walkthrough du ska generea en slutrapport som i 15 i en enkel bulletlist.