/**
 * Optimizer "analyze all" — bounded concurrency and transient retry.
 *
 * Every teacher came back "API error: 502" except the one fast local check.
 * analyzeAll() fired EVERY teacher at once via forEach, and each one is a PHP
 * request holding a worker while it calls an LLM or an external API. Eight at a
 * time exhausts the worker pool a typical WordPress host allows, and the proxy
 * answers 502 for whichever ones lost the race — precisely the reported shape:
 * "Keyword placement — 1 passed", everything slower 502.
 *
 * The predicates below are transcribed from useOptimizer.ts; section 5 reads the
 * real source back so a transcription that drifts fails instead of lying.
 *
 * Run: node tests/standalone/optimizer_concurrency_test.mjs
 */

import { readFileSync, readdirSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..', '..');

let pass = 0, fail = 0;
const check = (name, ok, got) => {
  if (ok) { pass++; console.log(`  ok  ${name}`); }
  else { fail++; console.log(`FAIL  ${name}\n      got: ${JSON.stringify(got)}`); }
};

// ── Transcribed from useOptimizer.ts ───────────────────────────────────────
const MAX_PARALLEL = 2;
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

const isTransient = (e) => {
  const status = e?.status;
  if (status === 502 || status === 503 || status === 504) return true;
  const msg = e instanceof Error ? e.message : String(e ?? '');
  return /\b(50[234])\b|bad gateway|gateway time-?out|service unavailable/i.test(msg);
};

/** analyzeAll's pool: a fixed number of workers draining one queue. */
async function analyzeAll(ids, runOne) {
  const queue = [...ids];
  const drain = async () => {
    for (;;) {
      const id = queue.shift();
      if (id === undefined) return;
      await runOne(id);
    }
  };
  await Promise.all(Array.from({ length: Math.min(MAX_PARALLEL, queue.length) }, drain));
}

console.log('\n1. Transient gateway failures are recognised');
for (const [e, why] of [
  [{ status: 502 }, 'status 502'],
  [{ status: 503 }, 'status 503'],
  [{ status: 504 }, 'status 504'],
  [new Error('API error: 502 Bad Gateway'), 'message with 502'],
  [new Error('504 Gateway Time-out'), 'message with 504'],
  [new Error('Service Unavailable'), 'service unavailable text'],
]) check(`transient: ${why}`, isTransient(e) === true, e);

console.log('\n2. Real faults are NOT retried');
// 500 is the server reporting a genuine fault; retrying only doubles the wait.
for (const [e, why] of [
  [{ status: 500 }, 'status 500'],
  [new Error('API error: 500 Internal Server Error'), 'message with 500'],
  [{ status: 400 }, 'status 400'],
  [{ status: 403 }, 'status 403'],
  [new Error('No primary keyword set'), 'a domain error'],
]) check(`not transient: ${why}`, isTransient(e) === false, e);

console.log('\n3. Never more than MAX_PARALLEL analyses in flight');
{
  let inFlight = 0, peak = 0;
  const order = [];
  const runOne = async (id) => {
    inFlight++; peak = Math.max(peak, inFlight);
    order.push(id);
    await sleep(5);
    inFlight--;
  };
  const ids = ['keyword', 'coverage', 'serp', 'gsc', 'interlink', 'answers', 'recs', 'facts'];
  await analyzeAll(ids, runOne);
  check(`peak concurrency is ${MAX_PARALLEL}, not ${ids.length}`, peak === MAX_PARALLEL, peak);
  check('every teacher still ran', order.length === ids.length, order.length);
  check('none ran twice', new Set(order).size === ids.length, order);
  // The old forEach would have hit 8.
  check('the old fan-out shape is gone', peak < ids.length, peak);
}

console.log('\n4. A queue shorter than the pool spawns only what it needs');
{
  let started = 0;
  await analyzeAll(['only-one'], async () => { started++; });
  check('one teacher runs once', started === 1, started);
  await analyzeAll([], async () => { started++; });
  check('an empty queue runs nothing', started === 1, started);
}

console.log('\n5. One retry on transient, none on a real fault');
{
  // Transcribed retry wrapper.
  const withRetry = async (send) => {
    try { return await send(); }
    catch (e) {
      if (!isTransient(e)) throw e;
      await sleep(1);
      return send();
    }
  };

  let calls = 0;
  const flaky = async () => { calls++; if (calls === 1) throw { status: 502 }; return { items: [] }; };
  const res = await withRetry(flaky);
  check('a 502 is retried and then succeeds', calls === 2 && !!res, { calls, res });

  calls = 0;
  const broken = async () => { calls++; throw { status: 500, message: 'boom' }; };
  let threw = false;
  try { await withRetry(broken); } catch { threw = true; }
  check('a 500 is not retried', calls === 1 && threw, { calls, threw });

  calls = 0;
  const alwaysBusy = async () => { calls++; throw { status: 502 }; };
  threw = false;
  try { await withRetry(alwaysBusy); } catch { threw = true; }
  check('retry happens ONCE, not in a loop', calls === 2 && threw, { calls, threw });
}

console.log('\n6. The component still implements these rules');
const src = readFileSync(join(ROOT, 'app/src/modules/SEO/optimizer/useOptimizer.ts'), 'utf8');
check('a concurrency cap exists', /const MAX_PARALLEL = \d+;/.test(src), 'no cap');
check('the cap is small enough to matter', (src.match(/const MAX_PARALLEL = (\d+);/) ?? [])[1] <= 3, 'cap too high');
check('analyzeAll drains a queue, not forEach',
  src.includes('queue.shift()') && !/analyzeAll[\s\S]{0,200}teachers\.forEach/.test(src), 'still fans out');
check('workers are capped by the queue length too',
  src.includes('Math.min(MAX_PARALLEL, queue.length)'), 'unbounded worker spawn');
check('analyzeOne is awaited by the worker', /await analyzeOne\(id\)/.test(src), 'not awaited — cap is cosmetic');
check('transient detection present', src.includes('const isTransient'), 'no transient check');
check('exactly one retry', (src.match(/await sleep\(RETRY_DELAY_MS\)/g) ?? []).length === 1, 'retry count wrong');
check('a superseded run bails after the backoff',
  /await sleep\(RETRY_DELAY_MS\);[\s\S]{0,120}runSeq\.current\[teacherId\] !== seq/.test(src), 'stale result can land');
check('the 502 message explains itself',
  src.includes('too busy to answer') && src.includes('Already retried once'), 'still a bare API error');

console.log('\n7. A served error message is never hidden behind "too busy"');
// The optimizer controller maps teacher exceptions to HTTP 502 WITH a JSON
// message (e.g. an LLM timeout). Only the shim's no-JSON fallback — a real
// gateway page — earns the generic wording; anything else keeps its diagnosis.
const isGatewayPage = (e) => e instanceof Error && /^API error: 50[234]\b/.test(e.message);
check('the bare shim fallback reads as a gateway page',
  isGatewayPage(new Error('API error: 502 Bad Gateway')) === true);
check('a served timeout message keeps its own words',
  isGatewayPage(new Error('cURL error 28: Operation timed out after 60001 ms')) === false);
check('a served model error keeps its own words',
  isGatewayPage(new Error('LLM API error 400: Developer instruction is not enabled')) === false);
check('component gates the busy text on isGatewayPage',
  src.includes('isGatewayPage(e)') && src.includes('const isGatewayPage'), 'gate missing');

console.log('\n8. Every optimizer analysis LLM call is time-capped');
// The 300s PCM_LLM default outlives any gateway; each call must carry the cap.
const optDir = join(ROOT, 'includes/modules/optimizer');
const phpFiles = [join(optDir, 'service.php'),
  ...readdirSync(join(optDir, 'teachers')).filter((f) => f.endsWith('.php')).map((f) => join(optDir, 'teachers', f))];
let llmCalls = 0, capRefs = 0;
for (const f of phpFiles) {
  const php = readFileSync(f, 'utf8');
  llmCalls += [...php.matchAll(/PCM_LLM::(invoke_json|invoke_with_grounding|invoke)\(/g)].length;
  capRefs += (php.match(/ANALYZE_TIMEOUT/g) ?? []).length;
}
check('the module makes LLM calls at all', llmCalls >= 8, llmCalls);
check('a shared cap constant exists',
  readFileSync(join(optDir, 'service.php'), 'utf8').includes('const ANALYZE_TIMEOUT = 60'), 'no constant');
check('every call site references the cap (8 sites + 1 definition)', capRefs === 9, capRefs);


console.log('\n' + '─'.repeat(52));
console.log(`  passed: ${pass}   failed: ${fail}`);
process.exit(fail > 0 ? 1 : 0);
