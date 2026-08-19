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
// 2026-08-19: the cluster (11 controls wide by now) still lives INSIDE the header block, but
// breaks to its own full-width line (basis-full) instead of sharing the identity row — the two
// flex children could both shrink, crushing the name/badges and painting the controls over the
// meta line ("the UI is overlapping"). Same tokens, same gating; the header is one row taller.
const controls = at('Controls — on their OWN full-width row');
check('the controls cluster forces its own line inside the header (basis-full, light top rule)',
  SRC.includes('className="flex flex-wrap items-center gap-x-2 gap-y-1.5 basis-full pt-2 mt-1"') && !SRC.includes('justify-end gap-x-2 gap-y-1.5 ml-auto min-w-0'), 'controls share the identity row again');
check('the identity line wraps its badges rather than crushing them', SRC.includes('<div className="flex flex-wrap items-center gap-2">') && SRC.includes('rounded-full shrink-0 whitespace-nowrap'), 'badges can crush');
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
  const near = LINES.slice(line - 4, line + 14); // the controls comment is 12 lines long since 2026-08-19
  check(`${label}: gated within a few lines of its comment`,
    near.some((l) => l.includes('expandedId === strategy.id &&')), { line });
}
check('the header row itself is not gated (it always renders)',
  header < identity && at('expandedId === strategy.id &&', header) > identity,
  'identity row is behind the gate');

console.log('\n4. Design tokens come from the shared component, not from here');
// SUPERSEDED 2026-08-08: this section used to require `h-7 text-xs` ON each
// trigger. That is exactly the drift the app-wide dropdown unification removed
// — height/font/background now live in selectTriggerVariants, and a class here
// would BEAT the base (cn() runs tailwind-merge) and desynchronise this module
// from every other one. The rule is now the inverse: carry layout only.
const region = LINES.slice(controls, itemsGate - 1).join('\n');
const triggers = region.match(/<SelectTrigger[^>]*className="[^"]*"/g) ?? [];
check('found the expected number of selects', triggers.length >= 6, triggers.length);
check('no trigger here sets its own height',
  triggers.every((t) => !/\bh-\d+\b/.test(t)), triggers.filter((t) => /\bh-\d+\b/.test(t)));
check('no trigger here sets its own font size',
  triggers.every((t) => !/\btext-(?:xs|sm|base)\b/.test(t)), triggers.filter((t) => /\btext-/.test(t)));
check('no trigger here sets its own background',
  triggers.every((t) => !/\bbg-\w/.test(t)), triggers.filter((t) => /\bbg-\w/.test(t)));
// Widths are still this module's business — they were tuned against truncation.
check('triggers still carry their per-context width',
  triggers.every((t) => /\bw-/.test(t)), triggers.filter((t) => !/\bw-/.test(t)));

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

console.log('\n8. Source sits on the SAME row as the completion count');
// Owner, 2026-08-08: "put the source information on the same row as the 3/3
// items". RSS/Social feeds used to render as a THIRD line below the meta line,
// so every sourced card was taller than a keyword one just to show a hostname.
// Extract the meta line's own element and require the source to live inside it.
const metaOpen = LINES.findIndex((l) => l.includes('{strategy.completedItems}/{strategy.totalItems}'));
check('the meta line was found', metaOpen !== -1, metaOpen);
// Walk back to the element that opens it, forward to where it closes.
let mStart = metaOpen;
while (mStart > 0 && !LINES[mStart].includes('<div')) mStart--;
let depth = 0, mEnd = mStart;
for (let i = mStart; i < LINES.length; i++) {
  depth += (LINES[i].match(/<div\b/g) ?? []).length - (LINES[i].match(/<\/div>/g) ?? []).length;
  if (depth === 0 && i > mStart) { mEnd = i; break; }
}
const metaRow = LINES.slice(mStart, mEnd + 1).join('\n');
check('the RSS source renders inside the meta row', /rssFeeds\.map\(feedHost\)/.test(metaRow), 'RSS still a separate line');
check('the Social source renders inside the meta row', /socialFeedLinks\.map\(feedHost\)/.test(metaRow), 'Social still a separate line');
check('the source keeps its icon', /<Rss /.test(metaRow) && /<Share2 /.test(metaRow), 'icons dropped');
// BOTH source segments need their own separator — checking "a dot exists
// somewhere in the row" passes while one of the two is broken.
check('each source segment is separated by its own dot',
  (metaRow.match(/shrink-0">·</g) ?? []).length === 2,
  (metaRow.match(/shrink-0">·</g) ?? []).length);
// Layout guards: a long feed list must shorten ITSELF, not shove the counts out.
// Assert on the ROW'S OPENING TAG specifically — the inner source spans carry
// the same class string, so a row-wide search passes even when the outer
// container has lost min-w-0 and can no longer shrink.
const metaOpenTag = metaRow.slice(0, metaRow.indexOf('>') + 1);
check('the row itself is a flex line that can shrink',
  /flex items-center gap-1 min-w-0/.test(metaOpenTag), metaOpenTag.replace(/\s+/g, ' ').trim());
check('the counts never truncate', /<span className="shrink-0">\s*\n\s*\{strategy\.completedItems\}/.test(metaRow), 'counts can be squeezed');
check('long feed lists truncate instead', (metaRow.match(/truncate/g) ?? []).length >= 2, 'no truncation');
check('full URLs stay reachable in the tooltip',
  /title=\{rssFeeds\.join/.test(metaRow) && /title=\{socialFeedLinks\.join/.test(metaRow), 'tooltip lost');
// The old third line must be gone, not merely duplicated.
check('no leftover standalone source line',
  (SRC.match(/rssFeeds\.map\(feedHost\)/g) ?? []).length === 1, 'source rendered twice');

console.log('\n9. A move, not a rewrite — handlers still wired');
for (const h of ['handlePublishingModeChange', 'handleSiteChange', 'handleApprovalChange',
  'handleImageModelChange', 'handleImageTemplateChange', 'handleTextModelChange']) {
  check(`${h} still wired`, SRC.includes(`${h}(strategy.id`), h);
}
check('controls still stop row-toggle propagation',
  region.includes('onClick={(e) => e.stopPropagation()}'), 'clicks would collapse the card');
check('the stale "settings panel below" comment is gone',
  !SRC.includes('SETTINGS PANEL'), 'stale comment');

console.log('\n10. ONE bulk surface: the floating bar, two modes (owner corrected: keep the BOTTOM one)');
// v1 of this fix hid the bottom bar while items were ticked; the owner wanted
// the OPPOSITE — the inline card strip removed and the bottom bar to carry the
// item actions. One container, item mode first, strategy mode otherwise.
check('the floating bar shows for item OR strategy selections',
  SRC.includes("view === 'list' && (selectedItemCount > 0 || selectedIds.size > 0) && ("),
  'bar gating changed');
check('item mode wins when items are ticked', SRC.includes('{selectedItemCount > 0 ? ('), 'no mode split');
check('the inline card strip is GONE',
  !SRC.includes('handleGenerateSelected(strategy, ids)'), 'top strip back');
check('item groups derive from the owning strategies',
  SRC.includes('.filter((it) => selectedItemIds.has(it.id)).map((it) => it.id)'), 'groups rewired');
check('Linking disables on a cross-strategy selection',
  SRC.includes('disabled={itemBulkBusy || itemGroups.length !== 1}'), 'cross-card linking allowed');
check('there is exactly ONE floating-bar container',
  (SRC.match(/fixed left-1\/2 -translate-x-1\/2 bottom-6/g) || []).length === 1, 'two bars again');

console.log('\n11. HOOK ORDER LAW — no hook below the loading return (React #310)');
// The itemGroups useMemo originally landed AFTER `if (isLoading) return …`, so
// the loading render counted one fewer hook than the loaded render and React
// crashed the whole module to the error screen (minified #310). Every hook in
// StrategiesModule must be declared before its first early return.
const modStart = SRC.indexOf('export function StrategiesModule()');
const modEnd = SRC.indexOf('\nfunction AutoScanStatus()');
const mod = SRC.slice(modStart, modEnd === -1 ? undefined : modEnd);
check('component + boundary located', modStart !== -1 && modEnd !== -1, { modStart, modEnd });
const loadingAt = mod.indexOf('if (isLoading) {');
check('the loading early return exists', loadingAt !== -1, loadingAt);
const below = mod.slice(loadingAt);
const hooksBelow = below.match(/=\s*use(State|Memo|Callback|Ref|Query|Mutation)\(|\buse(Effect|LayoutEffect)\(/g) ?? [];
check('NO hook is declared below the early return', hooksBelow.length === 0, hooksBelow);
check('the itemGroups memo sits ABOVE the loading return',
  mod.indexOf('const itemGroups = useMemo(') !== -1 && mod.indexOf('const itemGroups = useMemo(') < loadingAt,
  { memoAt: mod.indexOf('const itemGroups = useMemo('), loadingAt });

console.log('\n' + '-'.repeat(56));
console.log(`  passed: ${pass}   failed: ${fail}`);
process.exit(fail > 0 ? 1 : 0);
