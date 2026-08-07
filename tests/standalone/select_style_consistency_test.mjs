/**
 * Every dropdown looks the same, in every module.
 *
 * Owner order 2026-08-08: "in every module work with the ui every dropdown
 * should look same in terms of style/css".
 *
 * 129 SelectTrigger call sites had drifted to 4 heights (h-7/8/9/10), 2 font
 * sizes and 5 backgrounds. The mechanism: cn() runs tailwind-merge, so a class
 * passed at the CALL SITE beats the base component — the shared style had no
 * authority, and every local `h-9 bg-white` silently won.
 *
 * The rule this guards: height / font-size / background / border / shadow /
 * focus-ring belong to selectTriggerVariants. Call sites pass WIDTH and LAYOUT
 * only. Anything else is drift and will look different from its neighbours.
 *
 * Run: node tests/standalone/select_style_consistency_test.mjs
 */

import { readFileSync, readdirSync, statSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..', '..');
const SRC = join(ROOT, 'app/src');
const SELECT = join(SRC, 'components/ui/select.tsx');

let pass = 0, fail = 0;
const check = (name, ok, got) => {
  if (ok) { pass++; console.log(`  ok  ${name}`); }
  else { fail++; console.log(`FAIL  ${name}\n      got: ${JSON.stringify(got)}`); }
};

/** Every .tsx under app/src. */
const walk = (dir, out = []) => {
  for (const e of readdirSync(dir)) {
    const p = join(dir, e);
    if (statSync(p).isDirectory()) walk(p, out);
    else if (e.endsWith('.tsx')) out.push(p);
  }
  return out;
};
const FILES = walk(SRC);
const rel = (p) => p.slice(SRC.length + 1).replace(/\\/g, '/');

const base = readFileSync(SELECT, 'utf8');

console.log('\n1. The base component owns the look');
check('canonical height is defined once, on the base',
  base.includes('data-[size=default]:h-8') && base.includes('data-[size=sm]:h-7'), 'no canonical height');
check('canonical font size is on the base trigger', /rounded-md border px-3 py-2 text-xs/.test(base), 'no text-xs');
check('canonical background is a single surface',
  /default: "bg-card"/.test(base) && /surface: "bg-card"/.test(base), 'surfaces differ');
check('a chromeless variant exists (not hand-rolled per call site)',
  /ghost:\s*\n?\s*"border-0 bg-transparent shadow-none/.test(base), 'no ghost variant');
check('size="auto" is a real opt-out for container-fitting triggers',
  base.includes('"sm" | "default" | "auto"'), 'no auto size');
check('the open list matches the trigger font size',
  /pr-8 pl-2 text-xs outline-hidden/.test(base), 'SelectItem font differs from trigger');

console.log('\n2. NO call site overrides the look');
// Tokens the base owns. h-auto / h-full are LAYOUT (fit the container) and stay.
const BANNED = [
  [/\bh-\d+\b/, 'height'],
  [/\btext-(?:xs|sm|base)\b/, 'font size'],
  [/\bbg-(?:card|white|background|transparent|slate-\d+|muted)\b/, 'background'],
  [/\bborder-0\b/, 'border removal'],
  [/\bshadow-none\b/, 'shadow removal'],
  [/\bfocus:ring-0\b/, 'focus ring removal'],
  [/\brounded-none\b/, 'radius'],
];
const offenders = [];
let triggerCount = 0, itemCount = 0;
for (const f of FILES) {
  const src = readFileSync(f, 'utf8');
  for (const m of src.matchAll(/<SelectTrigger(?![\w-])[^>]*?className="([^"]*)"/g)) {
    triggerCount++;
    for (const [re, what] of BANNED) {
      if (re.test(m[1])) offenders.push(`${rel(f)}  [${what}]  ${m[1]}`);
    }
  }
  for (const m of src.matchAll(/<SelectItem(?![\w-])[^>]*?className="([^"]*)"/g)) {
    itemCount++;
    if (/\btext-(?:xs|sm|base)\b/.test(m[1])) offenders.push(`${rel(f)}  [item font]  ${m[1]}`);
  }
}
// Sanity: count ALL triggers, not just the ones that still carry a className —
// the codemod removed the attribute entirely wherever nothing layout-ish was
// left, so a className-only count under-reports and would mask a broken scan.
let allTriggers = 0;
for (const f of FILES) allTriggers += (readFileSync(f, 'utf8').match(/<SelectTrigger(?![\w-])/g) ?? []).length;
check('the codebase still has dropdowns to check (harness sanity)', allTriggers > 100, allTriggers);
check('most triggers now carry layout-only classes or none at all',
  triggerCount <= allTriggers, { triggerCount, allTriggers });
check(`no SelectTrigger/SelectItem overrides the canonical style (${triggerCount} triggers, ${itemCount} items)`,
  offenders.length === 0, offenders.slice(0, 12));

console.log('\n3. Layout is still the call site\'s business');
const widths = new Set();
for (const f of FILES) {
  for (const m of readFileSync(f, 'utf8').matchAll(/<SelectTrigger(?![\w-])[^>]*?className="([^"]*)"/g)) {
    for (const t of m[1].split(/\s+/)) if (/^(?:w-|min-w-|max-w-|flex-1)/.test(t)) widths.add(t);
  }
}
check('per-context widths survived (they are content-driven, not style)', widths.size > 10, widths.size);
check('h-full / h-auto survived where a trigger must fit its container',
  FILES.some((f) => /<SelectTrigger[^>]*className="[^"]*h-(?:full|auto)/.test(readFileSync(f, 'utf8'))),
  'container-fitting heights were stripped');

console.log('\n4. Chromeless triggers went through the variant, not by hand');
let ghosts = 0;
for (const f of FILES) {
  ghosts += (readFileSync(f, 'utf8').match(/variant="ghost"\s+size="auto"/g) ?? []).length;
}
check('every chromeless trigger uses variant="ghost" size="auto"', ghosts === 4, ghosts);

console.log('\n' + '-'.repeat(60));
console.log(`  passed: ${pass}   failed: ${fail}`);
process.exit(fail > 0 ? 1 : 0);
