<?php
/**
 * "Fetch Brand" — niche / location / language.
 *
 * Guards the fix for "clicking Fetch Brand does not fill location, language or
 * niche". Name, summary, colours and images always worked because they come from
 * DOM parsing; those three came ONLY from the optional LLM enrichment, which
 * `scrape_and_prepare()` skipped whenever no model was passed — silently, so a
 * half-filled form looked like a broken fetch.
 *
 * Three things changed and are pinned here:
 *   1. LANGUAGE now needs no model at all. A page declares it in `<html lang>`
 *      (or `<meta property="og:locale">`), which is both free and more reliable
 *      than asking a model to guess it from the copy.
 *   2. A missing model falls back to Settings.defaultTextModel server-side, so
 *      the callers that never forwarded it (ContextPanel, the SEO business card)
 *      stop losing niche/location/phone.
 *   3. When enrichment genuinely cannot run, the response carries a NOTICE, so
 *      the UI can say "configure a model" instead of showing an unexplained
 *      half-filled form.
 *
 * Run: php tests/standalone/brand_fetch_fields_test.php
 */

error_reporting(E_ALL & ~E_DEPRECATED);
if (!defined('ABSPATH')) { define('ABSPATH', __DIR__ . '/'); }

$ROOT = dirname(__DIR__, 2);
require_once $ROOT . '/includes/core/class-pcm-website-scraper.php';

$PASS = 0; $FAIL = 0;
function check(string $name, $ok, $got = null): void {
    global $PASS, $FAIL;
    if ($ok) { $PASS++; echo "  ok  $name\n"; }
    else { $FAIL++; echo "FAIL  $name\n      got: " . var_export($got, true) . "\n"; }
}

/** Run the real private extractor over a snippet of HTML. */
function extract_page(string $html): array {
    $dom = new DOMDocument();
    @$dom->loadHTML($html, LIBXML_NOERROR | LIBXML_NOWARNING);
    $xpath = new DOMXPath($dom);
    $m = new ReflectionMethod('PCM_Website_Scraper', 'extract_text_data');
    $m->setAccessible(true);
    return $m->invoke(null, $dom, $xpath);
}

echo "\n1. Language comes straight off the page — no model needed\n";
$cases = array(
    '<html lang="sv"><head><title>T</title></head><body></body></html>'      => 'sv',
    '<html lang="sv-SE"><head><title>T</title></head><body></body></html>'   => 'sv',
    '<html lang="en_US"><head><title>T</title></head><body></body></html>'   => 'en',
    '<html lang="DE"><head><title>T</title></head><body></body></html>'      => 'de',
);
foreach ($cases as $html => $want) {
    $got = extract_page($html)['lang'];
    preg_match('/lang="([^"]*)"/', $html, $m);
    check("lang=\"{$m[1]}\" -> '$want'", $got === $want, $got);
}

echo "\n2. og:locale is the fallback when <html lang> is absent\n";
$got = extract_page('<html><head><meta property="og:locale" content="de_DE"><title>T</title></head></html>')['lang'];
check('og:locale de_DE -> de', $got === 'de', $got);
// <html lang> is the more specific declaration, so it must win.
$got = extract_page('<html lang="sv"><head><meta property="og:locale" content="de_DE"></head></html>')['lang'];
check('<html lang> beats og:locale', $got === 'sv', $got);

echo "\n3. Nothing usable yields an empty string, never junk\n";
foreach (array(
    '<html><head><title>T</title></head><body></body></html>'        => 'no declaration',
    '<html lang=""><head><title>T</title></head></html>'             => 'empty attribute',
    '<html lang="123"><head><title>T</title></head></html>'          => 'not a language code',
    '<html lang="x"><head><title>T</title></head></html>'            => 'single letter',
) as $html => $why) {
    $got = extract_page($html)['lang'];
    check("$why -> ''", $got === '', $got);
}

echo "\n4. The existing text extraction still works\n";
$r = extract_page('<html lang="sv"><head><title>Page Title</title>'
    . '<meta name="description" content="A summary."></head>'
    . '<body><h1>The Heading</h1></body></html>');
check('title still read', $r['title'] === 'Page Title', $r['title']);
check('description still read', $r['description'] === 'A summary.', $r['description']);
check('h1 still read', $r['h1'] === 'The Heading', $r['h1']);
check('lang added alongside, not instead', $r['lang'] === 'sv', $r['lang']);

echo "\n5. scrape() forwards the language to its caller\n";
// A key added to extract_text_data() but not to scrape()'s return array would be
// invisible to scrape_and_prepare(), which is where it is consumed.
$scraper_src = file_get_contents($ROOT . '/includes/core/class-pcm-website-scraper.php');
check("scrape() returns 'lang'", (bool) preg_match("/'lang'\s*=>\s*\\\$text_data\['lang'\]/", $scraper_src));

echo "\n6. scrape_and_prepare() uses it, and stops failing silently\n";
$svc = file_get_contents($ROOT . '/includes/modules/brands/service.php');
// The DOM seed is a MAP now — lang plus the markup-derived business fields —
// so assert the mapping, not one hand-written assignment line.
check('DOM values seed businessInfo',
    (bool) preg_match("/'lang'\s*=>\s*'language'/", $svc)
        && str_contains($svc, "\$result['businessInfo'][\$to] = \$scraped[\$from]"), 'not wired');
// Ordering matters: the model's answer should still win when there is one.
$dom_at = strpos($svc, "\$result['businessInfo'][\$to] = \$scraped[\$from]");
$llm_at = strpos($svc, "= \$llm_data['language']");
check('LLM language still overrides the DOM one', $dom_at !== false && $llm_at !== false && $dom_at < $llm_at,
    array('dom' => $dom_at, 'llm' => $llm_at));
check('missing model falls back to the configured default',
    str_contains($svc, "PCM_Settings::get('defaultTextModel')"), 'no fallback');
check('response reports whether enrichment ran', str_contains($svc, "\$result['enriched']"), 'no flag');
check('and explains itself when it could not', str_contains($svc, "enrichmentNotice"), 'no notice');

echo "\n7. The UI surfaces that notice\n";
$hook = file_get_contents($ROOT . '/app/src/modules/Brands/hooks/useBrandFetch.ts');
check('fetch hook reads enrichmentNotice', str_contains($hook, 'enrichmentNotice'), 'not read');
check('and shows it to the user', str_contains($hook, 'toast.warning(enrichmentNotice'), 'not shown');

echo "\n8. All three fields can still reach the form\n";
// A field the backend fills but the shared mapper does not know about would be
// dropped between the response and the form.
$types = file_get_contents($ROOT . '/app/shared/brandTypes.ts');
foreach (array('niche', 'location', 'language') as $f) {
    check("$f is mapped to a form key", (bool) preg_match("/\b$f:\s*\"[a-z_]+\"/", $types), $f);
}

echo "\n9. A provider fallback runs before the notice is shown\n";
// Warning the user to go and configure a default was the wrong default: any
// provider that already has a key can do this extraction.
check('service falls back to a connected provider',
    str_contains($svc, 'first_available_text_model('), 'no provider fallback');
check('notice now points at Providers, not the default-model setting',
    str_contains($svc, 'Settings') && str_contains($svc, 'Providers')
        && !str_contains($svc, 'Default Text Model'), 'stale wording');

echo "\n10. Fetch Brand does not hijack an EDIT with the logo picker\n";
// In edit mode "Fetch Brand" means refresh my fields. Jumping to the Select Logo
// step over an already-open Edit Brand dialog is what looked like a second popup
// appearing by itself a few seconds after the click.
$dlg = file_get_contents($ROOT . '/app/src/modules/Brands/BrandDialog.tsx');
check('edit mode returns to the form instead of the logo step',
    (bool) preg_match('/if \(editBrand\) \{\s*setCurrentStep\("form"\);\s*return;/', $dlg), 'no edit-mode guard');
check('the guard is keyed on editBrand', str_contains($dlg, '[fetchHook, editBrand]'), 'dep missing');
// Create mode must still walk the wizard.
check('create mode still opens the logo step', str_contains($dlg, 'setCurrentStep("logo")'), 'wizard broken');

echo "\n11. A failing model no longer destroys the whole fetch\n";
// The reported 404 ("models/gemini-2.5-flash is no longer available") surfaced as
// a hard error because the catch rethrew, discarding the DOM half that had ALREADY
// succeeded — name, summary, language, colours, images.
check('enrichment failure is caught, not rethrown',
    !str_contains($svc, "throw new \RuntimeException('LLM enrichment failed"), 'still rethrows');
check('the successful DOM result is kept',
    str_contains($svc, "\$result['enriched'] = false;")
        && str_contains($svc, "could not be detected: '"), 'no graceful degrade');
// The page fetch itself must still be able to fail loudly — that one is fatal.
check('a genuine page-fetch failure still throws',
    str_contains($svc, 'PCM_Website_Scraper::scrape($url)'), 'scrape call missing');

echo "\n12. The model is resolved from live rows, not a stale hardcoded list\n";
check('user model rows are consulted', str_contains($svc, 'PCM_DB::get_user_models'), 'not consulted');
check('only text-capable models qualify', str_contains($svc, 'canGenerateText'), 'capability ignored');
check('disabled / unavailable rows are skipped',
    str_contains($svc, 'isEnabled') && str_contains($svc, 'isAvailable'), 'live flags ignored');
check('the PCM user id reaches the service',
    str_contains(file_get_contents($ROOT . '/includes/modules/brands/controller.php'),
        'scrape_and_prepare($url, $model ?: null, (int) $user->id)'), 'user id not threaded');

echo "\n" . str_repeat('─', 52) . "\n";
echo "  passed: $PASS   failed: $FAIL\n";
exit($FAIL > 0 ? 1 : 0);
