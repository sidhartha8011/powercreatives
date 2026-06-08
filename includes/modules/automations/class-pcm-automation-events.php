<?php
/**
 * Automation Events Catalog
 *
 * Canonical list of events the Automations engine can dispatch. Triggers
 * (currently the Approvals module) reference these constants instead of raw
 * strings so that event names stay consistent across PHP, the rules table,
 * and outbound payloads.
 *
 * Each event carries a context array whose shape is documented below. Channels
 * read from the context to build their payloads.
 *
 * @package PowerCreatives
 * @since   1.14.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class PCM_Automation_Events
{
    /**
     * An approval set was shared with a client (invite sent).
     * Context: setId, setName, token, clientEmail, clientName?, shareUrl, brandId?.
     */
    public const APPROVAL_SET_SHARED = 'approval.set.shared';

    /**
     * A client posted a comment on an asset in an approval set.
     * Context: setId, setName, token, assetId, commentId, author, body,
     *          shareUrl, assetUrl, brandId?.
     */
    public const APPROVAL_COMMENT_CREATED = 'approval.comment.created';

    /**
     * A team member replied to a client comment thread.
     * Context: setId, setName, token, assetId, commentId, author, body,
     *          clientEmail, shareUrl, assetUrl, brandId?.
     */
    public const APPROVAL_COMMENT_TEAM_REPLY = 'approval.comment.team_reply';

    /**
     * Every asset in an approval set has been approved by the client; the set
     * has auto-advanced to 'launch'. Fires at most once per set.
     * Context: setId, setName, token, approvedCounts, shareUrl, brandId?.
     */
    public const APPROVAL_ALL_APPROVED = 'approval.all_approved';

    /**
     * Legacy: the client submitted the full review (single submit button).
     * Preserved for backwards compatibility with existing webhook consumers.
     * Context: setId, setName, token, clientName, feedback, brandId?.
     */
    public const APPROVAL_COMPLETED = 'approval.completed';

    /**
     * All known event identifiers.
     *
     * @return string[]
     */
    public static function all(): array
    {
        return array(
            self::APPROVAL_SET_SHARED,
            self::APPROVAL_COMMENT_CREATED,
            self::APPROVAL_COMMENT_TEAM_REPLY,
            self::APPROVAL_ALL_APPROVED,
            self::APPROVAL_COMPLETED,
        );
    }

    /**
     * Whether a given string is a recognised event.
     *
     * @param string $event Event identifier.
     * @return bool
     */
    public static function is_valid(string $event): bool
    {
        return in_array($event, self::all(), true);
    }
}
