## Backlog

- [/] Unifiera kopieringskortets typografi med granskningssidans globala klasser (pcm-copy-headline och pcm-copy-text) för rubrik och beskrivning för att eliminera alla hårdkodade Tailwind-stilar. [2026-05-24]
- [x] Uppdatera och polera inline-textredigeringen för kopieringskort till en pixel-perfekt Facebook Link Preview-overlay med stöd för beskrivning (description) och direktklick-fokus på enskilda textelement. [2026-05-24]
- [x] Lägg till inline-textredigering (rubrik via text-input, brödtext via TiptapBodyEditor) och automatisk sparning vid klick utanför för kopieringskort på klientgranskningssidan, med DB-synk och gränssnittsuppdatering för inloggade teammedlemmar. [2026-05-24]
- [ ] Lär upp AI URL Scraper i backend att identifiera och hämta ISO-certifikat och Trust Badges automatiskt när man fetchar en hemsida. Detta är Phase 2 av Certifikat-migrationen.
- [x] Fixa problem med klientdelningsflöde i Ads-modulen (åtgärda urklippskopiering och ogiltig delningslänk/WordPress 404). [2026-05-23]
- [x] Fixa bugg i Ads-modulen där varumärkets logotyp inte skickades med vid bildgenerering (synkronisera logotypen och referensbilder i Ads-sidofältets livscykel). [2026-05-23]
- [x] Fixa bugg i Ads-modulen där valda antal publiker och varianter inte respekterades av backend-generatorn (enforce och slica AI-responser till exakta valda antal). [2026-05-23]
- [x] Polera och förfina Ads-modulen: åtgärda TypeScript-typfelskontrollfel, rensa oanvända importer och argument, samt implementera en interaktiv avbrytningsfunktion för generering. [2026-05-23]
- [x] Aliniera och unifiera Ads-modulens copywriting och bildgenerering (AI Enhance, Angles-slider och orchestrator hook). [2026-05-23]
- [x] Konvertera SVG-logotyper till PNG vid lagring (Imagick rasterisering). AI-bildmodeller kan inte bearbeta SVG-vektorfiler. Automatisk konvertering vid uppladdning och URL-hämtning + v1.7.0 databas-migration för befintliga SVG-tillgångar. [2026-05-23]
- [x] Fixa saknad `role`-parameter i referensbilds-sparning. Fetch Brand och ReferenceImageSelector skickade inte `role: 'reference'` till backend, vilket gjorde att alla referensbilder tyst avvisades med 400 "Asset role is required." [2026-05-23]
