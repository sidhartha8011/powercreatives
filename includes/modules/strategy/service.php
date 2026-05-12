<?php
/**
 * Strategy Service — Business Logic Layer
 *
 * Orchestrates the content generation pipeline:
 *   1. Creates strategies from keyword selections
 *   2. Generates articles by combining template + brand context + LLM
 *   3. Manages item status transitions
 *
 * This replaces the backup's `strategyService.ts` (916 lines) with ~200 lines
 * by leveraging existing PC infrastructure (PCM_LLM, PCM_DB, templates).
 *
 * @package PowerCreatives
 */

if (!defined('ABSPATH')) {
    exit;
}

class PCM_Strategy_Service
{

    /**
     * Create a strategy and its items from a keyword list.
     *
     * Equivalent to backup's `createNewStrategy()` (18 params) but simplified
     * to use PC's DB-backed persistence and template system.
     *
     * @param int         $user_id     PCM user ID.
     * @param string      $name        Strategy name.
     * @param int         $template_id Template ID from pcm_templates.
     * @param int|null    $brand_id    Optional brand ID for context injection.
     * @param array       $keywords    Array of keyword strings.
     * @param array       $options     Optional config (hierarchyMode, publishingMode, config).
     *
     * @return array Created strategy with items array.
     * @throws \RuntimeException On validation or DB failure.
     */
    public static function create_from_keywords(
        int $user_id,
        string $name,
        int $template_id,
        ?int $brand_id,
        array $keywords,
        array $options = array()
    ): array {
        // ── Create the strategy record ──
        $strategy_data = array(
            'userId'         => $user_id,
            'name'           => $name,
            'templateId'     => $template_id,
            'brandId'        => $brand_id,
            'status'         => 'pending',
            'hierarchyMode'  => $options['hierarchyMode'] ?? 'standalone',
            'publishingMode' => $options['publishingMode'] ?? 'draft',
            'config'         => !empty($options['config']) ? wp_json_encode($options['config']) : null,
            'totalItems'     => count($keywords),
            'completedItems' => 0,
            'failedItems'    => 0,
        );

        $strategy_id = PCM_DB::create_strategy($strategy_data);
        if (!$strategy_id) {
            throw new \RuntimeException('Failed to create strategy in database.');
        }

        // ── Create items for each keyword ──
        $items_created = PCM_DB::create_strategy_items($strategy_id, $user_id, $keywords);

        // ── Return the complete strategy with items ──
        $strategy = PCM_DB::get_strategy($strategy_id, $user_id);
        $strategy->items = PCM_DB::get_strategy_items($strategy_id);

        return (array)$strategy;
    }

    /**
     * Generate an article for the next pending item in a strategy.
     *
     * Pipeline:
     *   1. Find next pending item
     *   2. Load template (prompt entries)
     *   3. Load brand context (if set)
     *   4. Build LLM prompt with variable injection
     *   5. Invoke PCM_LLM
     *   6. Create article in pcm_articles
     *   7. Link article to strategy item
     *   8. Update strategy counters
     *
     * @param object $strategy Strategy DB row.
     * @param int    $user_id  PCM user ID.
     *
     * @return array|null Generated article data, or null if all items done.
     * @throws \RuntimeException On generation failure.
     */
    public static function generate_next_item(object $strategy, int $user_id): ?array
    {
        // ── 1. Find next pending item ──
        $item = PCM_DB::get_next_pending_item((int)$strategy->id);
        if (!$item) {
            // All items are complete — update strategy status
            PCM_DB::update_strategy((int)$strategy->id, $user_id, array('status' => 'completed'));
            return null;
        }

        // Mark item as generating
        PCM_DB::update_strategy_item((int)$item->id, array('status' => 'generating'));

        try {
            // ── 2. Load template ──
            $template = self::load_template((int)$strategy->templateId, $user_id);

            // ── 3. Load brand context ──
            $brand = null;
            if (!empty($strategy->brandId)) {
                $brand = PCM_DB::get_brand_by_id((int)$strategy->brandId, $user_id);
            }

            // ── 4. Build prompt with variable injection ──
            $messages = self::build_prompt($item->keyword, $template, $brand);

            // ── 5. Invoke LLM ──
            $result = PCM_LLM::invoke_json($messages, self::article_schema(), array(
                'model'      => 'gemini-2.5-flash',
                'max_tokens' => 8192,
                'user_id'    => get_current_user_id(),
            ));

            // ── 6. Create article ──
            $title = $result['title'] ?? ucfirst($item->keyword);
            $slug = sanitize_title($title);

            $article_id = PCM_DB::create_article(array(
                'userId'          => $user_id,
                'strategyId'      => (int)$strategy->id,
                'strategyItemId'  => (int)$item->id,
                'brandId'         => !empty($strategy->brandId) ? (int)$strategy->brandId : null,
                'title'           => $title,
                'slug'            => $slug,
                'content'         => $result['content'] ?? '',
                'metaTitle'       => $result['metaTitle'] ?? $title,
                'metaDescription' => $result['metaDescription'] ?? '',
                'schemaType'      => 'Article',
                'status'          => 'draft',
            ));

            if (!$article_id) {
                throw new \RuntimeException('Failed to save generated article.');
            }

            // ── 7. Link article to strategy item ──
            PCM_DB::update_strategy_item((int)$item->id, array(
                'status'    => 'completed',
                'title'     => $title,
                'slug'      => $slug,
                'articleId' => $article_id,
            ));

            // ── 8. Update strategy counters ──
            $completed = (int)$strategy->completedItems + 1;
            $total = (int)$strategy->totalItems;
            $new_status = ($completed >= $total) ? 'completed' : 'in_progress';

            PCM_DB::update_strategy((int)$strategy->id, $user_id, array(
                'completedItems' => $completed,
                'status'         => $new_status,
            ));

            return array(
                'item'    => PCM_DB::get_strategy_items((int)$strategy->id),
                'article' => PCM_DB::get_article($article_id, $user_id),
            );

        } catch (\Throwable $e) {
            // Mark item as failed — don't crash the entire strategy
            PCM_DB::update_strategy_item((int)$item->id, array(
                'status'       => 'error',
                'errorMessage' => substr($e->getMessage(), 0, 1000),
            ));

            // Update strategy failed counter
            PCM_DB::update_strategy((int)$strategy->id, $user_id, array(
                'failedItems' => (int)$strategy->failedItems + 1,
            ));

            throw $e;
        }
    }

    // =========================================================================
    // PRIVATE HELPERS
    // =========================================================================

    /**
     * Load and parse a template by ID.
     *
     * @param int $template_id Template ID.
     * @param int $user_id     User ID.
     * @return array Template entries and metadata.
     */
    private static function load_template(int $template_id, int $user_id): array
    {
        global $wpdb;
        $table = PCM_Schema::table('templates');

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE id = %d AND (userId = %d OR userId = 0)",
            $template_id,
            $user_id
        ));

        if (!$row) {
            throw new \RuntimeException("Template #{$template_id} not found.");
        }

        $form_data = json_decode($row->formData, true) ?: array();
        return array(
            'name'    => $row->name,
            'entries' => $form_data['entries'] ?? array(),
            'type'    => $form_data['type'] ?? null,
        );
    }

    /**
     * Build LLM messages by injecting keyword + brand into template prompt.
     *
     * Template entries with category "prompt" are used as the system prompt.
     * Brand context is injected as additional system context.
     * The keyword becomes the user message.
     *
     * @param string     $keyword  Target keyword.
     * @param array      $template Template data with entries.
     * @param object|null $brand   Brand DB row or null.
     *
     * @return array OpenAI-compatible messages array.
     */
    private static function build_prompt(string $keyword, array $template, ?object $brand): array
    {
        $messages = array();

        // ── System prompt from template entries ──
        $system_parts = array();

        // Collect prompt entries from template
        foreach ($template['entries'] as $entry) {
            if (($entry['category'] ?? '') === 'prompt') {
                $system_parts[] = $entry['value'] ?? '';
            }
        }

        // If no prompt entries exist, use a sensible default
        if (empty($system_parts)) {
            $system_parts[] = 'You are an expert SEO content writer. Write a comprehensive, well-structured article optimized for search engines.';
        }

        // ── Inject brand context ──
        if ($brand) {
            $brand_context = "BRAND CONTEXT:\n";
            if (!empty($brand->name)) {
                $brand_context .= "- Company: {$brand->name}\n";
            }
            if (!empty($brand->niche)) {
                $brand_context .= "- Industry: {$brand->niche}\n";
            }
            if (!empty($brand->tonOfVoice)) {
                $brand_context .= "- Tone of Voice: {$brand->tonOfVoice}\n";
            }
            if (!empty($brand->targetAudience)) {
                $brand_context .= "- Target Audience: {$brand->targetAudience}\n";
            }
            if (!empty($brand->uniqueSellingPoints)) {
                $brand_context .= "- Unique Selling Points: {$brand->uniqueSellingPoints}\n";
            }
            if (!empty($brand->language)) {
                $brand_context .= "- Content Language: {$brand->language}\n";
            }
            $system_parts[] = $brand_context;
        }

        $messages[] = array(
            'role'    => 'system',
            'content' => implode("\n\n", $system_parts),
        );

        // ── User message: keyword as the generation target ──
        $messages[] = array(
            'role'    => 'user',
            'content' => "Write a comprehensive article targeting the keyword: \"{$keyword}\"\n\n"
                       . "Return a JSON object with the following fields: title, content (HTML), metaTitle, metaDescription.",
        );

        return $messages;
    }

    /**
     * JSON schema for structured article output from LLM.
     *
     * Used by PCM_LLM::invoke_json() for guaranteed structured output.
     *
     * @return array JSON schema definition.
     */
    private static function article_schema(): array
    {
        return array(
            'name'   => 'article_output',
            'strict' => true,
            'schema' => array(
                'type'       => 'object',
                'required'   => array('title', 'content', 'metaTitle', 'metaDescription'),
                'properties' => array(
                    'title' => array(
                        'type'        => 'string',
                        'description' => 'SEO-optimized article title (H1)',
                    ),
                    'content' => array(
                        'type'        => 'string',
                        'description' => 'Full article content in clean HTML (h2, h3, p, ul, ol, strong, em). No wrapper div.',
                    ),
                    'metaTitle' => array(
                        'type'        => 'string',
                        'description' => 'SEO meta title tag, max 60 characters',
                    ),
                    'metaDescription' => array(
                        'type'        => 'string',
                        'description' => 'SEO meta description, max 160 characters',
                    ),
                ),
                'additionalProperties' => false,
            ),
        );
    }
}
