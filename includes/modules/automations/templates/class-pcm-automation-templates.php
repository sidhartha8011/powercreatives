<?php
/**
 * Automation Email Templates
 *
 * Renders the small set of transactional emails the Approvals flow sends.
 * Templates are plain PHP that build escaped HTML strings — no external
 * template engine. Every interpolated value is escaped at the point of use.
 *
 * @package PowerCreatives
 * @since   1.14.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class PCM_Automation_Templates
{
    /**
     * Shared responsive layout wrapper. Keeps a clean, professional look with
     * inline styles (required for email clients).
     *
     * @param string $heading   Pre-escaped or plain heading text.
     * @param string $body_html Inner HTML (already escaped by the caller).
     * @param array  $cta       Optional { label, url } primary button.
     * @return string Full HTML document.
     */
    private static function layout(string $heading, string $body_html, array $cta = array()): string
    {
        $brand = esc_html__('Power Creatives', 'power-creatives');
        $button = '';
        if (!empty($cta['url']) && !empty($cta['label'])) {
            $button = sprintf(
                '<tr><td style="padding:8px 0 4px;">'
                . '<a href="%1$s" style="display:inline-block;background:#111827;color:#ffffff;'
                . 'text-decoration:none;padding:12px 22px;border-radius:8px;font-weight:600;'
                . 'font-size:14px;">%2$s</a></td></tr>',
                esc_url($cta['url']),
                esc_html($cta['label'])
            );
        }

        return '<!DOCTYPE html><html><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width,initial-scale=1"></head>'
            . '<body style="margin:0;padding:0;background:#f3f4f6;font-family:-apple-system,'
            . 'BlinkMacSystemFont,\'Segoe UI\',Roboto,Helvetica,Arial,sans-serif;color:#111827;">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" '
            . 'style="background:#f3f4f6;padding:32px 0;"><tr><td align="center">'
            . '<table role="presentation" width="560" cellpadding="0" cellspacing="0" '
            . 'style="background:#ffffff;border-radius:14px;overflow:hidden;'
            . 'box-shadow:0 1px 3px rgba(0,0,0,0.08);max-width:560px;width:100%;">'
            . '<tr><td style="padding:28px 32px 0;font-size:13px;font-weight:700;'
            . 'letter-spacing:0.04em;text-transform:uppercase;color:#6b7280;">' . $brand . '</td></tr>'
            . '<tr><td style="padding:12px 32px 0;font-size:20px;font-weight:700;line-height:1.3;">'
            . esc_html($heading) . '</td></tr>'
            . '<tr><td style="padding:14px 32px 4px;font-size:15px;line-height:1.6;color:#374151;">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0">'
            . '<tr><td>' . $body_html . '</td></tr>' . $button . '</table></td></tr>'
            . '<tr><td style="padding:24px 32px 28px;font-size:12px;color:#9ca3af;'
            . 'border-top:1px solid #f3f4f6;">'
            . esc_html__('Sent by Power Creatives on behalf of your team.', 'power-creatives')
            . '</td></tr></table></td></tr></table></body></html>';
    }

    /**
     * Client invite email — sent when a set is shared for review.
     *
     * @param array $vars { brandName, setName, shareUrl, clientName?, customMessage? }
     * @return array{ subject: string, html: string }
     */
    public static function client_invite(array $vars): array
    {
        $brand_name = (string) ($vars['brandName'] ?? '');
        $set_name   = (string) ($vars['setName'] ?? '');
        $share_url  = (string) ($vars['shareUrl'] ?? '');
        $custom_msg = trim((string) ($vars['customMessage'] ?? ''));
        $greeting   = !empty($vars['clientName'])
            ? sprintf(/* translators: %s: client name */ __('Hi %s,', 'power-creatives'), $vars['clientName'])
            : __('Hi there,', 'power-creatives');

        $subject = $brand_name !== ''
            ? sprintf(/* translators: %s: brand name */ __('Your creatives are ready for review — %s', 'power-creatives'), $brand_name)
            : __('Your creatives are ready for review', 'power-creatives');

        if ($custom_msg !== '') {
            // Sender-authored message from the share dialog. Plain text → escape +
            // preserve line breaks. The review-board CTA button is still appended below.
            $body = '<p style="margin:0 0 12px;">' . nl2br(esc_html($custom_msg)) . '</p>'
                . '<p style="margin:0 0 4px;">'
                . esc_html__('Click below to open your private review board:', 'power-creatives')
                . '</p>';
        } else {
            $body = '<p style="margin:0 0 12px;">' . esc_html($greeting) . '</p>'
                . '<p style="margin:0 0 12px;">'
                . sprintf(
                    /* translators: %s: approval set name */
                    esc_html__('A new set of creatives, %s, is ready for your review. You can approve each item, leave comments, or approve everything in one click.', 'power-creatives'),
                    '<strong>' . esc_html($set_name) . '</strong>'
                )
                . '</p>'
                . '<p style="margin:0 0 4px;">'
                . esc_html__('Click below to open your private review board:', 'power-creatives')
                . '</p>';
        }

        return array(
            'subject' => $subject,
            'html'    => self::layout(
                __('Creatives ready for your review', 'power-creatives'),
                $body,
                array('label' => __('Open review board', 'power-creatives'), 'url' => $share_url)
            ),
        );
    }

    /**
     * Team reply email — sent to the client when a team member replies in a thread.
     *
     * @param array $vars { brandName, setName, author, body, shareUrl, assetUrl? }
     * @return array{ subject: string, html: string }
     */
    public static function team_reply(array $vars): array
    {
        $set_name = (string) ($vars['setName'] ?? '');
        $author   = (string) ($vars['author'] ?? __('The team', 'power-creatives'));
        $reply    = (string) ($vars['body'] ?? '');
        $link     = !empty($vars['assetUrl']) ? (string) $vars['assetUrl'] : (string) ($vars['shareUrl'] ?? '');

        $subject = $set_name !== ''
            ? sprintf(/* translators: %s: approval set name */ __('New reply on your review — %s', 'power-creatives'), $set_name)
            : __('New reply on your review', 'power-creatives');

        $body = '<p style="margin:0 0 12px;">'
            . sprintf(
                /* translators: %s: team member name */
                esc_html__('%s replied to your comment:', 'power-creatives'),
                '<strong>' . esc_html($author) . '</strong>'
            )
            . '</p>'
            . '<blockquote style="margin:0 0 14px;padding:12px 16px;background:#f9fafb;'
            . 'border-left:3px solid #d1d5db;border-radius:6px;color:#374151;">'
            . nl2br(esc_html($reply))
            . '</blockquote>'
            . '<p style="margin:0 0 4px;">'
            . esc_html__('Open the board to continue the conversation:', 'power-creatives')
            . '</p>';

        return array(
            'subject' => $subject,
            'html'    => self::layout(
                __('You have a new reply', 'power-creatives'),
                $body,
                array('label' => __('View & reply', 'power-creatives'), 'url' => $link)
            ),
        );
    }
}
