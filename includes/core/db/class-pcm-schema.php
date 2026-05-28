<?php
/**
 * Database Schema Definition
 *
 * Defines all custom database tables using WordPress dbDelta() format.
 * Maps 1:1 to the original Drizzle ORM schema (drizzle/schema.ts).
 *
 * Tables created (all prefixed with wp_pcm_):
 *   - users           → Core user table (auth)
 *   - integrations    → API keys & model configs
 *   - projects        → User projects
 *   - assets          → Generated images/videos
 *   - scraped_collections → URL scraping sessions
 *   - scraped_images  → Individual scraped images
 *   - models          → Unified AI model registry
 *   - copy_jobs       → Copy generation requests
 *   - copy_results    → Generated copy cards
 *   - templates       → Reusable form presets
 *   - brands          → Business profile data
 *   - brand_assets    → Brand logo/images
 *   - prompt_overrides → User-edited system prompts
 *
 * @package PowerCreatives
 */

if (!defined('ABSPATH')) {
    exit;
}

class PCM_Schema
{

    /**
     * Get the custom table prefix: wp_pcm_
     *
     * @return string
     */
    public static function prefix(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'pcm_';
    }

    /**
     * Get a fully qualified table name.
     *
     * @param string $table Short table name (e.g. 'users').
     * @return string Full table name (e.g. 'wp_pcm_users').
     */
    public static function table(string $table): string
    {
        return self::prefix() . $table;
    }

    /**
     * Create or update all plugin tables.
     *
     * Uses dbDelta() which safely handles both creation and schema migration.
     * Called on plugin activation and during version upgrades.
     *
     * @return void
     */
    public static function create_tables(): void
    {
        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();
        $prefix = self::prefix();

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        // ── Users ──
        $sql = "CREATE TABLE {$prefix}users (
            id int(11) NOT NULL AUTO_INCREMENT,
            openId varchar(256) NOT NULL,
            name varchar(256) DEFAULT NULL,
            email varchar(256) DEFAULT NULL,
            role varchar(50) DEFAULT 'user' NOT NULL,
            avatarUrl text DEFAULT NULL,
            createdAt datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
            updatedAt datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
            lastSignedIn datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
            PRIMARY KEY  (id),
            KEY idx_openId (openId)
        ) $charset_collate;";
        dbDelta($sql);

        // ── Integrations ──
        $sql = "CREATE TABLE {$prefix}integrations (
            id int(11) NOT NULL AUTO_INCREMENT,
            userId int(11) NOT NULL,
            provider varchar(100) NOT NULL,
            type varchar(50) NOT NULL,
            apiKey text NOT NULL,
            label varchar(256) DEFAULT NULL,
            isActive tinyint(1) DEFAULT 1 NOT NULL,
            createdAt datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
            updatedAt datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
            PRIMARY KEY  (id),
            KEY idx_userId (userId),
            KEY idx_provider (provider)
        ) $charset_collate;";
        dbDelta($sql);

        // ── Projects ──
        $sql = "CREATE TABLE {$prefix}projects (
            id int(11) NOT NULL AUTO_INCREMENT,
            userId int(11) NOT NULL,
            name varchar(256) NOT NULL,
            description text DEFAULT NULL,
            status varchar(50) DEFAULT 'active' NOT NULL,
            settings text DEFAULT NULL,
            createdAt datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
            updatedAt datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
            PRIMARY KEY  (id),
            KEY idx_userId (userId)
        ) $charset_collate;";
        dbDelta($sql);

        // ── Assets ──
        $sql = "CREATE TABLE {$prefix}assets (
            id int(11) NOT NULL AUTO_INCREMENT,
            projectId int(11) DEFAULT NULL,
            userId int(11) NOT NULL,
            type varchar(50) NOT NULL,
            url text NOT NULL,
            prompt text DEFAULT NULL,
            provider varchar(100) DEFAULT NULL,
            modelId varchar(256) DEFAULT NULL,
            metadata text DEFAULT NULL,
            versionName varchar(256) DEFAULT NULL,
            createdAt datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
            PRIMARY KEY  (id),
            KEY idx_projectId (projectId),
            KEY idx_userId (userId)
        ) $charset_collate;";
        dbDelta($sql);

        // Migrate assets columns: projectId NOT NULL → DEFAULT NULL.
        // dbDelta() cannot change column nullability on existing tables,
        // so we handle it via explicit ALTER TABLE.
        self::migrate_assets_columns($prefix);

        // ── Scraped Collections ──
        $sql = "CREATE TABLE {$prefix}scraped_collections (
            id int(11) NOT NULL AUTO_INCREMENT,
            userId int(11) NOT NULL,
            sourceUrl text NOT NULL,
            title varchar(512) DEFAULT NULL,
            status varchar(50) DEFAULT 'pending' NOT NULL,
            imageCount int(11) DEFAULT 0 NOT NULL,
            selectedCount int(11) DEFAULT 0 NOT NULL,
            errorMessage text DEFAULT NULL,
            createdAt datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
            updatedAt datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
            PRIMARY KEY  (id),
            KEY idx_userId (userId)
        ) $charset_collate;";
        dbDelta($sql);

        // ── Scraped Images ──
        $sql = "CREATE TABLE {$prefix}scraped_images (
            id int(11) NOT NULL AUTO_INCREMENT,
            collectionId int(11) NOT NULL,
            userId int(11) NOT NULL,
            originalUrl text NOT NULL,
            thumbnailUrl text DEFAULT NULL,
            width int(11) DEFAULT NULL,
            height int(11) DEFAULT NULL,
            alt varchar(512) DEFAULT NULL,
            aiTags text DEFAULT NULL,
            aiCategory varchar(256) DEFAULT NULL,
            aiDescription text DEFAULT NULL,
            aiScore float DEFAULT NULL,
            relevanceReason text DEFAULT NULL,
            classifierResult text DEFAULT NULL,
            isUsable tinyint(1) DEFAULT 1 NOT NULL,
            isExcluded tinyint(1) DEFAULT 0 NOT NULL,
            createdAt datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
            PRIMARY KEY  (id),
            KEY idx_collectionId (collectionId),
            KEY idx_userId (userId)
        ) $charset_collate;";
        dbDelta($sql);

        // ── Unified Models ──
        $sql = "CREATE TABLE {$prefix}models (
            id int(11) NOT NULL AUTO_INCREMENT,
            userId int(11) NOT NULL,
            modelId varchar(256) NOT NULL,
            provider varchar(100) NOT NULL,
            displayName varchar(256) DEFAULT NULL,
            canGenerateImage tinyint(1) DEFAULT 0 NOT NULL,
            canEditImage tinyint(1) DEFAULT 0 NOT NULL,
            canGenerateVideo tinyint(1) DEFAULT 0 NOT NULL,
            canEditVideo tinyint(1) DEFAULT 0 NOT NULL,
            canGenerateText tinyint(1) DEFAULT 0 NOT NULL,
            canVision tinyint(1) DEFAULT 0 NOT NULL,
            costTier varchar(20) DEFAULT 'standard' NOT NULL,
            status varchar(50) DEFAULT 'auto' NOT NULL,
            isEnabled tinyint(1) DEFAULT 1 NOT NULL,
            isAvailable tinyint(1) DEFAULT 1 NOT NULL,
            enabledModules text DEFAULT NULL,
            providerMetadata text DEFAULT NULL,
            description text DEFAULT NULL,
            tags text DEFAULT NULL,
            sortOrder int(11) DEFAULT 0 NOT NULL,
            createdAt datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
            updatedAt datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
            PRIMARY KEY  (id),
            KEY idx_userId (userId),
            KEY idx_modelId (modelId),
            KEY idx_provider (provider)
        ) $charset_collate;";
        dbDelta($sql);

        // ── Copy Jobs ──
        $sql = "CREATE TABLE {$prefix}copy_jobs (
            id int(11) NOT NULL AUTO_INCREMENT,
            userId int(11) NOT NULL,
            copyTypes text NOT NULL,
            modelId varchar(256) DEFAULT NULL,
            brandId int(11) DEFAULT NULL,
            templateId int(11) DEFAULT NULL,
            formSnapshot text DEFAULT NULL,
            status varchar(50) DEFAULT 'pending' NOT NULL,
            totalCount int(11) DEFAULT 0 NOT NULL,
            completedCount int(11) DEFAULT 0 NOT NULL,
            failedCount int(11) DEFAULT 0 NOT NULL,
            errorMessage text DEFAULT NULL,
            createdAt datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
            updatedAt datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
            PRIMARY KEY  (id),
            KEY idx_userId (userId),
            KEY idx_status (status)
        ) $charset_collate;";
        dbDelta($sql);

        // ── Copy Results ──
        $sql = "CREATE TABLE {$prefix}copy_results (
            id int(11) NOT NULL AUTO_INCREMENT,
            jobId int(11) NOT NULL,
            userId int(11) NOT NULL,
            projectId int(11) DEFAULT NULL,
            copyType varchar(50) NOT NULL,
            audienceId varchar(256) DEFAULT NULL,
            audienceName varchar(512) DEFAULT NULL,
            headline text DEFAULT NULL,
            body text DEFAULT NULL,
            cta varchar(512) DEFAULT NULL,
            hashtags text DEFAULT NULL,
            description text DEFAULT NULL,
            rawResponse text DEFAULT NULL,
            createdAt datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
            PRIMARY KEY  (id),
            KEY idx_jobId (jobId),
            KEY idx_userId (userId),
            KEY idx_projectId (projectId)
        ) $charset_collate;";
        dbDelta($sql);

        // ── Templates ──
        $sql = "CREATE TABLE {$prefix}templates (
            id int(11) NOT NULL AUTO_INCREMENT,
            userId int(11) NOT NULL,
            name varchar(256) NOT NULL,
            module varchar(100) NOT NULL,
            formData text NOT NULL,
            description text DEFAULT NULL,
            isDefault tinyint(1) DEFAULT 0 NOT NULL,
            createdAt datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
            updatedAt datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
            PRIMARY KEY  (id),
            KEY idx_userId (userId),
            KEY idx_module (module)
        ) $charset_collate;";
        dbDelta($sql);

        // ── Brands ──
        $sql = "CREATE TABLE {$prefix}brands (
            id int(11) NOT NULL AUTO_INCREMENT,
            userId int(11) NOT NULL,
            name varchar(256) NOT NULL,
            website text DEFAULT NULL,
            domain varchar(256) DEFAULT NULL,
            niche varchar(256) DEFAULT NULL,
            location varchar(512) DEFAULT NULL,
            phone varchar(100) DEFAULT NULL,
            businessSummary text DEFAULT NULL,
            language varchar(50) DEFAULT NULL,
            description text DEFAULT NULL,
            tonOfVoice text DEFAULT NULL,
            targetAudience text DEFAULT NULL,
            uniqueSellingPoints text DEFAULT NULL,
            additionalContext text DEFAULT NULL,
            colors text DEFAULT NULL,
            fonts text DEFAULT NULL,
            assets text DEFAULT NULL,
            scrapedAt datetime DEFAULT NULL,
            createdAt datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
            updatedAt datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
            PRIMARY KEY  (id),
            KEY idx_userId (userId),
            UNIQUE KEY idx_domain (domain)
        ) $charset_collate;";
        dbDelta($sql);

        // Migrate legacy column names if upgrading from an older schema.
        // dbDelta() can ADD columns but cannot RENAME them, so we handle
        // renames separately via ALTER TABLE CHANGE.
        self::migrate_brands_columns($prefix);
        self::migrate_brands_domain($prefix);

        // ── Brand Assets ──
        $sql = "CREATE TABLE {$prefix}brand_assets (
            id int(11) NOT NULL AUTO_INCREMENT,
            brandId int(11) NOT NULL,
            userId int(11) NOT NULL,
            type varchar(50) NOT NULL,
            url text NOT NULL,
            label varchar(256) DEFAULT NULL,
            sortOrder int(11) DEFAULT 0 NOT NULL,
            createdAt datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
            PRIMARY KEY  (id),
            KEY idx_brandId (brandId)
        ) $charset_collate;";
        dbDelta($sql);

        // ── Prompt Overrides ──
        $sql = "CREATE TABLE {$prefix}prompt_overrides (
            id int(11) NOT NULL AUTO_INCREMENT,
            userId int(11) NOT NULL,
            module varchar(100) NOT NULL,
            section varchar(256) NOT NULL,
            variantName varchar(256) DEFAULT 'default' NOT NULL,
            isActive tinyint(1) DEFAULT 0 NOT NULL,
            content text NOT NULL,
            createdAt datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
            updatedAt datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
            PRIMARY KEY  (id),
            KEY idx_userId_module (userId, module)
        ) $charset_collate;";
        dbDelta($sql);

        // ── Strategies ──
        // Content campaigns that group keywords with a template and brand.
        // Orchestrates the generation pipeline (Keywords → Writer → Sites).
        $sql = "CREATE TABLE {$prefix}strategies (
            id int(11) NOT NULL AUTO_INCREMENT,
            userId int(11) NOT NULL,
            brandId int(11) DEFAULT NULL,
            templateId int(11) DEFAULT NULL,
            name varchar(256) NOT NULL,
            status varchar(50) DEFAULT 'pending' NOT NULL,
            hierarchyMode varchar(50) DEFAULT 'standalone' NOT NULL,
            publishingMode varchar(50) DEFAULT 'draft' NOT NULL,
            config text DEFAULT NULL,
            totalItems int(11) DEFAULT 0 NOT NULL,
            completedItems int(11) DEFAULT 0 NOT NULL,
            failedItems int(11) DEFAULT 0 NOT NULL,
            createdAt datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
            updatedAt datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
            PRIMARY KEY  (id),
            KEY idx_userId (userId),
            KEY idx_status (status),
            KEY idx_brandId (brandId)
        ) $charset_collate;";
        dbDelta($sql);

        // ── Strategy Items ──
        // Individual keyword-to-article work units within a strategy.
        // Each item tracks its own generation status independently.
        $sql = "CREATE TABLE {$prefix}strategy_items (
            id int(11) NOT NULL AUTO_INCREMENT,
            strategyId int(11) NOT NULL,
            userId int(11) NOT NULL,
            keyword varchar(512) NOT NULL,
            title varchar(512) DEFAULT NULL,
            slug varchar(512) DEFAULT NULL,
            status varchar(50) DEFAULT 'pending' NOT NULL,
            articleId int(11) DEFAULT NULL,
            position int(11) DEFAULT 0 NOT NULL,
            errorMessage text DEFAULT NULL,
            config text DEFAULT NULL,
            createdAt datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
            updatedAt datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
            PRIMARY KEY  (id),
            KEY idx_strategyId (strategyId),
            KEY idx_userId (userId),
            KEY idx_status (status)
        ) $charset_collate;";
        dbDelta($sql);

        // ── Articles ──
        // Generated content documents, editable in the Writer module.
        // Bridges Strategies (source) → Writer (edit) → Sites (publish).
        $sql = "CREATE TABLE {$prefix}articles (
            id int(11) NOT NULL AUTO_INCREMENT,
            userId int(11) NOT NULL,
            strategyId int(11) DEFAULT NULL,
            strategyItemId int(11) DEFAULT NULL,
            brandId int(11) DEFAULT NULL,
            title varchar(512) NOT NULL,
            slug varchar(512) DEFAULT NULL,
            content longtext DEFAULT NULL,
            metaTitle varchar(512) DEFAULT NULL,
            metaDescription text DEFAULT NULL,
            schemaType varchar(50) DEFAULT 'Article' NOT NULL,
            status varchar(50) DEFAULT 'draft' NOT NULL,
            featuredImage text DEFAULT NULL,
            seoScore int(11) DEFAULT NULL,
            publishedUrl text DEFAULT NULL,
            publishedPostId int(11) DEFAULT NULL,
            siteId int(11) DEFAULT NULL,
            createdAt datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
            updatedAt datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
            publishedAt datetime DEFAULT NULL,
            PRIMARY KEY  (id),
            KEY idx_userId (userId),
            KEY idx_strategyId (strategyId),
            KEY idx_status (status),
            KEY idx_siteId (siteId)
        ) $charset_collate;";
        dbDelta($sql);

        // ── Sites ──
        // Connected WordPress sites for content publishing.
        // Uses WP Application Passwords for secure REST API auth.
        // appPassword is stored encrypted via openssl_encrypt().
        $sql = "CREATE TABLE {$prefix}sites (
            id int(11) NOT NULL AUTO_INCREMENT,
            userId int(11) NOT NULL,
            name varchar(256) NOT NULL,
            url text NOT NULL,
            username varchar(256) NOT NULL,
            appPassword text NOT NULL,
            status varchar(50) DEFAULT 'active' NOT NULL,
            lastSyncAt datetime DEFAULT NULL,
            createdAt datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
            updatedAt datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
            PRIMARY KEY  (id),
            KEY idx_userId (userId),
            KEY idx_status (status)
        ) $charset_collate;";
        dbDelta($sql);

        // ── Deliveries ──
        // Continual-fulfilment client deliveries. Standalone module on par
        // with Brands and Sites; status column drives the Kanban pipeline
        // (active / paused / completed). Other modules reference deliveryId
        // by FK in their own payloads — Deliveries never tracks back.
        $sql = "CREATE TABLE {$prefix}deliveries (
            id int(11) NOT NULL AUTO_INCREMENT,
            userId int(11) NOT NULL,
            name varchar(256) NOT NULL,
            clientName varchar(256) DEFAULT NULL,
            status varchar(50) DEFAULT 'active' NOT NULL,
            createdAt datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
            updatedAt datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
            PRIMARY KEY  (id),
            KEY idx_userId (userId),
            KEY idx_status (status)
        ) $charset_collate;";
        dbDelta($sql);

        // ── Approval Sets ──
        // Packages generated copy and media assets into client-shareable boards.
        // Stores client approvals, element comments, and frozen snapshots.
        $sql = "CREATE TABLE {$prefix}approval_sets (
            id int(11) NOT NULL AUTO_INCREMENT,
            userId int(11) NOT NULL,
            brandId int(11) DEFAULT NULL,
            projectId int(11) DEFAULT NULL,
            name varchar(256) NOT NULL,
            token varchar(128) NOT NULL,
            status varchar(50) DEFAULT 'draft' NOT NULL,
            snapshot longtext NOT NULL,
            reviewFeedback longtext DEFAULT NULL,
            createdAt datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
            updatedAt datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY idx_token (token),
            KEY idx_userId (userId),
            KEY idx_brandId (brandId)
        ) $charset_collate;";
        dbDelta($sql);
    }

    /**
     * Migrate legacy brands column names.
     *
     * dbDelta() can add new columns but cannot rename existing ones.
     * This method handles renames for installs upgrading from older schemas:
     *   - `url` → `website`  (semantic alignment with code)
     *   - `toneOfVoice` → `tonOfVoice` (camelCase consistency)
     *
     * Safe to call repeatedly — checks column existence before renaming.
     *
     * @param string $prefix Table prefix (e.g. 'wp_pcm_').
     * @return void
     */
    private static function migrate_brands_columns(string $prefix): void
    {
        global $wpdb;

        $table = "{$prefix}brands";

        // Check which columns currently exist
        $columns = $wpdb->get_col("SHOW COLUMNS FROM {$table}", 0);
        if (empty($columns)) {
            return; // Table doesn't exist yet
        }

        $has = array_flip($columns);

        // Rename 'url' → 'website' if old column exists and new doesn't
        if (isset($has['url']) && !isset($has['website'])) {
            $wpdb->query("ALTER TABLE {$table} CHANGE `url` `website` text DEFAULT NULL");
        }

        // Rename 'toneOfVoice' → 'tonOfVoice' if old column exists and new doesn't
        if (isset($has['toneOfVoice']) && !isset($has['tonOfVoice'])) {
            $wpdb->query("ALTER TABLE {$table} CHANGE `toneOfVoice` `tonOfVoice` text DEFAULT NULL");
        }
    }

    /**
     * Migrate brands: populate `domain` from `website` and deduplicate.
     *
     * 1. For each brand with a website but no domain, extract the
     *    normalized domain (strip protocol, www, path, query).
     * 2. If duplicate domains exist, keep the row with the highest
     *    `updatedAt` (or `id`) and delete the rest.
     * 3. Apply the UNIQUE INDEX after deduplication to prevent future dupes.
     *
     * Uses the shared `PCM_DB::normalize_domain()` helper so all modules
     * produce identical domain keys.
     *
     * Safe to call repeatedly — skips rows that already have a domain set.
     *
     * @param string $prefix Table prefix (e.g. 'wp_pcm_').
     * @return void
     */
    private static function migrate_brands_domain(string $prefix): void
    {
        global $wpdb;

        $table = "{$prefix}brands";

        // Check if domain column exists
        $columns = $wpdb->get_col("SHOW COLUMNS FROM {$table}", 0);
        if (empty($columns) || !in_array('domain', $columns, true)) {
            return; // Column not yet added by dbDelta
        }

        // Step 1: Populate domain from website for rows that don't have it yet
        $rows = $wpdb->get_results(
            "SELECT id, website FROM {$table} WHERE domain IS NULL AND website IS NOT NULL AND website != ''"
        );

        foreach ($rows as $row) {
            $domain = PCM_DB::normalize_domain($row->website);
            if ($domain) {
                $wpdb->update($table, array('domain' => $domain), array('id' => (int)$row->id));
            }
        }

        // Step 2: Deduplicate — keep the row with the highest id per domain
        // This removes older duplicates while preserving the most recent.
        $dupes = $wpdb->get_results(
            "SELECT domain, MAX(id) as keep_id, COUNT(*) as cnt
             FROM {$table}
             WHERE domain IS NOT NULL
             GROUP BY domain
             HAVING cnt > 1"
        );

        foreach ($dupes as $dupe) {
            $wpdb->query($wpdb->prepare(
                "DELETE FROM {$table} WHERE domain = %s AND id != %d",
                $dupe->domain,
                (int)$dupe->keep_id
            ));
        }
    }

    /**
     * Migrate assets column constraints.
     *
     * dbDelta() cannot change NOT NULL → DEFAULT NULL on existing columns.
     * projectId was originally NOT NULL but images can be generated without
     * a project context, so it must be nullable.
     *
     * Safe to call repeatedly — checks current column definition before altering.
     *
     * @param string $prefix Table prefix (e.g. 'wp_pcm_').
     * @return void
     */
    private static function migrate_assets_columns(string $prefix): void
    {
        global $wpdb;

        $table = "{$prefix}assets";

        // Check if table exists
        $columns = $wpdb->get_results("SHOW COLUMNS FROM {$table}", ARRAY_A);
        if (empty($columns)) {
            return;
        }

        // Find projectId column and check if it's NOT NULL
        foreach ($columns as $col) {
            if ($col['Field'] === 'projectId' && $col['Null'] === 'NO') {
                $wpdb->query("ALTER TABLE {$table} MODIFY `projectId` int(11) DEFAULT NULL");
                break;
            }
        }
    }

    /**
     * Backfill role='logo' on legacy brand assets (v1.4.0).
     *
     * Brand assets are stored as a JSON array in the brands.assets column.
     * Before v1.4.0 the role field did not exist — every asset was created
     * without a role, and the implicit convention was "assets[0] is the logo".
     *
     * After v1.4.0 every consumer expects an explicit role. This migration
     * makes the implicit convention explicit by setting:
     *   - assets[0].role = 'logo' (preserves historical "first = logo" behaviour)
     *   - assets[1..].role = 'reference' (default for any extra assets)
     *
     * Idempotent: assets that already have a role are left untouched.
     * Brands are only updated if at least one asset was modified, so re-running
     * this migration after it has completed is a no-op (zero DB writes).
     *
     * @return void
     */
    public static function migrate_brand_assets_role(): void
    {
        global $wpdb;

        $table = self::table('brands');

        // Fetch all brands that have a non-empty assets payload.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $rows = $wpdb->get_results(
            "SELECT id, assets FROM {$table} WHERE assets IS NOT NULL AND assets != '' AND assets != '[]'",
            ARRAY_A
        );

        if (empty($rows)) {
            return;
        }

        foreach ($rows as $row) {
            $assets = json_decode($row['assets'], true);
            if (!is_array($assets) || empty($assets)) {
                continue;
            }

            $changed = false;
            foreach ($assets as $i => $asset) {
                if (!is_array($asset) || isset($asset['role'])) {
                    continue;
                }
                $assets[$i]['role'] = ($i === 0) ? 'logo' : 'reference';
                $changed = true;
            }

            if ($changed) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                $wpdb->update(
                    $table,
                    array('assets' => wp_json_encode($assets)),
                    array('id' => (int)$row['id']),
                    array('%s'),
                    array('%d')
                );
            }
        }
    }

    /**
     * v1.7.0 Migration: Convert existing SVG brand assets to PNG.
     *
     * AI image generation models (Flux, DALL-E, etc.) cannot process SVG
     * vector files. This migration finds all brand assets with mimeType
     * 'image/svg+xml', rasterizes them to PNG using Imagick, and updates
     * the database records with the new PNG URL and MIME type.
     *
     * Idempotent — only touches assets with svg MIME type.
     *
     * @return void
     */
    public static function migrate_brand_svg_to_png(): void
    {
        global $wpdb;

        $table = self::table('brands');

        // Only fetch brands with SVG assets
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $rows = $wpdb->get_results(
            "SELECT id, assets FROM {$table} WHERE assets LIKE '%svg%'",
            ARRAY_A
        );

        if (empty($rows)) {
            return;
        }

        foreach ($rows as $row) {
            $assets = json_decode($row['assets'], true);
            if (!is_array($assets) || empty($assets)) {
                continue;
            }

            $changed = false;
            foreach ($assets as $i => $asset) {
                if (!is_array($asset)) {
                    continue;
                }

                $mime = $asset['mimeType'] ?? '';
                if (!PCM_Image_Utils::is_svg($mime)) {
                    continue;
                }

                // Resolve the local file path from the URL
                $url = $asset['url'] ?? '';
                if (empty($url)) {
                    continue;
                }

                // Convert URL to local path
                $upload_dir = wp_upload_dir();
                $base_url = $upload_dir['baseurl'];
                $base_dir = $upload_dir['basedir'];

                if (!str_contains($url, $base_url)) {
                    // URL doesn't point to our uploads directory — skip
                    continue;
                }

                $relative = str_replace($base_url, '', $url);
                $local_path = $base_dir . $relative;

                if (!file_exists($local_path)) {
                    error_log('[PCM_Schema] SVG migration: file not found at ' . $local_path);
                    continue;
                }

                // Rasterize SVG → PNG
                $rasterized = PCM_Image_Utils::rasterize_svg_to_png($local_path);
                if (!$rasterized) {
                    error_log('[PCM_Schema] SVG migration: rasterization failed for brand ' . $row['id']);
                    continue;
                }

                // Update asset entry with PNG info
                $png_url = dirname($url) . '/' . $rasterized['url_filename'];
                $assets[$i]['url'] = $png_url;
                $assets[$i]['mimeType'] = 'image/png';
                $changed = true;

                error_log('[PCM_Schema] SVG migration: brand ' . $row['id'] . ' asset ' . ($asset['fileKey'] ?? '?') . ' converted to PNG.');
            }

            if ($changed) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                $wpdb->update(
                    $table,
                    array('assets' => wp_json_encode($assets)),
                    array('id' => (int)$row['id']),
                    array('%s'),
                    array('%d')
                );
            }
        }
    }

    /**
     * Migrate legacy approval set statuses to the v1.8.0 6-status taxonomy.
     *
     * Old taxonomy: draft / review / completed.
     * New taxonomy: draft / internal / client / approved / live / archived.
     *
     * Mapping (defined in PCM_Approvals_Service::LEGACY_STATUS_MAP):
     *   review    → client    (sets out for client review)
     *   completed → approved  (client signed off / round complete)
     *
     * Idempotent — touches only rows whose status is still a legacy value.
     * Safe to run on installs that have no approval_sets table yet.
     *
     * @return void
     */
    public static function migrate_approval_set_statuses(): void
    {
        global $wpdb;

        $table = self::table('approval_sets');

        // Defensive: skip if table doesn't exist on this install.
        $table_check = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
        if ($table_check !== $table) {
            return;
        }

        // PCM_Approvals_Service defines the legacy-to-new map. Load it.
        require_once PCM_PLUGIN_DIR . 'includes/modules/approvals/service.php';

        foreach (PCM_Approvals_Service::LEGACY_STATUS_MAP as $legacy => $next) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
            $wpdb->query(
                $wpdb->prepare(
                    "UPDATE {$table} SET status = %s WHERE status = %s",
                    $next,
                    $legacy
                )
            );
        }
    }

    /**
     * Drop all plugin tables.
     *
     * Only called when user explicitly deletes plugin data.
     * NOT called on deactivation (preserve data).
     *
     * @return void
     */
    public static function drop_tables(): void
    {
        global $wpdb;

        $prefix = self::prefix();
        $tables = array(
            'approval_sets',
            'deliveries',
            'sites',
            'articles',
            'strategy_items',
            'strategies',
            'prompt_overrides',
            'brand_assets',
            'brands',
            'templates',
            'copy_results',
            'copy_jobs',
            'models',
            'scraped_images',
            'scraped_collections',
            'assets',
            'projects',
            'integrations',
            'users',
        );

        foreach ($tables as $table) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange
            $wpdb->query("DROP TABLE IF EXISTS {$prefix}{$table}");
        }
    }
}
