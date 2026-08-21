/**
 * Settings → Web-enabled generation: explanations behind an info HOVER
 * (owner 2026-08-20: "an information pop-over hover button that shows the
 * explanations instead of showing it outside, because otherwise everything
 * grows so much and it's hard for the UI").
 *
 * The rule: the card is ONE row (icon, name, info, switch); every explanation
 * lives INSIDE the hover content; the behaviour of the switch is untouched.
 * Inside/outside is decided by slicing at the HoverCardContent tags — the
 * lesson from this file's siblings: label-bounded slices leak neighbours.
 *
 * Run: node tests/standalone/settings_websearch_hover_test.mjs
 */

import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..', '..');
const SRC = readFileSync(join(ROOT, 'app/src/modules/Settings/WebSearchSection.tsx'), 'utf8');

let PASS = 0, FAIL = 0;
const check = (name, ok, got) => {
  if (ok) { PASS++; console.log(`  ok  ${name}`); }
  else { FAIL++; console.log(`FAIL  ${name}${got === undefined ? '' : `\n      got: ${JSON.stringify(got)}`}`); }
};
const code = SRC.replace(/\{\/\*[\s\S]*?\*\/\}/g, '').replace(/\/\*[\s\S]*?\*\//g, '').replace(/^\s*\/\/.*$/gm, '');

// ── slice inside vs outside at the content tags ──
const cStart = code.indexOf('<HoverCardContent');
const cEnd = code.indexOf('</HoverCardContent>');
check('a HoverCardContent exists', cStart > -1 && cEnd > cStart);
const inside = code.slice(cStart, cEnd);
const outside = code.slice(0, cStart) + code.slice(cEnd);

// ── 1. every explanation is INSIDE the hover ──
for (const [label, needle] of [
  ['the long description', 'instead of writing from memory'],
  ['"Where it applies"', 'Where it applies'],
  ['"How" (provider tools)', 'Google grounding'],
  ['the state-dependent line', 'can still opt out'],
]) {
  check(`${label} lives inside the hover`, inside.includes(needle));
  check(`${label} no longer renders outside`, !outside.includes(needle));
}

// ── 2. the trigger is a real, labelled button on the shared primitive ──
check('trigger is HoverCardTrigger asChild on a real <button>',
  /<HoverCardTrigger asChild>\s*<button/.test(code));
check('the button carries an aria-label', /aria-label="About web-enabled generation"/.test(code));
check('it shows the Info glyph', /<Info className=/.test(code));
check('no hand-rolled hover state (shared HoverCard only)',
  !/onMouseEnter|onMouseLeave/.test(code));
check('imports come from the shared ui layer',
  /from '@\/components\/ui\/hover-card'/.test(code));

// ── 3. the switch behaviour is untouched ──
check('the switch still writes llm_web_search',
  /saveMutation\.mutate\(\{ llm_web_search: !!on \}\)/.test(code));
check('absent still means ON (the server default)',
  /settings\.llm_web_search !== false && settings\.llm_web_search !== 0/.test(code));
check('the switch renders OUTSIDE the hover (always visible)',
  outside.includes('<Switch'));
// The mirror: a switch INSIDE the hover means the control hides behind the
// info button — a control you must hover to find is a control that isn't
// there. (A dead-ternary variant of this mutant is invisible to source tests;
// this catches every textual form of the move.)
check('no switch hides inside the hover content', !inside.includes('<Switch'));
check('the section keeps its testid', /data-testid="web-search-section"/.test(code));

// ── 4. the row really is compact: no Label blocks outside the hover ──
check('no explanation <Label> remains outside the hover',
  !/<Label/.test(outside.replace(/import[^;]+;/g, '')));

console.log(`\n${FAIL === 0 ? 'ALL GREEN' : 'FAILURES'} — ${PASS} passed, ${FAIL} failed`);
process.exit(FAIL === 0 ? 0 : 1);
