<?php
/**
 * PCM Access — team access grants via delivery assignments.
 *
 * An admin assigns a delivery to a PCM user (wp_pcm_delivery_assignments).
 * Through the assignment the assignee is GRANTED view+use access to:
 *   - the delivery itself,
 *   - the delivery's linked brand (deliveries.brandId),
 *   - the delivery's linked project (deliveries.projectId).
 *
 * Read queries become "owned OR granted" (see PCM_DB::get_user_brands etc.);
 * write queries stay owner-scoped, so assignees can use but not mutate the
 * owner's records.
 *
 * Results are memoized per request — the grant set cannot change mid-request.
 *
 * @package PowerCreatives
 * @since   1.18.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class PCM_Access
{
    /** @var array<string, int[]> Per-request memo, keyed by "kind:userId". */
    private static array $memo = array();

    /**
     * Delivery ids assigned to the user (not owned — granted).
     *
     * @param int $user_id PCM user id.
     * @return int[]
     */
    public static function granted_delivery_ids(int $user_id): array
    {
        if ($user_id <= 0) {
            return array();
        }
        $key = 'deliveries:' . $user_id;
        if (!isset(self::$memo[$key])) {
            global $wpdb;
            $table = PCM_Schema::table('delivery_assignments');
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $ids = $wpdb->get_col($wpdb->prepare(
                "SELECT deliveryId FROM {$table} WHERE userId = %d",
                $user_id
            ));
            self::$memo[$key] = array_map('intval', $ids ?: array());
        }
        return self::$memo[$key];
    }

    /**
     * Brand ids granted to the user via assigned deliveries.
     *
     * @param int $user_id PCM user id.
     * @return int[]
     */
    public static function granted_brand_ids(int $user_id): array
    {
        return self::granted_linked_ids($user_id, 'brandId');
    }

    /**
     * Project ids granted to the user via assigned deliveries.
     *
     * @param int $user_id PCM user id.
     * @return int[]
     */
    public static function granted_project_ids(int $user_id): array
    {
        return self::granted_linked_ids($user_id, 'projectId');
    }

    /**
     * Build an "(owned OR granted)" WHERE fragment for a read query.
     * Returns array{sql: string, params: array} — the SQL contains exactly one
     * %d for the userId plus one %d per granted id, so it can be dropped into
     * $wpdb->prepare alongside the returned params.
     *
     * @param string $user_column Column holding the owner id (e.g. 'userId').
     * @param string $id_column   Primary-key column (e.g. 'id').
     * @param int    $user_id     PCM user id.
     * @param int[]  $granted_ids Granted ids for this resource kind.
     * @return array{sql: string, params: array}
     */
    public static function scope_clause(string $user_column, string $id_column, int $user_id, array $granted_ids): array
    {
        if (empty($granted_ids)) {
            return array('sql' => "{$user_column} = %d", 'params' => array($user_id));
        }
        $placeholders = implode(',', array_fill(0, count($granted_ids), '%d'));
        return array(
            'sql'    => "({$user_column} = %d OR {$id_column} IN ({$placeholders}))",
            'params' => array_merge(array($user_id), array_map('intval', $granted_ids)),
        );
    }

    /**
     * Distinct non-null linked ids (brandId/projectId) of assigned deliveries.
     *
     * @param int    $user_id PCM user id.
     * @param string $column  'brandId' | 'projectId' (internal whitelist).
     * @return int[]
     */
    private static function granted_linked_ids(int $user_id, string $column): array
    {
        if ($user_id <= 0 || !in_array($column, array('brandId', 'projectId'), true)) {
            return array();
        }
        $key = $column . ':' . $user_id;
        if (!isset(self::$memo[$key])) {
            global $wpdb;
            $assignments = PCM_Schema::table('delivery_assignments');
            $deliveries  = PCM_Schema::table('deliveries');
            // $column is whitelisted above — never user input.
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
            $ids = $wpdb->get_col($wpdb->prepare(
                "SELECT DISTINCT d.{$column}
                 FROM {$assignments} a
                 INNER JOIN {$deliveries} d ON d.id = a.deliveryId
                 WHERE a.userId = %d AND d.{$column} IS NOT NULL",
                $user_id
            ));
            self::$memo[$key] = array_map('intval', $ids ?: array());
        }
        return self::$memo[$key];
    }

    /**
     * Module ids granted to the user — the union of the `modules` JSON across
     * their assigned deliveries (stored by frontend nav id, e.g. 'copy','ads').
     *
     * @param int $user_id PCM user id.
     * @return string[]
     */
    public static function granted_module_ids(int $user_id): array
    {
        if ($user_id <= 0) {
            return array();
        }
        $key = 'modules:' . $user_id;
        if (!isset(self::$memo[$key])) {
            global $wpdb;
            $assignments = PCM_Schema::table('delivery_assignments');
            $deliveries  = PCM_Schema::table('deliveries');
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $blobs = $wpdb->get_col($wpdb->prepare(
                "SELECT d.modules
                 FROM {$assignments} a
                 INNER JOIN {$deliveries} d ON d.id = a.deliveryId
                 WHERE a.userId = %d AND d.modules IS NOT NULL",
                $user_id
            ));
            $union = array();
            foreach (($blobs ?: array()) as $blob) {
                $list = json_decode((string) $blob, true);
                if (is_array($list)) {
                    foreach ($list as $module) {
                        $union[(string) $module] = true;
                    }
                }
            }
            self::$memo[$key] = array_keys($union);
        }
        return self::$memo[$key];
    }

    /**
     * Brand ids granted to the user via assigned deliveries whose `modules`
     * JSON intersects the given module keys. Tightens the flat
     * granted_brand_ids union: a brand is only usable INSIDE the modules of
     * the delivery that granted it.
     *
     * @param int      $user_id     PCM user id.
     * @param string[] $module_keys Module nav ids (e.g. a controller's grant keys).
     * @return int[]
     */
    public static function granted_brand_ids_for_modules(int $user_id, array $module_keys): array
    {
        $module_keys = array_values(array_unique(array_map('strval', $module_keys)));
        if ($user_id <= 0 || empty($module_keys)) {
            return array();
        }
        sort($module_keys);
        $key = 'brandsformods:' . $user_id . ':' . implode(',', $module_keys);
        if (!isset(self::$memo[$key])) {
            global $wpdb;
            $assignments = PCM_Schema::table('delivery_assignments');
            $deliveries  = PCM_Schema::table('deliveries');
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT d.brandId, d.modules
                 FROM {$assignments} a
                 INNER JOIN {$deliveries} d ON d.id = a.deliveryId
                 WHERE a.userId = %d AND d.brandId IS NOT NULL AND d.modules IS NOT NULL",
                $user_id
            ));
            $brand_ids = array();
            foreach (($rows ?: array()) as $row) {
                $list = json_decode((string) $row->modules, true);
                if (is_array($list) && array_intersect($module_keys, array_map('strval', $list))) {
                    $brand_ids[(int) $row->brandId] = true;
                }
            }
            self::$memo[$key] = array_keys($brand_ids);
        }
        return self::$memo[$key];
    }

    /**
     * Map of granted module id → brand ids usable inside that module:
     * module-scoped grants plus the user's OWN brands (owned brands are
     * always usable — mirrors the base controller's check_module_brand).
     * Powers the frontend's brand-picker filtering
     * (pcmConfig.user.brandsByModule).
     *
     * @param int $user_id PCM user id.
     * @return array<string, int[]>
     */
    public static function brands_by_module(int $user_id): array
    {
        $owned = array();
        if ($user_id > 0) {
            global $wpdb;
            $brands = PCM_Schema::table('brands');
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $owned = array_map('intval', $wpdb->get_col($wpdb->prepare(
                "SELECT id FROM {$brands} WHERE userId = %d",
                $user_id
            )) ?: array());
        }
        $map = array();
        foreach (self::granted_module_ids($user_id) as $module) {
            $granted = self::granted_brand_ids_for_modules($user_id, array($module));
            $map[$module] = array_values(array_unique(array_merge($granted, $owned)));
        }
        return $map;
    }

    /**
     * Whether the PCM user has the in-plugin 'admin' level (mirrors WP
     * manage_options via the role-sync in get_current_pcm_user).
     *
     * @param int $user_id PCM user id.
     * @return bool
     */
    public static function is_admin(int $user_id): bool
    {
        if ($user_id <= 0) {
            return false;
        }
        $key = 'isadmin:' . $user_id;
        if (!isset(self::$memo[$key])) {
            global $wpdb;
            $users = PCM_Schema::table('users');
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $role = $wpdb->get_var($wpdb->prepare(
                "SELECT role FROM {$users} WHERE id = %d",
                $user_id
            ));
            // Admin status is driven by the stored role, for BOTH kinds of user:
            //  - WP users: get_current_pcm_user() reconciles their role to their
            //    live `manage_options` capability on every request, so it stays honest.
            //  - Platform (gate-login) users: role is set ONLY by the admin-gated
            //    Users routes (create_user / set_role) — a normal user can never set
            //    their own role — so a stored 'admin' is a deliberate grant by an
            //    existing admin (multi-admin / co-admin support). Trusting it here is
            //    intentional: a platform admin gets the same team-wide oversight
            //    (all brands/deliveries/approvals) a WP admin has.
            $is_admin = ((string) $role) === 'admin';
            self::$memo[$key] = array($is_admin);
        }
        return self::$memo[$key][0];
    }

    /**
     * Default project for a non-admin's generated output: the projectId of the
     * assigned delivery matching the brand being worked on; with no brand (or
     * no match), the single assigned delivery's project. Null when ambiguous
     * or nothing is assigned — callers leave projectId unset in that case.
     *
     * @param int      $user_id  PCM user id (assignee).
     * @param int|null $brand_id Brand in use, when known.
     * @return int|null
     */
    public static function auto_project_id(int $user_id, ?int $brand_id = null): ?int
    {
        if ($user_id <= 0) {
            return null;
        }
        global $wpdb;
        $assignments = PCM_Schema::table('delivery_assignments');
        $deliveries  = PCM_Schema::table('deliveries');

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT d.brandId, d.projectId
             FROM {$assignments} a
             INNER JOIN {$deliveries} d ON d.id = a.deliveryId
             WHERE a.userId = %d AND d.projectId IS NOT NULL
             ORDER BY d.updatedAt DESC, d.id DESC",
            $user_id
        ));
        if (empty($rows)) {
            return null;
        }
        if ($brand_id !== null && $brand_id > 0) {
            foreach ($rows as $row) {
                if ((int) $row->brandId === $brand_id) {
                    return (int) $row->projectId;
                }
            }
        }
        // No brand context: only safe when exactly one assigned project exists.
        $project_ids = array_unique(array_map(static fn($r) => (int) $r->projectId, $rows));
        return count($project_ids) === 1 ? $project_ids[0] : null;
    }

    /**
     * THE WORKSPACE API KEY for a provider — the one place credentials resolve.
     *
     * Integrations are stored per pcm-user. That was fine when every visitor
     * shared one workspace row, but platform (id/pass) users are now each their
     * OWN user row, so a user the admin creates owns no integrations and every
     * AI feature failed for them with "No active API key found" (owner report
     * 2026-07-31 — integrations visible in wp-admin, empty under a platform login).
     *
     * API keys belong to the BUSINESS, not the individual, so resolution falls
     * back across the workspace (owner decision 2026-07-31):
     *   1. the caller's OWN key for that provider — a user who added their own
     *      key keeps using it, so nothing that worked before changes;
     *   2. otherwise an ADMIN's key — the workspace's shared credential.
     * Newest `updatedAt` breaks ties within a tier.
     *
     * Only `isActive = 1` rows are eligible, matching every previous lookup.
     * Not memoized: a key can be rotated mid-request and callers must not cache
     * a revoked credential.
     *
     * @param string $provider Provider slug, e.g. 'openai', 'ahrefs', 'apify'.
     * @param int    $user_id  PCM user id (wp_pcm_users.id), NOT the WP user id.
     * @return string|null The key, or null when the workspace has none.
     */
    public static function workspace_api_key(string $provider, int $user_id): ?string
    {
        if ($provider === '') {
            return null;
        }
        global $wpdb;
        $integrations = PCM_Schema::table('integrations');
        $users        = PCM_Schema::table('users');
        // ORDER BY encodes the fallback: own key first, then an admin's.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $key = $wpdb->get_var($wpdb->prepare(
            "SELECT i.apiKey
               FROM {$integrations} i
               LEFT JOIN {$users} u ON u.id = i.userId
              WHERE i.provider = %s AND i.isActive = 1
           ORDER BY (i.userId = %d) DESC, (u.role = 'admin') DESC, i.updatedAt DESC
              LIMIT 1",
            $provider,
            $user_id
        ));
        return (is_string($key) && $key !== '') ? $key : null;
    }

    /** Test helper: clear the per-request memo. */
    public static function reset_memo(): void
    {
        self::$memo = array();
    }
}
