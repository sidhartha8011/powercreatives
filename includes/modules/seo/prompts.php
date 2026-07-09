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
            . "Requirements:\n- Maximum 60 characters\n- Include primary keyword naturally\n- Compelling and click-worthy\n- Accurately describe the page content\n- Do NOT use quotation marks in the output\n- Output ONLY the meta title text — no markdown, headings, character counts, labels, or commentary",
        'optimize' => "Optimize the following meta title for SEO. Improve keyword usage, readability, and search intent.\n\n"
            . "Current Meta Title: {{current_value}}\nPage Title: {{title}}\nPrimary Keyword: {{primary_keyword}}\nSupporting Keyword: {{supporting_keyword}}\nBusiness: {{business.name}}\n\n"
            . "Requirements:\n- Maximum 60 characters\n- Include primary keyword naturally\n- Better search intent alignment\n- Do NOT use quotation marks in the output\n- Output ONLY the meta title text — no markdown, headings, character counts, labels, or commentary",
    ),

    // ── Heading (H1–H6) — used by the SEO table's expandable heading editor ──
    'heading' => array(
        'max'      => 80,
        'generate' => "Write a single SEO-optimized heading for a section of this page.\n\n"
            . "Page Title: {{title}}\nPrimary Keyword: {{primary_keyword}}\nSupporting Keyword: {{supporting_keyword}}\nBusiness: {{business.name}}\nLanguage: {{site.lang}}\n\n"
            . "Requirements:\n- Concise and descriptive (ideally under 70 characters)\n- Include a relevant keyword naturally when it fits\n- Match search intent for the section\n- Plain text only — no HTML tags, no markdown, no quotation marks\n- Output ONLY the heading text, nothing else",
        'optimize' => "Optimize the following page heading for SEO and readability, keeping its original meaning and section topic.\n\n"
            . "Current Heading: {{current_value}}\nPage Title: {{title}}\nPrimary Keyword: {{primary_keyword}}\nSupporting Keyword: {{supporting_keyword}}\nBusiness: {{business.name}}\nLanguage: {{site.lang}}\n\n"
            . "Requirements:\n- Keep it concise (ideally under 70 characters)\n- Improve clarity, keyword relevance, and search intent — do NOT change what the section is about\n- Plain text only — no HTML tags, no markdown, no quotation marks\n- Output ONLY the heading text, nothing else",
    ),

    // ── Paragraph (on-page body text) — the dynamic-rule editor's AI optimize ──
    // Output is served VERBATIM at render time by the connector's rule engine, so
    // the requirements pin plain inline text (links allowed) and same-language.
    'paragraph' => array(
        'max'      => 400,
        'generate' => "Write a single SEO-optimized paragraph for a section of this page.\n\n"
            . "Page Title: {{title}}\nPrimary Keyword: {{primary_keyword}}\nSupporting Keyword: {{supporting_keyword}}\nBusiness: {{business.name}}\nLanguage: {{site.lang}}\n\n"
            . "Requirements:\n- 2-4 sentences, clear and factual\n- Work a relevant keyword in naturally (no stuffing)\n- Write in {{site.lang}}\n- Plain text only — no headings, no markdown, no quotation marks around the output\n- Output ONLY the paragraph text, nothing else",
        'optimize' => "Optimize the following paragraph for SEO and readability, keeping its original meaning, facts, and language.\n\n"
            . "Current Paragraph: {{current_value}}\nPage Title: {{title}}\nPrimary Keyword: {{primary_keyword}}\nSupporting Keyword: {{supporting_keyword}}\nBusiness: {{business.name}}\nLanguage: {{site.lang}}\n\n"
            . "Requirements:\n- Keep roughly the same length (never more than ~40% longer)\n- Improve clarity, keyword relevance, and search intent — do NOT change what the paragraph says or invent facts\n- Write in {{site.lang}} (the SAME language as the current paragraph)\n- Plain text only — no headings, no markdown, no quotation marks around the output\n- Output ONLY the paragraph text, nothing else",
    ),

    // ── Section (heading + its paragraphs) — the section editor's AI rewrite ──
    // Output is served VERBATIM at render time by the connector's section engine
    // (rule schema v2), so the requirements pin clean sibling block HTML: one
    // heading + <p>/<ul>/<ol> blocks, same language, no wrappers, no styles.
    'section' => array(
        'max'      => 1200,
        'generate' => "Write a complete NEW content section for this page.\n\n"
            . "Section topic / instruction: {{topic}}\nPage Title: {{title}}\nPrimary Keyword: {{primary_keyword}}\nSupporting Keyword: {{supporting_keyword}}\nBusiness: {{business.name}}\nLanguage: {{site.lang}}\n\n"
            . "Requirements:\n- Output clean HTML blocks ONLY: exactly one heading (<h2> or <h3>) first, then 1-4 <p> paragraphs (a <ul>/<ol> list is allowed where it genuinely helps)\n- No wrapper elements (<div>/<section>), no inline styles, no classes\n- Work a relevant keyword in naturally (no stuffing); clear and factual — never invent business facts\n- Write in {{site.lang}}\n- Output ONLY the HTML — no markdown, no code fences, no commentary",
        'optimize' => "Optimize the following page section for SEO and readability, keeping its original meaning, facts, and language.\n\n"
            . "Current Section HTML: {{current_value}}\nPage Title: {{title}}\nPrimary Keyword: {{primary_keyword}}\nSupporting Keyword: {{supporting_keyword}}\nBusiness: {{business.name}}\nLanguage: {{site.lang}}\n\n"
            . "Requirements:\n- Keep the same overall structure and roughly the same length (never more than ~40% longer); you may merge or split paragraphs when it clearly improves readability\n- Keep the heading's tag level; improve its text only when it clearly helps search intent\n- Output clean sibling HTML blocks ONLY (heading, <p>, optionally <ul>/<ol>); keep existing inline links; no wrappers, no inline styles, no classes\n- Do NOT change what the section says or invent facts\n- Write in {{site.lang}} (the SAME language as the current section)\n- Output ONLY the HTML — no markdown, no code fences, no commentary",
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

    // ── URL slug — built from the page's target keywords ──
    'slug' => array(
        'max'      => 60,
        'generate' => "Generate an SEO-friendly URL slug for this page, built from its target keywords.\n\n"
            . "Page Title: {{title}}\nPrimary Keyword: {{primary_keyword}}\nSupporting Keyword: {{supporting_keyword}}\nKeywords: {{meta_keywords}}\nBusiness: {{business.name}}\n\n"
            . "Requirements:\n- Base the slug on the primary keyword; fold in a supporting keyword only if it stays concise\n- If no keywords are provided, derive the slug from the page title\n- Lowercase, hyphen-separated words\n- Maximum 5 words\n- No stop words (the, a, an, is, of, for, to, etc.)\n- ASCII letters, numbers and hyphens only\n- Output ONLY the slug (e.g. best-seo-tools), nothing else",
        'optimize' => "Optimize the following URL slug for SEO, building it around the page's target keywords for maximum search visibility.\n\n"
            . "Current Slug: {{current_value}}\nPage Title: {{title}}\nPrimary Keyword: {{primary_keyword}}\nSupporting Keyword: {{supporting_keyword}}\nKeywords: {{meta_keywords}}\nBusiness: {{business.name}}\n\n"
            . "Requirements:\n- Base the slug on the primary keyword; fold in a supporting keyword only if it stays concise\n- If no keywords are provided, derive the slug from the page title\n- Lowercase, hyphen-separated words\n- Maximum 5 words\n- No stop words\n- ASCII letters, numbers and hyphens only\n- Output ONLY the slug (e.g. best-seo-tools), nothing else",
    ),

    // ── Site-wide robots.txt (Site tab → Optimize) ──
    'robots' => array(
        'max'      => 600,
        'generate' => "Generate a robots.txt file for this WordPress site.\n\n"
            . "Website: {{website.url}}\n\n"
            . "Requirements:\n- Standard robots.txt syntax\n- Allow legitimate search-engine crawlers by default\n- Disallow /wp-admin/ except /wp-admin/admin-ajax.php\n- Include a Sitemap directive pointing to {{website.url}}/sitemap.xml\n- Allow common AI crawlers (GPTBot, ClaudeBot, Google-Extended)\n- Do NOT include a Host directive (deprecated)\n- Output ONLY the robots.txt content — no explanation, no code fences",
    ),

    // ── Site-wide LocalBusiness JSON-LD schema (Site tab → Optimize) ──
    'site_schema' => array(
        'max'      => 800,
        'generate' => "Generate a valid JSON-LD schema.org LocalBusiness block for this website.\n\n"
            . "Business Name: {{business.name}}\nCategory: {{business.category}}\nAddress: {{business.address}}\nPhone: {{business.phone}}\nWebsite: {{website.url}}\nLatitude: {{business.lat}}\nLongitude: {{business.lng}}\nRating: {{business.rating}}\nOpening Hours: {{business.hours}}\nLanguage: {{site.lang}}\n\n"
            . "Requirements:\n- Valid JSON-LD with @context and @type\n- Use LocalBusiness (or a more specific subtype if the category matches a schema.org type)\n- Include name, url, telephone, address (PostalAddress), geo (GeoCoordinates) when data is available\n- Include aggregateRating and openingHoursSpecification only when that data is provided\n- Output ONLY the raw JSON object — no markdown fences, no explanation",
    ),

    // ── Site title (WP Site Title / blogname → Site tab → Optimize) ──
    'site_title' => array(
        'max'      => 60,
        'generate' => "Generate an SEO-optimized site title for this website.\n\n"
            . "Business: {{business.name}}\nCategory: {{business.category}}\nLanguage: {{site.lang}}\n\n"
            . "Requirements:\n- Maximum 60 characters\n- Clearly identify the business or brand\n- Include the primary service or industry naturally\n- Compelling and memorable\n- Write in {{site.lang}}\n- Do NOT use quotation marks\n- Output ONLY the site title text, nothing else",
    ),

    // ── Site tagline (WP Tagline / blogdescription → Site tab → Optimize) ──
    'site_tagline' => array(
        'max'      => 120,
        'generate' => "Generate an SEO-optimized tagline (site description) for this website.\n\n"
            . "Business: {{business.name}}\nCategory: {{business.category}}\nAddress: {{business.address}}\nLanguage: {{site.lang}}\n\n"
            . "Requirements:\n- Maximum 120 characters\n- Communicate the business value proposition clearly\n- Include the main service/industry keyword naturally\n- Compelling and succinct\n- Write in {{site.lang}}\n- Do NOT use quotation marks\n- Output ONLY the tagline text, nothing else",
    ),
);
