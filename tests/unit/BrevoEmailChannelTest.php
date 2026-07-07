<?php
/**
 * Unit Tests — Brevo Email Channel.
 *
 * Verifies the Brevo API request shape, the api-key header, and graceful
 * handling of missing recipient / missing key. wp_remote_post and $wpdb are
 * mocked, so no real network or DB access occurs.
 *
 * @package PowerCreatives\Tests\Unit
 */

use WP_Mock\Tools\TestCase;

class BrevoEmailChannelTest extends TestCase
{
    public function setUp(): void
    {
        parent::setUp();
        WP_Mock::passthruFunction('sanitize_email');
        WP_Mock::passthruFunction('sanitize_text_field');
        WP_Mock::userFunction('is_email')->andReturnUsing(
            fn($e) => (bool) filter_var($e, FILTER_VALIDATE_EMAIL)
        );
        // The Brevo channel reads the per-module from-sender via PCM_Settings::get()
        // → get_option(). Return an empty option so settings fall to defaults; without
        // this the test only passed by borrowing a get_option mock leaked from another
        // test, so it errored intermittently depending on test order.
        WP_Mock::userFunction('get_option')->andReturn(array());
    }

    /** Build a mocked $wpdb that returns a given Brevo key. */
    private function mock_wpdb($api_key): void
    {
        $wpdb = Mockery::mock();
        $wpdb->prefix = 'wp_';
        $wpdb->shouldReceive('prepare')->andReturnUsing(fn($q) => $q);
        $wpdb->shouldReceive('get_var')->andReturn($api_key);
        $GLOBALS['wpdb'] = $wpdb;
    }

    public function test_send_skips_when_no_recipient(): void
    {
        $channel = new PCM_Brevo_Email_Channel();
        $result  = $channel->send(array(), array('event' => 'approval.set.shared'), 1);

        $this->assertTrue($result['skipped']);
        $this->assertFalse($result['ok']);
    }

    public function test_send_fails_gracefully_when_no_api_key(): void
    {
        $this->mock_wpdb(null);

        $channel = new PCM_Brevo_Email_Channel();
        $context = array(
            'event' => 'approval.set.shared',
            'email' => array('to' => 'client@example.com', 'subject' => 'Hi', 'html' => '<p>x</p>'),
        );
        $result = $channel->send(array('fromEmail' => 'team@agency.com'), $context, 1);

        $this->assertFalse($result['ok']);
        $this->assertFalse($result['skipped']);
        $this->assertStringContainsString('Brevo', (string) $result['error']);
    }

    public function test_send_posts_correct_brevo_payload(): void
    {
        $this->mock_wpdb('brevo-secret-key');

        $captured = null;
        WP_Mock::userFunction('wp_json_encode')->andReturnUsing(fn($v) => json_encode($v));
        WP_Mock::userFunction('is_wp_error')->andReturn(false);
        WP_Mock::userFunction('wp_remote_retrieve_response_code')->andReturn(201);
        WP_Mock::userFunction('wp_remote_post')->andReturnUsing(function ($url, $args) use (&$captured) {
            $captured = array('url' => $url, 'args' => $args);
            return array('ok');
        });

        $channel = new PCM_Brevo_Email_Channel();
        $context = array(
            'event' => 'approval.set.shared',
            'email' => array(
                'to'      => 'client@example.com',
                'subject' => 'Your creatives are ready',
                'html'    => '<p>Review now</p>',
            ),
        );
        $result = $channel->send(
            array('fromEmail' => 'team@agency.com', 'fromName' => 'Agency'),
            $context,
            1
        );

        $this->assertTrue($result['ok']);
        $this->assertSame(201, $result['code']);

        $this->assertSame(PCM_Brevo_Email_Channel::API_URL, $captured['url']);
        $this->assertSame('brevo-secret-key', $captured['args']['headers']['api-key']);

        $body = json_decode($captured['args']['body'], true);
        $this->assertSame('team@agency.com', $body['sender']['email']);
        $this->assertSame('Agency', $body['sender']['name']);
        $this->assertSame('client@example.com', $body['to'][0]['email']);
        $this->assertSame('Your creatives are ready', $body['subject']);
        $this->assertSame('<p>Review now</p>', $body['htmlContent']);
    }

    public function tearDown(): void
    {
        unset($GLOBALS['wpdb']);
        parent::tearDown();
    }
}
