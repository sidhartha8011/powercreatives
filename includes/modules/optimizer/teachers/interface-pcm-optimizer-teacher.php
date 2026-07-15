<?php
/**
 * The Teacher contract — every optimization purpose implements exactly
 * this and nothing more. A teacher is a pure function: context in,
 * catalog items out. It owns ONE file, registers itself on load
 * (PCM_Optimizer_Service::register), and can never reach another teacher
 * — shared capabilities live in core services below all of them.
 *
 * Catalog item shape (the one contract, forever):
 *   { id, teacherId, found, label, evidence, instruction }
 *
 * @package PowerCreatives
 */

if (!defined('ABSPATH')) {
    exit;
}

interface PCM_Optimizer_Teacher
{
    /** Stable machine id — also the catalog items' teacherId. */
    public function id(): string;

    /** The rail section's human label. */
    public function label(): string;

    /** Rail position — lower renders first. */
    public function order(): int;

    /** The rail's top group (owner taxonomy ruling 2026-07-14):
     *  'search' = Search optimization · 'ai' = AI optimization.
     *  Overlap between groups is fine by design — the compiler reconciles. */
    public function group(): string;

    /**
     * Analyze the page and contribute catalog items.
     *
     * @param array $context {siteId, postId, html, pageType, model,
     *                        provider, userId, keywords {primary,
     *                        supporting[], additional[]}, business
     *                        (resolved brand record), pages[]} — see the
     *                        controller. Items may carry an optional
     *                        `source` (which integration/engine answered).
     * @return array<int, array{id: string, teacherId: string, found: bool,
     *                          label: string, evidence: string,
     *                          instruction: string}>
     * @throws \Throwable On real failure — the rail renders the purpose
     *                    as failed with its own retry; never fake-empty.
     */
    public function analyze(array $context): array;
}
