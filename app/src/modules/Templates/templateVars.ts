/**
 * templateVars — the {{ variable }} vocabulary each Templates module supports.
 *
 * Single source for the "/" typeahead in the Value editors. Every token here is
 * one a server-side resolver actually substitutes; offering anything else would
 * paste text that silently survives into the prompt sent to the model.
 *
 * WHERE EACH LIST COMES FROM (all verified against the PHP, not the docs):
 *
 *   writer    → PCM_Strategy_Service::render_template_vars() / build_prompt().
 *               Documented lowercase + whitespace-tolerant (service.php:3347),
 *               so `{{ post_title }}` == `{{post_title}}`. Spaced form kept here
 *               because that is how the module's own prompts are written.
 *
 *   seo       → PCM_SEO_AI::build_field_vars() + `current_value`, which the
 *               caller adds before substitute_vars() runs.
 *
 *   copy      → PCM_Copy_Service::resolve_prompt_placeholders($tpl, $prompt_vars);
 *               templates live in wp_pcm_templates (module=copy).
 *
 *   image     → PCM_Image_Service::build_image_context() plus `brief`/`style`,
 *               added at the call sites before resolve_prompt_placeholders().
 *
 *   optimizer → PCM_Optimizer_Service::render_prompt_vars() at the single
 *               'compile' resolve site — exactly two tokens.
 *
 *   video     → INTENTIONALLY EMPTY. The video module has no {{ }} substitution
 *               engine at all, so a token typed into a video template stays
 *               literal. No vocabulary is offered rather than a fake one.
 *
 * These resolvers are all LITERAL str_replace('{{' . key . '}}') except the
 * writer one, so every non-writer token is written tight — a spaced token would
 * never resolve.
 */

/** Writer/strategy prompt variables. */
const WRITER_VARS = [
  '{{ post_title }}',
  '{{ post_content }}',
  '{{ post_link }}',
  '{{ keyword }}',
  '{{ brand_context }}',
  '{{ research }}',
  '{{ output_format }}',
  '{{ media_instructions }}',
  '{{ brand_language }}',
];

/** SEO prompt variables — build_field_vars() + current_value. */
const SEO_VARS = [
  '{{title}}',
  '{{current_value}}',
  '{{primary_keyword}}',
  '{{supporting_keyword}}',
  '{{meta_title}}',
  '{{meta_description}}',
  '{{meta_keywords}}',
  '{{post_type}}',
  '{{site.lang}}',
  '{{today}}',
  '{{website.url}}',
  '{{business.name}}',
  '{{business.category}}',
  '{{business.description}}',
  '{{business.tagline}}',
  '{{business.address}}',
  '{{business.phone}}',
  '{{business.hours}}',
  '{{business.rating}}',
  '{{business.types}}',
  '{{business.website}}',
  '{{business.website|hostname}}',
  '{{business.lat}}',
  '{{business.lng}}',
];

/** Copy prompt variables — PCM_Copy_Service $prompt_vars. */
const COPY_VARS = [
  '{{language}}',
  '{{brief}}',
  '{{typeLabel}}',
  '{{angle}}',
  '{{audience}}',
  '{{creativeBrief}}',
  '{{copyFramework}}',
  '{{campaignContext}}',
  '{{organicContext}}',
  '{{referenceCopy}}',
  '{{reviewsContext}}',
  '{{toneInstruction}}',
  '{{ctaInstruction}}',
  '{{emojiInstruction}}',
];

/** Image prompt variables — build_image_context() + call-site additions. */
const IMAGE_VARS = [
  '{{count}}',
  '{{brief}}',
  '{{style}}',
  '{{brandName}}',
  '{{brandSummary}}',
  '{{brandColors}}',
  '{{url}}',
  '{{niche}}',
  '{{location}}',
  '{{phone}}',
  '{{language}}',
  '{{seasonEvent}}',
  '{{campaignTheme}}',
  '{{referenceImageIntent}}',
];

/** Optimizer prompt variables — the 'compile' section's two tokens. */
const OPTIMIZER_VARS = ['{{routing_rules}}', '{{routing_json}}'];

// An allow-list, not a default: a module with no known vocabulary gets nothing
// rather than borrowing another module's, because a token that stays literal is
// worse than no suggestion at all.
function varsFor(module?: string): string[] {
  switch (module) {
    case 'writer': return WRITER_VARS;
    case 'seo': return SEO_VARS;
    case 'copy': return COPY_VARS;
    case 'image': return IMAGE_VARS;
    case 'optimizer': return OPTIMIZER_VARS;
    // 'video' deliberately absent — nothing substitutes there.
    default: return [];
  }
}

/**
 * Variables an entry may use. Only `prompt` entries run through a substituting
 * builder; on any other category the token would stay literal.
 */
export function templateVarsFor(module?: string, category?: string): string[] {
  return category === 'prompt' ? varsFor(module) : [];
}
