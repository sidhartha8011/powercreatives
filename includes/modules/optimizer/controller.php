<?php
/**
 * Optimizer REST Controller
 *
 * Endpoints:
 *   GET  /optimizer/teachers → the teacher registry (id, label, order)
 *   POST /optimizer/analyze  → run ONE teacher against the page content
 *
 * Analyze takes a single teacherId by design: the rail's per-purpose
 * re-analyze button IS this endpoint — never a separate code path.
 *
 * @package PowerCreatives
 */

if (!defined('ABSPATH')) {
    exit;
}

class PCM_REST_Optimizer extends PCM_REST_Base
{
    // Work module — same bar as the SEO editor it lives inside.
    protected string $default_capability = 'edit_posts';

    /**
     * Define optimizer routes.
     *
     * @return array
     */
    protected function routes(): array
    {
        return [
            ['GET',  '/optimizer/teachers', 'list_teachers'],
            ['POST', '/optimizer/analyze', 'analyze'],
            ['POST', '/optimizer/compile', 'compile'],
        ];
    }

    /**
     * POST /optimizer/compile — THE BASKET COMPILER (one spine stage).
     *
     * Input:  { items: [{instruction, teacherId, label}], model?, provider? }
     * Output: { directives: [{text, purposes: string[], sources: int[]}] }
     *
     * Merges the ticked suggestions into one concise, ordered to-do list.
     * The service enforces the certainty contract: every input item must be
     * covered by the output — a dropped intent is an honest error.
     *
     * @param WP_REST_Request $request Request object.
     * @return WP_REST_Response|WP_Error
     */
    public function compile(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $p     = $request->get_json_params();
        $items = array();
        foreach ((is_array($p) && is_array($p['items'] ?? null)) ? $p['items'] : array() as $it) {
            if (!is_array($it)) {
                continue;
            }
            $instruction = sanitize_text_field((string) ($it['instruction'] ?? ''));
            $teacher_id  = sanitize_key((string) ($it['teacherId'] ?? ''));
            if ($instruction !== '' && $teacher_id !== '') {
                $items[] = array(
                    'instruction' => $instruction,
                    'teacherId'   => $teacher_id,
                    'label'       => sanitize_text_field((string) ($it['label'] ?? '')),
                );
            }
        }
        if (empty($items)) {
            return $this->error('There are no selected suggestions to compile.');
        }

        try {
            $directives = PCM_Optimizer_Service::compile($items, array(
                'model'    => is_array($p) ? sanitize_text_field((string) ($p['model'] ?? '')) : '',
                'provider' => is_array($p) ? sanitize_key((string) ($p['provider'] ?? '')) : '',
                'userId'   => get_current_user_id(),
            ));
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), 502);
        }

        return $this->success(array('directives' => $directives));
    }

    /**
     * GET /optimizer/teachers — the registry, in rail order.
     *
     * @param WP_REST_Request $request Request object.
     * @return WP_REST_Response
     */
    public function list_teachers(WP_REST_Request $request): WP_REST_Response
    {
        return $this->success(array('teachers' => PCM_Optimizer_Service::teacher_meta()));
    }

    /**
     * POST /optimizer/analyze — run ONE teacher.
     *
     * Input:  { teacherId, siteId, postId, html, pageType, model?, provider? }
     * Output: { teacherId, items: [{id, teacherId, found, label, evidence, instruction}] }
     *
     * The content ARRIVES from the editor (the live document is the source
     * of truth there); the server contributes checklist data, identity and
     * the LLM plumbing. Failures are honest errors — the rail shows the
     * failed purpose with its own retry, never a fake empty result.
     *
     * @param WP_REST_Request $request Request object.
     * @return WP_REST_Response|WP_Error
     */
    public function analyze(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $p          = $request->get_json_params();
        $teacher_id = is_array($p) ? sanitize_key((string) ($p['teacherId'] ?? '')) : '';
        $html       = is_array($p) ? (string) ($p['html'] ?? '') : '';
        if ($teacher_id === '') {
            return $this->error('teacherId is required.');
        }
        if (trim($html) === '') {
            return $this->error('There is no content to analyze.');
        }

        $context = array(
            'siteId'   => is_array($p) ? (int) ($p['siteId'] ?? 0) : 0,
            'postId'   => is_array($p) ? (int) ($p['postId'] ?? 0) : 0,
            'html'     => $html,
            'pageType' => is_array($p) ? sanitize_key((string) ($p['pageType'] ?? 'general')) : 'general',
            'model'    => is_array($p) ? sanitize_text_field((string) ($p['model'] ?? '')) : '',
            'provider' => is_array($p) ? sanitize_key((string) ($p['provider'] ?? '')) : '',
            // The CALLER's identity rides every LLM call (key lookup must
            // never lean on an absent session — the model-truth lesson).
            'userId'   => get_current_user_id(),
        );

        try {
            $items = PCM_Optimizer_Service::analyze($teacher_id, $context);
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), 502);
        }

        return $this->success(array(
            'teacherId' => $teacher_id,
            'items'     => $items,
        ));
    }
}
