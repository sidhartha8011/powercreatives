<?php
/**
 * Teacher: AI mentions (research spine D5 — THE PANEL).
 *
 * Multi-engine from birth (owner ruling): the same money-questions go to
 * EVERY AI engine the user holds a key for (one representative text model
 * per provider — value scales with keys, not code). Each engine answers
 * as it would answer a real user; whether OUR brand is mentioned is
 * decided DETERMINISTICALLY on our side (never the model's self-report).
 * Engines run SEQUENTIALLY inside this one request — the hub has 2 PHP
 * workers, parallel fan-out here would freeze the app (handover §2).
 *
 * Every item carries source='<provider>:<model>' — the owner sees which
 * engine said what, always. Questions are hub-controlled DATA
 * (pcm_optimizer_research → mention.questions, placeholder-substituted).
 *
 * @package PowerCreatives
 */

if (!defined('ABSPATH')) {
    exit;
}

class PCM_Teacher_Mention implements PCM_Optimizer_Teacher
{
    public function id(): string
    {
        return 'mention';
    }

    public function label(): string
    {
        return 'AI recommendations';
    }

    public function order(): int
    {
        return 45;
    }

    public function group(): string
    {
        return 'ai';
    }

    /**
     * Build the money questions from tunables + context, ask every engine,
     * verdict per engine. An engine that ERRORS is reported as its own
     * failed item (evidence = the error) — one broken key never hides the
     * other engines' answers, and never fakes a verdict.
     *
     * @param array $context See the interface.
     * @return array Catalog items.
     */
    public function analyze(array $context): array
    {
        $user_id  = (int) ($context['userId'] ?? 0);
        $business = (array) ($context['business'] ?? array());
        $tun      = PCM_Optimizer_Service::research_tunables()['mention'] ?? array();

        $questions = $this->build_questions((array) ($tun['questions'] ?? array()), $context);
        if (empty($questions)) {
            throw new \RuntimeException('AI mention research needs a primary keyword or a linked business — set the keyword in the drawer or link the business in the editor header.');
        }

        $engines = PCM_Optimizer_Service::text_engines($user_id, (int) ($tun['maxEngines'] ?? 4));
        if (empty($engines)) {
            throw new \RuntimeException('AI mention research needs at least one AI provider key — add one on the Integrations page.');
        }

        $items = array();
        foreach ($engines as $engine) {
            $source = $engine['provider'] . ':' . $engine['model'];
            try {
                $answer = $this->ask_engine($engine, $questions, $user_id);
            } catch (\Throwable $e) {
                // One engine's failure is ITS OWN row — visible, retryable,
                // never hiding the panel's other voices.
                $items[] = array(
                    'id'          => 'engine-' . sanitize_title($engine['provider']),
                    'teacherId'   => $this->id(),
                    'found'       => true,
                    'label'       => sprintf('%s: engine unreachable', $engine['provider']),
                    'evidence'    => $e->getMessage(),
                    'instruction' => '',
                    'source'      => $source,
                );
                continue;
            }

            $mentioned = PCM_Optimizer_Service::mentions_brand($answer['raw'], $business);
            $names     = array_slice($answer['recommendations'], 0, 5);
            $items[]   = array(
                'id'          => 'engine-' . sanitize_title($engine['provider']),
                'teacherId'   => $this->id(),
                'found'       => !$mentioned,
                'label'       => sprintf('%s %s', $engine['provider'], $mentioned ? 'recommends this business' : 'does not mention this business'),
                'evidence'    => empty($names)
                    ? 'The engine named no concrete providers.'
                    : sprintf('It recommends: %s.%s', implode(', ', $names), $answer['reason'] !== '' ? ' Why: ' . $answer['reason'] : ''),
                'instruction' => $mentioned ? '' : sprintf(
                    'Add the concrete, verifiable proof AI recommendations cite — the kind the recommended competitors show%s: specific facts, numbers, guarantees, reviews — woven into the content, never invented.',
                    empty($names) ? '' : ' (' . implode(', ', $names) . ')'
                ),
                'source'      => $source,
            );
        }

        return $items;
    }

    /**
     * Substitute placeholders into the question DATA; a question whose
     * placeholder resolves empty is SKIPPED — never sent half-filled.
     *
     * @param array $patterns Question patterns from tunables.
     * @param array $context  The context package.
     * @return string[]
     */
    private function build_questions(array $patterns, array $context): array
    {
        $business = (array) ($context['business'] ?? array());
        $vars     = array(
            '{{primary_keyword}}'    => trim((string) ($context['keywords']['primary'] ?? '')),
            '{{business.category}}'  => trim((string) ($business['category'] ?? '')),
            '{{business.address}}'   => trim((string) ($business['address'] ?? '')),
            '{{business.name}}'      => trim((string) ($business['name'] ?? '')),
        );
        $questions = array();
        foreach ($patterns as $pattern) {
            $q        = (string) $pattern;
            $complete = true;
            foreach ($vars as $ph => $value) {
                if (strpos($q, $ph) === false) {
                    continue;
                }
                if ($value === '') {
                    $complete = false;
                    break;
                }
                $q = str_replace($ph, $value, $q);
            }
            if ($complete && trim($q) !== '') {
                $questions[] = trim($q);
            }
        }
        return $questions;
    }

    /**
     * ONE call to one engine: it answers the questions as it would answer
     * a real user, and lists the concrete providers/brands it recommended.
     * Google engines answer WEB-GROUNDED via invoke_with_grounding (the
     * existing capability) — labeled through the source the same way.
     *
     * @param array    $engine    {provider, model}.
     * @param string[] $questions The money questions.
     * @param int      $user_id   Key owner.
     * @return array{raw: string, recommendations: string[], reason: string}
     */
    private function ask_engine(array $engine, array $questions, int $user_id): array
    {
        // No hidden prompt: this wrapper is a Templates (module=optimizer)
        // row a user can view/edit — resolve_prompt() returns this exact
        // default verbatim when no override exists. The "RECOMMENDED: [...]"
        // sentinel is parsed below; removing it degrades to empty
        // recommendations rather than failing (never a hard break).
        $default_prompt = "Answer the following user questions exactly as you would answer a real user asking you for a recommendation. "
            . "Be concrete: name the actual providers/businesses you would recommend and why."
            . "\n\nQUESTIONS:\n{{questions}}"
            . "\n\nAfter your answer, on the LAST line output exactly: "
            . 'RECOMMENDED: ["name1","name2",...] — the JSON array of the concrete provider/business names you recommended.';
        $prompt_tpl = PCM_Optimizer_Service::resolve_prompt('teacher_mention', $default_prompt, $user_id);
        $prompt     = PCM_Optimizer_Service::render_prompt_vars($prompt_tpl, array(
            'questions' => '- ' . implode("\n- ", $questions),
        ));

        $messages = array(array('role' => 'user', 'content' => $prompt));
        $options  = array(
            'model'      => $engine['model'],
            'provider'   => $engine['provider'],
            'user_id'    => $user_id,
            'max_tokens' => 1024,
        );

        // Google gets the web-grounded path — real search results behind the
        // recommendation, the strongest mention signal available.
        if ($engine['provider'] === 'google') {
            $result = PCM_LLM::invoke_with_grounding($messages, $options);
        } else {
            $result = PCM_LLM::invoke($messages, $options);
        }
        $raw = (string) ($result['content'] ?? '');
        if (trim($raw) === '') {
            throw new \RuntimeException('The engine returned no answer.');
        }

        $recommendations = array();
        if (preg_match('/RECOMMENDED:\s*(\[.*\])/s', $raw, $m)) {
            $decoded = json_decode($m[1], true);
            if (is_array($decoded)) {
                foreach ($decoded as $name) {
                    $n = sanitize_text_field((string) $name);
                    if ($n !== '' && !in_array($n, $recommendations, true)) {
                        $recommendations[] = $n;
                    }
                }
            }
        }

        // The reason: the first sentence of the prose answer (short, honest).
        $prose  = trim((string) preg_replace('/RECOMMENDED:\s*\[.*\]\s*$/s', '', $raw));
        $period = function_exists('mb_strpos') ? mb_strpos($prose, '. ') : strpos($prose, '. ');
        $reason = $period !== false
            ? (function_exists('mb_substr') ? mb_substr($prose, 0, $period + 1) : substr($prose, 0, $period + 1))
            : '';

        return array('raw' => $raw, 'recommendations' => $recommendations, 'reason' => trim($reason));
    }
}

PCM_Optimizer_Service::register(new PCM_Teacher_Mention());
