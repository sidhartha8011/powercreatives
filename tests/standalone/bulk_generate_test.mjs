/**
 * Bulk "Generate (N)" — generate only the ticked items.
 *
 * Owner 2026-08-10: "Make the generate button appear on select (bulk actions)
 * so that it instead controls and allows the user to generate a select number
 * of items."
 *
 * No new endpoint was needed: POST /strategies/{id}/generate has always taken
 * an optional `itemId` (the per-item Retry uses it). The risk is not the call,
 * it is WHICH items get called — a targeted generate is destructive, because
 * the server explicitly allows any status ("any current status is allowed",
 * strategy/service.php), so a naive loop over the selection would overwrite
 * finished articles and double-generate items a worker already claimed.
 *
 * This imports the REAL planner (node --experimental-strip-types) instead of
 * transcribing it like the sibling frontend tests do — a transcription can
 * silently drift from the shipped code; this cannot.
 *
 * Run: node --experimental-strip-types tests/standalone/bulk_generate_test.mjs
 */

import { readFileSync } from 'node:fs';
import { fileURLToPath, pathToFileURL } from 'node:url';
import { dirname, join } from 'node:path';

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..', '..');
const SRC = join(ROOT, 'app/src/modules/Strategies');

const { planBulkGenerate, isFinishedItemStatus } =
  await import(pathToFileURL(join(SRC, 'bulkGenerate.ts')).href);

let pass = 0, fail = 0;
const check = (name, ok, got) => {
  if (ok) { pass++; console.log(`  ok  ${name}`); }
  else { fail++; console.log(`FAIL  ${name}\n      got: ${JSON.stringify(got)}`); }
};

const item = (id, status) => ({ id, status });
// A realistic strategy: two pending, one mid-flight, one done, one errored.
const ITEMS = [
  item(1, 'pending'),
  item(2, 'generating'),
  item(3, 'completed'),
  item(4, 'error'),
  item(5, 'pending'),
];
const plan = (ids, consolidated = false, regen = true) =>
  planBulkGenerate(ITEMS, ids, consolidated, regen);

console.log('\n1. It generates EXACTLY the selection — not all, not the next one');
let p = plan([1, 5]);
check('two ticked pending items produce two calls', p.calls.length === 2, p.calls);
check('and they are the ticked ids, in order', JSON.stringify(p.calls) === '[1,5]', p.calls);
p = plan([4]);
check('one ticked item produces one call', JSON.stringify(p.calls) === '[4]', p.calls);
check('unticked items are never touched', !plan([1]).calls.includes(5), plan([1]).calls);
check('an empty selection generates nothing', plan([]).calls.length === 0, plan([]).calls);
check('an unknown id is ignored, not sent', plan([999]).calls.length === 0, plan([999]).calls);

console.log('\n2. An item a worker already owns is NOT re-issued');
// Re-issuing a 'generating' item double-generates it: the server's atomic
// claim guards the untargeted path, but a targeted call bypasses that pick.
p = plan([1, 2, 5]);
check('the generating item is skipped', !p.calls.includes(2), p.calls);
check('its siblings still run', JSON.stringify(p.calls) === '[1,5]', p.calls);
check('and it is reported as in-flight, not silently dropped', p.inFlight === 1, p.inFlight);
p = plan([2]);
check('selecting ONLY a generating item is a no-op', p.calls.length === 0, p.calls);
check('…and still reports why', p.inFlight === 1, p.inFlight);

console.log('\n3. Finished articles are surfaced before they are overwritten');
p = plan([1, 3]);
check('the finished item is flagged for the confirm', p.finished.map((i) => i.id).join() === '3', p.finished);
check('confirming regenerates it', p.calls.includes(3), p.calls);
const declined = plan([1, 3], false, /* regen */ false);
check('declining spares the finished item', !declined.calls.includes(3), declined.calls);
check('declining still generates the rest', JSON.stringify(declined.calls) === '[1]', declined.calls);
check('nothing is flagged when no target is finished', plan([1, 5]).finished.length === 0, plan([1, 5]).finished);
// All three spellings must count as finished, or the confirm misses the article it destroys.
for (const s of ['written', 'completed', 'complete']) {
  check(`'${s}' counts as finished`, isFinishedItemStatus(s) === true, s);
  const one = planBulkGenerate([item(9, s)], [9], false, false);
  check(`'${s}' is spared when declining`, one.calls.length === 0, one.calls);
}
for (const s of ['pending', 'error', 'generating', '']) {
  check(`'${s}' is NOT finished`, isFinishedItemStatus(s) === false, s);
}
// An errored item is the main thing you'd bulk-retry: it must never need a confirm.
check('an errored item needs no overwrite confirm', plan([4]).finished.length === 0, plan([4]).finished);

console.log('\n4. A consolidated strategy costs ONE call, not N');
// Every keyword shares one article, so N calls = N expensive LLM runs for one result.
p = plan([1, 4, 5], /* consolidated */ true);
check('three selected items collapse to a single call', p.calls.length === 1, p.calls);
check('but all three are still counted as targets', p.targets.length === 3, p.targets.length);
check('an empty consolidated selection makes no call', plan([], true).calls.length === 0, plan([], true).calls);
check('consolidated still skips in-flight items', !plan([2], true).calls.length, plan([2], true).calls);
check('the individual path does NOT collapse', plan([1, 4, 5], false).calls.length === 3, plan([1, 4, 5], false).calls);

console.log('\n5. The button is wired to it, and the run is sequential');
const idx = readFileSync(join(SRC, 'index.tsx'), 'utf8');
// The bulk bar region: from its marker to the item list that follows.
const bar = idx.slice(idx.indexOf('Item bulk bar'), idx.indexOf('{strategy.items.map('));
check('a Generate button exists in the bulk bar', /Generate \(\{ids\.length\}\)/.test(bar), 'no button');
check('it shows the selected count', bar.includes('{ids.length}'), 'no count');
check('it calls the bulk-generate handler', bar.includes('handleGenerateSelected(strategy, ids)'), 'not wired');
check('it is the primary action in the bar', /variant="default"/.test(bar), 'not primary');
check('it is disabled while a run owns this strategy',
  /disabled=\{itemBulkBusy \|\| bulkStrategyId === strategy\.id/.test(bar), 'double-fireable');
check('it shows a spinner mid-run', bar.includes('animate-spin'), 'no progress feedback');

// Slice from the declaration to ITS OWN closing deps array. Anchoring the end
// on a neighbouring function is fragile — the handler was moved during this
// task and an end-anchor that had drifted above the start silently produced an
// EMPTY slice, which failed every check below for the wrong reason.
const hStart = idx.indexOf('const handleGenerateSelected');
const hEnd = idx.indexOf('}, [generateMutation, refetch, clearItemSelection]);', hStart);
check('the handler slice is real (guards against an empty-region false failure)',
  hStart !== -1 && hEnd > hStart, { hStart, hEnd });
const handler = idx.slice(hStart, hEnd);
check('the handler uses the shared planner (no second implementation)',
  handler.includes('planBulkGenerate('), 'logic duplicated');
check('it AWAITS each call — concurrent generates are what caused the 502s',
  /for \(const itemId of plan\.calls\)[\s\S]*?await generateMutation\.mutateAsync/.test(handler), 'fires concurrently');
check('it sends itemId (targeted), not a bare strategy generate',
  /mutateAsync\(\{ id: strategy\.id, itemId \}\)/.test(handler), 'not targeted');
check('one failure does not abort the batch', /catch \{\s*\n\s*fail\+\+/.test(handler), 'batch aborts');
check('it suppresses per-item toasts via bulk mode', handler.includes('bulkModeRef.current = true'), 'toast spam');
check('it restores state in finally', /\} finally \{[\s\S]*setBulkStrategyId\(null\)/.test(handler), 'can wedge');
check('it refetches so the rows update', handler.includes('refetch()'), 'stale rows');

console.log('\n6. The route really does carry itemId to the server');
const routes = readFileSync(join(ROOT, 'app/src/lib/trpc-routes.ts'), 'utf8');
const gen = routes.slice(routes.indexOf('"strategy.generate"'), routes.indexOf('"strategy.generate"') + 320);
check('strategy.generate forwards itemId', gen.includes('itemId'), gen);
const php = readFileSync(join(ROOT, 'includes/modules/strategy/controller.php'), 'utf8');
check('the PHP handler reads itemId', /\$params\['itemId'\]/.test(php), 'server ignores itemId');
check('and passes it to the service', /generate_next_item\(\$strategy, \(int\)\$pcm_user->id, \$item_id\)/.test(php), 'not threaded');

console.log('\n' + '-'.repeat(58));
console.log(`  passed: ${pass}   failed: ${fail}`);
process.exit(fail > 0 ? 1 : 0);
