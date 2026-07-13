<?php
/**
 * Unit Tests — per-site recurring content schedules (Task I2).
 *
 * Exercises the REAL PCM_Strategy_Service::run_site_schedules() against the
 * shared strategy fakes plus a fake PCM_Topic_Suggester and in-memory
 * get_option/update_option shims declared here. Focus: cadence dedupe (a rule
 * runs once per period, stamped only on success), rule gating (disabled /
 * incomplete / missing site), and the created strategy carrying the rule's
 * mode + siteId config.
 *
 * Same process-isolation contract as the sibling suites (shared class NAMES
 * with real classmapped services → @runTestsInSeparateProcesses, fakes behind
 * class_exists(..., false) guards, declared via top-level functions invoked
 * from setUp()).
 *
 * @package PowerCreatives\Tests\Unit
 */

// In-memory WP options store — declared BEFORE the shared fakes file so these
// function_exists-guarded shims win (that file defines neither, but ordering
// keeps this self-evidently safe).
if (!function_exists('get_option')) {
    function get_option($name, $default = false)
    {
        return $GLOBALS['pcm_test_options'][$name] ?? $default;
    }
}
if (!function_exists('update_option')) {
    function update_option($name, $value, $autoload = null)
    {
        $GLOBALS['pcm_test_options'][$name] = $value;
        return true;
    }
}

require_once __DIR__ . '/StrategyAutoPublishTest.php';

/**
 * Fake PCM_Topic_Suggester — settable topic list, call log for assertions.
 * Declared before the real service could ever require it (the service's
 * class_exists() guard then skips loading the real class).
 */
function pcm_test_define_topic_suggester_fake(): void
{
    if (!class_exists('PCM_Topic_Suggester', false)) {
        class PCM_Topic_Suggester
        {
            /** @var array<int,array<int,mixed>> every suggest() call's args */
            public static $calls = array();
            /** @var array what suggest() returns (empty simulates LLM failure) */
            public static $topics = array();
            public static function suggest($user_id, $context, $count = 5, $model = '', $provider = '')
            {
                self::$calls[] = func_get_args();
                return self::$topics;
            }
        }
    }
}

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class StrategySiteSchedulesTest extends \PHPUnit\Framework\TestCase
{
    private const NOW = '2026-07-10 09:00:00';

    protected function setUp(): void
    {
        parent::setUp();
        pcm_test_define_strategy_fakes();
        pcm_test_define_topic_suggester_fake();

        require_once dirname(__DIR__, 2) . '/includes/modules/strategy/service.php';

        $GLOBALS['pcm_test_options'] = array();
        PCM_DB::$items = array();
        PCM_DB::$strategy = array();
        PCM_DB::$strategyRow = (object) array('id' => 7, 'status' => 'pending', 'config' => null);
        PCM_DB::$createStrategyData = null;
        PCM_DB::$site = (object) array('id' => 42, 'name' => 'Client Site', 'url' => 'https://client.example');
        PCM_Topic_Suggester::$calls = array();
        PCM_Topic_Suggester::$topics = array(
            array('keyword' => 'solar panels cost', 'title' => 'T1', 'rationale' => 'R1'),
            array('keyword' => 'solar rebates 2026', 'title' => 'T2', 'rationale' => 'R2'),
        );
        PCM_Test_Cron::$scheduleCalls = array();
        PCM_Test_Cron::$alreadyScheduled = false;
        PCM_Test_Cron::$now = self::NOW;
    }

    /** Store one rule under the option the scan reads. */
    private function putRule(array $overrides = array()): void
    {
        $GLOBALS['pcm_test_options']['pcm_site_schedules'] = array(
            '42' => array_merge(array(
                'enabled'        => true,
                'frequency'      => 'weekly',
                'count'          => 2,
                'templateId'     => 5,
                'publishingMode' => 'draft',
                'niche'          => 'residential solar',
                'userId'         => 1,
                'lastRunAt'      => null,
            ), $overrides),
        );
    }

    public function test_due_rule_creates_strategy_with_site_config_and_stamps_last_run(): void
    {
        $this->putRule();

        $out = PCM_Strategy_Service::run_site_schedules(self::NOW);

        $this->assertSame('created', $out[0]['reason']);
        $this->assertGreaterThan(0, $out[0]['strategyId']);
        // Suggested keywords became the items.
        $keywords = array_map(static fn($it) => $it->keyword, array_values(PCM_DB::$items));
        $this->assertSame(array('solar panels cost', 'solar rebates 2026'), $keywords);
        // Suggester got the rule's niche + the site's identity.
        $ctx = PCM_Topic_Suggester::$calls[0][1];
        $this->assertSame('residential solar', $ctx['niche']);
        $this->assertSame('Client Site', $ctx['siteName']);
        // The created strategy targets the site and uses the rule's mode.
        $this->assertSame('draft', PCM_DB::$createStrategyData['publishingMode']);
        $cfg = json_decode((string) PCM_DB::$createStrategyData['config'], true);
        $this->assertSame(42, $cfg['siteId']);
        // lastRunAt stamped → dedupe for the rest of the period.
        $stored = $GLOBALS['pcm_test_options']['pcm_site_schedules']['42'];
        $this->assertSame(self::NOW, $stored['lastRunAt']);
    }

    public function test_rule_within_period_is_not_run_again(): void
    {
        $this->putRule(array('lastRunAt' => '2026-07-08 09:00:00')); // 2 days ago, weekly cadence

        $out = PCM_Strategy_Service::run_site_schedules(self::NOW);

        $this->assertSame('not due', $out[0]['reason']);
        $this->assertSame(array(), PCM_Topic_Suggester::$calls);
        $this->assertSame(array(), PCM_DB::$items);
    }

    public function test_rule_past_period_runs_again(): void
    {
        $this->putRule(array('lastRunAt' => '2026-07-02 09:00:00')); // 8 days ago, weekly cadence

        $out = PCM_Strategy_Service::run_site_schedules(self::NOW);

        $this->assertSame('created', $out[0]['reason']);
    }

    public function test_disabled_rule_is_skipped(): void
    {
        $this->putRule(array('enabled' => false));

        $out = PCM_Strategy_Service::run_site_schedules(self::NOW);

        $this->assertSame('disabled', $out[0]['reason']);
        $this->assertSame(array(), PCM_Topic_Suggester::$calls);
    }

    public function test_empty_suggestions_do_not_stamp_so_rule_retries_next_scan(): void
    {
        $this->putRule();
        PCM_Topic_Suggester::$topics = array();

        $out = PCM_Strategy_Service::run_site_schedules(self::NOW);

        $this->assertSame('no suggestions', $out[0]['reason']);
        $this->assertSame(array(), PCM_DB::$items);
        $this->assertNull($GLOBALS['pcm_test_options']['pcm_site_schedules']['42']['lastRunAt']);
    }

    public function test_missing_site_leaves_rule_inert(): void
    {
        $this->putRule();
        PCM_DB::$site = null;

        $out = PCM_Strategy_Service::run_site_schedules(self::NOW);

        $this->assertSame('site gone', $out[0]['reason']);
        $this->assertSame(array(), PCM_Topic_Suggester::$calls);
    }

    public function test_schedule_mode_carries_frequency_into_schedule_config(): void
    {
        $this->putRule(array('publishingMode' => 'schedule', 'frequency' => 'daily'));

        $out = PCM_Strategy_Service::run_site_schedules(self::NOW);

        $this->assertSame('created', $out[0]['reason']);
        $cfg = json_decode((string) PCM_DB::$createStrategyData['config'], true);
        $this->assertSame('daily', $cfg['scheduleConfig']['frequency']);
    }
}
