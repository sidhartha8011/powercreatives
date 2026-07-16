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
            // Batch connector health (gap 89ef71a): the SEO tab dots' ONE call.
            array('GET',    '/sites/health',                   'sites_health'),
            array('POST',   '/sites/(?P<id>\d+)/gsc-verify',   'gsc_verify_site'),
            array('POST',   '/sites/(?P<id>\d+)/gsc-preview',  'gsc_preview'),
            array('POST',   '/sites/update-connectors',        'update_connectors'),
            array('GET',    '/sites/(?P<id>\d+)/schedule',     'get_schedule'),
            array('POST',   '/sites/(?P<id>\d+)/schedule',     'set_schedule'),
            array('GET',    '/sites/(?P<id>\d+)/connector-version', 'connector_version'),
            array('POST',   '/sites/(?P<id>\d+)/update-connector',  'update_connector'),
        );
    }

    /**
     * GET /sites/{id}/schedule — the site's recurring content-schedule rule
     * (Task I2), or null when none is configured.
     */
    public function get_schedule(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $pcm_user = $this->get_current_pcm_user();
        $site_id  = (int) $request->get_param('id');

        $site = PCM_DB::get_site($site_id, (int) $pcm_user->id);
        if (!$site) {
            return $this->not_found('Site');
        }

        require_once __DIR__ . '/service.php';
        return $this->success(array('schedule' => PCM_Sites_Service::get_site_schedule($site_id)));
    }

    /**
     * POST /sites/{id}/schedule — create/replace the site's recurring
     * content-schedule rule. The daily strategy scan
     * (PCM_Strategy_Service::run_site_schedules()) picks it up: on each due
     * period it asks PCM_Topic_Suggester for `count` topics and creates a
     * strategy targeting this site. Disable with enabled:false (the rule is
     * kept so its settings survive re-enabling).
     */
    public function set_schedule(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $pcm_user = $this->get_current_pcm_user();
        $site_id  = (int) $request->get_param('id');

        $site = PCM_DB::get_site($site_id, (int) $pcm_user->id);
        if (!$site) {
            return $this->not_found('Site');
        }

        $params = $request->get_json_params();
        if (!is_array($params)) {
            $params = array();
        }

        $frequency = in_array($params['frequency'] ?? '', array('daily', 'weekly', 'monthly'), true)
            ? (string) $params['frequency']
            : 'weekly';
        $mode = in_array($params['publishingMode'] ?? '', array('draft', 'publish', 'schedule'), true)
            ? (string) $params['publishingMode']
            : 'draft';
        $template_id = absint($params['templateId'] ?? 0);
        if (!empty($params['enabled']) && !$template_id) {
            return $this->error('A template is required for an enabled schedule.');
        }

        require_once __DIR__ . '/service.php';
        $rule = PCM_Sites_Service::set_site_schedule($site_id, array(
            'enabled'        => !empty($params['enabled']),
            'frequency'      => $frequency,
            'count'          => min(10, max(1, absint($params['count'] ?? 5))),
            'templateId'     => $template_id,
            'publishingMode' => $mode,
            'niche'          => sanitize_text_field((string) ($params['niche'] ?? '')),
            // Owner stamp: the daily cron runs with no request user — the rule
            // carries whose LLM key + site scope it must execute under.
            'userId'         => (int) $pcm_user->id,
        ));
        return $this->success(array('schedule' => $rule));
    }

    /**
     * GET /sites/{id}/connector-version — the site's INSTALLED connector version (read live
     * from its plugin list) + the hub's latest, so the UI can show an inline update button
     * only when the site is behind. version/latest are '' when unreadable (shown honestly).
     */
    public function connector_version(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $pcm_user = $this->get_current_pcm_user();
        $site = PCM_DB::get_site(absint($request->get_param('id')), (int) $pcm_user->id);
        if (!$site) {
            return $this->not_found('Site');
        }
        require_once __DIR__ . '/service.php';
        $version = PCM_Sites_Service::remote_connector_version($site);
        $latest  = PCM_Sites_Service::latest_connector_version();
        return $this->success(array(
            'version'  => $version,
            'latest'   => $latest,
            'upToDate' => $version !== '' && $latest !== '' && version_compare($version, $latest, '>='),
        ));
    }

    /**
     * POST /sites/{id}/update-connector — force ONE site's connector to self-update now
     * (the per-site twin of update_connectors; same /pcm-conn/v1/update-now channel).
     * Returns { status, from, to, version } — version re-read after the update so the
     * UI reflects what is actually installed, not what the update claimed.
     */
    public function update_connector(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $pcm_user = $this->get_current_pcm_user();
        $site = PCM_DB::get_site(absint($request->get_param('id')), (int) $pcm_user->id);
        if (!$site) {
            return $this->not_found('Site');
        }
        require_once __DIR__ . '/service.php';
        $r = PCM_Sites_Service::remote_rest($site, 'POST', '/pcm-conn/v1/update-now', array(), array(), 120);
        if (is_wp_error($r)) {
            return $this->error($r->get_error_message(), 502, 'pcm_conn_update_failed');
        }
        $code = (int) ($r['status'] ?? 0);
        $body = is_array($r['body'] ?? null) ? $r['body'] : array();
        if ($code === 404) {
            return $this->error(
                __('This site’s connector predates self-update (no /update-now). Reinstall the connector once (Download connector), then it self-updates from here on.', 'power-creatives'),
                409,
                'pcm_conn_too_old'
            );
        }
        if ($code >= 300) {
            return $this->error((string) ($body['message'] ?? ('HTTP ' . $code)), 502, 'pcm_conn_update_failed');
        }
        return $this->success(array(
            'status'  => (string) ($body['status'] ?? 'ok'),
            'from'    => (string) ($body['from'] ?? ''),
            'to'      => (string) ($body['to'] ?? ''),
            'version' => PCM_Sites_Service::remote_connector_version($site),
        ));
    }

    /**
     * POST /sites/update-connectors — force every connected site's connector to self-update NOW
     * (the "emergency push": bypasses WordPress's twice-daily poll). Calls each site's
     * /pcm-conn/v1/update-now over the existing app-password channel. Best-effort per site;
     * returns a per-site result list.
     *
     * @param WP_REST_Request $request Request.
     * @return WP_REST_Response|WP_Error
     */
    public function update_connectors(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $pcm_user = $this->get_current_pcm_user();
        require_once __DIR__ . '/service.php';
        $results = array();
        foreach (PCM_DB::get_user_sites((int)$pcm_user->id) as $site) {
            $r = PCM_Sites_Service::remote_rest($site, 'POST', '/pcm-conn/v1/update-now', array(), array(), 120);
            if (is_wp_error($r)) {
                $results[] = array('id' => (int)$site->id, 'name' => (string)$site->name, 'status' => 'error', 'message' => $r->get_error_message());
                continue;
            }
            $code = (int) ($r['status'] ?? 0);
            $body = is_array($r['body'] ?? null) ? $r['body'] : array();
            if ($code === 404) {
                $results[] = array('id' => (int)$site->id, 'name' => (string)$site->name, 'status' => 'error', 'message' => 'This site’s connector predates self-update (no /update-now). Reinstall the connector once (Download connector), then it self-updates from here on.');
            } elseif ($code >= 300) {
                $results[] = array('id' => (int)$site->id, 'name' => (string)$site->name, 'status' => 'error', 'message' => (string) ($body['message'] ?? ('HTTP ' . $code)));
            } else {
                $results[] = array('id' => (int)$site->id, 'name' => (string)$site->name, 'status' => (string) ($body['status'] ?? 'ok'), 'from' => (string) ($body['from'] ?? ''), 'to' => (string) ($body['to'] ?? ''));
            }
        }
        return $this->success(array('results' => $results));
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

        // As soon as a site is added, try to register + verify it in Google Search Console
        // (add property → META token → push to connector → verify). Best-effort by design:
        // the site is saved regardless, and the report tells the UI what happened.
        $gsc = array('attempted' => false, 'verified' => false, 'error' => null);
        try {
            $gsc = PCM_Sites_Service::gsc_provision($site, (int)$pcm_user->id);
        } catch (\Throwable $e) {
            $gsc['error'] = $e->getMessage();
        }

        $safe = (array)$site;
        $safe['appPassword'] = '••••••••';
        $safe['gsc'] = $gsc;

        return $this->success($safe, 201);
    }

    /**
     * POST /sites/<id>/gsc-verify — retry Google Search Console provisioning for a site
     * (add property → META token → connector push → verify). Used after fixing whatever
     * blocked the automatic attempt at add time (no GSC connection, old connector, cache…).
     *
     * @param WP_REST_Request $request Request with id.
     * @return WP_REST_Response|WP_Error
     */
    public function gsc_verify_site(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $pcm_user = $this->get_current_pcm_user();
        $site = PCM_DB::get_site(absint($request->get_param('id')), (int)$pcm_user->id);
        if (!$site) {
            return $this->not_found('Site');
        }
        require_once __DIR__ . '/service.php';
        // Optional targetUrl = the domain variant the user confirmed in the GSC dialog (www vs
        // non-www). Empty → gsc_provision defaults to the stored site URL.
        $p      = $request->get_json_params();
        $target = is_array($p) ? trim((string) ($p['targetUrl'] ?? '')) : '';
        return $this->success(PCM_Sites_Service::gsc_provision($site, (int)$pcm_user->id, $target));
    }

    /**
     * POST /sites/<id>/gsc-preview — data for the "Verify in GSC" dialog: existing GSC properties
     * that already cover this site (reuse, don't duplicate) + the auto-detected canonical/indexed
     * domain to pre-fill the input. Read-only; performs outbound GSC + homepage-redirect probes.
     *
     * @param WP_REST_Request $request Request with id.
     * @return WP_REST_Response|WP_Error
     */
    public function gsc_preview(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $pcm_user = $this->get_current_pcm_user();
        $site = PCM_DB::get_site(absint($request->get_param('id')), (int)$pcm_user->id);
        if (!$site) {
            return $this->not_found('Site');
        }
        require_once __DIR__ . '/service.php';
        return $this->success(PCM_Sites_Service::gsc_preview($site, (int)$pcm_user->id));
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
        if (array_key_exists('brandId', $params)) {
            // The site↔brand link (FK only — brands own the business data).
            // 0 / null disconnects.
            $update['brandId'] = absint($params['brandId']) ?: null;
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
    /**
     * GET /sites/health — every site's connector health in ONE call
     * (gap 89ef71a). Tests run SEQUENTIALLY server-side (the 2-worker
     * law: N frontend fan-out would freeze the hub) with a short
     * hub-tunable timeout; a failing site reports its error honestly,
     * never blocks the others.
     *
     * @param WP_REST_Request $request Request object.
     * @return WP_REST_Response
     */
    public function sites_health(WP_REST_Request $request): WP_REST_Response
    {
        $pcm_user = $this->get_current_pcm_user();
        // Timeout is hub DATA (read-through seed — the tunables law).
        $cfg = get_option('pcm_sites_health_check');
        if (!is_array($cfg) || !isset($cfg['timeoutS'])) {
            $cfg = array('timeoutS' => 5);
            add_option('pcm_sites_health_check', $cfg, '', false);
        }
        require_once __DIR__ . '/service.php';
        $health = array();
        foreach (PCM_DB::get_user_sites((int) $pcm_user->id) as $site) {
            try {
                PCM_Sites_Service::test_connection($site, max(1, (int) $cfg['timeoutS']));
                $health[(int) $site->id] = array('ok' => true, 'error' => null);
            } catch (\Throwable $e) {
                $health[(int) $site->id] = array('ok' => false, 'error' => $e->getMessage());
            }
        }
        return $this->success(array('health' => $health));
    }

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
