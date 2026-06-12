<?php
/**
 * Database Abstraction Layer
 *
 * Provides typed CRUD methods for all plugin tables.
 * Replaces the standalone app's db.ts (53 functions).
 *
 * Each method uses $wpdb->prepare() for SQL injection prevention.
 * Includes WP Transients cache for frequently-read list queries
 * (models, integrations, brands, templates) with automatic
 * invalidation on all write operations.
 *
 * @package PowerCreatives
 */

if (!defined('ABSPATH')) {
    exit;
}

class PCM_DB
{

    /**
     * Cache TTL in seconds (5 minutes).
     * Balances freshness with DB load reduction.
     *
     * @var int
     */
    private const CACHE_TTL = 300;

    /**
     * Cache key prefix — ensures no collision with other plugins.
     *
     * @var string
     */
    private const CACHE_PREFIX = 'pcm_';

    // =========================================================================
    // CACHE HELPERS
    // =========================================================================

    /**
     * Build a per-user cache key for a table.
     *
     * @param string      $table   Short table name (models, brands, etc.).
     * @param int         $user_id User ID.
     * @param string|null $suffix  Optional suffix for query variants.
     *
     * @return string Transient key (max 172 chars for WP compatibility).
     */
    private static function cache_key(string $table, int $user_id, ?string $suffix = null): string
    {
        $key = self::CACHE_PREFIX . $table . '_u' . $user_id;
        if ($suffix) {
            $key .= '_' . $suffix;
        }
        return $key;
    }

    /**
     * Get a cached value or execute the callback and cache the result.
     *
     * @param string   $key      Transient key.
     * @param callable $callback Function that returns the data to cache.
     *
     * @return mixed Cached or fresh data.
     */
    private static function cached(string $key, callable $callback): mixed
    {
        $cached = get_transient($key);
        if (false !== $cached) {
            return $cached;
        }

        $result = $callback();
        set_transient($key, $result, self::CACHE_TTL);
        return $result;
    }

    /**
     * Invalidate all cached queries for a table + user.
     *
     * Deletes the primary list cache key. For tables with variant
     * queries (e.g. templates with module filter), also clears those.
     *
     * Public so cross-cutting features (e.g. delivery assignments granting
     * access) can bust a user's cached lists when their visible set changes.
     *
     * @param string $table   Short table name.
     * @param int    $user_id User ID.
     *
     * @return void
     */
    public static function invalidate(string $table, int $user_id): void
    {
        delete_transient(self::cache_key($table, $user_id));

        // Templates have module-filtered variants
        if ('templates' === $table) {
            foreach (array('copy', 'image', 'video', 'brands', 'scraper') as $module) {
                delete_transient(self::cache_key($table, $user_id, $module));
            }
        }
    }

    /**
     * Get a fully qualified table name.
     *
     * @param string $table Short name (e.g. 'users').
     * @return string Full table name with prefix.
     */
    private static function t(string $table): string
    {
        return PCM_Schema::table($table);
    }

    // =========================================================================
    // USERS
    // =========================================================================

    /**
     * Upsert a user by openId — insert if new, update if exists.
     *
     * Maps to: server/db.ts → upsertUser()
     *
     * @param array{openId: string, name?: string, email?: string, role?: string, avatarUrl?: string} $data User data.
     * @return int|false User ID on success, false on failure.
     */
    public static function upsert_user(array $data): int|false
    {
        global $wpdb;

        $table = self::t('users');
        $exists = $wpdb->get_var(
            $wpdb->prepare("SELECT id FROM {$table} WHERE openId = %s", $data['openId'])
        );

        if ($exists) {
            // Update existing user
            $update = array('lastSignedIn' => current_time('mysql'));
            if (!empty($data['name'])) {
                $update['name'] = $data['name'];
            }
            if (!empty($data['email'])) {
                $update['email'] = $data['email'];
            }
            if (!empty($data['avatarUrl'])) {
                $update['avatarUrl'] = $data['avatarUrl'];
            }

            $wpdb->update($table, $update, array('id' => $exists));
            return (int)$exists;
        }

        // Insert new user
        $wpdb->insert(
            $table,
            array(
            'openId' => $data['openId'],
            'name' => $data['name'] ?? null,
            'email' => $data['email'] ?? null,
            'role' => $data['role'] ?? 'user',
            'avatarUrl' => $data['avatarUrl'] ?? null,
        )
        );

        return $wpdb->insert_id ?: false;
    }

    /**
     * Get user by openId.
     *
     * @param string $open_id The user's openId.
     * @return object|null User row or null.
     */
    public static function get_user_by_open_id(string $open_id): ?object
    {
        global $wpdb;

        $table = self::t('users');
        return $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$table} WHERE openId = %s", $open_id)
        );
    }

    // =========================================================================
    // INTEGRATIONS
    // =========================================================================

    /**
     * Create a new integration.
     *
     * @param array $data Integration data.
     * @return int|false Insert ID or false.
     */
    public static function create_integration(array $data): int|false
    {
        global $wpdb;

        $result = $wpdb->insert(self::t('integrations'), $data);
        if ($result) {
            self::invalidate('integrations', (int)$data['userId']);
            return $wpdb->insert_id;
        }
        return false;
    }

    /**
     * Get all integrations for a user.
     *
     * @param int $user_id User ID.
     * @return array Array of integration objects.
     */
    public static function get_user_integrations(int $user_id): array
    {
        return self::cached(self::cache_key('integrations', $user_id), function () use ($user_id) {
            global $wpdb;
            $table = self::t('integrations');
            return $wpdb->get_results(
                $wpdb->prepare("SELECT * FROM {$table} WHERE userId = %d ORDER BY createdAt DESC", $user_id)
            );
        });
    }

    /**
     * Delete an integration by ID and user.
     *
     * @param int $id Integration ID.
     * @param int $user_id User ID (ownership check).
     * @return bool True on success.
     */
    public static function delete_integration(int $id, int $user_id): bool
    {
        global $wpdb;

        $rows = $wpdb->delete(
            self::t('integrations'),
            array(
            'id' => $id,
            'userId' => $user_id,
        ),
            array('%d', '%d')
        );

        if ($rows > 0) {
            self::invalidate('integrations', $user_id);
            return true;
        }
        return false;
    }

    // =========================================================================
    // MODELS
    // =========================================================================

    /**
     * Get all models for a user.
     *
     * @param int $user_id User ID.
     * @return array Model objects.
     */
    public static function get_user_models(int $user_id): array
    {
        return self::cached(self::cache_key('models', $user_id), function () use ($user_id) {
            global $wpdb;
            $table = self::t('models');
            return $wpdb->get_results(
                $wpdb->prepare(
                "SELECT * FROM {$table} WHERE userId = %d ORDER BY sortOrder ASC, displayName ASC",
                $user_id
            )
            );
        });
    }

    /**
     * Upsert a model — insert or update by userId + modelId + provider.
     *
     * @param array $data Model data.
     * @return int|false Model ID or false.
     */
    public static function upsert_model(array $data): int|false
    {
        global $wpdb;

        $table = self::t('models');
        $exists = $wpdb->get_var(
            $wpdb->prepare(
            "SELECT id FROM {$table} WHERE userId = %d AND modelId = %s AND provider = %s",
            $data['userId'],
            $data['modelId'],
            $data['provider']
        )
        );

        if ($exists) {
            $wpdb->update($table, $data, array('id' => $exists));
            self::invalidate('models', (int)$data['userId']);
            return (int)$exists;
        }

        $wpdb->insert($table, $data);
        $id = $wpdb->insert_id ?: false;
        if ($id) {
            self::invalidate('models', (int)$data['userId']);
        }
        return $id;
    }

    // =========================================================================
    // BRANDS
    // =========================================================================

    /**
     * Get all brands for a user.
     *
     * @param int $user_id User ID.
     * @return array Brand objects.
     */
    public static function get_user_brands(int $user_id): array
    {
        return self::cached(self::cache_key('brands', $user_id), function () use ($user_id) {
            global $wpdb;
            $table = self::t('brands');
            // Admins see every user's brands (team-wide oversight).
            if (PCM_Access::is_admin($user_id)) {
                return $wpdb->get_results("SELECT * FROM {$table} ORDER BY name ASC"); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            }
            // Owned OR granted via an assigned delivery (view + use).
            $scope = PCM_Access::scope_clause('userId', 'id', $user_id, PCM_Access::granted_brand_ids($user_id));
            // phpcs:ignore WordPress.DB.PreparedSQL -- clause built from %d placeholders only.
            return $wpdb->get_results(
                $wpdb->prepare("SELECT * FROM {$table} WHERE {$scope['sql']} ORDER BY name ASC", ...$scope['params'])
            );
        });
    }

    /**
     * Create a brand.
     *
     * @param array $data Brand data.
     * @return int|false Brand ID or false.
     */
    public static function create_brand(array $data): int|false
    {
        global $wpdb;

        $result = $wpdb->insert(self::t('brands'), $data);
        if ($result) {
            self::invalidate('brands', (int)$data['userId']);
            return $wpdb->insert_id;
        }
        return false;
    }

    /**
     * Get a single brand by ID and user ID.
     *
     * @param int $id      Brand ID.
     * @param int $user_id User ID for ownership check.
     * @return object|null Brand row or null.
     */
    public static function get_brand_by_id(int $id, int $user_id): ?object
    {
        global $wpdb;

        $table = self::t('brands');
        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$table} WHERE id = %d AND userId = %d", $id, $user_id)
        );
        if ($row) {
            return $row;
        }
        // Not owned — readable when granted via an assigned delivery (view +
        // use), or by an admin (team-wide oversight).
        if (PCM_Access::is_admin($user_id) || in_array($id, PCM_Access::granted_brand_ids($user_id), true)) {
            return $wpdb->get_row(
                $wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $id)
            );
        }
        return null;
    }

    /**
     * Update a brand's data.
     *
     * @param int   $id      Brand ID.
     * @param int   $user_id User ID for ownership check.
     * @param array $data    Column-value pairs to update.
     * @return bool True on success.
     */
    public static function update_brand(int $id, int $user_id, array $data): bool
    {
        global $wpdb;

        $rows = $wpdb->update(
            self::t('brands'),
            $data,
            array('id' => $id, 'userId' => $user_id)
        );

        if ($rows !== false) {
            self::invalidate('brands', $user_id);
            return true;
        }
        return false;
    }

    /**
     * Delete a brand by ID and user ID.
     *
     * @param int $id      Brand ID.
     * @param int $user_id User ID for ownership check.
     * @return bool True on success.
     */
    public static function delete_brand(int $id, int $user_id): bool
    {
        global $wpdb;

        $rows = $wpdb->delete(
            self::t('brands'),
            array('id' => $id, 'userId' => $user_id),
            array('%d', '%d')
        );

        if ($rows > 0) {
            self::invalidate('brands', $user_id);
            return true;
        }
        return false;
    }

    // =========================================================================
    // DELIVERIES
    // =========================================================================

    /**
     * Get all deliveries for a user.
     *
     * Cached for 5 minutes. The list is the only delivery query frequent
     * enough to warrant caching — single-row reads bypass it. Invalidation
     * fires on every write below.
     *
     * @param int $user_id User ID.
     * @return array Delivery objects ordered by recency.
     */
    public static function get_user_deliveries(int $user_id): array
    {
        return self::cached(self::cache_key('deliveries', $user_id), function () use ($user_id) {
            global $wpdb;
            $table = self::t('deliveries');
            // Admins see every user's deliveries (team-wide oversight).
            if (PCM_Access::is_admin($user_id)) {
                return $wpdb->get_results("SELECT * FROM {$table} ORDER BY updatedAt DESC, id DESC"); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            }
            // Owned OR assigned to this user (view + use).
            $scope = PCM_Access::scope_clause('userId', 'id', $user_id, PCM_Access::granted_delivery_ids($user_id));
            // phpcs:ignore WordPress.DB.PreparedSQL -- clause built from %d placeholders only.
            return $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT * FROM {$table} WHERE {$scope['sql']} ORDER BY updatedAt DESC, id DESC",
                    ...$scope['params']
                )
            );
        });
    }

    /**
     * Get a single delivery by ID and user (ownership-scoped).
     *
     * @param int $id      Delivery ID.
     * @param int $user_id User ID.
     * @return object|null Delivery row or null.
     */
    public static function get_delivery_by_id(int $id, int $user_id): ?object
    {
        global $wpdb;
        $table = self::t('deliveries');
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE id = %d AND userId = %d",
                $id,
                $user_id
            )
        );
        if ($row) {
            return $row;
        }
        // Not owned — readable when assigned to this user (view + use), or by
        // an admin (team-wide oversight).
        if (PCM_Access::is_admin($user_id) || in_array($id, PCM_Access::granted_delivery_ids($user_id), true)) {
            return $wpdb->get_row(
                $wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $id)
            );
        }
        return null;
    }

    /**
     * Create a delivery.
     *
     * @param array $data Delivery data (userId required).
     * @return int|false Delivery ID or false.
     */
    public static function create_delivery(array $data): int|false
    {
        global $wpdb;
        $result = $wpdb->insert(self::t('deliveries'), $data);
        if ($result) {
            self::invalidate('deliveries', (int)$data['userId']);
            return $wpdb->insert_id;
        }
        return false;
    }

    /**
     * Update a delivery (ownership-scoped).
     *
     * Auto-stamps updatedAt so the list ORDER BY updatedAt stays meaningful
     * without requiring callers to remember.
     *
     * @param int   $id      Delivery ID.
     * @param int   $user_id User ID for ownership check.
     * @param array $data    Column-value pairs to update.
     * @return bool True on success.
     */
    public static function update_delivery(int $id, int $user_id, array $data): bool
    {
        global $wpdb;
        $data['updatedAt'] = current_time('mysql');
        $rows = $wpdb->update(
            self::t('deliveries'),
            $data,
            array('id' => $id, 'userId' => $user_id)
        );
        if ($rows !== false) {
            self::invalidate('deliveries', $user_id);
            return true;
        }
        return false;
    }

    /**
     * Delete a delivery (ownership-scoped).
     *
     * @param int $id      Delivery ID.
     * @param int $user_id User ID for ownership check.
     * @return bool True on success.
     */
    public static function delete_delivery(int $id, int $user_id): bool
    {
        global $wpdb;
        $rows = $wpdb->delete(
            self::t('deliveries'),
            array('id' => $id, 'userId' => $user_id),
            array('%d', '%d')
        );
        if ($rows > 0) {
            self::invalidate('deliveries', $user_id);
            return true;
        }
        return false;
    }

    // =========================================================================
    // DOMAIN NORMALIZATION (shared utility for all modules)
    // =========================================================================

    /**
     * Normalize a URL to its root domain (domännamn + ändelse).
     *
     * Strips protocol, www, path, query, fragment, and port.
     * Returns only the registrable domain (e.g. "avlopp24.se").
     *
     * This is a shared utility — any module that needs to deduplicate
     * by domain can call PCM_DB::normalize_domain().
     *
     * Examples:
     *   "https://www.avlopp24.se/kontakt?ref=1" → "avlopp24.se"
     *   "http://avlopp24.se"                    → "avlopp24.se"
     *   "www.avlopp24.se"                       → "avlopp24.se"
     *   "avlopp24.se"                           → "avlopp24.se"
     *   ""                                      → null
     *
     * @param string $url Raw URL or domain string.
     * @return string|null Normalized domain, or null if input is empty/invalid.
     */
    public static function normalize_domain(string $url): ?string
    {
        $url = trim($url);
        if (empty($url)) {
            return null;
        }

        // Ensure we have a parseable URL (add scheme if missing)
        if (!preg_match('#^https?://#i', $url)) {
            $url = 'https://' . $url;
        }

        $host = wp_parse_url($url, PHP_URL_HOST);
        if (empty($host)) {
            return null;
        }

        // Strip "www." prefix — keeping only domännamn + ändelse
        $host = strtolower($host);
        if (str_starts_with($host, 'www.')) {
            $host = substr($host, 4);
        }

        // Guard: must contain at least one dot (e.g. "avlopp24.se")
        if (!str_contains($host, '.')) {
            return null;
        }

        return $host;
    }

    // =========================================================================
    // BRANDS — UPSERT (domain-based deduplication)
    // =========================================================================

    /**
     * Insert or update a brand based on normalized domain.
     *
     * If a brand with the same domain already exists:
     *   → Updates the existing row with the provided fields.
     *   → Returns the existing brand ID.
     *
     * If no matching domain exists:
     *   → Inserts a new row.
     *   → Returns the new brand ID.
     *
     * Brands without a website/domain bypass deduplication and are
     * always inserted as new rows (manual brands without a URL).
     *
     * This method is used by all brand creation paths (controller,
     * onboarding, auto-save from URL scrape) to prevent duplicates.
     *
     * @param array $data Brand data including 'website' key.
     * @return int|false Brand ID on success, false on failure.
     */
    public static function upsert_brand(array $data): int|false
    {
        global $wpdb;

        $table = self::t('brands');
        $domain = null;

        // Normalize domain from website if provided
        if (!empty($data['website'])) {
            $domain = self::normalize_domain($data['website']);
        }

        // Set the domain field for storage
        $data['domain'] = $domain;

        // If we have a domain, check for existing brand
        if ($domain) {
            $existing_id = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$table} WHERE domain = %s LIMIT 1",
                $domain
            ));

            if ($existing_id) {
                // Update existing brand — merge new data into existing row
                $update = $data;
                unset($update['userId']); // Never change ownership
                unset($update['createdAt']); // Preserve original creation date

                $wpdb->update($table, $update, array('id' => (int)$existing_id));
                self::invalidate('brands', (int)($data['userId'] ?? 0));
                return (int)$existing_id;
            }
        }

        // No existing brand found — insert new row
        $result = $wpdb->insert($table, $data);
        if ($result) {
            self::invalidate('brands', (int)($data['userId'] ?? 0));
            return $wpdb->insert_id;
        }
        return false;
    }

    // =========================================================================
    // TEMPLATES
    // =========================================================================

    /**
     * Get all templates for a user, optionally filtered by module.
     *
     * @param int         $user_id User ID.
     * @param string|null $module  Module filter (e.g. 'copy', 'image').
     * @return array Template objects.
     */
    public static function get_user_templates(int $user_id, ?string $module = null): array
    {
        $suffix = $module ?: null;
        return self::cached(self::cache_key('templates', $user_id, $suffix), function () use ($user_id, $module) {
            global $wpdb;
            $table = self::t('templates');

            if ($module) {
                return $wpdb->get_results(
                    $wpdb->prepare(
                    "SELECT * FROM {$table} WHERE userId = %d AND module = %s ORDER BY name ASC",
                    $user_id,
                    $module
                )
                );
            }

            return $wpdb->get_results(
                $wpdb->prepare("SELECT * FROM {$table} WHERE userId = %d ORDER BY module ASC, name ASC", $user_id)
            );
        });
    }

    // =========================================================================
    // GENERIC HELPERS
    // =========================================================================

    /**
     * Get a single row by ID and user ID from any table.
     *
     * @param string $table Short table name.
     * @param int    $id    Row ID.
     * @param int    $user_id User ID for ownership check.
     * @return object|null
     */
    public static function get_by_id(string $table, int $id, int $user_id): ?object
    {
        global $wpdb;

        $full_table = self::t($table);
        return $wpdb->get_row(
            $wpdb->prepare(
            "SELECT * FROM {$full_table} WHERE id = %d AND userId = %d",
            $id,
            $user_id
        )
        );
    }

    /**
     * Update a row by ID and user ID in any table.
     *
     * @param string $table  Short table name.
     * @param int    $id     Row ID.
     * @param int    $user_id User ID for ownership check.
     * @param array  $data   Column-value pairs to update.
     * @return bool True on success.
     */
    public static function update_by_id(string $table, int $id, int $user_id, array $data): bool
    {
        global $wpdb;

        $rows = $wpdb->update(
            self::t($table),
            $data,
            array(
            'id' => $id,
            'userId' => $user_id,
        )
        );

        if ($rows !== false) {
            self::invalidate($table, $user_id);
            return true;
        }
        return false;
    }

    /**
     * Delete a row by ID and user ID from any table.
     *
     * @param string $table  Short table name.
     * @param int    $id     Row ID.
     * @param int    $user_id User ID for ownership check.
     * @return bool True on success.
     */
    public static function delete_by_id(string $table, int $id, int $user_id): bool
    {
        global $wpdb;

        $rows = $wpdb->delete(
            self::t($table),
            array(
            'id' => $id,
            'userId' => $user_id,
        ),
            array('%d', '%d')
        );

        if ($rows > 0) {
            self::invalidate($table, $user_id);
            return true;
        }
        return false;
    }

    // =========================================================================
    // STRATEGIES
    // =========================================================================

    /**
     * Create a new strategy.
     *
     * @param array $data Strategy data (userId, name, brandId, templateId, etc.).
     * @return int|false Strategy ID or false.
     */
    public static function create_strategy(array $data): int|false
    {
        global $wpdb;

        $result = $wpdb->insert(self::t('strategies'), $data);
        if ($result) {
            self::invalidate('strategies', (int)$data['userId']);
            return $wpdb->insert_id;
        }
        return false;
    }

    /**
     * Get all strategies for a user.
     *
     * @param int $user_id User ID.
     * @return array Strategy objects ordered by creation date (newest first).
     */
    public static function get_user_strategies(int $user_id): array
    {
        return self::cached(self::cache_key('strategies', $user_id), function () use ($user_id) {
            global $wpdb;
            $table = self::t('strategies');
            return $wpdb->get_results(
                $wpdb->prepare(
                "SELECT * FROM {$table} WHERE userId = %d ORDER BY createdAt DESC",
                $user_id
            )
            );
        });
    }

    /**
     * Get a single strategy by ID and user.
     *
     * @param int $id      Strategy ID.
     * @param int $user_id User ID for ownership check.
     * @return object|null Strategy row or null.
     */
    public static function get_strategy(int $id, int $user_id): ?object
    {
        global $wpdb;
        $table = self::t('strategies');
        return $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$table} WHERE id = %d AND userId = %d", $id, $user_id)
        );
    }

    /**
     * Update a strategy.
     *
     * @param int   $id      Strategy ID.
     * @param int   $user_id User ID for ownership.
     * @param array $data    Column-value pairs to update.
     * @return bool True on success.
     */
    public static function update_strategy(int $id, int $user_id, array $data): bool
    {
        global $wpdb;
        $data['updatedAt'] = current_time('mysql');
        $rows = $wpdb->update(self::t('strategies'), $data, array('id' => $id, 'userId' => $user_id));
        if ($rows !== false) {
            self::invalidate('strategies', $user_id);
            return true;
        }
        return false;
    }

    /**
     * Delete a strategy and its items.
     *
     * @param int $id      Strategy ID.
     * @param int $user_id User ID for ownership.
     * @return bool True on success.
     */
    public static function delete_strategy(int $id, int $user_id): bool
    {
        global $wpdb;

        // Verify ownership before cascading delete
        $strategy = self::get_strategy($id, $user_id);
        if (!$strategy) {
            return false;
        }

        // Delete child items first
        $wpdb->delete(self::t('strategy_items'), array('strategyId' => $id), array('%d'));

        // Delete the strategy
        $rows = $wpdb->delete(self::t('strategies'), array('id' => $id, 'userId' => $user_id), array('%d', '%d'));
        if ($rows > 0) {
            self::invalidate('strategies', $user_id);
            return true;
        }
        return false;
    }

    // =========================================================================
    // STRATEGY ITEMS
    // =========================================================================

    /**
     * Bulk-create strategy items from a keyword list.
     *
     * @param int   $strategy_id Strategy ID.
     * @param int   $user_id     User ID.
     * @param array $keywords    Array of keyword strings.
     * @return int Number of items inserted.
     */
    public static function create_strategy_items(int $strategy_id, int $user_id, array $keywords): int
    {
        global $wpdb;
        $table = self::t('strategy_items');
        $count = 0;

        foreach ($keywords as $position => $keyword) {
            $result = $wpdb->insert($table, array(
                'strategyId' => $strategy_id,
                'userId'     => $user_id,
                'keyword'    => sanitize_text_field($keyword),
                'position'   => $position,
                'status'     => 'pending',
            ));
            if ($result) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Get all items for a strategy.
     *
     * @param int $strategy_id Strategy ID.
     * @return array Strategy item objects ordered by position.
     */
    public static function get_strategy_items(int $strategy_id): array
    {
        global $wpdb;
        $table = self::t('strategy_items');
        return $wpdb->get_results(
            $wpdb->prepare(
            "SELECT * FROM {$table} WHERE strategyId = %d ORDER BY position ASC",
            $strategy_id
        )
        );
    }

    /**
     * Get the next pending item in a strategy.
     *
     * @param int $strategy_id Strategy ID.
     * @return object|null The next pending item or null.
     */
    public static function get_next_pending_item(int $strategy_id): ?object
    {
        global $wpdb;
        $table = self::t('strategy_items');
        return $wpdb->get_row(
            $wpdb->prepare(
            "SELECT * FROM {$table} WHERE strategyId = %d AND status = 'pending' ORDER BY position ASC LIMIT 1",
            $strategy_id
        )
        );
    }

    /**
     * Update a strategy item.
     *
     * @param int   $id   Item ID.
     * @param array $data Column-value pairs.
     * @return bool True on success.
     */
    public static function update_strategy_item(int $id, array $data): bool
    {
        global $wpdb;
        $data['updatedAt'] = current_time('mysql');
        $rows = $wpdb->update(self::t('strategy_items'), $data, array('id' => $id));
        return $rows !== false;
    }

    // =========================================================================
    // ARTICLES
    // =========================================================================

    /**
     * Create a new article.
     *
     * @param array $data Article data.
     * @return int|false Article ID or false.
     */
    public static function create_article(array $data): int|false
    {
        global $wpdb;
        $result = $wpdb->insert(self::t('articles'), $data);
        if ($result) {
            self::invalidate('articles', (int)$data['userId']);
            return $wpdb->insert_id;
        }
        return false;
    }

    /**
     * Get all articles for a user, optionally filtered by status.
     *
     * @param int         $user_id User ID.
     * @param string|null $status  Optional status filter.
     * @return array Article objects.
     */
    public static function get_user_articles(int $user_id, ?string $status = null): array
    {
        global $wpdb;
        $table = self::t('articles');

        if ($status) {
            return $wpdb->get_results(
                $wpdb->prepare(
                "SELECT * FROM {$table} WHERE userId = %d AND status = %s ORDER BY updatedAt DESC",
                $user_id,
                $status
            )
            );
        }

        return $wpdb->get_results(
            $wpdb->prepare(
            "SELECT * FROM {$table} WHERE userId = %d ORDER BY updatedAt DESC",
            $user_id
        )
        );
    }

    /**
     * Get a single article by ID and user.
     *
     * @param int $id      Article ID.
     * @param int $user_id User ID.
     * @return object|null Article row or null.
     */
    public static function get_article(int $id, int $user_id): ?object
    {
        global $wpdb;
        $table = self::t('articles');
        return $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$table} WHERE id = %d AND userId = %d", $id, $user_id)
        );
    }

    /**
     * Update an article.
     *
     * @param int   $id      Article ID.
     * @param int   $user_id User ID.
     * @param array $data    Column-value pairs.
     * @return bool True on success.
     */
    public static function update_article(int $id, int $user_id, array $data): bool
    {
        global $wpdb;
        $data['updatedAt'] = current_time('mysql');
        $rows = $wpdb->update(self::t('articles'), $data, array('id' => $id, 'userId' => $user_id));
        if ($rows !== false) {
            self::invalidate('articles', $user_id);
            return true;
        }
        return false;
    }

    /**
     * Delete an article.
     *
     * @param int $id      Article ID.
     * @param int $user_id User ID.
     * @return bool True on success.
     */
    public static function delete_article(int $id, int $user_id): bool
    {
        global $wpdb;
        $rows = $wpdb->delete(self::t('articles'), array('id' => $id, 'userId' => $user_id), array('%d', '%d'));
        if ($rows > 0) {
            self::invalidate('articles', $user_id);
            return true;
        }
        return false;
    }

    // =========================================================================
    // SITES
    // =========================================================================

    /**
     * Create a new site connection.
     *
     * @param array $data Site data (userId, name, url, username, appPassword).
     * @return int|false Site ID or false.
     */
    public static function create_site(array $data): int|false
    {
        global $wpdb;
        $result = $wpdb->insert(self::t('sites'), $data);
        if ($result) {
            self::invalidate('sites', (int)$data['userId']);
            return $wpdb->insert_id;
        }
        return false;
    }

    /**
     * Get all sites for a user.
     *
     * @param int $user_id User ID.
     * @return array Site objects.
     */
    public static function get_user_sites(int $user_id): array
    {
        return self::cached(self::cache_key('sites', $user_id), function () use ($user_id) {
            global $wpdb;
            $table = self::t('sites');
            return $wpdb->get_results(
                $wpdb->prepare("SELECT * FROM {$table} WHERE userId = %d ORDER BY name ASC", $user_id)
            );
        });
    }

    /**
     * Get a single site by ID.
     *
     * @param int $id      Site ID.
     * @param int $user_id User ID.
     * @return object|null Site row or null.
     */
    public static function get_site(int $id, int $user_id): ?object
    {
        global $wpdb;
        $table = self::t('sites');
        return $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$table} WHERE id = %d AND userId = %d", $id, $user_id)
        );
    }

    /**
     * Update a site.
     *
     * @param int   $id      Site ID.
     * @param int   $user_id User ID.
     * @param array $data    Column-value pairs.
     * @return bool True on success.
     */
    public static function update_site(int $id, int $user_id, array $data): bool
    {
        global $wpdb;
        $data['updatedAt'] = current_time('mysql');
        $rows = $wpdb->update(self::t('sites'), $data, array('id' => $id, 'userId' => $user_id));
        if ($rows !== false) {
            self::invalidate('sites', $user_id);
            return true;
        }
        return false;
    }

    /**
     * Delete a site.
     *
     * @param int $id      Site ID.
     * @param int $user_id User ID.
     * @return bool True on success.
     */
    public static function delete_site(int $id, int $user_id): bool
    {
        global $wpdb;
        $rows = $wpdb->delete(self::t('sites'), array('id' => $id, 'userId' => $user_id), array('%d', '%d'));
        if ($rows > 0) {
            self::invalidate('sites', $user_id);
            return true;
        }
        return false;
    }
}
