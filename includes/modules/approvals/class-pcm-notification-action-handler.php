<?php
/**
 * Notification Action Handler (notifications.create)
 *
 * The "THEN" side of the seeded approval-flow notification rules: writes an
 * in-app notification row when a set receives a comment or an approval. One
 * row per EVENT — visibility (owner / granted brand / admin) is computed at
 * read time by the notifications module, so no per-recipient fan-out.
 *
 * Runs as a user-editable automation rule (disable/delete in Automations to
 * turn notifications off per trigger).
 *
 * Mode: sync (single INSERT).
 *
 * @package PowerCreatives
 * @since   1.19.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class PCM_Notification_Action_Handler implements PCM_Automation_Action_Handler
{
    public function id(): string
    {
        return 'notifications.create';
    }

    public function mode(): string
    {
        return 'sync';
    }

    public function run(array $config, array $inputs, array $context, int $user_id): array
    {
        global $wpdb;

        $set_id = isset($context['setId']) ? absint($context['setId']) : 0;
        $base   = array('ok' => false, 'code' => 0, 'target' => 'notification', 'error' => null, 'skipped' => false);

        if ($set_id <= 0 || $user_id <= 0) {
            $base['skipped'] = true;
            $base['error']   = __('Missing set or user in trigger context.', 'power-creatives');
            return $base;
        }

        $is_comment = isset($context['commentId']);
        $set_name   = sanitize_text_field((string) ($context['name'] ?? ''));
        $author     = sanitize_text_field((string) ($context['author'] ?? ''));

        if ($is_comment) {
            $title = sprintf(
                /* translators: 1: comment author, 2: approval set name */
                __('%1$s commented on %2$s', 'power-creatives'),
                $author !== '' ? $author : __('Someone', 'power-creatives'),
                $set_name
            );
            $excerpt = sanitize_textarea_field((string) ($context['body'] ?? ''));
            if (function_exists('mb_substr')) {
                $excerpt = mb_substr($excerpt, 0, 200);
            } else {
                $excerpt = substr($excerpt, 0, 200);
            }
            $type = 'comment';
        } else {
            $asset = sanitize_text_field((string) ($context['assetId'] ?? ''));
            $title = $asset === 'all'
                ? sprintf(/* translators: %s: approval set name */ __('All assets approved in %s', 'power-creatives'), $set_name)
                : sprintf(/* translators: %s: approval set name */ __('Asset approved in %s', 'power-creatives'), $set_name);
            $excerpt = '';
            $type    = 'approval';
        }

        // Deep link: comment events anchor the asset thread on the public board.
        $link = (string) ($context['commentUrl'] ?? ($context['link'] ?? ''));

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $ok = $wpdb->insert(
            PCM_Schema::table('notifications'),
            array(
                'ownerId'   => $user_id,
                'brandId'   => !empty($context['brandId']) ? absint($context['brandId']) : null,
                'setId'     => $set_id,
                'type'      => $type,
                'title'     => $title,
                'excerpt'   => $excerpt,
                'link'      => esc_url_raw($link),
                'createdAt' => current_time('mysql'),
            )
        );

        if ($ok) {
            $base['ok'] = true;
        } else {
            $base['error'] = __('Failed to store notification.', 'power-creatives');
        }
        return $base;
    }
}
