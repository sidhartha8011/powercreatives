<?php
/**
 * Unit Tests — PCM_Topic_Suggester AI topic-ideation service.
 *
 * Exercises the REAL PCM_Topic_Suggester::suggest() against a lightweight
 * in-process stand-in for PCM_LLM (declared by pcm_test_define_topic_suggester_fakes()
 * below). This suite carries @runTestsInSeparateProcesses for the same reason
 * as StrategyAutoPublishTest.php: the stand-in shares a class name with the
 * real, composer-classmapped PCM_LLM, so process isolation is what makes
 * redeclaring it under the same name safe (see that file's docblock for the
 * full rationale).
 *
 * @package PowerCreatives\Tests\Unit
 */

/** Declares the fake PCM_LLM exactly once per (isolated) process, on first call. */
function pcm_test_define_topic_suggester_fakes(): void
{
    if (!defined('ABSPATH')) {
        define('ABSPATH', '/tmp/wordpress/');
    }
    // Same STDERR-vs-process-isolation concern as StrategyAutoPublishTest.php's
    // identical redirect — PCM_Topic_Suggester::suggest() error_log()s on the
    // throw path, which under @runTestsInSeparateProcesses would otherwise be
    // misreported as a fatal by PHPUnit's child-process runner.
    ini_set('error_log', sys_get_temp_dir() . '/pcm_test_error_log_' . getmypid() . '.log');

    // Declared BEFORE StrategyAutoPublishTest.php's pcm_test_define_strategy_fakes()
    // ever runs, so our class_exists('PCM_LLM', false) guard here wins and its
    // own (differently-shaped) PCM_LLM fake never gets declared.
    if (!class_exists('PCM_LLM', false)) {
        class PCM_LLM
        {
            /** @var string|null Last call's user-role message content. */
            public static $lastUserMessage = null;
            /** @var array|null Override for invoke_json()'s return value; null = default 3-topic payload. */
            public static $returnValue = null;
            /** @var bool When true, invoke_json() throws instead of returning. */
            public static $throw = false;

            public static function invoke_json($messages, $schema, $options)
            {
                if (self::$throw) {
                    throw new \RuntimeException('LLM boom');
                }

                $user = '';
                foreach ($messages as $m) {
                    if (($m['role'] ?? '') === 'user') {
                        $user = $m['content'];
                    }
                }
                self::$lastUserMessage = $user;

                return self::$returnValue ?? array(
                    'topics' => array(
                        array('keyword' => 'kw one', 'title' => 'Title One', 'rationale' => 'Rationale one'),
                        array('keyword' => 'kw two', 'title' => 'Title Two', 'rationale' => 'Rationale two'),
                        array('keyword' => 'kw three', 'title' => 'Title Three', 'rationale' => 'Rationale three'),
                    ),
                );
            }
        }
    }
}

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class TopicSuggesterTest extends \PHPUnit\Framework\TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Our fake PCM_LLM MUST be declared first so it wins the class_exists()
        // guard inside pcm_test_define_strategy_fakes().
        pcm_test_define_topic_suggester_fakes();

        require_once __DIR__ . '/StrategyAutoPublishTest.php';
        pcm_test_define_strategy_fakes();

        require_once dirname(__DIR__, 2) . '/includes/modules/strategy/class-pcm-topic-suggester.php';

        PCM_LLM::$lastUserMessage = null;
        PCM_LLM::$returnValue = null;
        PCM_LLM::$throw = false;
    }

    public function test_happy_path_returns_validated_topics_and_prompt_contains_niche_and_count(): void
    {
        $topics = PCM_Topic_Suggester::suggest(1, array('niche' => 'organic gardening', 'siteName' => 'GardenPro'), 3);

        $this->assertCount(3, $topics);
        $this->assertSame(array('keyword', 'title', 'rationale'), array_keys($topics[0]));
        $this->assertSame('kw one', $topics[0]['keyword']);
        $this->assertStringContainsString('organic gardening', PCM_LLM::$lastUserMessage);
        $this->assertStringContainsString('3', PCM_LLM::$lastUserMessage);
    }

    public function test_malformed_entries_missing_keyword_are_dropped(): void
    {
        PCM_LLM::$returnValue = array('topics' => array(
            array('keyword' => 'good kw', 'title' => 'Good', 'rationale' => 'Because'),
            array('title' => 'No Keyword', 'rationale' => 'Missing keyword'),
            array('keyword' => '', 'title' => 'Empty Keyword', 'rationale' => 'Empty'),
            'not-an-array',
        ));

        $topics = PCM_Topic_Suggester::suggest(1, array('niche' => 'fitness'), 5);

        $this->assertCount(1, $topics);
        $this->assertSame('good kw', $topics[0]['keyword']);
    }

    public function test_llm_throw_returns_empty_array_without_exception(): void
    {
        PCM_LLM::$throw = true;

        $topics = PCM_Topic_Suggester::suggest(1, array('niche' => 'finance'), 5);

        $this->assertSame(array(), $topics);
    }

    public function test_count_slices_results_to_requested_count(): void
    {
        PCM_LLM::$returnValue = array('topics' => array(
            array('keyword' => 'kw a', 'title' => 'A', 'rationale' => 'ra'),
            array('keyword' => 'kw b', 'title' => 'B', 'rationale' => 'rb'),
            array('keyword' => 'kw c', 'title' => 'C', 'rationale' => 'rc'),
        ));

        $topics = PCM_Topic_Suggester::suggest(1, array('niche' => 'travel'), 2);

        $this->assertCount(2, $topics);
        $this->assertSame('kw a', $topics[0]['keyword']);
        $this->assertSame('kw b', $topics[1]['keyword']);
    }

    public function test_existing_topics_are_included_in_prompt_to_avoid_duplication(): void
    {
        PCM_Topic_Suggester::suggest(1, array('niche' => 'cooking', 'existingTopics' => array('Knife skills', 'Sourdough basics')), 4);

        $this->assertStringContainsString('Knife skills', PCM_LLM::$lastUserMessage);
        $this->assertStringContainsString('Sourdough basics', PCM_LLM::$lastUserMessage);
    }
}
