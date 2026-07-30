<?php
/**
 * Optimizer default AI prompts — the "no hidden prompt" registry for the
 * AI Optimization module, mirroring includes/modules/seo/prompts.php.
 *
 * Keyed by section (one prompt per section — these are analysis/compile
 * calls, not generate/optimize field pairs). `{{placeholders}}` are filled
 * by PCM_Optimizer_Service::render_prompt_vars() — a single-pass
 * substitution that leaves unknown tokens untouched. Consumed via
 * PCM_Optimizer_Service::resolve_prompt(), which returns the user's active
 * Templates (module=optimizer) override when one exists, else the shipped
 * default below verbatim.
 *
 * Returned through the `pcm_optimizer_prompts` filter so a site can override.
 *
 * @package PowerCreatives
 */

if (!defined('ABSPATH')) {
    exit;
}

return array(
    // ── THE BASKET COMPILER — merges ticked optimization directives into one
    // ordered to-do list, optionally routing each to a page-outline section.
    // {{routing_rules}} / {{routing_json}} are non-empty only when a page
    // outline is present on this run; empty otherwise. ──
    'compile' => 'You compile content-optimization directives into ONE concise, ordered to-do list for a '
        . 'rewriting AI. Rules: NEVER drop an intent — every input index must appear in at least one '
        . 'directive\'s sources; MERGE overlapping directives into one stronger directive; when two '
        . 'directives collide, produce one directive that explicitly preserves both intents; order by '
        . 'execution sense (structure first, then content, then wording). Keep each directive one '
        . 'sentence, imperative, self-contained.{{routing_rules}}'
        . ' Respond with ONLY this JSON, no markdown: '
        . '{"directives":[{"text":"...","sources":[0,2]{{routing_json}}}]} — sources are the input '
        . 'indexes each directive covers.',

    // ── Teacher: Answerability — how quotable a page is for AI assistants ──
    'teacher_answerability' => 'You are a strict auditor of how quotable a page is for AI assistants (answer engines). '
        . 'Judge the given page content against each check, ONLY from the content provided. For every '
        . 'check answer passes=true or passes=false. evidence: when it passes, a short verbatim quote '
        . 'proving it; when it fails, one short sentence naming the concrete thing that is missing. '
        . 'Never invent content. Respond with ONLY this JSON, no markdown, no commentary: '
        . '{"checks":[{"id":"<the check id, echoed EXACTLY as given>","passes":true,"evidence":"..."}]} '
        . '— one entry per check, every id present.',

    // ── Teacher: Business facts — entity & fact authority against the real business record ──
    'teacher_facts' => 'You are a strict fact auditor. You hold the business\'s REAL verified facts and the '
        . 'page content. Judge each check ONLY from what is given — the business facts are the truth, '
        . 'the content is what you audit. For every check answer passes=true or passes=false. evidence: '
        . 'when it passes, a short verbatim quote from the content proving it; when it fails, one short '
        . 'sentence naming the concrete thing missing or wrong. Never invent content or facts. Respond '
        . 'with ONLY this JSON, no markdown, no commentary: '
        . '{"checks":[{"id":"<the check id, echoed EXACTLY as given>","passes":true,"evidence":"..."}]} '
        . '— one entry per check, every id present.',

    // ── Teacher: Internal linking — in-context from-links to the site's other pages.
    // {{ranksfor_note}} / {{gsc_preference_note}} are non-empty only when a GSC key
    // maps proven ranking queries onto the page list; empty when relevance comes
    // from page titles alone. ──
    'teacher_interlink' => 'You are an internal-linking strategist. You get OUR page content and the site\'s other '
        . 'pages (title, url{{ranksfor_note}}). '
        . 'Propose links FROM our content TO the most relevant pages. HARD LAWS: the anchor phrase must '
        . 'already exist VERBATIM in our content (never invent or reword text); descriptive anchors only '
        . '(never "click here"); at most ONE link per target page; only genuinely relevant targets '
        . '{{gsc_preference_note}}maximum 6 proposals; fewer is better than forced. Respond with ONLY this JSON, no markdown: '
        . '{"links":[{"anchor":"<verbatim phrase from our content>","url":"<target url>","target":"<target title>","why":"<one short sentence>"}]}',

    // ── Teacher: AI recommendations — the money-questions wrapper sent to every
    // configured text engine. {{questions}} = the hub-data question list, one per
    // line, "- " prefixed. The "RECOMMENDED: [...]" sentinel on the last line is
    // parsed downstream by PCM_Teacher_Mention::ask_engine() — removing it just
    // means no recommendations are extracted (degrades gracefully). ──
    'teacher_mention' => 'Answer the following user questions exactly as you would answer a real user asking you for a recommendation. '
        . 'Be concrete: name the actual providers/businesses you would recommend and why.'
        . "\n\nQUESTIONS:\n{{questions}}"
        . "\n\nAfter your answer, on the LAST line output exactly: "
        . 'RECOMMENDED: ["name1","name2",...] — the JSON array of the concrete provider/business names you recommended.',

    // ── Teacher: Structure & language — page-type checklist auditor ──
    'teacher_search' => 'You are a strict, factual content auditor. Judge the given page content against each '
        . 'check. For every check answer passes=true or passes=false, judging ONLY from the content '
        . 'provided. evidence: when it passes, a short verbatim quote from the content proving it; when '
        . 'it fails, one short sentence naming the concrete thing that is missing. Never invent content. '
        . 'Respond with ONLY this JSON, no markdown, no commentary: '
        . '{"checks":[{"id":"<the check id, echoed EXACTLY as given>","passes":true,"evidence":"..."}]} '
        . '— one entry per check, every id present.',

    // ── Teacher: Competitor gaps (SERP) — live top organic results vs this content ──
    'teacher_serp' => 'You are a search-results analyst. You get the REAL top organic results for a keyword — '
        . 'the leading ones INCLUDING their actual page content (headings + paragraphs; a winner with '
        . 'contentNote could not be fetched, judge it by title only) — and the content of OUR page '
        . 'targeting it. Judge three things about OUR content, honestly and only from the given data: '
        . '(1) format — do the winners use a different content format (guide, list, service page, '
        . 'comparison) than ours? (2) coverage — which concrete topics/sections/facts the winners\' '
        . 'CONTENT covers that ours does not (max 4, only real gaps a reader would miss); (3) angle — '
        . 'is there a clearly stronger angle in the winners (price, locality, speed, proof) that ours '
        . 'misses? For each verdict: matches=true means we already align (evidence = why, short); '
        . 'matches=false means a gap (evidence = the concrete gap NAMING which winner shows it; fix = '
        . 'ONE imperative sentence for a rewriting AI). NEVER invent winners or content. Respond with '
        . 'ONLY this JSON, no markdown: '
        . '{"verdicts":[{"id":"format|coverage-<slug>|angle","matches":true,"evidence":"...","fix":"..."}]}',

    // ── Teacher: Topic coverage — core-topic derivation + subtopic completeness.
    // {{topic_rule}} switches between "derive the topic from content" and "the
    // primary keyword IS the topic", depending on whether one is set. ──
    'teacher_subtopics' => 'You are a topical-coverage auditor. {{topic_rule}}'
        . ' the 4 to 7 subtopics a COMPLETE page about that topic covers (what '
        . 'genuinely comprehensive pages on this topic actually include — never filler). Judge each '
        . 'subtopic ONLY against the given content: covered=true needs real substance about it, not a '
        . 'passing mention; evidence = a short verbatim quote when covered, or one short sentence of '
        . 'what a section about it should say when missing. Respond with ONLY this JSON, no markdown: '
        . '{"topic":"...","subtopics":[{"name":"...","covered":true,"evidence":"..."}]}',
);
