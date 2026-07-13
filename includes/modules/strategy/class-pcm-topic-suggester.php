<?php
/**
 * AI Topic Suggestion Service
 *
 * Port of AutoPress's topic-ideation feature onto structured-output based
 * PCM_LLM::invoke_json(). Given a niche/site context, asks the LLM for N
 * distinct blog topic ideas (target keyword + title + short rationale),
 * avoiding topics the caller has already covered.
 *
 * Isolation contract: mirrors PCM_Strategy_Image — a suggestion failure must
 * NEVER break the caller. Every path here returns an empty array on any
 * problem (missing API key, LLM throw, malformed response) and error_log()s
 * the reason — the caller treats an empty array as "no suggestions".
 *
 * @package PowerCreatives
 */

if (!defined('ABSPATH')) {
    exit;
}

class PCM_Topic_Suggester
{
    /**
     * Suggest $count content topic ideas for the given niche/site context.
     *
     * @param int   $user_id  PCM/WP user ID whose integration API key is used (passed through to PCM_LLM).
     * @param array $context {
     *     Optional context describing the site/niche. All keys are optional.
     *
     *     @type string   $niche          Niche/industry description.
     *     @type string   $siteName       Site name.
     *     @type string   $siteUrl        Site URL.
     *     @type string   $audience       Target audience description.
     *     @type string[] $existingTopics Topics already covered — avoid duplicating these.
     * }
     * @param int    $count    Number of topic ideas requested. Default 5.
     * @param string $model    Model ID; empty → PCM_LLM default.
     * @param string $provider Provider ID; empty → PCM_LLM auto-detect.
     * @return array<int, array{keyword: string, title: string, rationale: string}> Empty array on ANY failure.
     */
    public static function suggest(int $user_id, array $context, int $count = 5, string $model = '', string $provider = ''): array
    {
        try {
            $count = max(1, $count);

            $niche           = (string)($context['niche'] ?? '');
            $site_name       = (string)($context['siteName'] ?? '');
            $site_url        = (string)($context['siteUrl'] ?? '');
            $audience        = (string)($context['audience'] ?? '');
            $existing_topics = array_map('strval', (array)($context['existingTopics'] ?? array()));

            $messages = self::build_messages($niche, $site_name, $site_url, $audience, $existing_topics, $count);
            $schema   = self::build_schema();

            $options = array(
                'max_tokens' => 2048,
                'user_id'    => $user_id,
            );
            if ($model !== '') {
                $options['model'] = $model;
            }
            if ($provider !== '') {
                $options['provider'] = $provider;
            }

            $result = PCM_LLM::invoke_json($messages, $schema, $options);
            $topics = is_array($result['topics'] ?? null) ? $result['topics'] : array();

            return self::validate_topics($topics, $count);
        } catch (\Throwable $e) {
            error_log(sprintf(
                '[PCM_Topic_Suggester] Topic suggestion failed: %s',
                $e->getMessage()
            ));
            return array();
        }
    }

    /**
     * Build the system + user messages for the topic-ideation prompt.
     *
     * @param string   $niche
     * @param string   $site_name
     * @param string   $site_url
     * @param string   $audience
     * @param string[] $existing_topics
     * @param int      $count
     * @return array<int, array{role: string, content: string}>
     */
    private static function build_messages(
        string $niche,
        string $site_name,
        string $site_url,
        string $audience,
        array $existing_topics,
        int $count
    ): array {
        $system = 'You are an SEO content strategist. Given a site\'s niche and audience, you propose fresh, ' .
            'high-value blog topic ideas. Each idea must include a specific target keyword, a compelling ' .
            'title, and a short rationale explaining why the topic is worth covering. Avoid generic, vague, ' .
            'or duplicate topics.';

        $lines   = array();
        $lines[] = sprintf('Suggest exactly %d distinct blog topic ideas.', $count);

        if ($niche !== '') {
            $lines[] = sprintf('Niche: %s', $niche);
        }
        if ($site_name !== '') {
            $lines[] = sprintf('Site name: %s', $site_name);
        }
        if ($site_url !== '') {
            $lines[] = sprintf('Site URL: %s', $site_url);
        }
        if ($audience !== '') {
            $lines[] = sprintf('Target audience: %s', $audience);
        }
        if (!empty($existing_topics)) {
            $lines[] = sprintf(
                'Do NOT suggest topics that duplicate or closely overlap these existing topics: %s',
                implode(', ', $existing_topics)
            );
        }
        $lines[] = 'For each idea, provide a target keyword, a title, and a short rationale.';

        return array(
            array('role' => 'system', 'content' => $system),
            array('role' => 'user', 'content' => implode("\n", $lines)),
        );
    }

    /**
     * JSON schema passed to PCM_LLM::invoke_json(). PCM_LLM's tiered
     * fallback (json_schema → json_object → prompt-only) and its
     * additionalProperties/strict-mode wiring are handled automatically.
     *
     * @return array
     */
    private static function build_schema(): array
    {
        return array(
            'name'   => 'topic_suggestions',
            'schema' => array(
                'type'       => 'object',
                'properties' => array(
                    'topics' => array(
                        'type'  => 'array',
                        'items' => array(
                            'type'       => 'object',
                            'properties' => array(
                                'keyword'   => array('type' => 'string'),
                                'title'     => array('type' => 'string'),
                                'rationale' => array('type' => 'string'),
                            ),
                            'required' => array('keyword', 'title', 'rationale'),
                        ),
                    ),
                ),
                'required' => array('topics'),
            ),
        );
    }

    /**
     * Validate and normalize the raw topics array from the LLM response:
     * cast each entry to an array with string keyword/title/rationale keys,
     * drop entries missing a (non-empty) keyword, then slice to $count.
     *
     * @param array $topics Raw topics array from the LLM response.
     * @param int   $count  Max number of entries to return.
     * @return array<int, array{keyword: string, title: string, rationale: string}>
     */
    private static function validate_topics(array $topics, int $count): array
    {
        $validated = array();

        foreach ($topics as $topic) {
            if (!is_array($topic)) {
                continue;
            }
            $keyword = (string)($topic['keyword'] ?? '');
            if ($keyword === '') {
                continue;
            }
            $validated[] = array(
                'keyword'   => $keyword,
                'title'     => (string)($topic['title'] ?? ''),
                'rationale' => (string)($topic['rationale'] ?? ''),
            );
        }

        return array_slice($validated, 0, $count);
    }
}
