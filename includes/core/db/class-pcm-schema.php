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
 *   - seo_views       → Per-user saved SEO content table views
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
            username varchar(191) DEFAULT NULL,
            passwordHash varchar(255) DEFAULT NULL,
            name varchar(256) DEFAULT NULL,
            email varchar(256) DEFAULT NULL,
            role varchar(50) DEFAULT 'user' NOT NULL,
            avatarUrl text DEFAULT NULL,
            notificationsSeenAt datetime DEFAULT NULL,
            notificationsClearedAt datetime DEFAULT NULL,
            createdAt datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
            updatedAt datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
            lastSignedIn datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
            PRIMARY KEY  (id),
            KEY idx_openId (openId),
            UNIQUE KEY idx_username (username)
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
        // deliveryId (v1.29.0): a Project belongs to a Delivery (Brand → Delivery → Project).
        // Anything attached to a project derives its delivery + brand LIVE from this chain
        // (see PCM_Hierarchy) — never stored/hardcoded — so reassigning the project's delivery
        // (or that delivery's brand) moves everything that belongs to the project with it.
        // siteId (v1.34.0): FK to the {$prefix}sites table (WP connection credentials) —
        // NULL = not connected. NOT the SEO Hub tenant site (that is deliveries.seoSiteId).
        // N:1 on purpose: many projects may connect to the same site. Read the link only via
        // PCM_Hierarchy::site_for_project() / projects_for_site().
        $sql = "CREATE TABLE {$prefix}projects (
            id int(11) NOT NULL AUTO_INCREMENT,
            userId int(11) NOT NULL,
            name varchar(256) NOT NULL,
            description text DEFAULT NULL,
            status varchar(50) DEFAULT 'active' NOT NULL,
            deliveryId int(11) DEFAULT NULL,
            siteId int(11) DEFAULT NULL,
            settings text DEFAULT NULL,
            externalId varchar(191) DEFAULT NULL,
            createdAt datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
            updatedAt datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
            PRIMARY KEY  (id),
            KEY idx_userId (userId),
            KEY idx_deliveryId (deliveryId),
            KEY idx_siteId (siteId)
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
            inputPrice decimal(12,4) DEFAULT NULL,
            outputPrice decimal(12,4) DEFAULT NULL,
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
            clientEmail varchar(256) DEFAULT NULL,
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
            externalId varchar(191) DEFAULT NULL,
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

        // ── Brand business units (Business Spine P1, v1.43.0) ──
        // A brand's 0..n physical/logical locations; exactly one PRIMARY.
        // Two-layer storage per unit (the proven refresh-survival law):
        // `fetched` = auto-sourced fields JSON, `manual` = human corrections
        // JSON (always win, survive every refresh), `sources` = per-key
        // origin of fetched values (gbp / scrape / maps-paste / platform).
        // Brands never reference sites — sites carry businessUnitId and
        // CONSUME this table (owner dependency ruling 2026-07-17).
        $sql = "CREATE TABLE {$prefix}brand_business_units (
            id int(11) NOT NULL AUTO_INCREMENT,
            brandId int(11) NOT NULL,
            label varchar(256) DEFAULT '' NOT NULL,
            isPrimary tinyint(1) DEFAULT 0 NOT NULL,
            fetched longtext DEFAULT NULL,
            manual longtext DEFAULT NULL,
            sources longtext DEFAULT NULL,
            createdAt datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
            updatedAt datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
            PRIMARY KEY  (id),
            KEY idx_brandId (brandId)
        ) $charset_collate;";
        dbDelta($sql);

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
        // scheduledDate (v1.37.0): when publishingMode='schedule', the due date this
        // item should generate+publish on; a cron scanner (PCM_Strategy_Service)
        // queries items where scheduledDate <= now. NULL for non-scheduled items.
        // setId (v1.38.0): when the strategy's approvalMode != 'none', links this
        // item to the Approvals module set created for its generated article
        // ('in_review' status). NULL until an approval set is created for it.
        // volume/difficulty (v1.39.0): display-only SEO metrics carried from the
        // Keyword Explorer onto each item at creation (Ahrefs search volume +
        // keyword difficulty). Purely additive + nullable, no index — the
        // Strategies UI surfaces them next to the keyword; nothing queries or
        // filters on them. NULL when the source keyword was never enriched.
        $sql = "CREATE TABLE {$prefix}strategy_items (
            id int(11) NOT NULL AUTO_INCREMENT,
            strategyId int(11) NOT NULL,
            userId int(11) NOT NULL,
            keyword varchar(512) NOT NULL,
            title varchar(512) DEFAULT NULL,
            slug varchar(512) DEFAULT NULL,
            status varchar(50) DEFAULT 'pending' NOT NULL,
            articleId int(11) DEFAULT NULL,
            setId int(11) DEFAULT NULL,
            position int(11) DEFAULT 0 NOT NULL,
            errorMessage text DEFAULT NULL,
            config text DEFAULT NULL,
            scheduledDate datetime DEFAULT NULL,
            volume int(11) DEFAULT NULL,
            difficulty int(11) DEFAULT NULL,
            createdAt datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
            updatedAt datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
            PRIMARY KEY  (id),
            KEY idx_strategyId (strategyId),
            KEY idx_userId (userId),
            KEY idx_status (status),
            KEY idx_scheduledDate (scheduledDate),
            KEY idx_setId (setId)
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
            connectMethod varchar(20) DEFAULT 'password' NOT NULL,
            brandId int(11) DEFAULT NULL,
            businessUnitId int(11) DEFAULT NULL,
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
        // brandId/projectId (v1.18.0) link the delivery to the client's brand
        // and project so assigning the delivery grants view+use access to both.
        // seoSiteId — DEPRECATED v1.35.0: write-only field no module ever read.
        // Do not wire it up again; site resolution derives live via
        // PCM_Hierarchy (delivery → its projects → projects.siteId).
        $sql = "CREATE TABLE {$prefix}deliveries (
            id int(11) NOT NULL AUTO_INCREMENT,
            userId int(11) NOT NULL,
            name varchar(256) NOT NULL,
            clientName varchar(256) DEFAULT NULL,
            status varchar(50) DEFAULT 'active' NOT NULL,
            type varchar(64) DEFAULT NULL,
            brandId int(11) DEFAULT NULL,
            projectId int(11) DEFAULT NULL,
            seoSiteId int(11) DEFAULT NULL,
            modules text DEFAULT NULL,
            externalId varchar(191) DEFAULT NULL,
            createdAt datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
            updatedAt datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
            PRIMARY KEY  (id),
            KEY idx_userId (userId),
            KEY idx_status (status)
        ) $charset_collate;";
        dbDelta($sql);

        // ── Notifications (v1.19.0) ──
        // One row per approval-flow EVENT (comment / approval) — no per-user
        // fan-out. Visibility is computed at read time: admins see all rows,
        // others see rows they own or whose brandId is granted to them via a
        // delivery assignment. Read-state = users.notificationsSeenAt anchor.
        $sql = "CREATE TABLE {$prefix}notifications (
            id int(11) NOT NULL AUTO_INCREMENT,
            ownerId int(11) NOT NULL,
            brandId int(11) DEFAULT NULL,
            setId int(11) NOT NULL,
            type varchar(30) NOT NULL,
            title varchar(256) NOT NULL,
            excerpt text DEFAULT NULL,
            link text DEFAULT NULL,
            createdAt datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
            PRIMARY KEY  (id),
            KEY idx_ownerId (ownerId),
            KEY idx_brandId (brandId),
            KEY idx_createdAt (createdAt)
        ) $charset_collate;";
        dbDelta($sql);

        // ── Delivery assignments (v1.18.0) ──
        // Team access: an admin assigns a delivery to a PCM user (userId =
        // assignee). The assignee gains VIEW + USE access to the delivery and
        // its linked brand/project (reads become "owned OR granted" via
        // PCM_Access; writes stay owner-scoped).
        // role (v1.36.0): 'lead' marks the delivery's single lead; every other
        // assignment is 'member'. Access/grants ignore role — a lead is just
        // an assignment with a badge, so PCM_Access needs no changes.
        $sql = "CREATE TABLE {$prefix}delivery_assignments (
            id int(11) NOT NULL AUTO_INCREMENT,
            deliveryId int(11) NOT NULL,
            userId int(11) NOT NULL,
            assignedBy int(11) NOT NULL,
            role varchar(20) DEFAULT 'member' NOT NULL,
            createdAt datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY uniq_delivery_user (deliveryId,userId),
            KEY idx_userId (userId)
        ) $charset_collate;";
        dbDelta($sql);

        // ── Delivery logs (v1.35.0) ──
        // Free-text work log per delivery ("what was done") shown in the
        // delivery card. Owned by the Deliveries module; append-only notes,
        // one row per entry, author = pcm user id.
        $sql = "CREATE TABLE {$prefix}delivery_logs (
            id int(11) NOT NULL AUTO_INCREMENT,
            deliveryId int(11) NOT NULL,
            userId int(11) NOT NULL,
            note text NOT NULL,
            createdAt datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
            PRIMARY KEY  (id),
            KEY idx_deliveryId (deliveryId),
            KEY idx_createdAt (createdAt)
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
            deliveryId int(11) DEFAULT NULL,
            name varchar(256) NOT NULL,
            token varchar(128) NOT NULL,
            status varchar(50) DEFAULT 'draft' NOT NULL,
            clientEmail varchar(256) DEFAULT NULL,
            clientSentAt datetime DEFAULT NULL,
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

        // ── Automations ──
        // User-defined IF-THEN rules for the Automations module. Each row binds
        // a `triggerId` (e.g. 'approvals.set_status_changed') + JSON `conditions`
        // (e.g. {"status":"launch"}) to an `actionId` (e.g. 'webhook') + JSON
        // `config` (e.g. {"url":"…","secret":"…"}). An optional `brandId` scopes
        // a rule to one brand; NULL = account-wide. `triggerId`/`actionId` avoid
        // the SQL reserved words `trigger`/`action`. The legacy `event`/`channel`
        // columns (v1.14.0) are retained for the built-in approval notifications
        // and are nullable for rule rows.
        $sql = "CREATE TABLE {$prefix}automations (
            id int(11) NOT NULL AUTO_INCREMENT,
            userId int(11) NOT NULL,
            brandId int(11) DEFAULT NULL,
            name varchar(191) DEFAULT NULL,
            triggerId varchar(64) DEFAULT NULL,
            conditions longtext DEFAULT NULL,
            actionId varchar(64) DEFAULT NULL,
            inputMapping longtext DEFAULT NULL,
            event varchar(64) DEFAULT NULL,
            channel varchar(32) DEFAULT NULL,
            config longtext DEFAULT NULL,
            isActive tinyint(1) DEFAULT 1 NOT NULL,
            createdAt datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
            updatedAt datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
            PRIMARY KEY  (id),
            KEY idx_userId (userId),
            KEY idx_triggerId (triggerId)
        ) $charset_collate;";
        dbDelta($sql);

        // v1.15.0: the v1.14.0 table created event/channel as NOT NULL. dbDelta
        // cannot relax nullability, so ALTER them to DEFAULT NULL on existing
        // installs (rule rows use triggerId/actionId, not event/channel).
        self::migrate_automations_columns($prefix);

        // ── Automation Logs ──
        // Append-only dispatch audit trail. Powers idempotency checks
        // (e.g. fire `approval.all_approved` only once per set) and debugging.
        // Stores a `payloadHash` (sha256), never the raw payload or secrets.
        $sql = "CREATE TABLE {$prefix}automation_logs (
            id int(11) NOT NULL AUTO_INCREMENT,
            userId int(11) NOT NULL,
            automationId int(11) DEFAULT NULL,
            event varchar(64) NOT NULL,
            channel varchar(32) NOT NULL,
            target varchar(255) DEFAULT NULL,
            dedupeKey varchar(191) DEFAULT NULL,
            payloadHash char(64) DEFAULT NULL,
            status varchar(16) NOT NULL,
            httpCode int(11) DEFAULT NULL,
            error text DEFAULT NULL,
            createdAt datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
            PRIMARY KEY  (id),
            KEY idx_userId (userId),
            KEY idx_event (event),
            KEY idx_dedupeKey (dedupeKey)
        ) $charset_collate;";
        dbDelta($sql);

        // ── SEO Hub: managed remote sites (tenants) + HMAC replay nonces (v1.23.0) ──
        // Each remote WP site installs a generated connector plugin that
        // registers with this hub via an HMAC-signed handshake; we then proxy
        // to it using a captured Application Password.
        $sql = "CREATE TABLE {$prefix}seo_tenants (
            id int(11) NOT NULL AUTO_INCREMENT,
            createdBy int(11) DEFAULT 0 NOT NULL,
            clientId varchar(64) NOT NULL,
            clientSecret varchar(128) NOT NULL,
            name varchar(255) DEFAULT NULL,
            domain varchar(255) DEFAULT NULL,
            siteUrl varchar(500) DEFAULT NULL,
            status varchar(16) DEFAULT 'pending' NOT NULL,
            appUser varchar(255) DEFAULT NULL,
            appPassword varchar(255) DEFAULT NULL,
            wpVersion varchar(20) DEFAULT NULL,
            phpVersion varchar(20) DEFAULT NULL,
            adminEmail varchar(320) DEFAULT NULL,
            lastPingAt datetime DEFAULT NULL,
            createdAt datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
            updatedAt datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY uq_clientId (clientId),
            KEY idx_status (status),
            KEY idx_domain (domain)
        ) $charset_collate;";
        dbDelta($sql);

        $sql = "CREATE TABLE {$prefix}seo_hmac_nonces (
            id int(11) NOT NULL AUTO_INCREMENT,
            nonce varchar(64) NOT NULL,
            createdAt datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY uq_nonce (nonce),
            KEY idx_createdAt (createdAt)
        ) $charset_collate;";
        dbDelta($sql);

        // ── SEO Views (v1.26.0) ──
        // Per-PCM-user saved configurations of the SEO content table: which
        // columns are visible + the active per-column filters. `config` stores
        // an arbitrary JSON object ({columns, filters}) via wp_json_encode and
        // is decoded on read.
        $sql = "CREATE TABLE {$prefix}seo_views (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            userId bigint(20) unsigned NOT NULL,
            name varchar(191) NOT NULL,
            config longtext NOT NULL,
            isDefault tinyint(1) DEFAULT 0 NOT NULL,
            createdAt datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
            updatedAt datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
            PRIMARY KEY  (id),
            KEY userId (userId)
        ) $charset_collate;";
        dbDelta($sql);

        // ── SEO Dynamic Rules (v1.37.0) ──
        // The hub's source of truth for render-time content rules served by the
        // connector (rule schema v1 — docs/DYNAMIC-OPTIMIZATION-ARCHITECTURE.md).
        // A rule swaps ONE block's visible text on ONE remote post at render
        // time; matchText is stored ALREADY normalized (normalization spec v1).
        // changesetId is NULLABLE ON PURPOSE: the approval/version machine
        // (pair 5) groups rules into changesets — the column exists from day
        // one so that lands additively, never as a migration of meaning.
        $sql = "CREATE TABLE {$prefix}seo_dynamic_rules (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            userId bigint(20) unsigned NOT NULL,
            siteId int(11) NOT NULL,
            postId int(11) NOT NULL,
            target varchar(20) DEFAULT 'paragraph' NOT NULL,
            matchText text NOT NULL,
            occurrence int(11) DEFAULT 0 NOT NULL,
            replacement longtext NOT NULL,
            anchorContext text DEFAULT NULL,
            active tinyint(1) DEFAULT 1 NOT NULL,
            staleCount int(11) DEFAULT 0 NOT NULL,
            changesetId bigint(20) unsigned DEFAULT NULL,
            sourceChangeId bigint(20) unsigned DEFAULT NULL,
            createdAt datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
            updatedAt datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
            PRIMARY KEY  (id),
            KEY idx_site_post (siteId, postId),
            KEY idx_changesetId (changesetId),
            KEY idx_userId (userId)
        ) $charset_collate;";
        dbDelta($sql);

        // ── SEO section version history (DB 1.38.0) ──
        // One row per ACCEPTED save — the editors' version dropdowns. Keyed by
        // IDENTITY (target + matchText + occurrence), NOT the rule id: a clean
        // revert DELETES the rule row, and history must survive it. Targets:
        // 'section' (matchText = heading key) and 'page' (matchText = '',
        // replacement = the whole page document as saved).
        // "Original" is never stored — it is always read live from the scan.
        // NOT the changeset system (phase 2 deployment grouping) — this is
        // pure per-section edit history; the two layers stay separate.
        $sql = "CREATE TABLE {$prefix}seo_rule_versions (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            userId bigint(20) unsigned NOT NULL,
            siteId int(11) NOT NULL,
            postId int(11) NOT NULL,
            target varchar(20) DEFAULT 'section' NOT NULL,
            matchText text NOT NULL,
            occurrence int(11) DEFAULT 0 NOT NULL,
            replacement longtext NOT NULL,
            createdAt datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
            PRIMARY KEY  (id),
            KEY idx_section (siteId, postId),
            KEY idx_userId (userId)
        ) $charset_collate;";
        dbDelta($sql);

        // ── SEO slug-change redirects (DB 1.39.0) ──
        // Hub-owned store; the connector serves a pushed COPY (full-set replace,
        // same law as rules). UPSERT identity = siteId + fromPath (normalized via
        // PCM_Text_Matcher::normalize_path — harness-pinned to the connector's
        // matcher). code is whitelisted 301/302/307/308 at every boundary.
        $sql = "CREATE TABLE {$prefix}seo_redirects (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            userId bigint(20) unsigned NOT NULL,
            siteId int(11) NOT NULL,
            fromPath text NOT NULL,
            toUrl text NOT NULL,
            code smallint(5) unsigned DEFAULT 301 NOT NULL,
            active tinyint(1) DEFAULT 1 NOT NULL,
            createdAt datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
            PRIMARY KEY  (id),
            KEY idx_site (siteId),
            KEY idx_userId (userId)
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
     * Business Spine P1 (v1.43.0): move per-brand GBP option records
     * (`pcm_seo_gbp_{brandId}` = {gbp, overrides}) into the brand's PRIMARY
     * business unit (fetched = gbp, manual = overrides, sources = all-'gbp').
     * Idempotent: a brand that already has units is skipped; each option is
     * DELETED only after its unit row landed (the named deletion that ships
     * with its replacement). Installs with no options no-op honestly.
     *
     * @return void
     */
    public static function migrate_gbp_to_business_units(): void
    {
        global $wpdb;
        $units = self::table('brand_business_units');
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $names = $wpdb->get_col(
            "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE 'pcm\\_seo\\_gbp\\_%'"
        );
        foreach (($names ?: array()) as $name) {
            $brand_id = (int) substr((string) $name, strlen('pcm_seo_gbp_'));
            if ($brand_id <= 0) {
                continue;
            }
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $has = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$units} WHERE brandId = %d",
                $brand_id
            ));
            if ($has === 0) {
                $stored    = get_option($name, array());
                $gbp       = is_array($stored['gbp'] ?? null) ? $stored['gbp'] : array();
                $overrides = is_array($stored['overrides'] ?? null) ? $stored['overrides'] : array();
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                $wpdb->insert($units, array(
                    'brandId'   => $brand_id,
                    'label'     => '',
                    'isPrimary' => 1,
                    'fetched'   => wp_json_encode($gbp),
                    'manual'    => wp_json_encode($overrides),
                    'sources'   => wp_json_encode(array_fill_keys(array_keys($gbp), 'gbp')),
                ), array('%d', '%s', '%d', '%s', '%s', '%s'));
            }
            delete_option($name);
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
     * Relax automations.event / .channel to nullable (v1.15.0).
     *
     * The v1.14.0 table created these NOT NULL. User-defined rule rows use
     * triggerId/actionId instead, so event/channel must be nullable. dbDelta
     * cannot change nullability, so ALTER explicitly. Idempotent + best-effort
     * (skips columns already nullable). Note: PCM_Automation_Engine::create_rule
     * also mirrors triggerId/actionId into event/channel, so inserts succeed even
     * where the driver does not support the ALTER.
     *
     * @param string $prefix Table prefix.
     * @return void
     */
    private static function migrate_automations_columns(string $prefix): void
    {
        global $wpdb;

        $table = "{$prefix}automations";
        $columns = $wpdb->get_results("SHOW COLUMNS FROM {$table}", ARRAY_A);
        if (empty($columns)) {
            return;
        }

        foreach ($columns as $col) {
            if ($col['Field'] === 'event' && $col['Null'] === 'NO') {
                $wpdb->query("ALTER TABLE {$table} MODIFY `event` varchar(64) DEFAULT NULL");
            }
            if ($col['Field'] === 'channel' && $col['Null'] === 'NO') {
                $wpdb->query("ALTER TABLE {$table} MODIFY `channel` varchar(32) DEFAULT NULL");
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
     * Backfill projects.deliveryId (v1.29.0). A project inherits the delivery that currently
     * references it (deliveries.projectId — the legacy delivery-centric link), so existing data
     * gains the Project → Delivery chain. Idempotent: only fills projects with no delivery yet.
     *
     * @return void
     */
    public static function migrate_backfill_project_delivery(): void
    {
        global $wpdb;
        $projects   = self::table('projects');
        $deliveries = self::table('deliveries');

        // Defensive: needs the new column (added by dbDelta in create_tables, which runs first).
        $col = $wpdb->get_var($wpdb->prepare('SHOW COLUMNS FROM ' . $projects . ' LIKE %s', 'deliveryId'));
        if (!$col) {
            return;
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
        $wpdb->query(
            "UPDATE {$projects} p
             JOIN (
                 SELECT projectId, MAX(id) AS did
                 FROM {$deliveries}
                 WHERE projectId IS NOT NULL
                 GROUP BY projectId
             ) d ON d.projectId = p.id
             SET p.deliveryId = d.did
             WHERE p.deliveryId IS NULL"
        );
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
            'automation_logs',
            'automations',
            'approval_sets',
            'notifications',
            'seo_views',
            'seo_dynamic_rules',
            'seo_tenants',
            'seo_hmac_nonces',
            'delivery_assignments',
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
