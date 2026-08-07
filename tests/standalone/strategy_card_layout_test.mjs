/**
 * Strategy card layout — controls live behind the expand, not on the card.
 *
 * Reported: "4 strategies are eating all my brainpower". Every collapsed card
 * carried ~10 dropdowns (status, site, approval, schedule, template, model,
 * image prompt, image model, …) across two wrapped rows, so four strategies
 * rendered forty controls before you had read a single name.
 *
 * Owner pick (a): collapse them into the expanded view — a closed card is ONE
 * identity row; expanding reveals the settings panel, then the items.
 *
 * This asserts ORDER and CONTAINMENT in the real JSX rather than re-testing
 * React: every control marker must sit after the expand gate and before the
 * item list, and none may remain in the header.
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

/** 1-indexed line of the first match, or null. */
const at = (needle) => {
  const i = LINES.findIndex((l) => l.includes(needle));
  return i === -1 ? null : i + 1;
};

const header = at('Strategy header');
const gate = at('Expanded items list');
const panel = at('SETTINGS PANEL (owner pick');
const bulk = at('Item bulk bar');

console.log('\n1. The landmarks still exist');
for (const [n, v] of [['header', header], ['expand gate', gate], ['settings panel', panel], ['item bulk bar', bulk]]) {
  check(`${n} found`, v !== null, v);
}

console.log('\n2. Every per-strategy control sits INSIDE the expanded view');
// The controls the report named, plus the rest of the row they travelled with.
const CONTROLS = [
  ['Publishing Mode ', 'status'],
  ['Target Site ', 'site'],
  // "Approval mode", not bare "Approval" — the file has a section comment
  // mentioning "Approvals" near the top that a loose needle matches instead.
  ['Approval mode', 'approval'],
  ['GENERATION settings', 'generation row'],
  ['Image AI model', 'image model'],
];
for (const [needle, label] of CONTROLS) {
  const line = at(needle);
  check(`${label}: present`, line !== null, needle);
  if (line === null) continue;
  // After the gate = only rendered when expanded. Before the bulk bar = still
  // in the settings panel rather than adrift among the items.
  check(`${label}: after the expand gate`, line > gate, { line, gate });
  check(`${label}: before the item list`, line < bulk, { line, bulk });
}

console.log('\n3. Nothing control-shaped is left on the collapsed card');
// The header region is everything between its comment and the gate.
const headerRegion = LINES.slice(header, gate - 1).join('\n');
for (const [needle, label] of CONTROLS) {
  check(`${label}: gone from the header`, !headerRegion.includes(needle), label);
}
// Identity + the rare icon utilities are what SHOULD remain.
check('the name still renders in the header', headerRegion.includes('{strategy.name}'));
check('the status badge stays', headerRegion.includes('<StatusBadge'));
check('the progress bar stays', headerRegion.includes('completedItems / strategy.totalItems'));
check('icon utilities stay', headerRegion.includes('Icon utilities'));
check('the expand chevron stays', headerRegion.includes('ChevronDown'));

console.log('\n4. The panel is a real, click-isolated container');
const panelRegion = LINES.slice(gate, bulk - 1).join('\n');
// Without stopPropagation a click on any control would toggle the card shut.
check('panel stops row-toggle propagation', panelRegion.includes('onClick={(e) => e.stopPropagation()}'));
check('panel is visually separated', panelRegion.includes('borderBottom: `1px solid ${colors.borderLight}`'));
check('panel sits on the card surface', panelRegion.includes('background: colors.bgSurface'));

console.log('\n5. Controls kept their handlers (a move, not a rewrite)');
for (const h of [
  'handlePublishingModeChange', 'handleSiteChange', 'handleImageModelChange',
  'handleImageTemplateChange',
]) {
  check(`${h} still wired`, SRC.includes(`${h}(strategy.id`), h);
}
// The stale "two lines / line 2 / line 3" wording must not survive the move.
check('the old two-line header comment is gone', !SRC.includes('line 2 is every'), 'stale comment');
// The row used to hang off the header with a top margin; inside the panel that
// spacing is the panel's padding's job. Only the FIRST row is checked — the
// generation row below it legitimately keeps a top margin to separate the two.
const firstRow = LINES.slice(gate, bulk - 1).find((l) => l.includes('className="flex flex-wrap items-center'));
check('the panel exists as a control row', firstRow !== undefined);
check('the first panel row drops the old header top-margin',
  firstRow !== undefined && !/\bmt-\d/.test(firstRow), firstRow);

console.log('\n' + '-'.repeat(52));
console.log(`  passed: ${pass}   failed: ${fail}`);
process.exit(fail > 0 ? 1 : 0);
