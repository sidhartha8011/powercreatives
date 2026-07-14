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
