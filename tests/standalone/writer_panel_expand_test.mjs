/**
 * "Show Revisions" did nothing — the click issue, root-caused and pinned.
 *
 * react-resizable-panels' expand() frees space only from panels AFTER the
 * target (its pivot). After the drawer split, the Revisions panel's only
 * follower is the AI Review panel — collapsed at 0, with nothing to give —
 * so expand() computed an UNCHANGED layout and silently no-oped. (The old
 * combined panel was LAST, which takes space from the editor upstream —
 * that's why this regressed only after the split.)
 *
 * The REAL library math (adjustLayoutByDelta, validatePanelGroupLayout) is
 * EXTRACTED from the installed package and EXECUTED here:
 *   §1 reproduces the dead click, §2 shows why the neighbours worked,
 *   §3 proves the fix's whole-group layouts are valid as pushed.
 *
 * Run: node tests/standalone/writer_panel_expand_test.mjs
 */

import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..', '..');
const DIST = join(ROOT, 'app/node_modules/react-resizable-panels/dist/react-resizable-panels.development.js');

let pass = 0, fail = 0;
const check = (name, ok, got) => {
  if (ok) { pass++; console.log(`  ok  ${name}`); }
  else { fail++; console.log(`FAIL  ${name}\n      got: ${JSON.stringify(got)}`); }
};

// ── Extract the pure layout math from the INSTALLED library ────────────────
const src = readFileSync(DIST, 'utf8');
const sliceFn = (name) => {
  // Anchor the OPEN paren (so `fuzzyNumbersEqual` can't match `fuzzyNumbersEqual$1`)
  // and end on a line that is EXACTLY `}` (destructured params close with `}) {`,
  // which a bare `^\}` would mistake for the end of the function).
  const m = src.match(new RegExp(`^(function ${name}\\([\\s\\S]*?^\\}$)`, 'm'));
  if (!m) throw new Error(`function ${name} not found in dist`);
  return m[1];
};
const PRECISION = src.match(/^const PRECISION = .*$/m)?.[0] ?? 'const PRECISION = 10;';
const code = [
  PRECISION,
  sliceFn('assert'),
  sliceFn('fuzzyCompareNumbers'),
  sliceFn('fuzzyNumbersEqual\\$1').replace('fuzzyNumbersEqual$1', 'fuzzyNumbersEqual__1'),
  sliceFn('fuzzyNumbersEqual'),
  sliceFn('fuzzyLayoutsEqual'),
  sliceFn('resizePanel'),
  sliceFn('adjustLayoutByDelta').replace(/fuzzyNumbersEqual\$1/g, 'fuzzyNumbersEqual__1'),
  sliceFn('validatePanelGroupLayout').replace(/fuzzyNumbersEqual\$1/g, 'fuzzyNumbersEqual__1'),
  'return { adjustLayoutByDelta, validatePanelGroupLayout };',
].join('\n');
const { adjustLayoutByDelta, validatePanelGroupLayout } = new Function(code)();
check('library math extracted and executable', typeof adjustLayoutByDelta === 'function');

// The Writer group, in panel order (index.tsx): Queue | Context | Editor | Revisions | Review.
const CONSTRAINTS = [
  { collapsedSize: 0, collapsible: true, minSize: 10, maxSize: 20 },
  { collapsedSize: 0, collapsible: true, minSize: 18, maxSize: 40 },
  { minSize: 30 },
  { collapsedSize: 0, collapsible: true, minSize: 12, maxSize: 30 },
  { collapsedSize: 0, collapsible: true, minSize: 12, maxSize: 30 },
];
const ALL_CLOSED = [0, 0, 100, 0, 0];
const run = (delta, pivotIndices, layout = ALL_CLOSED) => adjustLayoutByDelta({
  delta, initialLayout: layout, panelConstraints: CONSTRAINTS,
  pivotIndices, prevLayout: layout, trigger: 'imperative-api',
});

console.log('\n1. The dead click, REPRODUCED with the real library math');
const revExpand = run(18, [3, 4]); // expand() on Revisions: pivot = [self, next]
check('expand(Revisions) computes an UNCHANGED layout (the no-op click)',
  JSON.stringify(revExpand) === JSON.stringify(ALL_CLOSED), revExpand);

console.log('\n2. Why every OTHER toggle worked (the asymmetry that hid this)');
const ctxExpand = run(20, [1, 2]); // Context: its follower is the editor → space available
check('expand(Context) works — its follower is the editor',
  ctxExpand[1] === 20 && ctxExpand[2] === 80, ctxExpand);
const queueExpand = run(14, [0, 1]); // Queue: walks past collapsed Context into the editor
check('expand(Queue) works — the walk reaches the editor', queueExpand[0] === 14, queueExpand);
const reviewExpand = run(-18, [3, 4]); // Review is LAST: negative delta pulls from upstream
check('expand(Review, last panel) works — it pulls from upstream', reviewExpand[4] === 18, reviewExpand);

console.log('\n3. The fix: whole-group setLayout — every desired state is a VALID layout');
const scenarios = [
  ['revisions open', [0, 0, 82, 18, 0]],
  ['review open', [0, 0, 82, 0, 18]],
  ['both right drawers open', [0, 0, 64, 18, 18]],
  ['everything open (editor keeps its min 30)', [14, 20, 30, 18, 18]],
  ['all closed', ALL_CLOSED],
];
for (const [name, layout] of scenarios) {
  const validated = validatePanelGroupLayout({ layout, panelConstraints: CONSTRAINTS });
  check(`${name}: passes validation unchanged`,
    JSON.stringify(validated) === JSON.stringify(layout), validated);
}

console.log('\n4. index.tsx routes every intent through the whole-group layout');
const index = readFileSync(join(ROOT, 'app/src/modules/Writer/index.tsx'), 'utf8');
check('group ref attached', index.includes('<ResizablePanelGroup ref={panelGroupRef}'));
check('applyDesiredLayout sets ALL five sizes at once',
  /setLayout\(\[queue, context, editor, revisions, review\]\)/.test(index));
check('the editor absorbs whatever the drawers need',
  index.includes('const editor = 100 - queue - context - revisions - review;'));
check('all four toggles route through applyDesiredLayout',
  (index.match(/desiredCollapsed\.current\.\w+ = !desiredCollapsed\.current\.\w+;\s*\n\s*applyDesiredLayout\(\);/g) || []).length === 4);
check('header collapse buttons route through applyDesiredLayout',
  (index.match(/desiredCollapsed\.current\.\w+ = true;\s*\n\s*applyDesiredLayout\(\);/g) || []).length === 3);
check('drift guards re-assert via applyDesiredLayout too',
  (index.match(/applyDesiredLayout\(\);\s*\n\s*return;/g) || []).length === 8);
check('no per-panel expand()/collapse() calls remain (the pivot trap is gone)',
  !/PanelRef\.current\?\.(expand|collapse)\(\)/.test(index));
check('all four open at once still leaves the editor its minSize',
  index.includes('{ queue: 14, context: 20, revisions: 18, review: 18 }'));

console.log('\n' + '-'.repeat(60));
console.log(`  passed: ${pass}   failed: ${fail}`);
process.exit(fail > 0 ? 1 : 0);
