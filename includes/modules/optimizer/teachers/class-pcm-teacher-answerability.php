<?php
/**
 * Teacher: Answerability (research spine D5 — the AI group's first fish).
 *
 * What actually gets pages QUOTED by AI assistants: the direct answer up
 * front, question-form headings where a question is what users ask,
 * sections that stand alone as liftable quotes, an FAQ answering the real
 * money questions, and plain definitions. ONE structured call judges the
 * fixed check set against THIS content — checks in DATA order, the
 * model's order never trusted.
 *
 * @package PowerCreatives
 */

if (!defined('ABSPATH')) {
    exit;
}

class PCM_Teacher_Answerability implements PCM_Optimizer_Teacher
{
    /** The check set — id · label · question. Answerability is a stable
     *  editorial discipline, not a tunable threshold, so the set lives
     *  here like the search teacher's contract (prompt-as-data comes with
     *  the deferred Templates arc). */
    private const CHECKS = array(
        array(
            'id'       => 'answer-first',
            'label'    => 'Direct answer up front',
            'question' => 'Does the very first paragraph directly and completely answer the core question this page targets — quotable on its own, no warm-up?',
            'fix'      => 'Rewrite the opening so the first paragraph IS the direct, complete answer to the page\'s core question — quotable standalone.',
        ),
        array(
            'id'       => 'question-headings',
            'label'    => 'Question-form headings',
            'question' => 'Where a section answers something users genuinely ask, is its heading phrased as that question?',
            'fix'      => 'Rephrase the headings of answer-bearing sections as the literal questions users ask — only where a real question fits.',
        ),
        array(
            'id'       => 'liftable-sections',
            'label'    => 'Sections quotable standalone',
            'question' => 'Can each major section be lifted out alone and still make complete sense — self-contained, no dangling references like "as mentioned above"?',
            'fix'      => 'Make each section self-contained: restate the subject instead of referring backwards, so any section survives being quoted alone.',
        ),
        array(
            'id'       => 'faq-present',
            'label'    => 'FAQ answers real questions',
            'question' => 'Does the content include a FAQ (or Q/A blocks) answering the concrete questions a buyer of this topic actually asks?',
            'fix'      => 'Add a short FAQ section answering the 3-5 concrete questions a buyer of this topic actually asks — one direct answer each.',
        ),
        array(
            'id'       => 'plain-definitions',
            'label'    => 'Key terms defined plainly',
            'question' => 'Are the topic\'s key terms defined in plain one-sentence language an AI can quote as a definition?',
            'fix'      => 'Add plain one-sentence definitions for the topic\'s key terms where they first appear.',
        ),
    );

    public function id(): string
    {
        return 'answerability';
    }

    public function label(): string
    {
        return 'Direct answers';
    }

    public function order(): int
    {
        return 40;
    }

    public function group(): string
    {
        return 'ai';
    }

    /**
     * One invoke_json call: checks in, per-check verdicts out, mapped onto
     * the catalog contract in DATA order (the search-teacher pattern).
     *
     * @param array $context See the interface.
     * @return array Catalog items.
     */
    public function analyze(array $context): array
    {
        $questions = array_map(
            static fn(array $c): array => array('id' => $c['id'], 'question' => $c['question']),
            self::CHECKS
        );

        // No hidden prompt: this system prompt is a Templates (module=
        // optimizer) row a user can view/edit — resolve_prompt() returns
        // this exact default verbatim when no override exists.
        $default_system = 'You are a strict auditor of how quotable a page is for AI assistants (answer engines). '
            . 'Judge the given page content against each check, ONLY from the content provided. For every '
            . 'check answer passes=true or passes=false. evidence: when it passes, a short verbatim quote '
            . 'proving it; when it fails, one short sentence naming the concrete thing that is missing. '
            . 'Never invent content. Respond with ONLY this JSON, no markdown, no commentary: '
            . '{"checks":[{"id":"<the check id, echoed EXACTLY as given>","passes":true,"evidence":"..."}]} '
            . '— one entry per check, every id present.';

        $messages = array(
            array(
                'role'    => 'system',
                // The exact output contract lives IN the prompt (the
                // Anthropic law — no response_format there).
                'content' => PCM_Optimizer_Service::resolve_prompt('teacher_answerability', $default_system, (int) ($context['userId'] ?? 0)),
            ),
            array(
                'role'    => 'user',
                'content' => 'PAGE TYPE: ' . (string) ($context['pageType'] ?? 'general')
                    . PCM_Optimizer_Service::context_suffix($context) . "\n\n"
                    . "CHECKS (answer every one, by id):\n" . wp_json_encode($questions) . "\n\n"
                    . "PAGE CONTENT (HTML):\n" . (string) $context['html'],
            ),
        );

        $schema = array(
            'name'   => 'optimizer_answerability_verdicts',
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
            'timeout'  => PCM_Optimizer_Service::ANALYZE_TIMEOUT,
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
        if (empty($verdicts)) {
            throw new \RuntimeException('The model did not answer the answerability checks — re-analyze, or pick another model.');
        }

        $items = array();
        foreach (self::CHECKS as $check) {
            $verdict = $verdicts[$check['id']] ?? null;
            if ($verdict === null) {
                error_log(sprintf('[PCM_Optimizer] answerability teacher: model skipped check "%s" — omitted from this run.', $check['id']));
                continue;
            }
            $items[] = array(
                'id'          => $check['id'],
                'teacherId'   => $this->id(),
                'found'       => !(bool) $verdict['passes'],
                'label'       => $check['label'],
                'evidence'    => (string) ($verdict['evidence'] ?? ''),
                'instruction' => $check['fix'],
            );
        }
        return $items;
    }
}

PCM_Optimizer_Service::register(new PCM_Teacher_Answerability());
