<?php
/**
 * Automation Engine
 *
 * The single dispatch point for the Automations module. Triggers (currently the
 * Approvals module) call PCM_Automation_Engine::dispatch($event, $context, $userId).
 * The engine resolves matching rules from wp_pcm_automations (plus sensible
 * synthesized defaults so the MVP works without manual setup), fans the event
 * out to the relevant channels (webhook / email), and records every attempt in
 * wp_pcm_automation_logs for auditing and idempotency.
 *
 * Channels are pure transport (see channels/). All persistence and rule logic
 * lives here.
 *
 * @package PowerCreatives
 * @since   1.14.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class PCM_Automation_Engine
{
    /**
     * Which channels each event fans out to by default. Used both to
     * synthesize default rules and to know what content to render.
     *
     * @var array<string, string[]>
     */
    private const EVENT_CHANNELS = array(
        PCM_Automation_Events::APPROVAL_SET_SHARED         => array('email'),
        PCM_Automation_Events::APPROVAL_COMMENT_CREATED    => array('webhook'),
        PCM_Automation_Events::APPROVAL_COMMENT_TEAM_REPLY => array('email'),
        PCM_Automation_Events::APPROVAL_ALL_APPROVED       => array('webhook'),
        PCM_Automation_Events::APPROVAL_COMPLETED          => array('webhook'),
    );

    /**
     * Registry of available channels, keyed by channel id.
     *
     * @var array<string, PCM_Automation_Channel>
     */
    private static array $channels = array();

    /**
     * Registry of cross-module action handlers, keyed by action id.
     * Modules register their own handlers (in includes/modules/{id}/automations.php)
     * so the engine can execute their actions. The built-in 'webhook' handler is
     * registered in boot().
     *
     * @var array<string, PCM_Automation_Action_Handler>
     */
    private static array $action_handlers = array();

    /**
     * Whether built-in channels/handlers have been registered.
     *
     * @var bool
     */
    private static bool $booted = false;

    /**
     * Register the built-in channels + action handlers once. Extra ones can be
     * added via register_channel()/register_action_handler() (e.g. by a module's
     * automations.php) before the first dispatch.
     *
     * @return void
     */
    private static function boot(): void
    {
        if (self::$booted) {
            return;
        }
        self::register_channel(new PCM_Webhook_Channel());
        self::register_channel(new PCM_Brevo_Email_Channel());
        // Built-in cross-module action executor.
        if (class_exists('PCM_Webhook_Action_Handler')) {
            self::register_action_handler(new PCM_Webhook_Action_Handler());
        }
        self::$booted = true;
    }

    /**
     * Register (or override) an automation channel.
     *
     * @param PCM_Automation_Channel $channel Channel instance.
     * @return void
     */
    public static function register_channel(PCM_Automation_Channel $channel): void
    {
        self::$channels[$channel->id()] = $channel;
    }

    /**
     * Register (or override) a cross-module action handler. Called by a module's
     * automations.php so the engine can run that module's action.
     *
     * @param PCM_Automation_Action_Handler $handler Handler instance.
     * @return void
     */
    public static function register_action_handler(PCM_Automation_Action_Handler $handler): void
    {
        self::$action_handlers[$handler->id()] = $handler;
    }

    /**
     * Dispatch an event to all matching channels.
     *
     * @param string $event   One of PCM_Automation_Events::*.
     * @param array  $context Event context (see PCM_Automation_Events docblocks).
     * @param int    $user_id Owning PCM user id.
     * @return array<int, array> The per-rule send results (for tests/introspection).
     */
    public static function dispatch(string $event, array $context, int $user_id): array
    {
        self::boot();

        if (!PCM_Automation_Events::is_valid($event) || $user_id <= 0) {
            return array();
        }

        $context['event'] = $event;
        $brand_id = isset($context['brandId']) ? (int) $context['brandId'] : 0;

        $rules   = self::resolve_rules($event, $user_id, $brand_id, $context);
        $dedupe  = self::dedupe_key($event, $context);
        $results = array();

        foreach ($rules as $rule) {
            $channel = self::$channels[$rule['channel']] ?? null;
            if (!$channel) {
                continue;
            }

            $ctx = $context;

            // The email channel needs rendered content + a recipient.
            if ($rule['channel'] === 'email') {
                $email = self::render_email_for_event($event, $context);
                if ($email === null) {
                    continue; // Not emailable / no recipient — skip silently.
                }
                $ctx['email'] = $email;
            }

            // Idempotency: skip if this dedupe key already produced a sent log.
            if ($dedupe !== null && self::already_sent($dedupe, $user_id)) {
                continue;
            }

            try {
                $result = $channel->send($rule['config'], $ctx, $user_id);
            } catch (\Throwable $e) {
                $result = array('ok' => false, 'code' => 0, 'target' => '', 'error' => $e->getMessage(), 'skipped' => false);
            }

            self::log($user_id, $rule, $event, $ctx, $result, $dedupe);
            $results[] = array_merge($result, array('channel' => $rule['channel'], 'ruleId' => $rule['id']));
        }

        return $results;
    }

    /**
     * Resolve the rules that should fire for an event: explicit rows from
     * wp_pcm_automations, plus a synthesized default per expected channel when
     * the user has not configured one (so the MVP works out of the box).
     *
     * @param string $event    Event id.
     * @param int    $user_id  PCM user id.
     * @param int    $brand_id Brand id (0 = none).
     * @param array  $context  Event context (for default URL resolution).
     * @return array<int, array{ id: ?int, channel: string, config: array }>
     */
    private static function resolve_rules(string $event, int $user_id, int $brand_id, array $context): array
    {
        global $wpdb;
        $table = PCM_Schema::table('automations');

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, channel, config FROM {$table}
                 WHERE userId = %d AND event = %s AND isActive = 1
                   AND (brandId IS NULL OR brandId = %d)",
                $user_id,
                $event,
                $brand_id
            )
        ) ?: array();

        $rules = array();
        $channels_with_rule = array();

        foreach ($rows as $row) {
            $config = json_decode((string) $row->config, true);
            $rules[] = array(
                'id'      => (int) $row->id,
                'channel' => (string) $row->channel,
                'config'  => is_array($config) ? $config : array(),
            );
            $channels_with_rule[(string) $row->channel] = true;
        }

        // Synthesize defaults for expected channels the user hasn't configured.
        foreach (self::EVENT_CHANNELS[$event] ?? array() as $channel) {
            if (isset($channels_with_rule[$channel])) {
                continue;
            }
            $config = self::default_config($channel, $user_id, $brand_id);
            if ($config !== null) {
                $rules[] = array('id' => null, 'channel' => $channel, 'config' => $config);
            }
        }

        return $rules;
    }

    /**
     * Build the default config for a synthesized rule, or null to skip.
     *
     * @param string $channel  Channel id.
     * @param int    $user_id  PCM user id.
     * @param int    $brand_id Brand id (0 = none).
     * @return array|null
     */
    private static function default_config(string $channel, int $user_id, int $brand_id): ?array
    {
        if ($channel === 'webhook') {
            $url = self::resolve_default_webhook_url($brand_id, $user_id);
            return $url === '' ? null : array('url' => $url);
        }

        if ($channel === 'email') {
            // The Brevo channel pulls sender + key from settings/integrations and
            // the recipient from the rendered context, so an empty config is fine.
            return array();
        }

        return null;
    }

    /**
     * Resolve a webhook URL from brand override → global setting (legacy logic,
     * preserved so existing installs keep working after the refactor).
     *
     * @param int $brand_id Brand id (0 = none).
     * @param int $user_id  PCM user id.
     * @return string URL or ''.
     */
    private static function resolve_default_webhook_url(int $brand_id, int $user_id): string
    {
        if ($brand_id > 0) {
            $brand = PCM_DB::get_brand_by_id($brand_id, $user_id);
            if ($brand && !empty($brand->additionalContext)) {
                $brand_ctx = json_decode($brand->additionalContext, true);
                if (is_array($brand_ctx) && !empty($brand_ctx['webhookUrl'])) {
                    return esc_url_raw($brand_ctx['webhookUrl']);
                }
            }
        }

        return (string) PCM_Settings::get('global_webhook_url', '');
    }

    /**
     * Render the event-specific email (subject + HTML + recipient), or null
     * when the event is not emailable or has no recipient.
     *
     * @param string $event   Event id.
     * @param array  $context Event context.
     * @return array{ to: string, subject: string, html: string }|null
     */
    private static function render_email_for_event(string $event, array $context): ?array
    {
        $to = isset($context['clientEmail']) ? sanitize_email((string) $context['clientEmail']) : '';
        if ($to === '' || !is_email($to)) {
            return null;
        }

        if ($event === PCM_Automation_Events::APPROVAL_SET_SHARED) {
            $rendered = PCM_Automation_Templates::client_invite($context);
        } elseif ($event === PCM_Automation_Events::APPROVAL_COMMENT_TEAM_REPLY) {
            $rendered = PCM_Automation_Templates::team_reply($context);
        } else {
            return null;
        }

        return array(
            'to'      => $to,
            'subject' => $rendered['subject'],
            'html'    => $rendered['html'],
        );
    }

    /**
     * Compute a dedupe key for events that must fire at most once, else null.
     *
     * @param string $event   Event id.
     * @param array  $context Event context.
     * @return string|null
     */
    private static function dedupe_key(string $event, array $context): ?string
    {
        if ($event === PCM_Automation_Events::APPROVAL_ALL_APPROVED && !empty($context['setId'])) {
            return 'all_approved:set:' . (int) $context['setId'];
        }
        return null;
    }

    /**
     * Whether a dedupe key has already produced a successful send.
     *
     * @param string $dedupe_key Dedupe key.
     * @param int    $user_id    PCM user id.
     * @return bool
     */
    private static function already_sent(string $dedupe_key, int $user_id, bool $any_status = false): bool
    {
        global $wpdb;
        $table = PCM_Schema::table('automation_logs');

        // $any_status=true → ANY prior log for this dedupe slice counts (sent,
        // skipped, or failed). Used by TIME-SLICED keys (pending scanner): the
        // caller picks a fresh key for the next slice, so a failed attempt only
        // suppresses noise within the slice, never the next cycle.
        // $any_status=false → only a successful 'sent' row blocks. Used by
        // NON-sliced keys (e.g. all_approved:set:{id}) where there is no next
        // slice — a transient failure must stay retryable.
        $status_sql = $any_status ? '' : " AND status = 'sent'";
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
        $count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table}
             WHERE userId = %d AND dedupeKey = %s{$status_sql}",
            $user_id,
            $dedupe_key
        ));

        return (int) $count > 0;
    }

    /**
     * Record a dispatch attempt in the audit log.
     *
     * @param int         $user_id PCM user id.
     * @param array       $rule    Resolved rule.
     * @param string      $event   Event id.
     * @param array       $context Event context (hashed, never stored raw).
     * @param array       $result  Channel send result.
     * @param string|null $dedupe  Dedupe key or null.
     * @return void
     */
    private static function log(int $user_id, array $rule, string $event, array $context, array $result, ?string $dedupe): void
    {
        global $wpdb;
        $table = PCM_Schema::table('automation_logs');

        if (!empty($result['skipped'])) {
            $status = 'skipped';
        } elseif (!empty($result['ok'])) {
            $status = 'sent';
        } else {
            $status = 'failed';
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $wpdb->insert(
            $table,
            array(
                'userId'       => $user_id,
                'automationId' => $rule['id'] ?? null,
                'event'        => $event,
                'channel'      => $rule['channel'],
                'target'       => isset($result['target']) ? substr((string) $result['target'], 0, 255) : null,
                'dedupeKey'    => $dedupe,
                'payloadHash'  => hash('sha256', (string) wp_json_encode($context)),
                'status'       => $status,
                'httpCode'     => isset($result['code']) ? (int) $result['code'] : null,
                'error'        => isset($result['error']) ? $result['error'] : null,
            )
        );
    }

    // =========================================================================
    // TRIGGER → CONDITION → ACTION (user-defined automation rules)
    // =========================================================================

    /**
     * Fire a trigger: run every active rule whose trigger matches and whose
     * conditions are satisfied by the context. This is the entry point modules
     * call (e.g. Approvals on a lane change).
     *
     * @param string      $trigger_id One of PCM_Automation_Triggers::all() ids.
     * @param array       $context    Trigger context (condition + payload values).
     * @param int         $user_id    Owning PCM user id.
     * @param array       $opts       Optional: {
     *   ruleId?     int  — fire only this rule (cron scanner has already chosen it),
     *   dedupeKey?  string — guard against re-firing for the same (caller-defined)
     *                        slice (e.g. per-day reminders). Logged + checked via
     *                        automation_logs.dedupeKey.
     *   skipConditions? bool — bypass conditions_match() when the caller has
     *                        already decided eligibility (used by the scanner since
     *                        equality doesn't model `daysSinceSent >= minDays`).
     * }.
     * @return array<int, array> Per-rule action results (for tests/introspection).
     */
    public static function fire_trigger(string $trigger_id, array $context, int $user_id, array $opts = array()): array
    {
        self::boot();

        if ($user_id <= 0 || !PCM_Automation_Triggers::is_valid($trigger_id)) {
            return array();
        }

        $only_rule_id    = isset($opts['ruleId']) ? (int) $opts['ruleId'] : 0;
        $dedupe_key      = isset($opts['dedupeKey']) ? (string) $opts['dedupeKey'] : null;
        $skip_conditions = !empty($opts['skipConditions']);

        // Engine-level dedupe: if this caller-defined slice already produced a
        // 'sent' log for this user, do nothing. Cheap idempotency for cron loops.
        if ($dedupe_key !== null && self::already_sent($dedupe_key, $user_id, true)) {
            return array();
        }

        global $wpdb;
        $table = PCM_Schema::table('automations');

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $rows = $only_rule_id > 0
            ? $wpdb->get_results($wpdb->prepare(
                "SELECT id, conditions, actionId, config, inputMapping, brandId FROM {$table}
                 WHERE userId = %d AND id = %d AND triggerId = %s AND isActive = 1",
                $user_id,
                $only_rule_id,
                $trigger_id
            ))
            : $wpdb->get_results($wpdb->prepare(
                // The event owner's rules PLUS admin-owned rules — automations
                // an admin creates apply to every user's events (platform-wide
                // config lives with the admins). Seeded admin defaults are
                // excluded below: every user has their own seeded copies, so
                // letting the admin's copies fire globally would duplicate
                // every notification/lane-move.
                "SELECT a.id, a.userId, a.conditions, a.actionId, a.config, a.inputMapping, a.brandId
                 FROM {$table} a
                 LEFT JOIN " . PCM_Schema::table('users') . " u ON u.id = a.userId
                 WHERE a.triggerId = %s AND a.isActive = 1
                   AND (a.userId = %d OR u.role = 'admin')",
                $trigger_id,
                $user_id
            ));
        $rows = $rows ?: array();

        $results = array();
        foreach ($rows as $row) {
            // Brand scope: a rule pinned to a brand only fires for that brand.
            $brand_id = $row->brandId !== null ? (int) $row->brandId : null;
            if ($brand_id !== null && (int) ($context['brandId'] ?? 0) !== $brand_id) {
                continue;
            }

            $conditions = json_decode((string) $row->conditions, true);
            $conditions = is_array($conditions) ? $conditions : array();
            if (!$skip_conditions && !self::conditions_match($conditions, $context)) {
                continue;
            }

            $config = json_decode((string) $row->config, true);
            $config = is_array($config) ? $config : array();

            // Foreign admin rules: only CUSTOM ones (no __seedKey) go global —
            // the event owner's own seeded defaults already cover the seeded
            // behavior (see the query comment above).
            if (isset($row->userId) && (int) $row->userId !== $user_id && isset($config['__seedKey'])) {
                continue;
            }

            $mapping = json_decode((string) $row->inputMapping, true);
            $mapping = is_array($mapping) ? $mapping : array();

            $results[] = self::run_action((string) $row->actionId, $config, $mapping, $trigger_id, $context, $user_id, (int) $row->id, $dedupe_key);
        }

        return $results;
    }

    /**
     * Evaluate a rule's conditions against the trigger context.
     * MVP semantics: every condition key must equal the context value
     * (string-compared). Empty conditions always match.
     *
     * @param array $conditions Condition key→expected-value map.
     * @param array $context    Trigger context.
     * @return bool
     */
    private static function conditions_match(array $conditions, array $context): bool
    {
        foreach ($conditions as $key => $expected) {
            if ($expected === '' || $expected === null) {
                continue; // unset condition = "any"
            }
            if (!array_key_exists($key, $context) || (string) $context[$key] !== (string) $expected) {
                return false;
            }
        }
        return true;
    }

    /**
     * Run a single action for a matched rule by dispatching to the registered
     * action handler (cross-module seam). The rule's inputMapping is resolved
     * against the trigger context to build the handler inputs. Sync handlers run
     * inline; async handlers are scheduled via wp-cron so they never block the
     * triggering request. Unregistered/not-yet-implemented actions log 'skipped'.
     *
     * @param string $action_id  Action id.
     * @param array  $config     Action config (handler-specific, e.g. { url, secret }).
     * @param array  $mapping    Rule inputMapping (empty = handler default payload).
     * @param string $trigger_id Firing trigger id.
     * @param array  $context    Trigger context.
     * @param int    $user_id    PCM user id.
     * @param int    $rule_id    Rule id (for the log).
     * @return array Result (ok/code/target/error/skipped + ruleId/action).
     */
    private static function run_action(string $action_id, array $config, array $mapping, string $trigger_id, array $context, int $user_id, int $rule_id, ?string $dedupe_key = null): array
    {
        $handler = self::$action_handlers[$action_id] ?? null;

        // Registered-but-not-implemented ("coming soon") or unknown action.
        if (!$handler) {
            $result = array(
                'ok'      => false,
                'code'    => 0,
                'target'  => '',
                'error'   => 'Action not implemented yet: ' . $action_id,
                'skipped' => true,
            );
            self::log_action($user_id, $rule_id, $trigger_id, $action_id, $result, $context, $dedupe_key);
            return array_merge($result, array('ruleId' => $rule_id, 'action' => $action_id));
        }

        // Build handler inputs from the rule's input mapping (empty → handler default).
        $inputs = !empty($mapping) ? PCM_Automation_Mapping::resolve($mapping, $context) : array();
        $ctx    = array_merge($context, array('event' => $trigger_id));

        // Async (long-running) actions are scheduled via wp-cron so they don't
        // block the triggering request. The webhook handler is sync.
        if ($handler->mode() === 'async' && function_exists('wp_schedule_single_event')) {
            wp_schedule_single_event(time(), 'pcm_automation_run_action', array(array(
                'actionId' => $action_id,
                'config'   => $config,
                'inputs'   => $inputs,
                'context'  => $ctx,
                'userId'   => $user_id,
                'ruleId'   => $rule_id,
            )));
            $result = array('ok' => true, 'code' => 0, 'target' => $action_id, 'error' => null, 'skipped' => false);
            self::log_action($user_id, $rule_id, $trigger_id, $action_id, $result, $inputs ?: $ctx, $dedupe_key);
            return array_merge($result, array('ruleId' => $rule_id, 'action' => $action_id, 'queued' => true));
        }

        try {
            $result = $handler->run($config, $inputs, $ctx, $user_id);
        } catch (\Throwable $e) {
            $result = array('ok' => false, 'code' => 0, 'target' => '', 'error' => $e->getMessage(), 'skipped' => false);
        }

        self::log_action($user_id, $rule_id, $trigger_id, $action_id, $result, $inputs ?: $ctx, $dedupe_key);
        return array_merge($result, array('ruleId' => $rule_id, 'action' => $action_id));
    }

    /**
     * wp-cron callback that executes an async action scheduled by run_action().
     * Registered via add_action('pcm_automation_run_action', ...) at file load.
     *
     * @param array $args { actionId, config, inputs, context, userId, ruleId }.
     * @return void
     */
    public static function run_scheduled_action($args): void
    {
        self::boot();
        if (!is_array($args)) {
            return;
        }
        $action_id = (string) ($args['actionId'] ?? '');
        $handler   = self::$action_handlers[$action_id] ?? null;
        if (!$handler) {
            return;
        }

        $context = is_array($args['context'] ?? null) ? $args['context'] : array();
        $inputs  = is_array($args['inputs'] ?? null) ? $args['inputs'] : array();
        $user_id = (int) ($args['userId'] ?? 0);
        $rule_id = (int) ($args['ruleId'] ?? 0);

        try {
            $result = $handler->run((array) ($args['config'] ?? array()), $inputs, $context, $user_id);
        } catch (\Throwable $e) {
            $result = array('ok' => false, 'code' => 0, 'target' => '', 'error' => $e->getMessage(), 'skipped' => false);
        }

        self::log_action($user_id, $rule_id, (string) ($context['event'] ?? $action_id), $action_id, $result, $inputs ?: $context);
    }

    /**
     * Append a rule-action attempt to the audit log (reuses automation_logs;
     * event = triggerId, channel = actionId).
     *
     * @param int    $user_id    PCM user id.
     * @param int    $rule_id    Rule id.
     * @param string $trigger_id Trigger id.
     * @param string $action_id  Action id.
     * @param array  $result     Action result.
     * @param array  $payload    Payload (hashed, never stored raw).
     * @return void
     */
    private static function log_action(int $user_id, int $rule_id, string $trigger_id, string $action_id, array $result, array $payload, ?string $dedupe_key = null): void
    {
        global $wpdb;
        $table = PCM_Schema::table('automation_logs');

        if (!empty($result['skipped'])) {
            $status = 'skipped';
        } elseif (!empty($result['ok'])) {
            $status = 'sent';
        } else {
            $status = 'failed';
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $wpdb->insert(
            $table,
            array(
                'userId'       => $user_id,
                'automationId' => $rule_id ?: null,
                'event'        => $trigger_id,
                'channel'      => $action_id,
                'target'       => isset($result['target']) ? substr((string) $result['target'], 0, 255) : null,
                'dedupeKey'    => $dedupe_key,
                'payloadHash'  => hash('sha256', (string) wp_json_encode($payload)),
                'status'       => $status,
                'httpCode'     => isset($result['code']) ? (int) $result['code'] : null,
                'error'        => isset($result['error']) ? $result['error'] : null,
            )
        );
    }

    // =========================================================================
    // RULE CRUD (used by the REST controller / Automations module UI)
    // =========================================================================

    /**
     * List a user's automation rules.
     *
     * @param int $user_id PCM user id.
     * @return array<int, object>
     */
    public static function list_rules(int $user_id): array
    {
        global $wpdb;
        $table = PCM_Schema::table('automations');

        // Admins see every user's rules (team-wide oversight — same law as
        // brands/deliveries/approvals lists).
        if (class_exists('PCM_Access') && PCM_Access::is_admin($user_id)) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $rows = $wpdb->get_results("SELECT * FROM {$table} ORDER BY createdAt DESC") ?: array();
        } else {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $rows = $wpdb->get_results(
                $wpdb->prepare("SELECT * FROM {$table} WHERE userId = %d ORDER BY createdAt DESC", $user_id)
            ) ?: array();
        }

        return array_map(static function ($row) {
            $config = json_decode((string) $row->config, true);
            $conditions = json_decode((string) $row->conditions, true);
            $mapping = json_decode((string) ($row->inputMapping ?? ''), true);
            $row->config = is_array($config) ? $config : array();
            $row->conditions = is_array($conditions) ? $conditions : array();
            $row->inputMapping = is_array($mapping) ? $mapping : array();
            $row->id = (int) $row->id;
            $row->userId = (int) $row->userId;
            $row->brandId = $row->brandId !== null ? (int) $row->brandId : null;
            $row->isActive = (bool) $row->isActive;
            return $row;
        }, $rows);
    }

    /**
     * Create an automation rule.
     *
     * @param int   $user_id PCM user id.
     * @param array $data    { triggerId, actionId, conditions, config, name?, brandId?, isActive? }.
     * @return int|false New rule id or false.
     */
    public static function create_rule(int $user_id, array $data): int|false
    {
        global $wpdb;
        $table = PCM_Schema::table('automations');

        $trigger = (string) ($data['triggerId'] ?? '');
        $action  = (string) ($data['actionId'] ?? '');

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $ok = $wpdb->insert(
            $table,
            array(
                'userId'     => $user_id,
                'brandId'    => !empty($data['brandId']) ? (int) $data['brandId'] : null,
                'name'       => isset($data['name']) ? (string) $data['name'] : null,
                'triggerId'  => $trigger,
                'conditions' => wp_json_encode($data['conditions'] ?? array()),
                'actionId'   => $action,
                'config'     => wp_json_encode($data['config'] ?? array()),
                'inputMapping' => wp_json_encode($data['inputMapping'] ?? array()),
                // Mirror into the legacy NOT-NULL columns so inserts succeed even
                // where the nullability ALTER is unsupported (e.g. SQLite).
                'event'      => $trigger,
                'channel'    => $action,
                'isActive'   => isset($data['isActive']) ? (int) (bool) $data['isActive'] : 1,
            )
        );

        return $ok ? (int) $wpdb->insert_id : false;
    }

    /**
     * Update an automation rule (ownership-scoped).
     *
     * @param int   $id      Rule id.
     * @param int   $user_id PCM user id.
     * @param array $data    Fields to update.
     * @return bool
     */
    /**
     * Fetch a single rule, ownership-scoped, with the config JSON decoded.
     *
     * @param int $id      Rule id.
     * @param int $user_id PCM user id.
     * @return object|null
     */
    public static function get_rule(int $id, int $user_id): ?object
    {
        global $wpdb;
        $table = PCM_Schema::table('automations');

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$table} WHERE id = %d AND userId = %d", $id, $user_id)
        );
        if (!$row) {
            return null;
        }
        $config      = json_decode((string) $row->config, true);
        $row->config = is_array($config) ? $config : array();
        return $row;
    }

    public static function update_rule(int $id, int $user_id, array $data): bool
    {
        global $wpdb;
        $table = PCM_Schema::table('automations');

        $update = array('updatedAt' => current_time('mysql'));
        if (isset($data['name'])) {
            $update['name'] = (string) $data['name'];
        }
        if (isset($data['triggerId'])) {
            $update['triggerId'] = (string) $data['triggerId'];
            $update['event'] = (string) $data['triggerId'];
        }
        if (isset($data['actionId'])) {
            $update['actionId'] = (string) $data['actionId'];
            $update['channel'] = (string) $data['actionId'];
        }
        if (array_key_exists('conditions', $data)) {
            $update['conditions'] = wp_json_encode($data['conditions'] ?? array());
        }
        if (array_key_exists('config', $data)) {
            $config = is_array($data['config'] ?? null) ? $data['config'] : array();
            // Preserve the seeder's idempotency marker across edits. The REST
            // sanitizer drops unknown config keys (by design — the marker must
            // never come from request input), so without this carry-over an
            // edited seeded rule would lose its marker and the per-request
            // seeder would re-insert a duplicate default rule.
            if (!isset($config['__seedKey'])) {
                $existing = self::get_rule($id, $user_id);
                if ($existing && isset($existing->config['__seedKey'])) {
                    $config['__seedKey'] = (string) $existing->config['__seedKey'];
                }
            }
            $update['config'] = wp_json_encode($config);
        }
        if (array_key_exists('inputMapping', $data)) {
            $update['inputMapping'] = wp_json_encode($data['inputMapping'] ?? array());
        }
        if (array_key_exists('brandId', $data)) {
            $update['brandId'] = !empty($data['brandId']) ? (int) $data['brandId'] : null;
        }
        if (isset($data['isActive'])) {
            $update['isActive'] = (int) (bool) $data['isActive'];
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $rows = $wpdb->update($table, $update, array('id' => $id, 'userId' => $user_id));

        return $rows !== false;
    }

    /**
     * Delete an automation rule (ownership-scoped).
     *
     * @param int $id      Rule id.
     * @param int $user_id PCM user id.
     * @return bool
     */
    public static function delete_rule(int $id, int $user_id): bool
    {
        global $wpdb;
        $table = PCM_Schema::table('automations');

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $rows = $wpdb->delete($table, array('id' => $id, 'userId' => $user_id), array('%d', '%d'));

        return $rows !== false && $rows > 0;
    }

    // =========================================================================
    // PENDING-CLIENT REMINDER SCANNER (daily wp-cron)
    // =========================================================================

    /**
     * Pure eligibility check for the pending-client trigger. Public so tests can
     * exercise the math without DB setup.
     *
     * Rule: fire when the set has been in the lane for at least `minDays`.
     * Repetition is handled by the CYCLE-anchored dedupe key (see
     * pending_cycle_day): one reminder per minDays-cycle, indefinitely, until
     * the set leaves the lane. Anchoring to the cycle (rather than requiring
     * days % minDays === 0 exactly) means a missed cron day — wp-cron is
     * traffic-driven and best-effort — fires a catch-up reminder on the next
     * run instead of silently skipping the whole cycle.
     *
     * @param int $days     daysSinceSent (computed from clientSentAt).
     * @param int $min_days Rule's minDays.
     * @return bool
     */
    public static function pending_should_fire(int $days, int $min_days): bool
    {
        return $min_days > 0 && $days >= $min_days;
    }

    /**
     * The start-day of the current reminder cycle: days 3–5 with minDays=3 all
     * map to 3; days 6–8 map to 6; etc. Used to anchor the dedupe key so each
     * cycle fires at most once, with catch-up if cron missed the boundary day.
     *
     * @param int $days     daysSinceSent.
     * @param int $min_days Rule's minDays (> 0).
     * @return int
     */
    public static function pending_cycle_day(int $days, int $min_days): int
    {
        return intdiv($days, $min_days) * $min_days;
    }

    /**
     * Build the dedupe key used by the scanner — keeps each (rule, set, cycle)
     * triple to one log row so the trigger can never fire twice in the same
     * cycle even if cron runs multiple times.
     *
     * @param int $rule_id Rule id.
     * @param int $set_id  Approval set id.
     * @param int $day     Cycle-anchored day (pending_cycle_day).
     * @return string
     */
    public static function pending_dedupe_key(int $rule_id, int $set_id, int $day): string
    {
        return 'pending:set:' . $set_id . ':rule:' . $rule_id . ':day:' . $day;
    }

    /**
     * wp-cron callback: scan every user's active rules for the pending-client
     * trigger, and for each rule fire the trigger on every set still in the
     * 'client' lane whose day count crosses the rule's `minDays` boundary.
     *
     * Registered via add_action('pcm_automation_check_pending_approvals', …) and
     * scheduled daily by pcm_init() in power-creatives.php.
     *
     * @return void
     */
    public static function run_pending_client_scan(): void
    {
        self::boot();

        global $wpdb;
        $rules_table = PCM_Schema::table('automations');
        $sets_table  = PCM_Schema::table('approval_sets');

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $rules = $wpdb->get_results($wpdb->prepare(
            "SELECT id, userId, brandId, conditions, actionId, config, inputMapping
             FROM {$rules_table}
             WHERE triggerId = %s AND isActive = 1",
            'approvals.set_pending_in_client'
        )) ?: array();

        if (empty($rules)) {
            return;
        }

        // Group rules by owning user (we only scan that user's sets).
        $by_user = array();
        foreach ($rules as $rule) {
            $by_user[(int) $rule->userId][] = $rule;
        }

        $now = current_time('timestamp');

        foreach ($by_user as $user_id => $user_rules) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $sets = $wpdb->get_results($wpdb->prepare(
                "SELECT id, name, token, status, clientEmail, brandId, clientSentAt
                 FROM {$sets_table}
                 WHERE userId = %d AND status = %s AND clientSentAt IS NOT NULL",
                $user_id,
                'client'
            )) ?: array();

            foreach ($sets as $set) {
                $sent_ts = strtotime((string) $set->clientSentAt);
                if (!$sent_ts) {
                    continue;
                }
                $days = (int) floor(($now - $sent_ts) / DAY_IN_SECONDS);
                if ($days < 1) {
                    continue;
                }

                foreach ($user_rules as $rule) {
                    $conditions = json_decode((string) $rule->conditions, true) ?: array();
                    $min_days   = (int) ($conditions['minDays'] ?? 0);
                    if (!self::pending_should_fire($days, $min_days)) {
                        continue;
                    }

                    // Brand-pinned rules only fire for their brand.
                    if ($rule->brandId !== null
                        && (int) $set->brandId !== (int) $rule->brandId
                    ) {
                        continue;
                    }

                    $context = array(
                        'setId'         => (int) $set->id,
                        'name'          => (string) $set->name,
                        'status'        => (string) $set->status,
                        'token'         => (string) $set->token,
                        'link'          => PCM_Approvals_Service::build_share_url((string) $set->token),
                        'daysSinceSent' => $days,
                        'clientEmail'   => (string) ($set->clientEmail ?? ''),
                        'brandId'       => $set->brandId !== null ? (int) $set->brandId : null,
                    );

                    self::fire_trigger(
                        'approvals.set_pending_in_client',
                        $context,
                        (int) $user_id,
                        array(
                            'ruleId'         => (int) $rule->id,
                            'dedupeKey'      => self::pending_dedupe_key(
                                (int) $rule->id,
                                (int) $set->id,
                                self::pending_cycle_day($days, $min_days)
                            ),
                            'skipConditions' => true, // minDays is parameter, not filter
                        )
                    );
                }
            }
        }
    }
}

// wp-cron callback for async (long-running) cross-module actions scheduled by
// PCM_Automation_Engine::run_action(). Registered at file load so the hook exists
// when wp-cron fires the event. Only the (sync) webhook runs today, so this is the
// dormant async seam for future module actions.
if (function_exists('add_action')) {
    add_action('pcm_automation_run_action', array('PCM_Automation_Engine', 'run_scheduled_action'));
    // Daily reminder scanner for the pending-client trigger. Scheduling lives
    // in pcm_init() (power-creatives.php) so it runs once after plugin load.
    add_action('pcm_automation_check_pending_approvals', array('PCM_Automation_Engine', 'run_pending_client_scan'));
}
