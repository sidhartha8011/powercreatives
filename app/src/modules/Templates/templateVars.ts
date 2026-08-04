/**
 * templateVars — the {{ variable }} vocabulary each Templates module supports.
 *
 * Single source for the "/" typeahead in the Value editors. Every token here is
 * one a server-side resolver actually substitutes; offering anything else would
 * paste text that silently survives into the prompt sent to the model.
 *
 * WHERE EACH LIST COMES FROM (all read out of the PHP, not the docs). Most
 * modules substitute at SEVERAL call sites with different maps, so each list is
 * the UNION across all of them — an earlier version captured only one site per
 * module and hid 34 real variables.
 *
 *   writer    → PCM_Strategy_Service::source_vars() (the post_* trio)
 *               + build_prompt()'s fragment map
 *               + build_image_prompt()'s {{ title }}, for a writer template
 *                 reused as a strategy's image prompt.
 *               Documented lowercase + whitespace-tolerant (service.php:3347), so
 *               `{{ post_title }}` == `{{post_title}}`. Spaced form kept here
 *               because that is how the module's own prompts are written.
 *
 *   seo       → PCM_SEO_AI::build_field_vars() + `current_value` (added by the
 *               caller before substitute_vars() runs), plus every token the
 *               section prompts in seo/prompts.php and seo/local.php resolve —
 *               the local/GBP page prompts carry their own extra set.
 *
 *   copy      → every key passed to PCM_Copy_Service::resolve_prompt_placeholders()
 *               across its prompt-building sites (generation, research, angles,
 *               audiences, reference ads).
 *
 *   image     → PCM_Image_Service::build_image_context() plus `brief`/`style`,
 *               added at the call sites before resolve_prompt_placeholders().
 *
 *   optimizer → PCM_Optimizer_Service::render_prompt_vars() at the 'compile' site
 *               plus the interlink and mention teachers.
 *
 *   video     → INTENTIONALLY EMPTY. The video module has no {{ }} substitution
 *               engine at all, so a token typed into a video template stays
 *               literal. No vocabulary is offered rather than a fake one.
 *
 * Tokens appearing in the PHP only inside DOCBLOCKS that describe the mechanism
 * ({{key}}, {{placeholder}}, {{placeholders}}, {{var}}, {{variable}}) are not
 * variables and are excluded. So is seo's {{primary_kw}} — a comment there records
 * it as a typo already corrected to {{primary_keyword}}.
 *
 * Every resolver is a LITERAL str_replace('{{' . key . '}}') except the writer
 * one, so every non-writer token is written tight; a spaced token would never
 * resolve.
 */

/** Writer/strategy prompt variables. */
const WRITER_VARS = [
  '{{ post_title }}',
  '{{ post_content }}',
  '{{ post_link }}',
  '{{ keyword }}',
  '{{ title }}',
  '{{ brand_context }}',
  '{{ research }}',
  '{{ output_format }}',
  '{{ media_instructions }}',
  '{{ brand_language }}',
];

/** SEO prompt variables. */
const SEO_VARS = [
  '{{title}}',
  '{{current_value}}',
  '{{primary_keyword}}',
  '{{supporting_keyword}}',
  '{{meta_title}}',
  '{{meta_description}}',
  '{{meta_keywords}}',
  '{{post_type}}',
  '{{page.type}}',
  '{{site.lang}}',
  '{{site_name}}',
  '{{website.url}}',
  '{{today}}',
  '{{topic}}',
  '{{name}}',
  '{{why}}',
  '{{facts}}',
  '{{output_format}}',
  '{{key_pages}}',
  '{{corpus}}',
  '{{corpus_note}}',
  '{{keywords_bullet}}',
  '{{strengths_bullet}}',
  '{{years_bullet}}',
  '{{area_bullet}}',
  '{{area_section}}',
  '{{area_serves_clause}}',
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

/** Copy prompt variables. */
const COPY_VARS = [
  '{{language}}',
  '{{typeLabel}}',
  '{{brief}}',
  '{{creativeBrief}}',
  '{{campaignContext}}',
  '{{organicContext}}',
  '{{researchContext}}',
  '{{reviewsContext}}',
  '{{referenceCopy}}',
  '{{referenceAds}}',
  '{{copyFramework}}',
  '{{angle}}',
  '{{anglesPerAudience}}',
  '{{audience}}',
  '{{audiences}}',
  '{{tone}}',
  '{{toneInstruction}}',
  '{{ctaInstruction}}',
  '{{emojiInstruction}}',
  '{{brandName}}',
  '{{product}}',
  '{{description}}',
  '{{count}}',
];

/** Image prompt variables. */
const IMAGE_VARS = [
  '{{brief}}',
  '{{style}}',
  '{{count}}',
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

/** Optimizer prompt variables. */
const OPTIMIZER_VARS = [
  '{{routing_rules}}',
  '{{routing_json}}',
  '{{topic_rule}}',
  '{{primary_keyword}}',
  '{{questions}}',
  '{{ranksfor_note}}',
  '{{gsc_preference_note}}',
  '{{business.name}}',
  '{{business.category}}',
  '{{business.address}}',
];

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
