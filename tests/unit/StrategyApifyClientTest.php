<?php
/**
 * Unit Tests — PCM_Apify (social account watching client).
 *
 * Exercises the REAL PCM_Apify against in-process stand-ins: the shared
 * strategy fakes (ABSPATH, wp_json_encode, PCM_Schema, error_log redirect)
 * from StrategyAutoPublishTest, plus a suite-local $wpdb fake mirroring the
 * integrations token query (prepare → sprintf'd string, get_var → seeded
 * value) and a capturing wp_remote_post with a configurable response.
 *
 * Covers: token lookup (seeded row / empty row), has_key, and the fetch
 * degradation contract — no token → no HTTP call, success → decoded items
 * with Bearer header + actor URL + JSON body, non-2xx → [], WP_Error → [].
 *
 * @package PowerCreatives\Tests\Unit
 */

require_once __DIR__ . '/StrategyAutoPublishTest.php';

/** Declares the suite-local fakes exactly once per (isolated) process. */
function pcm_test_define_apify_fakes(): void
{
    if (!function_exists('wp_remote_post')) {
        function wp_remote_post($url, $args = array())
        {
            $GLOBALS['pcm_test_remote_posts'][] = array('url' => $url, 'args' => $args);
            return $GLOBALS['pcm_test_remote_response']
                ?? array('response' => array('code' => 200), 'body' => '[]');
        }
    }
    if (!function_exists('wp_remote_retrieve_response_code')) {
        function wp_remote_retrieve_response_code($response)
        {
            return is_array($response) ? (int) ($response['response']['code'] ?? 0) : 0;
        }
    }
    if (!function_exists('wp_remote_retrieve_body')) {
        function wp_remote_retrieve_body($response)
        {
            return is_array($response) ? (string) ($response['body'] ?? '') : '';
        }
    }
    if (!class_exists('WP_Error', false)) {
        class WP_Error
        {
            private $message;
            public function __construct($code = '', $message = '')
            {
                $this->message = $message;
            }
            public function get_error_message()
            {
                return $this->message;
            }
        }
    }
    if (!function_exists('is_wp_error')) {
        function is_wp_error($thing)
        {
            return $thing instanceof WP_Error;
        }
    }
    // Minimal $wpdb mirroring PCM_LLM::get_api_key's table access: prepare()
    // captures the query + args and returns a sprintf'd string; get_var()
    // returns a configurable seeded value (the apiKey column or null).
    if (!class_exists('PCM_Test_Apify_Wpdb', false)) {
        class PCM_Test_Apify_Wpdb
        {
            /** @var mixed Seeded get_var() result — the apiKey value or null. */
            public $varResult = null;
            public $lastPrepareQuery = '';
            public $lastPrepareArgs = array();
            public $lastQuery = '';
            public function prepare($query, ...$args)
            {
                $this->lastPrepareQuery = $query;
                $this->lastPrepareArgs = $args;
                return vsprintf(str_replace('%s', "'%s'", $query), $args);
            }
            public function get_var($query)
            {
                $this->lastQuery = $query;
                return $this->varResult;
            }
        }
    }
}

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class StrategyApifyClientTest extends \PHPUnit\Framework\TestCase
{
    /** @var PCM_Test_Apify_Wpdb */
    private $wpdb;

    protected function setUp(): void
    {
        parent::setUp();
        pcm_test_define_strategy_fakes(); // ABSPATH, wp_json_encode, PCM_Schema, error_log redirect
        pcm_test_define_apify_fakes();
        require_once dirname(__DIR__, 2) . '/includes/modules/strategy/class-pcm-apify.php';

        $this->wpdb = new PCM_Test_Apify_Wpdb();
        $GLOBALS['wpdb'] = $this->wpdb;
        $GLOBALS['pcm_test_remote_posts'] = array();
        unset($GLOBALS['pcm_test_remote_response']);
    }

    // ── get_token / has_key ──

    public function test_get_token_returns_seeded_key_via_prepared_integrations_query(): void
    {
        $this->wpdb->varResult = 'apify_api_token_abc123';

        $this->assertSame('apify_api_token_abc123', PCM_Apify::get_token(42));
        $this->assertTrue(PCM_Apify::has_key(42));

        // Mirrors PCM_LLM::get_api_key: provider + userId + isActive filter,
        // newest first, against the PCM_Schema-resolved integrations table.
        $this->assertStringContainsString('wp_pcm_integrations', $this->wpdb->lastPrepareQuery);
        $this->assertStringContainsString('provider = %s', $this->wpdb->lastPrepareQuery);
        $this->assertStringContainsString('userId = %d', $this->wpdb->lastPrepareQuery);
        $this->assertStringContainsString('isActive = 1', $this->wpdb->lastPrepareQuery);
        $this->assertStringContainsString('ORDER BY updatedAt DESC LIMIT 1', $this->wpdb->lastPrepareQuery);
        $this->assertSame(array('apify', 42), $this->wpdb->lastPrepareArgs);
        // The prepared (sprintf'd) string is what actually hits get_var().
        $this->assertStringContainsString("provider = 'apify'", $this->wpdb->lastQuery);
        $this->assertStringContainsString('userId = 42', $this->wpdb->lastQuery);
    }

    public function test_get_token_returns_empty_string_when_no_row(): void
    {
        $this->wpdb->varResult = null; // no matching integration row
        $this->assertSame('', PCM_Apify::get_token(42));
        $this->assertFalse(PCM_Apify::has_key(42));

        $this->wpdb->varResult = ''; // empty stored key behaves like missing
        $this->assertSame('', PCM_Apify::get_token(42));
        $this->assertFalse(PCM_Apify::has_key(42));
    }

    // ── fetch_account_items ──

    public function test_fetch_without_token_returns_empty_and_makes_no_http_call(): void
    {
        $this->wpdb->varResult = null;

        $items = PCM_Apify::fetch_account_items(
            array('actor' => 'apify~instagram-scraper', 'input' => array('resultsLimit' => 10)),
            42
        );

        $this->assertSame(array(), $items);
        $this->assertCount(0, $GLOBALS['pcm_test_remote_posts'], 'no token must mean no HTTP call');
    }

    public function test_fetch_success_decodes_items_and_sends_bearer_actor_and_json_body(): void
    {
        $this->wpdb->varResult = 'apify_api_token_abc123';
        $dataset = array(
            array('id' => 'p1', 'url' => 'https://www.instagram.com/p/AAA/', 'caption' => 'first'),
            array('id' => 'p2', 'url' => 'https://www.instagram.com/p/BBB/', 'caption' => 'second'),
        );
        $GLOBALS['pcm_test_remote_response'] = array(
            'response' => array('code' => 201), // any 2xx counts
            'body'     => json_encode($dataset),
        );
        $input = array(
            'directUrls'  => array('https://www.instagram.com/someaccount/'),
            'resultsType' => 'posts',
            'resultsLimit' => 10,
        );

        $items = PCM_Apify::fetch_account_items(
            array('actor' => 'apify~instagram-scraper', 'input' => $input),
            42
        );

        $this->assertSame($dataset, $items);
        $this->assertCount(1, $GLOBALS['pcm_test_remote_posts']);
        $call = $GLOBALS['pcm_test_remote_posts'][0];
        $this->assertStringContainsString(
            'https://api.apify.com/v2/acts/apify~instagram-scraper/run-sync-get-dataset-items',
            $call['url']
        );
        $this->assertStringContainsString('timeout=110', $call['url']);
        $this->assertStringContainsString('format=json', $call['url']);
        $this->assertSame('Bearer apify_api_token_abc123', $call['args']['headers']['Authorization']);
        $this->assertSame('application/json', $call['args']['headers']['Content-Type']);
        $this->assertSame(json_encode($input), $call['args']['body']);
        $this->assertSame(120, $call['args']['timeout']); // MUST exceed the URL's timeout=110 cap, or real account scans (~40-60s) abort with zero items
        $this->assertTrue($call['args']['sslverify']);
    }

    public function test_fetch_non_2xx_returns_empty_array(): void
    {
        $this->wpdb->varResult = 'apify_api_token_abc123';
        $GLOBALS['pcm_test_remote_response'] = array(
            'response' => array('code' => 402),
            'body'     => '{"error":{"type":"insufficient-credit"}}',
        );

        $items = PCM_Apify::fetch_account_items(
            array('actor' => 'apify~instagram-scraper', 'input' => array('resultsLimit' => 10)),
            42
        );

        $this->assertSame(array(), $items);
        // No message in the body → bare code.
        $this->assertSame('Apify returned HTTP 402.', PCM_Apify::$last_error);
    }

    public function test_fetch_non_2xx_surfaces_the_apify_error_message(): void
    {
        $this->wpdb->varResult = 'apify_api_token_abc123';
        $GLOBALS['pcm_test_remote_response'] = array(
            'response' => array('code' => 400),
            'body'     => '{"error":{"type":"invalid-input","message":"Input is not valid: Values in input.directUrls at positions [0] must match regular expression"}}',
        );

        $items = PCM_Apify::fetch_account_items(
            array('actor' => 'apify~instagram-scraper', 'input' => array('resultsLimit' => 10)),
            42
        );

        $this->assertSame(array(), $items);
        // The API's own message reaches $last_error → the Scan-now toast names
        // the real problem instead of a bare status code.
        $this->assertSame(
            'Apify returned HTTP 400: Input is not valid: Values in input.directUrls at positions [0] must match regular expression',
            PCM_Apify::$last_error
        );
    }

    public function test_fetch_wp_error_returns_empty_array(): void
    {
        $this->wpdb->varResult = 'apify_api_token_abc123';
        $GLOBALS['pcm_test_remote_response'] = new WP_Error('http_request_failed', 'cURL error 28: timed out');

        $items = PCM_Apify::fetch_account_items(
            array('actor' => 'apify~instagram-scraper', 'input' => array('resultsLimit' => 10)),
            42
        );

        $this->assertSame(array(), $items);
        $this->assertCount(1, $GLOBALS['pcm_test_remote_posts'], 'the call was attempted, then degraded');
    }

    public function test_fetch_non_array_json_returns_empty_array(): void
    {
        $this->wpdb->varResult = 'apify_api_token_abc123';
        $GLOBALS['pcm_test_remote_response'] = array(
            'response' => array('code' => 200),
            'body'     => 'not-json-at-all',
        );

        $items = PCM_Apify::fetch_account_items(
            array('actor' => 'apify~instagram-scraper', 'input' => array('resultsLimit' => 10)),
            42
        );

        $this->assertSame(array(), $items);
    }
}
