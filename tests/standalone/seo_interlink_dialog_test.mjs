/**
 * Interlink review — it is a DIALOG, and it identifies pages honestly.
 *
 * Owner 2026-08-20, from the shipped inline panel: "can we get a popup instead?
 * and we can select approve or reject". The same screenshot also showed two
 * rows reading "X → X", because the list identified a page by TITLE alone and
 * this site carries the same title on a post and a page.
 *
 * Asserted against SOURCE by structure (this is a rendering contract, and the
 * component is JSX — it cannot be executed here). The behavioural half of the
 * feature is executed in seo_interlinks_test.php.
 *
 * Run: node tests/standalone/seo_interlink_dialog_test.mjs
 */

import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..', '..');
const PANEL = readFileSync(join(ROOT, 'app/src/modules/SEO/InterlinkPanel.tsx'), 'utf8');
const INDEX = readFileSync(join(ROOT, 'app/src/modules/SEO/index.tsx'), 'utf8');
const TYPES = readFileSync(join(ROOT, 'app/src/modules/SEO/types.ts'), 'utf8');

let PASS = 0, FAIL = 0;
const check = (name, ok, got) => {
  if (ok) { PASS++; console.log(`  ok  ${name}`); }
  else { FAIL++; console.log(`FAIL  ${name}${got === undefined ? '' : `\n      got: ${JSON.stringify(got)}`}`); }
};
/** Source with comments stripped — the docblocks quote the old behaviour. */
const code = (s) => s.replace(/\/\*[\s\S]*?\*\//g, '').replace(/^\s*\/\/.*$/gm, '')
                     .replace(/\{\/\*[\s\S]*?\*\/\}/g, '');
const P = code(PANEL), I = code(INDEX);

// ── 1. it is a dialog, built from the SHARED primitive ──
check('uses the shared Dialog primitive, not a hand-rolled overlay',
  /from ["']@\/components\/ui\/dialog["']/.test(P));
check('renders a DialogContent', /<DialogContent/.test(P));
// The ROOT must be the Dialog. Asserting only that DialogContent/Title/Footer
// appear somewhere lets the outer wrapper be swapped back to a plain <div> —
// the component still compiles, renders inline, and every part-based check
// passes. (The negative control missed exactly that until this was added.)
check('the component ROOT is a Dialog, not just its parts',
  /return \(\s*<Dialog[\s>]/.test(P));
check('the dialog is controlled open and closes through onOpenChange',
  /<Dialog open onOpenChange=/.test(P));
check('has a DialogTitle', /<DialogTitle/.test(P));
check('actions live in a DialogFooter', /<DialogFooter/.test(P));
check('no hand-rolled fixed-position overlay in the panel',
  !/fixed inset-0/.test(P));

// ── 2. approve / reject per row ──
// Scope to the ready-row block. Matching a bare label anywhere in the file
// would also be satisfied by the FOOTER's "Approve all", so a per-row button
// could go missing and the check would still pass — and both labels happen to
// follow a `}` (the spinner ternary), not a `>`, which a naive shape-based
// regex misses entirely. Assert the rule: inside the rows, each verb sits with
// its own handler.
// NB the end anchor must be searched FROM the start anchor: `refused.length > 0`
// also appears earlier, in the dialog description, which sliced to nothing.
const readyStart = P.indexOf('ready.map(');
const readyBlock = P.slice(readyStart, P.indexOf('refused.length > 0', readyStart));
check('the ready-row block was located', readyBlock.length > 200, readyBlock.length);
check('each ready row offers Approve, inside the row (not just the footer)',
  /Approve/.test(readyBlock));
check('each ready row offers Reject', /Reject/.test(readyBlock));
check('Approve calls onAccept with that row',
  /onClick=\{\(\)\s*=>\s*onAccept\(p\)\}/.test(readyBlock));
check('Reject calls onReject with that row',
  /onClick=\{\(\)\s*=>\s*onReject\(p\)\}/.test(readyBlock));
check('the row Approve is the success-styled action',
  /variant="success"/.test(readyBlock));
check('bulk Approve all is still offered', /Approve all/.test(P));
check('actions disable while a write is in flight', /disabled=\{busy\}/.test(P));

// ── 3. refusals stay VISIBLE (card 7's lesson) ──
check('refused rows are still rendered, not filtered away',
  /refused\.map\(/.test(P));
check('each refusal shows its reason', /\{p\.reason\}/.test(P));
check('the refusal block explains the anchor law',
  /already on the\s*\n?\s*page|wording that is already/.test(P));

// ── 4. a page is identified by title AND path ──
check('a PageRef component exists', /function PageRef\(/.test(P));
check('PageRef renders the path beside the title', /\{path\}/.test(P) && /\{title\}/.test(P));
check('sources are rendered through PageRef, not a bare title',
  (P.match(/<PageRef/g) || []).length >= 4, (P.match(/<PageRef/g) || []).length);
check('no bare {p.sourceTitle} left in the row headers',
  !/>\s*\{p\.sourceTitle\}\s*</.test(P));
check('the proposal type carries both paths',
  /sourcePath:\s*string/.test(TYPES) && /targetPath:\s*string/.test(TYPES));

// ── 5. mounting ──
check('the dialog is mounted OUTSIDE the table scroll container',
  /InterlinkPanel/.test(I) && !/rounded-md border border-border overflow-auto[\s\S]{0,400}<InterlinkPanel/.test(I));
check('it is still gated on hierarchy mode and a generated list',
  /hierarchyOn && proposals !== null/.test(I));
check('closing clears the proposals', /onClose=\{\(\) => setProposals\(null\)\}/.test(I));

console.log(`\n${FAIL === 0 ? 'ALL GREEN' : 'FAILURES'} — ${PASS} passed, ${FAIL} failed`);
process.exit(FAIL === 0 ? 0 : 1);
