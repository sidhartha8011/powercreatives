<?php
/**
 * Strategy REST Controller
 *
 * Full CRUD for content strategies + generation trigger.
 * Strategies group keywords with a brand and template to orchestrate
 * the content pipeline: Keywords → Strategy → Writer → Sites.
 *
 * Endpoints:
 *   GET    /strategies                      List all strategies
 *   POST   /strategies                      Create strategy + items
 *   GET    /strategies/(?P<id>\d+)          Get strategy with items
 *   PATCH  /strategies/(?P<id>\d+)          Update strategy
 *   DELETE /strategies/(?P<id>\d+)          Delete strategy + items
 *   POST   /strategies/(?P<id>\d+)/generate Generate next pending item
 *
 * @package PowerCreatives
 */

if (!defined('ABSPATH')) {
    exit;
}

class PCM_REST_Strategy extends PCM_REST_Base
{
    // Work module — usable by non-admin team members (assigned access).
    protected string $default_capability = 'edit_posts';
    // Per-delivery module grant ids (see PCM_REST_Base::$module_grant_keys).
    protected array $module_grant_keys = array('strategies');

    protected function routes(): array
    {
        return array(
            array('GET',    '/strategies',                      'list_strategies'),
            array('POST',   '/strategies',                      'create_strategy'),
            array('GET',    '/strategies/(?P<id>\d+)',           'get_strategy'),
            array('PATCH',  '/strategies/(?P<id>\d+)',           'update_strategy'),
            array('DELETE', '/strategies/(?P<id>\d+)',           'delete_strategy'),
            array('POST',   '/strategies/(?P<id>\d+)/generate',  'generate_item'),
        );
    }

    /**
     * List all strategies for the current user.
     * Returns strategies with item counts (not individual items).
     */
    public function list_strategies(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $pcm_user = $this->get_current_pcm_user();
        $strategies = PCM_DB::get_user_strategies((int)$pcm_user->id);
        return $this->success($strategies);
    }

    /**
     * Create a strategy from a keyword selection.
     *
     * Expected body:
     * {
     *   name: string,
     *   templateId: number,
     *   brandId?: number,
     *   keywords: string[],
     *   hierarchyMode?: string,
     *   publishingMode?: string,
     *   config?: object
     * }
     */
    public function create_strategy(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $params = $request->get_json_params();
        $pcm_user = $this->get_current_pcm_user();
        $user_id = (int)$pcm_user->id;

        // ── Validate required fields ──
        if (empty($params['name'])) {
            return $this->error('Strategy name is required.');
        }
        if (empty($params['templateId'])) {
            return $this->error('Template ID is required.');
        }
        if (empty($params['keywords']) || !is_array($params['keywords'])) {
            return $this->error('Keywords array is required.');
        }

        try {
            // Delegate to the service layer for orchestration
            require_once __DIR__ . '/service.php';
            $strategy = PCM_Strategy_Service::create_from_keywords(
                $user_id,
                sanitize_text_field($params['name']),
                (int)$params['templateId'],
                !empty($params['brandId']) ? (int)$params['brandId'] : null,
                array_map('sanitize_text_field', $params['keywords']),
                array(
                    'hierarchyMode'  => sanitize_text_field($params['hierarchyMode'] ?? 'standalone'),
                    'publishingMode' => sanitize_text_field($params['publishingMode'] ?? 'draft'),
                    'config'         => $params['config'] ?? null,
                )
            );

            return $this->success($strategy, 201);
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    /**
     * Get a single strategy with its items.
     */
    public function get_strategy(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $pcm_user = $this->get_current_pcm_user();
        $strategy_id = (int)$request->get_param('id');

        $strategy = PCM_DB::get_strategy($strategy_id, (int)$pcm_user->id);
        if (!$strategy) {
            return $this->not_found('Strategy');
        }

        // Attach items to response
        $strategy->items = PCM_DB::get_strategy_items($strategy_id);

        return $this->success($strategy);
    }

    /**
     * Update strategy metadata (name, status, config).
     */
    public function update_strategy(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $pcm_user = $this->get_current_pcm_user();
        $strategy_id = (int)$request->get_param('id');
        $params = $request->get_json_params();

        // Whitelist updateable fields
        $allowed = array('name', 'status', 'hierarchyMode', 'publishingMode', 'config');
        $update = array();
        foreach ($allowed as $field) {
            if (isset($params[$field])) {
                $update[$field] = is_string($params[$field])
                    ? sanitize_text_field($params[$field])
                    : wp_json_encode($params[$field]);
            }
        }

        if (empty($update)) {
            return $this->error('No valid fields to update.');
        }

        $success = PCM_DB::update_strategy($strategy_id, (int)$pcm_user->id, $update);
        if (!$success) {
            return $this->not_found('Strategy');
        }

        // Return updated strategy
        $strategy = PCM_DB::get_strategy($strategy_id, (int)$pcm_user->id);
        $strategy->items = PCM_DB::get_strategy_items($strategy_id);
        return $this->success($strategy);
    }

    /**
     * Delete a strategy and all its items.
     */
    public function delete_strategy(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $pcm_user = $this->get_current_pcm_user();
        $strategy_id = (int)$request->get_param('id');

        $success = PCM_DB::delete_strategy($strategy_id, (int)$pcm_user->id);
        if (!$success) {
            return $this->not_found('Strategy');
        }

        return $this->success(array('deleted' => true));
    }

    /**
     * Generate the next pending item in a strategy.
     * Uses the strategy's template + brand context to invoke PCM_LLM.
     * Creates an article in the articles table on success.
     */
    public function generate_item(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $pcm_user = $this->get_current_pcm_user();
        $strategy_id = (int)$request->get_param('id');

        // Verify strategy ownership
        $strategy = PCM_DB::get_strategy($strategy_id, (int)$pcm_user->id);
        if (!$strategy) {
            return $this->not_found('Strategy');
        }

        try {
            require_once __DIR__ . '/service.php';
            $result = PCM_Strategy_Service::generate_next_item($strategy, (int)$pcm_user->id);

            if (!$result) {
                return $this->success(array(
                    'complete' => true,
                    'message'  => 'All items have been generated.',
                ));
            }

            return $this->success($result);
        } catch (\Throwable $e) {
            return $this->error('Generation failed: ' . $e->getMessage(), 500);
        }
    }
}
