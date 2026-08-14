<?php
/**
 * Remote author save — "when i select the author it doesn't select".
 *
 * A capability failure 403s and was already surfaced. The invisible case:
 * WordPress core REST silently IGNORES the `author` param when the post
 * type doesn't expose author support — 200 OK, author unchanged — and the
 * hub reported success while the table reverted on the next refetch, which
 * reads as "the pick didn't select" (knallenstandvard.se).
 *
 * remote_save_cell is EXECUTED here with scripted HTTP: the updated-post
 * response body is the verification (no extra round trip) — author echoed
 * back = saved; different or ABSENT = refused/unsupported → honest 422.
 *
 * Run: php tests/standalone/seo_author_save_test.php
 */

error_reporting(E_ALL & ~E_DEPRECATED);
if (!defined('ABSPATH')) { define('ABSPATH', __DIR__ . '/'); }
$ROOT = dirname(__DIR__, 2);

$PASS = 0; $FAIL = 0;
function check(string $name, $ok, $got = null): void {
    global $PASS, $FAIL;
    if ($ok) { $PASS++; echo "  ok  $name\n"; }
    else { $FAIL++; echo "FAIL  $name\n      got: " . var_export($got, true) . "\n"; }
}

if (!class_exists('WP_Error')) {
    class WP_Error {
        public $code; public $message; public $data;
        public function __construct($c = '', $m = '', $d = null) { $this->code = $c; $this->message = $m; $this->data = $d; }
        public function get_error_message() { return $this->message; }
    }
}
function is_wp_error($x) { return $x instanceof WP_Error; }
function __($s, $d = null) { return $s; }
function absint($v) { return abs((int) $v); }
function sanitize_title($s) { return trim(preg_replace('/[^a-z0-9]+/', '-', strtolower((string) $s)), '-'); }

$svc = file_get_contents($ROOT . '/includes/modules/seo/service.php');
$i = strpos($svc, 'public static function remote_save_cell');
$start = strrpos(substr($svc, 0, (int)$i), "\n") + 1;
$j = strpos($svc, "\n    /**", (int)$i);
$fn = substr($svc, (int)$start, (int)$j - (int)$start);
if (preg_match('/\n    \}\r?\n/', $fn, $m, PREG_OFFSET_CAPTURE)) { $fn = substr($fn, 0, $m[0][1]) . "\n    }"; }
$fn = str_replace(
    array('self::ensure_sites_service();', 'PCM_Sites_Service::remote_rest', 'self::remote_route($site, $type, (int) $post_id)', 'self::remote_meta_keys($field)'),
    array('', 'self::remote_rest', "'/wp/v2/posts/' . (int) \$post_id", 'self::meta_keys($field)'),
    $fn
);
eval('class SaveHost {
    public static $script = array();   // successive responses
    public static $calls = array();
    public static function remote_rest($site, $method, $route, $q = array(), $b = null, $t = 30) {
        self::$calls[] = array($method, $route, $b);
        return array_shift(self::$script);
    }
    public static function meta_keys($f) { return $f === "metaTitle" ? array("pcm_seo_meta_title") : array(); }
    ' . $fn . ' }');

$site = (object) array('id' => 7, 'url' => 'https://knallenstandvard.se');
// $extra FIRST: PHP's + union keeps the left operand's keys, so overrides win.
$post_body = static fn(array $extra = array()) => array('status' => 200, 'body' => $extra + array('id' => 55, 'slug' => 's'));

echo "\n1. The reported bug — a silently IGNORED author write is refused honestly\n";
SaveHost::$script = array($post_body());   // 200, but NO author key in the schema
$r = SaveHost::remote_save_cell($site, 55, 'post', 'author', '3');
check('author absent from the response → 422, not fake success',
    $r instanceof WP_Error && $r->code === 'pcm_seo_author_not_saved', $r);
check('…the message names the likely cause (role / capability)',
    $r instanceof WP_Error && strpos($r->message, 'edit others') !== false, $r->message ?? '');

SaveHost::$script = array($post_body(array('author' => 1)));   // kept the OLD author
$r = SaveHost::remote_save_cell($site, 55, 'post', 'author', '3');
check('author echoed back DIFFERENT → 422 (write refused)',
    $r instanceof WP_Error && $r->code === 'pcm_seo_author_not_saved', $r);

echo "\n2. A real save still succeeds — and costs ONE request\n";
SaveHost::$script = array($post_body(array('author' => 3)));
SaveHost::$calls = array();
$r = SaveHost::remote_save_cell($site, 55, 'post', 'author', '3');
check('author echoed back EQUAL → success', is_array($r) && $r['value'] === '3', $r);
check('no extra verification round trip (the POST body IS the proof)', count(SaveHost::$calls) === 1, SaveHost::$calls);
check('the payload sent the id as an int', (SaveHost::$calls[0][2]['author'] ?? null) === 3, SaveHost::$calls[0][2]);

echo "\n3. The neighbours are untouched\n";
SaveHost::$script = array(array('status' => 403, 'body' => array('message' => 'Sorry, you are not allowed to edit this post.')));
$r = SaveHost::remote_save_cell($site, 55, 'post', 'author', '3');
check('a cap failure (403) still surfaces the site\'s own message',
    $r instanceof WP_Error && strpos($r->message, 'not allowed') !== false, $r);
SaveHost::$script = array($post_body(array('title' => array('raw' => 'X'))));
$r = SaveHost::remote_save_cell($site, 55, 'post', 'title', 'X');
check('title saves stay verification-free (native, always stored)', is_array($r) && $r['value'] === 'X', $r);
SaveHost::$script = array(
    $post_body(),                                                    // save answer
    array('status' => 200, 'body' => array('meta' => array())),      // verify: key absent
);
$r = SaveHost::remote_save_cell($site, 55, 'post', 'metaTitle', 'T');
check('the meta phantom-save law is untouched (still verifies + 422s)',
    $r instanceof WP_Error && $r->code === 'pcm_seo_remote_meta_unsupported', $r);
SaveHost::$script = array($post_body(array('slug' => 'x-2', 'author' => 9)));
$r = SaveHost::remote_save_cell($site, 55, 'post', 'slug', 'x');
check('slug keeps reflecting the server-deduped value', is_array($r) && $r['value'] === 'x-2', $r);

echo "\n" . str_repeat('-', 60) . "\n";
echo "  passed: {$PASS}   failed: {$FAIL}\n";
exit($FAIL > 0 ? 1 : 0);
