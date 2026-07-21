<?php
/**
 * Unit Tests — the keep-alive REST surface (token gate + status endpoint).
 *
 * Historical note: this suite originally covered the external cron-tick URL;
 * that feature was removed by owner ruling (background scanning is always-on
 * and fully internal — no external schedulers). The file keeps its name
 * because StrategyKeepaliveTest reuses pcm_test_define_crontick_fakes().
 *
 * Covers: the public /strategies/keepalive route's token auth, the hidden
 * emergency brake ('0') making the chain a no-op, and cron_info's status-only
 * shape (no tick URL — there is nothing to configure).
 *
 * @package PowerCreatives\Tests\Unit
 */

require_once __DIR__ . '/StrategySectionsConfigTest.php';

function pcm_test_define_crontick_fakes(): void
{
    // Minimal PCM_DB — the scans must resolve their entry points without
    // fataling; empty result sets are all the gate tests need.
    if (!class_exists('PCM_DB', false)) {
        class PCM_DB
        {
            public static $rssStrategies = array();
            public static $dueScheduledStrategies = array();
            public static function get_rss_strategies()
            {
                return self::$rssStrategies;
            }
            public static function get_due_scheduled_strategies($now)
            {
                return self::$dueScheduledStrategies;
            }
        }
    }
    if (!function_exists('get_transient')) {
        function get_transient($key)
        {
            return $GLOBALS['pcm_test_transients'][$key] ?? false;
        }
        function set_transient($key, $value, $ttl = 0)
        {
            $GLOBALS['pcm_test_transients'][$key] = $value;
            return true;
        }
    }
    if (!function_exists('rest_url')) {
        function rest_url($path = '')
        {
            return 'https://example.test/wp-json/' . ltrim($path, '/');
        }
    }
    if (!function_exists('wp_generate_password'))  {
        function wp_generate_password($length = 12, $special = true, $extra = false)
        {
            return str_repeat('t', (int) $length);
        }
    }
}

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class StrategyCronTickTest extends \PHPUnit\Framework\TestCase
{
    private $controller;

    protected function setUp(): void
    {
        parent::setUp();
        pcm_test_define_crontick_fakes();  // minimal PCM_DB must win the race
        pcm_test_define_sections_fakes();  // REST stack + PCM_LLM + absint
        pcm_test_define_strategy_fakes();  // shared WP shims / cron / options
        require_once dirname(__DIR__, 2) . '/includes/modules/strategy/service.php';
        require_once dirname(__DIR__, 2) . '/includes/modules/strategy/controller.php';

        $GLOBALS['pcm_test_transients'] = array();
        update_option('pcm_cron_token', 'secret-token-for-tests');
        // Every test that reaches run_keepalive_chain sets the hidden brake so
        // the unit process never actually sleeps.
        update_option('pcm_keepalive_enabled', '0');
        $this->controller = new PCM_REST_Strategy();
    }

    public function test_keepalive_link_missing_token_is_rejected(): void
    {
        $res = $this->controller->keepalive_link(new WP_REST_Request(array()));
        $this->assertInstanceOf(WP_Error::class, $res);
    }

    public function test_keepalive_link_wrong_token_is_rejected(): void
    {
        $res = $this->controller->keepalive_link(new WP_REST_Request(array('token' => 'nope')));
        $this->assertInstanceOf(WP_Error::class, $res);
    }

    public function test_keepalive_link_empty_stored_token_rejects_even_an_empty_param(): void
    {
        update_option('pcm_cron_token', '');
        $res = $this->controller->keepalive_link(new WP_REST_Request(array('token' => '')));
        $this->assertInstanceOf(WP_Error::class, $res);
    }

    public function test_keepalive_link_with_brake_reports_not_alive_and_runs_nothing(): void
    {
        $res = $this->controller->keepalive_link(new WP_REST_Request(array('token' => 'secret-token-for-tests')));
        $this->assertInstanceOf(WP_REST_Response::class, $res);
        $this->assertFalse($res->data['alive']);
        // The braked chain must exit before taking ownership.
        $this->assertSame('', (string) get_option('pcm_keepalive_owner', ''));
    }

    public function test_cron_info_is_status_only_with_no_tick_url(): void
    {
        update_option('pcm_keepalive_beat', time() - 5);
        update_option('pcm_rss_last_scan', '2026-07-17 10:00:00');
        $res = $this->controller->cron_info(new WP_REST_Request(array()));
        $this->assertInstanceOf(WP_REST_Response::class, $res);
        $this->assertArrayNotHasKey('tickUrl', $res->data, 'the external tick URL is gone');
        $this->assertSame('2026-07-17 10:00:00', $res->data['lastScan']);
        $this->assertTrue($res->data['keepalive']['aliveNow'], 'a 5s-old beat is alive');
        $this->assertNotNull($res->data['keepalive']['lastBeat']);
    }

    public function test_cron_info_with_no_beat_reports_not_alive(): void
    {
        $res = $this->controller->cron_info(new WP_REST_Request(array()));
        $this->assertNull($res->data['keepalive']['lastBeat']);
        $this->assertFalse($res->data['keepalive']['aliveNow']);
    }
}
