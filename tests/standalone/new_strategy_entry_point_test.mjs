/**
 * "New Strategy" belongs on the Strategies page, not the Keyword Explorer.
 *
 * Owner, 2026-08-08: "create strategy we said should be in the strategies, not
 * keywords. we said that before." They had — the session log flagged it on
 * 2026-07-16 as a known gap ("a source-agnostic 'New strategy' entry point is
 * a candidate next step") and it was never actioned.
 *
 * The distinction this guards, because it is easy to over-correct:
 *   - the SOURCE-AGNOSTIC entry point (no selection needed; an RSS/Social
 *     strategy involves no keyword at all) now lives on Strategies;
 *   - the bulk bar's "Create Strategy" STAYS on Keywords, because that one
 *     seeds a strategy FROM the selected rows — genuinely keyword work.
 * Deleting the second one while moving the first would silently destroy the
 * whole select-keywords -> strategy flow, so it is asserted explicitly.
 *
 * Run: node tests/standalone/new_strategy_entry_point_test.mjs
 */

import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..', '..');
const read = (p) => readFileSync(join(ROOT, 'app/src', p), 'utf8');
const STRATEGIES = read('modules/Strategies/index.tsx');
const KEYWORDS = read('modules/Keywords/index.tsx');
const DIALOG = read('modules/Keywords/CreateStrategyDialog.tsx');

let pass = 0, fail = 0;
const check = (name, ok, got) => {
  if (ok) { pass++; console.log(`  ok  ${name}`); }
  else { fail++; console.log(`FAIL  ${name}\n      got: ${JSON.stringify(got)}`); }
};
/** Code with comments stripped — so a rule is never "satisfied" by prose describing it. */
const code = (s) => s.replace(/\{?\/\*[\s\S]*?\*\/\}?|\/\/[^\n]*/g, '');
const S = code(STRATEGIES), K = code(KEYWORDS);

console.log('\n1. Strategies owns the source-agnostic entry point');
// A BUTTON must carry the label — the bare string also appears as the dialog's
// defaultName here, so matching the string alone survives renaming the button.
const stratButtons = S.match(/<Button[\s\S]*?<\/Button>/g) ?? [];
check('a "New Strategy" button renders on the Strategies page',
  stratButtons.some((b) => b.includes('New Strategy') && b.includes('setCreateOpen(true)')),
  'no button labelled "New Strategy" opens the create dialog');
check('it opens the create dialog', /setCreateOpen\(true\)/.test(S), 'no opener');
check('the create dialog is mounted here', /<CreateStrategyDialog[\s\S]{0,400}open=\{createOpen\}/.test(S), 'not mounted');
check('mounted in CREATE mode, not edit mode',
  /open=\{createOpen\}[\s\S]{0,400}selectedCount=\{0\}/.test(S), 'not create mode');
check('no keyword selection is implied', /selectedKeywords=\{\[\]\}/.test(S), 'selection leaked in');
check('it calls strategy.create', /strategy\.create\.useMutation/.test(S), 'no create mutation');
check('the list refreshes after a create', /setCreateOpen\(false\);\s*refetch\(\)/.test(S), 'no refetch');

console.log('\n2. Keywords no longer carries the source-agnostic button');
// The literal string legitimately survives as the dialog's `defaultName`
// placeholder, so assert no BUTTON renders it — not that the words are absent.
const kwButtons = K.match(/<Button[\s\S]*?<\/Button>/g) ?? [];
check('no Keywords button is labelled "New Strategy"',
  !kwButtons.some((b) => b.includes('New Strategy')),
  kwButtons.filter((b) => b.includes('New Strategy')));
check('the only surviving mention is the dialog placeholder',
  (K.match(/New Strategy/g) ?? []).length === 1
  // [\s\S] not [^}]: the prop value is a ternary containing `${selectedCount}`,
  // whose closing brace ends a [^}] run long before the label.
  && /defaultName=\{[\s\S]{0,120}?New Strategy/.test(K),
  (K.match(/New Strategy/g) ?? []).length);
check('no bare setStrategyDialogOpen(true) outside the selection handler',
  (K.match(/setStrategyDialogOpen\(true\)/g) ?? []).length === 1,
  (K.match(/setStrategyDialogOpen\(true\)/g) ?? []).length);

console.log('\n3. …but the SELECTION-driven flow survives (the over-correction guard)');
check('bulk bar still offers "Create Strategy"', /BulkActionBar\.Action[^\n]*Create Strategy/.test(K), 'bulk action deleted');
check('it still routes through handleOpenStrategy', /onClick=\{handleOpenStrategy\}/.test(K), 'handler unwired');
check('the dialog is still mounted on Keywords', /<CreateStrategyDialog/.test(K), 'dialog removed');
check('it still passes the real selection', /selectedCount=\{selectedCount\}/.test(K), 'selection dropped');
check('the keyword create handler is intact', /handlePerformCreateStrategy/.test(K), 'handler gone');

console.log('\n4. The two entry points agree on payload shape');
// RSS/social items come from sources; seeding keyword items would also eat the
// weekly backpressure window. Both callers must special-case it the same way.
// Assert the RULE, not one spelling: Keywords tests a local `src` variable
// while Strategies tests `rest.sourceMode`, and both are correct.
for (const [label, src] of [['Strategies', S], ['Keywords', K]]) {
  check(`${label}: guards on both sourced modes`,
    /===\s*'rss'\s*\|\|\s*[\w.?]*\s*===\s*'social'/.test(src), label);
  // Keywords early-returns with a literal `keywords: []`; Strategies uses a
  // ternary. Both satisfy the rule — accept either shape.
  check(`${label}: and sends no keywords for them`,
    /keywords:\s*(?:\[\]|[\w.]+\s*\?\s*\[\])/.test(src), label);
}
check('Strategies forwards the dialog-typed keywords', /manualKeywords/.test(S), 'manual keywords dropped');
check('Strategies sends empty keywordMeta (no Explorer rows behind it)',
  /keywordMeta: \[\]/.test(S), 'keywordMeta wrong');

console.log('\n5. The shared dialog really supports the no-selection case');
check('selectedCount 0 starts on a source that needs no keyword',
  /selectedCount === 0 \? 'social' : 'keywords'/.test(code(DIALOG)), 'no-selection path missing');
check('defaultName is the placeholder and the save-time fallback',
  /placeholder=\{defaultName\}/.test(code(DIALOG)) && /name\.trim\(\) \? name\.trim\(\) : defaultName/.test(code(DIALOG)),
  'defaultName unused');

console.log('\n' + '-'.repeat(58));
console.log(`  passed: ${pass}   failed: ${fail}`);
process.exit(fail > 0 ? 1 : 0);
