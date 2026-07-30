<?php
/**
 * Unit Tests — Optimizer "no hidden prompt" exposure.
 *
 * Guards PCM_Optimizer_Service's Templates (module=optimizer) resolution
 * mechanism — the same mechanism PCM_SEO_Service already has for its field
 * prompts, extended here so the compiler + every LLM-calling teacher's
 * system prompt is a user-editable row instead of a hardcoded string.
 *
 * @package PowerCreatives\Tests\Unit
 */

use WP_Mock\Tools\TestCase;

class OptimizerPromptExposureTest extends TestCase
{
    public function test_get_default_prompts_covers_every_section(): void
    {
        WP_Mock::userFunction('apply_filters')->andReturnUsing(fn($hook, $value) => $value);
        $defaults = PCM_Optimizer_Service::get_default_prompts();

        $expected = array(
            'compile',
            'teacher_answerability',
            'teacher_facts',
            'teacher_interlink',
            'teacher_mention',
            'teacher_search',
            'teacher_serp',
            'teacher_subtopics',
        );
        $actual = array_keys($defaults);
        sort($expected);
        sort($actual);
        $this->assertSame($expected, $actual);

        foreach ($defaults as $section => $content) {
            $this->assertNotEmpty($content, "empty built-in default for {$section}");
        }
    }

    public function test_resolve_prompt_returns_default_without_user(): void
    {
        // No PCM user id → no DB lookup → shipped default returned verbatim.
        $this->assertSame('DEFAULT', PCM_Optimizer_Service::resolve_prompt('compile', 'DEFAULT', null));
        $this->assertSame('DEFAULT', PCM_Optimizer_Service::resolve_prompt('compile', 'DEFAULT', 0));
    }

    public function test_resolve_prompt_prefers_users_own_template(): void
    {
        WP_Mock::userFunction('apply_filters')->andReturnUsing(fn($hook, $value) => $value);
        $seeded = array_map(
            fn($s) => json_encode(array('type' => $s)),
            array_keys(PCM_Optimizer_Service::get_default_prompts())
        );

        global $wpdb;
        $wpdb = \Mockery::mock();
        $wpdb->prefix = 'wp_';
        $wpdb->shouldReceive('get_col')->andReturn($seeded); // seed: all present → no inserts
        $wpdb->shouldReceive('prepare')->andReturn('SQL');
        $wpdb->shouldReceive('get_results')->andReturn(array(
            array(
                'id'        => 5,
                'userId'    => 42,
                'isDefault' => 1,
                'formData'  => json_encode(array(
                    'type'    => 'teacher_answerability',
                    'entries' => array(array('category' => 'prompt', 'value' => 'MY CUSTOM PROMPT')),
                )),
            ),
        ));

        $this->assertSame(
            'MY CUSTOM PROMPT',
            PCM_Optimizer_Service::resolve_prompt('teacher_answerability', 'DEFAULT', 42)
        );
    }

    public function test_resolve_prompt_falls_back_to_default_with_no_match(): void
    {
        WP_Mock::userFunction('apply_filters')->andReturnUsing(fn($hook, $value) => $value);
        $seeded = array_map(
            fn($s) => json_encode(array('type' => $s)),
            array_keys(PCM_Optimizer_Service::get_default_prompts())
        );

        global $wpdb;
        $wpdb = \Mockery::mock();
        $wpdb->prefix = 'wp_';
        $wpdb->shouldReceive('get_col')->andReturn($seeded);
        $wpdb->shouldReceive('prepare')->andReturn('SQL');
        $wpdb->shouldReceive('get_results')->andReturn(array()); // no Template row for this user at all

        $this->assertSame(
            'DEFAULT',
            PCM_Optimizer_Service::resolve_prompt('teacher_serp', 'DEFAULT', 42)
        );
    }

    public function test_seed_optimizer_templates_inserts_every_missing_section(): void
    {
        WP_Mock::userFunction('wp_json_encode')->andReturnUsing(static fn($v) => json_encode($v));

        global $wpdb;
        $wpdb = \Mockery::mock();
        $wpdb->prefix = 'wp_';
        $wpdb->shouldReceive('get_col')->once()->andReturn(array()); // nothing seeded yet
        $wpdb->shouldReceive('insert')
            ->times(count(PCM_Optimizer_Service::get_default_prompts()))
            ->andReturn(1);
        $wpdb->insert_id = 1;

        PCM_Optimizer_Service::seed_optimizer_templates();
        // The mock's ->times() assertion (verified on teardown) is the real check —
        // reaching here without a Mockery exception means exactly 8 rows were seeded.
        $this->addToAssertionCount(1);
    }

    public function test_seed_optimizer_templates_is_idempotent(): void
    {
        global $wpdb;
        $wpdb = \Mockery::mock();
        $wpdb->prefix = 'wp_';
        $seeded = array_map(
            fn($s) => json_encode(array('type' => $s)),
            array_keys(PCM_Optimizer_Service::get_default_prompts())
        );
        $wpdb->shouldReceive('get_col')->once()->andReturn($seeded); // every section already present
        $wpdb->shouldReceive('insert')->never(); // so nothing gets re-inserted

        PCM_Optimizer_Service::seed_optimizer_templates();
        $this->addToAssertionCount(1);
    }

    // ── render_prompt_vars: single-pass substitution, unknown tokens untouched ──

    public function test_render_prompt_vars_substitutes_known_vars(): void
    {
        $rendered = PCM_Optimizer_Service::render_prompt_vars(
            'A{{x}}B{{y}}C',
            array('x' => '1', 'y' => '2')
        );
        $this->assertSame('A1B2C', $rendered);
    }

    public function test_render_prompt_vars_leaves_unknown_tokens_untouched(): void
    {
        $rendered = PCM_Optimizer_Service::render_prompt_vars(
            'Known: {{x}}. Unknown: {{typo}}.',
            array('x' => 'value')
        );
        $this->assertSame('Known: value. Unknown: {{typo}}.', $rendered);
    }

    public function test_render_prompt_vars_empty_value_is_a_clean_removal(): void
    {
        // The compile()/interlink()/subtopics() conditional-fragment pattern:
        // an empty string var must vanish cleanly, not leave stray whitespace
        // markers behind.
        $rendered = PCM_Optimizer_Service::render_prompt_vars(
            'targets {{gsc_preference_note}}maximum 6 proposals',
            array('gsc_preference_note' => '')
        );
        $this->assertSame('targets maximum 6 proposals', $rendered);
    }
}
