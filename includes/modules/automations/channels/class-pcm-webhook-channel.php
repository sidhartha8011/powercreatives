<?php
/**
 * Webhook Channel
 *
 * Sends the event context as a JSON POST to a configured URL. When a signing
 * secret is available the body is HMAC-SHA256 signed and the digest is sent in
 * the `X-PCM-Signature` header (format: `sha256=<hex>`), so the receiver can
 * verify authenticity.
 *
 * Config shape: { url: string, secret?: string, blocking?: bool }.
 * When `secret` is omitted, the global `automations_webhook_secret` setting is
 * used. Dispatch is non-blocking by default (fire-and-forget) to keep the
 * triggering request fast; the test endpoint opts into blocking mode.
 *
 * @package PowerCreatives
 * @since   1.14.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class PCM_Webhook_Channel implements PCM_Automation_Channel
{
    public function id(): string
    {
        return 'webhook';
    }

    /**
     * Build the signed JSON body for a payload. Exposed as a static helper so
     * the signature logic can be unit-tested in isolation.
     *
     * @param array  $payload Outbound payload.
     * @param string $secret  HMAC secret ('' to skip signing).
     * @return array{ body: string, signature: ?string }
     */
    public static function build_signed_body(array $payload, string $secret): array
    {
        $body = wp_json_encode($payload);
        $signature = ($secret !== '')
            ? 'sha256=' . hash_hmac('sha256', $body, $secret)
            : null;

        return array('body' => $body, 'signature' => $signature);
    }

    public function send(array $config, array $context, int $user_id): array
    {
        $url = isset($config['url']) ? esc_url_raw((string) $config['url']) : '';

        if ($url === '') {
            return array('ok' => false, 'code' => 0, 'target' => '', 'error' => null, 'skipped' => true);
        }

        $secret = isset($config['secret']) && $config['secret'] !== ''
            ? (string) $config['secret']
            : (string) PCM_Settings::get('automations_webhook_secret', '');

        $payload = array_merge(
            array('event' => $context['event'] ?? ''),
            $context,
            array('timestamp' => current_time('c'))
        );

        $signed = self::build_signed_body($payload, $secret);

        $headers = array(
            'Content-Type' => 'application/json',
            'X-PCM-Event'  => (string) ($context['event'] ?? ''),
        );
        if ($signed['signature'] !== null) {
            $headers['X-PCM-Signature'] = $signed['signature'];
        }

        $blocking = !empty($config['blocking']);

        $response = wp_remote_post($url, array(
            'headers'     => $headers,
            'body'        => $signed['body'],
            'timeout'     => $blocking ? 15 : 5,
            'redirection' => 5,
            'blocking'    => $blocking,
        ));

        // Fire-and-forget: we cannot know the status code, so treat dispatch as ok.
        if (!$blocking) {
            return array('ok' => true, 'code' => 0, 'target' => $url, 'error' => null, 'skipped' => false);
        }

        if (is_wp_error($response)) {
            return array(
                'ok'      => false,
                'code'    => 0,
                'target'  => $url,
                'error'   => $response->get_error_message(),
                'skipped' => false,
            );
        }

        $code = (int) wp_remote_retrieve_response_code($response);

        return array(
            'ok'      => ($code >= 200 && $code < 300),
            'code'    => $code,
            'target'  => $url,
            'error'   => ($code >= 200 && $code < 300) ? null : "HTTP {$code}",
            'skipped' => false,
        );
    }
}
