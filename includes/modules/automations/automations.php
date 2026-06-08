<?php
/**
 * Automations — core registration
 *
 * Auto-loaded by PCM_Module_Loader::discover(). Registers the built-in,
 * functional 'webhook' action (its executor PCM_Webhook_Action_Handler is
 * registered by the engine), plus a small set of INERT "coming soon" triggers
 * and actions from other modules so the cross-module catalog is visible in the
 * builder. Inert entries (`implemented => false`) have no emitter/handler — they
 * render disabled and, if somehow targeted, log as 'skipped'.
 *
 * As each module is wired for real, its trigger/action moves into that module's
 * own includes/modules/{id}/automations.php and flips to `implemented => true`.
 *
 * @package PowerCreatives
 * @since   1.16.0
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('PCM_Automation_Actions') || !class_exists('PCM_Automation_Triggers')) {
    return;
}

// ── Functional action: webhook ────────────────────────────────────────────────
PCM_Automation_Actions::register(array(
    'id'          => 'webhook',
    'module'      => 'core',
    'implemented' => true,
    'label'       => __('Send a webhook', 'power-creatives'),
    'description' => __('POST the payload to a URL, HMAC-signed if a secret is set.', 'power-creatives'),
    'configFields' => array(
        array('key' => 'url',    'label' => __('Webhook URL', 'power-creatives'),            'type' => 'url',  'required' => true,  'placeholder' => 'https://hooks.example.com/…'),
        array('key' => 'secret', 'label' => __('Signing secret (optional)', 'power-creatives'), 'type' => 'text', 'required' => false, 'placeholder' => __('Used to HMAC-sign the payload', 'power-creatives')),
    ),
    // Inputs the action can build from the trigger context via inputMapping.
    // Empty mapping → the handler's default payload (name + link).
    'inputSchema' => array(
        array('key' => 'name', 'label' => __('Name', 'power-creatives')),
        array('key' => 'link', 'label' => __('Link', 'power-creatives')),
    ),
));

// ── Functional action: email.send (Brevo) ────────────────────────────────────
if (class_exists('PCM_Email_Action_Handler')) {
    PCM_Automation_Engine::register_action_handler(new PCM_Email_Action_Handler());
}
PCM_Automation_Actions::register(array(
    'id'          => 'email.send',
    'module'      => 'core',
    'implemented' => true,
    'label'       => __('Send an email', 'power-creatives'),
    'description' => __('Send a transactional email via your Brevo integration.', 'power-creatives'),
    'configFields' => array(
        array('key' => 'fromEmail', 'label' => __('From email (optional)', 'power-creatives'), 'type' => 'text', 'required' => false, 'placeholder' => __('Defaults to your Settings sender', 'power-creatives')),
        array('key' => 'fromName',  'label' => __('From name (optional)', 'power-creatives'),  'type' => 'text', 'required' => false),
    ),
    'inputSchema' => array(
        array('key' => 'to',      'label' => __('To', 'power-creatives')),
        array('key' => 'subject', 'label' => __('Subject', 'power-creatives')),
        array('key' => 'message', 'label' => __('Message', 'power-creatives')),
    ),
));

// ── Inert "coming soon" ACTIONS (cross-module demo; not yet implemented) ──────
$pcm_soon_actions = array(
    array('id' => 'approvals.create_set', 'module' => 'approvals', 'label' => __('Create an approval set', 'power-creatives'),  'description' => __('Bundle assets into a new approval set.', 'power-creatives')),
    array('id' => 'image.generate',       'module' => 'image',     'label' => __('Generate an image', 'power-creatives'),       'description' => __('Generate an image from a prompt.', 'power-creatives')),
    array('id' => 'copy.generate',        'module' => 'copy',      'label' => __('Generate ad copy', 'power-creatives'),        'description' => __('Generate ad copy for a brand/audience.', 'power-creatives')),
    array('id' => 'writer.generate',      'module' => 'writer',    'label' => __('Write an article', 'power-creatives'),        'description' => __('Generate an SEO article.', 'power-creatives')),
);
foreach ($pcm_soon_actions as $a) {
    PCM_Automation_Actions::register(array_merge($a, array('implemented' => false, 'configFields' => array(), 'inputSchema' => array())));
}

// ── Inert "coming soon" TRIGGERS (cross-module demo; not yet emitted) ─────────
$pcm_soon_triggers = array(
    array('id' => 'copy.generation_completed',  'module' => 'copy',   'label' => __('Ad copy generated', 'power-creatives'),   'description' => __('Fires when a copy generation job completes.', 'power-creatives'),   'contextKeys' => array('resultId', 'brandId')),
    array('id' => 'image.generation_completed', 'module' => 'image',  'label' => __('Image generated', 'power-creatives'),     'description' => __('Fires when an image finishes generating.', 'power-creatives'),       'contextKeys' => array('assetId', 'url', 'brandId')),
    array('id' => 'writer.article_generated',   'module' => 'writer', 'label' => __('Article generated', 'power-creatives'),   'description' => __('Fires when an article is generated.', 'power-creatives'),            'contextKeys' => array('articleId', 'title', 'brandId')),
);
foreach ($pcm_soon_triggers as $t) {
    PCM_Automation_Triggers::register(array_merge($t, array('implemented' => false, 'conditionFields' => array())));
}
