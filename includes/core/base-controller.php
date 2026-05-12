<?php
/**
 * Abstract REST API Base Controller
 *
 * Provides shared infrastructure for all PCM REST controllers:
 * - Automatic route registration via declarative routes() method
 * - Permission checks (WP nonce + capability)
 * - Current PCM user resolution (WP user → pcm_users row)
 * - Standardised JSON success/error response formatting
 * - Input validation helpers
 *
 * All module controllers extend this class and implement routes().
 *
 * @package PowerCreatives
 */

if (!defined('ABSPATH')) {
    exit;
}

abstract class PCM_REST_Base
{

    /**
     * REST API namespace for all plugin endpoints.
     *
     * @var string
     */
    protected string $namespace = 'pcm/v1';

    /**
     * Register all routes defined by the child controller.
     * Called via `rest_api_init` hook.
     *
     * @return void
     */
    public function register(): void
    {
        foreach ($this->routes() as $route_def) {
            $method = $route_def[0]; // HTTP method: GET, POST, PATCH, DELETE
            $path = $route_def[1]; // Route path (e.g. '/integrations')
            $callback = $route_def[2]; // Method name on this class
            $args = $route_def[3] ?? array(); // Optional: validation args
            $permission = $route_def[4] ?? 'manage_options'; // WP capability

            register_rest_route(
                $this->namespace,
                $path,
                array(
                'methods' => $method,
                'callback' => array($this, $callback),
                'permission_callback' => $this->make_permission_callback($permission),
                'args' => $args,
            )
            );
        }
    }

    /**
     * Define routes for this controller.
     *
     * Each entry is an array: [HTTP_METHOD, path, callback_method, ?args, ?capability]
     *
     * @return array<int, array>
     */
    abstract protected function routes(): array;

    // =========================================================================
    // PERMISSION HELPERS
    // =========================================================================

    /**
     * Create a permission callback that checks nonce + WP capability.
     *
     * @param string $capability WordPress capability to require.
     * @return callable
     */
    protected function make_permission_callback(string $capability): callable
    {
        return function (WP_REST_Request $request) use ($capability) {
            // Verify WP REST nonce (set by wp_localize_script in PCM_Admin)
            $nonce = $request->get_header('X-WP-Nonce');
            if (!$nonce || !wp_verify_nonce($nonce, 'wp_rest')) {
                return new WP_Error(
                    'pcm_invalid_nonce',
                    __('Security check failed.', 'power-creatives'),
                    array('status' => 403)
                    );
            }

            // Check user capability
            if (!current_user_can($capability)) {
                return new WP_Error(
                    'pcm_forbidden',
                    __('You do not have permission to perform this action.', 'power-creatives'),
                    array('status' => 403)
                    );
            }

            return true;
        };
    }

    /**
     * Create a permission callback that allows public access (no auth).
     * Used for endpoints like provider listing that don't need authentication.
     *
     * @return callable
     */
    protected function public_access(): callable
    {
        return function () {
            return true;
        };
    }

    // =========================================================================
    // USER CONTEXT
    // =========================================================================

    /**
     * Get or create the PCM user row for the current WordPress user.
     *
     * Maps wp_users → pcm_users, using WP user ID as openId.
     * This bridges WordPress auth with the PCM user system.
     *
     * @return object PCM user row with id, openId, name, email, role.
     */
    protected function get_current_pcm_user(): object
    {
        $wp_user = wp_get_current_user();

        // Use WP user ID as the openId for the PCM user system
        $open_id = 'wp_' . $wp_user->ID;

        $pcm_user = PCM_DB::get_user_by_open_id($open_id);

        if (!$pcm_user) {
            // Auto-create PCM user on first REST request
            $role = current_user_can('manage_options') ? 'admin' : 'user';

            $user_id = PCM_DB::upsert_user(array(
                'openId' => $open_id,
                'name' => $wp_user->display_name,
                'email' => $wp_user->user_email,
                'role' => $role,
                'avatarUrl' => get_avatar_url($wp_user->ID),
            ));

            // Seed default prompts for the newly created user.
            // This fixes the chicken-and-egg: plugin activation seeds run before
            // any users exist, so new users would never get their default prompts.
            if ($user_id) {
                PCM_Prompt_Seeds::seed_for_user((int) $user_id);
            }

            $pcm_user = PCM_DB::get_user_by_open_id($open_id);
        }

        return $pcm_user;
    }

    // =========================================================================
    // RESPONSE HELPERS
    // =========================================================================

    /**
     * Return a standardised success JSON response.
     *
     * @param mixed $data    Response payload.
     * @param int   $status  HTTP status code (default 200).
     * @return WP_REST_Response
     */
    protected function success(mixed $data, int $status = 200): WP_REST_Response
    {
        return new WP_REST_Response($data, $status);
    }

    /**
     * Return a standardised error response.
     *
     * @param string $message Human-readable error message.
     * @param int    $status  HTTP status code (default 400).
     * @param string $code    Machine-readable error code.
     * @return WP_Error
     */
    protected function error(string $message, int $status = 400, string $code = 'pcm_error'): WP_Error
    {
        return new WP_Error($code, $message, array('status' => $status));
    }

    /**
     * Return a 404 Not Found error.
     *
     * @param string $resource Resource type description (e.g. 'Brand').
     * @return WP_Error
     */
    protected function not_found(string $resource = 'Resource'): WP_Error
    {
        return $this->error(
            sprintf(__('%s not found.', 'power-creatives'), $resource),
            404,
            'pcm_not_found'
        );
    }

    // =========================================================================
    // VALIDATION HELPERS
    // =========================================================================

    /**
     * Define a required string argument for route registration.
     *
     * @param int $min_length Minimum length.
     * @param int $max_length Maximum length.
     * @return array Argument definition.
     */
    protected static function string_arg(int $min_length = 1, int $max_length = 256): array
    {
        return array(
            'type' => 'string',
            'required' => true,
            'sanitize_callback' => 'sanitize_text_field',
            'validate_callback' => function ($value) use ($min_length, $max_length) {
            $len = mb_strlen($value);
            return $len >= $min_length && $len <= $max_length;
        },
        );
    }

    /**
     * Define a required integer argument.
     *
     * @param int $min Minimum value.
     * @return array Argument definition.
     */
    protected static function int_arg(int $min = 1): array
    {
        return array(
            'type' => 'integer',
            'required' => true,
            'sanitize_callback' => 'absint',
            'validate_callback' => function ($value) use ($min) {
            return is_numeric($value) && (int)$value >= $min;
        },
        );
    }

    /**
     * Define an optional boolean argument.
     *
     * @return array Argument definition.
     */
    protected static function bool_arg(): array
    {
        return array(
            'type' => 'boolean',
            'required' => false,
            'sanitize_callback' => 'rest_sanitize_boolean',
        );
    }

    // ========================================
    // Shared Helpers (deduplicated from child controllers)
    // ========================================

    /**
     * Get API key for a provider.
     *
     * Looks up the active integration for the given provider and user.
     * Shared across Image, Video, and Copy controllers.
     *
     * @param string $provider Provider ID (e.g. 'openai', 'google', 'kieai').
     * @param int    $user_id  PCM user ID.
     * @return string API key.
     * @throws \RuntimeException If no key is found.
     */
    protected function get_provider_api_key(string $provider, int $user_id): string
    {
        global $wpdb;

        $table = PCM_Schema::table('integrations');
        $key = $wpdb->get_var($wpdb->prepare(
            "SELECT apiKey FROM $table WHERE provider = %s AND userId = %d AND isActive = 1 LIMIT 1",
            $provider,
            $user_id
        ));

        if (empty($key)) {
            throw new \RuntimeException("No API key found for provider: {$provider}");
        }

        return $key;
    }

    /**
     * Get prompt override for a module+section.
     *
     * @param int    $user_id User ID.
     * @param string $module  Module name (e.g. 'image', 'video', 'copy').
     * @param string $section Section name (e.g. 'concept_suggestions').
     * @return string|null Override content or null.
     */
    protected function get_prompt_override(int $user_id, string $module, string $section): ?string
    {
        global $wpdb;

        $table = PCM_Schema::table('prompt_overrides');

        return $wpdb->get_var($wpdb->prepare(
            "SELECT content FROM $table WHERE userId = %d AND module = %s AND section = %s AND isActive = 1 LIMIT 1",
            $user_id,
            $module,
            $section
        ));
    }

    /**
     * Get the primary logo URL for a brand.
     *
     * @param int $brand_id Brand ID.
     * @return string|null Logo URL or null.
     */
    protected function get_brand_logo_url(int $brand_id): ?string
    {
        global $wpdb;

        $table = PCM_Schema::table('brand_assets');

        $url = $wpdb->get_var($wpdb->prepare(
            "SELECT url FROM $table WHERE brandId = %d AND type = 'logo' AND isPrimary = 1 LIMIT 1",
            $brand_id
        ));

        if (empty($url)) {
            $url = $wpdb->get_var($wpdb->prepare(
                "SELECT url FROM $table WHERE brandId = %d AND type = 'logo' ORDER BY createdAt DESC LIMIT 1",
                $brand_id
            ));
        }

        return $url ?: null;
    }
}
