<?php
/**
 * Unit Tests — Strategy featured-image generation at article-creation time
 * (AutoPress parity).
 *
 * Exercises the REAL PCM_Strategy_Service::generate_next_item() against the same
 * in-process PCM_DB / PCM_LLM / PCM_Schema stand-ins the auto-publish suite
 * defines — reused verbatim via `require_once` of that file's
 * pcm_test_define_strategy_fakes() — PLUS a fake PCM_Strategy_Image declared
 * here. The service resolves featured-image generation through
 * PCM_Strategy_Image::generate(), so faking that one class lets us assert the
 * wiring (opt-in gate, prompt content, the URL landing on the article's
 * featuredImage column, and failure isolation) without any real provider/HTTP.
 *
 * Same process-isolation contract as the sibling suite (see its docblock): the
 * fakes share class NAMES with the real classmapped services, so every test
 * method runs in its own PHP process and every class_exists() guard passes
 * `false` to disable autoloading. The PCM_Strategy_Image fake is declared inside
 * setUp() (execution time, in the isolated child process) — never at file top
 * level — for the same reason the sibling wraps its fakes in a function.
 *
 * @package PowerCreatives\Tests\Unit
 */

// Reuse the sibling suite's fakes function (PCM_DB / PCM_LLM / PCM_Schema / cron
// stand-ins). require_once — not a fresh copy — so there is exactly one
// definition of pcm_test_define_strategy_fakes() across both files.
require_once __DIR__ . '/StrategyAutoPublishTest.php';

/**
 * Declares the fake PCM_Strategy_Image exactly once per (isolated) process.
 * Wrapped in a top-level function — never at file top level, and never inside a
 * method (PHP forbids nested class declarations) — for the same reason the
 * sibling suite wraps its fakes: so the class isn't declared merely by PHPUnit
 * collecting this file in the non-isolated parent process, only when this runs
 * inside the isolated child at test-execution time. `class_exists(..., false)`
 * disables autoloading so the guard can't pull in a real classmapped class.
 */
function pcm_test_define_strategy_image_fake(): void
{
    if (!class_exists('PCM_Strategy_Image', false)) {
        class PCM_Strategy_Image
        {
            /** @var array<int,array<int,mixed>> every generate() call's args, for assertions */
            public static $calls = array();
            /** @var string|null what generate() returns (null simulates a failed/absent image) */
            public static $returnUrl = 'https://img.example/x.png';
            public static function generate($prompt, $uid, $provider = '', $model = '')
            {
                self::$calls[] = func_get_args();
                return self::$returnUrl;
            }
        }
    }
}

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class StrategyFeaturedImageTest extends \PHPUnit\Framework\TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        pcm_test_define_strategy_fakes();

        // Declare the fake PCM_Strategy_Image BEFORE requiring the real service:
        // the service's maybe_generate_featured_image() does
        // `if (!class_exists('PCM_Strategy_Image')) require_once ...` — seeing this
        // fake already declared, it skips loading (and calling) the real one.
        pcm_test_define_strategy_image_fake();

        require_once dirname(__DIR__, 2) . '/includes/modules/strategy/service.php';

        // Reset shared fake statics (mirrors the sibling suite's setUp).
        PCM_DB::$items = array();
        PCM_DB::$strategy = array();
        PCM_DB::$strategyRow = null;
        PCM_DB::$site = null;
        PCM_DB::$articles = array();
        PCM_DB::$articleSeq = 100;
        PCM_DB::$forceNullArticle = false;
        PCM_LLM::$lastOptions = null;
        PCM_LLM::$throwOn = null;
        PCM_LLM::$callCount = 0;
        PCM_LLM::$lastUserMessage = null;
        PCM_Sites_Service::$calls = array();
        PCM_Sites_Service::$shouldThrow = false;
        PCM_Test_Cron::$scheduleCalls = array();
        PCM_Test_Cron::$alreadyScheduled = false;
        PCM_Test_Cron::$now = null;
        PCM_Approvals_Service::$createSetCalls = array();
        PCM_Approvals_Service::$nextSetId = 501;
        PCM_DB::$forceClaimFail = false;
        PCM_DB::$loseGenerationClaims = 0;

        // Reset the featured-image fake's own statics.
        PCM_Strategy_Image::$calls = array();
        PCM_Strategy_Image::$returnUrl = 'https://img.example/x.png';
    }

    /** @param array<int,array<string,mixed>> $items */
    private function seedItems(array $items): void
    {
        foreach ($items as $it) {
            $o = (object)$it;
            PCM_DB::$items[$o->id] = $o;
        }
    }

    /** The single created article row (the fakes key $articles by auto-increment id). */
    private function firstArticle(): array
    {
        $articles = array_values(PCM_DB::$articles);
        $this->assertNotEmpty($articles, 'an article should have been created');
        return $articles[0];
    }

    public function test_featured_images_enabled_stores_generated_url_and_uses_a_title_or_keyword_prompt(): void
    {
        $this->seedItems(array(array('id' => 1, 'keyword' => 'kw one', 'status' => 'pending', 'position' => 0)));
        $strategy = (object)array(
            'id' => 7, 'templateId' => 3, 'brandId' => null,
            'config' => json_encode(array('featuredImages' => true)),
            'totalItems' => 1, 'completedItems' => 0, 'failedItems' => 0,
        );

        PCM_Strategy_Service::generate_next_item($strategy, 1);

        $this->assertCount(1, PCM_Strategy_Image::$calls, 'the image generator must be called exactly once');
        $prompt = (string)(PCM_Strategy_Image::$calls[0][0] ?? '');
        $this->assertTrue(
            strpos($prompt, 'Generated Title') !== false || strpos($prompt, 'kw one') !== false,
            'the prompt must reference the article title or the keyword'
        );

        $article = $this->firstArticle();
        $this->assertSame('https://img.example/x.png', $article['featuredImage'] ?? null);
        $this->assertSame('completed', PCM_DB::$items[1]->status);
    }

    public function test_featured_images_disabled_or_absent_skips_image_and_stores_null(): void
    {
        $this->seedItems(array(array('id' => 1, 'keyword' => 'kw one', 'status' => 'pending', 'position' => 0)));
        // No featuredImages key at all — the opt-in gate must treat this as "off".
        $strategy = (object)array(
            'id' => 7, 'templateId' => 3, 'brandId' => null,
            'config' => json_encode(array('model' => 'x')),
            'totalItems' => 1, 'completedItems' => 0, 'failedItems' => 0,
        );

        PCM_Strategy_Service::generate_next_item($strategy, 1);

        $this->assertCount(0, PCM_Strategy_Image::$calls, 'no image call when the strategy did not opt in');
        $article = $this->firstArticle();
        $this->assertArrayHasKey('featuredImage', $article, 'the featuredImage key is always set on the article');
        $this->assertNull($article['featuredImage'], 'featuredImage must be null when opted out');
        $this->assertSame('completed', PCM_DB::$items[1]->status);
    }

    public function test_image_failure_is_isolated_article_still_created_with_null_featured_image(): void
    {
        // The image path is fully failure-isolated: PCM_Strategy_Image::generate()
        // returns null on ANY failure. That null must land as featuredImage without
        // failing or delaying the article — the item still completes.
        $this->seedItems(array(array('id' => 1, 'keyword' => 'kw one', 'status' => 'pending', 'position' => 0)));
        PCM_Strategy_Image::$returnUrl = null; // simulate a failed/absent image
        $strategy = (object)array(
            'id' => 7, 'templateId' => 3, 'brandId' => null,
            'config' => json_encode(array('featuredImages' => true)),
            'totalItems' => 1, 'completedItems' => 0, 'failedItems' => 0,
        );

        $res = PCM_Strategy_Service::generate_next_item($strategy, 1);

        $this->assertNotNull($res, 'a null image must never fail generation');
        $this->assertCount(1, PCM_Strategy_Image::$calls, 'the generator was still invoked');
        $article = $this->firstArticle();
        $this->assertArrayHasKey('featuredImage', $article, 'the featuredImage key is always set on the article');
        $this->assertNull($article['featuredImage'], 'a failed image degrades to a null featuredImage');
        $this->assertSame('completed', PCM_DB::$items[1]->status, 'the article is created regardless of the image outcome');
        $this->assertSame('', PCM_DB::$items[1]->errorMessage, 'an image failure must not mark the item errored');
    }
}
