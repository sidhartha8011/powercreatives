<?php
/**
 * PCM LLM Core
 *
 * Central LLM invocation class used by all AI-powered modules
 * (Copy, Image concepts, Asset Scraper, URL Scraper).
 *
 * Features:
 *   - Provider dispatch via pcm_integrations table (API key lookup)
 *   - OpenAI Chat Completions compatible format (works with OpenAI, Google via proxy)
 *   - Structured JSON output (response_format: json_schema)
 *   - Streaming callback support for SSE (content tokens emitted as they arrive)
 *   - Message normalization (text, image_url, multi-part content)
 *
 * @package PowerCreatives
 * @since   1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class PCM_LLM
{

    /**
     * Default model to use when none is specified.
     *
     * @var string
     */
    const DEFAULT_MODEL = 'gpt-4o-mini';

    /**
     * Default max tokens for completion.
     *
     * @var int
     */
    const DEFAULT_MAX_TOKENS = 16384;

    /**
     * Provider-to-base-URL mapping for OpenAI-compatible APIs.
     *
     * Google, Anthropic, etc. need their own adapters since their API format
     * differs. For now we support OpenAI-compatible endpoints (OpenAI, together.ai,
     * Groq, etc.) and Google Generative AI via its OpenAI-compatible mode.
     *
     * @var array<string, string>
     */
    private static array $provider_endpoints = array(
        'openai' => 'https://api.openai.com/v1/chat/completions',
        'google' => 'https://generativelanguage.googleapis.com/v1beta/openai/chat/completions',
        'anthropic' => 'https://api.anthropic.com/v1/messages',
    );

    /**
     * Invoke an LLM with the given messages and options.
     *
     * This is the primary entry point for all AI text generation in the plugin.
     *
     * @param array  $messages        Array of message objects: [['role' => 'system', 'content' => '...'], ...]
     * @param array  $options {
     *     Optional parameters.
     *
     *     @type string   $model           Model ID (e.g. 'gpt-4o', 'gemini-2.0-flash-exp'). Default 'gpt-4o-mini'.
     *     @type string   $provider        Provider ID (e.g. 'openai', 'google'). Auto-detected from model if omitted.
     *     @type int      $max_tokens      Max completion tokens. Default 16384.
     *     @type array    $response_format Response format config (e.g. json_schema).
     *     @type array    $tools           LLM tools to enable (e.g. google_search grounding).
     *                                     Currently supported: Google provider only.
     *     @type callable $on_chunk        Streaming callback: fn(string $token) called per chunk.
     *     @type int      $user_id         WP user ID for API key lookup. Default current_user.
     * }
     *
     * @return array Parsed response: { content: string, usage: array, model: string, raw: array }
     * @throws \RuntimeException On API key missing, HTTP error, or parse failure.
     */
    public static function invoke(array $messages, array $options = array()): array
    {
        // Resolve model — handle both null and empty string gracefully.
        // PHP's ?? only triggers for undefined keys, NOT for explicit null values.
        // Callers may pass 'model' => null when no override is selected.
        $model = !empty($options['model']) ? $options['model'] : self::DEFAULT_MODEL;
        $provider = $options['provider'] ?? self::detect_provider($model);
        $max_tokens = $options['max_tokens'] ?? self::DEFAULT_MAX_TOKENS;
        $user_id = $options['user_id'] ?? get_current_user_id();
        $on_chunk = $options['on_chunk'] ?? null;
        $response_format = $options['response_format'] ?? null;
        $tools = $options['tools'] ?? null;

        // ── Resolve API credentials ──
        $api_key = self::get_api_key($provider, $user_id);
        $base_url = self::get_endpoint_url($provider);

        // ── Build the request payload ──
        $payload = array(
            'model' => $model,
            'messages' => self::normalize_messages($messages),
        );

        // Token limit handling per provider.
        // Google Gemini 2.5 "thinking" models generate hidden reasoning tokens
        // that consume max_tokens budget. Use max_completion_tokens to only limit
        // visible output tokens, leaving thinking budget uncapped.
        if ($provider === 'google') {
            $payload['max_completion_tokens'] = $max_tokens;
        }
        else {
            $payload['max_tokens'] = $max_tokens;
        }

        // Add structured output format if specified.
        // Adapt response_format per provider since not all providers support
        // the same modes:
        //   - OpenAI: full json_schema with strict mode (Structured Outputs)
        //   - Google: full json_schema via OpenAI-compatible endpoint (supported since Gemini 2.0+)
        //   - Anthropic: no response_format support (JSON enforced via prompt)
        if ($response_format) {
            if ($provider === 'openai' || $provider === 'google') {
                // Both OpenAI and Google's OpenAI-compatible endpoint support
                // json_schema with strict mode. Ensure additionalProperties: false
                // on every object in the schema (required by both).
                $payload['response_format'] = self::ensure_additional_properties($response_format);
            }
        // Anthropic: response_format is silently dropped (not supported).
        // The prompt should already request JSON output.
        }

        // Pass through LLM tools (e.g. Gemini grounding via google_search).
        // Currently only Google supports tools via its OpenAI-compatible endpoint.
        // Other providers: silently ignored (can be extended per-provider later).
        if (!empty($tools) && $provider === 'google') {
            $payload['tools'] = $tools;
        }

        // Enable streaming if callback provided
        if (is_callable($on_chunk)) {
            $payload['stream'] = true;
        }

        // ── Provider-specific header adjustments ──
        $headers = array(
            'Content-Type' => 'application/json',
        );

        if ($provider === 'anthropic') {
            $headers['x-api-key'] = $api_key;
            $headers['anthropic-version'] = '2023-06-01';
            // Convert to Anthropic message format
            $payload = self::to_anthropic_format($payload);
        }
        else {
            $headers['Authorization'] = 'Bearer ' . $api_key;
        }

        // Log request for debugging (only in WP_DEBUG mode)
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log(sprintf('[PCM_LLM] Invoking %s via %s — model: %s', $provider, $base_url, $model));

            // [PCM_DIAG — remove after style inconsistency investigation]
            // Dump full request payload to diagnostic file for objective analysis
            self::write_diagnostic(array(
                'direction' => 'REQUEST',
                'timestamp' => gmdate('Y-m-d H:i:s'),
                'provider' => $provider,
                'model' => $model,
                'payload' => $payload,
            ));
        }

        // ── Execute the request ──
        if (is_callable($on_chunk)) {
            return self::stream_request($base_url, $payload, $headers, $on_chunk);
        }

        return self::blocking_request($base_url, $payload, $headers);
    }

    /**
     * Convenience: invoke and parse JSON response content.
     *
     * @param array $messages Message array.
     * @param array $schema   JSON schema for structured output.
     * @param array $options  Additional invoke options.
     *
     * @return array Parsed JSON from response content.
     */
    public static function invoke_json(array $messages, array $schema, array $options = array()): array
    {
        $options['response_format'] = array(
            'type' => 'json_schema',
            'json_schema' => $schema,
        );

        $result = self::invoke($messages, $options);
        $content = $result['content'] ?? '';
        $clean_json = self::extract_json($content);
        $parsed = json_decode($clean_json, true);

        if (json_last_error() === JSON_ERROR_NONE) {
            return $parsed;
        }

        // --- Retry: LLM returned non-JSON (common with Anthropic/Claude) ---
        // Prepend an explicit JSON-only instruction to force structured output.
        error_log(sprintf(
            '[PCM_LLM] invoke_json failed on first attempt (model=%s). Retrying with explicit JSON instruction. Raw preview: %s',
            $options['model'] ?? 'default',
            substr($content, 0, 200)
        ));

        $schema_hint = '';
        if (!empty($schema['schema']['properties'])) {
            $schema_hint = ' The JSON must contain these top-level keys: ' . implode(', ', array_keys($schema['schema']['properties'])) . '.';
        }

        // Inject a hard JSON constraint into the first system message
        $json_instruction = "CRITICAL: You MUST respond with ONLY valid JSON. No markdown, no explanations, no code blocks. Output raw JSON only.{$schema_hint}";

        $retry_messages = $messages;
        if (!empty($retry_messages[0]) && ($retry_messages[0]['role'] ?? '') === 'system') {
            $retry_messages[0]['content'] = $json_instruction . "\n\n" . $retry_messages[0]['content'];
        } else {
            array_unshift($retry_messages, array('role' => 'system', 'content' => $json_instruction));
        }

        $retry_result = self::invoke($retry_messages, $options);
        $retry_content = $retry_result['content'] ?? '';
        $retry_clean = self::extract_json($retry_content);
        $retry_parsed = json_decode($retry_clean, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException(
                sprintf('LLM returned invalid JSON after retry: %s — raw: %s', json_last_error_msg(), substr($retry_content, 0, 500))
            );
        }

        return $retry_parsed;
    }

    /**
     * Extract pure JSON from a string that might contain Markdown code blocks.
     * LLMs (especially Anthropic and sometimes Google) often wrap JSON in ```json ... ```
     * 
     * @param string $content Raw content from LLM.
     * @return string Extracted JSON string.
     */
    public static function extract_json(string $content): string
    {
        $content = trim($content);
        
        // Match content inside ```json ... ``` or just ``` ... ```
        if (preg_match('/```(?:json)?\s*(.*?)\s*```/is', $content, $matches)) {
            return trim($matches[1]);
        }
        
        // If no markdown block found, assume the whole string is JSON (or attempt to find first { or [)
        $start_obj = strpos($content, '{');
        $start_arr = strpos($content, '[');
        
        $start = false;
        if ($start_obj !== false && $start_arr !== false) {
            $start = min($start_obj, $start_arr);
        } elseif ($start_obj !== false) {
            $start = $start_obj;
        } elseif ($start_arr !== false) {
            $start = $start_arr;
        }
        
        if ($start !== false) {
            // Find the matching end character
            $end_char = $content[$start] === '{' ? '}' : ']';
            $end = strrpos($content, $end_char);
            
            if ($end !== false && $end > $start) {
                return substr($content, $start, $end - $start + 1);
            }
        }
        
        return $content;
    }

    /**
     * Invoke a Google Gemini model with Google Search grounding.
     *
     * The OpenAI-compatible endpoint does NOT support Google Search grounding.
     * This method uses the native Gemini `generateContent` REST API which
     * supports the `google_search` tool.
     *
     * Native endpoint: POST /v1beta/models/{model}:generateContent
     * Auth: x-goog-api-key header (not Bearer token)
     *
     * @param array $messages  Chat messages [['role' => '...', 'content' => '...'], ...]
     * @param array $options {
     *     @type string $model      Gemini model ID. Required.
     *     @type int    $max_tokens Max output tokens. Default DEFAULT_MAX_TOKENS.
     *     @type int    $user_id    User ID for API key lookup.
     * }
     *
     * @return array { content: string, usage: array, model: string, raw: array }
     * @throws \RuntimeException On API errors.
     */
    public static function invoke_with_grounding(array $messages, array $options = array()): array
    {
        $model = $options['model'] ?? 'gemini-2.5-flash';
        $max_tokens = $options['max_tokens'] ?? self::DEFAULT_MAX_TOKENS;
        $user_id = $options['user_id'] ?? get_current_user_id();

        // Resolve API key for Google provider
        $api_key = self::get_api_key('google', $user_id);

        // Build native Gemini generateContent URL
        $url = sprintf(
            'https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent',
            urlencode($model)
        );

        // Convert OpenAI-style messages to Gemini native format
        // Gemini uses: systemInstruction + contents[{role, parts[{text}]}]
        $system_text = '';
        $contents = array();

        foreach ($messages as $msg) {
            if ($msg['role'] === 'system') {
                $system_text .= ($system_text ? "\n" : '') . $msg['content'];
            }
            else {
                // Gemini uses 'user' and 'model' roles (not 'assistant')
                $role = $msg['role'] === 'assistant' ? 'model' : 'user';
                $contents[] = array(
                    'role' => $role,
                    'parts' => array(array('text' => $msg['content'])),
                );
            }
        }

        // Build the native Gemini payload
        $payload = array(
            'contents' => $contents,
            'tools' => array(
                    array('google_search' => new \stdClass()),
            ),
            'generationConfig' => array(
                'maxOutputTokens' => $max_tokens,
            ),
        );

        // Add system instruction if present
        if (!empty($system_text)) {
            $payload['systemInstruction'] = array(
                'parts' => array(array('text' => $system_text)),
            );
        }

        // Native Gemini API uses x-goog-api-key header (not Bearer)
        $headers = array(
            'Content-Type' => 'application/json',
            'x-goog-api-key' => $api_key,
        );

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log(sprintf('[PCM_LLM] Grounding request via native Gemini API — model: %s', $model));
        }

        // Execute the request
        $response = wp_remote_post($url, array(
            'body' => wp_json_encode($payload),
            'headers' => $headers,
            'timeout' => 120,
            'sslverify' => true,
        ));

        if (is_wp_error($response)) {
            throw new \RuntimeException(
                sprintf('Grounding API request failed: %s', $response->get_error_message())
                );
        }

        $status = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);

        if ($status < 200 || $status >= 300) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log(sprintf('[PCM_LLM] Grounding API error %d: %s', $status, substr($body, 0, 2000)));
            }
            throw new \RuntimeException(
                sprintf('Grounding API error %d: %s', $status, substr($body, 0, 1000))
                );
        }

        $data = json_decode($body, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException('Grounding API returned non-JSON response');
        }

        // Parse native Gemini response format:
        // { candidates: [{ content: { parts: [{ text: '...' }] } }] }
        $text = '';
        $candidates = $data['candidates'] ?? array();
        if (!empty($candidates)) {
            $parts = $candidates[0]['content']['parts'] ?? array();
            foreach ($parts as $part) {
                if (isset($part['text'])) {
                    $text .= $part['text'];
                }
            }
        }

        return array(
            'content' => $text,
            'usage' => $data['usageMetadata'] ?? array(),
            'model' => $model,
            'raw' => $data,
        );
    }

    // ========================================
    // Request executors
    // ========================================

    /**
     * Standard blocking HTTP request to LLM API.
     *
     * @param string $url     API endpoint URL.
     * @param array  $payload Request body.
     * @param array  $headers HTTP headers.
     *
     * @return array { content, usage, model, raw }
     */
    private static function blocking_request(string $url, array $payload, array $headers): array
    {
        $response = wp_remote_post($url, array(
            'body' => wp_json_encode($payload),
            'headers' => $headers,
            'timeout' => 120,
            'sslverify' => true,
        ));

        if (is_wp_error($response)) {
            throw new \RuntimeException(
                sprintf('LLM API request failed: %s', $response->get_error_message())
                );
        }

        $status = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);

        if ($status < 200 || $status >= 300) {
            // Log full error for debugging
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log(sprintf('[PCM_LLM] API error %d from %s: %s', $status, $url, substr($body, 0, 2000)));
            }
            throw new \RuntimeException(
                sprintf('LLM API error %d: %s', $status, substr($body, 0, 1000))
                );
        }

        $data = json_decode($body, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException('LLM API returned non-JSON response');
        }

        $parsed = self::parse_response($data);

        // [PCM_DIAG — remove after style inconsistency investigation]
        // Dump full response for objective analysis
        if (defined('WP_DEBUG') && WP_DEBUG) {
            self::write_diagnostic(array(
                'direction' => 'RESPONSE',
                'timestamp' => gmdate('Y-m-d H:i:s'),
                'model' => $parsed['model'] ?? 'unknown',
                'content' => $parsed['content'] ?? '',
                'usage' => $parsed['usage'] ?? null,
            ));
        }

        return $parsed;
    }

    /**
     * Streaming HTTP request using cURL directly (bypasses wp_remote_post).
     *
     * Reads SSE lines from the response and invokes the callback per content token.
     * Returns the full accumulated response when the stream ends.
     *
     * @param string   $url       API endpoint URL.
     * @param array    $payload   Request body (with stream: true).
     * @param array    $headers   HTTP headers.
     * @param callable $on_chunk  Callback: fn(string $token).
     *
     * @return array { content, usage, model, raw }
     */
    private static function stream_request(string $url, array $payload, array $headers, callable $on_chunk): array
    {
        $ch = curl_init($url);

        // Build header array for cURL
        $curl_headers = array();
        foreach ($headers as $key => $value) {
            $curl_headers[] = "$key: $value";
        }

        $accumulated_content = '';
        $model_name = '';

        // cURL write callback — processes SSE chunks in real-time
        $write_callback = function ($ch, $data) use (&$accumulated_content, &$model_name, $on_chunk) {
            // SSE format: lines starting with "data: "
            $lines = explode("\n", $data);

            foreach ($lines as $line) {
                $line = trim($line);

                if (empty($line) || strpos($line, 'data: ') !== 0) {
                    continue;
                }

                $json_str = substr($line, 6); // Remove "data: "

                if ($json_str === '[DONE]') {
                    continue;
                }

                $chunk = json_decode($json_str, true);
                if (!$chunk) {
                    continue;
                }

                // Extract model name from first chunk
                if (empty($model_name) && !empty($chunk['model'])) {
                    $model_name = $chunk['model'];
                }

                // Extract content delta
                $delta = $chunk['choices'][0]['delta']['content'] ?? '';
                if ($delta !== '') {
                    $accumulated_content .= $delta;
                    $on_chunk($delta);
                }
            }

            return strlen($data);
        };

        curl_setopt_array($ch, array(
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => wp_json_encode($payload),
            CURLOPT_HTTPHEADER => $curl_headers,
            CURLOPT_WRITEFUNCTION => $write_callback,
            CURLOPT_TIMEOUT => 120,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_RETURNTRANSFER => false,
        ));

        $result = curl_exec($ch);

        if ($result === false) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new \RuntimeException(sprintf('LLM streaming failed: %s', $error));
        }

        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($http_code < 200 || $http_code >= 300) {
            throw new \RuntimeException(
                sprintf('LLM streaming API error %d', $http_code)
                );
        }

        return array(
            'content' => $accumulated_content,
            'usage' => null, // Streaming responses don't always include usage
            'model' => $model_name,
            'raw' => null,
        );
    }

    // ========================================
    // Provider resolution
    // ========================================

    /**
     * Look up the API key for a provider from the integrations table.
     *
     * @param string $provider Provider ID (e.g. 'openai', 'google').
     * @param int    $user_id  WP user ID.
     *
     * @return string The API key.
     * @throws \RuntimeException If no active integration found.
     */
    private static function get_api_key(string $provider, int $user_id): string
    {
        global $wpdb;

        $table = PCM_Schema::table('integrations');
        $result = $wpdb->get_var($wpdb->prepare(
            "SELECT apiKey FROM $table WHERE provider = %s AND userId = %d AND isActive = 1 ORDER BY updatedAt DESC LIMIT 1",
            $provider,
            $user_id
        ));

        if (empty($result)) {
            throw new \RuntimeException(
                sprintf(
                'No active API key found for provider "%s". Please add one in Settings → Integrations.',
                $provider
            )
                );
        }

        return $result;
    }

    /**
     * Get the API endpoint URL for a provider.
     *
     * @param string $provider Provider ID.
     *
     * @return string Full endpoint URL.
     */
    private static function get_endpoint_url(string $provider): string
    {
        return self::$provider_endpoints[$provider]
            ?? self::$provider_endpoints['openai']; // Fallback to OpenAI-compatible
    }

    /**
     * Per-request cache for provider lookups.
     * Avoids repeated DB queries for the same model within one request.
     *
     * @var array<string, string>
     */
    private static array $provider_cache = array();

    /**
     * Auto-detect provider from model ID.
     *
     * Resolution order:
     *   1. Per-request cache (avoid repeated DB hits)
     *   2. DB lookup from pcm_models.provider (canonical source of truth)
     *   3. Prefix-based heuristic (fallback for unregistered/ad-hoc models)
     *
     * @param string $model_id Model identifier.
     *
     * @return string Provider ID ('google', 'openai', 'anthropic').
     */
    private static function detect_provider(string $model_id): string
    {
        // 1. Return cached result if available
        if (isset(self::$provider_cache[$model_id])) {
            return self::$provider_cache[$model_id];
        }

        // 2. DB lookup — pcm_models stores the authoritative provider per model
        global $wpdb;
        $table = PCM_Schema::table('models');
        $user_id = get_current_user_id();
        $db_provider = $wpdb->get_var($wpdb->prepare(
            "SELECT provider FROM {$table} WHERE modelId = %s AND userId = %d LIMIT 1",
            $model_id,
            $user_id
        ));

        if (!empty($db_provider)) {
            self::$provider_cache[$model_id] = $db_provider;
            return $db_provider;
        }

        // 3. Prefix-based heuristic — fallback for models not in the registry
        //    (e.g. built-in defaults, test models, or models used before registration)
        $provider = 'openai'; // default

        if (str_starts_with($model_id, 'gemini')
        || str_starts_with($model_id, 'imagen')
        || str_starts_with($model_id, 'deep-research')
        || str_starts_with($model_id, 'veo')
        || str_starts_with($model_id, 'learnlm')
        ) {
            $provider = 'google';
        }
        elseif (str_starts_with($model_id, 'claude')) {
            $provider = 'anthropic';
        }

        self::$provider_cache[$model_id] = $provider;
        return $provider;
    }

    /**
     * Public wrapper: detect provider from model ID.
     *
     * Used by external services (e.g. normalize_model_id) to check
     * which provider a model belongs to before selecting it.
     *
     * @param string $model_id Model identifier.
     * @return string Provider ID ('openai', 'google', 'anthropic').
     */
    public static function detect_provider_for(string $model_id): string
    {
        return self::detect_provider($model_id);
    }

    /**
     * Check if a provider has an active API key for the given user.
     *
     * Non-throwing alternative to get_api_key() — returns bool instead
     * of throwing RuntimeException. Used by normalize_model_id() to skip
     * models whose provider isn't configured.
     *
     * @param string $provider Provider ID (e.g. 'openai', 'google').
     * @param int    $user_id  WP user ID.
     * @return bool True if an active API key exists.
     */
    public static function has_api_key(string $provider, int $user_id): bool
    {
        try {
            self::get_api_key($provider, $user_id);
            return true;
        }
        catch (\RuntimeException) {
            return false;
        }
    }

    // ========================================
    // Message normalization
    // ========================================

    /**
     * Normalize messages to the OpenAI Chat Completions format.
     *
     * Handles:
     *   - Plain string content → kept as-is
     *   - Array content (multimodal) → normalized to [{ type, text/image_url }]
     *   - Image URLs → { type: "image_url", image_url: { url, detail } }
     *
     * @param array $messages Raw message array.
     *
     * @return array Normalized messages.
     */
    private static function normalize_messages(array $messages): array
    {
        $normalized = array();

        foreach ($messages as $msg) {
            $role = $msg['role'] ?? 'user';
            $content = $msg['content'] ?? '';

            // Simple string content — keep as-is
            if (is_string($content)) {
                $normalized[] = array('role' => $role, 'content' => $content);
                continue;
            }

            // Array content (multimodal) — normalize each part
            if (is_array($content)) {
                $parts = array();
                foreach ($content as $part) {
                    if (is_string($part)) {
                        $parts[] = array('type' => 'text', 'text' => $part);
                    }
                    elseif (isset($part['type'])) {
                        $parts[] = $part; // Already normalized
                    }
                }

                // If only one text part, collapse for compatibility
                if (count($parts) === 1 && $parts[0]['type'] === 'text') {
                    $normalized[] = array('role' => $role, 'content' => $parts[0]['text']);
                }
                else {
                    $normalized[] = array('role' => $role, 'content' => $parts);
                }
                continue;
            }

            // Fallback
            $normalized[] = array('role' => $role, 'content' => (string)$content);
        }

        return $normalized;
    }

    // ========================================
    // Anthropic format adapter
    // ========================================

    /**
     * Convert OpenAI-format payload to Anthropic Messages API format.
     *
     * Main differences:
     *   - System message goes in top-level `system` field, not in messages array
     *   - No `max_tokens` default — must be explicitly set
     *   - Content array uses `text` type (same) but image format differs
     *
     * @param array $payload OpenAI-format payload.
     *
     * @return array Anthropic-format payload.
     */
    private static function to_anthropic_format(array $payload): array
    {
        $system = '';
        $messages = array();

        foreach ($payload['messages'] as $msg) {
            if ($msg['role'] === 'system') {
                // Anthropic: system message is a top-level field
                $system .= (is_string($msg['content']) ? $msg['content'] : wp_json_encode($msg['content'])) . "\n";
            }
            else {
                $messages[] = $msg;
            }
        }

        $anthropic_payload = array(
            'model' => $payload['model'],
            'max_tokens' => $payload['max_tokens'] ?? self::DEFAULT_MAX_TOKENS,
            'messages' => $messages,
        );

        if (!empty(trim($system))) {
            $anthropic_payload['system'] = trim($system);
        }

        if (!empty($payload['stream'])) {
            $anthropic_payload['stream'] = true;
        }

        return $anthropic_payload;
    }

    // ========================================
    // Schema helpers
    // ========================================

    /**
     * Recursively ensure all objects in a JSON schema have additionalProperties: false.
     *
     * OpenAI Structured Outputs (response_format: json_schema) requires this
     * on every object. Without it the API returns a 400.
     *
     * Works on the full response_format array, traversing into
     * json_schema → schema → properties → items, etc.
     *
     * @param array|mixed $node Any part of the response_format / schema tree.
     *
     * @return array|mixed The node with additionalProperties injected.
     */
    private static function ensure_additional_properties(mixed $node): mixed
    {
        if (!is_array($node)) {
            return $node;
        }

        // If this node describes a JSON Schema object, add the flag
        if (isset($node['type']) && $node['type'] === 'object' && !array_key_exists('additionalProperties', $node)) {
            $node['additionalProperties'] = false;
        }

        // Recurse into schema (top-level json_schema wrapper)
        if (isset($node['schema']) && is_array($node['schema'])) {
            $node['schema'] = self::ensure_additional_properties($node['schema']);
        }

        // Recurse into json_schema wrapper
        if (isset($node['json_schema']) && is_array($node['json_schema'])) {
            $node['json_schema'] = self::ensure_additional_properties($node['json_schema']);
        }

        // Recurse into properties (each property can be a sub-schema)
        if (isset($node['properties']) && is_array($node['properties'])) {
            foreach ($node['properties'] as $key => $prop) {
                $node['properties'][$key] = self::ensure_additional_properties($prop);
            }
        }

        // Recurse into items (for arrays of objects)
        if (isset($node['items']) && is_array($node['items'])) {
            $node['items'] = self::ensure_additional_properties($node['items']);
        }

        // Recurse into anyOf / oneOf / allOf
        foreach (['anyOf', 'oneOf', 'allOf'] as $combinator) {
            if (isset($node[$combinator]) && is_array($node[$combinator])) {
                foreach ($node[$combinator] as $i => $sub) {
                    $node[$combinator][$i] = self::ensure_additional_properties($sub);
                }
            }
        }

        return $node;
    }

    // ========================================
    // Response parsing
    // ========================================

    /**
     * Parse an OpenAI-compatible response into a standard result format.
     *
     * @param array $data Raw API response.
     *
     * @return array { content: string, usage: array|null, model: string, raw: array }
     */
    private static function parse_response(array $data): array
    {
        $content = '';

        // OpenAI format: choices[0].message.content
        if (isset($data['choices'][0]['message']['content'])) {
            $content = $data['choices'][0]['message']['content'];
        }
        // Anthropic format: content[0].text
        elseif (isset($data['content'][0]['text'])) {
            $content = $data['content'][0]['text'];
        }

        return array(
            'content' => $content,
            'usage' => $data['usage'] ?? null,
            'model' => $data['model'] ?? '',
            'raw' => $data,
        );
    }

    // ========================================
    // Diagnostic helpers
    // ========================================

    /**
     * [PCM_DIAG — remove after style inconsistency investigation]
     *
     * Write a diagnostic entry to a JSONL file for objective analysis.
     * Each entry is one JSON line with full request or response data.
     *
     * File: wp-content/uploads/pcm-diagnostics/diag-YYYY-MM-DD.jsonl
     *
     * @param array $entry Diagnostic data to write.
     */
    public static function write_diagnostic(array $entry): void
    {
        $upload_dir = wp_upload_dir();
        $diag_dir = $upload_dir['basedir'] . '/pcm-diagnostics';

        // Create directory if it doesn't exist
        if (!is_dir($diag_dir)) {
            wp_mkdir_p($diag_dir);
        }

        $file = $diag_dir . '/diag-' . gmdate('Y-m-d') . '.jsonl';
        $line = wp_json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";

        file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
    }
}
