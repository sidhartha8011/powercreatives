# GAP — VERSIONED CONNECTOR FILENAMES (owner order 2026-07-17)
FACTS: three download spots serve fixed names (seohub/controller.php:72
pcm-connector.zip · :142 pcm-connector-{clientId}.zip · :162 generic);
the template version is parsed by DUPLICATED regex in service.php:395 +
:435 ("Version: x.y.z", currently 3.0.8).
DESIGN: ONE helper PCM_SEOHub_Service::connector_template_version()
replaces both regex spots (dedup) and stamps all three filenames:
pcm-connector-3.0.8.zip / pcm-connector-{clientId}-3.0.8.zip. No other
behavior changes.
CHECKLIST: [ ] helper + dedup · [ ] 3 filenames · [ ] php -l ·
[ ] harness 88/88 · [ ] changelog · [ ] AFTER commit LOCAL ONLY
