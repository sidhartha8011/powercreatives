<?php
/**
 * Teacher: Entity & fact authority (research spine D5 — AI group).
 *
 * AI engines recommend businesses whose facts are UNAMBIGUOUS and
 * verifiable: consistent name/address/phone, plain who/what/where,
 * concrete numbers instead of adjectives, no unverifiable claims. ONE
 * structured call judges the content AGAINST THE REAL BUSINESS RECORD
 * from the context package — the judge holds the actual facts in hand,
 * so "phone number missing" means the REAL phone number is missing.
 *
 * @package PowerCreatives
 */

if (!defined('ABSPATH')) {
    exit;
}

class PCM_Teacher_Facts implements PCM_Optimizer_Teacher
{
    /** The check set — id · label · question (judged with the business
     *  record in view) · fix. Stable editorial discipline, same
     *  prompt-as-contract pattern as the search teacher. */
    private const CHECKS = array(
        array(
            'id'       => 'nap-present',
            'label'    => 'Name, address, phone present',
            'question' => 'Does the content show the business name, address and phone EXACTLY as given in the business facts (no variants, no missing pieces)?',
            'fix'      => 'Show the business name, address and phone exactly as the business facts state them — consistent, no variants.',
        ),
        array(
            'id'       => 'who-what-where',
            'label'    => 'Who / what / where stated plainly',
            'question' => 'Can a machine reading only this content state plainly WHO the business is, WHAT it does, and WHERE it serves — each in one clear sentence found in the text?',
            'fix'      => 'State plainly, early in the content: who the business is, what it does, and where it serves — one clear sentence each.',
        ),
        array(
            'id'       => 'concrete-numbers',
            'label'    => 'Concrete numbers over adjectives',
            'question' => 'Are the strongest claims backed by concrete numbers (years, counts, response times, ratings) rather than vague adjectives like "leading" or "best"?',
            'fix'      => 'Replace vague superlatives with concrete verifiable numbers — years in business, jobs done, response times, real ratings.',
        ),
        array(
            'id'       => 'verifiable-claims',
            'label'    => 'No unverifiable claims',
            'question' => 'Is the content free of claims that cannot be verified anywhere (invented awards, unsourced statistics, "#1" without a source)?',
            'fix'      => 'Remove or source every unverifiable claim — an AI that catches one invented fact distrusts the whole page.',
        ),
        array(
            'id'       => 'entity-consistent',
            'label'    => 'One consistent identity',
            'question' => 'Does the content refer to the business by ONE consistent name throughout (no alternating spellings, abbreviations, or old names)?',
            'fix'      => 'Use one consistent business name everywhere in the content — exactly the name in the business facts.',
        ),
    );

    public function id(): string
    {
        return 'facts';
    }

    public function label(): string
    {
        return 'Business facts';
    }

    public function order(): int
    {
        return 50;
    }

    public function group(): string
    {
        return 'ai';
    }

    /**
     * One invoke_json call with the REAL business record in the prompt.
     * Without a linked business the record-dependent checks cannot be
     * judged honestly — the teacher says so as its single item.
     *
     * @param array $context See the interface.
     * @return array Catalog items.
     */
    public function analyze(array $context): array
    {
        $business = (array) ($context['business'] ?? array());
        $has_record = trim((string) ($business['name'] ?? '')) !== '';
        if (!$has_record) {
            return array(array(
                'id'          => 'no-business-linked',
                'teacherId'   => $this->id(),
                'found'       => true,
                'label'       => 'No business linked',
                'evidence'    => 'Fact-authority checks compare the content against the real business record — link the business in the editor header first.',
                'instruction' => '',
            ));
        }

        $questions = array_map(
            static fn(array $c): array => array('id' => $c['id'], 'question' => $c['question']),
            self::CHECKS
        );

        // No hidden prompt: this system prompt is a Templates (module=
        // optimizer) row a user can view/edit — resolve_prompt() returns
        // this exact default verbatim when no override exists.
        $default_system = 'You are a strict fact auditor. You hold the business\'s REAL verified facts and the '
            . 'page content. Judge each check ONLY from what is given — the business facts are the truth, '
            . 'the content is what you audit. For every check answer passes=true or passes=false. evidence: '
            . 'when it passes, a short verbatim quote from the content proving it; when it fails, one short '
            . 'sentence naming the concrete thing missing or wrong. Never invent content or facts. Respond '
            . 'with ONLY this JSON, no markdown, no commentary: '
            . '{"checks":[{"id":"<the check id, echoed EXACTLY as given>","passes":true,"evidence":"..."}]} '
            . '— one entry per check, every id present.';

        $messages = array(
            array(
                'role'    => 'system',
                // The exact output contract lives IN the prompt (the
                // Anthropic law — no response_format there).
                'content' => PCM_Optimizer_Service::resolve_prompt('teacher_facts', $default_system, (int) ($context['userId'] ?? 0)),
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
            'name'   => 'optimizer_facts_verdicts',
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
        if (empty($verdicts)) {
            throw new \RuntimeException('The model did not answer the fact checks — re-analyze, or pick another model.');
        }

        $items = array();
        foreach (self::CHECKS as $check) {
            $verdict = $verdicts[$check['id']] ?? null;
            if ($verdict === null) {
                error_log(sprintf('[PCM_Optimizer] facts teacher: model skipped check "%s" — omitted from this run.', $check['id']));
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

PCM_Optimizer_Service::register(new PCM_Teacher_Facts());
