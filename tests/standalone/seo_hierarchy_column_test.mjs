/**
 * SEO hierarchy view — the Parent picker's PLACEMENT, executed.
 *
 * Owner, looking at the shipped build: "how to set". The picker was rendering,
 * but useColumnLayout appends unknown keys to the END of a saved layout, so on
 * any browser that already had one it sat past ~20 columns off-screen. The
 * feature was present and unreachable — the exact defect as the per-item status
 * dropdown buried in the overrides panel (08-12), whose test passed 57/57
 * because it asserted the control EXISTED and never asserted WHERE.
 *
 * So this file executes the REAL ordering logic lifted out of index.tsx rather
 * than checking that a 'parent' string appears somewhere.
 *
 * Run: node tests/standalone/seo_hierarchy_column_test.mjs
 */

import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..', '..');
const SRC = readFileSync(join(ROOT, 'app/src/modules/SEO/index.tsx'), 'utf8');

let PASS = 0, FAIL = 0;
const check = (name, ok, got) => {
  if (ok) { PASS++; console.log(`  ok  ${name}`); }
  else { FAIL++; console.log(`FAIL  ${name}\n      got: ${JSON.stringify(got)}`); }
};

// ── Extract the shipped orderedCols body and make it callable ────────
const START = 'const orderedCols = useMemo(() => {';
const END = '}, [colOrder, cols, hierarchyOn]);';
const from = SRC.indexOf(START);
const to = SRC.indexOf(END, from);
check('orderedCols is a memo with the expected dependencies', from > -1 && to > from,
  { from, to });
if (from < 0 || to < 0) { console.log('\nFAILURES — cannot extract'); process.exit(1); }

const body = SRC.slice(from + START.length, to);
// Plain JS: no JSX and no type annotations in this body, so it runs as-is.
const orderedCols = new Function('colOrder', 'vis', 'hierarchyOn', body);

// A layout that already existed before 'parent' was added — reconcileOrder
// appends new keys, so 'parent' arrives LAST. This is the real-world input.
const SAVED = ['type', 'title', 'slug', 'featuredImage', 'status', 'metaTitle',
  'metaDescription', 'primaryKeyword', 'date', 'author', 'actions', 'parent'];
const visAll = (hier) => (k) => (k === 'parent' ? hier : true);

// ── off ──
const off = orderedCols(SAVED, visAll(false), false);
check('hierarchy OFF: no Parent column at all', !off.includes('parent'), off);
check('hierarchy OFF: the saved order is untouched',
  JSON.stringify(off) === JSON.stringify(SAVED.filter((k) => k !== 'parent')), off);

// ── on ──
const on = orderedCols(SAVED, visAll(true), true);
check('hierarchy ON: Parent is present', on.includes('parent'), on);
check('hierarchy ON: Parent sits IMMEDIATELY after Title — not appended last',
  on[on.indexOf('title') + 1] === 'parent', on);
check('hierarchy ON: Parent is NOT at the end (the shipped bug)',
  on[on.length - 1] !== 'parent', on);
check('hierarchy ON: it appears exactly once',
  on.filter((k) => k === 'parent').length === 1, on);
check('hierarchy ON: every other column survives, in order',
  JSON.stringify(on.filter((k) => k !== 'parent' && k !== 'anchors'))
    === JSON.stringify(SAVED.filter((k) => k !== 'parent')), on);

// Anchors (2026-08-20 round 2) rides with Parent — one workflow: pick the
// pillar, then name the phrases links to it should wrap.
check('hierarchy ON: Anchors sits immediately after Parent',
  on[on.indexOf('parent') + 1] === 'anchors', on);
check('hierarchy OFF: no Anchors column either', !off.includes('anchors'), off);

// Title hidden → the picker must still be reachable, not dropped.
const noTitle = orderedCols(SAVED, (k) => (k === 'title' ? false : k === 'parent' ? true : true), true);
check('Title hidden: Parent is still shown (first), never dropped',
  noTitle[0] === 'parent', noTitle);

// A layout that never knew about parent at all.
const legacy = ['type', 'title', 'status'];
const legacyOn = orderedCols(legacy, visAll(true), true);
check('a layout with no parent key still gets the picker after Title',
  legacyOn[legacyOn.indexOf('title') + 1] === 'parent', legacyOn);

// ── the Columns menu must not offer it (the toggle owns it) ──
check('Parent AND Anchors are excluded from the Columns menu',
  /COLUMNS_MENU\s*=\s*TOGGLE_COLUMNS\.filter\(\(c\)\s*=>\s*c\.key\s*!==\s*'parent'\s*&&\s*c\.key\s*!==\s*'anchors'\)/.test(SRC));
check('the Columns menu is what the toolbar actually receives',
  /columns=\{COLUMNS_MENU\}/.test(SRC));
check('visibility of Parent and Anchors derives from hierarchyOn, not the cols map',
  /key === 'parent' \|\| key === 'anchors' \? hierarchyOn :/.test(SRC));

console.log(`\n${FAIL === 0 ? 'ALL GREEN' : 'FAILURES'} — ${PASS} passed, ${FAIL} failed`);
process.exit(FAIL === 0 ? 0 : 1);
