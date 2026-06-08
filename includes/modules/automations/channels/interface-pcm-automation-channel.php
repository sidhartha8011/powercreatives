<?php
/**
 * Automation Channel Interface
 *
 * A channel turns a dispatched event into a concrete outbound action
 * (HTTP webhook, transactional email, …). The engine resolves rules to
 * channels and calls send() on each. Channels are pure I/O — they never
 * touch the database; logging is the engine's responsibility.
 *
 * @package PowerCreatives
 * @since   1.14.0
 */

if (!defined('ABSPATH')) {
    exit;
}

interface PCM_Automation_Channel
{
    /**
     * Channel identifier as stored in wp_pcm_automations.channel
     * (e.g. 'webhook', 'email').
     *
     * @return string
     */
    public function id(): string;

    /**
     * Perform the outbound action for one rule + event context.
     *
     * Implementations MUST NOT throw; any failure is returned in the result
     * array so the engine can log it uniformly.
     *
     * @param array $config  Decoded rule config (channel-specific shape).
     * @param array $context Event context (see PCM_Automation_Events).
     * @param int   $user_id Owning PCM user id (for credential lookup).
     * @return array{
     *     ok: bool,
     *     code: int,          // HTTP status (0 when no request was made)
     *     target: string,     // where it was sent (URL / email), for the log
     *     error: ?string,
     *     skipped: bool        // true when config was incomplete → not an error
     * }
     */
    public function send(array $config, array $context, int $user_id): array;
}
