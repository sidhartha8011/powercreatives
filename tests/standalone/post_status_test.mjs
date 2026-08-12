/**
 * WordPress post status — per-item dropdown + bulk change.
 *
 * Owner 2026-08-10: "Bulk: Add a dropdown option to allow to change the status
 * on wordpress, use all statuses a post or page can have in wordpress …
 * Single: Also add individual status dropdown for each post."
 *
 * The per-item control already existed but offered only publish/draft/trash,
 * and it derived its VALUE from the local Writer status — so Pending and
 * Private collapsed to "Draft" and looked like they had not saved. The fix
 * needed a real remote-status column (articles.publishedStatus, v1.46.0),
 * because `articles.status` is the Writer workflow vocabulary
 * (draft|review|ready|published) and overloading it would corrupt Writer.
 *
 * Imports the REAL module (node --experimental-strip-types) rather than
 * transcribing it, and cross-checks the UI list against the hub's PHP list —
 * if they drift, the UI offers a status the server 400s.
 *
 * Run: node --experimental-strip-types tests/standalone/post_status_test.mjs
 */

import { readFileSync } from 'node:fs';
import { fileURLToPath, pathToFileURL } from 'node:url';
import { dirname, join } from 'node:path';

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..', '..');
const SRC = join(ROOT, 'app/src/modules/Strategies');
const mod = await import(pathToFileURL(join(SRC, 'postStatus.ts')).href);
const { POST_STATUSES, planBulkPostStatus, isDestructiveStatus, postStatusLabel } = mod;

let pass = 0, fail = 0;
const check = (name, ok, got) => {
  if (ok) { pass++; console.log(`  ok  ${name}`); }
  else { fail++; console.log(`FAIL  ${name}\n      got: ${JSON.stringify(got)}`); }
};

const values = POST_STATUSES.map((s) => s.value);

console.log('\n1. Every status a WordPress post/page can be moved to');
for (const s of ['publish', 'future', 'draft', 'pending', 'private', 'trash']) {
  check(`offers '${s}'`, values.includes(s), values);
}
// auto-draft/inherit are internal WordPress states, never user-selectable.
for (const s of ['auto-draft', 'inherit']) {
  check(`does NOT offer the internal '${s}'`, !values.includes(s), values);
}
check('no duplicate values', new Set(values).size === values.length, values);
check('every option has a human label', POST_STATUSES.every((s) => !!s.label), POST_STATUSES);
check('labels read like WordPress', postStatusLabel('pending') === 'Pending Review', postStatusLabel('pending'));
check('an unknown status degrades to its raw value', postStatusLabel('zzz') === 'zzz', postStatusLabel('zzz'));
check('a null status is blank, not "null"', postStatusLabel(null) === '', postStatusLabel(null));

console.log('\n2. Destructive statuses are flagged so the UI can confirm');
check('trash is destructive', isDestructiveStatus('trash') === true);
for (const s of ['publish', 'draft', 'pending', 'private', 'future']) {
  check(`${s} is not destructive`, isDestructiveStatus(s) === false, s);
}

console.log('\n3. The UI list matches the hub, or the server 400s what the UI offers');
const php = readFileSync(join(ROOT, 'includes/modules/strategy/service.php'), 'utf8');
const m = php.match(/POST_STATUSES\s*=\s*array\(([^)]*)\)/);
check('the hub exposes POST_STATUSES', !!m, 'constant missing');
if (m) {
  const phpValues = [...m[1].matchAll(/'([a-z-]+)'/g)].map((x) => x[1]);
  check('hub and UI offer the SAME statuses',
    JSON.stringify([...phpValues].sort()) === JSON.stringify([...values].sort()),
    { php: phpValues, ui: values });
}
const ctl = readFileSync(join(ROOT, 'includes/modules/strategy/controller.php'), 'utf8');
check('the controller validates against that shared list',
  ctl.includes('PCM_Strategy_Service::POST_STATUSES'), 'controller has its own hardcoded list');
check('the old 3-status whitelist is gone from the controller',
  !/in_array\(\$status, array\('draft', 'publish', 'trash'\)/.test(ctl), 'still limited to 3');
check('the old 3-status whitelist is gone from the service',
  !/in_array\(\$status, array\('draft', 'publish', 'trash'\)/.test(php), 'still limited to 3');

console.log('\n4. Bulk targets only items that HAVE a live post');
const item = (id, postId) => ({ id, articlePublishedPostId: postId });
const ITEMS = [item(1, 101), item(2, null), item(3, 303), item(4, undefined), item(5, 505)];
let p = planBulkPostStatus(ITEMS, [1, 3, 5]);
check('three live posts produce three targets', p.targets.length === 3, p.targets);
check('and nothing is skipped', p.skipped === 0, p.skipped);
p = planBulkPostStatus(ITEMS, [1, 2, 4]);
check('unpublished items are filtered out', p.targets.map((t) => t.id).join() === '1', p.targets);
check('…and reported as skipped, not silently dropped', p.skipped === 2, p.skipped);
p = planBulkPostStatus(ITEMS, [2, 4]);
check('a selection with no live posts yields no calls', p.targets.length === 0, p.targets);
check('…and says why', p.skipped === 2, p.skipped);
check('an empty selection is a no-op', planBulkPostStatus(ITEMS, []).targets.length === 0);
check('unknown ids are ignored', planBulkPostStatus(ITEMS, [999]).targets.length === 0);
check('unticked live items are never touched', !planBulkPostStatus(ITEMS, [1]).targets.some((t) => t.id === 3));

console.log('\n5. Both UI surfaces are wired to the shared list');
const idx = readFileSync(join(SRC, 'index.tsx'), 'utf8');
const bar = idx.slice(idx.indexOf('Item bulk bar'), idx.indexOf('{strategy.items.map('));
check('the bulk bar has a status dropdown', bar.includes('handleBulkPostStatus(strategy, ids, value)'), 'not wired');
check('the bulk dropdown renders the shared list', /POST_STATUSES\.map/.test(bar), 'hardcoded options');
check('it is disabled during a bulk run', /disabled=\{itemBulkBusy \|\| bulkStrategyId === strategy\.id\}/.test(bar), 'double-fireable');
// WHERE the per-item control renders, not merely THAT it exists.
// This section exists because the original version of this test only asserted
// existence — and passed while the dropdown sat inside the per-item overrides
// sub-panel, which is collapsed behind its own toggle. The post row therefore
// had no status control at all, and the test said it did.
const itemsStart = idx.indexOf('{strategy.items.map(');
const overridesGate = idx.indexOf('{openOverridesItemId === item.id && (', itemsStart);
check('the item row and the overrides sub-panel are both locatable',
  itemsStart !== -1 && overridesGate > itemsStart, { itemsStart, overridesGate });
const rowRegion = idx.slice(itemsStart, overridesGate);          // always visible
const overridesRegion = idx.slice(overridesGate);                // behind a toggle

check('the status dropdown is ON THE ROW (always visible)',
  /POST_STATUSES\.map/.test(rowRegion), 'not on the row');
check('it is NOT buried in the collapsed overrides sub-panel',
  !/POST_STATUSES\.map/.test(overridesRegion), 'still hidden behind the overrides toggle');
check('exactly ONE per-item status dropdown exists (no duplicate left behind)',
  (idx.slice(itemsStart).match(/POST_STATUSES\.map/g) ?? []).length === 1,
  (idx.slice(itemsStart).match(/POST_STATUSES\.map/g) ?? []).length);
// Look BACKWARDS from the dropdown. A forward regex anchors on the FIRST
// `livePostOf(item) && (` in the region — which is the Edit/Preview block far
// above — and then fails on distance rather than on the actual condition.
const dropdownAt = rowRegion.indexOf('POST_STATUSES.map');
const before = rowRegion.slice(Math.max(0, dropdownAt - 1400), dropdownAt);
check('it sits beside the row actions, gated on a live post like Edit/Preview',
  /livePostOf\(item\) && \(/.test(before), before.slice(-160));
// Scope this to the dropdown's OWN trigger. The row has other controls that
// stop propagation (the due-date input), so a region-wide search passes even
// when the status trigger has lost it and a click would collapse the row.
const triggerWindow = rowRegion.slice(Math.max(0, dropdownAt - 700), dropdownAt);
check('clicking it does not toggle the row',
  /onClick=\{\(e\) => e\.stopPropagation\(\)\}/.test(triggerWindow), triggerWindow.slice(-200));
check('the per-item value reads the REMOTE status',
  rowRegion.includes('item.articlePublishedStatus'), 'still derives from the local Writer status');
check('no hardcoded 3-option list remains',
  !/<SelectItem value="publish">Published<\/SelectItem>\s*<SelectItem value="draft">/.test(idx), 'old triple still there');
const handler = idx.slice(idx.indexOf('const handleBulkPostStatus'), idx.indexOf('}, [postStatusMutation, refetch, clearItemSelection]);'));
check('the bulk handler slice is real', handler.length > 100, handler.length);
check('it uses the shared planner', handler.includes('planBulkPostStatus('), 'logic duplicated');
check('it AWAITS each call (no concurrent fan-out to the client site)',
  /for \(const target of plan\.targets\)[\s\S]*?await postStatusMutation\.mutateAsync/.test(handler), 'fires concurrently');
check('one failure does not abort the batch', /catch \(e: any\) \{[\s\S]*?failed\+\+/.test(handler), 'batch aborts');
check('it confirms before trashing', handler.includes('isDestructiveStatus(status)'), 'no confirm');
check('it surfaces the server reason, not just a count', handler.includes('lastError'), 'unactionable errors');

console.log('\n6. The remote status is actually PERSISTED (or the dropdown snaps back)');
check('articles gained publishedStatus',
  readFileSync(join(ROOT, 'includes/core/db/class-pcm-schema.php'), 'utf8').includes('publishedStatus varchar(20)'), 'no column');
check('the DB version was bumped for it',
  readFileSync(join(ROOT, 'power-creatives.php'), 'utf8').includes("PCM_DB_VERSION', '1.46.0"), 'not bumped');
check('the item query selects it',
  readFileSync(join(ROOT, 'includes/core/db/class-pcm-db.php'), 'utf8').includes('a.publishedStatus AS articlePublishedStatus'), 'not joined');
check('the API serialises it', ctl.includes("'articlePublishedStatus'"), 'not exposed');
check('a status change writes it', /'publishedStatus' => \$status/.test(php), 'not persisted');
check('trashing clears it', /'publishedStatus'\s*=> null/.test(php), 'stale after trash');
check('the WP sync reconciles it', /update_article\(\(int\)\$article->id, \$user_id, array\('publishedStatus' => \$remote_status\)\)/.test(php), 'sync ignores it');
check('the local Writer vocabulary is NOT widened',
  /'status'\s*=> \$status === 'publish' \? 'published' : 'draft'/.test(php), 'Writer states corrupted');

console.log('\n7. Scheduled needs a real future date');
check('future is flagged as needing a date',
  POST_STATUSES.find((s) => s.value === 'future')?.needsDate === true, POST_STATUSES);
// Tie each message to its LIVE condition. Asserting the message alone passes
// even when the guard is disabled (`if (false)`) and the throw is dead code —
// which is exactly how the first negative-control run slipped through.
check('the hub refuses future with no date',
  /if \(\$when === '' \|\| strtotime\(\$when\) === false\)[\s\S]{0,220}?Set a schedule date on this item before choosing Scheduled/.test(php),
  'guard disabled or message orphaned');
check('the hub refuses a past date',
  /if \(strtotime\(\$when\) <= time\(\)\)[\s\S]{0,220}?schedule date is in the past/.test(php),
  'guard disabled or message orphaned');
check('the hub sends the date alongside the status', /\$body\['date'\] = gmdate/.test(php), 'no date sent');

console.log('\n' + '-'.repeat(60));
console.log(`  passed: ${pass}   failed: ${fail}`);
process.exit(fail > 0 ? 1 : 0);
