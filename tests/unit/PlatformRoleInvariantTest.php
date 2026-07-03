<?php
/**
 * Security invariant — a PLATFORM (gate-login) user only ever becomes an admin
 * on purpose.
 *
 * `PCM_Access::is_admin()` trusts the `role` column, which unlocks team-wide
 * oversight. That is only safe because a gate user's `role` is writable through
 * exactly a few sanctioned paths — every one of which either derives the role
 * from a real WordPress capability (WP-mirrored users) or whitelists it to
 * {admin,user} behind an admin-gated Users route. If any OTHER code ever wrote a
 * `role` onto the users table (a login-time upsert, a self-service profile save,
 * a webhook), a normal platform user could silently gain admin — the exact hole
 * that was closed. This test is a tripwire: it fails if a new writer appears, or
 * if the sanctioned writers drop their whitelist / capability derivation.
 *
 * @package PowerCreatives\Tests\Unit
 */

use WP_Mock\Tools\TestCase;

class PlatformRoleInvariantTest extends TestCase
{
    /** Files allowed to write the users-table `role` column, and why each is safe. */
    private const ROLE_WRITER_ALLOWLIST = array(
        // WP-user mirror seed — role derived from user_can('manage_options').
        'includes/class-pcm-activator.php',
        // Per-request WP role-sync — role derived from current_user_can('manage_options').
        'includes/core/base-controller.php',
        // create_platform_user (whitelist) + upsert_user (WP mirror).
        'includes/core/db/class-pcm-db.php',
        // create_user + set_role — both whitelist role, both behind admin-gated routes.
        'includes/modules/users/service.php',
    );

    /**
     * No file outside the allowlist writes the users-table `role` column. A
     * "users-table role write" = a file that both references the users table AND
     * assigns a `role` array key (an insert/update payload). LLM chat-message
     * roles and brand-asset roles don't reference the users table, so they're
     * excluded automatically.
     */
    public function test_only_sanctioned_files_write_the_users_table_role(): void
    {
        $root    = rtrim(PCM_PLUGIN_DIR, '/');
        $flagged = array();

        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root . '/includes', FilesystemIterator::SKIP_DOTS)
        );
        foreach ($it as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $src = (string) file_get_contents($file->getPathname());
            // Matches PCM_Schema::table('users'), self::t('users'), $x->table('users').
            $references_users_table = preg_match("/(?:table|t)\\('users'\\)/", $src) === 1;
            $writes_role_key        = preg_match('/[\'"]role[\'"]\s*=>/', $src) === 1;
            if ($references_users_table && $writes_role_key) {
                $flagged[] = str_replace($root . '/', '', $file->getPathname());
            }
        }
        sort($flagged);
        $allow = self::ROLE_WRITER_ALLOWLIST;
        sort($allow);

        $unexpected = array_values(array_diff($flagged, $allow));
        $this->assertSame(
            array(),
            $unexpected,
            "A file outside the allowlist writes the users-table `role`. If this is a NEW, "
            . "capability-derived or admin-gated writer, add it to ROLE_WRITER_ALLOWLIST after "
            . "confirming a normal platform user can't reach it. Offenders: " . implode(', ', $unexpected)
        );
        // And the allowlist stays honest — every entry still writes a role (no dead entries).
        $this->assertSame($allow, array_values(array_intersect($allow, $flagged)));
    }

    /** create_platform_user() whitelists role to {admin,user} — a caller can't inject another value. */
    public function test_create_platform_user_whitelists_role(): void
    {
        $src = (string) file_get_contents(PCM_PLUGIN_DIR . 'includes/core/db/class-pcm-db.php');
        $this->assertMatchesRegularExpression(
            "/in_array\\(\\(\\\$data\\['role'\\] \\?\\? 'user'\\), array\\('admin', 'user'\\), true\\)/",
            $src,
            'create_platform_user() must whitelist role to {admin,user}.'
        );
    }

    /** set_role() whitelists role to {admin,user} and rejects WP-mirrored (username-null) rows. */
    public function test_set_role_whitelists_role_and_rejects_wp_users(): void
    {
        $src = (string) file_get_contents(PCM_PLUGIN_DIR . 'includes/modules/users/service.php');
        $this->assertStringContainsString("in_array(\$role, array('admin', 'user'), true)", $src, 'set_role() must whitelist role.');
        $this->assertStringContainsString('empty($user->username)', $src, 'set_role() must reject WP-mirrored (username-null) users.');
    }

    /** The gate login/auth layer never writes a role — a login can't self-promote. */
    public function test_gate_auth_never_writes_a_role(): void
    {
        $src = (string) file_get_contents(PCM_PLUGIN_DIR . 'includes/core/class-pcm-gate-auth.php');
        $this->assertDoesNotMatchRegularExpression(
            '/[\'"]role[\'"]\s*=>/',
            $src,
            'The gate auth layer must never write a role (login only authenticates + sets the cookie).'
        );
    }
}
