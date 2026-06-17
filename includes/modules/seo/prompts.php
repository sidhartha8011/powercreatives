<?php
/**
 * SEO default AI prompts — ported verbatim from the source plugin's
 * prompt-templates config (option `optimizer_site_prompt_templates`).
 *
 * Keyed by `use`; each has a `generate` template (fresh value) and, where the
 * source provided one, an `optimize` template (improve an existing value via
 * {{current_value}}). The `{{placeholders}}` are filled by
 * PCM_SEO_Service::substitute_vars(). `max` = completion-token budget.
 *
 * Returned through the `pcm_seo_field_prompts` filter so a site can override.
 *
 * @package PowerCreatives
 * @since   1.23.0
 */

if (!defined('ABSPATH')) {
    exit;
}

return array(
    // ── Page title (H1 / column "title") ──
    'page_title' => array(
        'max'      => 120,
        'generate' => "Generate an SEO-optimized page title (H1 heading) for this page.\n\n"
            . "Current Title: {{title}}\nPrimary Keyword: {{primary_keyword}}\nSupporting Keyword: {{supporting_keyword}}\nBusiness: {{business.name}}\n\n"
            . "Requirements:\n- Maximum 70 characters\n- Include primary keyword naturally near the beginning\n- Compelling, descriptive, and unique\n- Must work as both the page heading and browser tab title\n- Do NOT use quotation marks in the output\n- Output ONLY the title text, nothing else",
        'optimize' => "Optimize the following page title for SEO. Improve keyword placement, readability, and search intent.\n\n"
            . "Current Title: {{current_value}}\nPrimary Keyword: {{primary_keyword}}\nSupporting Keyword: {{supporting_keyword}}\nBusiness: {{business.name}}\n\n"
            . "Requirements:\n- Maximum 70 characters\n- Include primary keyword near the beginning\n- Better search intent alignment\n- Do NOT use quotation marks in the output\n- Output ONLY the title text, nothing else",
    ),

    // ── Meta title ──
    'meta_title' => array(
        'max'      => 120,
        'generate' => "Generate an SEO-optimized meta title for this page.\n\n"
            . "Page Title: {{title}}\nPrimary Keyword: {{primary_keyword}}\nSupporting Keyword: {{supporting_keyword}}\nBusiness: {{business.name}}\n\n"
            . "Requirements:\n- Maximum 60 characters\n- Include primary keyword naturally\n- Compelling and click-worthy\n- Accurately describe the page content\n- Do NOT use quotation marks in the output",
        'optimize' => "Optimize the following meta title for SEO. Improve keyword usage, readability, and search intent.\n\n"
            . "Current Meta Title: {{current_value}}\nPage Title: {{title}}\nPrimary Keyword: {{primary_keyword}}\nSupporting Keyword: {{supporting_keyword}}\nBusiness: {{business.name}}\n\n"
            . "Requirements:\n- Maximum 60 characters\n- Include primary keyword naturally\n- Better search intent alignment\n- Do NOT use quotation marks in the output",
    ),

    // ── Meta description ──
    'meta_description' => array(
        'max'      => 220,
        'generate' => "Generate an SEO-optimized meta description for this page.\n\n"
            . "Page Title: {{title}}\nPrimary Keyword: {{primary_keyword}}\nSupporting Keyword: {{supporting_keyword}}\nBusiness: {{business.name}}\n\n"
            . "Requirements:\n- Maximum 160 characters\n- Include primary keyword naturally\n- Compelling call-to-action\n- Accurately summarize the page content\n\nOnly meta description without any comments or ** or other symbols.",
        'optimize' => "Optimize the following meta description for SEO. Improve keyword usage, readability, and search intent.\n\n"
            . "Current Meta Description: {{current_value}}\nPage Title: {{title}}\nPrimary Keyword: {{primary_keyword}}\nSupporting Keyword: {{supporting_keyword}}\nBusiness: {{business.name}}\nLanguage: {{site.lang}}\n\n"
            . "Requirements:\n- Maximum 160 characters\n- Include primary keyword naturally\n- Better search intent alignment\n- Compelling call-to-action\n- No comments only description\n\nStructure\n[Keyword exact match] [Give the prospect what they search for] [CTA]",
    ),

    // ── Full-body content optimization (SEO + AEO) ──
    'content' => array(
        'max'      => 4096,
        'optimize' => "You are an expert SEO and Answer-Engine-Optimization (AEO) content editor.\n\n"
            . "Primary Keyword: {{primary_keyword}}\nSupporting Keyword: {{supporting_keyword}}\nBusiness: {{business.name}}\nLanguage: {{site.lang}}\n\n"
            . "Rewrite and optimize the page content below for search and answer-engine visibility. Requirements:\n"
            . "- Keep clean semantic HTML (h2/h3, p, ul/ol, tables) — no inline styles, no <html>/<body> wrappers\n"
            . "- Include the primary keyword naturally in the first 100 words AND in at least one heading\n"
            . "- Use clear headings and short paragraphs; add a concise FAQ section (h2 + Q/A) when relevant\n"
            . "- Preserve the original meaning and any factual details; write in {{site.lang}}\n"
            . "- Output ONLY the optimized HTML body — no commentary, no code fences\n\n"
            . "CURRENT CONTENT:\n{{current_value}}",
    ),

    // ── Primary keyword (the single target keyword for the page) ──
    'primary_keyword' => array(
        'max'      => 30,
        'generate' => "Suggest the single best primary target keyword for this page for SEO.\n\n"
            . "Page Title: {{title}}\nMeta Description: {{meta_description}}\nBusiness: {{business.name}}\nLanguage: {{site.lang}}\n\n"
            . "Requirements:\n- 1-4 words; the main search term this page should rank for\n- Realistic search intent (not the brand name unless that is the intent)\n- Output ONLY the keyword phrase in lowercase — no quotation marks, no commentary",
        'optimize' => "Improve the primary target keyword for this page for SEO.\n\n"
            . "Current Primary Keyword: {{current_value}}\nPage Title: {{title}}\nMeta Description: {{meta_description}}\nBusiness: {{business.name}}\nLanguage: {{site.lang}}\n\n"
            . "Requirements:\n- 1-4 words; the main search term this page should rank for\n- Better search intent / volume alignment than the current keyword\n- Output ONLY the keyword phrase in lowercase — no quotation marks, no commentary",
    ),

    // ── Meta keywords (corrected {{primary_kw}} → {{primary_keyword}}) ──
    'meta_keywords' => array(
        'max'      => 300,
        'generate' => "Generate 10-15 highly relevant SEO keywords/synonyms for this page. "
            . "Focus on high search volume terms. Put the primary keyword first, the supporting keyword second, "
            . "then synonyms and long-tail variations. Return ONLY a comma-separated list, no business name. "
            . "Language: {{site.lang}}. Page Title: {{title}} Primary Keyword: {{primary_keyword}} Supporting Keyword: {{supporting_keyword}}",
    ),
);
