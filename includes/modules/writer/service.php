<?php
/**
 * Writer Service
 *
 * Article generation pipeline for the Writer module.
 * Builds LLM prompt from user context (keywords, brand, site, prompt, settings)
 * and returns structured article output (title, content HTML, meta).
 *
 * Follows the same patterns as PCM_Strategy_Service::build_prompt() but with
 * richer context injection (supporting keywords, format, perspective, tone, custom prompt).
 *
 * @package PowerCreatives
 * @since   1.2.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class PCM_Writer_Service
{
    /**
     * Generate an article from Writer context.
     *
     * @param array $params {
     *     @type string   $primaryKeyword      Main target keyword / brief.
     *     @type string   $supportingKeywords   Newline-separated supporting keywords.
     *     @type string   $customPromptText     User/template prompt instructions.
     *     @type int|null $brandId              Brand ID for context injection.
     *     @type int|null $templateId           Template ID (prompt entries loaded if set).
     *     @type string   $format               Article format (deep_dive, listicle, etc.).
     *     @type string   $perspective           Writing perspective (first_plural, etc.).
     *     @type string   $toneOfVoice           Tone of voice.
     *     @type string   $siteUrl              Target site URL (for contextual relevance).
     *     @type string   $imageCount           Number of images to plan (0-5). Default '0'.
     *     @type string   $imageStyle           Image style preset (auto, realistic, etc.).
     *     @type string   $imageModel           Image generation model ID (auto = any).
     * }
     * @param int $user_id PCM user ID.
     *
     * @return array { title, content, metaTitle, metaDescription, ?mediaManifest }
     * @throws \RuntimeException On generation failure.
     */
    public static function generate(array $params, int $user_id): array
    {
        $keyword = $params['primaryKeyword'] ?? '';
        $supporting = $params['supportingKeywords'] ?? '';
        $custom_prompt = $params['writerPrompt'] ?? $params['customPromptText'] ?? '';
        $brand_id = !empty($params['brandId']) ? (int) $params['brandId'] : null;
        $template_id = !empty($params['templateId']) ? (int) $params['templateId'] : null;
        // Settings from UI — these may also be injected via template presets
        $format = $params['format'] ?? '';
        $perspective = $params['perspective'] ?? '';
        $tone = $params['toneOfVoice'] ?? '';
        $site_url = $params['siteUrl'] ?? '';
        $model_id = !empty($params['modelId']) ? sanitize_text_field($params['modelId']) : 'gemini-2.5-flash';

        // Image order from frontend sidebar
        $image_count = isset($params['imageCount']) ? (int) $params['imageCount'] : 0;
        $image_style = sanitize_text_field($params['imageStyle'] ?? 'auto');
        $image_model = sanitize_text_field($params['imageModel'] ?? 'auto');

        if (empty($keyword)) {
            throw new \RuntimeException('Primary keyword is required.');
        }

        // ── Load brand context ──
        $brand = null;
        if ($brand_id) {
            $brand = PCM_DB::get_brand_by_id($brand_id, $user_id);
        }

        // ── Load template prompt entries (if template selected) ──
        $template_prompt = '';
        if ($template_id) {
            $template = self::load_template($template_id, $user_id);
            foreach ($template['entries'] as $entry) {
                if (($entry['category'] ?? '') === 'prompt') {
                    $template_prompt .= ($template_prompt ? "\n\n" : '') . ($entry['value'] ?? '');
                }
            }
        }

        // ── Build messages (image_count drives media placeholder instructions) ──
        $messages = self::build_messages(
            $keyword,
            $supporting,
            $custom_prompt,
            $template_prompt,
            $brand,
            $format,
            $perspective,
            $tone,
            $site_url,
            $image_count,
            $user_id
        );

        // ── Invoke LLM with selected model ──
        $invoke_args = array(
            'model' => $model_id,
            'max_tokens' => 32768, // High ceiling for thinking models (Gemini 2.5 Pro) — reasoning tokens consume this budget
            'user_id' => get_current_user_id(),
        );

        // NOTE: We intentionally do NOT use on_chunk here.
        // PCM_LLM::invoke_json() requires a blocking (non-streaming) LLM call
        // so that it receives the complete JSON response for parsing.
        // Injecting on_chunk would force streaming mode, which accumulates
        // raw deltas that may be malformed JSON (missing opening braces, etc.).
        // PCM_SSE::start(600) already prevents timeouts via set_time_limit()
        // and X-Accel-Buffering: no headers.

        $result = PCM_LLM::invoke_json($messages, self::article_schema($image_count), $invoke_args);
        
        // Enrich manifest entries with the user's requested style/model for downstream consumers
        if ($image_count > 0 && isset($result['mediaManifest'])) {
            foreach ($result['mediaManifest'] as &$media) {
                $media['requested_style'] = $image_style;
                $media['requested_model'] = $image_model;
            }
        }
        
        return $result;
    }

    /**
     * Resolve {{placeholder}} variables inside a prompt template.
     */
    public static function resolve_prompt_placeholders(string $template, array $vars): string
    {
        foreach ($vars as $key => $value) {
            $template = str_replace('{{' . $key . '}}', (string)$value, $template);
        }
        // Clean up empty lines
        $template = preg_replace("/(^[\r\n]*|[\r\n]+)[\s\t]*[\r\n]+/", "\n\n", $template);
        return trim($template);
    }

    /**
     * Get default system prompts for the Writer module.
     */
    public static function get_default_prompts(): array
    {
        return array(
            'writer_system' => "You are an expert SEO Content Writer and Editor.

SETTINGS / GUIDELINES:
{{settingsContext}}

BRAND CONTEXT:
{{brandContext}}",

            'writer_user' => "{{instructionContext}}

TASK:
Write an article targeting the core brief: \"{{keyword}}\"

SUPPORTING DETAILS:
{{supporting}}

MEDIA INSTRUCTION:
{{mediaInstruction}}

OUTPUT FORMAT:
Return a JSON object with fields: title, content (clean semantic HTML — NO wrapper div, NO inline styles), metaTitle (max 60 chars), metaDescription (max 160 chars)."
        );
    }

    /**
     * Fetch a prompt from the Database overrides, fallback to defaults.
     */
    public static function get_system_prompt(string $section, int $user_id = 0): string
    {
        global $wpdb;

        if ($user_id > 0) {
            $table = PCM_Schema::table('prompt_overrides');
            $override = $wpdb->get_var($wpdb->prepare(
                "SELECT content FROM $table WHERE userId = %d AND module = 'writer' AND section = %s AND isActive = 1 ORDER BY updatedAt DESC LIMIT 1",
                $user_id,
                $section
            ));

            if (!empty($override)) {
                return $override;
            }
        }

        $defaults = self::get_default_prompts();
        if (isset($defaults[$section])) {
            return $defaults[$section];
        }

        throw new \RuntimeException("No active prompt found for section '{$section}' (userId={$user_id}).");
    }

    /**
     * Build OpenAI-compatible messages array from all context using Zero Hardcoding templates.
     */
    private static function build_messages(
        string $keyword,
        string $supporting,
        string $custom_prompt,
        string $template_prompt,
        ?object $brand,
        string $format,
        string $perspective,
        string $tone,
        string $site_url,
        int $image_count = 0,
        int $user_id = 0
    ): array {
        // 1. User settings logic
        $settings = array();
        if (!empty($format)) $settings[] = "FORMAT: {$format}";
        if (!empty($perspective)) $settings[] = "PERSPECTIVE: {$perspective}";
        if (!empty($tone)) $settings[] = "TONE OF VOICE: {$tone}";
        if (!empty($site_url)) $settings[] = "TARGET SITE: {$site_url}";
        $settingsContext = !empty($settings) ? implode("\n", $settings) : '';

        // 2. Brand context logic
        $brandContext = '';
        if ($brand) {
            if (!empty($brand->name)) $brandContext .= "- Company: {$brand->name}\n";
            if (!empty($brand->niche)) $brandContext .= "- Industry: {$brand->niche}\n";
            if (!empty($brand->tonOfVoice)) $brandContext .= "- Tone of Voice: {$brand->tonOfVoice}\n";
            if (!empty($brand->targetAudience)) $brandContext .= "- Target Audience: {$brand->targetAudience}\n";
            if (!empty($brand->uniqueSellingPoints)) $brandContext .= "- Unique Selling Points: {$brand->uniqueSellingPoints}\n";
            if (!empty($brand->language)) $brandContext .= "- Content Language: {$brand->language}\n";
        }

        // 3. Instruction context logic (Custom or Template)
        $instructionContext = '';
        if (!empty($custom_prompt)) {
            $instructionContext = "INSTRUCTION:\n" . $custom_prompt;
        } elseif (!empty($template_prompt)) {
            $instructionContext = "INSTRUCTION:\n" . $template_prompt;
        }

        // 4. Media Instructions
        $mediaInstruction = ($image_count > 0)
            ? "CRITICAL: You MUST plan exactly {$image_count} highly contextual images for this article. For each image, insert its exact placeholder, such as `[MEDIA:image:01]`, into the main `content` HTML string precisely where the image should appear (between paragraphs). Also output an array of detailed AI generation prompts in the `mediaManifest` JSON array for these exact images."
            : "CRITICAL: Do NOT generate any images or media placeholders for this article.";

        // Inject variables into prompt templates
        $vars = [
            'settingsContext' => $settingsContext,
            'brandContext' => $brandContext,
            'instructionContext' => $instructionContext,
            'keyword' => $keyword,
            'supporting' => $supporting,
            'mediaInstruction' => $mediaInstruction
        ];

        $system_template = self::get_system_prompt('writer_system', $user_id);
        $user_template = self::get_system_prompt('writer_user', $user_id);

        return array(
            array('role' => 'system', 'content' => self::resolve_prompt_placeholders($system_template, $vars)),
            array('role' => 'user', 'content' => self::resolve_prompt_placeholders($user_template, $vars)),
        );
    }

    /**
     * JSON schema for structured article output.
     * Dynamically includes mediaManifest when images are requested.
     *
     * @param int $image_count Number of images requested (0 = no media).
     */
    private static function article_schema(int $image_count = 0): array
    {
        $properties = array(
            'title' => array(
                'type' => 'string',
                'description' => 'SEO-optimized article title (H1)',
            ),
            'content' => array(
                'type' => 'string',
                'description' => 'Full article content in clean HTML (h2, h3, h4, p, ul, ol). No wrapper div.',
            ),
            'metaTitle' => array(
                'type' => 'string',
                'description' => 'SEO meta title tag, max 60 characters',
            ),
            'metaDescription' => array(
                'type' => 'string',
                'description' => 'SEO meta description, max 160 characters',
            ),
        );
        $required = array('title', 'content', 'metaTitle', 'metaDescription');

        if ($image_count > 0) {
            $properties['mediaManifest'] = array(
                'type' => 'array',
                'description' => 'List of contextual images to generate.',
                'items' => array(
                    'type' => 'object',
                    'properties' => array(
                        'id' => array('type' => 'string', 'description' => 'Identifier matching the placeholder (e.g. 01, 02)'),
                        'type' => array('type' => 'string', 'enum' => array('image')),
                        'prompt' => array('type' => 'string', 'description' => 'Detailed AI Image Generation Prompt depicting the exact scene from the paragraph context.'),
                        'alt_text' => array('type' => 'string', 'description' => 'SEO friendly alt text'),
                    ),
                    'required' => array('id', 'type', 'prompt', 'alt_text'),
                    'additionalProperties' => false,
                ),
            );
            $required[] = 'mediaManifest';
        }

        return array(
            'name' => 'article_output',
            'strict' => true,
            'schema' => array(
                'type' => 'object',
                'required' => $required,
                'properties' => $properties,
                'additionalProperties' => false,
            ),
        );
    }

    /**
     * Load template with entries from DB.
     *
     * Templates are stored in pcm_templates table with entries inside
     * the `formData` JSON column. This method queries the table directly
     * following the same pattern as PCM_REST_Templates::get_by_id().
     */
    private static function load_template(int $template_id, int $user_id): array
    {
        global $wpdb;

        $table = PCM_Schema::table('templates');

        // Allow user-owned and system-level (userId=0) templates
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE id = %d AND (userId = %d OR userId = 0)",
            $template_id,
            $user_id
        ));

        if (!$row) {
            return array('entries' => array());
        }

        $form_data = json_decode($row->formData, true) ?: array();

        return array(
            'id'      => (int) $row->id,
            'name'    => $row->name,
            'entries' => $form_data['entries'] ?? array(),
        );
    }

    /**
     * Upload an image to WP Media Library from a base64 string.
     *
     * @param array $params Contains fileData (base64 string), filename, mimeType
     * @param int $user_id
     * @return array {url, id}
     * @throws \RuntimeException
     */
    public static function upload_image(array $params, int $user_id): array
    {
        $fileData = $params['fileData'];
        $filename = sanitize_file_name($params['filename']);
        $mimeType = sanitize_text_field($params['mimeType']);

        // Check if fileData contains the base64 prefix (e.g., data:image/png;base64,) and strip it if present
        if (strpos($fileData, 'base64,') !== false) {
            $exploded = explode('base64,', $fileData);
            $fileData = $exploded[1];
        }

        $decodedData = base64_decode($fileData, true);

        if ($decodedData === false) {
            throw new \RuntimeException('Failed to decode base64 image data.');
        }

        // The Writer and Approvals editors embed the original attachment URL;
        // they never request Media Library thumbnails. Generating every
        // registered subsize here held the observed REST response open for
        // tens of seconds and made the document appear locked while closing.
        return PCM_Storage::save_data(
            $decodedData,
            $filename,
            $mimeType,
            'writer-inline-image',
            false
        );
    }
}
