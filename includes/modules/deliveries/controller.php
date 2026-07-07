<?php
/**
 * Deliveries REST Controller
 *
 * Thin router for the Deliveries module. Routing + input validation +
 * response formatting only — all CRUD goes through PCM_DB, all business
 * logic through PCM_Deliveries_Service.
 *
 * Endpoints:
 *   GET    /deliveries          → list (current user)
 *   GET    /deliveries/<id>     → getById
 *   POST   /deliveries          → create
 *   PATCH  /deliveries/<id>     → update
 *   DELETE /deliveries/<id>     → delete
 *
 * @package PowerCreatives
 * @since   1.6.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class PCM_REST_Deliveries extends PCM_REST_Base
{
    // Work module — usable by non-admin team members (assigned access).
    protected string $default_capability = 'edit_posts';


    /**
     * Service instance — formatting + status validation.
     *
     * @var PCM_Deliveries_Service
     */
    private PCM_Deliveries_Service $service;

    public function __construct()
    {
        $this->service = new PCM_Deliveries_Service();
    }

    /**
     * Define all delivery routes.
     *
     * @return array
     */
    protected function routes(): array
    {
        return array(
            // Static segment before the (?P<id>\d+) routes so it can't be
            // swallowed by the numeric matcher. Presets feed the admin-only
            // delivery dialog / Settings tab.
            array('GET',    '/deliveries/type-presets',       'get_type_presets', array(), 'manage_options'),
            array('GET',    '/deliveries',                    'list_items'),
            array('GET',    '/deliveries/(?P<id>\\d+)',       'get_by_id'),
            // Work log — append-only "what was done" notes on the delivery
            // card. Team members can read and add; entries are never edited.
            array('GET',    '/deliveries/(?P<id>\\d+)/logs',  'list_logs'),
            array('POST',   '/deliveries/(?P<id>\\d+)/logs',  'add_log'),
            // Writes are admin-only; team members only view assigned deliveries.
            array('POST',   '/deliveries',                    'create_item', array(), 'manage_options'),
            array('PATCH',  '/deliveries/(?P<id>\\d+)',       'update_item', array(), 'manage_options'),
            array('DELETE', '/deliveries/(?P<id>\\d+)',       'delete_item', array(), 'manage_options'),
        );
    }

    // =========================================================================
    // READ
    // =========================================================================

    /**
     * GET /deliveries/type-presets — Resolved delivery-type → modules map.
     * Central setting: admin-customized via the Settings UI
     * (pcm_settings.delivery_type_presets), built-in defaults otherwise.
     */
    public function get_type_presets(WP_REST_Request $request): WP_REST_Response
    {
        return $this->success(array(
            'presets'          => PCM_Deliveries_Service::type_presets(),
            'grantableModules' => PCM_Deliveries_Service::GRANTABLE_MODULES,
        ));
    }

    /** GET /deliveries — List all deliveries for the current user. */
    public function list_items(WP_REST_Request $request): WP_REST_Response
    {
        $user = $this->get_current_pcm_user();
        $rows = PCM_DB::get_user_deliveries($user->id);

        $items = array_map(array($this->service, 'format_delivery'), $rows);

        // Batch-attach assignees ({id, name} per delivery) from
        // delivery_assignments — read-only enrichment for list surfaces (the
        // Deliveries table's Assignee column). Assigning itself stays in the
        // Users module (PUT /users/{id}/deliveries).
        if (!empty($items)) {
            global $wpdb;
            $ids = array_map(static function (array $item): int {
                return (int) $item['id'];
            }, $items);
            $placeholders = implode(',', array_fill(0, count($ids), '%d'));
            $assign_table = PCM_Schema::table('delivery_assignments');
            $users_table  = PCM_Schema::table('users');
            // phpcs:ignore WordPress.DB.PreparedSQL -- placeholders built from %d only.
            $assignment_rows = $wpdb->get_results($wpdb->prepare(
                "SELECT a.deliveryId, u.id AS userId, u.name
                 FROM $assign_table a
                 INNER JOIN $users_table u ON u.id = a.userId
                 WHERE a.deliveryId IN ($placeholders)
                 ORDER BY u.name ASC",
                ...$ids
            ));
            $by_delivery = array();
            foreach ($assignment_rows as $assignment) {
                $by_delivery[(int) $assignment->deliveryId][] = array(
                    'id'   => (int) $assignment->userId,
                    'name' => (string) $assignment->name,
                );
            }
            foreach ($items as &$item) {
                $item['assignees'] = $by_delivery[(int) $item['id']] ?? array();
            }
            unset($item);
        }

        return $this->success($items);
    }

    /** GET /deliveries/<id> — Get a single delivery by ID. */
    public function get_by_id(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $user = $this->get_current_pcm_user();
        $row  = PCM_DB::get_delivery_by_id(absint($request->get_param('id')), $user->id);

        if (!$row) {
            return $this->not_found('Delivery');
        }

        return $this->success($this->service->format_delivery($row));
    }

    // =========================================================================
    // WORK LOG
    // =========================================================================

    /** GET /deliveries/<id>/logs — Work-log entries, newest first. */
    public function list_logs(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        global $wpdb;
        $user = $this->get_current_pcm_user();
        $id   = absint($request->get_param('id'));

        if (!PCM_DB::get_delivery_by_id($id, (int) $user->id)) {
            return $this->not_found('Delivery');
        }

        $logs  = PCM_Schema::table('delivery_logs');
        $users = PCM_Schema::table('users');
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT l.id, l.userId, l.note, l.createdAt, u.name AS userName, u.username
             FROM {$logs} l LEFT JOIN {$users} u ON u.id = l.userId
             WHERE l.deliveryId = %d ORDER BY l.createdAt DESC, l.id DESC",
            $id
        ));

        return $this->success(array_map(static function ($row) {
            return array(
                'id'        => (int) $row->id,
                'userId'    => (int) $row->userId,
                'note'      => (string) $row->note,
                'createdAt' => $row->createdAt,
                'userName'  => $row->userName ?: ($row->username ?: __('Unknown user', 'power-creatives')),
            );
        }, $rows));
    }

    /** POST /deliveries/<id>/logs — Append a work-log entry. Body: { note }. */
    public function add_log(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        global $wpdb;
        $user = $this->get_current_pcm_user();
        $id   = absint($request->get_param('id'));

        if (!PCM_DB::get_delivery_by_id($id, (int) $user->id)) {
            return $this->not_found('Delivery');
        }

        $note = sanitize_textarea_field((string) ($request->get_param('note') ?? ''));
        if ('' === trim($note)) {
            return $this->error(__('Log note cannot be empty.', 'power-creatives'));
        }

        $now = current_time('mysql');
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $wpdb->insert(PCM_Schema::table('delivery_logs'), array(
            'deliveryId' => $id,
            'userId'     => (int) $user->id,
            'note'       => $note,
            'createdAt'  => $now,
        ));

        if (!$wpdb->insert_id) {
            return $this->error(__('Failed to save the log entry.', 'power-creatives'), 500);
        }

        return $this->success(array(
            'id'        => (int) $wpdb->insert_id,
            'userId'    => (int) $user->id,
            'note'      => $note,
            'createdAt' => $now,
            'userName'  => $user->name ?: ($user->username ?? ''),
        ), 201);
    }

    // =========================================================================
    // CRUD
    // =========================================================================

    /** POST /deliveries — Create a new delivery. */
    public function create_item(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $user = $this->get_current_pcm_user();

        $name = sanitize_text_field($request->get_param('name') ?? '');
        if ('' === $name) {
            return $this->error('Delivery name is required.');
        }

        // Optional status — defaults to 'active'. Reject unknown values
        // up-front so the DB only ever sees a value from STATUSES.
        $status_input = $request->get_param('status');
        if (null === $status_input || '' === $status_input) {
            $status = PCM_Deliveries_Service::DEFAULT_STATUS;
        } else {
            $status = $this->service->validate_status($status_input);
            if (null === $status) {
                return $this->error(
                    'Invalid status. Allowed: ' . implode(', ', PCM_Deliveries_Service::STATUSES) . '.',
                    400,
                    'pcm_invalid_status'
                );
            }
        }

        $data = array(
            'userId'     => $user->id,
            'name'       => $name,
            'clientName' => sanitize_text_field($request->get_param('clientName') ?? ''),
            'status'     => $status,
            'externalId' => sanitize_text_field($request->get_param('externalId') ?? ''),
        );

        // Optional brand/project linkage — must belong to the caller.
        $links = $this->resolve_link_ids($request, (int) $user->id);
        if ($links instanceof WP_Error) {
            return $links;
        }
        $data = array_merge($data, $links);

        // Optional type + module grants (both whitelist-validated). A type
        // without explicit modules expands to its central preset, so API
        // callers get the same shortcut as the dialog.
        $params = $request->get_json_params() ?: array();
        if (array_key_exists('type', $params)) {
            $data['type'] = PCM_Deliveries_Service::sanitize_type($params['type']);
        }
        if (array_key_exists('modules', $params)) {
            $data['modules'] = wp_json_encode(PCM_Deliveries_Service::sanitize_modules($params['modules']));
        } elseif (!empty($data['type'])) {
            $preset          = PCM_Deliveries_Service::type_presets()[$data['type']]['modules'] ?? array();
            $data['modules'] = wp_json_encode(PCM_Deliveries_Service::sanitize_modules($preset));
        }

        $id = PCM_DB::create_delivery($data);
        if (!$id) {
            return $this->error('Failed to create delivery.', 500);
        }

        $row = PCM_DB::get_delivery_by_id($id, $user->id);
        if (!$row) {
            // Insert succeeded but read-back failed — degenerate case,
            // surface as a server error rather than fabricate a payload.
            return $this->error('Created delivery could not be read back.', 500);
        }

        return $this->success($this->service->format_delivery($row), 201);
    }

    /** PATCH /deliveries/<id> — Update a delivery's name / clientName / status. */
    public function update_item(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $user = $this->get_current_pcm_user();
        $id   = absint($request->get_param('id'));

        $existing = PCM_DB::get_delivery_by_id($id, $user->id);
        if (!$existing) {
            return $this->not_found('Delivery');
        }
        // get_delivery_by_id also returns deliveries merely ASSIGNED to the
        // caller (view + use). Editing stays owner-only, so reject a non-owner
        // explicitly instead of letting the owner-scoped UPDATE silently no-op
        // and return a misleading success.
        if ((int) $existing->userId !== (int) $user->id) {
            return $this->error('You can view this delivery but not edit it.', 403, 'pcm_forbidden');
        }

        $update = array();

        $name = $request->get_param('name');
        if (null !== $name) {
            $clean = sanitize_text_field($name);
            if ('' === $clean) {
                return $this->error('Delivery name cannot be empty.');
            }
            $update['name'] = $clean;
        }

        $client = $request->get_param('clientName');
        if (null !== $client) {
            $update['clientName'] = sanitize_text_field($client);
        }

        $external_id = $request->get_param('externalId');
        if (null !== $external_id) {
            $update['externalId'] = sanitize_text_field($external_id); // webhook deliveryExtID
        }

        $status_input = $request->get_param('status');
        if (null !== $status_input) {
            $status = $this->service->validate_status($status_input);
            if (null === $status) {
                return $this->error(
                    'Invalid status. Allowed: ' . implode(', ', PCM_Deliveries_Service::STATUSES) . '.',
                    400,
                    'pcm_invalid_status'
                );
            }
            $update['status'] = $status;
        }

        // Optional brand/project linkage — must belong to the caller.
        $links = $this->resolve_link_ids($request, (int) $user->id);
        if ($links instanceof WP_Error) {
            return $links;
        }
        $update = array_merge($update, $links);

        // Optional type + module grants (both whitelist-validated). On PATCH
        // a type change without explicit modules also re-applies the preset.
        $params = $request->get_json_params() ?: array();
        if (array_key_exists('type', $params)) {
            $update['type'] = PCM_Deliveries_Service::sanitize_type($params['type']);
        }
        if (array_key_exists('modules', $params)) {
            $update['modules'] = wp_json_encode(PCM_Deliveries_Service::sanitize_modules($params['modules']));
        } elseif (!empty($update['type'])) {
            $preset            = PCM_Deliveries_Service::type_presets()[$update['type']]['modules'] ?? array();
            $update['modules'] = wp_json_encode(PCM_Deliveries_Service::sanitize_modules($preset));
        }

        if (!empty($update)) {
            PCM_DB::update_delivery($id, $user->id, $update);
        }

        $row = PCM_DB::get_delivery_by_id($id, $user->id);
        return $this->success($this->service->format_delivery($row));
    }

    /** DELETE /deliveries/<id> — Delete a delivery. */
    public function delete_item(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $user = $this->get_current_pcm_user();
        $deleted = PCM_DB::delete_delivery(absint($request->get_param('id')), $user->id);

        if (!$deleted) {
            return $this->not_found('Delivery');
        }

        return $this->success(array('success' => true));
    }

    /**
     * Read optional brandId/projectId params, validating each belongs to the
     * caller. Returns only the keys present in the request (so PATCHes that
     * omit them leave the columns untouched); explicit null/0 clears the link.
     *
     * @param WP_REST_Request $request Request.
     * @param int             $user_id Caller's PCM user id.
     * @return array|WP_Error Update fragment or error.
     */
    private function resolve_link_ids(WP_REST_Request $request, int $user_id): array|WP_Error
    {
        global $wpdb;
        $out = array();

        $params = $request->get_json_params() ?: array();

        if (array_key_exists('brandId', $params)) {
            $brand_id = absint($params['brandId'] ?? 0);
            if ($brand_id === 0) {
                $out['brandId'] = null;
            } elseif (PCM_DB::get_brand_by_id($brand_id, $user_id)) {
                $out['brandId'] = $brand_id;
            } else {
                return $this->error('Brand not found.', 404, 'pcm_brand_not_found');
            }
        }

        if (array_key_exists('projectId', $params)) {
            $project_id = absint($params['projectId'] ?? 0);
            if ($project_id === 0) {
                $out['projectId'] = null;
            } else {
                $projects = PCM_Schema::table('projects');
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                $found = $wpdb->get_var($wpdb->prepare(
                    "SELECT id FROM {$projects} WHERE id = %d AND userId = %d",
                    $project_id,
                    $user_id
                ));
                if (!$found) {
                    return $this->error('Project not found.', 404, 'pcm_project_not_found');
                }
                $out['projectId'] = $project_id;
            }
        }

        // seoSiteId — DEPRECATED v1.35.0: intentionally no longer accepted.
        // It was a write-only field no module ever read; site resolution
        // derives live via PCM_Hierarchy (delivery → projects → siteId).

        return $out;
    }
}
