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
        return 'Search engine optimization';
    }

    public function order(): int
    {
        return 10;
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

        $messages = array(
            array(
                'role'    => 'system',
                'content' => 'You are a strict, factual content auditor. Judge the given page content against each '
                    . 'check. For every check answer passes=true or passes=false, judging ONLY from the content '
                    . 'provided. evidence: when it passes, a short verbatim quote from the content proving it; when '
                    . 'it fails, one short sentence naming the concrete thing that is missing. Never invent content.',
            ),
            array(
                'role'    => 'user',
                'content' => 'PAGE TYPE: ' . (string) ($context['pageType'] ?? 'general') . "\n\n"
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
