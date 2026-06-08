<?php
/**
 * Unit Tests — Approvals service decision logic.
 *
 * Exercises the pure rule helpers that drive the approval flow (asset
 * bucketing, full-approval detection, dedupe keys, attachment sanitization)
 * via reflection, without touching the database.
 *
 * @package PowerCreatives\Tests\Unit
 */

use WP_Mock\Tools\TestCase;

class ApprovalsLogicTest extends TestCase
{
    /**
     * Invoke a private static method on PCM_Approvals_Service.
     *
     * @param string $method Method name.
     * @param mixed  ...$args Arguments.
     * @return mixed
     */
    private function invoke(string $method, ...$args)
    {
        $ref = new ReflectionMethod(PCM_Approvals_Service::class, $method);
        $ref->setAccessible(true);
        return $ref->invoke(null, ...$args);
    }

    private function snapshot(): array
    {
        return array(
            'media'    => array(array('id' => 'm1'), array('id' => 'm2')),
            'copy'     => array(array('id' => 'c1')),
            'articles' => array(),
        );
    }

    // ── bucket_for_asset ────────────────────────────────────────────────

    public function test_bucket_for_asset_maps_to_correct_bucket(): void
    {
        $snap = $this->snapshot();
        $this->assertSame('approvedVisualIds', $this->invoke('bucket_for_asset', $snap, 'm1'));
        $this->assertSame('approvedCopyIds', $this->invoke('bucket_for_asset', $snap, 'c1'));
        $this->assertNull($this->invoke('bucket_for_asset', $snap, 'does-not-exist'));
    }

    // ── collect_ids ─────────────────────────────────────────────────────

    public function test_collect_ids_returns_all_ids_for_bucket(): void
    {
        $this->assertSame(array('m1', 'm2'), $this->invoke('collect_ids', $this->snapshot(), 'media'));
        $this->assertSame(array('c1'), $this->invoke('collect_ids', $this->snapshot(), 'copy'));
        $this->assertSame(array(), $this->invoke('collect_ids', $this->snapshot(), 'articles'));
    }

    // ── is_fully_approved ───────────────────────────────────────────────

    public function test_is_fully_approved_true_when_every_asset_approved(): void
    {
        $feedback = array(
            'approvedVisualIds'  => array('m1', 'm2'),
            'approvedCopyIds'    => array('c1'),
            'approvedArticleIds' => array(),
        );
        $this->assertTrue($this->invoke('is_fully_approved', $this->snapshot(), $feedback));
    }

    public function test_is_fully_approved_false_when_one_asset_missing(): void
    {
        $feedback = array(
            'approvedVisualIds'  => array('m1'), // missing m2
            'approvedCopyIds'    => array('c1'),
            'approvedArticleIds' => array(),
        );
        $this->assertFalse($this->invoke('is_fully_approved', $this->snapshot(), $feedback));
    }

    public function test_is_fully_approved_false_for_empty_set(): void
    {
        $empty = array('media' => array(), 'copy' => array(), 'articles' => array());
        $feedback = array('approvedVisualIds' => array(), 'approvedCopyIds' => array(), 'approvedArticleIds' => array());
        $this->assertFalse($this->invoke('is_fully_approved', $empty, $feedback));
    }

    // ── dedupe_key (engine) ─────────────────────────────────────────────

    /**
     * Invoke a private static method on PCM_Automation_Engine.
     *
     * @param string $method Method name.
     * @param mixed  ...$args Arguments.
     * @return mixed
     */
    private function invokeEngine(string $method, ...$args)
    {
        $ref = new ReflectionMethod(PCM_Automation_Engine::class, $method);
        $ref->setAccessible(true);
        return $ref->invoke(null, ...$args);
    }

    public function test_dedupe_key_for_all_approved(): void
    {
        $key = $this->invokeEngine('dedupe_key', PCM_Automation_Events::APPROVAL_ALL_APPROVED, array('setId' => 5));
        $this->assertSame('all_approved:set:5', $key);
    }

    public function test_dedupe_key_null_for_other_events(): void
    {
        $this->assertNull($this->invokeEngine('dedupe_key', PCM_Automation_Events::APPROVAL_COMPLETED, array('setId' => 5)));
        $this->assertNull($this->invokeEngine('dedupe_key', PCM_Automation_Events::APPROVAL_COMMENT_CREATED, array('setId' => 5)));
    }

    // ── sanitize_attachments ────────────────────────────────────────────

    public function test_sanitize_attachments_keeps_valid_drops_invalid(): void
    {
        WP_Mock::passthruFunction('esc_url_raw');
        WP_Mock::passthruFunction('sanitize_text_field');

        $input = array(
            array('url' => 'https://cdn.example.com/a.png', 'name' => 'a.png', 'type' => 'image/png'),
            array('name' => 'no-url-here'), // dropped: no url
            'not-an-array',                 // dropped
        );

        $result = $this->invoke('sanitize_attachments', $input);

        $this->assertCount(1, $result);
        $this->assertSame('https://cdn.example.com/a.png', $result[0]['url']);
        $this->assertSame('a.png', $result[0]['name']);
        $this->assertSame('image/png', $result[0]['type']);
    }
}
