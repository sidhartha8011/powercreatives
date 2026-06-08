<?php
/**
 * Unit Tests — Webhook Action Handler (cross-module action executor).
 *
 * Asserts the handler builds the correct payload (default vs mapping-driven) and
 * dispatches via the webhook channel. wp_remote_post is mocked — no network.
 *
 * @package PowerCreatives\Tests\Unit
 */

use WP_Mock\Tools\TestCase;

class AutomationHandlerTest extends TestCase
{
    public function test_id_and_mode(): void
    {
        $h = new PCM_Webhook_Action_Handler();
        $this->assertSame('webhook', $h->id());
        $this->assertSame('sync', $h->mode());
    }

    private function mock_transport(&$captured): void
    {
        WP_Mock::passthruFunction('esc_url_raw');
        WP_Mock::userFunction('current_time')->andReturn('2026-06-06T00:00:00+00:00');
        WP_Mock::userFunction('wp_json_encode')->andReturnUsing(fn($v) => json_encode($v));
        WP_Mock::userFunction('wp_remote_post')->andReturnUsing(function ($url, $args) use (&$captured) {
            $captured = array('url' => $url, 'args' => $args);
            return array();
        });
    }

    public function test_default_payload_is_name_and_link(): void
    {
        $captured = null;
        $this->mock_transport($captured);

        $context = array(
            'event'  => 'approvals.set_status_changed',
            'name'   => 'Acme Set',
            'link'   => 'https://site/?pcm_public_token=abc',
            'status' => 'launch',
            'setId'  => 7,
        );

        $result = (new PCM_Webhook_Action_Handler())->run(
            array('url' => 'https://hooks.example.com/x', 'secret' => 'topsecret'),
            array(),       // no mapping → default payload
            $context,
            1
        );

        $this->assertTrue($result['ok']);
        $this->assertSame('https://hooks.example.com/x', $result['target']);

        $body = json_decode($captured['args']['body'], true);
        $this->assertSame('approvals.set_status_changed', $body['event']);
        $this->assertSame('Acme Set', $body['name']);
        $this->assertSame('https://site/?pcm_public_token=abc', $body['link']);
        $this->assertSame('launch', $body['status']);
        $this->assertSame(7, $body['setId']);
    }

    public function test_mapping_inputs_become_the_payload(): void
    {
        $captured = null;
        $this->mock_transport($captured);

        $context = array('event' => 'approvals.set_status_changed', 'name' => 'Acme', 'link' => 'https://x');
        $inputs  = array('prompt' => 'Ad for Acme', 'foo' => 'bar');

        (new PCM_Webhook_Action_Handler())->run(
            array('url' => 'https://hooks.example.com/x', 'secret' => 's'),
            $inputs,
            $context,
            1
        );

        $body = json_decode($captured['args']['body'], true);
        $this->assertSame('Ad for Acme', $body['prompt']);
        $this->assertSame('bar', $body['foo']);
        $this->assertSame('approvals.set_status_changed', $body['event']);
        // Mapping inputs replace the default payload — no implicit 'name' key.
        $this->assertArrayNotHasKey('name', $body);
    }
}
