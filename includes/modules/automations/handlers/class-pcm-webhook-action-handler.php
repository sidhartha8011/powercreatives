<?php
/**
 * Webhook Action Handler
 *
 * The built-in, cross-module action: POST the action inputs to a URL (HMAC-signed
 * if a secret is set). Wraps the existing PCM_Webhook_Channel so the signing /
 * transport logic stays in one place.
 *
 * Payload:
 *  - If the rule defines an inputMapping, the resolved inputs become the payload
 *    (so any trigger's context can be shaped into the body).
 *  - Otherwise the default payload is { event, name, link, status, setId } — which
 *    keeps the one live rule (Approvals → Launch) working with zero config.
 *
 * Mode: sync (fire-and-forget; the channel posts non-blocking).
 *
 * @package PowerCreatives
 * @since   1.16.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class PCM_Webhook_Action_Handler implements PCM_Automation_Action_Handler
{
    public function id(): string
    {
        return 'webhook';
    }

    public function mode(): string
    {
        return 'sync';
    }

    public function run(array $config, array $inputs, array $context, int $user_id): array
    {
        $event = (string) ($context['event'] ?? 'automation');

        if (!empty($inputs)) {
            // Explicit mapping → use the resolved inputs as the payload body.
            $payload = array_merge(array('event' => $event), $inputs);
        } else {
            // Default payload — the set's name + link (per spec) plus a small envelope.
            $payload = array(
                'event'  => $event,
                'name'   => (string) ($context['name'] ?? ''),
                'link'   => (string) ($context['link'] ?? ''),
                'status' => (string) ($context['status'] ?? ''),
                'setId'  => isset($context['setId']) ? (int) $context['setId'] : null,
            );
        }

        // Reuse the signed, non-blocking webhook channel for transport.
        return (new PCM_Webhook_Channel())->send($config, $payload, $user_id);
    }
}
