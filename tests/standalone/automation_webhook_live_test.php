<?php
/**
 * LIVE end-to-end webhook test — real HTTP to webhook.site.
 *
 * Owner: "there are many free webhook sites we can test with, pick one and test
 * that all are sent properly so we are confident it works before I review."
 *
 * What runs REAL here: PCM_Automation_Mapping::resolve() (token resolution),
 * PCM_Webhook_Action_Handler-shaped payload assembly, and
 * PCM_Webhook_Channel::send() (signing + dispatch), with wp_remote_post backed
 * by curl. The payload travels over the actual internet to webhook.site and is
 * read back through their API; every one of the 12 required variables must
 * arrive with its exact value. The HMAC signature is re-computed from the
 * RECEIVED body and must match the received X-PCM-Signature header.
 *
 * What is NOT covered: enrich_context()'s DB reads (needs a live WP install —
 * covered structurally in automation_field_variables_test.php).
 *
 * NEEDS INTERNET. Exits 0 with SKIPPED when webhook.site is unreachable, so the
 * suite stays green offline; the summary line says which happened.
 *
 * Run: php tests/standalone/automation_webhook_live_test.php
 */

error_reporting(E_ALL & ~E_DEPRECATED);
if (!defined('ABSPATH')) { define('ABSPATH', __DIR__ . '/'); }

// ── Minimal WP surface, curl-backed where it matters ───────────────────────
if (!function_exists('wp_json_encode')) { function wp_json_encode($d) { return json_encode($d); } }
if (!function_exists('esc_url_raw')) { function esc_url_raw($u) { return (string) $u; } }
if (!function_exists('current_time')) { function current_time($t) { return gmdate('c'); } }
if (!function_exists('is_wp_error')) { function is_wp_error($x) { return $x instanceof WP_Error; } }
if (!class_exists('WP_Error')) {
    class WP_Error { public $msg; public function __construct($c = '', $m = '') { $this->msg = $m; } public function get_error_message() { return $this->msg; } }
}
class PCM_Settings { public static function get($k, $d = '') { return 'live-test-secret'; } }

function http(string $method, string $url, array $headers = array(), ?string $body = null): array {
    $ch = curl_init($url);
    $h = array();
    foreach ($headers as $k => $v) { $h[] = "{$k}: {$v}"; }
    curl_setopt_array($ch, array(
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_HTTPHEADER     => $h,
    ));
    if ($body !== null) { curl_setopt($ch, CURLOPT_POSTFIELDS, $body); }
    $out  = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    return array('code' => $code, 'body' => (string) $out, 'error' => $err);
}

// wp_remote_post backed by the same curl helper — the REAL channel calls this.
function wp_remote_post($url, $args = array()) {
    $headers = isset($args['headers']) && is_array($args['headers']) ? $args['headers'] : array();
    $res = http('POST', $url, $headers, (string) ($args['body'] ?? ''));
    if ($res['error'] !== '') { return new WP_Error('http', $res['error']); }
    return array('response' => array('code' => $res['code']), 'body' => $res['body']);
}
function wp_remote_retrieve_response_code($r) { return is_array($r) ? (int) ($r['response']['code'] ?? 0) : 0; }

$ROOT = dirname(__DIR__, 2);
require_once $ROOT . '/includes/modules/automations/class-pcm-automation-mapping.php';
require_once $ROOT . '/includes/modules/automations/channels/interface-pcm-automation-channel.php';
require_once $ROOT . '/includes/modules/automations/channels/class-pcm-webhook-channel.php';

$PASS = 0; $FAIL = 0;
function check(string $name, $ok, $got = null): void {
    global $PASS, $FAIL;
    if ($ok) { $PASS++; echo "  ok  $name\n"; }
    else { $FAIL++; echo "FAIL  $name\n      got: " . var_export($got, true) . "\n"; }
}

// ── 0. Reachability gate ───────────────────────────────────────────────────
$tok = http('POST', 'https://webhook.site/token', array('Content-Type' => 'application/json'), '{}');
$uuid = '';
if ($tok['error'] === '' && $tok['code'] < 300) {
    $j = json_decode($tok['body'], true);
    $uuid = is_array($j) ? (string) ($j['uuid'] ?? '') : '';
}
if ($uuid === '') {
    echo "SKIPPED — webhook.site unreachable from this machine ({$tok['error']})\n";
    echo "  passed: 0   failed: 0   (live test skipped, no network)\n";
    exit(0);
}
echo "webhook.site token: {$uuid}\n";

// ── 1. The context enrich_context() would emit, with distinctive values ────
$context = array(
    'event'           => 'approvals.set_status_changed',
    'setID'           => 4242,
    'setName'         => 'LIVE Spring Launch',
    'setStatus'       => 'client',
    'setLink'         => 'https://example.test/review?pcm_approval=tok123',
    'setInternalLink' => 'https://example.test/wp-admin/admin.php?page=power-creatives&pcm_approval_set=4242',
    'setComment'      => 'Please make the logo bigger',
    'brandName'       => 'Brizy Profit Media',
    'brandExtID'      => 'CRM-BRAND-77',
    'deliveryName'    => 'August content batch',
    'deliveryExtID'   => 'CRM-DLV-9',
    'projectName'     => 'Q3 SEO push',
    'projectExtID'    => 'CRM-PRJ-3',
    // legacy keys ride along like the real emit
    'setId'           => 4242, 'name' => 'LIVE Spring Launch', 'status' => 'client',
);

// The payload builder rows as a user would type them — including the exact
// lowercase slip from the owner's failing test.
$mapping = array(
    'brandName'       => '{{brandName}}',
    'brandExtID'      => '{{brandExtID}}',
    'deliveryName'    => '{{ deliveryname }}',   // the reported case slip — must resolve now
    'deliveryExtID'   => '{{deliveryExtID}}',
    'projectName'     => '{{projectName}}',
    'projectExtID'    => '{{projectExtID}}',
    'setID'           => '{{setID}}',
    'setName'         => '{{setName}}',
    'setStatus'       => '{{setStatus}}',
    'setLink'         => '{{setLink}}',
    'setInternalLink' => '{{setInternalLink}}',
    'setComment'      => '{{setComment}}',
);
$inputs  = PCM_Automation_Mapping::resolve($mapping, $context);
$payload = array_merge(array('event' => $context['event']), $inputs);

// ── 2. Send through the REAL channel (signed, blocking) ────────────────────
$channel = new PCM_Webhook_Channel();
$result  = $channel->send(
    array('url' => 'https://webhook.site/' . $uuid, 'blocking' => true),
    $payload,
    1
);
check('the channel reports success', !empty($result['ok']), $result);
check('webhook.site answered 2xx', (int) ($result['code'] ?? 0) < 300, $result['code'] ?? null);

// ── 3. Read back what actually ARRIVED ─────────────────────────────────────
sleep(2); // webhook.site indexes asynchronously
$reqs = http('GET', 'https://webhook.site/token/' . $uuid . '/requests?sorting=newest');
$data = json_decode($reqs['body'], true);
$hit  = is_array($data) && !empty($data['data'][0]) ? $data['data'][0] : null;
check('exactly one request arrived', is_array($data) && count($data['data'] ?? array()) === 1,
    is_array($data) ? count($data['data'] ?? array()) : $reqs['code']);
check('request found', $hit !== null);
$received = $hit ? json_decode((string) ($hit['content'] ?? ''), true) : null;
check('the received body is JSON', is_array($received), $hit['content'] ?? null);

echo "\n— every required variable arrived with its exact value —\n";
$expect = array(
    'brandName'       => 'Brizy Profit Media',
    'brandExtID'      => 'CRM-BRAND-77',
    'deliveryName'    => 'August content batch',   // via the lowercase token!
    'deliveryExtID'   => 'CRM-DLV-9',
    'projectName'     => 'Q3 SEO push',
    'projectExtID'    => 'CRM-PRJ-3',
    'setID'           => 4242,                     // int preserved end-to-end
    'setName'         => 'LIVE Spring Launch',
    'setStatus'       => 'client',
    'setLink'         => 'https://example.test/review?pcm_approval=tok123',
    'setInternalLink' => 'https://example.test/wp-admin/admin.php?page=power-creatives&pcm_approval_set=4242',
    'setComment'      => 'Please make the logo bigger',
);
foreach ($expect as $k => $want) {
    $got = is_array($received) && array_key_exists($k, $received) ? $received[$k] : '«absent»';
    check("received {$k}", $got === $want, $got);
}
check('the event name rode along', ($received['event'] ?? '') === 'approvals.set_status_changed', $received['event'] ?? null);
check('a timestamp was stamped by the channel', !empty($received['timestamp']), 'missing');

echo "\n— authenticity: signature verifiable from the RECEIVED bytes —\n";
$sig_header = '';
foreach ((array) ($hit['headers'] ?? array()) as $hk => $hv) {
    if (strtolower((string) $hk) === 'x-pcm-signature') {
        $sig_header = is_array($hv) ? (string) reset($hv) : (string) $hv;
    }
}
check('X-PCM-Signature header arrived', $sig_header !== '', array_keys((array) ($hit['headers'] ?? array())));
$recomputed = 'sha256=' . hash_hmac('sha256', (string) ($hit['content'] ?? ''), 'live-test-secret');
check('signature matches the received body (consumer can verify authenticity)',
    hash_equals($recomputed, $sig_header), array('want' => $recomputed, 'got' => $sig_header));

// Clean up the token so reruns stay deterministic.
http('DELETE', 'https://webhook.site/token/' . $uuid);

echo "\n" . str_repeat('-', 60) . "\n";
echo "  passed: {$PASS}   failed: {$FAIL}   (LIVE against webhook.site)\n";
exit($FAIL > 0 ? 1 : 0);
