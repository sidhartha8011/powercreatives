<?php
/**
 * Teacher: Topic coverage (the knowledge-graph purpose).
 *
 * A COMPLETE page about a topic covers the topic's core branches — that is
 * what actually ranks head terms (docs/RESEARCH-CONTENT-ANALYSIS-20260713.md
 * §1A). ONE structured call derives the page's core topic, lists the
 * subtopics a complete page covers, and judges each against THIS content:
 * covered → quiet with a verbatim quote; missing → a tickable suggestion
 * whose instruction adds a coverage section through the normal pipeline.
 *
 * @package PowerCreatives
 */

if (!defined('ABSPATH')) {
    exit;
}

class PCM_Teacher_Subtopics implements PCM_Optimizer_Teacher
{
    public function id(): string
    {
        return 'subtopics';
    }

    public function label(): string
    {
        return 'Topic coverage';
    }

    public function order(): int
    {
        return 20;
    }

    /**
     * One invoke_json call; verdicts land on the catalog contract. An
     * off-contract answer (no subtopics) is an honest failure — the rail
     * shows the purpose failed with its own retry.
     *
     * @param array $context See the interface.
     * @return array Catalog items.
     */
    public function analyze(array $context): array
    {
        $messages = array(
            array(
                'role'    => 'system',
                // The exact output contract lives IN the prompt (the
                // Anthropic law — no response_format there).
                'content' => 'You are a topical-coverage auditor. From the page content, determine the ONE core '
                    . 'topic, then list the 4 to 7 subtopics a COMPLETE page about that topic covers (what '
                    . 'genuinely comprehensive pages on this topic actually include — never filler). Judge each '
                    . 'subtopic ONLY against the given content: covered=true needs real substance about it, not a '
                    . 'passing mention; evidence = a short verbatim quote when covered, or one short sentence of '
                    . 'what a section about it should say when missing. Respond with ONLY this JSON, no markdown: '
                    . '{"topic":"...","subtopics":[{"name":"...","covered":true,"evidence":"..."}]}',
            ),
            array(
                'role'    => 'user',
                'content' => 'PAGE TYPE: ' . (string) ($context['pageType'] ?? 'general') . "\n\n"
                    . "PAGE CONTENT (HTML):\n" . (string) $context['html'],
            ),
        );

        $schema = array(
            'name'   => 'optimizer_subtopic_coverage',
            'schema' => array(
                'type'       => 'object',
                'properties' => array(
                    'topic'     => array('type' => 'string'),
                    'subtopics' => array(
                        'type'  => 'array',
                        'items' => array(
                            'type'       => 'object',
                            'properties' => array(
                                'name'     => array('type' => 'string'),
                                'covered'  => array('type' => 'boolean'),
                                'evidence' => array('type' => 'string'),
                            ),
                            'required'   => array('name', 'covered', 'evidence'),
                        ),
                    ),
                ),
                'required'   => array('topic', 'subtopics'),
            ),
        );

        $parsed = PCM_LLM::invoke_json($messages, $schema, array(
            'model'    => (string) ($context['model'] ?? '') ?: null,
            'provider' => (string) ($context['provider'] ?? '') ?: null,
            'user_id'  => (int) ($context['userId'] ?? 0),
        ));

        $topic     = trim((string) ($parsed['topic'] ?? ''));
        $subtopics = array_values(array_filter(
            (array) ($parsed['subtopics'] ?? array()),
            static fn($s): bool => is_array($s) && trim((string) ($s['name'] ?? '')) !== ''
        ));
        if ($topic === '' || empty($subtopics)) {
            throw new \RuntimeException('The model did not map the topic coverage — re-analyze, or pick another model.');
        }

        $items = array();
        foreach ($subtopics as $s) {
            $name    = trim((string) $s['name']);
            $covered = (bool) $s['covered'];
            $items[] = array(
                'id'          => sanitize_title($name),
                'teacherId'   => $this->id(),
                'found'       => !$covered, // a missing subtopic is the gap
                'label'       => sprintf('Subtopic: %s', $name),
                'evidence'    => (string) ($s['evidence'] ?? ''),
                'instruction' => $covered ? '' : sprintf(
                    'Add a short, substantive section covering "%s" — completing the page\'s coverage of %s.',
                    $name,
                    $topic
                ),
            );
        }
        return $items;
    }
}

PCM_Optimizer_Service::register(new PCM_Teacher_Subtopics());
