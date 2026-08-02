<?php
/**
 * Unit Tests — workspace-scoped API key resolution.
 *
 * Owner report 2026-07-31: integrations were visible in wp-admin but empty under
 * a platform (id/pass) login. Cause: integrations are stored per pcm-user, and a
 * platform user is their OWN user row, so they owned none — and because six
 * separate call sites resolved keys with `userId = %d` and no fallback, every AI
 * feature failed for them with "No active API key found", not just the list.
 *
 * Owner decision: API keys belong to the business, so resolution falls back
 * across the workspace — own key first, then an admin's. These pin BOTH halves:
 * the fallback works, and it never overrides a key the caller set themselves.
 *
 * @package PowerCreatives\Tests\Unit
 */

use WP_Mock\Tools\TestCase;

class WorkspaceApiKeyTest extends TestCase
{
    /** Capture the SQL PCM_Access::workspace_api_key builds, and hand back $return. */
    private function runQuery(string $provider, int $user_id, $return): string
    {
        global $wpdb;
        $captured = '';
        $wpdb = \Mockery::mock();
        $wpdb->prefix = 'wp_';
        $wpdb->shouldReceive('prepare')->andReturnUsing(function ($sql, ...$args) use (&$captured) {
            $captured = $sql;
            return $sql;
        });
        $wpdb->shouldReceive('get_var')->andReturn($return);
        PCM_Access::workspace_api_key($provider, $user_id);
        return $captured;
    }

    public function test_returns_the_resolved_key(): void
    {
        global $wpdb;
        $wpdb = \Mockery::mock();
        $wpdb->prefix = 'wp_';
        $wpdb->shouldReceive('prepare')->andReturn('SQL');
        $wpdb->shouldReceive('get_var')->andReturn('sk-workspace-key');

        $this->assertSame('sk-workspace-key', PCM_Access::workspace_api_key('openai', 42));
    }

    public function test_own_key_is_preferred_over_an_admins(): void
    {
        // The precedence IS the ORDER BY: the caller's own row sorts first, an
        // admin's second. Without the first term a user's own key would lose to
        // the admin's — silently switching which account gets billed.
        $sql = $this->runQuery('openai', 42, 'k');
        $this->assertMatchesRegularExpression(
            '/ORDER BY\s+\(i\.userId = %d\) DESC,\s*\(u\.role = \'admin\'\) DESC/',
            preg_replace('/\s+/', ' ', $sql)
        );
    }

    public function test_only_active_integrations_are_eligible(): void
    {
        // A revoked key must never be resolved — same guard every previous
        // per-user lookup had.
        $this->assertStringContainsString('isActive = 1', $this->runQuery('openai', 42, 'k'));
    }

    public function test_missing_key_returns_null_not_empty_string(): void
    {
        global $wpdb;
        $wpdb = \Mockery::mock();
        $wpdb->prefix = 'wp_';
        $wpdb->shouldReceive('prepare')->andReturn('SQL');
        $wpdb->shouldReceive('get_var')->andReturn(null);

        // Callers branch on null (PCM_LLM throws its "add one in Integrations"
        // message); an empty string would read as a usable key downstream.
        $this->assertNull(PCM_Access::workspace_api_key('openai', 42));
    }

    public function test_blank_key_row_is_treated_as_missing(): void
    {
        global $wpdb;
        $wpdb = \Mockery::mock();
        $wpdb->prefix = 'wp_';
        $wpdb->shouldReceive('prepare')->andReturn('SQL');
        $wpdb->shouldReceive('get_var')->andReturn('');

        $this->assertNull(PCM_Access::workspace_api_key('openai', 42));
    }

    public function test_empty_provider_short_circuits_without_a_query(): void
    {
        global $wpdb;
        $wpdb = \Mockery::mock();
        $wpdb->shouldNotReceive('get_var');

        $this->assertNull(PCM_Access::workspace_api_key('', 42));
    }
}
