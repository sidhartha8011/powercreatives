/**
 * Create-Strategy dialog — owner card (Bugs/not working):
 *
 *  A. "Is this test dead or not? Seems like it's getting red no matter what I do."
 *     The red under Template said "No writer template is written for this source"
 *     — a statement about the LIST, which nothing you pick in the dialog can
 *     change, so it stayed red whatever the user did. Now the note is about the
 *     PICK: red only when no template is selected (the one thing that blocks
 *     Create); amber, non-blocking, when the picked template is written for the
 *     other source; silent when it fits.
 *
 *  B. "This should not be above the target site. This should probably be in the
 *     schedule, and it should be clear what status the produced posts should
 *     take on. [Publish (after approved, if applicable)] or [Set to draft]".
 *     The Draft/Automatic toggle moved from the top of Publishing (above Target
 *     Site) to under the Publishing schedule divider as "Post status", with
 *     explicit labels + a one-line consequence. Same state, same payload.
 *
 * templateFitsSource / templateSourceFit are EXECUTED (real source, tsc-stripped);
 * the rest pins structure and order.
 *
 * Run: node tests/standalone/strategy_dialog_fit_and_status_test.mjs
 */

import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..', '..');
const src = readFileSync(join(ROOT, 'app/src/modules/Keywords/CreateStrategyDialog.tsx'), 'utf8');

let pass = 0, fail = 0;
const check = (name, ok, got) => {
  if (ok) { pass++; console.log(`  ok  ${name}`); }
  else { fail++; console.log(`FAIL  ${name}\n      got: ${JSON.stringify(got)}`); }
};
const at = (needle) => src.indexOf(needle);
const between = (a, b) => { const i = at(a); const j = at(b); return i >= 0 && j > i ? src.slice(i, j) : ''; };

// ── Execute the real classifier ─────────────────────────────────────────────
const fitSrc = between('const POST_VAR_RE', 'type SocialLinkInfo')
  .replace(/export function/g, 'function')
  // new Function() gets no type-stripping: drop the two annotations by hand.
  .replace(/\(template: any\): 'source' \| 'keyword'/, '(template)')
  .replace(/\(template: any, sourceMode: string\): boolean/, '(template, sourceMode)');
const { templateFitsSource } = new Function(fitSrc + '\nreturn { templateFitsSource, templateSourceFit };')();
const KW = { id: 1, name: 'SEO Pillar Article', entries: [{ category: 'prompt', value: 'Write about {{ keyword }}.' }] };
const RSS = { id: 2, name: 'RSS Reposting', entries: [{ category: 'prompt', value: 'Title: {{ post_title }} …' }] };
const NOPROMPT = { id: 3, name: 'Tone only', entries: [{ category: 'tonality', value: 'see {{ post_title }}' }] };

console.log('\n1. Classifier (unchanged, executed)');
check('keyword template fits keywords', templateFitsSource(KW, 'keywords') && !templateFitsSource(KW, 'rss'));
check('reposting template fits rss/social, not keywords', templateFitsSource(RSS, 'rss') && templateFitsSource(RSS, 'social') && !templateFitsSource(RSS, 'keywords'));
// 2026-08-18 (cards 5/13): EVERY writer entry is a prompt — the strategy loader normalises the
// category on read, so an old row saved under 'tonality'/'reference_ad' is classified by its text.
check('every writer entry counts (the engine normalises non-prompt categories on read)', templateFitsSource(NOPROMPT, 'rss') && !templateFitsSource(NOPROMPT, 'keywords'));

// ── A. the note is about the pick ────────────────────────────────────────────
console.log('\n2. The fit note is about the PICK, not the list');
const note = between('{/* Template fit, said about the PICK', '</AccordionSection>');
check('the old existence-red is gone', at('No writer template is written for this source') === -1);
check('it resolves the picked template', /const picked = all\.find\(\(t\) => String\(t\.id\) === templateId\)/.test(note));
check('red ONLY when nothing is picked — the real blocker', /if \(!picked\) \{[\s\S]*?text-destructive[\s\S]*?Select a template to continue\./.test(note));
check('…and it says how to get out (labelled list still usable / add one under Templates)',
  /you can still use one, or add a reposting template under Templates → Writer/.test(note)
  && /you can still use one, or add a keyword template under Templates → Writer/.test(note));
check('a fitting pick says NOTHING', /if \(templateFitsSource\(picked, sourceMode\)\) return null;/.test(note));
check('a mismatched pick is AMBER, not red', /text-amber-700[\s\S]*?is written for keywords[\s\S]*?is written for RSS\/Social/.test(note) && !/text-destructive[^]*?is written for RSS\/Social/.test(note.slice(note.indexOf('if (templateFitsSource'))));
check('the amber note names the consequence and the choice', /come out empty for a keyword strategy\. Pick a keyword template, or keep it if that is intended\./.test(note)
  && /the fetched item will not be in the prompt\. Pick a reposting template, or keep it if that is intended\./.test(note));
check('Create is still blocked by templateId only (fit never blocks)', /disabled=\{isSaving \|\| !templateId \|\| missingKeywords \|\| missingSite \|\| missingFeeds \|\| missingSocialLinks \|\| missingEndDate\}/.test(src)
  && !/disabled=\{[^}]*sourceTemplates/.test(src));

// ── B. Post status lives in the schedule ────────────────────────────────────
console.log('\n3. Post status: below Target Site, under the schedule divider, explicit');
const pub = between('<AccordionSection title="Publishing"', '<AccordionSection title="Content"');
const iSite = pub.indexOf('htmlFor="target-site"');
const iDivider = pub.indexOf('>Publishing schedule</Label>');
const iStatus = pub.search(/>\s*Post status\s*<\/Label>/); // the rendered label, not the comment/tooltip mentions
const iWhen = pub.indexOf('When to publish');
check('Target Site comes first', iSite > 0 && iSite < iDivider, { iSite, iDivider });
check('Post status sits UNDER the schedule divider, before When to publish', iDivider < iStatus && iStatus < iWhen, { iDivider, iStatus, iWhen });
check('the toggle is no longer above the Target Site', !/value=\{publishing\}[\s\S]*?htmlFor="target-site"/.test(pub));
check('the two options are explicit statuses', /value: 'auto', label: approvalMode !== 'none' \? 'Publish \(after approval\)' : 'Publish'/.test(pub) && /value: 'draft', label: 'Set to draft'/.test(pub));
check('the bare "Draft"/"Automatic" labels are gone', !/label: 'Draft' \}/.test(pub) && !/label: 'Automatic' \}/.test(pub));
check('a one-line consequence follows the pick (publish / after approval / kept as draft)',
  /once it has been approved\./.test(pub) && /as soon as it is written\./.test(pub) && /kept as a draft in Writer — nothing goes live until you publish it yourself\./.test(pub));
check('same state, same payload: publishing still feeds publishingMode', /publishing === 'auto' \? 'publish' : 'draft'/.test(src) && (src.match(/value=\{publishing\}/g) || []).length === 1);
check('missing-site copy speaks the new vocabulary', /Post status is set to Publish\./.test(pub) && !/to publish automatically\./.test(pub));
check('Target Site tooltip no longer says "Publishing is Automatic"', !/When Publishing is Automatic/.test(pub));

console.log('\n' + '-'.repeat(60));
console.log(`  passed: ${pass}   failed: ${fail}`);
process.exit(fail > 0 ? 1 : 0);
