<?php
/**
 * Unit Tests — Email Action Handler (cross-module action via Brevo).
 *
 * Asserts it builds context['email'] and dispatches via the Brevo channel.
 * wp_remote_post + $wpdb (Brevo key lookup) are mocked — no network/DB.
 *
 * @package PowerCreatives\Tests\Unit
 */

use WP_Mock\Tools\TestCase;

class AutomationEmailHandlerTest extends TestCase
{
    public function setUp(): void
    {
        parent::setUp();
        WP_Mock::passthruFunction('sanitize_email');
        WP_Mock::passthruFunction('sanitize_text_field');
        WP_Mock::passthruFunction('esc_html');
        WP_Mock::userFunction('is_email')->andReturnUsing(fn($e) => (bool) filter_var($e, FILTER_VALIDATE_EMAIL));
        WP_Mock::userFunction('__')->andReturnUsing(fn($s) => $s);
        // The Brevo channel reads the per-module from-sender via PCM_Settings::get()
        // → get_option(). Return an empty option so all settings fall to defaults and
        // the handler's explicit fromEmail/fromName inputs win. (Without this the test
        // only passed by borrowing a get_option mock leaked from an earlier test.)
        WP_Mock::userFunction('get_option')->andReturn(array());
        // nl2br is a native PHP function — used as-is (not mocked).
    }

    private function mock_wpdb($api_key): void
    {
        $wpdb = Mockery::mock();
        $wpdb->prefix = 'wp_';
        $wpdb->shouldReceive('prepare')->andReturnUsing(fn($q) => $q);
        $wpdb->shouldReceive('get_var')->andReturn($api_key);
        $GLOBALS['wpdb'] = $wpdb;
    }

    public function test_id_and_mode(): void
    {
        $h = new PCM_Email_Action_Handler();
        $this->assertSame('email.send', $h->id());
        $this->assertSame('sync', $h->mode());
    }

    public function test_skips_when_no_recipient(): void
    {
        $h = new PCM_Email_Action_Handler();
        $result = $h->run(array(), array('subject' => 'Hi', 'message' => 'x'), array('event' => 'brands.brand_created'), 1);
        $this->assertTrue($result['skipped']);
        $this->assertFalse($result['ok']);
    }

    public function test_falls_back_to_context_client_email(): void
    {
        $this->mock_wpdb('brevo-key');
        $captured = null;
        WP_Mock::userFunction('wp_json_encode')->andReturnUsing(fn($v) => json_encode($v));
        WP_Mock::userFunction('is_wp_error')->andReturn(false);
        WP_Mock::userFunction('wp_remote_retrieve_response_code')->andReturn(201);
        WP_Mock::userFunction('wp_remote_post')->andReturnUsing(function ($url, $args) use (&$captured) {
            $captured = array('url' => $url, 'args' => $args);
            return array('ok');
        });

        $h = new PCM_Email_Action_Handler();
        // No 'to' input → must fall back to context clientEmail.
        $result = $h->run(
            array('fromEmail' => 'team@agency.com', 'fromName' => 'Agency'),
            array('subject' => 'Approved', 'message' => 'All set'),
            array('event' => 'approvals.set_status_changed', 'clientEmail' => 'client@example.com'),
            1
        );

        $this->assertTrue($result['ok']);
        $body = json_decode($captured['args']['body'], true);
        $this->assertSame('client@example.com', $body['to'][0]['email']);
        $this->assertSame('Approved', $body['subject']);
        $this->assertStringContainsString('All set', $body['htmlContent']);
        $this->assertSame('team@agency.com', $body['sender']['email']);
    }

    public function test_missing_brevo_key_is_failed_not_skipped(): void
    {
        $this->mock_wpdb(null); // no key
        WP_Mock::userFunction('wp_json_encode')->andReturnUsing(fn($v) => json_encode($v));

        $h = new PCM_Email_Action_Handler();
        $result = $h->run(
            array('fromEmail' => 'team@agency.com'),
            array('to' => 'client@example.com', 'subject' => 'Hi', 'message' => 'x'),
            array('event' => 'brands.brand_created'),
            1
        );

        $this->assertFalse($result['ok']);
        $this->assertFalse($result['skipped']);
        $this->assertStringContainsString('Brevo', (string) $result['error']);
    }

    public function tearDown(): void
    {
        unset($GLOBALS['wpdb']);
        parent::tearDown();
    }
}
