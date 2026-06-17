<?php
/**
 * SEO Hub REST Controller.
 *
 * Tenant management is gated `manage_options:strict` (real WP admin only — the
 * rows carry client secrets + Application Passwords, so a shared-password gate
 * visitor must never reach them). The connector handshake is a public route,
 * authenticated by HMAC inside the handler.
 *
 * @package PowerCreatives
 * @since   1.23.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class PCM_REST_SEOHub extends PCM_REST_Base
{
    public function __construct()
    {
        require_once __DIR__ . '/service.php';
    }

    protected function routes(): array
    {
        return array(
            array('GET',    '/seohub/sites',                      'list_sites',       array(), 'manage_options:strict'),
            array('POST',   '/seohub/sites',                      'create_site',      array(), 'manage_options:strict'),
            array('POST',   '/seohub/sites/(?P<id>\d+)/revoke',   'revoke_site',      array(), 'manage_options:strict'),
            array('DELETE', '/seohub/sites/(?P<id>\d+)',          'delete_site',      array(), 'manage_options:strict'),
            array('GET',    '/seohub/sites/(?P<id>\d+)/connector', 'download_connector', array(), 'manage_options:strict'),
            // Public — HMAC-verified inside the handler.
            array('POST',   '/seohub/connector/hello',            'connector_hello',  array(), 'public'),
        );
    }

    /** GET /seohub/sites — list managed sites (secrets redacted). */
    public function list_sites(WP_REST_Request $request): WP_REST_Response
    {
        return $this->success(PCM_SEOHub_Service::list_tenants());
    }

    /** POST /seohub/sites — create a tenant (returns connector credentials). */
    public function create_site(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $params = $request->get_json_params() ?: array();
        $name   = sanitize_text_field((string) ($params['name'] ?? ''));
        if ($name === '') {
            return $this->error('Site name is required.', 400, 'pcm_seohub_no_name');
        }
        // Record the creating admin so register_ping can mirror the connected
        // site into that owner's wp_pcm_sites (publishable like a manual site).
        $user = $this->get_current_pcm_user();
        return $this->success(PCM_SEOHub_Service::create_tenant($name, $user ? (int) $user->id : 0), 201);
    }

    /** POST /seohub/sites/{id}/revoke — revoke a tenant. */
    public function revoke_site(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $id = absint($request->get_param('id'));
        if (!PCM_SEOHub_Service::get_by_id($id)) {
            return $this->not_found('Site');
        }
        PCM_SEOHub_Service::set_status($id, 'revoked');
        return $this->success(array('id' => $id, 'status' => 'revoked'));
    }

    /** DELETE /seohub/sites/{id} — delete a tenant. */
    public function delete_site(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $id = absint($request->get_param('id'));
        if (!PCM_SEOHub_Service::get_by_id($id)) {
            return $this->not_found('Site');
        }
        PCM_SEOHub_Service::delete_tenant($id);
        return $this->success(array('id' => $id, 'deleted' => true));
    }

    /**
     * GET /seohub/sites/{id}/connector — stream the connector plugin ZIP.
     * Streamed (not a public uploads URL) because the ZIP bakes the client
     * secret; the temp file is deleted right after sending.
     */
    public function download_connector(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $id     = absint($request->get_param('id'));
        $tenant = PCM_SEOHub_Service::get_by_id($id);
        if (!$tenant) {
            return $this->not_found('Site');
        }
        $built = PCM_SEOHub_Service::build_connector_zip($tenant);
        if (isset($built['error'])) {
            return $this->error((string) $built['error'], 500, 'pcm_seohub_zip');
        }
        $path = $built['path'];
        if (!file_exists($path)) {
            return $this->error('Connector build failed.', 500);
        }
        nocache_headers();
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="pcm-connector-' . $tenant->clientId . '.zip"');
        header('Content-Length: ' . filesize($path));
        readfile($path);
        @unlink($path); // don't leave the secret-bearing ZIP on disk
        exit;
    }

    /**
     * POST /seohub/connector/hello — connector registration handshake.
     * Public route; authenticated by HMAC over "{ts}.{nonce}.{rawBody}".
     */
    public function connector_hello(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $client_id = (string) $request->get_header('x_hub_client_id');
        $timestamp = (string) $request->get_header('x_hub_timestamp');
        $nonce     = (string) $request->get_header('x_hub_nonce');
        $signature = (string) $request->get_header('x_hub_signature');
        $body      = (string) $request->get_body();

        $tenant = PCM_SEOHub_Service::hmac_verify($client_id, $timestamp, $nonce, $body, $signature);
        if (is_wp_error($tenant)) {
            return $tenant;
        }
        $meta = json_decode($body, true);
        if (!is_array($meta)) {
            return $this->error('Invalid body.', 400, 'pcm_seohub_bad_body');
        }
        PCM_SEOHub_Service::register_ping($tenant, $meta);
        return $this->success(array('ok' => true));
    }
}
