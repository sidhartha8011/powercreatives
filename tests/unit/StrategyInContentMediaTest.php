<?php
/**
 * Unit Tests — Strategy in-content images & charts (A6, AutoPress [IMAGE_N] /
 * media_assets parity).
 *
 * Exercises the REAL PCM_Strategy_Service::generate_next_item() against the same
 * in-process PCM_DB / PCM_Schema / cron stand-ins the auto-publish suite defines
 * (reused via `require_once` of that file's pcm_test_define_strategy_fakes()) —
 * but with RICHER fakes for the two classes this feature drives: a PCM_LLM whose
 * invoke_json() returns a settable article (content + media_assets), and a
 * PCM_Strategy_Image that records calls and returns a settable URL. Those richer
 * fakes are declared FIRST (before pcm_test_define_strategy_fakes() runs), so
 * their `class_exists(..., false)` guards win over the sibling suite's plainer
 * ones. Provider fully faked — no network, no real image/chart generation.
 *
 * Same process-isolation contract as the sibling suites: fakes share class NAMES
 * with the real classmapped services, so every method runs in its own PHP
 * process and each class_exists() guard passes `false` to disable autoloading.
 * The fakes are declared inside a top-level function invoked in setUp() (never at
 * file top level) so they aren't declared merely by PHPUnit collecting this file
 * in the non-isolated parent process — the proven idiom this repo's strategy
 * suites use, which also guarantees precedence via call ordering in setUp().
 *
 * @package PowerCreatives\Tests\Unit
 */

/**
 * Declares this suite's richer PCM_LLM + PCM_Strategy_Image fakes (and an
 * esc_attr() shim the sibling fakes don't provide) exactly once per (isolated)
 * process. Must be CALLED before pcm_test_define_strategy_fakes() in setUp() so
 * these win: the sibling's own PCM_LLM declaration is guarded by
 * class_exists('PCM_LLM', false) and skips once this one is already present.
 */
function pcm_test_define_in_content_media_fakes(): void
{
    if (!function_exists('esc_attr')) {
        function esc_attr($s)
        {
            return htmlspecialchars((string) $s, ENT_QUOTES);
        }
    }

    // Richer PCM_LLM: invoke_json() returns a per-test settable article so a test
    // can supply content with [IMAGE_N] tokens and a matching media_assets array.
    if (!class_exists('PCM_LLM', false)) {
        class PCM_LLM
        {
            /** @var array|null The next article invoke_json() returns (null = default). */
            public static $nextResult = null;
            /** @var int Total invoke_json() calls. */
            public static $callCount = 0;
            /** @var string|null Last user-role message content (assert prompt wiring). */
            public static $lastUserMessage = null;
            /** @var array What invoke_with_grounding() returns (research enrichment). */
            public static $groundingResult = array('content' => 'RESEARCH: metric A is 42%, metric B is 58%, metric C is 71%.');

            public static function invoke_json($messages, $schema, $options)
            {
                self::$callCount++;
                foreach ($messages as $m) {
                    if (($m['role'] ?? '') === 'user') {
                        self::$lastUserMessage = $m['content'];
                    }
                }
                if (self::$nextResult !== null) {
                    return self::$nextResult;
                }
                return array('title' => 'Generated Title', 'content' => '<p>body</p>', 'metaTitle' => 'MT', 'metaDescription' => 'MD');
            }

            // Research enrichment (maybe_research_context) uses grounding; the fake
            // returns a settable summary so a test can exercise the research path.
            public static function invoke_with_grounding($messages, $options)
            {
                return self::$groundingResult;
            }
        }
    }

    // Fake PCM_Strategy_Image (same shape as the featured-image suite's): records
    // every generate() call and returns a settable URL (null simulates failure).
    if (!class_exists('PCM_Strategy_Image', false)) {
        class PCM_Strategy_Image
        {
            /** @var array<int,array<int,mixed>> every generate() call's args */
            public static $calls = array();
            /** @var string|null what generate() returns (null = failed/absent image) */
            public static $returnUrl = 'https://img.example/generated.png';
            public static function generate($prompt, $uid, $provider = '', $model = '')
            {
                self::$calls[] = func_get_args();
                return self::$returnUrl;
            }
        }
    }
}

// Reuse the sibling suite's fakes function (PCM_DB / PCM_Schema / cron / etc.).
// require_once — not a fresh copy — so there is exactly one definition of
// pcm_test_define_strategy_fakes() across the suites. Our richer fakes are
// DECLARED (above) before this require, so they take precedence at call time.
require_once __DIR__ . '/StrategyAutoPublishTest.php';

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class StrategyInContentMediaTest extends \PHPUnit\Framework\TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // OUR fakes FIRST so their class_exists(..., false) guards win over the
        // sibling suite's plainer PCM_LLM/PCM_Strategy_Image.
        pcm_test_define_in_content_media_fakes();
        pcm_test_define_strategy_fakes();

        require_once dirname(__DIR__, 2) . '/includes/modules/strategy/service.php';

        // Reset shared fake statics (mirrors the sibling suites' setUp).
        PCM_DB::$items = array();
        PCM_DB::$strategy = array();
        PCM_DB::$strategyRow = null;
        PCM_DB::$site = null;
        PCM_DB::$articles = array();
        PCM_DB::$articleSeq = 100;
        PCM_DB::$forceNullArticle = false;
        PCM_DB::$forceClaimFail = false;
        PCM_DB::$loseGenerationClaims = 0;
        PCM_Sites_Service::$calls = array();
        PCM_Sites_Service::$shouldThrow = false;
        PCM_Test_Cron::$scheduleCalls = array();
        PCM_Test_Cron::$alreadyScheduled = false;
        PCM_Test_Cron::$now = null;

        // Reset this suite's own fake statics.
        PCM_LLM::$nextResult = null;
        PCM_LLM::$callCount = 0;
        PCM_LLM::$lastUserMessage = null;
        PCM_LLM::$groundingResult = array('content' => 'RESEARCH: metric A is 42%, metric B is 58%, metric C is 71%.');
        PCM_Strategy_Image::$calls = array();
        PCM_Strategy_Image::$returnUrl = 'https://img.example/generated.png';
    }

    /** A canonical QUALITY Chart.js config (≥3 labels, ≥3 numeric distinct values,
     *  named series, descriptive title) — passes is_quality_chart(). */
    private function qualityChart(): array
    {
        return array(
            'type' => 'bar',
            'data' => array(
                'labels'   => array('Q1', 'Q2', 'Q3'),
                'datasets' => array(array('label' => 'Revenue', 'data' => array(10, 25, 18))),
            ),
            'options' => array('plugins' => array('title' => array('text' => 'Quarterly Revenue'))),
        );
    }

    /** @param array<int,array<string,mixed>> $items */
    private function seedItems(array $items): void
    {
        foreach ($items as $it) {
            $o = (object) $it;
            PCM_DB::$items[$o->id] = $o;
        }
    }

    /** The single created article's content string. */
    private function articleContent(): string
    {
        $articles = array_values(PCM_DB::$articles);
        $this->assertNotEmpty($articles, 'an article should have been created');
        return (string) ($articles[0]['content'] ?? '');
    }

    /** A one-item, in-content-media-enabled strategy (default ON; featuredImages off). */
    private function strategy(?array $config = null): object
    {
        $this->seedItems(array(array('id' => 1, 'keyword' => 'kw one', 'status' => 'pending', 'position' => 0)));
        return (object) array(
            'id' => 7, 'templateId' => 3, 'brandId' => null,
            'config' => $config === null ? null : json_encode($config),
            'totalItems' => 1, 'completedItems' => 0, 'failedItems' => 0,
        );
    }

    // ── (a) image asset → placeholder replaced with figure + fake URL ────────

    public function test_image_asset_replaces_placeholder_with_figure_containing_the_generated_url(): void
    {
        PCM_LLM::$nextResult = array(
            'title' => 'T', 'metaTitle' => 'MT', 'metaDescription' => 'MD',
            'content' => "<p>Intro</p>\n[IMAGE_1]\n<p>More</p>",
            'media_assets' => array(
                array('placeholder' => 'IMAGE_1', 'type' => 'image', 'prompt' => 'A friendly robot on a desk'),
            ),
        );

        PCM_Strategy_Service::generate_next_item($this->strategy(array()), 1);

        $content = $this->articleContent();
        $this->assertStringContainsString('<figure class="pcm-in-content-media">', $content);
        $this->assertStringContainsString('https://img.example/generated.png', $content);
        $this->assertStringNotContainsString('[IMAGE_1]', $content, 'the placeholder token must be consumed');
        $this->assertCount(1, PCM_Strategy_Image::$calls, 'the in-content image generator is called exactly once');
        $this->assertSame('A friendly robot on a desk', PCM_Strategy_Image::$calls[0][0] ?? null);
        $this->assertSame('completed', PCM_DB::$items[1]->status);
    }

    // ── (b) chart asset → figure src is a quickchart.io URL with encoded config ─

    public function test_chart_asset_builds_a_quickchart_url_containing_the_encoded_config(): void
    {
        // Must be a QUALITY chart now that charts pass a server-side validator —
        // a 2-point/untitled config would be dropped as junk (see the (c) test).
        $chart = $this->qualityChart();
        PCM_LLM::$nextResult = array(
            'title' => 'T', 'metaTitle' => 'MT', 'metaDescription' => 'MD',
            'content' => '<p>Data</p>[IMAGE_1]',
            'media_assets' => array(
                array('placeholder' => 'IMAGE_1', 'type' => 'chart', 'prompt' => 'Sales by quarter', 'chart_config' => $chart),
            ),
        );

        PCM_Strategy_Service::generate_next_item($this->strategy(array()), 1);

        $content = $this->articleContent();
        $this->assertStringContainsString('https://quickchart.io/chart?w=800&h=450&c=', $content);
        $this->assertStringContainsString(rawurlencode(json_encode($chart)), $content, 'the Chart.js config must be rawurlencoded into the URL');
        $this->assertStringContainsString('<figure class="pcm-in-content-media">', $content);
        $this->assertStringNotContainsString('[IMAGE_1]', $content);
        $this->assertCount(0, PCM_Strategy_Image::$calls, 'a chart must not call the image generator');
    }

    // ── (c) config disabled → placeholders stripped, no figures ──────────────

    public function test_disabled_config_strips_placeholders_and_generates_no_media(): void
    {
        PCM_LLM::$nextResult = array(
            'title' => 'T', 'metaTitle' => 'MT', 'metaDescription' => 'MD',
            'content' => '<p>Intro</p>[IMAGE_1]<p>More</p>',
            'media_assets' => array(
                array('placeholder' => 'IMAGE_1', 'type' => 'image', 'prompt' => 'anything'),
            ),
        );

        PCM_Strategy_Service::generate_next_item($this->strategy(array('inContentMedia' => false)), 1);

        $content = $this->articleContent();
        $this->assertStringNotContainsString('<figure', $content, 'no figures when the feature is disabled');
        $this->assertStringNotContainsString('[IMAGE_1]', $content, 'the raw placeholder must still be stripped on the skip path');
        $this->assertCount(0, PCM_Strategy_Image::$calls, 'no image generation when disabled');
    }

    // ── (d) leftover/unmatched placeholder is stripped ───────────────────────

    public function test_leftover_unmatched_placeholder_is_stripped(): void
    {
        PCM_LLM::$nextResult = array(
            'title' => 'T', 'metaTitle' => 'MT', 'metaDescription' => 'MD',
            'content' => '<p>a</p>[IMAGE_1]<p>b</p>[IMAGE_2]<p>c</p>',
            // Only IMAGE_1 has a matching asset — IMAGE_2 is orphaned.
            'media_assets' => array(
                array('placeholder' => 'IMAGE_1', 'type' => 'image', 'prompt' => 'pic'),
            ),
        );

        PCM_Strategy_Service::generate_next_item($this->strategy(array()), 1);

        $content = $this->articleContent();
        $this->assertStringContainsString('pcm-in-content-media', $content, 'IMAGE_1 is placed');
        $this->assertStringNotContainsString('[IMAGE_2]', $content, 'the unmatched placeholder must be stripped');
        $this->assertStringNotContainsString('[IMAGE', $content, 'no raw placeholder tokens may survive');
    }

    // ── (e) <p>-wrapped placeholder handled (whole wrapper replaced) ─────────

    public function test_paragraph_wrapped_placeholder_replaces_the_whole_wrapper(): void
    {
        PCM_LLM::$nextResult = array(
            'title' => 'T', 'metaTitle' => 'MT', 'metaDescription' => 'MD',
            'content' => '<p>Intro</p><p>[IMAGE_1]</p><p>More</p>',
            'media_assets' => array(
                array('placeholder' => 'IMAGE_1', 'type' => 'image', 'prompt' => 'pic'),
            ),
        );

        PCM_Strategy_Service::generate_next_item($this->strategy(array()), 1);

        $content = $this->articleContent();
        $this->assertStringContainsString('<figure class="pcm-in-content-media">', $content);
        $this->assertStringNotContainsString('<p>[IMAGE_1]</p>', $content, 'the lone-<p> wrapper must be replaced whole');
        $this->assertStringNotContainsString('[IMAGE_1]', $content);
        $this->assertStringNotContainsString('<p><figure', $content, 'the figure must not be left nested inside a <p>');
    }

    // ── (f) image generation returns null → asset dropped, token stripped ────

    public function test_null_image_drops_the_asset_and_strips_the_token(): void
    {
        PCM_Strategy_Image::$returnUrl = null; // simulate a failed/absent image
        PCM_LLM::$nextResult = array(
            'title' => 'T', 'metaTitle' => 'MT', 'metaDescription' => 'MD',
            'content' => '<p>a</p>[IMAGE_1]',
            'media_assets' => array(
                array('placeholder' => 'IMAGE_1', 'type' => 'image', 'prompt' => 'pic'),
            ),
        );

        $res = PCM_Strategy_Service::generate_next_item($this->strategy(array()), 1);

        $content = $this->articleContent();
        $this->assertStringNotContainsString('<figure', $content, 'a null image must not produce a figure');
        $this->assertStringNotContainsString('[IMAGE_1]', $content, 'the token is stripped when its asset is dropped');
        $this->assertNotNull($res, 'a null image must never fail generation');
        $this->assertSame('completed', PCM_DB::$items[1]->status);
    }

    // ── (g) mediaCount=1 caps placement: 2 returned assets → 1 figure, other stripped ─

    public function test_media_count_caps_the_number_of_placed_assets(): void
    {
        PCM_LLM::$nextResult = array(
            'title' => 'T', 'metaTitle' => 'MT', 'metaDescription' => 'MD',
            'content' => '<p>a</p>[IMAGE_1]<p>b</p>[IMAGE_2]',
            'media_assets' => array(
                array('placeholder' => 'IMAGE_1', 'type' => 'image', 'prompt' => 'first'),
                array('placeholder' => 'IMAGE_2', 'type' => 'image', 'prompt' => 'second'),
            ),
        );

        PCM_Strategy_Service::generate_next_item($this->strategy(array('mediaCount' => 1)), 1);

        $content = $this->articleContent();
        $this->assertSame(1, substr_count($content, '<figure class="pcm-in-content-media">'), 'only mediaCount figures are placed');
        $this->assertStringNotContainsString('[IMAGE_2]', $content, 'the over-cap placeholder must be stripped');
        $this->assertStringNotContainsString('[IMAGE', $content, 'no raw tokens survive');
        $this->assertCount(1, PCM_Strategy_Image::$calls, 'only the capped asset is generated');
    }

    // ── (h) mediaType='images' → a returned chart is dropped, the image kept ──

    public function test_media_type_images_drops_chart_assets(): void
    {
        PCM_LLM::$nextResult = array(
            'title' => 'T', 'metaTitle' => 'MT', 'metaDescription' => 'MD',
            'content' => '<p>a</p>[IMAGE_1]<p>b</p>[IMAGE_2]',
            'media_assets' => array(
                array('placeholder' => 'IMAGE_1', 'type' => 'chart', 'prompt' => 'a chart', 'chart_config' => $this->qualityChart()),
                array('placeholder' => 'IMAGE_2', 'type' => 'image', 'prompt' => 'an image'),
            ),
        );

        PCM_Strategy_Service::generate_next_item($this->strategy(array('mediaType' => 'images')), 1);

        $content = $this->articleContent();
        $this->assertStringNotContainsString('quickchart.io', $content, 'a chart must be dropped under images-only');
        $this->assertStringNotContainsString('[IMAGE_1]', $content, 'the dropped chart token is stripped');
        $this->assertStringContainsString('https://img.example/generated.png', $content, 'the image asset is kept');
        $this->assertCount(1, PCM_Strategy_Image::$calls, 'only the image asset is generated');
    }

    // ── (i) junk chart (all-equal values) → dropped, token stripped, no URL ──

    public function test_junk_chart_is_dropped_by_the_quality_validator(): void
    {
        $junk = array(
            'type' => 'bar',
            // 3 labels but all-identical values AND no title/named-context beyond label — the
            // all-equal values alone are the canonical junk smell the validator rejects.
            'data' => array('labels' => array('A', 'B', 'C'), 'datasets' => array(array('label' => 'S', 'data' => array(1, 1, 1)))),
            'options' => array('plugins' => array('title' => array('text' => 'Chart'))),
        );
        PCM_LLM::$nextResult = array(
            'title' => 'T', 'metaTitle' => 'MT', 'metaDescription' => 'MD',
            'content' => '<p>data</p>[IMAGE_1]',
            'media_assets' => array(
                array('placeholder' => 'IMAGE_1', 'type' => 'chart', 'prompt' => 'junk', 'chart_config' => $junk),
            ),
        );

        PCM_Strategy_Service::generate_next_item($this->strategy(array()), 1);

        $content = $this->articleContent();
        $this->assertStringNotContainsString('quickchart.io', $content, 'a junk chart yields no quickchart URL');
        $this->assertStringNotContainsString('<figure', $content, 'a junk chart produces no figure');
        $this->assertStringNotContainsString('[IMAGE_1]', $content, 'the junk chart token is stripped');
    }

    // ── (j) quality chart passes the validator → quickchart URL present ──

    public function test_quality_chart_passes_the_validator_and_renders(): void
    {
        $chart = $this->qualityChart();
        PCM_LLM::$nextResult = array(
            'title' => 'T', 'metaTitle' => 'MT', 'metaDescription' => 'MD',
            'content' => '<p>data</p>[IMAGE_1]',
            'media_assets' => array(
                array('placeholder' => 'IMAGE_1', 'type' => 'chart', 'prompt' => 'Quarterly revenue, source: 10-K', 'chart_config' => $chart),
            ),
        );

        PCM_Strategy_Service::generate_next_item($this->strategy(array()), 1);

        $content = $this->articleContent();
        $this->assertStringContainsString('https://quickchart.io/chart?w=800&h=450&c=', $content);
        $this->assertStringContainsString(rawurlencode(json_encode($chart)), $content, 'the validated chart config is encoded into the URL');
        $this->assertStringContainsString('<figure class="pcm-in-content-media">', $content);
    }

    // ── (k) research context present → prompt carries the research-data instruction ─

    public function test_research_context_injects_the_research_chart_instruction_into_the_prompt(): void
    {
        PCM_LLM::$nextResult = array(
            'title' => 'T', 'metaTitle' => 'MT', 'metaDescription' => 'MD',
            'content' => '<p>body</p>',
            'media_assets' => array(),
        );

        PCM_Strategy_Service::generate_next_item($this->strategy(array('research' => true)), 1);

        $this->assertNotNull(PCM_LLM::$lastUserMessage, 'the article prompt should have been built');
        $this->assertStringContainsString(
            'Base chart data on the RESEARCH FINDINGS above',
            (string) PCM_LLM::$lastUserMessage,
            'with a research context, the media instruction must direct charts to use the research findings'
        );
    }

    public function test_media_guidance_reaches_the_writer_prompt(): void
    {
        PCM_LLM::$nextResult = array(
            'title' => 'T', 'metaTitle' => 'MT', 'metaDescription' => 'MD',
            'content' => '<p>Body</p>',
            'media_assets' => array(),
        );

        PCM_Strategy_Service::generate_next_item(
            $this->strategy(array('mediaGuidance' => 'charts comparing yearly market growth')),
            1
        );

        $this->assertStringContainsString(
            'CREATIVE DIRECTION for the visuals: "charts comparing yearly market growth"',
            (string) PCM_LLM::$lastUserMessage,
            'config.mediaGuidance must be forwarded into the IN-CONTENT MEDIA prompt block'
        );
    }
}
