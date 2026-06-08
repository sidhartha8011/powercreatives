<?php
/**
 * Unit Tests — Automation input mapping (the cross-module glue).
 *
 * Pure string substitution, no WordPress functions involved.
 *
 * @package PowerCreatives\Tests\Unit
 */

use WP_Mock\Tools\TestCase;

class AutomationMappingTest extends TestCase
{
    public function test_lone_placeholder_returns_raw_typed_value(): void
    {
        $out = PCM_Automation_Mapping::resolve(array('x' => '{{name}}'), array('name' => 'Acme'));
        $this->assertSame('Acme', $out['x']);

        // A lone placeholder preserves the raw (typed) value — ints stay ints.
        $out2 = PCM_Automation_Mapping::resolve(array('id' => '{{setId}}'), array('setId' => 5));
        $this->assertSame(5, $out2['id']);
    }

    public function test_context_prefix_is_supported(): void
    {
        $out = PCM_Automation_Mapping::resolve(array('x' => '{{context.link}}'), array('link' => 'https://x/y'));
        $this->assertSame('https://x/y', $out['x']);
    }

    public function test_inline_interpolation(): void
    {
        $out = PCM_Automation_Mapping::resolve(
            array('msg' => 'Ad for {{name}} — {{link}}'),
            array('name' => 'Acme', 'link' => 'https://x')
        );
        $this->assertSame('Ad for Acme — https://x', $out['msg']);
    }

    public function test_unknown_placeholder_resolves_to_empty(): void
    {
        $out = PCM_Automation_Mapping::resolve(array('x' => 'pre {{missing}} post'), array());
        $this->assertSame('pre  post', $out['x']);
    }

    public function test_literal_passthrough(): void
    {
        $out = PCM_Automation_Mapping::resolve(array('x' => 'plain text'), array('name' => 'Acme'));
        $this->assertSame('plain text', $out['x']);
    }

    public function test_empty_mapping_returns_empty(): void
    {
        $this->assertSame(array(), PCM_Automation_Mapping::resolve(array(), array('name' => 'Acme')));
    }
}
