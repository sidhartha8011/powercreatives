<?php
/**
 * Unit Tests — Task F3: display-only search-volume + keyword-difficulty carried
 * from the Keyword Explorer onto each strategy item at creation.
 *
 * Exercises the REAL PCM_Strategy_Service::create_from_keywords() against
 * lightweight in-process stand-ins for PCM_DB / PCM_Schema (the same fakes
 * pattern StrategyAutoPublishTest.php establishes). The fake PCM_DB::
 * create_strategy_items() mirrors the real method's F3 side effect (writes
 * volume/difficulty from the optional 4th $meta param, keyed by keyword string)
 * so these tests assert the SERVICE correctly threads $options['keywordMeta']
 * through to the DB layer.
 *
 * Controller-level sanitization (PCM_REST_Strategy::sanitize_keyword_meta) is
 * NOT unit-tested here — REST handlers aren't exercisable in this WP-Mock-free
 * harness — so it is enforced by code review; the service-level tests below run
 * with already-clean, keyword-keyed input, exactly as the controller produces.
 *
 * The fakes share class NAMES with the real composer-classmapped services, so
 * this class carries @runTestsInSeparateProcesses (each test method runs in its
 * own PHP process) — without it, whichever test in the FULL suite autoloads the
 * real PCM_DB/PCM_Schema first would collide with these stand-ins. Every
 * class_exists() guard passes `false` to disable autoloading (the default would
 * itself load the real class), and the declarations live inside a plain
 * function so they aren't parsed merely by PHPUnit collecting this file in the
 * (non-isolated) parent process.
 *
 * @package PowerCreatives\Tests\Unit
 */

/** Declares the fakes exactly once per (isolated) process, on first call. */
function pcm_test_define_volume_difficulty_fakes(): void
{
    if (!defined('ABSPATH')) {
        define('ABSPATH', '/tmp/wordpress/');
    }
    if (!function_exists('sanitize_text_field')) {
        function sanitize_text_field($s)
        {
            return is_string($s) ? trim($s) : $s;
        }
    }
    if (!function_exists('wp_json_encode')) {
        function wp_json_encode($v)
        {
            return json_encode($v);
        }
    }
    if (!function_exists('current_time')) {
        function current_time($type)
        {
            return date('Y-m-d H:i:s');
        }
    }
    // Background-queue continuation is armed for non-schedule strategies. Fake the
    // two cron helpers so create_from_keywords()'s call to
    // maybe_schedule_queue_continuation() is a harmless no-op we don't assert on.
    if (!function_exists('wp_next_scheduled')) {
        function wp_next_scheduled($hook, $args = array())
        {
            return false;
        }
    }
    if (!function_exists('wp_schedule_single_event')) {
        function wp_schedule_single_event($timestamp, $hook, $args = array())
        {
            return true;
        }
    }

    if (!class_exists('PCM_Schema', false)) {
        class PCM_Schema
        {
            public static function table($n)
            {
                return 'wp_pcm_' . $n;
            }
        }
    }

    if (!class_exists('PCM_DB', false)) {
        class PCM_DB
        {
            public static $items = array();       // id => object
            public static $strategyRow = null;     // what get_strategy() returns
            public static $createStrategyId = 7;   // fake id returned by create_strategy()

            public static function create_strategy($data)
            {
                return self::$createStrategyId;
            }

            /** Mirrors the real method's F3 side effect: one pending row per
             *  keyword, in position order, with volume/difficulty written from
             *  the optional $meta map (keyed by keyword string) when present. */
            public static function create_strategy_items($strategy_id, $user_id, $keywords, $meta = array())
            {
                $id = 1;
                foreach ($keywords as $position => $keyword) {
                    $row = (object)array(
                        'id' => $id, 'strategyId' => $strategy_id, 'userId' => $user_id,
                        'keyword' => $keyword, 'status' => 'pending', 'position' => $position,
                        'articleId' => null, 'errorMessage' => '',
                        'volume' => null, 'difficulty' => null,
                    );
                    $km = is_array($meta) ? ($meta[$keyword] ?? null) : null;
                    if (is_array($km)) {
                        if (isset($km['volume']) && $km['volume'] !== null) {
                            $row->volume = (int)$km['volume'];
                        }
                        if (isset($km['difficulty']) && $km['difficulty'] !== null) {
                            $row->difficulty = (int)$km['difficulty'];
                        }
                    }
                    self::$items[$id] = $row;
                    $id++;
                }
                return count($keywords);
            }

            public static function get_strategy($id, $uid)
            {
                return self::$strategyRow;
            }
            public static function get_next_pending_item($sid)
            {
                foreach (self::$items as $it) {
                    if ($it->status === 'pending') {
                        return $it;
                    }
                }
                return null;
            }
            public static function get_strategy_items($sid)
            {
                return array_values(self::$items);
            }
            public static function update_strategy_item($id, $data)
            {
                foreach ($data as $k => $v) {
                    self::$items[$id]->$k = $v;
                }
                return true;
            }
            /** Faithful CAS: writes only while the item is still 'generating'. */
            public static function complete_strategy_item_if_generating($id, $data)
            {
                if (!isset(self::$items[$id])
                    || (string) (self::$items[$id]->status ?? '') !== 'generating'
                ) {
                    return false;
                }
                foreach ($data as $k => $v) {
                    self::$items[$id]->$k = $v;
                }
                return true;
            }
        }
    }
}

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class StrategyVolumeDifficultyTest extends \PHPUnit\Framework\TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        pcm_test_define_volume_difficulty_fakes();
        require_once dirname(__DIR__, 2) . '/includes/modules/strategy/service.php';

        PCM_DB::$items = array();
        PCM_DB::$strategyRow = (object)array('id' => 7, 'userId' => 1, 'status' => 'pending');
        PCM_DB::$createStrategyId = 7;
    }

    /** (a) keywordMeta writes volume/difficulty onto the matching items. */
    public function test_create_from_keywords_writes_volume_and_difficulty_from_keyword_meta(): void
    {
        PCM_Strategy_Service::create_from_keywords(
            1,
            'My Strategy',
            3,
            null,
            array('alpha kw', 'beta kw', 'gamma kw'),
            array('keywordMeta' => array(
                'alpha kw' => array('volume' => 1500, 'difficulty' => 42),
                'gamma kw' => array('volume' => 90), // volume only
            ))
        );

        // alpha kw — both metrics bound.
        $this->assertSame(1500, PCM_DB::$items[1]->volume);
        $this->assertSame(42, PCM_DB::$items[1]->difficulty);

        // beta kw — no meta entry at all → both stay null.
        $this->assertNull(PCM_DB::$items[2]->volume);
        $this->assertNull(PCM_DB::$items[2]->difficulty);

        // gamma kw — only volume supplied → difficulty stays null.
        $this->assertSame(90, PCM_DB::$items[3]->volume);
        $this->assertNull(PCM_DB::$items[3]->difficulty);
    }

    /** (b) no keywordMeta → every item's metrics stay null, no error. */
    public function test_create_from_keywords_without_keyword_meta_leaves_metrics_null(): void
    {
        $result = PCM_Strategy_Service::create_from_keywords(
            1,
            'My Strategy',
            3,
            null,
            array('alpha kw', 'beta kw'),
            array() // no keywordMeta key at all
        );

        $this->assertNull(PCM_DB::$items[1]->volume);
        $this->assertNull(PCM_DB::$items[1]->difficulty);
        $this->assertNull(PCM_DB::$items[2]->volume);
        $this->assertNull(PCM_DB::$items[2]->difficulty);
        // create_from_keywords still returns the strategy array cleanly.
        $this->assertIsArray($result);
        $this->assertArrayHasKey('items', $result);
    }

    /**
     * (c) Meta entries that match no created item bind to nothing; items whose
     * keyword carries no entry stay null. The controller
     * (PCM_REST_Strategy::sanitize_keyword_meta — absint()s the ints,
     * sanitize_text_field()s the keyword, and DROPS malformed entries; enforced
     * by CODE REVIEW since REST handlers aren't unit-testable here) guarantees
     * the service only ever sees a clean keyword-keyed map, so the service-level
     * contract to verify is exactly this selective, keyword-matched binding.
     */
    public function test_keyword_meta_only_binds_metrics_to_matching_keywords(): void
    {
        PCM_Strategy_Service::create_from_keywords(
            1,
            'My Strategy',
            3,
            null,
            array('real kw'),
            array('keywordMeta' => array(
                'real kw'  => array('volume' => 10, 'difficulty' => 5),
                'ghost kw' => array('volume' => 999, 'difficulty' => 88), // no matching item — ignored
            ))
        );

        $this->assertCount(1, PCM_DB::$items, 'only the one real keyword produced an item');
        $this->assertSame(10, PCM_DB::$items[1]->volume);
        $this->assertSame(5, PCM_DB::$items[1]->difficulty);
    }
}
