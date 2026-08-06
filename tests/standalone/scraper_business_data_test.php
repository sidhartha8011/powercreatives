<?php
/**
 * Website scraper — niche / location / phone straight from the markup.
 *
 * Guards the fix for "the niche and location fields are still not filled up — it
 * can scrape the website and at least find these two, right?"
 *
 * They were right. Those three fields used to exist ONLY if an LLM returned them,
 * so a retired model, a provider quirk or a missing key left them blank on pages
 * that state their address in the footer and their trade in schema.org markup.
 * PCM_Website_Scraper::extract_business_data() now reads what the page already
 * publishes; the model still overrides anything it returns.
 *
 * Reflection is used to drive the real private method against real HTML, so this
 * tests the shipped parser rather than a description of it.
 *
 * Run: php tests/standalone/scraper_business_data_test.php
 */

error_reporting(E_ALL & ~E_DEPRECATED);
if (!defined('ABSPATH')) { define('ABSPATH', __DIR__ . '/'); }

// Minimal WP shims — the parser itself touches none of these, but the file's
// other methods reference them at load time.
if (!function_exists('wp_parse_url')) { function wp_parse_url($u, $c = -1) { return $c === -1 ? parse_url($u) : parse_url($u, $c); } }
if (!function_exists('wp_remote_get')) { function wp_remote_get($u, $a = array()) { return array(); } }
if (!function_exists('is_wp_error')) { function is_wp_error($t) { return false; } }
if (!function_exists('wp_remote_retrieve_body')) { function wp_remote_retrieve_body($r) { return ''; } }
if (!function_exists('wp_remote_retrieve_response_code')) { function wp_remote_retrieve_response_code($r) { return 200; } }
if (!function_exists('esc_url_raw')) { function esc_url_raw($u) { return $u; } }
if (!function_exists('sanitize_text_field')) { function sanitize_text_field($s) { return trim(strip_tags((string) $s)); } }
if (!function_exists('wp_strip_all_tags')) { function wp_strip_all_tags($s) { return strip_tags((string) $s); } }
if (!function_exists('__')) { function __($s, $d = null) { return $s; } }
if (!function_exists('add_action')) { function add_action(...$a) {} }
if (!function_exists('add_filter')) { function add_filter(...$a) {} }

require_once dirname(__DIR__, 2) . '/includes/core/class-pcm-website-scraper.php';

$PASS = 0; $FAIL = 0;
function check(string $name, $ok, $got = null): void {
    global $PASS, $FAIL;
    if ($ok) { $PASS++; echo "  ok  $name\n"; }
    else { $FAIL++; echo "FAIL  $name\n      got: " . var_export($got, true) . "\n"; }
}

$m = new ReflectionMethod('PCM_Website_Scraper', 'extract_business_data');
$m->setAccessible(true);

/** Parse HTML the way scrape() does and run the real extractor over it. */
function parse(string $html): array {
    global $m;
    libxml_use_internal_errors(true);
    $dom = new DOMDocument();
    $dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOWARNING | LIBXML_NOERROR);
    libxml_clear_errors();
    return $m->invoke(null, $dom, new DOMXPath($dom));
}

echo "\n1. JSON-LD is the richest source, so it is read first\n";
$r = parse('<html><head><script type="application/ld+json">' . json_encode(array(
    '@context' => 'https://schema.org',
    '@type' => 'Dentist',
    'name' => 'Bright Smiles',
    'telephone' => '+46 8 123 456',
    'address' => array(
        '@type' => 'PostalAddress',
        'streetAddress' => 'Kungsgatan 12',
        'addressLocality' => 'Stockholm',
        'postalCode' => '111 43',
        'addressCountry' => 'SE',
    ),
)) . '</script></head><body></body></html>');
check('niche from @type', $r['niche'] === 'Dentist', $r['niche']);
check('address assembled in order',
    $r['location'] === 'Kungsgatan 12, Stockholm, 111 43, SE', $r['location']);
check('telephone read', $r['phone'] === '+46 8 123 456', $r['phone']);

echo "\n2. A CamelCase schema type is made readable\n";
$r = parse('<script type="application/ld+json">{"@type":"MovieTheater","address":"1 Main St"}</script>');
check('MovieTheater -> "Movie Theater"', $r['niche'] === 'Movie Theater', $r['niche']);
check('a plain string address is accepted', $r['location'] === '1 Main St', $r['location']);

echo "\n3. Generic types are NOT written into the Niche box\n";
// "Organization" / "WebSite" say nothing about the trade — offering them would
// fill the field with a word the user then has to delete.
foreach (array('Organization', 'WebSite', 'WebPage', 'Person', 'LocalBusiness') as $t) {
    $r = parse('<script type="application/ld+json">{"@type":"' . $t . '"}</script>');
    check("$t is rejected as a niche", $r['niche'] === '', $r['niche']);
}
// …but a specific type inside the same list still wins.
$r = parse('<script type="application/ld+json">{"@type":["Organization","AccountingService"]}</script>');
check('a specific type alongside a generic one is used', $r['niche'] === 'Accounting Service', $r['niche']);

echo "\n4. @graph and nested entities are walked\n";
$r = parse('<script type="application/ld+json">' . json_encode(array(
    '@context' => 'https://schema.org',
    '@graph' => array(
        array('@type' => 'WebSite', 'name' => 'Site'),
        array('@type' => 'Bakery', 'address' => array('addressLocality' => 'Malmö', 'addressCountry' => 'Sweden')),
    ),
)) . '</script>');
check('entity found inside @graph', $r['niche'] === 'Bakery', $r['niche']);
check('its address is used', $r['location'] === 'Malmö, Sweden', $r['location']);

echo "\n5. Meta tags when there is no JSON-LD\n";
$r = parse('<html><head>'
    . '<meta property="business:contact_data:locality" content="Gothenburg">'
    . '<meta property="business:contact_data:country_name" content="Sweden">'
    . '<meta property="business:contact_data:phone_number" content="+46 31 000 000">'
    . '</head></html>');
check('locality + country combined', $r['location'] === 'Gothenburg, Sweden', $r['location']);
check('phone from meta', $r['phone'] === '+46 31 000 000', $r['phone']);

echo "\n6. <address> and tel: links as the final fallback\n";
$r = parse('<html><body><address>  Storgatan 5
   903 26 Umeå  </address><a href="tel:+46%2090%20123">Call</a></body></html>');
check('address whitespace collapsed to one line',
    $r['location'] === 'Storgatan 5 903 26 Umeå', $r['location']);
check('tel: link decoded', $r['phone'] === '+46 90 123', $r['phone']);

echo "\n7. Keywords only as a last-resort niche\n";
$r = parse('<meta name="keywords" content="film production company, video, uk">');
check('a multi-word first keyword is used', $r['niche'] === 'film production company', $r['niche']);
// A single proper noun is almost always the brand name, not a category.
$r = parse('<meta name="keywords" content="Acme, video, uk">');
check('a single-word first keyword is rejected', $r['niche'] === '', $r['niche']);

echo "\n7b. The <title> as a niche source, for sites with no structured data\n";
// This is the real markup shape of birthgiverfilmproductions.com: no JSON-LD, no
// meta description, no keywords — an <address>, a tel: link and a descriptive
// title. Before this the Niche box stayed permanently empty for such sites.
$real = '<html lang="en"><head><title>Film Production &amp; Video Production UK | Creative Film Agency</title></head>'
      . '<body><h1>BirthGiver Film Productions</h1>'
      . '<footer><address>Seymour Road London, UK' . "\n      " . 'N8 0BH</address>'
      . '<a href="tel:+447776842718">+44 7776 842718</a></footer></body></html>';
$r = parse($real);
check('niche from the trailing title descriptor', $r['niche'] === 'Creative Film Agency', $r['niche']);
check('location from the footer <address>', $r['location'] === 'Seymour Road London, UK N8 0BH', $r['location']);
check('phone from the tel: link', $r['phone'] === '+447776842718', $r['phone']);

// The half that repeats the brand describes WHO, not WHAT.
$r = parse('<title>Acme Dental | Best dentist in town</title><h1>Acme Dental</h1>');
check('the brand half is skipped', $r['niche'] !== 'Acme Dental', $r['niche']);
// A tagline is not a category.
$r = parse('<title>Acme | We build the future, together.</title><h1>Acme</h1>');
check('a punctuated tagline is rejected', $r['niche'] === '', $r['niche']);
// Length bounds keep out single nouns and whole sentences.
$r = parse('<title>Acme | Bakery</title><h1>Acme</h1>');
check('a one-word segment is rejected', $r['niche'] === '', $r['niche']);
$r = parse('<title>Acme | the very best artisanal sourdough bakery in all of greater london</title><h1>Acme</h1>');
check('an over-long segment is rejected', $r['niche'] === '', $r['niche']);
// Separator variety: en dash, em dash, hyphen-with-spaces.
foreach (array('–', '—', ' - ') as $sep) {
    $r = parse('<title>Acme' . $sep . 'Dental Clinic</title><h1>Acme</h1>');
    check("separator '" . trim($sep) . "' splits the title", $r['niche'] === 'Dental Clinic', $r['niche']);
}
// Structured data still outranks the title.
$r = parse('<title>Acme | Dental Clinic</title><script type="application/ld+json">{"@type":"Bakery"}</script>');
check('JSON-LD still beats the title', $r['niche'] === 'Bakery', $r['niche']);

echo "\n8. Degenerate input never reaches the form\n";
$r = parse('<html><body><p>nothing here</p></body></html>');
check('empty page yields empty strings',
    $r === array('niche' => '', 'location' => '', 'phone' => ''), $r);
$r = parse('<script type="application/ld+json">{ this is not json </script>');
check('malformed JSON-LD is skipped, not fatal',
    $r['niche'] === '' && $r['location'] === '', $r);
$r = parse('<address>' . str_repeat('x', 500) . '</address>');
check('a runaway address is clipped', strlen($r['location']) === 200, strlen($r['location']));

echo "\n9. scrape() actually returns them\n";
$src = file_get_contents(dirname(__DIR__, 2) . '/includes/core/class-pcm-website-scraper.php');
foreach (array('niche', 'location', 'phone') as $f) {
    check("scrape() returns '$f'",
        (bool) preg_match("/'" . $f . "'\s*=>\s*\\\$business\['" . $f . "'\]/", $src), 'not returned');
}

echo "\n10. The service seeds them, and only warns about what is STILL missing\n";
$svc = file_get_contents(dirname(__DIR__, 2) . '/includes/modules/brands/service.php');
check('markup fields are mapped into businessInfo',
    str_contains($svc, "'niche' => 'niche'") && str_contains($svc, "'location' => 'location'"), 'not seeded');
check('the notice lists only the empty fields',
    str_contains($svc, "implode(', ', \$gaps)"), 'still a fixed sentence');
check('no notice at all when nothing is missing',
    str_contains($svc, 'if (!empty($gaps))'), 'warns unconditionally');

echo "\n" . str_repeat('─', 52) . "\n";
echo "  passed: $PASS   failed: $FAIL\n";
exit($FAIL > 0 ? 1 : 0);
