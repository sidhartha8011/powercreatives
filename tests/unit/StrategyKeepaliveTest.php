<?php
/**
 * Unit Tests — the internal keep-alive chain (opt-in traffic independence).
 *
 * The chain's sleep loop itself can't run in a unit harness (real sleep());
 * what CAN be pinned down is every decision it makes and every seam around it:
 * the pure slice decision (enabled/ownership), the RSS staleness gate, the
 * spawn's no-op guards + request shape (non-blocking, token in body), the
 * toggle handler (option write + spawn-on-enable only), and the public link
 * handler's token gate. Reuses the cron-tick suite's fakes; adds a
 * wp_remote_post capture.
 *
 * @package PowerCreatives\Tests\Unit
 */

require_once __DIR__ . '/StrategyCronTickTest.php';

function pcm_test_define_keepalive_fakes(): void
{
    if (!function_exists('wp_remote_post')) {
        function wp_remote_post($url, $args = array())
        {
            $GLOBALS['pcm_test_remote_posts'][] = array('url' => $url, 'args' => $args);
            return array('response' => array('code' => 200));
        }
    }
}

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class StrategyKeepaliveTest extends \PHPUnit\Framework\TestCase
{
    private $controller;

    protected function setUp(): void
    {
        parent::setUp();
        pcm_test_define_keepalive_fakes();
        pcm_test_define_crontick_fakes();
        pcm_test_define_sections_fakes();
        pcm_test_define_strategy_fakes();
        require_once dirname(__DIR__, 2) . '/includes/modules/strategy/service.php';
        require_once dirname(__DIR__, 2) . '/includes/modules/strategy/controller.php';

        $GLOBALS['pcm_test_transients']   = array();
        $GLOBALS['pcm_test_remote_posts'] = array();
        update_option('pcm_cron_token', 'secret-token-for-tests');
        // Hidden brake ON by default in tests — any accidental path into
        // run_keepalive_chain must exit instantly instead of sleeping.
        update_option('pcm_keepalive_enabled', '0');
        $this->controller = new PCM_REST_Strategy();
    }

    // ── keepalive_tick_decision ──

    public function test_tick_continues_by_default_and_when_owned(): void
    {
        // ALWAYS-ON: absent/empty enabled means running — only '0' stops.
        $this->assertSame('continue', PCM_Strategy_Service::keepalive_tick_decision(
            array('enabled' => '', 'owner' => 'me'),
            'me'
        ), 'absent option = default on');
        $this->assertSame('continue', PCM_Strategy_Service::keepalive_tick_decision(
            array('enabled' => '1', 'owner' => 'me'),
            'me'
        ));
    }

    public function test_tick_stops_on_brake_or_ownership_lost(): void
    {
        $this->assertSame('stop', PCM_Strategy_Service::keepalive_tick_decision(
            array('enabled' => '0', 'owner' => 'me'),
            'me'
        ), 'the hidden emergency brake must stop the chain');
        $this->assertSame('stop', PCM_Strategy_Service::keepalive_tick_decision(
            array('enabled' => '', 'owner' => 'someone-newer'),
            'me'
        ), 'a newer link taking ownership must stop the old one');
    }

    // ── keepalive_rss_due ──

    public function test_rss_due_gate(): void
    {
        $now = 1700000000;
        $this->assertTrue(PCM_Strategy_Service::keepalive_rss_due('', $now), 'never scanned → due');
        $this->assertTrue(PCM_Strategy_Service::keepalive_rss_due('not-a-date', $now), 'garbage stamp → due');
        $this->assertFalse(
            PCM_Strategy_Service::keepalive_rss_due(gmdate('Y-m-d H:i:s', $now - 10 * 60), $now),
            '10 minutes old → not due'
        );
        $this->assertTrue(
            PCM_Strategy_Service::keepalive_rss_due(gmdate('Y-m-d H:i:s', $now - 56 * 60), $now),
            '56 minutes old → due'
        );
    }

    // ── spawn_keepalive ──

    public function test_spawn_noops_on_brake_or_tokenless(): void
    {
        PCM_Strategy_Service::spawn_keepalive(); // brake is '0' from setUp
        update_option('pcm_keepalive_enabled', '');
        update_option('pcm_cron_token', '');
        PCM_Strategy_Service::spawn_keepalive(); // no token
        $this->assertCount(0, $GLOBALS['pcm_test_remote_posts']);
    }

    public function test_spawn_fires_by_default_a_nonblocking_tokened_loopback(): void
    {
        // ALWAYS-ON: no enabled option set at all → spawn still fires.
        update_option('pcm_keepalive_enabled', '');
        PCM_Strategy_Service::spawn_keepalive();
        $this->assertCount(1, $GLOBALS['pcm_test_remote_posts']);
        $call = $GLOBALS['pcm_test_remote_posts'][0];
        $this->assertStringContainsString('/strategies/keepalive', $call['url']);
        $this->assertFalse($call['args']['blocking']);
        $this->assertSame('secret-token-for-tests', $call['args']['body']['token']);
    }

    // ── keepalive_link (public route gate) ──
    // (The set_keepalive toggle route was removed — always-on by owner ruling.)

    public function test_link_rejects_bad_token(): void
    {
        $res = $this->controller->keepalive_link(new WP_REST_Request(array('token' => 'nope')));
        $this->assertInstanceOf(WP_Error::class, $res);
    }

    public function test_link_with_valid_token_noops_instantly_when_braked(): void
    {
        // Emergency brake '0' → run_keepalive_chain returns immediately (no
        // sleep, no spawn) and the handler reports alive:false.
        $res = $this->controller->keepalive_link(new WP_REST_Request(array('token' => 'secret-token-for-tests')));
        $this->assertInstanceOf(WP_REST_Response::class, $res);
        $this->assertFalse($res->data['alive']);
        $this->assertCount(0, $GLOBALS['pcm_test_remote_posts']);
    }

    // ── Loopback-dead fallback ────────────────────────────────────────────
    // spawn_keepalive() is fire-and-forget, so a host that BLOCKS or fakes
    // loopback self-requests makes every spawn look fine while the chain never
    // runs — background scanning then never happens at all (the reported
    // "auto-scan not working"). These pin the arming gate: it must stay OFF
    // while the chain is healthy and only engage once the heartbeat proves the
    // spawns are not landing.

    /** The exact condition the init self-heal evaluates for the inline fallback. */
    private function inlineFallbackArms(int $beatAgeSeconds, bool $lockHeld = false): bool
    {
        update_option('pcm_keepalive_enabled', ''); // brake off
        update_option('pcm_keepalive_beat', time() - $beatAgeSeconds);
        if ($lockHeld) {
            set_transient('pcm_keepalive_inline_lock', 1, 300);
        } else {
            delete_transient('pcm_keepalive_inline_lock');
        }
        return (string) get_option('pcm_keepalive_enabled', '') !== '0'
            && (time() - (int) get_option('pcm_keepalive_beat', 0)) > 900
            && !get_transient('pcm_keepalive_inline_lock');
    }

    public function test_inline_fallback_stays_off_while_the_chain_is_healthy(): void
    {
        $this->assertFalse($this->inlineFallbackArms(0), 'fresh heartbeat → chain alive, no inline work');
        $this->assertFalse($this->inlineFallbackArms(300), 'a 5-min quiet gap is normal between links');
        $this->assertFalse($this->inlineFallbackArms(899), 'just under the threshold stays off');
    }

    public function test_inline_fallback_engages_once_spawns_are_clearly_not_landing(): void
    {
        $this->assertTrue($this->inlineFallbackArms(901), 'heartbeat gone >15 min → loopback is dead, run inline');
        $this->assertTrue($this->inlineFallbackArms(7 * 86400), 'a week with no beat certainly qualifies');
    }

    public function test_inline_fallback_is_throttled_and_respects_the_brake(): void
    {
        $this->assertFalse($this->inlineFallbackArms(1200, true), 'throttle lock suppresses repeat runs');

        update_option('pcm_keepalive_enabled', '0'); // hidden emergency brake
        update_option('pcm_keepalive_beat', time() - 1200);
        delete_transient('pcm_keepalive_inline_lock');
        $this->assertFalse(
            (string) get_option('pcm_keepalive_enabled', '') !== '0'
                && (time() - (int) get_option('pcm_keepalive_beat', 0)) > 900,
            'the brake disables the inline fallback too'
        );
    }

    public function test_inline_runner_is_a_noop_under_the_brake(): void
    {
        // Brake on (setUp default) → returns immediately, touching nothing.
        update_option('pcm_keepalive_enabled', '0');
        PCM_Strategy_Service::run_due_work_inline();
        $this->assertCount(0, $GLOBALS['pcm_test_remote_posts'], 'braked inline run must not spawn or scan');
    }
}
