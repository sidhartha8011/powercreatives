<?php
/**
 * Email Action Handler
 *
 * Cross-module action: send a transactional email via the existing
 * PCM_Brevo_Email_Channel. Any trigger can use it (e.g. Approvals → Launch →
 * email the client, or Brand created → email the team).
 *
 * Inputs (from the rule's inputMapping, resolved against the trigger context):
 *   - to       recipient email. Falls back to {{clientEmail}} in the context so
 *              an Approvals trigger auto-targets the client when `to` is unset.
 *   - subject  email subject (defaults to a generic line).
 *   - message  plain text body (wrapped in minimal HTML, escaped).
 *
 * Config: { fromEmail?, fromName? } — falls back to the automations_from_email /
 * automations_from_name settings (handled by the Brevo channel).
 *
 * Mode: sync.
 *
 * @package PowerCreatives
 * @since   1.16.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class PCM_Email_Action_Handler implements PCM_Automation_Action_Handler
{
    public function id(): string
    {
        return 'email.send';
    }

    public function mode(): string
    {
        return 'sync';
    }

    public function run(array $config, array $inputs, array $context, int $user_id): array
    {
        // Recipient: explicit mapping wins, else the trigger's clientEmail (if any).
        $to = sanitize_email((string) ($inputs['to'] ?? ''));
        if ($to === '' && !empty($context['clientEmail'])) {
            $to = sanitize_email((string) $context['clientEmail']);
        }

        if ($to === '' || !is_email($to)) {
            return array('ok' => false, 'code' => 0, 'target' => $to, 'error' => null, 'skipped' => true);
        }

        $subject = trim((string) ($inputs['subject'] ?? ''));
        if ($subject === '') {
            $subject = __('Notification from Power Creatives', 'power-creatives');
        }

        $message = (string) ($inputs['message'] ?? '');
        $html    = '<p>' . nl2br(esc_html($message)) . '</p>';

        // The Brevo channel reads the rendered email off the context.
        $context['email'] = array(
            'to'      => $to,
            'subject' => $subject,
            'html'    => $html,
        );

        return (new PCM_Brevo_Email_Channel())->send($config, $context, $user_id);
    }
}
