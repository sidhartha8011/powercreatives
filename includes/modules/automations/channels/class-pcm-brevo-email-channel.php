<?php
/**
 * Brevo Email Channel
 *
 * Sends transactional email via the Brevo (Sendinblue) API
 * (POST https://api.brevo.com/v3/smtp/email). The API key is read from the
 * per-user Integrations store (provider 'brevo'); the sender identity comes
 * from the rule config or the global automations settings.
 *
 * The engine renders the event-specific subject + HTML body and places it on
 * `context['email'] = { to, subject, html, replyTo? }`. This channel is pure
 * transport: if the recipient or API key is missing it reports `skipped`.
 *
 * Config shape: { fromEmail?: string, fromName?: string }.
 *
 * @package PowerCreatives
 * @since   1.14.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class PCM_Brevo_Email_Channel implements PCM_Automation_Channel
{
    public const API_URL = 'https://api.brevo.com/v3/smtp/email';

    public function id(): string
    {
        return 'email';
    }

    /**
     * The firing module's configured sender, from Settings → Email senders
     * (module_email_senders: { module => { email, name } }). The module is the
     * event id's prefix ('approvals.set_shared' → 'approvals'). Returns
     * ['email' => '', 'name' => ''] when the event has no module entry.
     *
     * @param string $event Firing event/trigger id (may be '').
     * @return array{email:string,name:string}
     */
    private static function module_sender(string $event): array
    {
        $none   = array('email' => '', 'name' => '');
        $module = strstr($event, '.', true);
        if (!is_string($module) || $module === '') {
            return $none;
        }
        $map = PCM_Settings::get('module_email_senders', array());
        if (!is_array($map) || empty($map[$module]) || !is_array($map[$module])) {
            return $none;
        }
        $email = sanitize_email((string) ($map[$module]['email'] ?? ''));
        if ($email === '' || !is_email($email)) {
            return $none;
        }
        return array(
            'email' => $email,
            'name'  => sanitize_text_field((string) ($map[$module]['name'] ?? '')),
        );
    }

    public function send(array $config, array $context, int $user_id): array
    {
        $email = isset($context['email']) && is_array($context['email']) ? $context['email'] : array();
        $to    = isset($email['to']) ? sanitize_email((string) $email['to']) : '';

        // No recipient or no rendered content → nothing to send (not an error).
        if ($to === '' || !is_email($to) || empty($email['subject']) || empty($email['html'])) {
            return array('ok' => false, 'code' => 0, 'target' => $to, 'error' => null, 'skipped' => true);
        }

        $api_key = self::get_brevo_key($user_id);
        if ($api_key === '') {
            return array(
                'ok'      => false,
                'code'    => 0,
                'target'  => $to,
                'error'   => 'No Brevo API key configured for this user (add a Brevo integration).',
                'skipped' => false,
            );
        }

        // Sender resolution: rule-level override → the firing MODULE's own sender
        // (Settings → Email senders; module = the event id's prefix, e.g. 'approvals.…')
        // → the global default sender.
        $module_sender = self::module_sender((string) ($context['event'] ?? ''));
        $from_email = isset($config['fromEmail']) && $config['fromEmail'] !== ''
            ? sanitize_email((string) $config['fromEmail'])
            : ($module_sender['email'] !== ''
                ? $module_sender['email']
                : sanitize_email((string) PCM_Settings::get('automations_from_email', '')));
        $from_name = isset($config['fromName']) && $config['fromName'] !== ''
            ? sanitize_text_field((string) $config['fromName'])
            : ($module_sender['name'] !== ''
                ? $module_sender['name']
                : sanitize_text_field((string) PCM_Settings::get('automations_from_name', 'Power Creatives')));

        if ($from_email === '' || !is_email($from_email)) {
            return array(
                'ok'      => false,
                'code'    => 0,
                'target'  => $to,
                'error'   => 'No valid from-email configured (set a sender in Settings → Email senders).',
                'skipped' => false,
            );
        }

        $payload = array(
            'sender'      => array('email' => $from_email, 'name' => $from_name),
            'to'          => array(array('email' => $to)),
            'subject'     => (string) $email['subject'],
            'htmlContent' => (string) $email['html'],
        );
        if (!empty($email['replyTo']) && is_email($email['replyTo'])) {
            $payload['replyTo'] = array('email' => sanitize_email((string) $email['replyTo']));
        }

        $response = wp_remote_post(self::API_URL, array(
            'headers' => array(
                'api-key'      => $api_key,
                'Content-Type' => 'application/json',
                'Accept'       => 'application/json',
            ),
            'body'    => wp_json_encode($payload),
            'timeout' => 20,
        ));

        if (is_wp_error($response)) {
            return array(
                'ok'      => false,
                'code'    => 0,
                'target'  => $to,
                'error'   => $response->get_error_message(),
                'skipped' => false,
            );
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $ok   = ($code >= 200 && $code < 300);

        return array(
            'ok'      => $ok,
            'code'    => $code,
            'target'  => $to,
            'error'   => $ok ? null : "Brevo API error (HTTP {$code})",
            'skipped' => false,
        );
    }

    /**
     * Look up the active Brevo API key for a user from the Integrations table.
     * Mirrors PCM_REST_Base::get_provider_api_key() but returns '' instead of
     * throwing, since a missing key is a handled (logged) condition here.
     *
     * @param int $user_id PCM user id.
     * @return string API key or '' when none is configured.
     */
    private static function get_brevo_key(int $user_id): string
    {
        // Workspace-scoped (own key, else an admin's) — see PCM_Access::workspace_api_key.
        $key = class_exists('PCM_Access')
            ? PCM_Access::workspace_api_key('brevo', $user_id)
            : null;

        return is_string($key) ? $key : '';
    }
}
