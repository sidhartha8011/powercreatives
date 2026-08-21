/**
 * SEO toolbar — Update + New consolidation (owner card 2026-08-20).
 *
 * "Consolidate the add page and add post into one button drop-down" and
 * "consolidate the update stats and update ranks button into a drop-down with
 * both separate updates and one update button that will update both at the
 * same time … past days … as a sub dropdown".
 *
 * Structure test over source. Assertions target RULES (what runs, from where,
 * fed by which list) rather than spellings, and are sliced to the menu blocks
 * so a stray label elsewhere cannot satisfy them.
 *
 * Run: node tests/standalone/seo_toolbar_consolidation_test.mjs
 */

import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..', '..');
const SRC = readFileSync(join(ROOT, 'app/src/modules/SEO/index.tsx'), 'utf8');

let PASS = 0, FAIL = 0;
const check = (name, ok, got) => {
  if (ok) { PASS++; console.log(`  ok  ${name}`); }
  else { FAIL++; console.log(`FAIL  ${name}${got === undefined ? '' : `\n      got: ${JSON.stringify(got)}`}`); }
};
const code = SRC.replace(/\{\/\*[\s\S]*?\*\/\}/g, '').replace(/\/\*[\s\S]*?\*\//g, '').replace(/^\s*\/\/.*$/gm, '');

// ── locate the Update menu block ──
const updStart = code.indexOf("'Updating…' : 'Update'");
check('the Update trigger exists', updStart > -1);
const updEnd = code.indexOf('</DropdownMenu>', updStart);
const upd = code.slice(updStart, updEnd);
check('the Update menu block was sliced', upd.length > 300, upd.length);

// ── 1. combined entry runs BOTH, concurrently, per period ──
// Bounded by the first Sub's CLOSING tag, not the next label: the second
// sub-menu's OPENING tag precedes its own label in the JSX, so a label-bounded
// slice leaks it in and a flattened first Sub still 'has' a <DropdownMenuSub>.
// (Third slice-boundary lesson in this file's short life.)
const combined = upd.slice(0, upd.indexOf('</DropdownMenuSub>'));
check('a combined entry exists ahead of the singles', /Stats \+ ranks/.test(combined));
check('the combined entry runs the GSC pull with the picked period',
  /void handlePullGsc\(d\);/.test(combined));
check('…and the PRT pull in the SAME onSelect (both at the same time)',
  /void handlePullGsc\(d\);\s*void handlePullPrt\(\);/.test(combined));
// Exact opening tag: a bare /DropdownMenuSub/ also matches DropdownMenuSubTrigger,
// so flattening the Sub wrapper while keeping its trigger slipped through.
check('the combined periods are a sub-menu', /<DropdownMenuSub>/.test(combined));

// ── 2. separate updates still exist ──
const gscOnly = upd.slice(upd.indexOf('GSC stats only'));
check('a GSC-only sub-menu exists', /GSC stats only/.test(upd));
// The PRT item's onSelect ATTRIBUTE precedes its label in JSX, so slicing "up
// to the label" would swallow the PRT handler and fail here for the wrong
// reason (it did). Slice to the sub-menu's own closing tag instead.
const gscOnlySub = gscOnly.slice(0, gscOnly.indexOf('</DropdownMenuSubContent>'));
check('GSC-only pulls GSC and ONLY GSC',
  /void handlePullGsc\(d\);/.test(gscOnlySub) && !/handlePullPrt\(\)/.test(gscOnlySub));
check('a PRT-only item exists and pulls ONLY PRT',
  /PRT ranks only/.test(upd) && /onSelect=\{\(\) => \{ void handlePullPrt\(\); \}\}/.test(gscOnly));

// ── 3. one period list feeds both sub-menus ──
check('a single shared GSC_PERIODS constant exists',
  /const GSC_PERIODS = \[7, 30, 60, 90, 120\];/.test(code));
check('BOTH sub-menus map over it (no duplicated literal list)',
  (upd.match(/GSC_PERIODS\.map/g) || []).length === 2
  && !/\[7, 30, 60, 90, 120\]\.map/.test(upd),
  (upd.match(/GSC_PERIODS\.map/g) || []).length);

// ── 4. the old standalone buttons are gone ──
check('no standalone GSC stats PillButton remains', !/'GSC stats'\s*\}/.test(code) && !/>\s*\{gscPulling \? 'Pulling…' : 'GSC stats'\}/.test(code));
check('no standalone PRT ranks PillButton remains',
  !/PillButton[\s\S]{0,200}?onClick=\{handlePullPrt\}/.test(code));
// [^>]* cannot bridge PillButton→onClick: the icon prop's <Plus /> contains a
// '>', so a resurrected standalone button was invisible to the first version.
check('no standalone Post/Page PillButtons remain',
  !/PillButton[\s\S]{0,120}?onClick=\{\(\) => handleCreate\('post'\)\}/.test(code)
  && !/PillButton[\s\S]{0,120}?onClick=\{\(\) => handleCreate\('page'\)\}/.test(code));

// ── 5. the New menu creates both types ──
const newStart = code.indexOf('>New</PillButton>');
check('the New trigger exists', newStart > -1);
const newBlock = code.slice(newStart, code.indexOf('</DropdownMenu>', newStart));
check('New menu creates a post', /void handleCreate\('post'\);/.test(newBlock));
check('New menu creates a page', /void handleCreate\('page'\);/.test(newBlock));

// ── 6. the documented PillButton-in-span trap is respected on both triggers ──
const spanTrap = /DropdownMenuTrigger asChild[^>]*>\s*<span className="inline-flex">\s*<PillButton/g;
check('both new triggers wrap PillButton in the span (the closed-interface trap)',
  (code.match(spanTrap) || []).length >= 2, (code.match(spanTrap) || []).length);

// ── 7. the Update trigger disables while EITHER pull runs ──
check('the trigger disables on gscPulling OR prtPulling',
  /DropdownMenuTrigger asChild disabled=\{busy \|\| gscPulling \|\| prtPulling\}/.test(code));

console.log(`\n${FAIL === 0 ? 'ALL GREEN' : 'FAILURES'} — ${PASS} passed, ${FAIL} failed`);
process.exit(FAIL === 0 ? 0 : 1);
