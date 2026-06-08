<?php
/**
 * Automation Action Handler Interface
 *
 * An action handler executes the "THEN" side of an automation rule. It mirrors
 * the channel interface (PCM_Automation_Channel) but is the extensibility seam
 * for CROSS-MODULE actions: any module can register a handler so the engine can
 * run it (e.g. webhook today; image.generate / approvals.create_set later).
 *
 * The engine resolves a rule's inputMapping against the trigger context to build
 * the $inputs, then calls run(). Handlers are pure executors — the engine owns
 * rule resolution, condition matching, logging, and async scheduling.
 *
 * @package PowerCreatives
 * @since   1.16.0
 */

if (!defined('ABSPATH')) {
    exit;
}

interface PCM_Automation_Action_Handler
{
    /**
     * The action id this handler executes (must match a registered action id,
     * e.g. 'webhook').
     *
     * @return string
     */
    public function id(): string;

    /**
     * Execution mode:
     *  - 'sync'  : run inline during the trigger request (fast, fire-and-forget).
     *  - 'async' : the engine schedules it via wp-cron so it never blocks the
     *              triggering request (for long-running cross-module actions like
     *              image/video/article generation).
     *
     * @return string 'sync'|'async'
     */
    public function mode(): string;

    /**
     * Execute the action.
     *
     * Implementations MUST NOT throw; return the result so the engine can log it
     * uniformly.
     *
     * @param array $config  The rule's action config (e.g. { url, secret }).
     * @param array $inputs  Inputs built from the rule's inputMapping resolved
     *                       against the trigger context (action-specific shape).
     * @param array $context The raw trigger context (for handlers that prefer it).
     * @param int   $user_id Owning PCM user id.
     * @return array{
     *     ok: bool,
     *     code: int,          // HTTP status (0 when no request was made)
     *     target: string,     // where it went (URL / email / resource id), for the log
     *     error: ?string,
     *     skipped: bool        // true when config/inputs were incomplete (not an error)
     * }
     */
    public function run(array $config, array $inputs, array $context, int $user_id): array;
}
