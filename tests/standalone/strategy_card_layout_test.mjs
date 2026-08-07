/**
 * Strategy card layout — controls sit UP in the header row, not in a strip below.
 *
 * History, two rounds:
 *  1. "4 strategies are eating all my brainpower" — every collapsed card carried
 *     ~10 dropdowns. Fixed by expand-gating them (owner pick (a)).
 *  2. "Move up the dropdowns and make them smaller as they are in the other
 *     modules so we keep design tokens the same and that we can make the
 *     strategy rows smaller" — the gated controls had landed in a separate
 *     padded strip (px-4 py-3 + its own border) BELOW the title, wasting the
 *     empty space beside it. They now render inside the identity row itself.
 *
 * Asserts ORDER, CONTAINMENT and the SIZE TOKENS in the real JSX rather than
 * re-testing React.
 *
 * Run: node tests/standalone/strategy_card_layout_test.mjs
 */

import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..', '..');
const SRC = readFileSync(join(ROOT, 'app/src/modules/Strategies/index.tsx'), 'utf8');
const LINES = SRC.split('\n');

let pass = 0, fail = 0;
const check = (name, ok, got) => {
  if (ok) { pass++; console.log(`  ok  ${name}`); }
  else { fail++; console.log(`FAIL  ${name}\n      got: ${JSON.stringify(got)}`); }
};

/** 1-indexed line of the first match at or after `from`, else null. */
const at = (needle, from = 0) => {
  for (let i = from; i < LINES.length; i++) if (LINES[i].includes(needle)) return i + 1;
  return null;
};

const header = at('Strategy header.');
const identity = at('flex flex-wrap items-center gap-x-3 gap-y-1.5');
const controls = at('Controls — UP in the header row');
const progress = at('Progress — belongs beside');
const icons = at('Icon utilities');
const generation = at('Line 3 — GENERATION');
const itemsGate = at('Expanded items list');
const bulk = at('Item bulk bar');

console.log('\n1. The landmarks exist');
for (const [n, v] of [['header', header], ['identity row', identity], ['controls cluster', controls],
  ['progress', progress], ['icon utilities', icons], ['generation cluster', generation],
  ['items gate', itemsGate], ['item bulk bar', bulk]]) {
  check(`${n} found`, v !== null, v);
}

console.log('\n2. Controls live INSIDE the header, not in a strip below it');
// The strip was the whole complaint: its own padding + border made the row tall.
check('the separate padded settings strip is gone', at('px-4 py-3') === null, 'strip still present');
check('controls come after the identity row opens', identity < controls, { identity, controls });
check('controls come before the progress bar', controls < progress, { controls, progress });
check('controls come before the icon utilities', controls < icons, { controls, icons });
check('generation cluster sits below, still inside the header',
  icons < generation && generation < itemsGate, { icons, generation, itemsGate });
check('everything precedes the item list', generation < itemsGate && itemsGate < bulk, { generation, itemsGate, bulk });

console.log('\n3. Still expand-gated — a collapsed card is one identity row');
for (const [label, line] of [['controls', controls], ['generation', generation]]) {
  // The gate wraps the cluster, so it can sit just BEFORE the comment (as the
  // generation cluster's does) or just after it — search a window either way.
  const near = LINES.slice(line - 4, line + 10);
  check(`${label}: gated within a few lines of its comment`,
    near.some((l) => l.includes('expandedId === strategy.id &&')), { line });
}
check('the header row itself is not gated (it always renders)',
  header < identity && at('expandedId === strategy.id &&', header) > identity,
  'identity row is behind the gate');

console.log('\n4. Design tokens match the per-item selects (h-7 / text-xs)');
const region = LINES.slice(controls, itemsGate - 1).join('\n');
const triggers = region.match(/<SelectTrigger className="[^"]*"/g) ?? [];
check('every SelectTrigger in the header is h-7', triggers.every((t) => t.includes('h-7')), triggers);
check('every SelectTrigger in the header is text-xs', triggers.every((t) => t.includes('text-xs')), triggers);
check('found the expected number of selects', triggers.length >= 6, triggers.length);
// The per-item override row is the reference the request pointed at.
const itemRegion = SRC.slice(SRC.indexOf('Inline overrides row'));
const itemTriggers = itemRegion.match(/<SelectTrigger className="h-7 w-\d+ text-xs"/g) ?? [];
check('per-item reference selects still h-7/text-xs', itemTriggers.length >= 3, itemTriggers.length);

console.log('\n5. Widths shrank, but not past the recorded truncation floors');
check('no w-44 wrappers left (generation cluster trimmed)', !region.includes('w-44 shrink-0'), 'w-44 remains');
check('generation cluster is w-40', (region.match(/w-40 shrink-0/g) ?? []).length >= 4,
  (region.match(/w-40 shrink-0/g) ?? []).length);
// Comments in the file record WHY these two cannot shrink further.
check('site select keeps w-40 (hostnames truncated at w-32)', region.includes('w-40 shrink-0'), 'site shrank');
// Control wrappers all carry a stopPropagation click; the w-24 progress-bar
// wrapper does not, and must not be mistaken for a control that shrank.
const narrowControls = LINES.slice(controls, itemsGate - 1).filter(
  (l) => /w-2[0-7] shrink-0/.test(l) && l.includes('stopPropagation'));
check('no control wrapper dropped below w-28', narrowControls.length === 0, narrowControls);

console.log('\n6. Rows got shorter');
check('header padding tightened to py-1.5', SRC.includes('className="px-4 py-1.5 cursor-pointer"'), 'py-2 still');
check('control cluster uses the tight gap', region.includes('gap-x-2 gap-y-1.5'), 'loose gap');
check('generation cluster uses the tight gap+margin', region.includes('gap-x-2 gap-y-1.5 mt-1.5'), 'loose gap');

console.log('\n7. Identity content stayed in the header');
const headerRegion = LINES.slice(header, itemsGate - 1).join('\n');
check('the name still renders', headerRegion.includes('{strategy.name}'));
check('the status badge stays', headerRegion.includes('<StatusBadge'));
check('the progress bar stays', headerRegion.includes('completedItems / strategy.totalItems'));
check('the expand chevron stays', headerRegion.includes('ChevronDown'));
check('the identity row can wrap (controls need somewhere to go)',
  headerRegion.includes('flex flex-wrap items-center gap-x-3'), 'no wrap');

console.log('\n8. A move, not a rewrite — handlers still wired');
for (const h of ['handlePublishingModeChange', 'handleSiteChange', 'handleApprovalChange',
  'handleImageModelChange', 'handleImageTemplateChange', 'handleTextModelChange']) {
  check(`${h} still wired`, SRC.includes(`${h}(strategy.id`), h);
}
check('controls still stop row-toggle propagation',
  region.includes('onClick={(e) => e.stopPropagation()}'), 'clicks would collapse the card');
check('the stale "settings panel below" comment is gone',
  !SRC.includes('SETTINGS PANEL'), 'stale comment');

console.log('\n' + '-'.repeat(56));
console.log(`  passed: ${pass}   failed: ${fail}`);
process.exit(fail > 0 ? 1 : 0);
