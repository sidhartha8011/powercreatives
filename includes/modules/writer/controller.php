<?php
/**
 * Writer REST Controller
 *
 * CRUD for articles (documents) shown in the Writer editor.
 * Articles are created by the Strategy generation pipeline and
 * edited by users in the Tiptap-based Writer module.
 *
 * Endpoints:
 *   GET    /articles              List all articles (optional ?status= filter)
 *   GET    /articles/(?P<id>\d+)  Get a single article
 *   PATCH  /articles/(?P<id>\d+)  Update article content/metadata
 *   DELETE /articles/(?P<id>\d+)  Delete an article
 *
 * @package PowerCreatives
 */

if (!defined('ABSPATH')) {
    exit;
}

class PCM_REST_Writer extends PCM_REST_Base
{
    // Work module — usable by non-admin team members (assigned access).
    protected string $default_capability = 'edit_posts';
    // Per-delivery module grant ids (see PCM_REST_Base::$module_grant_keys).
    protected array $module_grant_keys = array('writer');

    protected function routes(): array
    {
        return array(
            array('GET', '/articles', 'list_articles'),
            array('POST', '/articles', 'create_article'),
            array('GET', '/articles/(?P<id>\d+)', 'get_article'),
            array('PATCH', '/articles/(?P<id>\d+)', 'update_article'),
            array('DELETE', '/articles/(?P<id>\d+)', 'delete_article'),
            array('POST', '/articles/generate', 'generate_article'),
            array('POST', '/articles/upload-image', 'upload_image'),
            array('POST', '/articles/(?P<id>\d+)/ai-review', 'ai_review'),
            array('POST', '/articles/(?P<id>\d+)/ai-review/apply', 'ai_review_apply'),
            array('GET', '/articles/(?P<id>\d+)/revisions', 'list_revisions'),
            array('GET', '/articles/(?P<id>\d+)/revisions/(?P<revId>\d+)', 'get_revision'),
            array('POST', '/articles/(?P<id>\d+)/revisions/(?P<revId>\d+)/restore', 'restore_revision'),
        );
    }

    /**
     * Decide whether an update should snapshot the article's PREVIOUS state.
     *
     * Pure decision helper (no DB / WP calls) so it is unit-testable: snapshot
     * only when the incoming payload carries a 'content' key AND that content
     * differs from the article's currently-stored content. A null article
     * (missing / not owned) never snapshots.
     *
     * @param array       $params  Incoming request params.
     * @param object|null $article The currently-stored article, or null.
     * @return bool
     */
    public static function should_snapshot(array $params, ?object $article): bool
    {
        if ($article === null || !array_key_exists('content', $params)) {
            return false;
        }
        return (string) $params['content'] !== (string) ($article->content ?? '');
    }

    /**
     * List all articles for the current user.
     * Supports optional ?status=draft|review|ready|published filter.
     */
    public function list_articles(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $pcm_user = $this->get_current_pcm_user();
        $status = $request->get_param('status');

        // Validate status if provided
        // (see fill_strategy_site_ids() below for the Target-Site backfill)
        $valid_statuses = array('draft', 'review', 'ready', 'published');
        if ($status && !in_array($status, $valid_statuses, true)) {
            return $this->error('Invalid status filter. Must be one of: ' . implode(', ', $valid_statuses));
        }

        $articles = PCM_DB::get_user_articles(
            (int) $pcm_user->id,
            $status ? sanitize_text_field($status) : null
        );

        return $this->success(self::fill_strategy_site_ids($articles, (int) $pcm_user->id));
    }

    /**
     * Fill in `siteId` for strategy-generated articles that don't carry one.
     *
     * Strategy generation now stamps the Target Site onto every article it creates, but
     * articles generated BEFORE that have `siteId` NULL — and the Writer's Publish
     * button reads exactly this field, so those drafts sat un-publishable even though
     * their strategy plainly had a site. Resolves from the owning strategy's
     * `config.siteId`.
     *
     * One extra query for the whole page (strategies fetched by id IN (...)), not one
     * per article. Purely additive: rows that already have a siteId are untouched, and
     * a strategy without a configured site leaves the field as it was.
     *
     * @param array $articles Article rows.
     * @param int   $user_id  Owner id (scopes the strategy lookup).
     * @return array The same rows, with siteId filled where it could be resolved.
     */
    private static function fill_strategy_site_ids(array $articles, int $user_id): array
    {
        $needed = array();
        foreach ($articles as $a) {
            if (empty($a->siteId) && !empty($a->strategyId)) {
                $needed[(int) $a->strategyId] = true;
            }
        }
        if (empty($needed)) {
            return $articles;
        }

        global $wpdb;
        $table = PCM_Schema::table('strategies');
        $ids   = array_map('intval', array_keys($needed));
        $ph    = implode(',', array_fill(0, count($ids), '%d'));
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT id, config FROM {$table} WHERE userId = %d AND id IN ($ph)",
            array_merge(array($user_id), $ids)
        ));

        $site_by_strategy = array();
        foreach ((array) $rows as $row) {
            $cfg = json_decode((string) $row->config, true);
            if (is_array($cfg) && !empty($cfg['siteId'])) {
                $site_by_strategy[(int) $row->id] = (int) $cfg['siteId'];
            }
        }
        if (empty($site_by_strategy)) {
            return $articles;
        }

        foreach ($articles as $a) {
            if (empty($a->siteId) && !empty($a->strategyId) && isset($site_by_strategy[(int) $a->strategyId])) {
                $a->siteId = $site_by_strategy[(int) $a->strategyId];
            }
        }
        return $articles;
    }

    /**
     * Create a new article.
     *
     * Accepts title and optional metadata. Content starts empty — the user
     * writes or generates it in the editor. Follows the same pattern as
     * Deliveries and Strategy controllers.
     */
    public function create_article(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $pcm_user = $this->get_current_pcm_user();
        $params   = $request->get_json_params();

        if (empty($params['title'])) {
            return $this->error('Title is required.');
        }

        // Whitelist fields that can be set at creation time
        $data = array(
            'userId' => (int) $pcm_user->id,
            'title'  => sanitize_text_field($params['title']),
            'slug'   => sanitize_text_field($params['slug'] ?? ''),
            'status' => 'draft',
        );

        // Optional fields — only include if provided
        $optional_text = array('content', 'metaTitle', 'metaDescription', 'schemaType', 'featuredImage');
        foreach ($optional_text as $field) {
            if (isset($params[$field])) {
                $data[$field] = $field === 'content'
                    ? wp_kses_post($params[$field])
                    : sanitize_text_field($params[$field]);
            }
        }

        // Optional integer references
        if (isset($params['brandId'])) {
            $data['brandId'] = (int) $params['brandId'];
        }
        if (isset($params['strategyId'])) {
            $data['strategyId'] = (int) $params['strategyId'];
        }
        if (isset($params['siteId'])) {
            $data['siteId'] = (int) $params['siteId'];
        }

        $article_id = PCM_DB::create_article($data);
        if (!$article_id) {
            return $this->error('Failed to create article.', 500);
        }

        // Return the full created article
        $article = PCM_DB::get_article($article_id, (int) $pcm_user->id);
        return $this->success($article);
    }

    /**
     * Get a single article by ID.
     */
    public function get_article(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $pcm_user = $this->get_current_pcm_user();
        $article_id = (int) $request->get_param('id');

        $article = PCM_DB::get_article($article_id, (int) $pcm_user->id);
        if (!$article) {
            return $this->not_found('Article');
        }

        return $this->success($article);
    }

    /**
     * Update an article's content, metadata, or status.
     * Supports partial updates — only provided fields are changed.
     */
    public function update_article(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $pcm_user = $this->get_current_pcm_user();
        $article_id = (int) $request->get_param('id');
        $params = $request->get_json_params();

        // Whitelist updateable fields
        $allowed = array(
            'title',
            'slug',
            'content',
            'metaTitle',
            'metaDescription',
            'schemaType',
            'status',
            'featuredImage',
            'seoScore',
            'siteId',
        );

        $update = array();
        foreach ($allowed as $field) {
            if (isset($params[$field])) {
                // Content (HTML) gets wp_kses_post, text fields get sanitize_text_field
                if ($field === 'content') {
                    $update[$field] = wp_kses_post($params[$field]);
                } elseif ($field === 'seoScore' || $field === 'siteId') {
                    $update[$field] = (int) $params[$field];
                } else {
                    $update[$field] = sanitize_text_field($params[$field]);
                }
            }
        }

        if (empty($update)) {
            return $this->error('No valid fields to update.');
        }

        // Capture the PREVIOUS title+content into the revision history before
        // applying, but only when the update actually changes the content
        // (ownership is enforced by loading the article as the current user).
        $existing = PCM_DB::get_article($article_id, (int) $pcm_user->id);
        if (self::should_snapshot($params, $existing)) {
            PCM_DB::add_article_revision(
                $article_id,
                (int) $pcm_user->id,
                (string) $existing->title,
                (string) $existing->content,
                'editor'
            );
        }

        $success = PCM_DB::update_article($article_id, (int) $pcm_user->id, $update);
        if (!$success) {
            return $this->not_found('Article');
        }

        // Return updated article
        $article = PCM_DB::get_article($article_id, (int) $pcm_user->id);
        return $this->success($article);
    }

    /**
     * Delete an article.
     */
    public function delete_article(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $pcm_user = $this->get_current_pcm_user();
        $article_id = (int) $request->get_param('id');

        $success = PCM_DB::delete_article($article_id, (int) $pcm_user->id);
        if (!$success) {
            return $this->not_found('Article');
        }

        return $this->success(array('deleted' => true));
    }

    /**
     * Generate an article using LLM.
     *
     * Takes Writer context (keyword, brand, prompt, settings) and returns
     * structured article output (title, content HTML, metaTitle, metaDescription).
     *
     * Does NOT save to DB — the frontend populates the editor, giving the
     * user control to review and edit before saving.
     */
    public function generate_article(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $pcm_user = $this->get_current_pcm_user();
        $params = $request->get_json_params();

        if (empty($params['primaryKeyword'])) {
            return $this->error('Primary keyword is required.');
        }

        // ── Begin Event-Driven Pipeline ──
        // This keeps the connection alive beyond typical timeout limits 
        // via Server-Sent Events (SSE).
        PCM_SSE::start(600);

        try {
            require_once __DIR__ . '/service.php';
            $result = PCM_Writer_Service::generate($params, (int) $pcm_user->id);
            
            // Push final structured response and end the stream
            PCM_SSE::send_done($result);
        } catch (\Throwable $e) {
            PCM_SSE::send_error('Generation failed: ' . $e->getMessage());
        }

        // Satisfy PHP return type — execution usually exits via send_done/send_error.
        return new WP_REST_Response();
    }

    /**
     * AI Review — return structured edit suggestions for an article.
     *
     * Optional `feedback` (textarea) steers the review; empty means a general
     * editorial pass. Suggestions are pre-validated server-side: every `find`
     * has a safe occurrence in the current content, so each row the UI shows
     * is applyable via ai_review_apply().
     */
    public function ai_review(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $pcm_user   = $this->get_current_pcm_user();
        $article_id = (int) $request->get_param('id');

        $article = PCM_DB::get_article($article_id, (int) $pcm_user->id);
        if (!$article) {
            return $this->not_found('Article');
        }
        if (trim((string) $article->content) === '') {
            return $this->error('Article has no content to review.');
        }

        $params   = $request->get_json_params();
        $feedback = sanitize_textarea_field((string) ($params['feedback'] ?? ''));

        try {
            require_once __DIR__ . '/class-pcm-article-review.php';
            $suggestions = PCM_Article_Review::review((string) $article->content, $feedback, (int) $pcm_user->id);
            return $this->success(array('suggestions' => $suggestions));
        } catch (\Throwable $e) {
            return $this->error('AI review failed: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Apply one AI-review suggestion: surgical first-safe-occurrence replace
     * in the stored article HTML, then persist. Returns the updated article
     * so the editor can re-sync its content.
     *
     * `find` is intentionally NOT run through sanitize_text_field — it is only
     * a search needle (never stored or echoed) and must match the stored
     * content byte-for-byte. `replacement` IS the security boundary (it lands
     * in stored HTML) and goes through wp_kses_post like every other content
     * write in this controller.
     */
    public function ai_review_apply(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $pcm_user   = $this->get_current_pcm_user();
        $article_id = (int) $request->get_param('id');

        $article = PCM_DB::get_article($article_id, (int) $pcm_user->id);
        if (!$article) {
            return $this->not_found('Article');
        }

        $params = $request->get_json_params();
        $find   = (string) wp_check_invalid_utf8((string) ($params['find'] ?? ''));
        if (trim($find) === '') {
            return $this->error('The text to replace is required.');
        }
        $replacement = wp_kses_post((string) ($params['replacement'] ?? ''));

        require_once __DIR__ . '/class-pcm-article-review.php';
        $updated_content = PCM_Article_Review::apply((string) $article->content, $find, $replacement);
        if ($updated_content === null) {
            return $this->error('That text could no longer be safely located in the article — it may have been edited since the review.', 409);
        }

        // Snapshot the PREVIOUS state before the AI edit lands, when it changes
        // the content (same differs-check as the editor path, source 'ai-review').
        if (self::should_snapshot(array('content' => $updated_content), $article)) {
            PCM_DB::add_article_revision(
                $article_id,
                (int) $pcm_user->id,
                (string) $article->title,
                (string) $article->content,
                'ai-review'
            );
        }

        $success = PCM_DB::update_article($article_id, (int) $pcm_user->id, array(
            'content' => wp_kses_post($updated_content),
        ));
        if (!$success) {
            return $this->error('Failed to save the updated article.', 500);
        }

        $article = PCM_DB::get_article($article_id, (int) $pcm_user->id);
        return $this->success($article);
    }

    /**
     * Upload an image to the WordPress Media Library via API.
     * Takes a base64 encoded file and passes it to the service for processing.
     */
    public function upload_image(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $pcm_user = $this->get_current_pcm_user();
        $params = $request->get_json_params();

        if (empty($params['fileData']) || empty($params['filename']) || empty($params['mimeType'])) {
            return $this->error('Missing file data, filename, or mimeType.');
        }

        try {
            require_once __DIR__ . '/service.php';
            $result = PCM_Writer_Service::upload_image($params, (int) $pcm_user->id);
            return $this->success($result);
        } catch (\Throwable $e) {
            return $this->error('Failed to upload image: ' . $e->getMessage(), 500);
        }
    }

    /**
     * List an article's revision history, newest first.
     *
     * Returns lightweight rows (id, title, source, createdAt, 160-char excerpt)
     * — never the full stored content. Ownership is enforced in PCM_DB.
     */
    public function list_revisions(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $pcm_user   = $this->get_current_pcm_user();
        $article_id = (int) $request->get_param('id');

        $article = PCM_DB::get_article($article_id, (int) $pcm_user->id);
        if (!$article) {
            return $this->not_found('Article');
        }

        $revisions = PCM_DB::get_article_revisions($article_id, (int) $pcm_user->id);
        return $this->success($revisions);
    }

    /**
     * Get a single revision WITH its full title + content, so the editor can
     * preview or diff it. Ownership is enforced via the parent article.
     */
    public function get_revision(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $pcm_user    = $this->get_current_pcm_user();
        $article_id  = (int) $request->get_param('id');
        $revision_id = (int) $request->get_param('revId');

        $revision = PCM_DB::get_article_revision($revision_id, (int) $pcm_user->id);
        if (!$revision || (int) $revision->articleId !== $article_id) {
            return $this->not_found('Revision');
        }

        return $this->success($revision);
    }

    /**
     * Restore an article to a previous revision.
     *
     * Snapshots the CURRENT state first (source 'restore') so the restore is
     * itself reversible, then writes the revision's title + content onto the
     * article (content re-sanitized via wp_kses_post). Returns the updated
     * article so the editor can re-sync.
     */
    public function restore_revision(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $pcm_user    = $this->get_current_pcm_user();
        $article_id  = (int) $request->get_param('id');
        $revision_id = (int) $request->get_param('revId');

        $article = PCM_DB::get_article($article_id, (int) $pcm_user->id);
        if (!$article) {
            return $this->not_found('Article');
        }

        $revision = PCM_DB::get_article_revision($revision_id, (int) $pcm_user->id);
        if (!$revision || (int) $revision->articleId !== $article_id) {
            return $this->not_found('Revision');
        }

        // Snapshot the current state so the restore can itself be undone.
        PCM_DB::add_article_revision(
            $article_id,
            (int) $pcm_user->id,
            (string) $article->title,
            (string) $article->content,
            'restore'
        );

        $success = PCM_DB::update_article($article_id, (int) $pcm_user->id, array(
            'title'   => sanitize_text_field((string) $revision->title),
            'content' => wp_kses_post((string) $revision->content),
        ));
        if (!$success) {
            return $this->error('Failed to restore the revision.', 500);
        }

        $article = PCM_DB::get_article($article_id, (int) $pcm_user->id);
        return $this->success($article);
    }
}
