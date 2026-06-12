<?php
/**
 * Sites REST Controller
 *
 * CRUD for connected WordPress sites + publish action.
 *
 * Endpoints:
 *   GET    /sites                          List all sites
 *   POST   /sites                          Add a new site
 *   GET    /sites/(?P<id>\d+)              Get a site
 *   PATCH  /sites/(?P<id>\d+)              Update a site
 *   DELETE /sites/(?P<id>\d+)              Delete a site
 *   POST   /sites/(?P<id>\d+)/publish      Publish an article to this site
 *   POST   /sites/(?P<id>\d+)/test         Test site connection
 *
 * @package PowerCreatives
 */

if (!defined('ABSPATH')) {
    exit;
}

class PCM_REST_Sites extends PCM_REST_Base
{
    // Work module — usable by non-admin team members (assigned access).
    protected string $default_capability = 'edit_posts';
    // Per-delivery module grant ids (see PCM_REST_Base::$module_grant_keys).
    protected array $module_grant_keys = array('sites');

    protected function routes(): array
    {
        return array(
            array('GET',    '/sites',                         'list_sites'),
            array('POST',   '/sites',                         'create_site'),
            array('GET',    '/sites/(?P<id>\d+)',              'get_site'),
            array('PATCH',  '/sites/(?P<id>\d+)',              'update_site'),
            array('DELETE', '/sites/(?P<id>\d+)',              'delete_site'),
            array('POST',   '/sites/(?P<id>\d+)/publish',      'publish_article'),
            array('POST',   '/sites/(?P<id>\d+)/test',         'test_connection'),
        );
    }

    /**
     * List all sites for the current user.
     * Strips appPassword from the response for security.
     */
    public function list_sites(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $pcm_user = $this->get_current_pcm_user();
        $sites = PCM_DB::get_user_sites((int)$pcm_user->id);

        // Never expose passwords in list responses
        $safe = array_map(function ($site) {
            $s = (array)$site;
            $s['appPassword'] = '••••••••';
            return $s;
        }, $sites);

        return $this->success($safe);
    }

    /**
     * Add a new WordPress site connection.
     *
     * Expected body: { name, url, username, appPassword }
     */
    public function create_site(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $pcm_user = $this->get_current_pcm_user();
        $params = $request->get_json_params();

        // Validate
        if (empty($params['name'])) {
            return $this->error('Site name is required.');
        }
        if (empty($params['url'])) {
            return $this->error('Site URL is required.');
        }
        if (empty($params['username'])) {
            return $this->error('WordPress username is required.');
        }
        if (empty($params['appPassword'])) {
            return $this->error('Application Password is required.');
        }

        // Normalize URL
        $url = rtrim(esc_url_raw($params['url']), '/');

        require_once __DIR__ . '/service.php';

        // Encrypt the app password before storage
        $encrypted = PCM_Sites_Service::encrypt_password($params['appPassword']);

        $site_id = PCM_DB::create_site(array(
            'userId'      => (int)$pcm_user->id,
            'name'        => sanitize_text_field($params['name']),
            'url'         => $url,
            'username'    => sanitize_text_field($params['username']),
            'appPassword' => $encrypted,
            'status'      => 'active',
        ));

        if (!$site_id) {
            return $this->error('Failed to save site.', 500);
        }

        $site = PCM_DB::get_site($site_id, (int)$pcm_user->id);
        $safe = (array)$site;
        $safe['appPassword'] = '••••••••';

        return $this->success($safe, 201);
    }

    /**
     * Get a single site.
     */
    public function get_site(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $pcm_user = $this->get_current_pcm_user();
        $site = PCM_DB::get_site((int)$request->get_param('id'), (int)$pcm_user->id);

        if (!$site) {
            return $this->not_found('Site');
        }

        $safe = (array)$site;
        $safe['appPassword'] = '••••••••';
        return $this->success($safe);
    }

    /**
     * Update site details.
     */
    public function update_site(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $pcm_user = $this->get_current_pcm_user();
        $site_id = (int)$request->get_param('id');
        $params = $request->get_json_params();

        $update = array();
        if (isset($params['name'])) {
            $update['name'] = sanitize_text_field($params['name']);
        }
        if (isset($params['url'])) {
            $update['url'] = rtrim(esc_url_raw($params['url']), '/');
        }
        if (isset($params['username'])) {
            $update['username'] = sanitize_text_field($params['username']);
        }
        if (isset($params['appPassword']) && !empty($params['appPassword'])) {
            require_once __DIR__ . '/service.php';
            $update['appPassword'] = PCM_Sites_Service::encrypt_password($params['appPassword']);
        }
        if (isset($params['status'])) {
            $update['status'] = sanitize_text_field($params['status']);
        }

        if (empty($update)) {
            return $this->error('No valid fields to update.');
        }

        $success = PCM_DB::update_site($site_id, (int)$pcm_user->id, $update);
        if (!$success) {
            return $this->not_found('Site');
        }

        $site = PCM_DB::get_site($site_id, (int)$pcm_user->id);
        $safe = (array)$site;
        $safe['appPassword'] = '••••••••';
        return $this->success($safe);
    }

    /**
     * Delete a site.
     */
    public function delete_site(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $pcm_user = $this->get_current_pcm_user();
        $success = PCM_DB::delete_site((int)$request->get_param('id'), (int)$pcm_user->id);

        if (!$success) {
            return $this->not_found('Site');
        }

        return $this->success(array('deleted' => true));
    }

    /**
     * Test connection to a WordPress site.
     */
    public function test_connection(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $pcm_user = $this->get_current_pcm_user();
        $site = PCM_DB::get_site((int)$request->get_param('id'), (int)$pcm_user->id);

        if (!$site) {
            return $this->not_found('Site');
        }

        try {
            require_once __DIR__ . '/service.php';
            $result = PCM_Sites_Service::test_connection($site);
            return $this->success($result);
        } catch (\Throwable $e) {
            return $this->error('Connection test failed: ' . $e->getMessage());
        }
    }

    /**
     * Publish an article to a WordPress site.
     *
     * Expected body: { articleId: number }
     */
    public function publish_article(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $pcm_user = $this->get_current_pcm_user();
        $site_id = (int)$request->get_param('id');
        $params = $request->get_json_params();

        if (empty($params['articleId'])) {
            return $this->error('Article ID is required.');
        }

        $site = PCM_DB::get_site($site_id, (int)$pcm_user->id);
        if (!$site) {
            return $this->not_found('Site');
        }

        $article = PCM_DB::get_article((int)$params['articleId'], (int)$pcm_user->id);
        if (!$article) {
            return $this->not_found('Article');
        }

        try {
            require_once __DIR__ . '/service.php';
            $result = PCM_Sites_Service::publish_to_site($site, $article, (int)$pcm_user->id);
            return $this->success($result);
        } catch (\Throwable $e) {
            return $this->error('Publishing failed: ' . $e->getMessage(), 500);
        }
    }
}
