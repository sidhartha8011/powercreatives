/**
 * Writer updates — the 1-Add-Writer-Updates PDF, pinned.
 *
 *  1. Panels default CLOSED (right menu + both left side bars).
 *  2. Panel states are INDEPENDENT — closing one must never reopen another
 *     (the desiredCollapsed drift-guard, on every collapsible panel).
 *  3. The right drawer is a HISTORY QUEUE; AI Review lives in its OWN drawer —
 *     no History/AI-Review tab toggle in one panel.
 *  4. Publish is the always-blue action; the approvals button is never the
 *     lit one, and "Sent to approval" is a visible state (label + status).
 *  5. Same shared accordions + shared controls as Ads/Video — the bespoke
 *     grey-styled inputs and inline-styled dropdowns are gone.
 *
 * Run: node tests/standalone/writer_updates_test.mjs
 */

import { readFileSync, existsSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..', '..');
const W = (p) => join(ROOT, 'app/src/modules/Writer', p);

let pass = 0, fail = 0;
const check = (name, ok, got) => {
  if (ok) { pass++; console.log(`  ok  ${name}`); }
  else { fail++; console.log(`FAIL  ${name}\n      got: ${JSON.stringify(got)}`); }
};

const index = readFileSync(W('index.tsx'), 'utf8');

console.log('\n1. Everything starts CLOSED (right menu + both left side bars)');
check('all four side panels default to size 0',
  (index.match(/^\s*defaultSize=\{0\}$/gm) || []).length === 4, index.match(/^\s*defaultSize=\{\d+\}$/gm));
check('the editor takes the full width by default', index.includes('defaultSize={100}'));
check('desired state starts collapsed for every panel',
  /desiredCollapsed = useRef\(\{ queue: true, context: true, revisions: true, review: true \}\)/.test(index),
  index.match(/desiredCollapsed = useRef\([^)]*\)/)?.[0]);

console.log('\n2. Panel states are independent (closing one never reopens another)');
// Guards re-assert intent by re-imposing the WHOLE desired layout — per-panel
// expand() is pivot-bound and no-ops when the only follower is collapsed
// (the "Show Revisions did nothing" bug; see writer_panel_expand_test.mjs).
for (const key of ['queue', 'context', 'revisions', 'review']) {
  check(`${key}: spurious collapse re-asserts the desired layout (drift-guard)`,
    new RegExp(`if \\(!desiredCollapsed\\.current\\.${key}\\) \\{\\s*(?:\\/\\/[^\\n]*\\n\\s*)*applyDesiredLayout\\(\\);`).test(index), key);
  check(`${key}: spurious expand re-asserts the desired layout (drift-guard)`,
    new RegExp(`if \\(desiredCollapsed\\.current\\.${key}\\) \\{\\s*(?:\\/\\/[^\\n]*\\n\\s*)*applyDesiredLayout\\(\\);`).test(index), key);
}

console.log('\n3. Revisions is a history queue; AI Review has its own drawer');
check('the old combined panel is GONE', !existsSync(W('components/AiRevisionsPanel.tsx')));
check('RevisionsPanel exists', existsSync(W('components/RevisionsPanel.tsx')));
check('AiReviewPanel exists', existsSync(W('components/AiReviewPanel.tsx')));
const revisions = readFileSync(W('components/RevisionsPanel.tsx'), 'utf8');
const review = readFileSync(W('components/AiReviewPanel.tsx'), 'utf8');
check('RevisionsPanel is history-only (no AI review pipeline, no tabs)',
  revisions.includes('trpc.writer.revisions.useQuery')
  && !revisions.includes('aiReview') && !/Tabs/.test(revisions), 'AI leaked into the history drawer');
check('RevisionsPanel keeps Restore', revisions.includes('revisionRestore') && revisions.includes('Restore'));
check('AiReviewPanel hosts the review pipeline (and no history)',
  review.includes('trpc.writer.aiReview.useMutation') && review.includes('aiReviewApply')
  && !review.includes('trpc.writer.revisions.useQuery'), 'panels not cleanly split');
check('no tab toggle in either drawer (the inline toggle is dead)',
  !/TabsTrigger/.test(revisions) && !/TabsTrigger/.test(review));
check('index mounts BOTH panels as separate columns',
  index.includes('<RevisionsPanel onCollapse={collapseRevisionsFromHeader} />')
  && index.includes('<AiReviewPanel onCollapse={collapseReviewFromHeader} />'));
check('each drawer has its own toolbar toggle',
  index.includes('onClick={toggleRevisions}') && index.includes('onClick={toggleReview}'));

console.log('\n4. Publish is the blue one; approval only a state');
const publishBtn = index.slice(index.indexOf('handlePublish}') - 400, index.indexOf('handlePublish}') + 200);
check('Publish button is always variant="active" (blue, lit)',
  /variant="active"\s*\n?\s*icon=\{<Rocket \/>\}/.test(index), 'Publish not always-blue');
const approvalsAt = index.indexOf('onClick={handleOpenApprovalDialog}');
const approvalsBtn = index.slice(approvalsAt - 500, approvalsAt + 500);
check('approvals button is never the lit-blue one',
  approvalsBtn.includes('variant="subtle"') && !approvalsBtn.includes("? 'active'"), approvalsBtn.slice(0, 200));
check('"Sent to Approval" is a visible state on the button',
  approvalsBtn.includes("'Sent to Approval'"));
check('sending marks the articles as in review (badge shows the state)',
  /sentIds\.includes\(d\.id\) && d\.status !== 'published' \? \{ \.\.\.d, status: 'review' \}/.test(index));

console.log('\n5. Shared accordions + shared controls (the grey ones are gone)');
const dyn = readFileSync(W('components/WriterDynamicSection.tsx'), 'utf8');
const ctx = readFileSync(W('components/ContextGenerationPanel.tsx'), 'utf8');
check('both section renderers use the SHARED AccordionSection (same as Ads/Video)',
  dyn.includes('AccordionSection') && ctx.includes('AccordionSection'));
check('no raw <input>/<textarea> in the dynamic fields — shared Input/Textarea',
  !/<input/.test(dyn) && !/<textarea/.test(dyn)
  && dyn.includes('@/components/ui/input') && dyn.includes('@/components/ui/textarea'), 'bespoke grey inputs back');
check('no grey background overrides on shared controls',
  !ctx.includes('style={{ background: colors.bgSurface }}')
  && !dyn.includes('background: colors.bgSurface'), 'bgSurface override back');
const canvas = readFileSync(W('components/ReviewEditorCanvas.tsx'), 'utf8');
const toolbar = readFileSync(W('components/ReviewEditorToolbar.tsx'), 'utf8');
for (const [name, src] of [['ReviewEditorCanvas', canvas], ['ReviewEditorToolbar', toolbar], ['ContextGenerationPanel', ctx], ['WriterDynamicSection', dyn]]) {
  check(`${name}: no inline-styled SelectTrigger (style comes from the shared variant)`,
    !/SelectTrigger\s*\n?\s*style=/.test(src) && !/SelectTrigger style=/.test(src), name);
}

console.log('\n' + '-'.repeat(60));
console.log(`  passed: ${pass}   failed: ${fail}`);
process.exit(fail > 0 ? 1 : 0);
