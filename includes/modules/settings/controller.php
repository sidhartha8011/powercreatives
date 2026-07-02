<?php
/**
 * REST Controller for Settings
 *
 * Exposes the application-wide settings over the REST API.
 * This resolves the 404 error during frontend initialization.
 *
 * @package PowerCreatives
 */

if (!defined('ABSPATH')) {
    exit;
}

class PCM_Settings_Controller extends PCM_REST_Base
{
    /**
     * Define routes for this controller.
     *
     * @return array
     */
    protected function routes(): array
    {
        return array(
            // GET /wp-json/pcm/v1/settings
            array('GET', '/settings', 'get_settings', array(), 'read'),

            // POST /wp-json/pcm/v1/settings
            array('POST', '/settings', 'update_settings', array(), 'manage_options'),
        );
    }

    /**
     * Get all plugin settings.
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public function get_settings(WP_REST_Request $request): WP_REST_Response
    {
        return $this->success(PCM_Settings::get_all());
    }

    /**
     * Update settings.
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public function update_settings(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $params = $request->get_json_params();

        if (empty($params) || !is_array($params)) {
            return $this->error('No settings provided to update.', 400);
        }

        // Delivery-type presets are structured data — normalize on write
        // (keys/labels sanitized, module ids whitelist-filtered) so the
        // option never stores junk. Explicit null resets to the built-ins.
        if (class_exists('PCM_Deliveries_Service')
            && array_key_exists(PCM_Deliveries_Service::TYPE_PRESETS_SETTING, $params)
        ) {
            $raw = $params[PCM_Deliveries_Service::TYPE_PRESETS_SETTING];
            $params[PCM_Deliveries_Service::TYPE_PRESETS_SETTING] =
                $raw === null ? null : PCM_Deliveries_Service::normalize_presets($raw);
        }

        // Per-module email senders are structured data — normalize on write so the
        // option only ever stores { module => { email, name } } with clean values.
        if (array_key_exists('module_email_senders', $params)) {
            $raw   = $params['module_email_senders'];
            $clean = array();
            if (is_array($raw)) {
                foreach ($raw as $module => $sender) {
                    $module = sanitize_key((string) $module);
                    $email  = is_array($sender) ? sanitize_email((string) ($sender['email'] ?? '')) : '';
                    if ($module === '' || $email === '' || !is_email($email)) {
                        continue; // empty email = "use the global default" → drop the entry
                    }
                    $clean[$module] = array(
                        'email' => $email,
                        'name'  => is_array($sender) ? sanitize_text_field((string) ($sender['name'] ?? '')) : '',
                    );
                }
            }
            $params['module_email_senders'] = $clean;
        }

        PCM_Settings::set_many($params);

        return $this->success(PCM_Settings::get_all());
    }
}
