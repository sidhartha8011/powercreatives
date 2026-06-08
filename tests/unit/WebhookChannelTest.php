<?php
/**
 * Unit Tests — Webhook Channel.
 *
 * Verifies HMAC signing and the outbound request shape. wp_remote_post is
 * mocked, so no real network traffic occurs.
 *
 * @package PowerCreatives\Tests\Unit
 */

use WP_Mock\Tools\TestCase;

class WebhookChannelTest extends TestCase
{
    public function test_build_signed_body_signs_with_hmac_sha256(): void
    {
        WP_Mock::userFunction('wp_json_encode')->andReturnUsing(fn($v) => json_encode($v));

        $payload = array('event' => 'approval.completed', 'setId' => 7);
        $secret  = 's3cret';

        $result = PCM_Webhook_Channel::build_signed_body($payload, $secret);

        $expected = 'sha256=' . hash_hmac('sha256', json_encode($payload), $secret);
        $this->assertSame($expected, $result['signature']);
        $this->assertSame(json_encode($payload), $result['body']);
    }

    public function test_build_signed_body_is_unsigned_without_secret(): void
    {
        WP_Mock::userFunction('wp_json_encode')->andReturnUsing(fn($v) => json_encode($v));

        $result = PCM_Webhook_Channel::build_signed_body(array('a' => 1), '');
        $this->assertNull($result['signature']);
    }

    public function test_send_skips_when_no_url(): void
    {
        WP_Mock::passthruFunction('esc_url_raw');

        $channel = new PCM_Webhook_Channel();
        $result  = $channel->send(array(), array('event' => 'approval.completed'), 1);

        $this->assertTrue($result['skipped']);
        $this->assertFalse($result['ok']);
    }

    public function test_send_posts_signed_payload(): void
    {
        $captured = null;

        WP_Mock::passthruFunction('esc_url_raw');
        WP_Mock::userFunction('current_time')->andReturn('2026-06-05T00:00:00+00:00');
        WP_Mock::userFunction('wp_json_encode')->andReturnUsing(fn($v) => json_encode($v));
        WP_Mock::userFunction('wp_remote_post')->andReturnUsing(function ($url, $args) use (&$captured) {
            $captured = array('url' => $url, 'args' => $args);
            return array();
        });

        $channel = new PCM_Webhook_Channel();
        $context = array('event' => 'approval.comment.created', 'setId' => 5, 'assetId' => 'a1');
        $result  = $channel->send(
            array('url' => 'https://hooks.example.com/x', 'secret' => 'topsecret'),
            $context,
            1
        );

        // Non-blocking fire-and-forget → reported as ok.
        $this->assertTrue($result['ok']);
        $this->assertSame('https://hooks.example.com/x', $result['target']);

        // Verify the captured request.
        $this->assertSame('https://hooks.example.com/x', $captured['url']);
        $this->assertSame('application/json', $captured['args']['headers']['Content-Type']);
        $this->assertSame('approval.comment.created', $captured['args']['headers']['X-PCM-Event']);

        $expected_payload = array_merge(
            array('event' => 'approval.comment.created'),
            $context,
            array('timestamp' => '2026-06-05T00:00:00+00:00')
        );
        $expected_sig = 'sha256=' . hash_hmac('sha256', json_encode($expected_payload), 'topsecret');

        $this->assertSame($expected_sig, $captured['args']['headers']['X-PCM-Signature']);
        $this->assertSame(json_encode($expected_payload), $captured['args']['body']);
        $this->assertFalse($captured['args']['blocking']);
    }
}
