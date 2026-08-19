<?php
/**
 * Article AI Review — structured edit suggestions + surgical apply.
 *
 * Scoped v1 port of the AutoPress editor-agent pipeline (editorAgentService +
 * finderAgent + writerAgent) compressed into one safe pass over stored HTML:
 * a single LLM call proposes suggestions as {find, issue, replacement} rows,
 * every `find` is re-validated against the article through the same
 * deterministic tag-safe occurrence scan the Strategy interlinker uses, and
 * applying a suggestion replaces exactly the FIRST safe occurrence. The
 * original's type router (replace/rewrite/delete/generic), per-node IDs, and
 * Swedish inflection tables are intentionally out of scope — inflection and
 * capitalization matching is delegated to the LLM prompt, and the verbatim
 * re-validation rail drops anything the model hallucinated.
 *
 * @package PowerCreatives
 */

if (!defined('ABSPATH')) {
    exit;
}

class PCM_Article_Review
{
    /** Hard cap on suggestions returned to the UI per review run. */
    private const MAX_SUGGESTIONS = 10;

    /**
     * Run an AI review over an article's HTML and return validated
     * suggestions. Each suggestion is guaranteed to be applyable: its `find`
     * text was located verbatim in the content outside of HTML markup (the
     * same guarantee apply() re-checks). Unlocatable model output is dropped,
     * never surfaced — a suggestion the user can't apply is noise.
     *
     * @param string $content  Article HTML.
     * @param string $feedback Optional user instruction ('' = general review:
     *                         clarity, grammar, flow, SEO phrasing).
     * @param int    $user_id  Owner ID, forwarded for LLM accounting.
     * @return array<int, array{find: string, issue: string, replacement: string}>
     * @throws \RuntimeException When the LLM call itself fails — the caller
     *                           (REST controller) turns this into an error
     *                           response; unlike the interlink AI-anchor
     *                           fallback this is a direct user action, so a
     *                           silent empty result would read as "no issues".
     */
    public static function review(string $content, string $feedback, int $user_id): array
    {
        $text = trim($content);
        if ($text === '') {
            return array();
        }

        $task = $feedback !== ''
            ? 'Apply this editorial feedback: ' . $feedback
            : 'Do a general editorial review: clarity, grammar, awkward phrasing, and SEO-unfriendly wording.';

        $messages = array(
            array(
                'role'    => 'system',
                'content' => 'You are a precise copy editor. You suggest small surgical text replacements inside an HTML article. '
                    . 'Rules: each "find" MUST be a short verbatim excerpt (3-25 words) copied EXACTLY from the article\'s visible text, '
                    . 'never from inside an HTML tag or attribute. Each "replacement" must slot into the sentence grammatically — '
                    . 'match the original\'s capitalization, number, and inflection. Plain text only in "find" and "replacement", no HTML tags. '
                    . 'Never suggest a replacement identical to its find. At most ' . self::MAX_SUGGESTIONS . ' suggestions.',
            ),
            array(
                'role'    => 'user',
                'content' => $task . "\n\nARTICLE HTML:\n" . substr($content, 0, 24000),
            ),
        );

        // invoke_json() takes the full json_schema WRAPPER ({name, schema}) —
        // OpenAI's response_format requires the name (400 without it).
        $schema = array(
            'name'   => 'article_review_suggestions',
            'schema' => array(
                'type'       => 'object',
                'properties' => array(
                    'suggestions' => array(
                        'type'  => 'array',
                        'items' => array(
                            'type'       => 'object',
                            'properties' => array(
                                'find'        => array('type' => 'string'),
                                'issue'       => array('type' => 'string'),
                                'replacement' => array('type' => 'string'),
                            ),
                            'required' => array('find', 'issue', 'replacement'),
                        ),
                    ),
                ),
                'required' => array('suggestions'),
            ),
        );

        $result = PCM_LLM::invoke_json($messages, $schema, array('user_id' => $user_id, 'web' => PCM_LLM::web_default()));
        $rows   = (is_array($result) && isset($result['suggestions']) && is_array($result['suggestions']))
            ? $result['suggestions']
            : array();

        // Deterministic validation rail: keep only rows whose find text has a
        // safe (outside-markup) occurrence, exactly what apply() will target.
        $valid = array();
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $find        = trim((string) ($row['find'] ?? ''));
            $replacement = trim((string) ($row['replacement'] ?? ''));
            $issue       = trim((string) ($row['issue'] ?? ''));
            if ($find === '' || $find === $replacement) {
                continue;
            }
            $hit = self::find_safe_occurrence($content, $find);
            if ($hit === null) {
                continue; // hallucinated or markup-only text — not applyable
            }
            $valid[] = array(
                // Return the exact matched surface form so apply()'s
                // case-sensitive pass finds the same occurrence.
                'find'        => $hit[0],
                'issue'       => $issue,
                'replacement' => $replacement,
            );
            if (count($valid) >= self::MAX_SUGGESTIONS) {
                break;
            }
        }
        return $valid;
    }

    /**
     * Surgically apply one suggestion: replace the FIRST occurrence of $find
     * that sits outside HTML markup with $replacement. Returns the new HTML,
     * or null when no safe occurrence exists (content changed since the
     * review, or the only occurrences are inside tags/anchors).
     *
     * @param string $content     Article HTML.
     * @param string $find        Verbatim text to replace.
     * @param string $replacement Replacement text ('' deletes the excerpt).
     * @return string|null
     */
    public static function apply(string $content, string $find, string $replacement): ?string
    {
        $hit = self::find_safe_occurrence($content, $find);
        if ($hit === null) {
            return null;
        }
        return substr($content, 0, $hit[1])
            . $replacement
            . substr($content, $hit[1] + strlen($hit[0]));
    }

    /**
     * First occurrence of $needle not inside HTML markup, case-sensitive
     * first with a case-insensitive fallback (mirrors the Strategy
     * interlinker's exact-then-relaxed scan; kept module-local because that
     * implementation is private to PCM_Strategy_Service).
     *
     * @param string $content Full HTML string.
     * @param string $needle  Literal phrase (matched via preg_quote).
     * @return array{0: string, 1: int}|null [matched text, byte offset] or null.
     */
    private static function find_safe_occurrence(string $content, string $needle): ?array
    {
        if ($needle === '') {
            return null;
        }
        foreach (array('', 'i') as $flags) {
            $pattern = '/' . preg_quote($needle, '/') . '/' . $flags;
            if (!preg_match_all($pattern, $content, $matches, PREG_OFFSET_CAPTURE)) {
                continue;
            }
            foreach ($matches[0] as $match) {
                if (!self::is_inside_html_tag($content, $match[1])) {
                    return array($match[0], $match[1]);
                }
            }
        }
        return null;
    }

    /**
     * Whether a byte offset is an unsafe edit position: inside a tag's own
     * markup (between an unmatched `<` and its `>`) or inside an existing
     * anchor's rendered text (same two rules as the Strategy interlinker —
     * replacing anchor text would silently change link labels).
     *
     * @param string $content Full HTML string.
     * @param int    $offset  Byte offset to check.
     * @return bool
     */
    private static function is_inside_html_tag(string $content, int $offset): bool
    {
        $before = substr($content, 0, $offset);

        $last_open  = strrpos($before, '<');
        $last_close = strrpos($before, '>');
        if ($last_open !== false && ($last_close === false || $last_open > $last_close)) {
            return true;
        }

        $open_anchors   = preg_match_all('/<a\b[^>]*>/i', $before);
        $closed_anchors = preg_match_all('#</a>#i', $before);
        return $open_anchors > $closed_anchors;
    }
}
