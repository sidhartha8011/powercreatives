<?php
/**
 * Teacher: Search engine optimization.
 *
 * Judges the page content against the page-type checklist (hub-controlled
 * DATA, seeded from docs/RESEARCH-CONTENT-ANALYSIS-20260713.md) in ONE
 * structured LLM call. Every verdict carries EVIDENCE — a quote when the
 * check passes, the concrete missing thing when it fails — because a bare
 * score is never trustworthy.
 *
 * @package PowerCreatives
 */

if (!defined('ABSPATH')) {
    exit;
}

class PCM_Teacher_Search implements PCM_Optimizer_Teacher
{
    public function id(): string
    {
        return 'search';
    }

    public function label(): string
    {
        return 'Structure & language';
    }

    public function order(): int
    {
        return 10;
    }

    public function group(): string
    {
        return 'search';
    }

    /**
     * One invoke_json call: checklist in, per-check verdicts out, mapped
     * onto the catalog contract in DATA order (the model's order is never
     * trusted). A check the model failed to answer is logged and skipped —
     * visible in the log, recoverable via the purpose's own re-analyze.
     *
     * @param array $context See the interface.
     * @return array Catalog items.
     */
    public function analyze(array $context): array
    {
        $checks = PCM_Optimizer_Service::checklist_for((string) ($context['pageType'] ?? 'general'));
        if (empty($checks)) {
            return array();
        }

        $questions = array_map(
            static fn(array $c): array => array('id' => $c['id'], 'question' => $c['question']),
            $checks
        );

        // No hidden prompt: this system prompt is a Templates (module=
        // optimizer) row a user can view/edit — resolve_prompt() returns
        // this exact default verbatim when no override exists.
        $default_system = 'You are a strict, factual content auditor. Judge the given page content against each '
            . 'check. For every check answer passes=true or passes=false, judging ONLY from the content '
            . 'provided. evidence: when it passes, a short verbatim quote from the content proving it; when '
            . 'it fails, one short sentence naming the concrete thing that is missing. Never invent content. '
            . 'Respond with ONLY this JSON, no markdown, no commentary: '
            . '{"checks":[{"id":"<the check id, echoed EXACTLY as given>","passes":true,"evidence":"..."}]} '
            . '— one entry per check, every id present.';

        $messages = array(
            array(
                'role'    => 'system',
                // The exact output contract lives IN the prompt: Anthropic has
                // no response_format (PCM_LLM drops it by documented design,
                // class-pcm-llm.php:126) — the prompt is the only schema there.
                'content' => PCM_Optimizer_Service::resolve_prompt('teacher_search', $default_system, (int) ($context['userId'] ?? 0)),
            ),
            array(
                'role'    => 'user',
                // The context package (research spine D1): the checks judge
                // with the real keywords and business facts in view — e.g.
                // "contact visible early" against the ACTUAL phone number.
                'content' => 'PAGE TYPE: ' . (string) ($context['pageType'] ?? 'general')
                    . PCM_Optimizer_Service::context_suffix($context) . "\n\n"
                    . "CHECKS (answer every one, by id):\n" . wp_json_encode($questions) . "\n\n"
                    . "PAGE CONTENT (HTML):\n" . (string) $context['html'],
            ),
        );

        $schema = array(
            'name'   => 'optimizer_search_verdicts',
            'schema' => array(
                'type'       => 'object',
                'properties' => array(
                    'checks' => array(
                        'type'  => 'array',
                        'items' => array(
                            'type'       => 'object',
                            'properties' => array(
                                'id'       => array('type' => 'string'),
                                'passes'   => array('type' => 'boolean'),
                                'evidence' => array('type' => 'string'),
                            ),
                            'required'   => array('id', 'passes', 'evidence'),
                        ),
                    ),
                ),
                'required'   => array('checks'),
            ),
        );

        $parsed = PCM_LLM::invoke_json($messages, $schema, array(
            'model'    => (string) ($context['model'] ?? '') ?: null,
            'provider' => (string) ($context['provider'] ?? '') ?: null,
            'user_id'  => (int) ($context['userId'] ?? 0),
        ));

        $verdicts = array();
        foreach ((array) ($parsed['checks'] ?? array()) as $row) {
            if (is_array($row) && isset($row['id'])) {
                $verdicts[(string) $row['id']] = $row;
            }
        }
        // ZERO matched verdicts = the model ignored the contract — an honest
        // FAILURE the rail shows with its own retry, never an empty success
        // (that exact silence shipped once, 2026-07-13, and read as "broken").
        if (empty($verdicts)) {
            throw new \RuntimeException('The model did not answer the checklist — re-analyze, or pick another model.');
        }

        $items = array();
        foreach ($checks as $check) {
            $verdict = $verdicts[$check['id']] ?? null;
            if ($verdict === null) {
                error_log(sprintf('[PCM_Optimizer] search teacher: model skipped check "%s" — omitted from this run.', $check['id']));
                continue;
            }
            $items[] = array(
                'id'          => $check['id'],
                'teacherId'   => $this->id(),
                'found'       => !(bool) $verdict['passes'], // found = a gap to fix
                'label'       => $check['label'],
                'evidence'    => (string) ($verdict['evidence'] ?? ''),
                'instruction' => $check['instruction'],
            );
        }
        return $items;
    }
}

PCM_Optimizer_Service::register(new PCM_Teacher_Search());
