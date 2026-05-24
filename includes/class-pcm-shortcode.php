<?php
/**
 * Shortcode Handler — [power_creatives]
 *
 * Renders the React SPA on any WordPress page or post via the
 * [power_creatives] shortcode. Visitors must enter the global
 * shortcode password (set in WP Admin → Power Creatives → Shortcode)
 * before they see the dashboard.
 *
 * Bot protection: rate limiting (5 attempts / 15 min / IP), honeypot
 * field, time gate (>= 2s between page load and submit), WP nonce.
 *
 * WP admins (manage_options) bypass the gate automatically.
 *
 * @package PowerCreatives
 * @since   1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class PCM_Shortcode
{
    const NONCE_ACTION = 'pcm_shortcode_login';
    const RATE_LIMIT_MAX = 5;
    const RATE_LIMIT_WINDOW = 900; // 15 minutes
    const MIN_FORM_AGE = 2; // seconds — humans need at least this long

    private bool $enqueued = false;

    public function __construct()
    {
        add_shortcode('power_creatives', array($this, 'render'));
        add_action('init', array($this, 'handle_auth_actions'));
        add_action('wp', array($this, 'maybe_disable_chrome'));
    }

    /**
     * When the current post contains [power_creatives] in fullscreen mode,
     * suppress the WP admin bar entirely so it never renders.
     */
    public function maybe_disable_chrome(): void
    {
        if (is_admin()) {
            return;
        }
        $post = get_post();
        if (!$post || empty($post->post_content)) {
            return;
        }
        if (!has_shortcode($post->post_content, 'power_creatives')) {
            return;
        }
        // Look for an explicit mode="inline" — if absent, treat as fullscreen
        if (preg_match('/\[power_creatives[^\]]*mode=["\']?inline["\']?[^\]]*\]/i', $post->post_content)) {
            return;
        }
        // Fullscreen: kill admin bar + remove WP's html margin injection
        add_filter('show_admin_bar', '__return_false');
        remove_action('wp_head', '_admin_bar_bump_cb');
    }

    /**
     * Handle login POST and logout GET requests early in the request lifecycle.
     * Uses Post-Redirect-Get pattern so cookies set before headers are sent.
     */
    public function handle_auth_actions(): void
    {
        // Logout: ?pcm_logout=1
        if (isset($_GET['pcm_logout'])) {
            PCM_Gate_Auth::clear_cookie();
            wp_safe_redirect(remove_query_arg('pcm_logout'));
            exit;
        }

        // Login submit
        if (!empty($_POST['pcm_login_submit'])) {
            $this->handle_login_submit();
        }
    }

    private function handle_login_submit(): void
    {
        $redirect_url = isset($_POST['_wp_http_referer'])
            ? remove_query_arg(array('pcm_err'), wp_unslash($_POST['_wp_http_referer']))
            : home_url();

        $reject = function (string $reason) use ($redirect_url): void {
            wp_safe_redirect(add_query_arg('pcm_err', $reason, $redirect_url));
            exit;
        };

        // CSRF
        if (!isset($_POST['_wpnonce']) || !wp_verify_nonce($_POST['_wpnonce'], self::NONCE_ACTION)) {
            $reject('invalid');
        }

        // Honeypot: bots fill in fields humans don't see
        if (!empty($_POST['company_website'])) {
            $reject('invalid');
        }

        // Time gate (HMAC-signed): reject submissions that arrive too fast to be human.
        // FIX (security): the timestamp is HMAC-signed on the server when the form
        // is rendered. Without this signing, a bot could just send any timestamp it
        // wants (e.g. "now minus 100 seconds") and bypass the check trivially. With
        // the HMAC, the bot would need wp_salt('auth') to forge a valid pair, which
        // it doesn't have. So the time gate now actually works.
        $form_ts = isset($_POST['pcm_form_ts']) ? (int) $_POST['pcm_form_ts'] : 0;
        $form_ts_sig = isset($_POST['pcm_form_ts_sig']) ? (string) $_POST['pcm_form_ts_sig'] : '';
        $expected_sig = hash_hmac('sha256', (string) $form_ts, wp_salt('auth') . '|pcm_form_ts');
        if ($form_ts <= 0 || !hash_equals($expected_sig, $form_ts_sig) || (time() - $form_ts) < self::MIN_FORM_AGE) {
            $reject('invalid');
        }

        // Rate limit per IP (admin can disable on demo sites via Settings → Shortcode)
        $rate_limit_enabled = (bool) PCM_Settings::get('shortcode_rate_limit_enabled', true);
        $ip_key = 'pcm_sc_rl_' . md5($this->get_client_ip());
        $attempts = (int) get_transient($ip_key);
        if ($rate_limit_enabled && $attempts >= self::RATE_LIMIT_MAX) {
            $reject('locked');
        }

        $hash = (string) PCM_Settings::get('shortcode_password_hash', '');
        $password = isset($_POST['pcm_password']) ? (string) wp_unslash($_POST['pcm_password']) : '';

        // FIX (DoS): cap password length BEFORE password_verify(). bcrypt is CPU-bound
        // and runs in time proportional to input length. Without this cap, an attacker
        // could send a 10 MB "password" and pin a CPU core for several seconds per
        // request. 256 chars is well above any realistic legit password length.
        if (strlen($password) > 256) {
            $reject('invalid');
        }

        if ($hash === '' || $password === '' || !password_verify($password, $hash)) {
            if ($rate_limit_enabled) {
                set_transient($ip_key, $attempts + 1, self::RATE_LIMIT_WINDOW);
            }
            $reject('invalid');
        }

        // Success — clear rate limit, set cookie, redirect clean
        delete_transient($ip_key);
        PCM_Gate_Auth::set_cookie();
        wp_safe_redirect($redirect_url);
        exit;
    }

    /**
     * Shortcode entry point.
     *
     * Attributes:
     *   mode  — "fullscreen" (default, app takes over viewport) or "inline" (sits in theme).
     */
    public function render($atts = array(), ?string $content = null): string
    {
        $atts = shortcode_atts(array(
            'mode' => 'fullscreen',
        ), $atts, 'power_creatives');

        $mode = $atts['mode'] === 'inline' ? 'inline' : 'fullscreen';

        // Admin bypass — logged-in admins skip the gate
        if (is_user_logged_in() && current_user_can('manage_options')) {
            return $this->render_dashboard($mode);
        }

        // Public client review board bypass
        if (isset($_GET['pcm_public_token'])) {
            return $this->render_dashboard($mode);
        }

        if (PCM_Gate_Auth::is_authenticated()) {
            return $this->render_dashboard($mode);
        }

        return $this->render_login_form($mode);
    }

    private function render_dashboard(string $mode): string
    {
        if (!$this->enqueued) {
            $this->enqueue_assets();
            $this->enqueued = true;
        }

        // Admins see "Exit" (back to wp-admin) — sign-out has no effect for them
        // since admin-bypass would just re-grant access. Password-authed visitors
        // see "Sign out" which clears the cookie and shows the login form.
        $is_admin_view = is_user_logged_in() && current_user_can('manage_options');
        if ($is_admin_view) {
            $logout_url = esc_url(admin_url());
            $logout_text = __('Exit (admin)', 'power-creatives');
        } else {
            $logout_url = esc_url(add_query_arg('pcm_logout', '1'));
            $logout_text = __('Sign out', 'power-creatives');
        }
        $logout_link = '<a href="' . $logout_url . '" style="font:13px/1 -apple-system,BlinkMacSystemFont,Inter,sans-serif;color:#666;text-decoration:none;padding:6px 10px;background:rgba(255,255,255,0.92);border:1px solid #e5e7eb;border-radius:6px;box-shadow:0 1px 2px rgba(0,0,0,0.04);">'
            . esc_html($logout_text) . '</a>';

        if ($mode === 'fullscreen') {
            // Fullscreen takeover strategy:
            //
            // 1. The JS below moves .pcm-fs-wrap to <body> level (escaping any
            //    theme container with transform/filter that would break position:fixed).
            //
            // 2. ALL existing body children at snapshot time get .pcm-fs-hide
            //    (display:none !important) — this is what actually hides the theme.
            //
            // 3. .pcm-fs-wrap intentionally has NO z-index. This is critical:
            //    Radix UI portals (Dialog, Select, DropdownMenu, Sheet, Popover,
            //    Tooltip, etc.) and Sonner toasts mount directly onto <body> AFTER
            //    the snapshot. They are NOT tagged with .pcm-fs-hide, so they stay
            //    visible. Their own z-index:50 naturally layers them above the
            //    wrapper, which sits in the normal stacking context.
            //    A high z-index on the wrapper would create an opaque stacking
            //    context that covers all portals — which is the exact bug we fixed.
            //
            // 4. .pcm-fs-logout uses z-index:51 — just above portal overlays so
            //    the sign-out link is always reachable.
            return '<style>'
                . 'html.pcm-fs-active,body.pcm-fs-active{margin:0 !important;padding:0 !important;overflow:hidden !important;height:100vh !important;}'
                . 'body.pcm-fs-active .pcm-fs-hide{display:none !important;}'
                . 'body.pcm-fs-active #wpadminbar{display:none !important;}'
                . '.pcm-fs-wrap{position:fixed;inset:0;background:#fff;overflow:auto;-webkit-overflow-scrolling:touch;}'
                . '.pcm-fs-logout{position:fixed;bottom:12px;left:12px;z-index:51;}'
                . '</style>'
                . '<div class="pcm-fs-wrap"><div id="pcm-root"></div></div>'
                . '<div class="pcm-fs-logout">' . $logout_link . '</div>'
                . '<script>(function(){'
                . 'document.documentElement.classList.add("pcm-fs-active");'
                . 'var go=function(){'
                . 'document.body.classList.add("pcm-fs-active");'
                // Snapshot existing body children and tag them for hiding.
                // Future children (React portals) are NOT tagged → remain visible.
                . 'Array.prototype.slice.call(document.body.children).forEach(function(el){'
                . 'if(el.classList&&!el.classList.contains("pcm-fs-wrap")&&!el.classList.contains("pcm-fs-logout")){el.classList.add("pcm-fs-hide");}'
                . '});'
                . 'var w=document.querySelector(".pcm-fs-wrap");'
                . 'var l=document.querySelector(".pcm-fs-logout");'
                . 'if(w&&w.parentNode!==document.body)document.body.appendChild(w);'
                . 'if(l&&l.parentNode!==document.body)document.body.appendChild(l);'
                . '};'
                . 'if(document.body){go();}else{document.addEventListener("DOMContentLoaded",go);}'
                . '})();</script>';
        }

        // Inline mode — sits in theme content area, no viewport takeover
        return '<div class="pcm-inline-wrap" style="position:relative;">'
            . '<div id="pcm-root"></div>'
            . '<div class="pcm-inline-logout" style="position:absolute;bottom:8px;left:8px;z-index:10;">' . $logout_link . '</div>'
            . '</div>';
    }

    // ────────────────────────────────────────────────────────────────────────
    // CLIENT IP (used for rate limiting)
    // ────────────────────────────────────────────────────────────────────────

    private function get_client_ip(): string
    {
        // Trust REMOTE_ADDR; if behind a known proxy, the host should set this correctly.
        return isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '0.0.0.0';
    }

    // ────────────────────────────────────────────────────────────────────────
    // LOGIN FORM (redesign-friendly: all markup + style in one method)
    // ────────────────────────────────────────────────────────────────────────

    private function render_login_form(string $mode = 'fullscreen'): string
    {
        $err = isset($_GET['pcm_err']) ? sanitize_key((string) $_GET['pcm_err']) : '';
        $no_password_set = (string) PCM_Settings::get('shortcode_password_hash', '') === '';

        $nonce = wp_create_nonce(self::NONCE_ACTION);
        // FIX (security): HMAC-sign the form timestamp so bots can't forge it.
        // Same secret derivation as handle_login_submit() — keep these two in sync.
        $form_ts = time();
        $form_ts_sig = hash_hmac('sha256', (string) $form_ts, wp_salt('auth') . '|pcm_form_ts');
        $action_url = esc_url($this->current_url());

        $error_html = '';
        if ($no_password_set) {
            $error_html = '<p class="pcm-msg pcm-msg-warn">' . esc_html__('No password has been set yet. Configure it in WordPress Admin → Power Creatives → Shortcode.', 'power-creatives') . '</p>';
        } elseif ($err === 'invalid') {
            $error_html = '<p class="pcm-msg pcm-msg-err">' . esc_html__('Incorrect password.', 'power-creatives') . '</p>';
        } elseif ($err === 'locked') {
            $error_html = '<p class="pcm-msg pcm-msg-err">' . esc_html__('Too many attempts. Try again in 15 minutes.', 'power-creatives') . '</p>';
        }

        $is_fullscreen = ($mode !== 'inline');

        ob_start();
        ?>
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap">
        <style>
            <?php if ($is_fullscreen): ?>
            html.pcm-fs-active,body.pcm-fs-active{margin:0 !important;padding:0 !important;overflow:hidden !important;height:100vh !important;}
            body.pcm-fs-active .pcm-fs-hide{display:none !important;}
            body.pcm-fs-active #wpadminbar{display:none !important;}
            .pcm-gate{position:fixed;inset:0;z-index:2147483646;background:#fff;font-family:'Inter',-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;color:#0a0a0a;overflow:hidden;display:flex;justify-content:center;align-items:center;padding:48px 24px;-webkit-font-smoothing:antialiased;-moz-osx-font-smoothing:grayscale;text-rendering:optimizeLegibility;}
            <?php else: ?>
            .pcm-gate{position:relative;background:#fff;font-family:'Inter',-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;color:#0a0a0a;display:flex;justify-content:center;align-items:center;min-height:600px;padding:80px 24px;overflow:hidden;border-radius:16px;-webkit-font-smoothing:antialiased;-moz-osx-font-smoothing:grayscale;text-rendering:optimizeLegibility;}
            <?php endif; ?>
            .pcm-gate *{box-sizing:border-box;}

            /* Ambient blue gradient blobs (the look) */
            .pcm-glow{position:absolute;border-radius:50%;filter:blur(90px);pointer-events:none;z-index:0;will-change:transform;}
            .pcm-glow-1{top:-20%;left:35%;width:680px;height:680px;background:radial-gradient(circle,#5e8df0 0%,rgba(94,141,240,0) 65%);opacity:.75;}
            .pcm-glow-2{bottom:-25%;right:-5%;width:560px;height:560px;background:radial-gradient(circle,#3d6fe0 0%,rgba(61,111,224,0) 65%);opacity:.55;}
            .pcm-glow-3{top:30%;left:-10%;width:480px;height:480px;background:radial-gradient(circle,#9bb9ff 0%,rgba(155,185,255,0) 65%);opacity:.6;}
            @media (max-width:640px){
                .pcm-glow-1{width:440px;height:440px;}
                .pcm-glow-2{width:380px;height:380px;}
                .pcm-glow-3{width:340px;height:340px;}
            }

            .pcm-gate-card{position:relative;z-index:10;width:100%;max-width:400px;padding:40px 44px 36px;background:rgba(255,255,255,0.9);backdrop-filter:saturate(180%) blur(20px);-webkit-backdrop-filter:saturate(180%) blur(20px);border:1px solid rgba(255,255,255,0.6);border-radius:18px;box-shadow:0 1px 2px rgba(15,40,90,0.04),0 12px 32px rgba(15,40,90,0.08);}
            .pcm-gate-h{margin:0 0 6px;font-size:22px;font-weight:600;letter-spacing:-0.025em;line-height:1.2;color:#0a0a0a;text-align:center;}
            .pcm-gate-sub{margin:0 0 28px;font-size:13px;color:#71717a;text-align:center;line-height:1.45;letter-spacing:-0.005em;}
            .pcm-gate-form{text-align:left;}
            .pcm-gate-label{display:block;font-size:11px;font-weight:500;margin-bottom:8px;color:#52525b;letter-spacing:0.08em;text-transform:uppercase;}
            .pcm-gate-input{width:100%;height:40px;padding:0 14px;font-size:14px;font-family:inherit;font-weight:400;color:#0a0a0a;border:1px solid rgba(0,0,0,0.12);border-radius:8px;background:#fff;transition:border-color .15s ease,box-shadow .15s ease;letter-spacing:-0.005em;}
            .pcm-gate-input::placeholder{color:#a1a1aa;}
            .pcm-gate-input:focus{outline:none;border-color:rgba(94,141,240,0.6);box-shadow:0 0 0 4px rgba(94,141,240,0.12);}
            .pcm-gate-input:disabled{background:#fafafa;color:rgba(0,0,0,0.3);cursor:not-allowed;}
            .pcm-gate-btn{width:100%;height:42px;margin-top:18px;padding:0;font-size:14px;font-weight:500;font-family:inherit;letter-spacing:-0.005em;color:#fff;background:#0a0a0a;border:0;border-radius:8px;cursor:pointer;transition:background .15s ease,transform .05s ease;}
            .pcm-gate-btn:hover{background:#27272a;}
            .pcm-gate-btn:active{transform:translateY(1px);}
            .pcm-gate-btn:disabled{background:rgba(0,0,0,0.2);cursor:not-allowed;}
            .pcm-msg{margin:0 0 18px;padding:10px 12px;font-size:13px;border-radius:8px;line-height:1.4;letter-spacing:-0.005em;text-align:left;}
            .pcm-msg-err{background:rgba(220,38,38,0.06);color:#b91c1c;border:1px solid rgba(220,38,38,0.15);}
            .pcm-msg-warn{background:rgba(245,158,11,0.06);color:#92400e;border:1px solid rgba(245,158,11,0.18);}
            .pcm-hp{position:absolute;left:-9999px;width:1px;height:1px;opacity:0;pointer-events:none;}
        </style>
        <div class="pcm-gate">
            <div class="pcm-glow pcm-glow-1" aria-hidden="true"></div>
            <div class="pcm-glow pcm-glow-2" aria-hidden="true"></div>
            <div class="pcm-glow pcm-glow-3" aria-hidden="true"></div>
            <div class="pcm-gate-card">
                <h1 class="pcm-gate-h">Sign in</h1>
                <p class="pcm-gate-sub">Enter the access password to continue</p>
                <?php echo $error_html; // already escaped above ?>
                <form class="pcm-gate-form" method="post" action="<?php echo $action_url; ?>" autocomplete="off">
                    <label class="pcm-gate-label" for="pcm-password">Password</label>
                    <input id="pcm-password" class="pcm-gate-input" type="password" name="pcm_password" placeholder="••••••••" autocomplete="current-password" required autofocus <?php echo $no_password_set ? 'disabled' : ''; ?> />
                    <div class="pcm-hp" aria-hidden="true">
                        <label for="pcm-cw">Company website</label>
                        <input type="text" id="pcm-cw" name="company_website" tabindex="-1" autocomplete="off" />
                    </div>
                    <input type="hidden" name="pcm_form_ts" value="<?php echo esc_attr((string) $form_ts); ?>" />
                    <input type="hidden" name="pcm_form_ts_sig" value="<?php echo esc_attr($form_ts_sig); ?>" />
                    <input type="hidden" name="pcm_login_submit" value="1" />
                    <?php wp_nonce_field(self::NONCE_ACTION); ?>
                    <button type="submit" class="pcm-gate-btn" <?php echo $no_password_set ? 'disabled' : ''; ?>>Continue</button>
                </form>
            </div>
        </div>
        <?php if ($is_fullscreen): ?>
        <script>(function(){
            document.documentElement.classList.add("pcm-fs-active");
            var go=function(){
                document.body.classList.add("pcm-fs-active");
                Array.prototype.slice.call(document.body.children).forEach(function(el){
                    if(el.classList&&!el.classList.contains("pcm-gate")){el.classList.add("pcm-fs-hide");}
                });
                var w=document.querySelector(".pcm-gate");
                if(w&&w.parentNode!==document.body)document.body.appendChild(w);
            };
            if(document.body){go();}else{document.addEventListener("DOMContentLoaded",go);}
        })();</script>
        <?php endif; ?>
        <?php
        return (string) ob_get_clean();
    }

    private function current_url(): string
    {
        $scheme = is_ssl() ? 'https' : 'http';
        $host = isset($_SERVER['HTTP_HOST']) ? (string) $_SERVER['HTTP_HOST'] : '';
        $uri = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '/';
        return $scheme . '://' . $host . $uri;
    }

    // ────────────────────────────────────────────────────────────────────────
    // ASSET ENQUEUE (mirrors PCM_Admin)
    // ────────────────────────────────────────────────────────────────────────

    private function enqueue_assets(): void
    {
        $app_dir = PCM_PLUGIN_DIR . 'app/dist/';
        $app_url = PCM_PLUGIN_URL . 'app/dist/';

        // Match PCM_Admin: try index-writer.js first, fall back to index.js
        $entry_file = file_exists($app_dir . 'index-writer.js') ? 'index-writer.js' : 'index.js';

        if (!file_exists($app_dir . $entry_file)) {
            return;
        }

        wp_enqueue_style(
            'pcm-google-fonts',
            'https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap',
            array(),
            null
        );

        if (file_exists($app_dir . 'index.css')) {
            wp_enqueue_style(
                'pcm-app',
                $app_url . 'index.css',
                array('pcm-google-fonts'),
                PCM_VERSION
            );
        }

        wp_enqueue_script(
            'pcm-app',
            $app_url . $entry_file,
            array(),
            PCM_VERSION,
            true
        );

        add_filter('script_loader_tag', function ($tag, $handle) {
            if ('pcm-app' === $handle) {
                return str_replace(' src', ' type="module" src', $tag);
            }
            return $tag;
        }, 10, 2);

        // Polyfill crypto.randomUUID() for non-secure contexts (http:// local dev).
        // The Web Crypto API's randomUUID() is only available in Secure Contexts
        // (HTTPS or localhost). Local dev domains like http://powercreatives.local
        // are NOT considered secure, causing TypeError crashes in the React app.
        // This polyfill uses crypto.getRandomValues() which IS available everywhere.
        // Mirrors the identical polyfill in PCM_Admin::enqueue_assets().
        wp_add_inline_script('pcm-app', '
            if (typeof crypto !== "undefined" && typeof crypto.randomUUID !== "function") {
                crypto.randomUUID = function() {
                    var a = new Uint8Array(16);
                    crypto.getRandomValues(a);
                    a[6] = (a[6] & 0x0f) | 0x40;
                    a[8] = (a[8] & 0x3f) | 0x80;
                    var h = Array.from(a, function(b) { return b.toString(16).padStart(2, "0"); }).join("");
                    return h.slice(0,8) + "-" + h.slice(8,12) + "-" + h.slice(12,16) + "-" + h.slice(16,20) + "-" + h.slice(20);
                };
            }
        ', 'before');

        wp_localize_script('pcm-app', 'pcmConfig', $this->get_js_config());
    }

    private function get_js_config(): array
    {
        $is_team_member = is_user_logged_in() && (current_user_can('edit_posts') || current_user_can('manage_options'));

        // Two contexts produce two user shapes:
        //  - WP-logged-in user → identifies as that WP user (admin or otherwise)
        //  - Gate-authed visitor → identifies as the shared workspace user, so
        //    the React app shows consistent identity across all visitors.
        if (is_user_logged_in()) {
            $wp_user = wp_get_current_user();
            $user_payload = array(
                'id' => $wp_user->ID,
                'name' => $wp_user->display_name,
                'email' => $wp_user->user_email,
                'role' => current_user_can('manage_options') ? 'admin' : 'user',
                'avatarUrl' => get_avatar_url($wp_user->ID),
                'isLoggedIn' => $is_team_member,
            );
        } else {
            // FIX: previously we sent hardcoded id=0, which could break any frontend
            // code path that does `if (!user.id) return null` or filters records by
            // user.id. Now we resolve to the SHARED workspace PCM user's real ID, so
            // the React app has a consistent identity that matches what the REST
            // layer sees (PCM_REST_Base::get_current_pcm_user() also returns the
            // shared user for gate-authed visitors). Falls back to 0 only if the
            // shared user couldn't be created (e.g. DB write failure).
            $shared_id = 0;
            if (class_exists('PCM_Gate_Auth') && PCM_Gate_Auth::is_authenticated()) {
                $shared = PCM_Gate_Auth::get_shared_pcm_user();
                if ($shared && isset($shared->id)) {
                    $shared_id = (int) $shared->id;
                }
            }
            $user_payload = array(
                'id' => $shared_id,
                'name' => __('Shortcode User', 'power-creatives'),
                'email' => '',
                'role' => 'user',
                'avatarUrl' => '',
                'isLoggedIn' => $is_team_member,
            );
        }

        // Dynamically find the published page/post containing the [power_creatives] shortcode
        $shortcode_page_url = home_url('/'); // Safe default fallback
        
        global $wpdb;
        $like_sc = '%' . $wpdb->esc_like('[power_creatives]') . '%';
        $page_id = $wpdb->get_var($wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_content LIKE %s LIMIT 1",
            $like_sc
        ));
        
        if ($page_id) {
            $shortcode_page_url = get_permalink((int) $page_id);
        }

        return array(
            'restUrl' => esc_url_raw(rest_url('pcm/v1/')),
            'nonce' => wp_create_nonce('wp_rest'),
            'pluginUrl' => esc_url(PCM_PLUGIN_URL),
            'shortcodePageUrl' => esc_url_raw($shortcode_page_url),
            'user' => $user_payload,
            'version' => PCM_VERSION,
        );
    }
}
