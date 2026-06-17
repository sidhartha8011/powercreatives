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

    // ── Mirror decision (connector tenant → publishable wp_pcm_sites row) ──
    // Pure logic only; the upsert + encryption are DB-bound and verified live.

    public function test_mirror_row_builds_a_connector_site(): void
    {
        $row = PCM_SEOHub_Service::mirror_row(7, 'Acme', 'https://acme.test/', 'editor', 'ENC');
        $this->assertSame(7, $row['userId']);
        $this->assertSame('Acme', $row['name']);
        $this->assertSame('https://acme.test', $row['url']); // trailing slash trimmed
        $this->assertSame('editor', $row['username']);
        $this->assertSame('ENC', $row['appPassword']);
        $this->assertSame('active', $row['status']);
        $this->assertSame('connector', $row['connectMethod']);
    }

    public function test_mirror_row_falls_back_to_url_for_blank_name(): void
    {
        $row = PCM_SEOHub_Service::mirror_row(7, '', 'https://acme.test', 'editor', 'ENC');
        $this->assertSame('https://acme.test', $row['name']);
    }

    public function test_mirror_row_returns_null_when_not_publishable(): void
    {
        // Missing owner, url, username, or password → not a usable site.
        $this->assertNull(PCM_SEOHub_Service::mirror_row(0, 'Acme', 'https://acme.test', 'editor', 'ENC'));
        $this->assertNull(PCM_SEOHub_Service::mirror_row(7, 'Acme', '', 'editor', 'ENC'));
        $this->assertNull(PCM_SEOHub_Service::mirror_row(7, 'Acme', 'https://acme.test', '', 'ENC'));
        $this->assertNull(PCM_SEOHub_Service::mirror_row(7, 'Acme', 'https://acme.test', 'editor', ''));
    }
}
