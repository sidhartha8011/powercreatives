# Generation Marketplace (Top Rated)

## Vision
Publik/delad modul som visar topprankade generationer baserat på användarnas ratings — inspirationsbibliotek med möjlighet att regenerera med exakt samma inställningar.

## Syfte
Let users discover the best generations across the platform and reproduce them.

## Målbild
- [ ] Ny modul: "Marketplace" eller "Discover" i sidopanelen
- [ ] Visar topprankade generationer (baserat på ratings från Generation Log)
- [ ] **Regenerate-knapp** — reproducera en generation med exakt samma inställningar
- [ ] Filtrerbar per modul (Image/Video/Copy), provider, model
- [ ] Preview-galleri med rating-visning

## Beroenden
- Kräver: **Generation Log + Rating System** (måste finnas först)
- Kräver: Sparade inställningar per generering (från loggen)

## Berörda delar
- Ny modul: `modules/marketplace/` (config, controller, service)
- Frontend: ny tab i sidopanelen
- Läser från `pcm_generation_log`-tabellen
