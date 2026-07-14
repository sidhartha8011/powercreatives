<?php
/**
 * Models module background handlers (loaded on EVERY request by the
 * power-creatives.php module glob — wp-cron needs the hook registered
 * outside REST bootstrapping).
 *
 * pcm_models_refresh: the registry's self-refresh — scheduled by
 * PCM_Models_Service::maybe_schedule_refresh whenever a registry read finds
 * a provider's models older than a day. Validates the stored key against
 * the provider's LIVE models API and runs the normal sync.
 *
 * @package PowerCreatives
 */

if (!defined('ABSPATH')) {
    exit;
}

add_action('pcm_models_refresh', static function ($user_id, $provider): void {
    if (!class_exists('PCM_Models_Service')) {
        require_once __DIR__ . '/service.php';
    }
    if (!class_exists('PCM_Providers') || !class_exists('PCM_DB')) {
        error_log('[PCM Models] Background refresh skipped — core classes unavailable in this context.');
        return;
    }
    (new PCM_Models_Service())->run_refresh((int) $user_id, (string) $provider);
}, 10, 2);
