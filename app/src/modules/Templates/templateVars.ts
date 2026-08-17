/**
 * templateVars — the {{ variable }} vocabulary each Templates module supports,
 * with a one-line explanation of what each token resolves to.
 *
 * Single source for the "/" typeahead in the Value editors. Every token here is
 * one a server-side resolver actually substitutes; offering anything else would
 * paste text that silently survives into the prompt sent to the model.
 *
 * Descriptions are written from the PHP — the label a token carries in the
 * default prompt ("Primary Keyword: {{primary_keyword}}") or the expression it is
 * assigned from — not from guesswork. Several are CONDITIONAL: the *_bullet and
 * *_note tokens resolve to a whole ready-made instruction sentence when their
 * source data exists and to nothing when it does not, which is worth knowing
 * before you build a prompt around one.
 *
 * WHERE EACH LIST COMES FROM. Most modules substitute at SEVERAL call sites with
 * different maps, so each list is the UNION across all of them — an earlier
 * version captured only one site per module and hid 34 real variables.
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
 *
 * A token may mean different things in different modules ({{count}}, {{brief}},
 * {{business.name}}), which is why descriptions live per module rather than in one
 * global lookup.
 */

/**
 * Modules whose entries can ONLY be prompts — there is no other category with a
 * meaning there, so the editor auto-sets 'prompt', hides the category picker, and
 * the variable menu is on offer whatever category an OLD row was saved under
 * (the strategy engine normalises writer entries to 'prompt' on read for the same
 * reason). Writer joined video/seo/optimizer here (owner cards 5/13): a writer
 * template created in the dialog used to land under the dropdown's default,
 * 'reference_ad' — no variables offered, and its text ignored at generation.
 */
export const PROMPT_ONLY_MODULES = new Set(['writer', 'video', 'seo', 'optimizer']);
export const isPromptOnlyModule = (module?: string): boolean => PROMPT_ONLY_MODULES.has(String(module ?? ''));

/** A variable on offer, with what it resolves to. */
export interface TemplateVar {
  token: string;
  description: string;
}

/**
 * The SHARED site + business vocabulary — PHP: PCM_Content_Vars::site_business().
 *
 * Writer and SEO both compose this map server-side, so both offer it here. Written
 * TIGHT ({{business.name}}), matching how SEO substitutes; the Writer renderer is
 * whitespace-tolerant so the same spelling resolves in both modules.
 */
const SITE_BUSINESS_VARS: Record<string, string> = {
  '{{site.lang}}': 'Language to write in — the brand’s content language, falling back to the site locale.',
  '{{website.url}}': 'The site’s URL.',
  '{{today}}': 'Today’s date (YYYY-MM-DD).',
  '{{business.name}}': 'Business name — the brand’s Google Business Profile name, else the brand, else the site.',
  '{{business.tagline}}': 'Business tagline.',
  '{{business.website}}': 'Business website URL.',
  '{{business.website|hostname}}': 'Business website as a bare hostname — no scheme, no path.',
  '{{business.address}}': 'Street address.',
  '{{business.phone}}': 'Phone number.',
  '{{business.category}}': 'Primary Google Business Profile category.',
  '{{business.hours}}': 'Opening hours.',
  '{{business.description}}': 'Google Business Profile description.',
  '{{business.rating}}': 'Average review rating.',
  '{{business.lat}}': 'Latitude, for location-aware copy.',
  '{{business.lng}}': 'Longitude, for location-aware copy.',
  '{{business.types}}': 'Full Google Business Profile category list.',
};

/** Writer/strategy prompt variables. */
const WRITER_VARS: Record<string, string> = {
  '{{ post_title }}': 'Title of the source post (RSS or social item). Empty for keyword strategies.',
  '{{ post_content }}': 'Body text of the source post. Using any post_* token replaces the built-in “write about this post” instruction.',
  '{{ post_link }}': 'URL of the source post.',
  '{{ keyword }}': 'The item’s target keyword. Placing it stops the keyword line being auto-appended.',
  '{{ primary_keyword }}': 'The same target keyword under its SEO-template name — placing it also stops the keyword line being auto-appended.',
  '{{ title }}': 'The article title — available when a writer template is reused as a strategy’s image prompt.',
  '{{ brand_context }}': 'The brand block (name, summary, tone, colours) the generator otherwise injects for you.',
  '{{ research }}': 'Research findings gathered for this item.',
  '{{ output_format }}': 'The required output structure. Auto-appended unless you place it yourself.',
  '{{ media_instructions }}': 'Image placeholder rules, including how many media assets you may insert.',
  '{{ brand_language }}': 'The brand’s content language (Brands → Language). Empty when none is set.',
  // The site + business half — the card: "writer templates should share the
  // variables that it can have coming from the site and the business… just like
  // the SEO". Resolved by build_prompt() via PCM_Content_Vars.
  ...SITE_BUSINESS_VARS,
};

/** SEO prompt variables. */
const SEO_VARS: Record<string, string> = {
  '{{title}}': 'The page’s current title / H1.',
  '{{current_value}}': 'The existing value of the field being rewritten — set only in “optimize” mode.',
  '{{primary_keyword}}': 'The page’s primary target keyword.',
  '{{supporting_keyword}}': 'The page’s secondary keyword.',
  '{{meta_title}}': 'The page’s current meta title.',
  '{{meta_description}}': 'The page’s current meta description.',
  '{{meta_keywords}}': 'The page’s current meta keywords.',
  '{{post_type}}': 'WordPress post type of the page (post, page, product…).',
  '{{page.type}}': 'Page intent (local, service, blog…) so the copy can match that intent.',
  '{{topic}}': 'The topic instruction for the section being written.',
  '{{name}}': 'The business name, for positioning copy (“What {{name}} does”).',
  '{{why}}': 'One-sentence explanation of the change made, returned with the edit.',
  '{{facts}}': 'Business facts block used to ground the copy in something real.',
  '{{output_format}}': 'The required output structure for this section.',
  '{{key_pages}}': 'List of the site’s key pages.',
  '{{corpus}}': 'Extracted site content used as source material.',
  '{{corpus_note}}': 'Ready-made “base the summary on the site content” instruction. Empty when there is no corpus.',
  '{{keywords_bullet}}': 'Ready-made “weave in the target keywords” bullet. Empty when no keywords are set.',
  '{{strengths_bullet}}': 'Ready-made bullet presenting the business’s strengths. Empty when none are set.',
  '{{years_bullet}}': 'Ready-made bullet framing years in business as proof. Empty when unknown.',
  '{{area_bullet}}': 'Ready-made bullet emphasising local focus. Empty when no service area is set.',
  '{{area_section}}': 'Extra “areas we serve” section. Empty when no service area is set.',
  '{{area_serves_clause}}': 'Clause naming the areas served. Empty when no service area is set.',
  // Same shared half Writer now composes — one definition, both modules.
  ...SITE_BUSINESS_VARS,
};

/** Copy prompt variables. */
const COPY_VARS: Record<string, string> = {
  '{{language}}': 'Language to write the copy in.',
  '{{typeLabel}}': 'Human label for the copy type being written (e.g. “Facebook ad”).',
  '{{brief}}': 'The campaign brief.',
  '{{creativeBrief}}': 'The creative brief for this specific piece.',
  '{{campaignContext}}': 'Context about the paid campaign this copy belongs to.',
  '{{organicContext}}': 'Context about the organic/social side of the campaign.',
  '{{researchContext}}': 'Research findings gathered for the brand or offer.',
  '{{reviewsContext}}': 'Customer reviews, for social proof and voice-of-customer language.',
  '{{referenceCopy}}': 'Reference copy the model should take its style from.',
  '{{referenceAds}}': 'Reference ads supplied as worked examples.',
  '{{copyFramework}}': 'The copywriting framework to follow (AIDA, PAS…).',
  '{{angle}}': 'The marketing angle for this variation.',
  '{{anglesPerAudience}}': 'How many angles to produce per audience.',
  '{{audience}}': 'The target audience for this variation.',
  '{{audiences}}': 'The full list of audiences to write for.',
  '{{tone}}': 'The tonality to write in.',
  '{{toneInstruction}}': 'Ready-made tone instruction sentence. Empty when no tonality is set.',
  '{{ctaInstruction}}': 'Ready-made call-to-action instruction. Empty when no CTA is set.',
  '{{emojiInstruction}}': 'Ready-made emoji instruction. Empty when emoji are not requested.',
  '{{brandName}}': 'The brand’s name.',
  '{{product}}': 'The product or service being sold.',
  '{{description}}': 'The product or service description.',
  '{{count}}': 'How many copy variations to produce.',
};

/** Image prompt variables. */
const IMAGE_VARS: Record<string, string> = {
  '{{brief}}': 'What the image should show.',
  '{{style}}': 'The visual style to render in.',
  '{{count}}': 'How many images to produce.',
  '{{brandName}}': 'The brand’s name.',
  '{{brandSummary}}': 'Short summary of the business.',
  '{{brandColors}}': 'The brand’s colour palette.',
  '{{url}}': 'The brand’s website.',
  '{{niche}}': 'The brand’s niche or industry.',
  '{{location}}': 'The brand’s location.',
  '{{phone}}': 'The brand’s phone number.',
  '{{language}}': 'Language for any text rendered inside the image.',
  '{{seasonEvent}}': 'Season or event the image should reference.',
  '{{campaignTheme}}': 'The campaign theme.',
  '{{referenceImageIntent}}': 'How the supplied reference image should be used.',
};

/** Optimizer prompt variables. */
const OPTIMIZER_VARS: Record<string, string> = {
  '{{routing_rules}}': 'The routing rules the compiler must follow, in readable form.',
  '{{routing_json}}': 'The same routing rules as JSON.',
  '{{topic_rule}}': 'Whether to derive the topic from the content or use a fixed one.',
  '{{primary_keyword}}': 'The page’s primary keyword.',
  '{{questions}}': 'Question set pulled from the hub data.',
  '{{ranksfor_note}}': 'Note about the queries the page ranks for. Set only when the source is GSC.',
  '{{gsc_preference_note}}': 'Instruction to prefer targets matching GSC queries. Set only when the source is GSC.',
  '{{business.name}}': 'Business name from the Google Business Profile.',
  '{{business.category}}': 'Primary Google Business Profile category.',
  '{{business.address}}': 'Street address.',
};

// An allow-list, not a default: a module with no known vocabulary gets nothing
// rather than borrowing another module's, because a token that stays literal is
// worse than no suggestion at all.
function varsFor(module?: string): Record<string, string> {
  switch (module) {
    case 'writer': return WRITER_VARS;
    case 'seo': return SEO_VARS;
    case 'copy': return COPY_VARS;
    case 'image': return IMAGE_VARS;
    case 'optimizer': return OPTIMIZER_VARS;
    // 'video' deliberately absent — nothing substitutes there.
    default: return {};
  }
}

/**
 * Variables an entry may use. Only `prompt` entries run through a substituting
 * builder; on any other category the token would stay literal.
 */
export function templateVarsFor(module?: string, category?: string): TemplateVar[] {
  // Prompt-only modules: every entry is a prompt, whatever category it was stored under.
  if (category !== 'prompt' && !isPromptOnlyModule(module)) return [];
  return Object.entries(varsFor(module)).map(([token, description]) => ({ token, description }));
}
