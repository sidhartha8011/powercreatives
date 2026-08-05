/**
 * "/" variable typeahead — trigger, filter and insert rules.
 *
 * Guards SlashVariableMenu.tsx, which lets a template author type "/" in the
 * Value textarea and pick a {{ variable }} instead of remembering its spelling.
 *
 * The risky part is not the menu, it is deciding WHEN "/" means "open the list".
 * "/" is ordinary text in a prompt — "and/or", "24/7", "https://example.com" —
 * so a naive trigger would pop the menu open constantly while someone writes.
 *
 * The predicates below are transcribed from the component. Section 6 then reads
 * the real TSX back and asserts the rules it relies on are still literally
 * there, so a transcription that silently drifts fails instead of lying.
 *
 * Run: node tests/standalone/slash_variable_menu_test.mjs
 */

import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..', '..');

let pass = 0, fail = 0;
const check = (name, ok, got) => {
  if (ok) { pass++; console.log(`  ok  ${name}`); }
  else { fail++; console.log(`FAIL  ${name}\n      got: ${JSON.stringify(got)}`); }
};

// ── Transcribed from SlashVariableMenu.tsx ─────────────────────────────────
const tokenName = (token) => token.replace(/[{}]/g, '').trim().toLowerCase();

/** Returns {start, query} when "/" should open the menu, else null. */
function trigger(text, caret) {
  const upto = text.slice(0, caret);
  const slash = upto.lastIndexOf('/');
  if (slash === -1) return null;
  const prev = slash === 0 ? '' : upto[slash - 1];
  const typed = upto.slice(slash + 1);
  if ((slash === 0 || /\s/.test(prev)) && !/\s/.test(typed)) {
    return { start: slash, query: typed };
  }
  return null;
}

// Variables are {token, description} objects — the menu shows the description
// beside the token. Matching stays on the TOKEN only: descriptions are prose, so
// a two-letter query would otherwise match nearly everything.
const filter = (vars, query) => {
  const q = query.toLowerCase();
  return q === '' ? vars : vars.filter((v) => tokenName(v.token).includes(q));
};

/** Replace the typed "/query" with the token. */
const insert = (value, start, caret, token) =>
  value.slice(0, start) + token + value.slice(caret);

const v = (token) => ({ token, description: 'what it resolves to' });
/** Comma-joined tokens of a match list, for terse assertions. */
const toks = (list) => list.map((m) => m.token).join();
const WRITER = ['{{ post_title }}', '{{ post_content }}', '{{ post_link }}', '{{ keyword }}', '{{ brand_context }}'].map(v);
const SEO = ['{{title}}', '{{primary_keyword}}', '{{business.name}}', '{{business.website|hostname}}'].map(v);

console.log('\n1. "/" opens the menu where a command would be expected');
for (const [text, why] of [
  ['/', 'at the very start'],
  ['Write a post.\n/', 'at the start of a line'],
  ['Write about /', 'after a space'],
  ['a\t/', 'after a tab'],
]) {
  const t = trigger(text, text.length);
  check(`opens ${why}`, t !== null && t.query === '', t);
}

console.log('\n2. "/" inside ordinary prose does NOT open it');
for (const [text, why] of [
  ['and/or', 'mid-word'],
  ['open 24/7', 'in a number'],
  ['https://example.com', 'in a URL'],
  ['a/b/c', 'in a path'],
]) {
  check(`stays closed: ${why} — "${text}"`, trigger(text, text.length) === null, trigger(text, text.length));
}

console.log('\n3. The query is what follows the slash, and dies at whitespace');
check('captures the typed query', trigger('Write /post', 11)?.query === 'post', trigger('Write /post', 11));
check('closes once a space is typed', trigger('Write /post ', 12) === null, trigger('Write /post ', 12));
check('closes on a newline', trigger('Write /post\n', 12) === null, trigger('Write /post\n', 12));
// The caret matters, not the end of the text: typing before existing content.
check('uses the caret, not the text end', trigger('Write /po rest of prompt', 9)?.query === 'po');

console.log('\n4. Filtering matches the variable NAME, not its punctuation');
check('empty query lists everything', filter(WRITER, '').length === WRITER.length);
check('"post" narrows to the post_* tokens', filter(WRITER, 'post').length === 3, filter(WRITER, 'post'));
check('braces/spaces are ignored when matching',
  toks(filter(WRITER, 'post_title')) === '{{ post_title }}', filter(WRITER, 'post_title'));
check('case-insensitive', filter(SEO, 'BUSINESS').length === 2, filter(SEO, 'BUSINESS'));
check('matches a dotted SEO name', toks(filter(SEO, 'business.name')) === '{{business.name}}');
check('matches a piped SEO name', toks(filter(SEO, 'hostname')) === '{{business.website|hostname}}');
check('no match yields nothing (menu stays shut)', filter(WRITER, 'zzz').length === 0);

console.log('\n5. Inserting replaces the typed "/query", not just appends');
{
  const v = 'Write about /post';
  const t = trigger(v, v.length);
  const out = insert(v, t.start, v.length, '{{ post_title }}');
  check('slash and query are consumed', out === 'Write about {{ post_title }}', out);
  check('no stray slash left', !out.includes('/'), out);
}
{
  // Caret mid-text: everything after it has to survive.
  const v = 'Start /po and then the rest';
  const caret = 9; // just after "po"
  const t = trigger(v, caret);
  const out = insert(v, t.start, caret, '{{ post_title }}');
  check('text after the caret is preserved', out === 'Start {{ post_title }} and then the rest', out);
}
{
  const v = '/';
  const t = trigger(v, 1);
  check('bare "/" inserts cleanly', insert(v, t.start, 1, '{{title}}') === '{{title}}');
}

console.log('\n6. The component still implements these rules');
const src = readFileSync(join(ROOT, 'app/src/modules/Templates/SlashVariableMenu.tsx'), 'utf8');
check('start-or-whitespace trigger present', src.includes("(slash === 0 || /\\s/.test(prev))"), 'rule missing');
check('whitespace ends the query', src.includes("!/\\s/.test(typed)"), 'rule missing');
check('insert splices at the slash index', src.includes('value.slice(0, start) + token + value.slice(caret)'), 'rule missing');
check('tokenName strips braces', src.includes("token.replace(/[{}]/g, '')"), 'rule missing');
// The transcription above filters {token, description} objects. When the vars
// became objects the real code changed to v.token while this file still filtered
// bare strings — it kept passing because it only ever tested itself. Pin the
// actual expression so that drift fails here instead of going unnoticed.
check('filters on v.token, matching the transcription',
  src.includes('tokenName(v.token).includes(q)'), 'filter shape drifted');
check('Enter inserts the token string, not the object',
  src.includes('.token)') && src.includes('matches[active] ?? matches[0]'), 'insert shape drifted');
// Escape must be swallowed, or dismissing the list would also cancel the edit
// and discard the author's work.
check('Escape is consumed by the menu', src.includes('e.stopPropagation()'), 'rule missing');
// Clicking an item must not blur the textarea before the caret is read.
check('mousedown is prevented on items', src.includes('onMouseDown={(e) => e.preventDefault()}'), 'rule missing');

console.log('\n7. The menu is anchored to the "/" and escapes the cell clip');
// The editor lives in `<TableCell class="max-w-0 overflow-hidden">`, so anything
// positioned inside it is clipped. Portal + fixed is what makes the menu visible
// at all; measuring the caret is what makes it land under the "/" and not under
// the whole textarea.
check('portalled out of the table cell', src.includes('createPortal(') && src.includes('document.body'), 'not portalled');
check('positioned fixed, not absolute inside the cell', src.includes("position: 'fixed'"), 'not fixed');
check('caret offset is measured', src.includes('caretOffset(ta, start)'), 'anchor not measured');
check('anchored to the slash index, not the textarea box',
  src.includes('rect.left + left - ta.scrollLeft') && src.includes('rect.top + top - ta.scrollTop'),
  'anchor math missing');
check('hangs below the caret line', src.includes('slashY + lineHeight'), 'vertical offset missing');
check('flips above when short of room', src.includes('slashY - height'), 'flip missing');
check('clamped inside the viewport',
  src.includes('window.innerWidth - width - VIEWPORT_PAD') && src.includes('window.innerHeight - VIEWPORT_PAD'),
  'clamping missing');
// A scrolled page moves the textarea but not a fixed menu, so it must re-measure.
check('re-measures on scroll (capture) and resize',
  src.includes("addEventListener('scroll', place, true)") && src.includes("addEventListener('resize', place)"),
  'listeners missing');
check('listeners are cleaned up',
  src.includes("removeEventListener('scroll', place, true)") && src.includes("removeEventListener('resize', place)"),
  'cleanup missing');
// Measuring in useLayoutEffect avoids a frame at the wrong coordinates.
check('measured before paint', src.includes('useLayoutEffect('), 'not pre-paint');
// The mirror div must be removed or every keystroke leaks a node into <body>.
check('mirror div is removed after measuring', src.includes('document.body.removeChild(mirror)'), 'mirror leaks');

console.log('\n8. Arrow keys keep the highlighted row visible');
// The list is capped (max-h-40) and scrolls. ↑/↓ only move an index, so without
// an explicit scroll the selection walks off the bottom and the menu looks stuck
// on the last visible row — the reported bug.
check('list has a ref to scroll', src.includes('listRef'), 'no list ref');
check('active row is scrolled into view', src.includes("scrollIntoView({ block: 'nearest' })"), 'no scrollIntoView');
check("uses 'nearest' so the page behind does not jump", !src.includes("block: 'center'") && !src.includes('scrollIntoView(true)'), 'wrong scroll mode');
check('re-runs when the active index changes', src.includes('[open, state.active]'), 'not keyed on active');
// Height-capped and scrollable. The axis is deliberately BOTH now: descriptions
// sit beside each token on one line, so a long one is read by scrolling sideways.
// Assert the cap + auto-overflow rather than one exact class string.
check('the list is height-capped and scrollable',
  /max-h-40 overflow-(auto|y-auto)/.test(src), 'list not scrollable');
check('horizontal scrolling is available for long descriptions',
  src.includes('overflow-auto') && src.includes('whitespace-nowrap'), 'no sideways reading');
check('vocabulary comes from the shared source',
  readFileSync(join(ROOT, 'app/src/modules/Templates/TemplateRow.tsx'), 'utf8').includes('templateVarsFor(template.module, subtype)'),
  'TemplateRow does not feed the shared list in');

console.log('\n' + '─'.repeat(52));
console.log(`  passed: ${pass}   failed: ${fail}`);
process.exit(fail > 0 ? 1 : 0);
