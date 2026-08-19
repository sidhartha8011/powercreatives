/**
 * Create-Strategy dialog — the SEO-posting card's remaining layout asks.
 *
 * PDF (Add/SEO Strategies/SEO posting), non-struck items:
 *  3. "The keyword input should be in the top of the box" — inputs BEFORE the
 *     template/model pickers inside Source.
 *  4. "We consolidate Publishing + Schedule + Duration into just Publishing" —
 *     one Publishing accordion holding the schedule; the separate Duration
 *     accordion REMOVED ("in scheduled mode, Ends already is duration"), its
 *     fields living on as "Stop publishing", shown only for as-posts-arrive.
 *  5. Content reorganised into three labelled groups: VISUALS / LINKING /
 *     RESEARCH ("keep everything but consolidate it into three groups").
 *
 * Everything here is a MOVE, not a rewrite: the payload keys and control ids
 * must survive unchanged, and each control must exist exactly once (a botched
 * block move duplicates or drops — both are asserted).
 *
 * Run: node tests/standalone/strategy_dialog_layout_test.mjs
 */

import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..', '..');
const src = readFileSync(join(ROOT, 'app/src/modules/Keywords/CreateStrategyDialog.tsx'), 'utf8');

let pass = 0, fail = 0;
const check = (name, ok, got) => {
  if (ok) { pass++; console.log(`  ok  ${name}`); }
  else { fail++; console.log(`FAIL  ${name}\n      got: ${JSON.stringify(got)}`); }
};
const at = (needle) => src.indexOf(needle);

console.log('\n1. Three accordions — Schedule & cadence and Duration are GONE');
const sections = [...src.matchAll(/<AccordionSection title="([^"]+)"/g)].map((m) => m[1]);
check('exactly Source, Publishing, Content', JSON.stringify(sections) === '["Source","Publishing","Content"]', sections);
check('no separate Duration accordion', !sections.includes('Duration'), sections);
check('no separate Schedule & cadence accordion', !sections.includes('Schedule & cadence'), sections);

console.log('\n2. Keyword input at the TOP of the Source box');
check('the primary-keyword input renders before the Template picker',
  at('id="manual-primary"') !== -1 && at('id="source-template"') !== -1
  && at('id="manual-primary"') < at('id="source-template"'),
  { primary: at('id="manual-primary"'), template: at('id="source-template"') });
check('…and before the Model picker too', at('id="manual-primary"') < at('id="source-model"'));
check('RSS feeds input also precedes the pickers (same rule, other tab)',
  at('id="rss-angle"') < at('id="source-template"'), { rss: at('id="rss-angle"'), template: at('id="source-template"') });

console.log('\n3. Publishing swallowed the schedule and the duration');
const pubStart = at('<AccordionSection title="Publishing"');
const contentStart = at('<AccordionSection title="Content"');
const pub = src.slice(pubStart, contentStart);
check('Publishing region is locatable', pubStart !== -1 && contentStart > pubStart, { pubStart, contentStart });
check('the schedule sub-header lives inside Publishing', pub.includes('>Publishing schedule</Label>'), 'schedule elsewhere');
check('the release-mode toggle is inside Publishing',
  // Card 15 spec wording (2026-08-18): "How should posts be released? [As content arrives] [On a schedule]"
  pub.includes("label: 'As content arrives'") && pub.includes("label: 'On a schedule'") && pub.includes('How should posts be released?'), 'toggle elsewhere');
check('Stop publishing lives inside Publishing', pub.includes('>Stop publishing</Label>'), 'duration elsewhere');
check('Stop publishing is GATED to as-posts-arrive (scheduled mode keeps its own Ends)',
  /\{sourceMode !== 'keywords' && !socialScheduled && \(/.test(pub), 'always visible — "which one wins" returns');
check('duration options relabelled for the new home (Never / On a date / After N posts — card 15 spec)',
  pub.includes("label: 'Never'") && pub.includes("label: 'On a date'") && pub.includes("label: 'After N posts'"),
  'old Ongoing/Until labels');
// Card 15 spec: "Publishing limit — Publish up to [3] posts per [week]. Extra posts are queued until capacity is
// available." + the Summary line "Publishes automatically, up to 3 posts per week. Extra posts are queued. Runs indefinitely."
check('cadence + queue note kept (spec wording)', pub.includes('id="rss-per-week"') && pub.includes('Publishing limit') && pub.includes('Extra posts are queued until capacity is available.') && pub.includes("'Runs indefinitely.'"), 'cadence lost');
check('recurrence + start date kept for scheduled mode', pub.includes('<RecurrenceEditor') && pub.includes('Leave blank to start today.'), 'schedule fields lost');

console.log('\n4. Content is three labelled groups, in order');
const content = src.slice(contentStart);
const gV = content.indexOf('>Visuals</Label>');
const gL = content.indexOf('>Linking</Label>');
const gR = content.indexOf('>Research</Label>');
check('all three group headers exist', gV !== -1 && gL !== -1 && gR !== -1, { gV, gL, gR });
check('in VISUALS → LINKING → RESEARCH order', gV < gL && gL < gR, { gV, gL, gR });
const between = (a, b, needle) => { const i = content.indexOf(needle); return i > a && (b === -1 || i < b); };
check('featured-images toggle is in Visuals', between(gV, gL, 'id="featured-images"'));
check('in-content media settings are in Visuals', between(gV, gL, 'id="in-content-media"'));
check('the image prompt + image model grid is in Visuals (not floating on top)',
  between(gV, gL, 'id="image-prompt"') && between(gV, gL, 'id="image-model"'),
  { imgPrompt: content.indexOf('id="image-prompt"') });
check('auto-interlink is in Linking', between(gL, gR, 'id="auto-interlink"'));
check('research depth checkboxes are in Research',
  between(gR, -1, 'id="research-landscape"') && between(gR, -1, 'id="research-gaps"'));
check('the research model select is in Research', between(gR, -1, 'id="research-model"'));
check('research checkboxes keep their explainer sublabels (card 15 spec wording)',
  content.includes('Review what currently ranks') && content.includes('Identify what competing articles miss') && content.includes('Find real questions and chart-ready statistics'), 'sublabels lost');

console.log('\n5. A move, not a rewrite — payload contract and controls intact');
for (const id of ['manual-primary', 'manual-supporting', 'source-template', 'source-model',
                  'image-prompt', 'image-model', 'auto-interlink', 'research-landscape',
                  'rss-per-week', 'duration-end', 'duration-max', 'target-site']) {
  const n = (src.match(new RegExp(`id="${id}"`, 'g')) ?? []).length;
  check(`control ${id} exists exactly once`, n === 1, n);
}
for (const key of ['durationMode', 'durationEndDate', 'durationMaxArticles', 'rssPerWeek', 'socialScheduled']) {
  check(`state ${key} still wired`, src.includes(key), key);
}
check('the payload still carries duration (until/limit modes emit as before)',
  src.includes("{ mode: 'until', endDate: durationEndDate }")
  && src.includes("{ mode: 'limit', maxArticles: durationMaxArticles }"), 'payload changed');

console.log('\n6. Earlier non-struck items pinned (done in prior rounds)');
const strat = readFileSync(join(ROOT, 'app/src/modules/Strategies/index.tsx'), 'utf8');
check('New Strategy button on the Strategies page', strat.includes('setCreateOpen(true)') && strat.includes('New Strategy'));
check('source info on the meta row', (strat.match(/rssFeeds\.map\(feedHost\)/g) ?? []).length === 1);

console.log('\n' + '-'.repeat(60));
console.log(`  passed: ${pass}   failed: ${fail}`);
process.exit(fail > 0 ? 1 : 0);
