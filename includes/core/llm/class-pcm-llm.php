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
     * @return array Parsed response: { content: string, usage: array, model: string,
     *   finish_reason: string, refusal: string, raw: array }. `refusal` is the
     *   model's own reason for declining (empty when it did not) — see
     *   parse_response(); a NON-empty refusal is terminal, not retryable.
     * @throws \RuntimeException On API key missing, HTTP error, or parse failure.
     */
    /**
     * Site-wide default for the `web` option (owner card 14: "every call we do should
     * be web-enabled"): ON unless the kill-switch option `pcm_llm_web_search` is '0'.
     * Long-form, site-reading generations pass this; short scalar fields (a title, a
     * meta description, ×500 in a bulk run) deliberately do not — nothing to browse
     * for, and the web tools bill per call.
     */
    public static function web_default(): bool
    {
        if (function_exists('get_option') && (string) get_option('pcm_llm_web_search', '1') === '0') {
            return false; // operator kill-switch (no UI)
        }
        // The UI switch: Settings → Model Registry → "Web-enabled generation" (absent = on).
        if (class_exists('PCM_Settings')) {
            $v = PCM_Settings::get('llm_web_search', true);
            if ($v === false || $v === 0 || $v === '0' || $v === 'false') {
                return false;
            }
        }
        return true;
    }

    public static function invoke(array $messages, array $options = array()): array
    {
        // Resolve model — handle both null and empty string gracefully.
        // PHP's ?? only triggers for undefined keys, NOT for explicit null values.
        // Callers may pass 'model' => null when no override is selected.
        $model = !empty($options['model']) ? $options['model'] : self::DEFAULT_MODEL;
        $provider = $options['provider'] ?? self::detect_provider($model);
        $max_tokens = $options['max_tokens'] ?? self::DEFAULT_MAX_TOKENS;

        // Clamp to a previously discovered safe cap for this model, if smaller —
        // avoids repeating the same context-overflow 400 on every call.
        $remembered_cap = self::remembered_token_cap($model);
        if ($remembered_cap !== null && $remembered_cap < $max_tokens) {
            $max_tokens = $remembered_cap;
        }

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
        // - OpenAI: newer models (o-series, GPT-5, recent gpt-4.x snapshots) REJECT
        //   `max_tokens` and require `max_completion_tokens`; it's accepted by every
        //   current OpenAI chat model, so use it universally for OpenAI.
        // - Google Gemini 2.5 "thinking" models generate hidden reasoning tokens that
        //   consume the budget — `max_completion_tokens` caps only visible output.
        if ($provider === 'google' || $provider === 'openai') {
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

        // ── WEB-ENABLED generation (owner card 14: "the best way is if the model
        //    actually has web enabled so it can actually read site stuff … every
        //    call we do should be web-enabled"). `web => true` turns on each
        //    provider's OWN live-web tool, so the model can open {{website.url}},
        //    the source post, competitors — instead of writing from memory:
        //      · OpenAI    → the Responses API with the `web_search_preview` tool
        //                    (chat/completions has no web tool for ordinary models)
        //      · Anthropic → the `web_search_20250305` server tool on /v1/messages
        //      · Google    → native generateContent with `google_search` grounding
        //                    (the OpenAI-compatible endpoint cannot ground)
        //    Streaming callers keep the plain path (the web tools stream
        //    differently and nothing here streams long-form articles). Any other
        //    provider ignores the flag rather than failing the call. ──
        $web = !empty($options['web']) && !is_callable($on_chunk);
        if ($web && $provider === 'openai') {
            return self::invoke_openai_responses($payload, $api_key, $options);
        }
        if ($web && $provider === 'google') {
            // The grounded path takes the ORIGINAL messages; response_format cannot
            // ride along with grounding, so JSON comes from the prompt — invoke_json()'s
            // tiers already tolerate that (its parser accepts fenced/prose-wrapped JSON).
            $grounded = self::invoke_with_grounding($messages, array(
                'model'      => $model,
                'max_tokens' => $max_tokens,
                'user_id'    => $user_id,
                'timeout'    => (int) ($options['timeout'] ?? 300),
            ));
            return $grounded + array('finish_reason' => '', 'refusal' => '');
        }
        if ($web && $provider === 'anthropic') {
            $payload['web'] = true; // to_anthropic_format() turns this into the server tool
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

        try {
            return self::blocking_request($base_url, $payload, $headers, max(30, (int) ($options['timeout'] ?? 300)));
        } catch (\RuntimeException $e) {
            // Token-budget overflow: the requested completion budget is too large
            // for the model's total context window OR its per-response output cap.
            // The default/strategy max_tokens (8192–16384) overflows small-context
            // models (e.g. an 8k-context gpt-4). Reduce the completion budget to
            // the model's own stated limit and retry ONCE. Covers, all confirmed
            // live: OpenAI context_length_exceeded, OpenAI "max_tokens is too
            // large" output cap, and Anthropic "max_tokens: X > Y" output cap.
            $token_key = isset($payload['max_completion_tokens']) ? 'max_completion_tokens' : 'max_tokens';
            $current   = (int) ($payload[$token_key] ?? 0);
            $budget    = self::reduced_token_budget($e->getMessage());

            if ($budget === null || $budget <= 0 || ($current > 0 && $budget >= $current)) {
                throw $e; // not a token-budget error, or reducing wouldn't help — propagate
            }

            error_log(sprintf(
                '[PCM_LLM] token-budget overflow; retrying with %s=%d (was %d). (%s)',
                $token_key,
                $budget,
                $current,
                substr($e->getMessage(), 0, 160)
            ));
            $payload[$token_key] = $budget;
            $retry_result = self::blocking_request($base_url, $payload, $headers, max(30, (int) ($options['timeout'] ?? 300)));
            self::remember_token_cap($model, $budget);
            return $retry_result;
        }
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
        $model = !empty($options['model']) ? $options['model'] : self::DEFAULT_MODEL;

        // Structured JSON is attempted in descending order of fidelity, falling
        // through to the next tier whenever a model rejects the parameter (400)
        // OR returns content we can't parse:
        //   1. json_schema — native Structured Outputs (strict, schema-enforced).
        //      Best, but only newer OpenAI/Google models support it.
        //   2. json_object — API-GUARANTEED valid JSON syntax, supported by a far
        //      wider set of models (gpt-4-turbo, gpt-4o, gpt-3.5-1106+, …). The
        //      API handles escaping, so this is reliable for large HTML content
        //      where prompt-only output would intermittently emit unescaped
        //      control chars and fail to parse.
        //   3. prompt-only — no response_format at all; last resort for models
        //      with no JSON mode (some proxied/self-hosted endpoints).

        // ── Tier 1: json_schema ──
        // Skipped entirely when this model has previously rejected it — avoids
        // repeating the same rejected-parameter 400 on every call.
        if (!self::json_schema_unsupported_remembered($model)) {
            $schema_opts = $options;
            $schema_opts['response_format'] = array('type' => 'json_schema', 'json_schema' => $schema);
            try {
                $schema_result = self::invoke($messages, $schema_opts);
                // A refusal here is terminal — see throw_if_refused(). Checked
                // BEFORE parse_json_result(), which reads only `content` and so
                // would drop the reason and fall through to tier 2.
                self::throw_if_refused($schema_result);
                $parsed = self::parse_json_result($schema_result);
                if ($parsed !== null) {
                    return $parsed;
                }
                error_log(sprintf('[PCM_LLM] json_schema parse failed (model=%s); trying json_object.', $model));
            } catch (\RuntimeException $e) {
                // A refusal must never be re-read by the format classifier: the
                // model's own words can contain "response_format … not
                // supported" and would then demote the tier and re-ask a
                // question already answered.
                if (self::is_refusal_error($e->getMessage())
                    || !self::is_response_format_unsupported($e->getMessage())
                ) {
                    throw $e; // genuine error (bad key, rate limit, context length) — propagate
                }
                self::remember_json_schema_unsupported($model);
                error_log(sprintf('[PCM_LLM] model=%s rejected json_schema; trying json_object. (%s)', $model, substr($e->getMessage(), 0, 160)));
            }
        }

        // ── Tier 2: json_object (API-enforced valid JSON) ──
        try {
            return self::invoke_json_fallback($messages, $schema, $options, true);
        } catch (\RuntimeException $e) {
            if (self::is_refusal_error($e->getMessage())
                || !self::is_response_format_unsupported($e->getMessage())
            ) {
                throw $e; // json_object parse failure (rare — truncation) is fatal, not a retry
            }
            error_log(sprintf('[PCM_LLM] model=%s rejected json_object; falling back to prompt-only. (%s)', $model, substr($e->getMessage(), 0, 160)));
        }

        // ── Tier 3: prompt-only ──
        return self::invoke_json_fallback($messages, $schema, $options, false);
    }

    /**
     * Parse an invoke() result's content into JSON, tolerating markdown fences /
     * surrounding prose via extract_json(). Returns null (not an exception) when
     * the content isn't valid JSON, so callers can fall through to another tier.
     *
     * @param array $result invoke() return value.
     * @return array|null Parsed JSON, or null if unparseable.
     */
    private static function parse_json_result(array $result): ?array
    {
        $raw    = self::extract_json($result['content'] ?? '');
        $parsed = json_decode($raw, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($parsed)) {
            return $parsed;
        }
        // Mechanical repair (in-string control chars, trailing commas) before
        // giving up on this tier — see repair_json().
        return self::repair_json($raw);
    }

    /**
     * Fallback JSON generation for models that don't support json_schema. Prepends
     * a hard JSON-only instruction (which both satisfies json_object mode's "the
     * prompt must mention json" requirement AND steers prompt-only output) plus a
     * top-level-keys hint, then invokes either with `response_format: json_object`
     * (API-enforced valid JSON — preferred) or with no response_format at all.
     *
     * @param array $messages       Original messages.
     * @param array $schema         The json_schema wrapper (for the keys hint).
     * @param array $options        Invoke options.
     * @param bool  $use_json_object True → request json_object mode; false → prompt-only.
     *
     * @return array Parsed JSON.
     * @throws \RuntimeException On a rejected response_format (caller falls through)
     *   or genuinely unparseable output.
     */
    private static function invoke_json_fallback(array $messages, array $schema, array $options, bool $use_json_object): array
    {
        $schema_hint = '';
        if (!empty($schema['schema']['properties']) && is_array($schema['schema']['properties'])) {
            $schema_hint = ' The JSON must contain these top-level keys: '
                . implode(', ', array_keys($schema['schema']['properties'])) . '.';
        }
        $json_instruction = 'CRITICAL: Respond with ONLY a single valid JSON object — no markdown, no code fences, no commentary.' . $schema_hint;

        $msgs = $messages;
        if (!empty($msgs[0]) && ($msgs[0]['role'] ?? '') === 'system') {
            $msgs[0]['content'] = $json_instruction . "\n\n" . $msgs[0]['content'];
        } else {
            array_unshift($msgs, array('role' => 'system', 'content' => $json_instruction));
        }

        if ($use_json_object) {
            $options['response_format'] = array('type' => 'json_object');
        } else {
            unset($options['response_format']);
        }

        $result = self::invoke($msgs, $options);
        // Fail fast on a decline, before the truncation retry and the reprompt
        // both re-ask (and both overwrite $result, losing the reason).
        self::throw_if_refused($result);
        $content = $result['content'] ?? '';
        $parsed = json_decode(self::extract_json($content), true);

        // Truncation rescue. The output hit the token cap mid-JSON — an
        // unterminated string / unclosed braces that json_decode can't parse
        // by construction. We detect this TWO ways: the API's own
        // finish_reason='length' flag AND a structural look_truncated() scan —
        // because Gemini's OpenAI-compat endpoint frequently truncates a long
        // article WITHOUT setting finish_reason (the observed live failure:
        // "Syntax error" on long listicles). Either signal → one retry with
        // double the budget; a second truncation at the doubled budget is a
        // genuine oversize and falls through to the reprompt/error below.
        if ((json_last_error() !== JSON_ERROR_NONE || !is_array($parsed))
            && (($result['finish_reason'] ?? '') === 'length' || self::looks_truncated(self::extract_json($content)))
            && empty($options['pcm_truncation_retry'])
        ) {
            $bumped = $options;
            $bumped['pcm_truncation_retry'] = true;
            $bumped['max_tokens'] = max(1024, (int) ($options['max_tokens'] ?? 4096)) * 2;
            error_log(sprintf(
                '[PCM_LLM] JSON output truncated (model=%s, finish_reason=%s); retrying once with max_tokens=%d.',
                (string) ($result['model'] ?? ''),
                (string) ($result['finish_reason'] ?? ''),
                $bumped['max_tokens']
            ));
            $result = self::invoke($msgs, $bumped);
            // The retry can itself come back a refusal — guard it too, or the
            // reason is overwritten by whatever the reprompt returns and the
            // model's own words reach the tier classifier downstream.
            self::throw_if_refused($result);
            $content = $result['content'] ?? '';
            $parsed = json_decode(self::extract_json($content), true);
        }

        // Mechanical repair: Gemini's known decode-fatal quirks (raw control
        // chars inside string values, trailing commas) are fixable without an
        // extra API call — try that before spending a reprompt.
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($parsed)) {
            $repaired = self::repair_json(self::extract_json($content));
            if ($repaired !== null) {
                error_log(sprintf(
                    '[PCM_LLM] Invalid JSON mechanically repaired (model=%s, %s mode).',
                    (string) ($result['model'] ?? ''),
                    $use_json_object ? 'json_object' : 'prompt-only'
                ));
                return $repaired;
            }
        }

        // Corrective reprompt: the output is broken in a way neither the
        // truncation retry nor repair_json() could fix — unescaped quotes,
        // stray prose, or an article STILL too long even after the doubled
        // budget. One retry that (a) tells the model exactly what broke and
        // (b) carries a raised token budget, so a reprompt of a long article
        // isn't re-truncated at the same cap. The pcm_json_reprompt guard
        // keeps this from ever looping.
        if ((json_last_error() !== JSON_ERROR_NONE || !is_array($parsed))
            && empty($options['pcm_json_reprompt'])
        ) {
            error_log(sprintf(
                '[PCM_LLM] Invalid JSON (model=%s, %s): reprompting once with the parse error.',
                (string) ($result['model'] ?? ''),
                json_last_error_msg()
            ));
            $opts2 = $options;
            $opts2['pcm_json_reprompt'] = true;
            // Raise the ceiling — the failure may have been budget-starved.
            $opts2['max_tokens'] = max(16384, (int) ($options['max_tokens'] ?? 4096) * 2);
            if ($use_json_object) {
                $opts2['response_format'] = array('type' => 'json_object');
            }
            $msgs2   = $msgs;
            $msgs2[] = array(
                'role'    => 'user',
                'content' => 'Your previous reply was not valid JSON (' . json_last_error_msg()
                    . '). Reply again with ONLY the complete, valid JSON object'
                    . $schema_hint . ' No markdown, no code fences, no commentary.',
            );
            $result  = self::invoke($msgs2, $opts2);
            self::throw_if_refused($result);
            $content = $result['content'] ?? '';
            $parsed  = json_decode(self::extract_json($content), true);
            if (json_last_error() !== JSON_ERROR_NONE || !is_array($parsed)) {
                $parsed = self::repair_json(self::extract_json($content));
                if ($parsed !== null) {
                    return $parsed;
                }
                $parsed = null; // fall through to the terminal error below
            } else {
                return $parsed;
            }
        }

        // Schema-aware salvage: the standard parse, the mechanical repair, AND
        // the reprompt all failed — most often an unescaped inner quote in a
        // large HTML `content` string that positional repair can't fix. As a
        // final rescue, reconstruct the known string fields directly from their
        // `"key":` markers.
        //
        // Guard on STRUCTURAL COMPLETENESS, not looks_truncated(): an unescaped
        // inner quote throws off looks_truncated()'s quote counting (an odd
        // count reads as "still in a string" → false-positive truncated),
        // which would wrongly block salvage on the very payloads it targets. A
        // genuinely truncated reply was cut mid-value and does NOT end in `}`;
        // a complete-but-malformed reply does. That closing brace is what
        // salvage needs to bound the final field, so it is the right gate.
        // Freeze the parse OUTCOME and its message BEFORE extract_json() —
        // that function probes candidate spans with json_decode(), so reading
        // the global afterwards can report an unrelated slice's "Syntax error"
        // instead of the real reason (e.g. "Malformed UTF-8"), and can even
        // flip a SUCCESSFUL parse into the failure branches below.
        $parse_failed = (json_last_error() !== JSON_ERROR_NONE || !is_array($parsed));
        $last_error   = json_last_error_msg();
        $salvage_src  = rtrim(self::extract_json($content));
        if ($parse_failed
            && ($result['finish_reason'] ?? '') !== 'length'
            && $salvage_src !== ''
            && substr($salvage_src, -1) === '}'
        ) {
            list($string_keys, $null_keys) = self::schema_key_partition($schema);
            if ($string_keys !== array()) {
                $salvaged = self::salvage_json_by_keys($salvage_src, $string_keys, $null_keys);
                if (is_array($salvaged)) {
                    error_log(sprintf(
                        '[PCM_LLM] Invalid JSON salvaged field-by-field from the schema keys (model=%s, %s mode).',
                        (string) ($result['model'] ?? ''),
                        $use_json_object ? 'json_object' : 'prompt-only'
                    ));
                    return $salvaged;
                }
            }
        }

        if ($parse_failed) {
            throw new \RuntimeException(sprintf(
                'LLM returned invalid JSON (%s mode)%s: %s — raw: %s',
                $use_json_object ? 'json_object' : 'prompt-only',
                self::describe_json_failure(
                    ($result['finish_reason'] ?? '') === 'length',
                    $salvage_src,
                    (string) ($result['refusal'] ?? ''),
                    (string) ($result['finish_reason'] ?? '')
                ),
                $last_error,
                substr($content, 0, 500)
            ));
        }

        return $parsed;
    }

    /**
     * Human-readable cause for the terminal "invalid JSON" error.
     *
     * WHY THIS EXISTS: keying the message off `finish_reason` alone made a
     * TRUNCATED reply and a merely malformed one read as the same opaque error
     * in the UI — the same failure has now been reported three times with no
     * diagnosable cause. Gemini's OpenAI-compat endpoint routinely truncates a
     * long article WITHOUT setting finish_reason (the truncation rescue above
     * already trusts looks_truncated() for exactly that), so the structure has
     * to be consulted too.
     *
     * BUT looks_truncated() is only trustworthy when the text is ALSO
     * structurally incomplete. It tracks quote parity, so a payload carrying an
     * unescaped inner quote reads as "still inside a string" and reports a
     * false positive — and that is precisely the payload class that reaches
     * this error (salvage refused it). Claiming "TRUNCATED, the article is too
     * long" there would send the reader after a token budget that is not the
     * problem, which is worse than the vague message it replaced. So the shape
     * signal counts only when the reply does not close with `}` — the same
     * completeness test the salvage gate uses, and for the same reason.
     *
     * Pure/deterministic — directly unit-testable (LlmJsonRepairTest).
     *
     * @param bool   $by_finish   Whether the API reported finish_reason='length'.
     * @param string $salvage_src The extracted JSON candidate (already rtrim'd).
     * @param string $refusal     The model's refusal text when it declined to
     *                            generate. Takes priority over every other
     *                            branch: the API's own reason for the empty
     *                            reply beats token-budget / shape heuristics.
     * @param string $finish_reason Raw finish_reason, named in the EMPTY-reply
     *                            clause so a content_filter / provider quirk is
     *                            distinguishable from a plain empty completion.
     * @return string Detail clause appended to the error message.
     */
    public static function describe_json_failure(bool $by_finish, string $salvage_src, string $refusal = '', string $finish_reason = ''): string
    {
        // A refusal is the API's explicit reason the reply is empty — it is
        // not a token-budget or malformed-output problem, so the branches
        // below must not re-label it as TRUNCATED or EMPTY. Tested before them
        // so the operator sees the real cause instead of an opaque message.
        if ($refusal !== '') {
            return ' — the model REFUSED to generate this content: '
                . self::clip($refusal, 300);
        }

        // finish_reason is the only NON-heuristic signal among the remaining
        // branches, so it is tested before the shape heuristics below.
        // Short-circuiting on an empty span ahead of it threw the signal away
        // and reported "nothing came back" for a reply the API had explicitly
        // flagged as cut at the token limit — a regression against the message
        // this function replaced.
        if ($by_finish) {
            return ' — output TRUNCATED at the token limit (detected via finish_reason=length);'
                . ' the article is too long for the budget';
        }

        if ($salvage_src === '') {
            // extract_json() returns its input unchanged when it finds no
            // braces, so an empty span means the reply itself was empty or
            // whitespace — NOT merely "unparseable".
            //
            // The raw finish_reason is appended because a refusal is only ONE
            // way to get an empty reply: a content filter
            // (finish_reason=content_filter) or a provider quirk produces a
            // byte-identical message with no refusal field, and without this
            // the operator cannot tell them apart — exactly the dead end this
            // whole change removes. NB $by_finish is always false here (it
            // returns above), so the boolean is useless for this — the string is
            // what carries the signal.
            return ' — the model returned an EMPTY reply'
                . ($finish_reason !== '' ? ' (finish_reason=' . self::clip($finish_reason, 40) . ')' : '');
        }

        // Accept `]` as well as `}`: extract_json() can hand back an

        // array-rooted span, and treating a complete array as "incomplete"
        // would re-open the quote-parity false positive for that root.
        $last       = substr($salvage_src, -1);
        $incomplete = $last !== '}' && $last !== ']';

        if ($incomplete && self::looks_truncated($salvage_src)) {
            return ' — output TRUNCATED at the token limit (detected via an unterminated JSON'
                . ' structure); the article is too long for the budget';
        }

        // Hedged on purpose, and the hedge is load-bearing. Two truncation
        // shapes slip past the gate above: a reply cut right after a nested
        // object closes still ends in `}`, and one cut inside a nested array
        // still ends in `]`. Neither can be caught by counting depth instead —
        // an unescaped inner quote corrupts the scan's string state, so the
        // depth count is no more trustworthy than the quote parity on exactly
        // the payloads that reach here. So this asserts only that NO truncation
        // signal was found, never that the reply was complete.
        return ' — no truncation signal detected; output appears complete but unparseable'
            . ' (repair, reprompt and schema-key salvage all failed)';
    }

    /** Marks a refusal so the tier classifier can recognise it — see is_refusal_error(). */
    private const REFUSAL_PREFIX = 'LLM refused to generate this content: ';

    /**
     * Throw immediately when the model declined.
     *
     * WHY FAIL FAST: a refusal is about CONTENT, so every downstream escalation
     * refuses again — measured at 4 API calls (json_schema → json_object →
     * doubled-budget truncation retry → 16k reprompt), up to 7 when the refusal
     * text happens to trip the format classifier. Worse, each retry REPLACES
     * `$result`, so the one field explaining the failure was overwritten by a
     * later empty reply and the operator got the same unexplained "EMPTY reply"
     * this whole change exists to end. The truncation retry fires on a refusal
     * because looks_truncated('') is true, so it cannot be avoided downstream.
     *
     * @param array $result invoke()'s return value.
     * @throws \RuntimeException When the reply carries a refusal.
     */
    private static function throw_if_refused(array $result): void
    {
        $refusal = trim((string) ($result['refusal'] ?? ''));
        if ($refusal === '') {
            return;
        }
        // A refusal is terminal ONLY when there is nothing usable to keep.
        // OpenAI pairs `refusal` with content:null, but a compat proxy (and
        // Anthropic's mid-generation stop_reason=refusal) can return a partial
        // decline ALONGSIDE a usable body — aborting that would throw away an
        // article we already have and were previously happy to use. Deliberate:
        // if the content parses downstream, the decline is advisory.
        if (trim((string) ($result['content'] ?? '')) !== '') {
            error_log(sprintf(
                '[PCM_LLM] Model returned a refusal ALONGSIDE usable content (model=%s) — using the content: %s',
                (string) ($result['model'] ?? ''),
                self::clip($refusal, 200)
            ));
            return;
        }
        error_log(sprintf(
            '[PCM_LLM] Model refused (model=%s): %s',
            (string) ($result['model'] ?? ''),
            self::clip($refusal, 300)
        ));
        throw new \RuntimeException(self::REFUSAL_PREFIX . self::clip($refusal, 300));
    }

    /**
     * Whether an exception message is one of ours from throw_if_refused().
     * Checked BEFORE is_response_format_unsupported() at every tier boundary:
     * the refusal text is model-authored and can itself contain phrases like
     * "response_format is not supported", which would otherwise demote the tier
     * and re-ask a question the model has already answered.
     *
     * @param string $message Exception message.
     * @return bool
     */
    private static function is_refusal_error(string $message): bool
    {
        return strpos($message, self::REFUSAL_PREFIX) === 0;
    }

    /**
     * Multibyte-safe clip. substr() can split a UTF-8 sequence, and the result
     * is persisted as strategy_items.errorMessage and returned over REST, where
     * wp_json_encode() drops an invalid-UTF-8 string entirely — losing the very
     * message being clipped.
     *
     * @param string $text Text to clip.
     * @param int    $max  Maximum characters.
     * @return string
     */
    private static function clip(string $text, int $max): string
    {
        return function_exists('mb_substr') ? mb_substr($text, 0, $max) : substr($text, 0, $max);
    }

    /**
     * Whether an API error message indicates the model doesn't accept the
     * response_format / json_schema Structured Outputs parameter — a 400 that
     * should trigger a prompt-only fallback rather than a hard failure. Kept
     * narrow (must mention the parameter AND an unsupported phrasing) so genuine
     * errors (bad key, rate limit, etc.) still propagate.
     *
     * @param string $message API error message.
     * @return bool
     */
    private static function is_response_format_unsupported(string $message): bool
    {
        $mentions_param = stripos($message, 'response_format') !== false
            || stripos($message, 'json_schema') !== false
            || stripos($message, 'structured output') !== false;

        if (!$mentions_param) {
            return false;
        }

        return stripos($message, 'not supported') !== false
            || stripos($message, 'unsupported') !== false
            || stripos($message, 'does not support') !== false
            || stripos($message, 'invalid parameter') !== false;
    }

    /**
     * From a token-budget API error, derive a safe completion-token budget to
     * retry with — or null if the error isn't a recognizable token-limit error.
     * Handles three confirmed-live formats:
     *   - OpenAI context overflow: "maximum context length is 8192 tokens ...
     *     (300 in the messages, 8192 in the completion)" → context − prompt − margin.
     *   - OpenAI output cap: "This model supports at most 4096 completion tokens" → 4096.
     *   - Anthropic output cap: "max_tokens: 200000 > 128000, which is the
     *     maximum allowed number of output tokens ..." → 128000.
     *
     * @param string $message API error message.
     * @return int|null Completion-token budget to retry with, or null.
     */
    private static function reduced_token_budget(string $message): ?int
    {
        // OpenAI: total-context overflow — leave the measured prompt intact and
        // fit the completion into what remains (minus a small margin for role/
        // formatting tokens the estimate doesn't include).
        if (preg_match('/maximum context length is\s+(\d+)\s+tokens/i', $message, $m)) {
            $max_context = (int) $m[1];
            $prompt = 0;
            if (preg_match('/(\d+)\s+in the messages/i', $message, $mm)) {
                $prompt = (int) $mm[1];
            }
            $budget = $max_context - $prompt - 256;
            return $budget > 0 ? $budget : null;
        }

        // OpenAI: per-response output cap.
        if (preg_match('/supports at most\s+(\d+)\s+completion tokens/i', $message, $m)) {
            return (int) $m[1];
        }

        // Anthropic: per-response output cap ("max_tokens: X > Y ...").
        if (preg_match('/max_tokens:\s*\d+\s*>\s*(\d+)/i', $message, $m)) {
            return (int) $m[1];
        }

        return null;
    }

    // ========================================
    // Model limit memory (transient-backed)
    // ========================================

    /**
     * Read a previously remembered safe token-budget cap for a model.
     *
     * Lets invoke() skip straight to a known-safe max_tokens instead of
     * repeating the same context-overflow 400 on every call for this model.
     *
     * @param string $model Model ID.
     * @return int|null Remembered cap, or null if none recorded (or WP isn't loaded).
     */
    private static function remembered_token_cap(string $model): ?int
    {
        if (!function_exists('get_transient')) {
            return null;
        }

        $cap = get_transient('pcm_llm_tokcap_' . md5($model));
        return (is_numeric($cap) && (int) $cap > 0) ? (int) $cap : null;
    }

    /**
     * Remember a safe token-budget cap for a model for future invocations.
     *
     * @param string $model  Model ID.
     * @param int    $budget Token budget that succeeded on retry.
     * @return void
     */
    private static function remember_token_cap(string $model, int $budget): void
    {
        if (!function_exists('set_transient')) {
            return;
        }

        $expiry = defined('WEEK_IN_SECONDS') ? WEEK_IN_SECONDS : 604800;
        set_transient('pcm_llm_tokcap_' . md5($model), $budget, $expiry);
    }

    /**
     * Whether this model has previously rejected response_format: json_schema.
     *
     * Lets invoke_json() skip straight to the json_object/prompt-only fallback
     * instead of repeating the same rejected-parameter 400 on every call.
     *
     * @param string $model Model ID.
     * @return bool True if remembered as unsupported.
     */
    private static function json_schema_unsupported_remembered(string $model): bool
    {
        if (!function_exists('get_transient')) {
            return false;
        }

        return (bool) get_transient('pcm_llm_nojschema_' . md5($model));
    }

    /**
     * Remember that this model rejects response_format: json_schema.
     *
     * @param string $model Model ID.
     * @return void
     */
    private static function remember_json_schema_unsupported(string $model): void
    {
        if (!function_exists('set_transient')) {
            return;
        }

        $expiry = defined('WEEK_IN_SECONDS') ? WEEK_IN_SECONDS : 604800;
        set_transient('pcm_llm_nojschema_' . md5($model), 1, $expiry);
    }

    /**
     * Extract pure JSON from a string that might contain Markdown code blocks.
     * LLMs (especially Anthropic and sometimes Google) often wrap JSON in ```json ... ```
     * 
     * @param string $content Raw content from LLM.
     * ⚠ CLOBBERS `json_last_error()`. This probes its candidate spans with
     * json_decode(), so the global parse-error state after calling this
     * reflects an internal probe, NOT the caller's own last decode. Callers
     * that report or branch on json_last_error()/json_last_error_msg() must
     * capture it BEFORE calling extract_json() (invoke_json_fallback() does).
     *
     * @return string Extracted JSON string.
     */
    public static function extract_json(string $content): string
    {
        $content = trim($content);

        // Strip a surrounding markdown fence. Two competing shapes exist and
        // neither regex wins both, so try the narrow one first and only widen
        // when it fails to produce JSON:
        //   - TWO separate fenced blocks (a JSON block then a usage sample):
        //     non-greedy is right; greedy would weld them together.
        //   - ONE block whose article content itself contains a ``` fence:
        //     non-greedy stops at that inner fence and yields a fragment cut
        //     mid-string, which then defeats every downstream tier (the
        //     salvage gate needs a closing brace); greedy is right.
        //
        // RETURNS the fence body directly — it must NOT fall through to the
        // brace scan below. Falling through lets that scan carve a decodable
        // span out of a fence body that is not JSON at all: a ```js block
        // containing `{}` yields "{}", which decodes to an empty array, passes
        // every `!is_array($parsed)` gate in invoke_json_fallback(), and
        // completes the item with an EMPTY article instead of throwing. A
        // decodable-but-wrong value returned silently is strictly worse than a
        // loud failure — the same trap that sank two earlier attempts at the
        // leading-bracket limit documented below.
        if (preg_match('/```(?:json)?\s*(.*?)\s*```/is', $content, $narrow)) {
            $body = trim($narrow[1]);
            json_decode($body, true);
            if (json_last_error() !== JSON_ERROR_NONE
                && preg_match('/```(?:json)?\s*(.*)\s*```/is', $content, $wide)
            ) {
                $widened = trim($wide[1]);
                json_decode($widened, true);
                $body = json_last_error() === JSON_ERROR_NONE ? $widened : $body;
            }
            return $body;
        }

        // KNOWN LIMIT (deliberately NOT fixed here): leading prose containing a
        // bracket — "Here is the article [as requested]: {…}" — starts the span
        // at that bracket and runs to the last `]`, yielding garbage. Two
        // attempts at candidate-ranking to fix it each introduced a WORSE
        // failure: an inner well-formed array (`media_assets` is required by
        // article_schema(), and is its LAST property, so its `]` can sit past
        // the object's `}`) decoded and won, the caller got a decodable-but-
        // wrong value, `is_array()` was true so nothing threw, salvage never
        // ran, and the item completed with an EMPTY article — silent bad data
        // replacing a loud, diagnosable error. Doing this safely needs a real
        // brace-matching scan (track string state, match the first opener to
        // its own close), not strpos/strrpos guessing; that belongs in its own
        // change with its own review, because this function is on the path of
        // every LLM call in the plugin. In json_object mode — where these
        // failures are reported — the API returns a bare object with no leading
        // prose, so the limit is not what is biting today.
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
     * Structural truncation detector — is this JSON cut off mid-output?
     * Scans once, tracking string/escape state and brace/bracket depth; a
     * result that ends while still inside a string, or with unclosed
     * containers, or that is empty, was truncated. Used to fire the
     * doubled-budget retry even when the API omits finish_reason='length'
     * (Gemini's OpenAI-compat endpoint routinely does on long articles).
     * Byte-safe for UTF-8: multibyte lead/continuation bytes are all >= 0x80,
     * so they never collide with the ASCII structural characters scanned here.
     *
     * @param string $json Candidate JSON (already through extract_json()).
     * @return bool True if the text looks truncated / empty.
     */
    public static function looks_truncated(string $json): bool
    {
        $json = trim($json);
        if ($json === '') {
            return true; // empty output — the model returned nothing usable
        }

        $in_string = false;
        $escaped   = false;
        $depth     = 0;
        $len       = strlen($json);

        for ($i = 0; $i < $len; $i++) {
            $ch = $json[$i];
            if ($in_string) {
                if ($escaped) {
                    $escaped = false;
                } elseif ($ch === '\\') {
                    $escaped = true;
                } elseif ($ch === '"') {
                    $in_string = false;
                }
                continue;
            }
            if ($ch === '"') {
                $in_string = true;
            } elseif ($ch === '{' || $ch === '[') {
                $depth++;
            } elseif ($ch === '}' || $ch === ']') {
                $depth--;
            }
        }

        return $in_string || $depth > 0;
    }

    /**
     * Last-resort repair for ALMOST-valid LLM JSON. Gemini in particular emits
     * two decode-fatal quirks even in json_object mode: raw control characters
     * (literal newlines/tabs) inside string values of long HTML fields, and
     * trailing commas before a closing brace/bracket. Both are mechanical to
     * fix without touching the payload's meaning — anything beyond that (e.g.
     * output cut mid-string) is NOT repaired here; truncation has its own
     * doubled-budget retry (see looks_truncated()), and rescuing half an
     * article would be worse than failing loudly.
     *
     * @param string $json Candidate JSON (already through extract_json()).
     * @return array|null Parsed array on successful repair, null otherwise.
     */
    public static function repair_json(string $json): ?array
    {
        if ($json === '') {
            return null;
        }

        // 1. Escape raw control characters that appear INSIDE string literals
        //    (a char-walk that tracks in-string/escape state — control chars
        //    outside strings are legal JSON whitespace and left alone).
        $fixed  = self::escape_control_chars_in_strings($json);
        $parsed = json_decode($fixed, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($parsed)) {
            return $parsed;
        }

        // 2. Trailing commas: {"a":1,} / [1,2,] → valid. Applied on top of the
        //    control-char fix so both quirks in one payload still recover.
        $fixed = preg_replace('/,\s*([}\]])/', '$1', $fixed);
        if (is_string($fixed)) {
            $parsed = json_decode($fixed, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($parsed)) {
                return $parsed;
            }
        }

        return null;
    }

    /**
     * Escape raw ASCII control characters (< 0x20) occurring inside JSON string
     * literals — \n, \r, \t map to their two-char escapes, anything else to
     * \u00XX. Tracks quote and backslash state so already-escaped sequences and
     * structural whitespace are untouched. Byte-safe for UTF-8 (multibyte lead/
     * continuation bytes are all >= 0x80).
     *
     * @param string $json Raw JSON text.
     * @return string JSON text with in-string control characters escaped.
     */
    private static function escape_control_chars_in_strings(string $json): string
    {
        $out       = '';
        $in_string = false;
        $escaped   = false;
        $len       = strlen($json);

        for ($i = 0; $i < $len; $i++) {
            $ch = $json[$i];

            if (!$in_string) {
                if ($ch === '"') {
                    $in_string = true;
                }
                $out .= $ch;
                continue;
            }

            if ($escaped) {
                $escaped = false;
                $out    .= $ch;
                continue;
            }
            if ($ch === '\\') {
                $escaped = true;
                $out    .= $ch;
                continue;
            }
            if ($ch === '"') {
                $in_string = false;
                $out      .= $ch;
                continue;
            }

            $ord = ord($ch);
            if ($ord < 0x20) {
                $map  = array("\n" => '\n', "\r" => '\r', "\t" => '\t');
                $out .= $map[$ch] ?? sprintf('\u%04x', $ord);
                continue;
            }

            $out .= $ch;
        }

        return $out;
    }

    /**
     * Last-resort SCHEMA-AWARE salvage for JSON that survives neither
     * json_decode() nor repair_json().
     *
     * The dominant unrepaired failure on real content generation is an
     * UNESCAPED double-quote inside a big HTML `content` string (the model
     * writes an attribute or a quoted phrase — e.g. Argentina to Messi:
     * "Thank You" — without escaping it), which ends the string early and
     * yields a bare "Syntax error". That is ambiguous to fix positionally, but
     * TRIVIAL when the top-level string keys are known: each field's value runs
     * from its own `"key":` marker to the next known marker (or the closing
     * brace). We slice those spans and lenient-decode each, so inner quotes /
     * stray control chars / emoji can't break the reconstruction.
     *
     * Only string-typed keys are recovered; any other required key (e.g. an
     * optional media array) is set to null. Returns null unless EVERY requested
     * string key was located, so a partial mangle never yields a half article.
     * Runs ONLY after the normal parse + repair have failed — it can never turn
     * a good parse bad.
     *
     * @param string   $raw         Candidate JSON (already extract_json()'d).
     * @param string[] $string_keys Top-level string properties to recover, in any order.
     * @param string[] $null_keys   Required non-string keys to set to null.
     * @return array|null Reconstructed map, or null when any string key is missing.
     */
    /**
     * Split a json_schema wrapper's REQUIRED top-level keys into the string
     * fields (salvageable field-by-field) and the rest (set to null on
     * salvage). A property whose type is 'string' — or a union that includes
     * 'string' — is treated as salvageable text.
     *
     * @param array $schema The {name, schema:{properties, required}} wrapper.
     * @return array{0: string[], 1: string[]} [stringKeys, nullKeys].
     */
    private static function schema_key_partition(array $schema): array
    {
        $props    = $schema['schema']['properties'] ?? null;
        $required = $schema['schema']['required'] ?? null;
        if (!is_array($props)) {
            return array(array(), array());
        }
        // With no explicit required list, treat every property as a candidate.
        $keys = is_array($required) && $required !== array() ? $required : array_keys($props);

        $string_keys = array();
        $null_keys   = array();
        foreach ($keys as $key) {
            $type = $props[$key]['type'] ?? null;
            $is_string = $type === 'string' || (is_array($type) && in_array('string', $type, true));
            if ($is_string) {
                $string_keys[] = $key;
            } else {
                $null_keys[] = $key;
            }
        }
        return array($string_keys, $null_keys);
    }

    public static function salvage_json_by_keys(string $raw, array $string_keys, array $null_keys = array()): ?array
    {
        if ($raw === '' || $string_keys === array()) {
            return null;
        }

        // Locate the top-level marker for EVERY key — string keys (whose value
        // we extract) AND null keys (used ONLY as value boundaries, so a
        // trailing or interleaved non-string key like `media_assets` can never
        // be swallowed into the preceding string value). A real top-level key
        // marker is preceded by `{` or `,`; requiring that structural char
        // makes a `"key":` lookalike inside content HTML far less likely to be
        // mistaken for a field boundary.
        $key_start  = array(); // key => offset of the key's opening quote
        $value_from = array(); // string key => offset of its value's first char
        foreach ($string_keys as $key) {
            $mark = self::locate_key_marker($raw, $key, true);
            if ($mark === null) {
                return null; // a required string key is absent — refuse to guess
            }
            $key_start[$key]  = $mark['keyStart'];
            $value_from[$key] = $mark['valueFrom'];
        }
        foreach ($null_keys as $key) {
            $mark = self::locate_key_marker($raw, $key, false);
            if ($mark !== null) {
                $key_start[$key] = $mark['keyStart']; // boundary only — value not read
            }
        }

        // Order ALL located markers by position; a string field's value ends at
        // the next marker of ANY type.
        asort($key_start);
        $ordered = array_keys($key_start);
        $count   = count($ordered);

        $out = array();
        for ($i = 0; $i < $count; $i++) {
            $key = $ordered[$i];
            if (!isset($value_from[$key])) {
                continue; // a null-key marker — a boundary only, nothing to extract
            }
            $inner_from = $value_from[$key];
            if ($i + 1 < $count) {
                // End the value just before the next key's marker (string OR
                // null), then drop this value's own closing `"` + comma.
                $chunk = substr($raw, $inner_from, $key_start[$ordered[$i + 1]] - $inner_from);
                $chunk = preg_replace('/"\s*,\s*$/', '', $chunk);
            } else {
                // Final field (no key follows): cut at the last closing brace,
                // then drop the trailing closing quote (+ optional comma).
                $chunk = substr($raw, $inner_from);
                $last_brace = strrpos($chunk, '}');
                if ($last_brace !== false) {
                    $chunk = substr($chunk, 0, $last_brace);
                }
                $chunk = preg_replace('/"\s*,?\s*$/', '', $chunk);
            }
            $out[$key] = self::decode_json_string_lenient((string) $chunk);
        }

        // Sanity gate against a mis-slice: a correctly-bounded value can never
        // still contain a STRUCTURAL `{`/`,` + `"schemaKey":` marker (that would
        // mean a real field boundary was swallowed — e.g. content HTML that
        // literally prints `,"metaTitle":"…"`). When that signature survives,
        // refuse the salvage (→ terminal error) rather than publish garbage.
        $all_keys = array_merge($string_keys, $null_keys);
        foreach ($string_keys as $key) {
            foreach ($all_keys as $other) {
                if (preg_match('/[{,]\s*"' . preg_quote($other, '/') . '"\s*:/', (string) $out[$key])) {
                    return null;
                }
            }
        }

        foreach ($null_keys as $key) {
            $out[$key] = null;
        }

        return $out;
    }

    /**
     * Locate a top-level JSON key's marker inside malformed JSON.
     *
     * A real field is preceded by a structural `{` or `,` (after optional
     * whitespace) — requiring that keeps a `"key":` lookalike buried in content
     * HTML from being mistaken for a field boundary. First structural match
     * wins.
     *
     * @param string $raw    The candidate JSON.
     * @param string $key    Key name to find.
     * @param bool   $string True → the value is a string (require the opening
     *                       `"`; return where the value text begins). False →
     *                       any value type (boundary use only).
     * @return array{keyStart:int, valueFrom:int}|null keyStart = offset of the
     *   key's opening quote; valueFrom = first char of a string value (0 when
     *   $string is false). Null when not found.
     */
    private static function locate_key_marker(string $raw, string $key, bool $string): ?array
    {
        $q       = preg_quote($key, '/');
        $pattern = $string
            ? '/([{,]\s*)"' . $q . '"\s*:\s*"/'
            : '/([{,]\s*)"' . $q . '"\s*:/';
        if (!preg_match($pattern, $raw, $m, PREG_OFFSET_CAPTURE)) {
            return null;
        }
        $key_start = $m[0][1] + strlen($m[1][0]); // skip the leading { or , + whitespace
        return array(
            'keyStart'  => $key_start,
            'valueFrom' => $string ? $m[0][1] + strlen($m[0][0]) : 0,
        );
    }

    /**
     * Decode the INNER text of a JSON string literal without ever failing.
     *
     * Honors the standard escapes a well-formed model still emits (\" \\ \/ \n
     * \r \t \b \f \uXXXX) so escaped content round-trips correctly, while
     * treating any OTHER character — including a raw unescaped `"` that broke
     * the surrounding parse — as a literal. Used only by salvage_json_by_keys().
     *
     * @param string $inner Raw bytes between a field value's quotes.
     * @return string The decoded string value.
     */
    private static function decode_json_string_lenient(string $inner): string
    {
        $out = '';
        $len = strlen($inner);
        for ($i = 0; $i < $len; $i++) {
            $ch = $inner[$i];
            if ($ch !== '\\' || $i + 1 >= $len) {
                $out .= $ch;
                continue;
            }
            $next = $inner[$i + 1];
            switch ($next) {
                case '"':  $out .= '"';  $i++; break;
                case '\\': $out .= '\\'; $i++; break;
                case '/':  $out .= '/';  $i++; break;
                case 'n':  $out .= "\n"; $i++; break;
                case 'r':  $out .= "\r"; $i++; break;
                case 't':  $out .= "\t"; $i++; break;
                case 'b':  $out .= "\x08"; $i++; break;
                case 'f':  $out .= "\x0C"; $i++; break;
                case 'u':
                    if ($i + 5 < $len && ctype_xdigit(substr($inner, $i + 2, 4))) {
                        $cp = hexdec(substr($inner, $i + 2, 4));
                        // Combine a high+low surrogate pair (e.g. an emoji sent
                        // as 😀) into one astral codepoint — decoding
                        // each half alone would yield U+FFFD replacement chars.
                        if ($cp >= 0xD800 && $cp <= 0xDBFF
                            && $i + 11 < $len
                            && $inner[$i + 6] === '\\' && $inner[$i + 7] === 'u'
                            && ctype_xdigit(substr($inner, $i + 8, 4))
                        ) {
                            $lo = hexdec(substr($inner, $i + 8, 4));
                            if ($lo >= 0xDC00 && $lo <= 0xDFFF) {
                                $cp   = 0x10000 + (($cp - 0xD800) << 10) + ($lo - 0xDC00);
                                $out .= self::codepoint_to_utf8($cp);
                                $i   += 11;
                                break;
                            }
                        }
                        $out .= self::codepoint_to_utf8($cp);
                        $i   += 5;
                    } else {
                        $out .= $ch; // malformed \u — keep the backslash literally
                    }
                    break;
                default:
                    $out .= $ch; // unknown escape — keep the backslash literally
            }
        }
        return $out;
    }

    /**
     * Codepoint → UTF-8. Handles the full range including astral-plane
     * codepoints (emoji), which the caller passes after combining a high+low
     * surrogate pair. A LONE surrogate (unpaired D800–DFFF) is invalid on its
     * own and maps to the U+FFFD replacement char.
     */
    private static function codepoint_to_utf8(int $cp): string
    {
        if ($cp >= 0xD800 && $cp <= 0xDFFF) {
            return "\u{FFFD}";
        }
        if (function_exists('mb_convert_encoding')) {
            return mb_convert_encoding(pack('N', $cp), 'UTF-8', 'UTF-32BE');
        }
        return "\u{FFFD}";
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
            // Grounded research fans out to live web search before answering —
            // give it the same headroom class as generation (was 120s).
            'timeout' => max(30, (int) ($options['timeout'] ?? 180)),
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
     * @return array { content, usage, model, finish_reason, refusal, raw } — the
     *   full parse_response() shape.
     */
    private static function blocking_request(string $url, array $payload, array $headers, int $timeout = 300): array
    {
        // Long-form generation (full pillar articles + media manifests) routinely
        // needs more than the old hard-coded 120s — cURL error 28 ("timed out
        // after 120002 ms with 0 bytes received") killed those items. Default is
        // now 300s, overridable per call via options['timeout']. Also raise PHP's
        // own execution ceiling so the HTTP wait can't outlive the process.
        if (function_exists('set_time_limit')) {
            @set_time_limit($timeout + 30);
        }
        $response = wp_remote_post($url, array(
            'body' => wp_json_encode($payload),
            'headers' => $headers,
            'timeout' => $timeout,
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
     * @return array { content, usage, model, raw } — NOTE this is a NARROWER
     *   shape than blocking_request(): a streamed reply carries neither
     *   `finish_reason` nor `refusal`, so a refusal over streaming is currently
     *   invisible. Safe today only because the article path never streams;
     *   callers must not assume those keys exist.
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
            // Total-transfer ceiling. Streams deliver tokens continuously, but a
            // long article still needs wall-clock: match the Writer's SSE budget
            // (PCM_SSE::start(600)) instead of the old 120s that cut streams off.
            CURLOPT_TIMEOUT => 600,
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
        // Workspace-scoped: the caller's own key, else an admin's (PCM_Access::
        // workspace_api_key). Platform (id/pass) users own no integrations, so a
        // per-user lookup failed for every one of them.
        $result = class_exists('PCM_Access')
            ? PCM_Access::workspace_api_key($provider, $user_id)
            : null;

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

        // 2. DB lookup — pcm_models stores the authoritative provider per model.
        //    NOT scoped by user, deliberately: a model's provider is a property
        //    of the MODEL, identical for every owner, so scoping only ever
        //    caused misses. The previous scope was doubly wrong — it compared
        //    get_current_user_id() (a WORDPRESS user id) against models.userId
        //    (a PCM user id), two unrelated id spaces that line up only by
        //    coincidence. On the shortcode SPA there is no WP login at all, so
        //    it was always 0, this lookup never matched, and every model fell
        //    through to the prefix heuristic below — which defaults to 'openai'
        //    and therefore mis-routed any provider it has no prefix rule for.
        global $wpdb;
        $table = PCM_Schema::table('models');
        $db_provider = $wpdb->get_var($wpdb->prepare(
            "SELECT provider FROM {$table} WHERE modelId = %s AND provider <> '' ORDER BY id ASC LIMIT 1",
            $model_id
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

        // Web-enabled generation: Anthropic's server-side web search tool. The model
        // decides when to search; results come back as extra content blocks that
        // parse_response() skips (it joins the text blocks only). Bounded per call.
        if (!empty($payload['web'])) {
            $anthropic_payload['tools'] = array(
                array('type' => 'web_search_20250305', 'name' => 'web_search', 'max_uses' => 5),
            );
        }

        return $anthropic_payload;
    }

    /**
     * OpenAI *Responses* API call with the `web_search_preview` tool — the
     * transport for web-enabled generation on OpenAI (chat/completions has no web
     * tool for ordinary models). Same in/out contract as blocking_request(): the
     * OpenAI-compatible payload built by invoke() goes in, the parse_response()
     * shape comes out; errors are RuntimeExceptions carrying the API body so
     * invoke_json()'s tier logic (json_schema → json_object → prompt-only) keeps
     * working — a rejected `text.format` is re-worded so that classifier sees it.
     *
     * @param array  $payload OpenAI-compatible payload from invoke().
     * @param string $api_key OpenAI key.
     * @param array  $options invoke() options (timeout).
     * @return array parse_response() shape.
     * @throws \RuntimeException On transport/API/parse failure.
     */
    private static function invoke_openai_responses(array $payload, string $api_key, array $options = array()): array
    {
        $instructions = '';
        $input        = array();
        foreach ((array) ($payload['messages'] ?? array()) as $msg) {
            $content = is_string($msg['content'] ?? null) ? $msg['content'] : wp_json_encode($msg['content'] ?? '');
            if (($msg['role'] ?? '') === 'system') {
                $instructions .= ($instructions !== '' ? "\n" : '') . $content;
                continue;
            }
            $input[] = array('role' => ($msg['role'] ?? 'user') === 'assistant' ? 'assistant' : 'user', 'content' => $content);
        }
        $body = array(
            'model'             => (string) $payload['model'],
            'input'             => $input,
            'tools'             => array(array('type' => 'web_search_preview')),
            'max_output_tokens' => (int) ($payload['max_completion_tokens'] ?? $payload['max_tokens'] ?? self::DEFAULT_MAX_TOKENS),
        );
        if ($instructions !== '') {
            $body['instructions'] = $instructions;
        }
        // response_format (chat shape) → text.format (Responses shape).
        $rf = is_array($payload['response_format'] ?? null) ? $payload['response_format'] : array();
        if (($rf['type'] ?? '') === 'json_schema' && is_array($rf['json_schema'] ?? null)) {
            $js = $rf['json_schema'];
            $body['text'] = array('format' => array(
                'type'   => 'json_schema',
                'name'   => (string) ($js['name'] ?? 'response'),
                'schema' => $js['schema'] ?? array('type' => 'object'),
                'strict' => !empty($js['strict']),
            ));
        } elseif (($rf['type'] ?? '') === 'json_object') {
            $body['text'] = array('format' => array('type' => 'json_object'));
        }

        $timeout = max(30, (int) ($options['timeout'] ?? 300));
        if (function_exists('set_time_limit')) {
            @set_time_limit($timeout + 30);
        }
        $response = wp_remote_post('https://api.openai.com/v1/responses', array(
            'body'      => wp_json_encode($body),
            'headers'   => array('Content-Type' => 'application/json', 'Authorization' => 'Bearer ' . $api_key),
            'timeout'   => $timeout,
            'sslverify' => true,
        ));
        if (is_wp_error($response)) {
            throw new \RuntimeException(sprintf('LLM API request failed: %s', $response->get_error_message()));
        }
        $status = (int) wp_remote_retrieve_response_code($response);
        $raw    = (string) wp_remote_retrieve_body($response);
        if ($status < 200 || $status >= 300) {
            $msg = substr($raw, 0, 1000);
            // Let invoke_json()'s format classifier recognise a rejected output format
            // under the Responses API's own parameter name.
            if (stripos($msg, 'text.format') !== false || stripos($msg, "'format'") !== false) {
                $msg = 'response_format not supported by this model via web-enabled generation: ' . $msg;
            }
            throw new \RuntimeException(sprintf('LLM API error %d: %s', $status, $msg));
        }
        $data = json_decode($raw, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
            throw new \RuntimeException('LLM API returned non-JSON response');
        }
        // output[] = tool calls + one or more message items; the text is the joined
        // output_text parts of the message items. A refusal part carries its reason.
        $content = '';
        $refusal = '';
        foreach ((array) ($data['output'] ?? array()) as $item) {
            if (($item['type'] ?? '') !== 'message') {
                continue;
            }
            foreach ((array) ($item['content'] ?? array()) as $part) {
                if (($part['type'] ?? '') === 'output_text' && isset($part['text'])) {
                    $content .= (string) $part['text'];
                } elseif (($part['type'] ?? '') === 'refusal' && isset($part['refusal'])) {
                    $refusal = (string) $part['refusal'];
                }
            }
        }
        $finish = (string) ($data['status'] ?? '');
        if ($finish === 'incomplete' && (($data['incomplete_details']['reason'] ?? '') === 'max_output_tokens')) {
            $finish = 'length';
        }
        return array(
            'content'       => $content,
            'usage'         => $data['usage'] ?? null,
            'model'         => (string) ($data['model'] ?? $payload['model']),
            'finish_reason' => $finish,
            'refusal'       => $refusal,
            'raw'           => $data,
        );
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
     * @return array { content: string, usage: array|null, model: string,
     *   finish_reason: string, refusal: string, raw: array }
     */
    private static function parse_response(array $data): array
    {
        $content = '';
        $refusal = '';

        // OpenAI sends message.content as an explicit `null` alongside a
        // message.refusal string when the model declines. isset() is false on
        // null, so the old check dropped the decline AND (via the elseif below)
        // misrouted a null-content OpenAI reply toward the Anthropic branch.
        // array_key_exists() sees the null key; the is_string() gate then
        // refuses to promote that null into a silent empty "success".
        $message = $data['choices'][0]['message'] ?? null;
        // The refusal is read INDEPENDENTLY of the content key: OpenAI pairs it
        // with content:null today, but nesting the read inside that guard means
        // a reply carrying only `refusal` would silently drop the one field
        // that explains the empty result — the exact failure this whole change
        // exists to end.
        if (is_array($message) && is_string($message['refusal'] ?? null)) {
            $refusal = $message['refusal'];
        }
        if (is_array($message) && array_key_exists('content', $message)) {
            if (is_string($message['content'])) {
                $content = $message['content'];
            }
        }
        // Anthropic format: content[] blocks. Reached only when the OpenAI
        // content key is absent — a real OpenAI string reply stays in the
        // branch above and never falls through to here. ALL text blocks are
        // joined: a web-enabled reply interleaves server_tool_use /
        // web_search_tool_result blocks with several text blocks, and reading
        // only content[0] would drop most of the article.
        elseif (isset($data['content']) && is_array($data['content'])) {
            foreach ($data['content'] as $block) {
                if (is_array($block) && (($block['type'] ?? 'text') === 'text') && isset($block['text']) && is_string($block['text'])) {
                    $content .= $block['text'];
                }
            }
        }

        // Anthropic surfaces a decline as stop_reason='refusal' with no body in
        // the content array, so map it onto the same refusal signal rather than
        // leave the operator with an unexplained empty reply.
        if ($refusal === '' && ($data['stop_reason'] ?? '') === 'refusal') {
            $refusal = 'The model declined to generate this content (stop_reason=refusal).';
        }

        // Normalized truncation signal: OpenAI/compat 'length', Anthropic
        // native 'max_tokens' — both mean the output hit the token cap and is
        // cut off mid-stream (invoke_json uses this to retry with more budget).
        $finish = $data['choices'][0]['finish_reason'] ?? ($data['stop_reason'] ?? '');
        if ($finish === 'max_tokens') {
            $finish = 'length';
        }

        return array(
            'content' => $content,
            'usage' => $data['usage'] ?? null,
            'model' => $data['model'] ?? '',
            'finish_reason' => (string) $finish,
            'refusal' => $refusal,
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
