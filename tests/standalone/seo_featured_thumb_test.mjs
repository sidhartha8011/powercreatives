/**
 * SEO table Image column — no broken-image glyphs.
 *
 * Owner (screenshot, 2026-08-17): every row showed the browser's torn
 * broken-image icon. The URLs were RIGHT; the client site refuses to serve
 * them cross-origin. Proven live against massagegoteborg.nu that day — its own
 * thumbnail answers 403 (Cloudflare challenge) for:
 *     plain fetch · full Chrome UA · same-site Referer · full browser headers
 * while /wp-json returns 200. So the picture cannot be rendered by the browser
 * OR by a hub-side proxy; the only honest choice is a clean placeholder that
 * explains why, instead of a broken glyph.
 *
 * This pins the CELL's contract. The decision logic is extracted from the real
 * component and EXECUTED (no React runtime needed): given (src, failed,
 * hasImageId) it must pick image | blocked-placeholder | unreadable-placeholder
 * | empty-placeholder, and never leave a live <img> with a dead URL.
 *
 * Run: node tests/standalone/seo_featured_thumb_test.mjs
 */

import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..', '..');
const SRC = readFileSync(join(ROOT, 'app/src/modules/SEO/index.tsx'), 'utf8');

let pass = 0, fail = 0;
const check = (name, ok, got) => {
  if (ok) { pass++; console.log(`  ok  ${name}`); }
  else { fail++; console.log(`FAIL  ${name}\n      got: ${JSON.stringify(got)}`); }
};

console.log('\n1. The component exists and the cell uses it');
const at = SRC.indexOf('function FeaturedThumb(');
check('FeaturedThumb is defined at module scope (stable identity, no remount per render)',
  at !== -1 && /\nfunction FeaturedThumb\(/.test(SRC), at);
check('the Image cell renders it', SRC.includes('<FeaturedThumb src={row.featuredImage || \'\'} hasImageId={hasImageId} />'), 'cell not wired');
check('no bare <img> is left in the Image cell',
  !/case 'featuredImage'[\s\S]{0,900}?<img /.test(SRC), 'raw img survives in the cell');
const body = SRC.slice(at, SRC.indexOf('\nfunction rowCellIsEmpty', at));

console.log('\n2. The failure path exists at all');
check('the image reports its own failure (onError)', /onError=\{\(\) => setFailed\(true\)\}/.test(body), 'no onError — broken glyph stays');
check('a failed load flips to the placeholder, not a retry loop',
  /if \(src && !failed\)/.test(body), 'failed state not consulted');
check('a NEW url resets the failed state (changed image / other site gets a fresh try)',
  /useEffect\(\(\) => \{ setFailed\(false\); \}, \[src\]\)/.test(body), 'stuck on the old failure');
check('referrerPolicy="no-referrer" is sent (rescues HOTLINK-protected sites, which allow an empty referer)',
  /referrerPolicy="no-referrer"/.test(body), 'cross-site referer still sent');
check('lazy loading kept (75-row tables)', /loading="lazy"/.test(body));

console.log('\n3. The four states — EXECUTED');
// Extract the real decision and run it. The component returns an <img> only for
// (src && !failed); everything else is the placeholder, whose wording/border is
// chosen by `blocked` (src !== '') then hasImageId.
const decide = (src, failed, hasImageId) => {
  if (src && !failed) return 'image';
  const blocked = src !== '';
  if (blocked) return 'blocked-placeholder';
  return hasImageId ? 'unreadable-placeholder' : 'empty-placeholder';
};
// The table below runs a TRANSCRIPTION of the decision, so pin the two source
// expressions it transcribes — otherwise editing the component's own logic (e.g.
// hardcoding `blocked = false`) would leave this section happily green.
check('source: the image branch is exactly (src && !failed)', /if \(src && !failed\) \{/.test(body), 'branch changed');
check('source: blocked is derived from the url being present', /const blocked = src !== '';/.test(body), 'blocked derivation changed');
const cases = [
  ['a working image renders', ['https://ok.test/t.jpg', false, true], 'image'],
  ['a BLOCKED image (the reported bug) → placeholder, never a broken glyph', ['https://massagegoteborg.nu/t.jpg', true, true], 'blocked-placeholder'],
  ['blocked even when no media id is known', ['https://x.test/t.jpg', true, false], 'blocked-placeholder'],
  ['id set but URL unresolvable → its own wording', ['', false, true], 'unreadable-placeholder'],
  ['genuinely no image → dashed empty state', ['', false, false], 'empty-placeholder'],
];
for (const [name, args, want] of cases) check(name, decide(...args) === want, decide(...args));

console.log('\n4. The placeholder EXPLAINS itself (the point of the fix)');
check('blocked wording names the cause and the remedy',
  body.includes('refuses to serve it to other origins (bot protection or hotlink protection)')
  && body.includes('Click to choose another'), 'no explanation');
check('the three tooltips are distinct (blocked ≠ unreadable ≠ none)',
  body.includes('could not be read (the media may be restricted)') && body.includes('Set featured image'), 'states collapsed');
check('blocked/unreadable look "filled" (solid border), empty looks unset (dashed)',
  /blocked \|\| hasImageId \? 'border-border bg-muted' : 'border-dashed border-border'/.test(body), 'states look identical');
check('clicking still opens the picker (the cell stays actionable)',
  /onClick=\{\(\) => openFeaturedImage\(row\)\}/.test(SRC), 'cell no longer clickable');

console.log('\n' + '-'.repeat(60));
console.log(`  passed: ${pass}   failed: ${fail}`);
process.exit(fail > 0 ? 1 : 0);
