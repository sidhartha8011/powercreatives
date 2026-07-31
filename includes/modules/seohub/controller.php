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
            // GENERIC connector = tenant-free: no clientId/secret is baked in (the
            // site pairs later with a one-time code), so this is a plain plugin zip,
            // not a credential. :coadmin lets a platform ADMIN download it too —
            // :strict blocked them entirely (owner report 2026-07-31, "Download
            // failed" for a platform Administrator). The PER-SITE download above
            // stays :strict: that one DOES bake in tenant credentials.
            array('GET',    '/seohub/connector-download',          'download_connector_generic', array(), 'manage_options:coadmin'),
            // Public — HMAC-verified inside the handler.
            array('POST',   '/seohub/connector/hello',            'connector_hello',  array(), 'public'),
            // Public — the connector's WP-native self-update fetches these (no auth; the connector
            // code isn't secret). manifest.sha256 always matches the package (shared cached artifact).
            array('GET',    '/seohub/connector-manifest',         'connector_manifest', array(), 'public'),
            array('GET',    '/seohub/connector-package',          'connector_package',  array(), 'public'),
        );
    }

    /** GET /seohub/connector-manifest — the update descriptor WordPress polls on connected sites. */
    public function connector_manifest(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $a = PCM_SEOHub_Service::connector_artifact();
        if (isset($a['error'])) {
            return $this->error((string) $a['error'], 500, 'pcm_seohub_manifest');
        }
        return new WP_REST_Response(array(
            'version'      => (string) $a['version'],
            'package'      => rest_url('pcm/v1/seohub/connector-package'),
            'sha256'       => (string) $a['sha256'],
            'requires'     => '5.9',
            'tested'       => get_bloginfo('version'),
            'requires_php' => '7.4',
            'url'          => home_url('/'),
        ), 200);
    }

    /** GET /seohub/connector-package — the connector zip, byte-identical to the manifest's sha256. */
    public function connector_package(WP_REST_Request $request): void
    {
        $a = PCM_SEOHub_Service::connector_artifact();
        if (isset($a['error'])) {
            status_header(500);
            echo esc_html((string) $a['error']);
            exit;
        }
        nocache_headers();
        header('Content-Type: application/zip');
        // Version in the filename (owner order 2026-07-17): every download
        // names what it is — no more guessing which zip is which.
        header('Content-Disposition: attachment; filename="pcm-connector-' . (string) $a['version'] . '.zip"');
        header('Content-Length: ' . strlen((string) $a['zip']));
        echo $a['zip']; // phpcs:ignore WordPress.Security.EscapeOutput -- binary zip
        exit;
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
        header('Content-Disposition: attachment; filename="pcm-connector-' . $tenant->clientId . '-' . PCM_SEOHub_Service::connector_effective_version() . '.zip"');
        header('Content-Length: ' . filesize($path));
        readfile($path);
        @unlink($path); // don't leave the secret-bearing ZIP on disk
        exit;
    }

    /** GET /seohub/connector-download — stream the GENERIC (tenant-free) pairing-code connector. */
    public function download_connector_generic(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $built = PCM_SEOHub_Service::build_connector_zip_generic();
        if (isset($built['error'])) {
            return $this->error((string) $built['error'], 500, 'pcm_seohub_zip');
        }
        $path = $built['path'];
        if (!file_exists($path)) {
            return $this->error('Connector build failed.', 500);
        }
        nocache_headers();
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="pcm-connector-' . PCM_SEOHub_Service::connector_effective_version() . '.zip"');
        header('Content-Length: ' . filesize($path));
        readfile($path);
        @unlink($path);
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
