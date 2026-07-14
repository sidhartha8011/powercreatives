<?php
/**
 * Optimizer Service — THE ENGINE, and only the engine.
 *
 * Owns: the teacher registry (one file per teacher under teachers/,
 * lazy-discovered — adding a purpose is adding a file, nothing central
 * ever changes), the checklist DATA (a seeded, editable option — never
 * hardcoded logic), and the analyze dispatch. NO teacher logic lives here;
 * shared capabilities teachers need (LLM, GSC, brand data) are consumed
 * from core services, keeping every teacher a pure context-in/items-out
 * function that structurally cannot talk to another teacher.
 *
 * The catalog-item contract (ONE shape for every teacher, forever):
 *   { id, teacherId, found, label, evidence, instruction }
 *   found=true  → a gap/opportunity: rendered tickable, pre-selected
 *   found=false → nothing to fix: rendered quiet
 *   instruction → the directive text that rides the user's basket into
 *                 the ONE optimization run
 *
 * @package PowerCreatives
 */

if (!defined('ABSPATH')) {
    exit;
}

class PCM_Optimizer_Service
{
    /** Option holding the editable checklist data (seeded on first read). */
    private const CHECKLISTS_OPTION = 'pcm_optimizer_checklists';

    /** Option holding the per-page keyword buckets, keyed "siteId:postId"
     *  (the proven option-map pattern — a table comes only if scale demands). */
    private const KW_BUCKET_OPTION = 'pcm_optimizer_kw_bucket';

    /** Option holding the last successful GSC keyword rows per page, keyed
     *  "siteId:postId" — the drawer serves these LABELED when Google is
     *  unreachable (never silently), and they make the table seedable as
     *  pure data. Rows capped so the map stays bounded. */
    private const KW_STATS_CACHE_OPTION = 'pcm_optimizer_kw_stats_cache';
    /** Rows arrive clicks-desc, so the cap keeps the most significant. The
     *  LIVE view is always complete — this bounds only the stored copy. */
    private const KW_STATS_CACHE_MAX_ROWS = 1000;

    /**
     * Registered teachers, keyed by id. Populated by load_teachers().
     *
     * @var array<string, PCM_Optimizer_Teacher>
     */
    private static array $teachers = [];

    /** Whether the teachers/ directory has been loaded. */
    private static bool $loaded = false;

    /**
     * Called by each file in teachers/ as it loads — the ONLY way in.
     *
     * @param PCM_Optimizer_Teacher $teacher The teacher instance.
     * @return void
     */
    public static function register(PCM_Optimizer_Teacher $teacher): void
    {
        self::$teachers[$teacher->id()] = $teacher;
    }

    /**
     * Lazy-load every teacher file (the automations-glob pattern): each
     * file defines one class and registers one instance. Load order inside
     * the directory is irrelevant — rail order comes from order().
     *
     * @return void
     */
    private static function load_teachers(): void
    {
        if (self::$loaded) {
            return;
        }
        self::$loaded = true;
        require_once __DIR__ . '/teachers/interface-pcm-optimizer-teacher.php';
        foreach (glob(__DIR__ . '/teachers/class-pcm-teacher-*.php') as $file) {
            require_once $file;
        }
    }

    /**
     * Registry metadata for the rail, sorted by order().
     *
     * @return array<int, array{id: string, label: string, order: int}>
     */
    public static function teacher_meta(): array
    {
        self::load_teachers();
        $meta = array();
        foreach (self::$teachers as $t) {
            $meta[] = array('id' => $t->id(), 'label' => $t->label(), 'order' => $t->order());
        }
        usort($meta, static fn(array $a, array $b): int => $a['order'] <=> $b['order']);
        return $meta;
    }

    /**
     * Dispatch ONE teacher's analysis.
     *
     * @param string $teacher_id The teacher to run.
     * @param array  $context    Content + identity (see controller).
     * @return array Catalog items.
     * @throws \RuntimeException When the teacher does not exist (an honest
     *                          404-class error, never an empty result).
     */
    public static function analyze(string $teacher_id, array $context): array
    {
        self::load_teachers();
        $teacher = self::$teachers[$teacher_id] ?? null;
        if ($teacher === null) {
            throw new \RuntimeException(sprintf('Unknown analysis "%s".', $teacher_id));
        }
        return $teacher->analyze($context);
    }

    /**
     * THE BASKET COMPILER (owner law 2026-07-13): the ticked suggestions are
     * never dumped raw at the rewriting model — ONE reconciliation call
     * merges overlaps and resolves collisions into a concise ordered to-do
     * list. THE CERTAINTY CONTRACT: every input item must be covered by the
     * output's `sources` union — a dropped intent is a thrown error, never a
     * quiet loss. Each directive carries its source purposes (teacherIds) —
     * the provenance the review's purpose bullets and pills stand on.
     *
     * A single item bypasses the model deterministically: there is nothing
     * to merge, and a no-op LLM hop is a cost without a function.
     *
     * @param array $items   [{instruction, teacherId, label}] — sanitized.
     * @param array $context {model, provider, userId}.
     * @return array<int, array{text: string, purposes: string[], sources: int[]}>
     * @throws \RuntimeException When the model drops an intent or answers
     *                           off-contract.
     */
    public static function compile(array $items, array $context): array
    {
        if (count($items) === 1) {
            return array(array(
                'text'     => $items[0]['instruction'],
                'purposes' => array($items[0]['teacherId']),
                'sources'  => array(0),
            ));
        }

        $numbered = array();
        foreach ($items as $i => $it) {
            $numbered[] = array('index' => $i, 'purpose' => $it['teacherId'], 'instruction' => $it['instruction']);
        }

        $messages = array(
            array(
                'role'    => 'system',
                // The exact output contract lives IN the prompt (the Anthropic
                // law — PCM_LLM drops response_format there by design).
                'content' => 'You compile content-optimization directives into ONE concise, ordered to-do list for a '
                    . 'rewriting AI. Rules: NEVER drop an intent — every input index must appear in at least one '
                    . 'directive\'s sources; MERGE overlapping directives into one stronger directive; when two '
                    . 'directives collide, produce one directive that explicitly preserves both intents; order by '
                    . 'execution sense (structure first, then content, then wording). Keep each directive one '
                    . 'sentence, imperative, self-contained. Respond with ONLY this JSON, no markdown: '
                    . '{"directives":[{"text":"...","sources":[0,2]}]} — sources are the input indexes each '
                    . 'directive covers.',
            ),
            array(
                'role'    => 'user',
                'content' => "INPUT DIRECTIVES:\n" . wp_json_encode($numbered),
            ),
        );

        $schema = array(
            'name'   => 'optimizer_compiled_directives',
            'schema' => array(
                'type'       => 'object',
                'properties' => array(
                    'directives' => array(
                        'type'  => 'array',
                        'items' => array(
                            'type'       => 'object',
                            'properties' => array(
                                'text'    => array('type' => 'string'),
                                'sources' => array('type' => 'array', 'items' => array('type' => 'integer')),
                            ),
                            'required'   => array('text', 'sources'),
                        ),
                    ),
                ),
                'required'   => array('directives'),
            ),
        );

        $parsed = PCM_LLM::invoke_json($messages, $schema, array(
            'model'    => (string) ($context['model'] ?? '') ?: null,
            'provider' => (string) ($context['provider'] ?? '') ?: null,
            'user_id'  => (int) ($context['userId'] ?? 0),
        ));

        $out     = array();
        $covered = array();
        foreach ((array) ($parsed['directives'] ?? array()) as $row) {
            if (!is_array($row) || trim((string) ($row['text'] ?? '')) === '') {
                continue;
            }
            $sources  = array();
            $purposes = array();
            foreach ((array) ($row['sources'] ?? array()) as $src) {
                $i = (int) $src;
                if (!isset($items[$i])) {
                    continue; // out-of-range index — ignore the claim, coverage check judges the truth
                }
                $sources[]               = $i;
                $covered[$i]             = true;
                $purposes[$items[$i]['teacherId']] = true;
            }
            if (empty($sources)) {
                continue;
            }
            $out[] = array(
                'text'     => trim((string) $row['text']),
                'purposes' => array_keys($purposes),
                'sources'  => $sources,
            );
        }

        // THE CERTAINTY CHECK: every intent accounted for, or an honest error.
        $missing = array();
        foreach ($items as $i => $it) {
            if (!isset($covered[$i])) {
                $missing[] = $it['label'] !== '' ? $it['label'] : $it['instruction'];
            }
        }
        if (!empty($missing) || empty($out)) {
            throw new \RuntimeException(sprintf(
                'The compiler failed to account for every selected optimization (%s) — try again or pick another model.',
                empty($missing) ? 'empty result' : implode(', ', array_slice($missing, 0, 5))
            ));
        }

        return $out;
    }

    /**
     * The page's keyword bucket — the additional keywords the user picked
     * from GSC, riding EVERY optimize run (owner law 2026-07-13).
     *
     * @param int $site_id Site id.
     * @param int $post_id Post id.
     * @return string[]
     */
    public static function bucket_get(int $site_id, int $post_id): array
    {
        $map = get_option(self::KW_BUCKET_OPTION);
        $key = $site_id . ':' . $post_id;
        return (is_array($map) && is_array($map[$key] ?? null)) ? array_values($map[$key]) : array();
    }

    /**
     * Persist one page's bucket ([] deletes the entry — the map never
     * accumulates empty rows).
     *
     * @param int   $site_id  Site id.
     * @param int   $post_id  Post id.
     * @param array $keywords Sanitized, deduped keyword list.
     * @return void
     */
    public static function bucket_save(int $site_id, int $post_id, array $keywords): void
    {
        $map = get_option(self::KW_BUCKET_OPTION);
        if (!is_array($map)) {
            $map = array();
        }
        $key = $site_id . ':' . $post_id;
        if (empty($keywords)) {
            unset($map[$key]);
        } else {
            $map[$key] = array_values($keywords);
        }
        update_option(self::KW_BUCKET_OPTION, $map, false);
    }

    /**
     * The stored keyword rows for one page, or null when never fetched.
     *
     * @param int $site_id Site id.
     * @param int $post_id Post id.
     * @return array{property: string, rows: array, fetchedAt: int}|null
     */
    public static function kw_stats_cache_get(int $site_id, int $post_id): ?array
    {
        $map   = get_option(self::KW_STATS_CACHE_OPTION);
        $entry = (is_array($map)) ? ($map[$site_id . ':' . $post_id] ?? null) : null;
        return (is_array($entry) && is_array($entry['rows'] ?? null)) ? $entry : null;
    }

    /**
     * Store a page's keyword rows after a successful live fetch (capped —
     * the map stays bounded).
     *
     * @param int    $site_id  Site id.
     * @param int    $post_id  Post id.
     * @param string $property The GSC property that answered.
     * @param array  $rows     Live rows (plain or compare-merged).
     * @param array  $meta     {days, compare} — what the stored set IS.
     * @return void
     */
    public static function kw_stats_cache_save(int $site_id, int $post_id, string $property, array $rows, array $meta = array()): void
    {
        $map = get_option(self::KW_STATS_CACHE_OPTION);
        if (!is_array($map)) {
            $map = array();
        }
        $map[$site_id . ':' . $post_id] = array(
            'property'  => $property,
            'rows'      => array_slice(array_values($rows), 0, self::KW_STATS_CACHE_MAX_ROWS),
            'fetchedAt' => time(),
            'days'      => (int) ($meta['days'] ?? 0),
            'compare'   => (bool) ($meta['compare'] ?? false),
        );
        update_option(self::KW_STATS_CACHE_OPTION, $map, false);
    }

    /**
     * Merge a current period's rows with the previous period's, per query:
     * every current row gains `prev` + `d` (the trend deltas); keywords seen
     * ONLY in the previous period stay in the list with zeroed current
     * metrics and negative deltas — a vanishing keyword IS the trend signal.
     * Positions honest by construction: no previous rank → `d.position`
     * null (a dash, never a fake zero); vanished → current position null.
     *
     * @param array $current  Current-period rows {query, clicks, impressions, position}.
     * @param array $previous Previous-period rows, same shape.
     * @return array Merged rows.
     */
    public static function merge_compare(array $current, array $previous): array
    {
        $prev_by = array();
        foreach ($previous as $r) {
            if (is_array($r) && isset($r['query'])) {
                $prev_by[(string) $r['query']] = $r;
            }
        }
        $out = array();
        foreach ($current as $r) {
            $p = $prev_by[(string) $r['query']] ?? null;
            unset($prev_by[(string) $r['query']]);
            $out[] = $r + array(
                'prev' => $p === null ? null : array(
                    'clicks'      => (int) $p['clicks'],
                    'impressions' => (int) $p['impressions'],
                    'position'    => (float) $p['position'],
                ),
                'd'    => array(
                    'clicks'      => (int) $r['clicks'] - (int) ($p['clicks'] ?? 0),
                    'impressions' => (int) $r['impressions'] - (int) ($p['impressions'] ?? 0),
                    'position'    => $p === null ? null : round((float) $r['position'] - (float) $p['position'], 1),
                ),
            );
        }
        foreach ($prev_by as $p) {
            $out[] = array(
                'query'       => (string) $p['query'],
                'clicks'      => 0,
                'impressions' => 0,
                'position'    => null,
                'prev'        => array(
                    'clicks'      => (int) $p['clicks'],
                    'impressions' => (int) $p['impressions'],
                    'position'    => (float) $p['position'],
                ),
                'd'           => array(
                    'clicks'      => -(int) $p['clicks'],
                    'impressions' => -(int) $p['impressions'],
                    'position'    => null,
                ),
            );
        }
        return $out;
    }

    /**
     * The checklist for one page type: its own checks + the universal ones.
     * Unknown page types honestly get only the universal checks.
     *
     * @param string $page_type One of the page-type dropdown values.
     * @return array<int, array{id: string, label: string, question: string, instruction: string}>
     */
    public static function checklist_for(string $page_type): array
    {
        $data     = self::checklists();
        $per_type = $data['perType'][$page_type] ?? array();
        return array_merge($per_type, $data['universal']);
    }

    /**
     * The checklist DATA — read-through seeded option, editable without
     * code (the standing hub-controlled-data law; same pattern as the
     * model tier thresholds).
     *
     * @return array{version: int, universal: array, perType: array<string, array>}
     */
    public static function checklists(): array
    {
        $stored = get_option(self::CHECKLISTS_OPTION);
        if (is_array($stored) && !empty($stored['universal'])) {
            return $stored;
        }
        $seed = self::default_checklists();
        add_option(self::CHECKLISTS_OPTION, $seed, '', false);
        return $seed;
    }

    /**
     * Seed checklists (docs/RESEARCH-CONTENT-ANALYSIS-20260713.md §1) —
     * the starting DATA, tuned later by editing the option, never by
     * editing code. Each check: id · label (the rail row) · question (what
     * the judge answers) · instruction (the directive a ticked row sends
     * into the optimization).
     *
     * @return array{version: int, universal: array, perType: array<string, array>}
     */
    private static function default_checklists(): array
    {
        $check = static fn(string $id, string $label, string $question, string $instruction): array =>
            array('id' => $id, 'label' => $label, 'question' => $question, 'instruction' => $instruction);

        $local = array(
            $check(
                'above-fold-contact',
                'Contact visible early',
                'Is a phone number or direct contact action present in the first section of the content?',
                'Place the business phone number and an easy contact action in the first section.'
            ),
            $check(
                'above-fold-cta',
                'Clear call-to-action early',
                'Is there one clear call-to-action (call, book, request a quote) early in the content?',
                'Add one clear call-to-action (call, book, or request a quote) early in the content.'
            ),
            $check(
                'service-place-named',
                'Service + place named early',
                'Do the first heading or first paragraph literally name BOTH the service and the place it serves?',
                'Name the service and the served location literally in the first heading or first paragraph.'
            ),
            $check(
                'usp-concrete',
                'Concrete selling points',
                'Are the unique selling points stated as concrete facts (years, guarantees, certifications, response times) rather than vague adjectives?',
                'State the unique selling points as concrete facts (years in business, guarantees, certifications, response times) — no vague adjectives.'
            ),
            $check(
                'social-proof',
                'Reviews / social proof',
                'Does the content show reviews, ratings, or other customer proof?',
                'Add visible customer proof — a review quote, rating, or customer count.'
            ),
            $check(
                'plain-language',
                'Simple human language',
                'Is the language simple, direct and conversational — short sentences, everyday words, no academic prose?',
                'Rewrite overly formal or academic passages into simple, direct, conversational language with short sentences.'
            ),
        );

        $supporting = array(
            $check(
                'answer-first',
                'Question answered immediately',
                'Is the specific question this content targets answered plainly in the first paragraph?',
                'Answer the content\'s core question plainly in the first paragraph; depth follows after.'
            ),
            $check(
                'depth-over-breadth',
                'Covers its topic deeply',
                'Does the content cover its one narrow topic exhaustively instead of skimming many topics?',
                'Deepen the coverage of the core topic: concrete details, steps, numbers — not more topics.'
            ),
        );

        $conversion = array(
            $check(
                'above-fold-cta',
                'Clear call-to-action early',
                'Is there one clear call-to-action early in the content?',
                'Add one clear call-to-action early in the content.'
            ),
            $check(
                'usp-concrete',
                'Concrete selling points',
                'Are the selling points stated as concrete facts rather than vague adjectives?',
                'State the selling points as concrete facts — no vague adjectives.'
            ),
            $check(
                'trust-signals',
                'Trust signals',
                'Does the content show trust signals — guarantees, certifications, years in business, real customer proof?',
                'Add concrete trust signals: guarantees, certifications, years in business, or customer proof.'
            ),
        );

        $universal = array(
            $check(
                'intent-answered-early',
                'Intent answered at the top',
                'Does the first screen of content directly answer what a visitor searching this topic wants to know?',
                'Answer the visitor\'s core question directly at the top of the content — no warm-up.'
            ),
            $check(
                'no-fluff-intro',
                'No fluff introduction',
                'Is the content free of generic filler introductions that delay the substance?',
                'Delete generic filler introductions — start at the substance.'
            ),
            $check(
                'scannable-structure',
                'Scannable structure',
                'Is the content scannable — descriptive headings per topic, lists where things are enumerable?',
                'Restructure for scannability: descriptive headings per topic, bullet lists for enumerable things.'
            ),
            $check(
                'natural-flow',
                'Natural human flow',
                'Does the text read like a person talking — natural transitions, varied sentences, no keyword stuffing?',
                'Rewrite unnatural or keyword-stuffed passages so the text reads like a person talking.'
            ),
        );

        return array(
            'version'   => 1,
            'universal' => $universal,
            'perType'   => array(
                'general' => array(),
                'local'   => $local,
                'service' => $local,
                'blog'    => $supporting,
                'product' => $conversion,
                'landing' => $conversion,
            ),
        );
    }
}
