<?php
/**
 * Unit Tests — SEO Hub HMAC signing (security-critical, pure).
 *
 * The full verify() path (tenant lookup, nonce replay, timestamp window) is
 * exercised live against WordPress; here we pin the signature algorithm.
 *
 * @package PowerCreatives\Tests\Unit
 */

use WP_Mock\Tools\TestCase;

class SeoHubTest extends TestCase
{
    public function test_hmac_sign_matches_canonical_string(): void
    {
        $ts = '1700000000';
        $nonce = 'abc-123';
        $body = '{"site_url":"https://x.test"}';
        $secret = 'topsecret';

        $expected = hash_hmac('sha256', $ts . '.' . $nonce . '.' . $body, $secret);
        $this->assertSame($expected, PCM_SEOHub_Service::hmac_sign($ts, $nonce, $body, $secret));
    }

    public function test_hmac_sign_is_sensitive_to_body_and_secret(): void
    {
        $base = PCM_SEOHub_Service::hmac_sign('1', 'n', 'body', 'secret');
        $this->assertNotSame($base, PCM_SEOHub_Service::hmac_sign('1', 'n', 'BODY', 'secret'));
        $this->assertNotSame($base, PCM_SEOHub_Service::hmac_sign('1', 'n', 'body', 'other'));
        $this->assertNotSame($base, PCM_SEOHub_Service::hmac_sign('1', 'N', 'body', 'secret'));
    }
}
