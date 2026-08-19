/**
 * Card 15 (the SEO Strategies master card) — the two NON-struck spec blocks, held to
 * the owner's LITERAL text (that is the acceptance criterion; the structure alone
 * had existed since 08-08 and he still had not struck them):
 *
 *  A. PUBLISHING SCHEDULE — "How should posts be released? [As content arrives]
 *     [On a schedule]"; for As content arrives: "Publishing limit — Publish up to [3]
 *     posts per [week]", "Extra posts are queued until capacity is available.",
 *     "Stop publishing ○ Never ○ On [date] ○ After [20] posts"; Summary:
 *     "Publishes automatically, up to 3 posts per week. Extra posts are queued. Runs
 *     indefinitely." (or the stop rule).
 *  B. CONTENT — "three groups inside one Content section … Use toggle for respective
 *     addition — toggle on visuals, toggle on research, then underneath you have the
 *     settings properly": VISUALS (Generate featured image / Include in-content images
 *     & charts / In-content visuals Type+Count / Visual instructions (Optional) / Image
 *     prompt / Image AI model), LINKING (Auto-interlink after generation), RESEARCH
 *     (Research depth: Search landscape "Review what currently ranks", Questions & data
 *     "Find real questions and chart-ready statistics", Competitor gaps "Identify what
 *     competing articles miss", Research model).
 *
 * Structural pins only — the payload contract is guarded by strategy_dialog_layout_test.
 * Run: node tests/standalone/strategy_dialog_card15_spec_test.mjs
 */
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..', '..');
const src = readFileSync(join(ROOT, 'app/src/modules/Keywords/CreateStrategyDialog.tsx'), 'utf8');
let pass = 0, fail = 0;
const check = (name, ok, got) => { if (ok) { pass++; console.log(`  ok  ${name}`); } else { fail++; console.log(`FAIL  ${name}\n      got: ${JSON.stringify(got)}`); } };
const slice = (a, b) => { const i = src.indexOf(a); const j = src.indexOf(b, i + 1); return i >= 0 && j > i ? src.slice(i, j) : ''; };
const pub = slice('<AccordionSection title="Publishing"', '<AccordionSection title="Content"');
const content = slice('<AccordionSection title="Content"', 'Generation starts automatically in the background after creation.');
const between = (hay, a, b) => { const i = hay.indexOf(a); const j = hay.indexOf(b, i + 1); return i >= 0 && j > i ? hay.slice(i, j) : ''; };

console.log('\nA. Publishing schedule — the owner’s words');
check('"How should posts be released?" heads BOTH branches (source + keyword) — the rendered labels', (pub.match(/>\s*How should posts be released\?\s*<\/Label>/g) || []).length === 2, pub.match(/>\s*How should posts be released\?\s*<\/Label>/g));
check('source options: [As content arrives] [On a schedule]', pub.includes("label: 'As content arrives'") && !pub.includes("label: 'As posts arrive'"));
check('keyword options: [On demand] [On a schedule]', pub.includes("label: 'On demand'") && pub.includes("label: 'On a schedule'"));
check('"Publishing limit — Publish up to [N] posts per [unit]"', /Publishing limit<\/Label>[\s\S]{0,300}?Publish up to<\/span>[\s\S]{0,600}?posts per<\/span>/.test(pub), 'limit wording');
check('"Extra posts are queued until capacity is available."', pub.includes('Extra posts are queued until capacity is available.'));
check('Summary line: "Publishes automatically, up to N posts per unit. Extra posts are queued. Runs indefinitely." — or the stop rule',
  /\{publishing === 'auto' \? 'Publishes automatically' : 'Saves each article as a draft'\}, up to\{' '\}/.test(pub)
  && pub.includes('Extra posts are queued.') && pub.includes("'Runs indefinitely.'") && pub.includes('Stops on') && pub.includes('Stops after'));
check('Stop publishing: Never / On a date / After N posts', pub.includes(">Stop publishing</Label>") && pub.includes("label: 'Never'") && pub.includes("label: 'On a date'") && pub.includes("label: 'After N posts'"));
check('…gated to As-content-arrives (scheduled mode’s Ends IS the duration — "which one wins" never returns)', /\{sourceMode !== 'keywords' && !socialScheduled && \(/.test(pub));
check('scheduled branch keeps Repeat / On days / Start (RecurrenceEditor + start date)', pub.includes('<RecurrenceEditor') && pub.includes('type="date"'));
check('no separate Duration accordion anywhere', !src.includes('<AccordionSection title="Duration"'));

console.log('\nB. Content — three groups, each with its toggle, settings underneath');
const gV = between(content, '>Visuals</Label>', '>Linking</Label>');
const gL = between(content, '>Linking</Label>', '>Research</Label>');
const gR = between(content, '>Research</Label>', '</AccordionSection>');
check('the three groups exist in order: Visuals → Linking → Research', gV.length > 0 && gL.length > 0 && gR.length > 0);
check('VISUALS header carries its switch (on = featuredImages || inContentMedia)', /<Switch\s+id="visuals-on"[\s\S]{0,200}?checked=\{featuredImages \|\| inContentMedia\}/.test(gV), 'no visuals switch');
check('…off clears both, on restores the featured image', /onCheckedChange=\{\(on\) => \{ if \(on\) \{ setFeaturedImages\(true\); \} else \{ setFeaturedImages\(false\); setInContentMedia\(false\); \} \}\}/.test(gV));
check('…and the visuals settings render ONLY while on', /\{\(featuredImages \|\| inContentMedia\) && \(/.test(gV));
check('VISUALS copy: "Generate featured image" / "Include in-content images & charts"', gV.includes('Generate featured image') && gV.includes('Include in-content images &amp; charts'));
check('VISUALS: "In-content visuals" Type + Count, "Visual instructions (Optional)"', gV.includes('>In-content visuals</span>') && gV.includes('Type:</span>') && gV.includes('Count:</span>') && /Visual instructions <span[^>]*>Optional<\/span>/.test(gV));
check('VISUALS: Image prompt + Image AI model live INSIDE the group', gV.includes('id="image-prompt"') && gV.includes('id="image-model"'));
check('LINKING header carries the auto-interlink switch; options underneath when on', /<Switch\s+id="auto-interlink"[\s\S]{0,160}?checked=\{autoInterlink\}/.test(gL) && gL.includes('Auto-interlink after generation') && /\{autoInterlink && \(/.test(gL));
check('RESEARCH header carries its switch (on = any pass); off clears all three', /<Switch\s+id="research-on"[\s\S]{0,200}?checked=\{researchLandscape \|\| researchQuestions \|\| researchGaps\}/.test(gR)
  && /setResearchLandscape\(false\); setResearchQuestions\(false\); setResearchGaps\(false\);/.test(gR));
check('…settings render only while on, under a "Research depth" label', /\{\(researchLandscape \|\| researchQuestions \|\| researchGaps\) && \(/.test(gR) && gR.includes('>Research depth</Label>'));
check('RESEARCH copy: the three explainers, verbatim', gR.includes('Review what currently ranks') && gR.includes('Find real questions and chart-ready statistics') && gR.includes('Identify what competing articles miss'));
check('RESEARCH: "Research model" select in the group', gR.includes('id="research-model"') && /Research model\s*<\/Label>/.test(gR));
check('payload untouched: the same state names feed handleSave', /featuredImages,/.test(src) && /inContentMedia,/.test(src) && /researchPasses/.test(src) && /interlinksConfig/.test(src));

console.log('\n' + '-'.repeat(60));
console.log(`  passed: ${pass}   failed: ${fail}`);
process.exit(fail > 0 ? 1 : 0);
