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
        );
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
        $valid_statuses = array('draft', 'review', 'ready', 'published');
        if ($status && !in_array($status, $valid_statuses, true)) {
            return $this->error('Invalid status filter. Must be one of: ' . implode(', ', $valid_statuses));
        }

        $articles = PCM_DB::get_user_articles(
            (int) $pcm_user->id,
            $status ? sanitize_text_field($status) : null
        );

        return $this->success($articles);
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
        );

        $update = array();
        foreach ($allowed as $field) {
            if (isset($params[$field])) {
                // Content (HTML) gets wp_kses_post, text fields get sanitize_text_field
                if ($field === 'content') {
                    $update[$field] = wp_kses_post($params[$field]);
                } elseif ($field === 'seoScore') {
                    $update[$field] = (int) $params[$field];
                } else {
                    $update[$field] = sanitize_text_field($params[$field]);
                }
            }
        }

        if (empty($update)) {
            return $this->error('No valid fields to update.');
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
}
