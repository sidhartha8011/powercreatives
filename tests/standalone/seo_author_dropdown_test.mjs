/**
 * SEO table — Author dropdown + "New author" on CONNECTED sites.
 *
 * Owner 2026-08-12, with a screenshot of a client site whose Author column was
 * blank: "There should be a drop down for selecting the author from the
 * WordPress authors' existing users, and there should also be a plus button to
 * create the new author."
 *
 * Both already existed — for LOCAL rows only. The cell bailed to read-only text
 * on `!isLocal`, deliberately: `options.authors` is get_users() on the HUB, and
 * hub user ids mean nothing on a client's install. Writing id 5 from here would
 * hand the client's post to whoever is id 5 over there.
 *
 * So the fix is not "enable the control" — it is "feed it the RIGHT list".
 * These checks exist mostly to stop the id-space hazard from being reintroduced
 * by someone who sees the gate and simply deletes it.
 *
 * Run: node tests/standalone/seo_author_dropdown_test.mjs
 */

import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..', '..');
const idx = readFileSync(join(ROOT, 'app/src/modules/SEO/index.tsx'), 'utf8');
const routes = readFileSync(join(ROOT, 'app/src/lib/trpc-routes.ts'), 'utf8');
const svc = readFileSync(join(ROOT, 'includes/modules/seo/service.php'), 'utf8');
const ctl = readFileSync(join(ROOT, 'includes/modules/seo/controller.php'), 'utf8');

let pass = 0, fail = 0;
const check = (name, ok, got) => {
  if (ok) { pass++; console.log(`  ok  ${name}`); }
  else { fail++; console.log(`FAIL  ${name}\n      got: ${JSON.stringify(got)}`); }
};

// The author cell, isolated — assertions must be about THIS cell, not the file.
const cellStart = idx.indexOf("case 'author': {");
const cellEnd = idx.indexOf("case 'slug': {", cellStart);
check('the author cell is locatable', cellStart !== -1 && cellEnd > cellStart, { cellStart, cellEnd });
const cell = idx.slice(cellStart, cellEnd);

console.log('\n1. The dropdown renders for a CONNECTED site, not just local');
check('the cell no longer bails out on !isLocal', !/if \(!isLocal \|\|/.test(cell), 'still local-only');
check('it renders a Select', cell.includes('<Select'), 'no dropdown');
check('options come from the scope-aware list', cell.includes('authorOptions'), 'still reads options.authors directly');
check('it still degrades to read-only text when there is no list',
  /if \(authors\.length === 0\)[\s\S]{0,220}?TableCell/.test(cell), 'would render an empty dropdown');
check('choosing an author saves the cell', /saveCell\(row\.id, 'author', v\)/.test(cell), 'not wired');

console.log('\n2. The plus button to create a new author');
check('the cell has a "New author" action', /New author/.test(cell), 'no create affordance');
check('it uses a Plus icon', /<Plus /.test(cell), 'no plus button');
check('opening it does not commit a bogus selection',
  /e\.preventDefault\(\); e\.stopPropagation\(\); setNewAuthorFor\(row\.id\)/.test(cell), 'would select the button itself');

console.log('\n3. THE HAZARD: a remote row must never be offered HUB users');
const optDef = idx.slice(idx.indexOf('const authorOptions'), idx.indexOf('const authorOptions') + 400);
check('authorOptions is scope-aware (isLocal chooses the source)',
  /isLocal \?\s*\(options\?\.authors[\s\S]{0,60}?:\s*remoteAuthors/.test(optDef), optDef.slice(0, 200));
check('remote authors are fetched from the SITE, not the hub',
  /trpc\.seo\.remoteAuthors\.useQuery\(\s*\{ siteId \}/.test(idx), 'no remote fetch');
// …and that the fetch is actually CONSUMED. Asserting only that the call exists
// passes even when its result is discarded, which is a live query and a dead list.
check('the fetched list is what remoteAuthors resolves to',
  /const remoteAuthors[^=]*=\s*\n?\s*Array\.isArray\(remoteAuthorsQuery\.data\) \? remoteAuthorsQuery\.data : \[\]/.test(idx),
  'query result is not used');
check('that query only runs for a connected site',
  /enabled: !isLocal && typeof siteId === 'number'/.test(idx), 'would fire for local too');
// Session-created authors are per-site; leaking them across a switch is the same id hazard.
check('session-created authors reset when the site changes',
  /useEffect\(\(\) => \{ setExtraAuthors\(\[\]\); \}, \[siteId\]\)/.test(idx), 'stale author leaks to the next site');

console.log('\n4. Creating an author happens WHERE the post lives');
const submit = idx.slice(idx.indexOf('const submitNewAuthor'), idx.indexOf('const creatingAuthor') > -1
  ? idx.indexOf('const submitNewAuthor') + 1600 : idx.indexOf('const submitNewAuthor') + 1600);
check('local rows create on the hub', /if \(isLocal\) \{[\s\S]{0,160}?createAuthorMutation\.mutate/.test(submit), 'local path lost');
check('remote rows create on the connected site',
  /createRemoteAuthorMutation\.mutate\(\{ siteId, name, email \}/.test(submit), 'creates on the hub instead');
check('both paths assign the returned id to the row', /saveCell\(rowId, 'author', String\(a\.id\)\)/.test(submit), 'not assigned');
check('the dialog says WHICH site it creates on',
  /isLocal \? 'this site' : \(activeSite\?\.name/.test(idx), 'ambiguous on a client install');
check('the submit button reflects EITHER mutation being in flight',
  /const creatingAuthor = createAuthorMutation\.isPending \|\| createRemoteAuthorMutation\.isPending/.test(idx),
  'remote create would be double-submittable with no spinner');

console.log('\n5. The hub can list and create authors on a connected site');
check('service exposes remote_authors', /function remote_authors\(object \$site\)/.test(svc), 'missing');
check('it asks the SITE for its authors', /'\/wp\/v2\/users'[\s\S]{0,200}?'who'\s*=>\s*'authors'/.test(svc), 'wrong source');
check('service exposes remote_create_author', /function remote_create_author\(object \$site/.test(svc), 'missing');
check('the created role is HARDCODED to author', /'roles'\s*=>\s*array\('author'\)/.test(svc), 'role from input = privilege escalation');
check('the password is GENERATED, never accepted', /'password'\s*=>\s*wp_generate_password\(/.test(svc), 'password from input');
check('a username clash retries instead of failing', /existing_user_login/.test(svc), 'first collision is fatal');
check('both routes are registered', /\/seo\/sites\/\(\?P<id>\\d\+\)\/authors', 'remote_authors'/.test(ctl)
  && /\/seo\/sites\/\(\?P<id>\\d\+\)\/authors', 'remote_create_author'/.test(ctl), 'not routed');
check('both require manage_options (creating users on a client install)',
  (ctl.match(/\/seo\/sites\/\(\?P<id>\\d\+\)\/authors'[^\n]*manage_options/g) ?? []).length === 2, 'under-gated');
check('the site is ownership-scoped before use',
  /function remote_create_author\(WP_REST_Request[\s\S]{0,320}?PCM_DB::get_site\(absint\(\$request->get_param\('id'\)\), \(int\) \$user->id\)/.test(ctl),
  'any site id would do');

console.log('\n6. Saving the author actually reaches the remote post');
// Slice the function rather than guessing a character distance — a fixed window
// between the condition and the assignment fails on comment length, not on the
// code being wrong (it did exactly that on the first run).
const saveFn = svc.slice(svc.indexOf('function remote_save_cell('), svc.indexOf('function remote_authors('));
check('remote_save_cell is locatable', saveFn.length > 200, saveFn.length);
check('author is a NATIVE remote field, not meta',
  /\$field === 'author'\)/.test(saveFn) && /\$payload\['author'\] = absint\(\$value\)/.test(saveFn),
  'would 400 as an unknown meta key');
check('it skips the meta-verification round trip',
  /array\('title', 'slug', 'status', 'author'\)/.test(svc), 'would report a phantom save');
check('the tRPC routes exist', routes.includes('"seo.remoteAuthors"') && routes.includes('"seo.remoteCreateAuthor"'), 'not proxied');
// BOTH remote routes must be site-scoped. A single-match test passes while one
// of the two has been pointed back at the hub-wide /seo/authors.
check('both remote author routes target the site path',
  (routes.match(/url: `seo\/sites\/\$\{input\.siteId\}\/authors`/g) ?? []).length === 2,
  (routes.match(/url: `seo\/sites\/\$\{input\.siteId\}\/authors`/g) ?? []).length);

console.log('\n' + '-'.repeat(60));
console.log(`  passed: ${pass}   failed: ${fail}`);
process.exit(fail > 0 ? 1 : 0);
