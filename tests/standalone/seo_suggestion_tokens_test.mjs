/**
 * Card 11 — "Remove all inline AI design": the SEO table's staged-AI-suggestion UI
 * (the "N AI suggestions pending" bar + the per-cell Accept / Reject / Re-generate
 * chips) must use the app's SHARED design tokens — not an inline palette.
 *
 *  - "font does not match the rest of the app"  → chips were `text-[10px]`; the app's
 *    smallest type step is `text-xs`. Now the shared Button (Inter, text-xs at size xs).
 *  - "the colors — are they shared design tokens?" → `bg-green-600 / hover:bg-green-700 /
 *    text-white` existed nowhere in the token set. The design intent IS green
 *    (owner 2026-07-13: green = keep, blue = asks the AI, grey = decline), so
 *    "keep the design the same, use shared tokens" = define that green ONCE
 *    (`--success` in index.css, `success` Button variant) and use it everywhere:
 *    table chips, the pending bar, the page editor's review chips, the diff mark.
 *  - "Borders — shared tokens?" → `border-border` / `border-primary/20` (already tokens);
 *    radius now the shared `rounded-md` via Button, not bare `rounded`.
 *  - "if no on any, then lets do it shared" → ONE `StagedSuggestion` component used by
 *    both the table cells and the Headings panel (they carried identical hand-rolled
 *    copies), and ONE shared Button size `xs` for in-cell controls.
 *
 * Run: node tests/standalone/seo_suggestion_tokens_test.mjs
 */

import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..', '..');
const read = (p) => readFileSync(join(ROOT, p), 'utf8');
const shared = read('app/src/modules/SEO/StagedSuggestion.tsx');
const table = read('app/src/modules/SEO/index.tsx');
const headings = read('app/src/modules/SEO/HeadingsPanel.tsx');
const button = read('app/src/components/ui/button.tsx');
const css = read('app/src/index.css');

let pass = 0, fail = 0;
const check = (name, ok, got) => {
  if (ok) { pass++; console.log(`  ok  ${name}`); }
  else { fail++; console.log(`FAIL  ${name}\n      got: ${JSON.stringify(got)}`); }
};
// Anything that is NOT a token: raw Tailwind palette shades, text-white, arbitrary px type sizes.
const INLINE_PALETTE = /\b(?:bg|text|border|hover:bg|hover:text)-(?:green|red|blue|slate|gray|zinc|neutral|stone|amber|yellow|emerald|sky|indigo|violet|rose|pink|orange|lime|teal|cyan)-\d{2,3}\b/;
const strip = (src) => src.replace(/\/\*[\s\S]*?\*\//g, '').replace(/^\s*\/\/.*$/gm, ''); // ignore comments

console.log('\n1. ONE shared component, built on the shared Button');
check('StagedSuggestion exists and exports the component', /export function StagedSuggestion\(/.test(shared));
check('it renders the shared <Button>, not raw <button>', /import \{ Button \} from '@\/components\/ui\/button'/.test(shared) && !/<button\b/.test(strip(shared)));
check('Accept = the shared success variant, Reject + Re-generate = outline',
  /<Button type="button" size="xs" variant="success" onClick=\{onAccept\}/.test(shared)
  && /<Button type="button" size="xs" variant="outline" onClick=\{onReject\}/.test(shared)
  && /<Button type="button" size="xs" variant="outline" onClick=\{onRegenerate\}/.test(shared));
check('no inline palette / text-white / px type sizes anywhere in it', !INLINE_PALETTE.test(strip(shared)) && !/text-white|text-\[\d+px\]/.test(strip(shared)), strip(shared).match(INLINE_PALETTE));
check('its surface uses tokens only (accent / primary / border / foreground)', /rounded-md border border-primary\/20 bg-accent p-1\.5/.test(shared) && /text-xs text-foreground/.test(shared));

console.log('\n2. The shared Button grew ONE in-cell size — text-xs, shared radius');
check('size xs is defined on the shared Button', /xs: "h-6 rounded-md gap-1 px-2 text-xs has-\[>svg\]:px-1\.5 \[&_svg:not\(\[class\*='size-'\]\)\]:size-3"/.test(button), 'no xs size');
check('the app’s font stays Inter through the token (nothing overrides it here)', /--font-sans: 'Inter'/.test(css) && !/font-\[|fontFamily/.test(shared));
check('the success green is defined ONCE as a token (index.css :root + @theme colour)',
  /--success: oklch\(0\.627 0\.194 149\.2\);\s*--success-foreground: oklch\(0\.98 0 0\);/.test(css) && /--color-success: var\(--success\);/.test(css) && /--color-success-foreground: var\(--success-foreground\);/.test(css), 'no success token');
check('…and the shared Button has a `success` variant built on it', /success:\s*"bg-success text-success-foreground hover:bg-success\/90 focus-visible:ring-success\/20"/.test(button), 'no success variant');
check('the token value is the green-600 the surfaces used inline (design unchanged)', /--success: oklch\(0\.627 0\.194 149\.2\)/.test(css));

console.log('\n3. Both surfaces use it — the hand-rolled copies are gone');
check('table cells render StagedSuggestion', /import \{ StagedSuggestion \} from '\.\/StagedSuggestion'/.test(table) && /<StagedSuggestion\s+suggestion=\{suggestion\}\s+busy=\{generating\}/.test(table));
check('headings panel renders StagedSuggestion', /import \{ StagedSuggestion \} from '\.\/StagedSuggestion'/.test(headings) && /<StagedSuggestion\s+suggestion=\{suggestion\}\s+busy=\{busy\}/.test(headings));
check('no bg-green anywhere in the table module or headings panel', !/bg-green-\d+/.test(strip(table)) && !/bg-green-\d+/.test(strip(headings)));
check('no hand-rolled Accept chip left (raw <button … title="Accept")', !/<button[^>]*title="Accept"/.test(table) && !/<button[^>]*title="Accept"/.test(headings));
check('no 10px chip type left on the suggestion surfaces', !/text-\[10px\][^\n]*(?:Accept|Reject|Re-generate)/.test(table) && !/text-\[10px\][^\n]*(?:Accept|Reject|Re-generate)/.test(headings));

console.log('\n4. The pending-suggestions bar');
const bar = table.slice(table.indexOf('{/* Pending AI suggestions'), table.indexOf('{isLoading ? ('));
check('bar found', bar.length > 100 && /AI suggestion\{pendingCount > 1/.test(bar));
check('Accept all & save = shared Button, success variant — no inline green', /<Button size="sm" variant="success" className="h-7 gap-1\.5" onClick=\{acceptAllStaged\}>/.test(bar) && !/bg-green/.test(bar));
check('Discard all = shared ghost Button', /<Button variant="ghost" size="sm" className="h-7 gap-1\.5" onClick=\{discardAllStaged\}>/.test(bar));
check('the bar’s surface is tokens (border-primary/20, bg-accent/60, text-foreground)', /rounded-lg border border-primary\/20 bg-accent\/60/.test(bar) && /text-sm font-medium text-foreground/.test(bar) && !INLINE_PALETTE.test(bar));

console.log('\n5. The page editor’s AI-review chips + diff marks draw from the SAME token');
const rail = read('app/src/modules/SEO/editor/ReviewRail.tsx');
const img = read('app/src/modules/SEO/editor/ImagePanel.tsx');
const lay = read('app/src/modules/SEO/editor/layout.ts');
const ext = read('app/src/modules/SEO/editor/extensions.ts');
const opt = read('app/src/modules/SEO/OptimizeModal.tsx');
const NO_GREEN = (src) => !/bg-green-\d+|text-green-\d+|hover:bg-green-\d+/.test(strip(src));
check('ReviewRail: Accept all / Adjust / Accept chips = bg-success + text-success-foreground (hover lightens, per the 07-13 ruling)',
  (rail.match(/bg-success px-[\d.]+ py-0\.5 text-\[10px\] font-medium text-success-foreground hover:bg-success\/85/g) || []).length === 3 && NO_GREEN(rail), rail.match(/bg-green[^"]*/g));
check('ReviewRail: revise hover = accent token, ghost/reject = border/card/muted tokens (no #e7f5ff, no slate on chips)',
  !/hover:bg-\[#e7f5ff\]/.test(rail) && /border-border bg-card px-1\.5 py-0\.5 text-\[10px\] text-muted-foreground hover:bg-muted/.test(rail));
check('ImagePanel: Save = success token, Hide = destructive tokens', /bg-success px-2 py-1 text-\[10px\] font-medium text-success-foreground/.test(img) && /border-destructive\/30 bg-card px-2 py-1 text-\[10px\] text-destructive hover:bg-destructive\/10/.test(img) && NO_GREEN(img) && !/border-red-|text-red-|bg-red-/.test(strip(img)));
check('in-editor chips (layout.ts): accept/revise/ghost = success / accent / border+card+muted tokens',
  /\[&_\.pcm-chip-accept\]:bg-success \[&_\.pcm-chip-accept\]:font-medium \[&_\.pcm-chip-accept\]:text-success-foreground \[&_\.pcm-chip-accept:hover\]:bg-success\/85/.test(lay)
  && /\[&_\.pcm-chip-revise:hover\]:bg-accent/.test(lay) && /\[&_\.pcm-chip-ghost\]:border-border \[&_\.pcm-chip-ghost\]:bg-card \[&_\.pcm-chip-ghost\]:text-muted-foreground \[&_\.pcm-chip-ghost:hover\]:bg-muted/.test(lay) && NO_GREEN(lay) && !/#e7f5ff|slate-/.test(lay.slice(lay.indexOf('pcm-review-chip'))));
check('diff marks: added = success tint, removed = destructive tint (no green-800/red-50 literals)', /class: 'rounded-sm bg-success\/15'/.test(ext) && /class: 'rounded-sm bg-destructive\/10 line-through decoration-destructive'/.test(ext) && NO_GREEN(ext) && !/red-/.test(strip(ext)));
check('OptimizeModal score dot = success / muted-foreground tokens', /v\.ok \? 'text-success' : 'text-muted-foreground'/.test(opt) && /v\.ok \? 'bg-success' : 'bg-muted-foreground'/.test(opt));

console.log('\n' + '-'.repeat(60));
console.log(`  passed: ${pass}   failed: ${fail}`);
process.exit(fail > 0 ? 1 : 0);
