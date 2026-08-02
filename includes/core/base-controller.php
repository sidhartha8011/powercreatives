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
     * Fallback capability for routes that don't specify one (5th element).
     * Work-module controllers override this to 'edit_posts' so non-admin team
     * members can use them; admin-only modules (users, settings, integrations,
     * automations, templates) keep the manage_options default.
     */
    protected string $default_capability = 'manage_options';

    /**
     * Per-delivery module grants. When non-empty, a logged-in NON-admin user
     * must hold at least one of these module ids (union of `modules` across
     * their assigned deliveries — PCM_Access::granted_module_ids) to use this
     * controller. Admins (manage_options) and gate-auth visitors bypass.
     * E.g. copy => ['copy','ads'] (granting Ads implies the copy backend).
     */
    protected array $module_grant_keys = array();

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
            $permission = $route_def[4] ?? $this->default_capability; // WP capability

            // Capability tiers beyond the default:
            //  - 'manage_options:strict'  = real WP admin ONLY (no gate bypass) —
            //    infrastructure/credential routes (connected-site management).
            //  - 'manage_options:coadmin' = WP admin OR a platform ADMIN (a gate
            //    user with role='admin') — workspace administration a co-admin may
            //    do (managing platform users, assigning deliveries). Still never
            //    reachable by a normal platform user.
            if ($permission === 'manage_options:strict') {
                $permission_callback = $this->make_strict_admin_callback();
            } elseif ($permission === 'manage_options:coadmin') {
                $permission_callback = $this->make_coadmin_callback();
            } elseif ($permission === 'public') {
                // Public tier — for routes an EXTERNAL service must reach by browser redirect
                // (e.g. the Google OAuth callback, which arrives without a REST nonce/cookie auth).
                // Handlers on this tier MUST authenticate by their own means (single-use server-side
                // state tokens) — never trust request params alone.
                $permission_callback = '__return_true';
            } else {
                $permission_callback = $this->make_permission_callback($permission);
            }

            register_rest_route(
                $this->namespace,
                $path,
                array(
                'methods' => $method,
                'callback' => $this->guard_callback($callback),
                'permission_callback' => $permission_callback,
                'args' => $args,
            )
            );
        }
    }

    /**
     * Wrap a controller method so an uncaught PHP Throwable becomes a structured
     * JSON error instead of a bare HTTP 500 with a non-JSON body.
     *
     * WordPress does NOT catch Errors/Exceptions thrown from a REST callback — a
     * version-mismatch fatal on the live site (e.g. `Call to undefined method`
     * after a partial update, or a PHP 8.x type error) propagates as a raw 500
     * whose body isn't the usual `{code, message}` JSON. The SPA then shows an
     * opaque "API error: 500" with no reason, which is undebuggable for the user.
     * Catching Throwable here preserves the real message + logs the trace, so the
     * admin sees WHAT failed (and support can act) instead of a dead end. Returned
     * WP_Error values from handlers are unaffected — only *thrown* faults are caught.
     *
     * @param string $method Controller method name.
     * @return callable
     */
    private function guard_callback(string $method): callable
    {
        return function (WP_REST_Request $request) use ($method) {
            try {
                return $this->{$method}($request);
            } catch (\Throwable $e) {
                error_log(sprintf(
                    '[power-creatives] Unhandled %s in %s::%s — %s at %s:%d',
                    get_class($e),
                    static::class,
                    $method,
                    $e->getMessage(),
                    $e->getFile(),
                    (int) $e->getLine()
                ));
                return $this->error(
                    sprintf(
                        /* translators: %s: the underlying PHP error message. */
                        __('The server hit an unexpected error: %s', 'power-creatives'),
                        $e->getMessage()
                    ),
                    500,
                    'pcm_internal_error'
                );
            }
        };
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
     * Create a permission callback that checks nonce + auth source.
     *
     * Accepts two auth sources:
     *  1. WP user with the required capability (existing admin flow)
     *  2. Valid shortcode gate cookie — treated as having access to all
     *     standard endpoints (`manage_options`/`read`). The gate visitor
     *     operates inside the shared workspace, not as a WP user.
     *
     * The nonce check stays in place for both sources to provide CSRF
     * protection — `wp_create_nonce('wp_rest')` works for anonymous users
     * (uid 0) and the same uid context is used by `wp_verify_nonce`.
     *
     * @param string $capability WordPress capability to require.
     * @return callable
     */
    protected function make_permission_callback(string $capability): callable
    {
        return function (WP_REST_Request $request) use ($capability) {
            $nonce = $request->get_header('X-WP-Nonce');
            if (!$nonce || !wp_verify_nonce($nonce, 'wp_rest')) {
                return new WP_Error(
                    'pcm_invalid_nonce',
                    __('Security check failed.', 'power-creatives'),
                    array('status' => 403)
                );
            }

            // Source 1: WP user with required capability
            if (current_user_can($capability)) {
                // Per-delivery module grants: admins pass unconditionally; a
                // non-admin must hold one of this controller's module ids via
                // an assigned delivery. (Sidebar hiding is UX — this is the
                // actual enforcement.)
                if (!empty($this->module_grant_keys)
                    && !current_user_can('manage_options')
                    && is_user_logged_in()
                ) {
                    $pcm_user = PCM_DB::get_user_by_open_id('wp_' . get_current_user_id());
                    $granted  = $pcm_user
                        ? PCM_Access::granted_module_ids((int) $pcm_user->id)
                        : array();
                    if (empty(array_intersect($this->module_grant_keys, $granted))) {
                        return new WP_Error(
                            'pcm_module_not_granted',
                            __('This module is not part of your assigned deliveries.', 'power-creatives'),
                            array('status' => 403)
                        );
                    }
                    // Per-module brand scoping: a granted brand is only
                    // usable inside the modules of the delivery that granted
                    // it. Owned brands always pass.
                    $brand_check = $this->check_module_brand($request, $pcm_user);
                    if ($brand_check instanceof WP_Error) {
                        return $brand_check;
                    }
                }
                return true;
            }

            // Source 2: Gate-authenticated visitor — treated as authorised for the
            // standard read/write capability used across PCM endpoints, but with
            // the SAME per-delivery module grants + per-module brand scoping a
            // restricted WP user gets: a platform user only reaches the modules
            // and brands their assigned deliveries grant.
            if (
                class_exists('PCM_Gate_Auth')
                && PCM_Gate_Auth::is_authenticated()
                && in_array($capability, array('manage_options', 'read', 'edit_posts'), true)
            ) {
                $gate_user = PCM_Gate_Auth::get_gate_user();
                if (!$gate_user) {
                    return new WP_Error(
                        'pcm_forbidden',
                        __('You do not have permission to perform this action.', 'power-creatives'),
                        array('status' => 403)
                    );
                }
                // A platform ADMIN bypasses per-module/brand scoping — like a WP admin,
                // they get workspace-wide access to review/manage everyone's work.
                if (PCM_Access::is_admin((int) $gate_user->id)) {
                    return true;
                }
                if (!empty($this->module_grant_keys)) {
                    $granted = PCM_Access::granted_module_ids((int) $gate_user->id);
                    if (empty(array_intersect($this->module_grant_keys, $granted))) {
                        return new WP_Error(
                            'pcm_module_not_granted',
                            __('This module is not part of your assigned deliveries.', 'power-creatives'),
                            array('status' => 403)
                        );
                    }
                    $brand_check = $this->check_module_brand($request, $gate_user);
                    if ($brand_check instanceof WP_Error) {
                        return $brand_check;
                    }
                }
                return true;
            }

            return new WP_Error(
                'pcm_forbidden',
                __('You do not have permission to perform this action.', 'power-creatives'),
                array('status' => 403)
            );
        };
    }

    /**
     * Validate the request's `brandId` (when present) against the caller's
     * per-module brand grants. Runs only for logged-in non-admins on
     * controllers that declare $module_grant_keys. A brand passes when the
     * caller OWNS it, or when it was granted via an assigned delivery whose
     * modules intersect this controller's grant keys.
     *
     * @param WP_REST_Request $request  Request.
     * @param object|null     $pcm_user Resolved PCM user row.
     * @return true|WP_Error
     */
    private function check_module_brand(WP_REST_Request $request, ?object $pcm_user): bool|WP_Error
    {
        $brand_id = absint($request->get_param('brandId') ?? 0);
        if ($brand_id === 0) {
            return true; // No brand in play — nothing to scope.
        }
        if (!$pcm_user) {
            return true; // No PCM row yet → no grants and no owned brands.
        }

        global $wpdb;
        $brands = PCM_Schema::table('brands');
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $owned = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$brands} WHERE id = %d AND userId = %d",
            $brand_id,
            (int) $pcm_user->id
        ));
        if ($owned) {
            return true;
        }

        $allowed = PCM_Access::granted_brand_ids_for_modules((int) $pcm_user->id, $this->module_grant_keys);
        if (in_array($brand_id, $allowed, true)) {
            return true;
        }

        return new WP_Error(
            'pcm_brand_not_granted',
            __('This brand is not part of your assigned deliveries for this module.', 'power-creatives'),
            array('status' => 403)
        );
    }

    /**
     * Permission callback for REAL WP administrators only — nonce + a genuine
     * `manage_options` WP capability, with NO shortcode-gate bypass. The gate
     * grants shared-password visitors `manage_options`-level access to ordinary
     * endpoints; routes that expose cross-user data (the WP user directory) must
     * not be reachable that way.
     *
     * @return callable
     */
    protected function make_strict_admin_callback(): callable
    {
        return function (WP_REST_Request $request) {
            $nonce = $request->get_header('X-WP-Nonce');
            if (!$nonce || !wp_verify_nonce($nonce, 'wp_rest')) {
                return new WP_Error(
                    'pcm_invalid_nonce',
                    __('Security check failed.', 'power-creatives'),
                    array('status' => 403)
                );
            }
            if (current_user_can('manage_options')) {
                return true;
            }
            return new WP_Error(
                'pcm_forbidden',
                __('Administrator access required.', 'power-creatives'),
                array('status' => 403)
            );
        };
    }

    /**
     * Permission callback for WORKSPACE administration — a real WP admin OR a
     * platform admin (a gate-login user whose PCM role is 'admin'). Lets multiple
     * admins manage platform users + assign deliveries, while a normal platform
     * user (role='user') is still refused. Never grants WP-level access: platform
     * admins can only manage OTHER platform users, never WP accounts.
     *
     * @return callable
     */
    protected function make_coadmin_callback(): callable
    {
        return function (WP_REST_Request $request) {
            $nonce = $request->get_header('X-WP-Nonce');
            if (!$nonce || !wp_verify_nonce($nonce, 'wp_rest')) {
                return new WP_Error(
                    'pcm_invalid_nonce',
                    __('Security check failed.', 'power-creatives'),
                    array('status' => 403)
                );
            }
            if (current_user_can('manage_options')) {
                return true;
            }
            // Platform admin: a gate-authed visitor whose PCM role is 'admin'.
            if (
                class_exists('PCM_Gate_Auth')
                && PCM_Gate_Auth::is_authenticated()
                && ($gate_user = PCM_Gate_Auth::get_gate_user())
                && PCM_Access::is_admin((int) $gate_user->id)
            ) {
                return true;
            }
            return new WP_Error(
                'pcm_forbidden',
                __('Administrator access required.', 'power-creatives'),
                array('status' => 403)
            );
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
     * Get or create the PCM user row for the current request.
     *
     * Two paths:
     *  1. If a gate-authenticated visitor is making the request (no WP login)
     *     → return the shared workspace user (one shared row for all visitors).
     *  2. Otherwise map wp_users → pcm_users, using WP user ID as openId.
     *
     * @return object PCM user row with id, openId, name, email, role.
     */
    protected function get_current_pcm_user(): object
    {
        // Gate-authed visitor without a WP login → the specific platform user their
        // login cookie identifies (each visitor is their own workspace user).
        if (
            !is_user_logged_in()
            && class_exists('PCM_Gate_Auth')
            && PCM_Gate_Auth::is_authenticated()
        ) {
            $gate_user = PCM_Gate_Auth::get_gate_user();
            if ($gate_user) {
                return $gate_user;
            }
            // Fall through to existing logic if the cookie user could not be resolved.
        }

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
                // Also seed the default automation rules (idempotent — no-op if
                // already seeded). Same chicken-and-egg as prompts.
                if (class_exists('PCM_Automation_Seeds')) {
                    PCM_Automation_Seeds::seed_for_user((int) $user_id);
                }
            }

            $pcm_user = PCM_DB::get_user_by_open_id($open_id);
        }

        // Keep the stored access level mirroring WordPress: if the WP role
        // changed since the row was created (e.g. user promoted to admin),
        // sync it on the next request. One cheap UPDATE, only when stale.
        if ($pcm_user && is_user_logged_in()) {
            $live_role = current_user_can('manage_options') ? 'admin' : 'user';
            if (($pcm_user->role ?? '') !== $live_role) {
                global $wpdb;
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                $wpdb->update(
                    PCM_Schema::table('users'),
                    array('role' => $live_role),
                    array('id' => (int) $pcm_user->id),
                    array('%s'),
                    array('%d')
                );
                $pcm_user->role = $live_role;
            }
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
        // Workspace-scoped: caller's own key, else an admin's (PCM_Access::
        // workspace_api_key). A platform (id/pass) user owns no integrations, so
        // the old per-user lookup threw for them on all 23 call sites that reach
        // this helper — video, image, optimizer, Brevo, ProRankTracker, GSC.
        $key = class_exists('PCM_Access')
            ? PCM_Access::workspace_api_key($provider, $user_id)
            : null;

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
     * Get the brand logo URL from the brand's assets JSON.
     *
     * Reads from wp_pcm_brands.assets JSON column and finds the first
     * asset with role='logo'. This is the single source of truth —
     * matches the frontend's getBrandLogo() resolver.
     *
     * @param int $brand_id Brand ID.
     * @return string|null Logo URL or null.
     */
    protected function get_brand_logo_url(int $brand_id): ?string
    {
        global $wpdb;

        $brands_table = PCM_Schema::table('brands');

        $assets_json = $wpdb->get_var($wpdb->prepare(
            "SELECT assets FROM $brands_table WHERE id = %d LIMIT 1",
            $brand_id
        ));

        if (empty($assets_json)) {
            return null;
        }

        $assets = json_decode($assets_json, true);
        if (!is_array($assets)) {
            return null;
        }

        // Find first asset with role='logo' — same logic as frontend getBrandLogo()
        foreach ($assets as $asset) {
            if (isset($asset['role']) && $asset['role'] === 'logo' && !empty($asset['url'])) {
                return $asset['url'];
            }
        }

        return null;
    }
}
