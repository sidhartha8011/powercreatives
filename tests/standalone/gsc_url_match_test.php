<?php
/**
 * GSC URL matching — the "draft posts have visits" bug.
 *
 * Both normalizers (hub PCM_GSC::norm_url and the SEO module's normGscUrl)
 * used to keep only host+path. A draft's permalink is a PREVIEW link
 * (`https://site/?page_id=9`) whose path is `/`, so every draft collapsed to
 * the homepage's key and wore the homepage's clicks/impressions — while real
 * Search Console (correctly) showed nothing for those rows. The query string
 * is now part of the key on BOTH sides.
 *
 * The PHP normalizer is EXECUTED here (sliced from the real file); the
 * frontend normalizer is EXTRACTED from index.tsx and EXECUTED under node,
 * and the two must agree byte-for-byte on every vector (drift in either
 * direction silently breaks row matching).
 *
 * Run: php tests/standalone/gsc_url_match_test.php
 */

error_reporting(E_ALL & ~E_DEPRECATED);
$ROOT = dirname(__DIR__, 2);

$PASS = 0; $FAIL = 0;
function check(string $name, $ok, $got = null): void {
    global $PASS, $FAIL;
    if ($ok) { $PASS++; echo "  ok  $name\n"; }
    else { $FAIL++; echo "FAIL  $name\n      got: " . var_export($got, true) . "\n"; }
}

// ── Host the real PHP normalizer ─────────────────────────────────────────────
$gsc = file_get_contents($ROOT . '/includes/core/class-pcm-gsc.php');
$i = strpos($gsc, 'public static function norm_url');
check('norm_url found in class-pcm-gsc.php', $i !== false);
$start = strrpos(substr($gsc, 0, (int)$i), "\n") + 1;
$next = strpos($gsc, 'public static function', $i + 10);
$fn = substr($gsc, $start, $next - $start);
if (preg_match('/\n    \}\r?\n/', $fn, $m, PREG_OFFSET_CAPTURE)) { $fn = substr($fn, 0, $m[0][1]) . "\n    }"; }
if (!function_exists('wp_parse_url')) {
    function wp_parse_url($url, $component = -1) { return parse_url($url, $component); }
}
eval('class GscHost { ' . $fn . ' }');

// ── Extract + execute the real frontend normalizer under node ────────────────
$ui = file_get_contents($ROOT . '/app/src/modules/SEO/index.tsx');
$a = strpos($ui, 'const normGscUrl = useCallback(');
check('normGscUrl found in index.tsx', $a !== false);
$b = strpos($ui, '}, []);', (int)$a);
$js_fn = substr($ui, (int)$a + strlen('const normGscUrl = useCallback('), (int)$b - (int)$a - strlen('const normGscUrl = useCallback(') + 1);
$js_fn = str_replace(': string', '', $js_fn);

$vectors = array(
    'https://massagegoteborg.nu/',                                    // 0 homepage
    'https://www.Massagegoteborg.nu',                                 // 1 homepage, www + case + no slash
    'https://massagegoteborg.nu/?page_id=9',                          // 2 DRAFT page preview link
    'https://massagegoteborg.nu/?p=12',                               // 3 DRAFT post preview link
    'https://massagegoteborg.nu/services/lymphatic-drainage/',        // 4 published pretty permalink
    'https://massagegoteborg.nu/services/lymphatic-drainage/?utm_source=x', // 5 tagged variant
    'https://massagegoteborg.nu/tandv%C3%A5rd/',                      // 6 encoded Swedish slug (GSC form)
    'https://massagegoteborg.nu/tandvård',                            // 7 raw Swedish slug (permalink form)
    'https://massagegoteborg.nu/?Page_ID=9',                          // 8 query case-insensitivity
    'https://massagegoteborg.nu/?s=tandv%C3%A5rd',                    // 9 encoded query value
);

$php_keys = array_map(static fn($u) => GscHost::norm_url($u), $vectors);

$tmp = tempnam(sys_get_temp_dir(), 'gsc') . '.mjs';
file_put_contents($tmp,
    'const normGscUrl = ' . $js_fn . ";\n"
    . 'const vectors = ' . json_encode($vectors) . ";\n"
    . 'process.stdout.write(JSON.stringify(vectors.map(normGscUrl)));' . "\n");
$out = shell_exec('node ' . escapeshellarg($tmp) . ' 2>&1');
@unlink($tmp);
$js_keys = json_decode((string)$out, true);

echo "\n1. The bug — drafts must NOT wear the homepage's stats\n";
check('homepage key is the bare host', $php_keys[0] === 'massagegoteborg.nu', $php_keys[0]);
check('www/case/slash variants still collapse to the homepage', $php_keys[1] === $php_keys[0], $php_keys[1]);
check('a draft PAGE preview link is NOT the homepage key', $php_keys[2] !== $php_keys[0], $php_keys[2]);
check('a draft POST preview link is NOT the homepage key', $php_keys[3] !== $php_keys[0], $php_keys[3]);
check('two different drafts do not collide either', $php_keys[2] !== $php_keys[3]);
check('the query survives in the key (lookups can only miss)', $php_keys[2] === 'massagegoteborg.nu?page_id=9', $php_keys[2]);

echo "\n2. Published matching must be unharmed\n";
check('pretty permalink normalizes to host+path', $php_keys[4] === 'massagegoteborg.nu/services/lymphatic-drainage', $php_keys[4]);
check('a utm-tagged GSC row no longer OVERWRITES the clean page entry', $php_keys[5] !== $php_keys[4], $php_keys[5]);
check('encoded and raw Swedish slugs still meet at one key', $php_keys[6] === $php_keys[7], array($php_keys[6], $php_keys[7]));
check('query is lowercased like the rest of the key', $php_keys[8] === $php_keys[2], $php_keys[8]);

echo "\n3. Frontend parity — normGscUrl EXECUTED, must mirror the hub exactly\n";
check('node executed the extracted frontend normalizer', is_array($js_keys) && count($js_keys) === count($vectors), $out);
if (is_array($js_keys)) {
    foreach ($vectors as $k => $v) {
        check('parity: ' . $v, ($js_keys[$k] ?? null) === $php_keys[$k],
            array('php' => $php_keys[$k], 'js' => $js_keys[$k] ?? null));
    }
}

echo "\n4. Every GSC cell reads through the one normalizer\n";
check('all stat reads go through normGscUrl (no stray raw-permalink lookups)',
    substr_count($ui, 'gscPages[normGscUrl(') >= 6, substr_count($ui, 'gscPages[normGscUrl('));

echo "\n" . str_repeat('-', 60) . "\n";
echo "  passed: {$PASS}   failed: {$FAIL}\n";
exit($FAIL > 0 ? 1 : 0);
